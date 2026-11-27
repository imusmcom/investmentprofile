<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = require __DIR__ . '/config.php';
        $dir = rtrim((string) $config['data_dir'], "/\\");

        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory: {$dir}");
        }
        self::tightenMode($dir, 0750);

        $dsn = 'sqlite:' . $dir . '/portfolio.sqlite';
        $dbPath = $dir . '/portfolio.sqlite';
        if (is_link($dbPath)) {
            throw new RuntimeException("Refusing a symbolic-link database: {$dbPath}");
        }
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::tightenMode($dbPath, 0640);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // bin/create-admin.php and the scheduled refresh write while the web server is running.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        try {
            self::migrate($pdo);
        } catch (Throwable $e) {
            // A failed migration must not leave a cached connection that appears initialized.
            self::$pdo = null;
            throw $e;
        }

        return $pdo;
    }

    /** Run related reads against one WAL snapshot; never commit a caller's write transaction. */
    public static function read(callable $read): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $read($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $read($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Tighten only; never broaden access or change ownership. */
    private static function tightenMode(string $path, int $desired): void
    {
        $stat = @stat($path);
        if ($stat === false) {
            throw new RuntimeException("Cannot inspect permissions for: {$path}");
        }

        // FPM and CLI run as the deployment user (www here). Root may operate on its files, but
        // ownership is still inspected and reported; no path is ever chowned automatically.
        if (function_exists('posix_geteuid')) {
            $euid = posix_geteuid();
            $owner = (int) $stat['uid'];
            if ($euid !== 0 && $owner !== $euid) {
                throw new RuntimeException(
                    "Refusing to change permissions on {$path}: owned by uid {$owner}, current uid {$euid}"
                );
            }
        }

        $current = (int) $stat['mode'] & 0777;
        $tight = $current & $desired;
        if ($tight === $current) {
            return;
        }

        if (!@chmod($path, $tight)) {
            $error = error_get_last();
            throw new RuntimeException(
                "Cannot tighten permissions on {$path} to " . decoct($tight)
                . ': ' . (string) ($error['message'] ?? 'chmod failed')
            );
        }
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || ((int) $stat['mode'] & 0777) !== $tight) {
            throw new RuntimeException("Permission verification failed for: {$path}");
        }
    }

    private static function migrate(PDO $pdo): void
    {
        $targetVersion = 9;
        $settingsExisted = (int) self::scalar(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'settings'",
            [],
            0
        ) === 1;

        // The version must be read before schema.sql runs: its old schema_version seed made every
        // legacy database look current before the ALTER statements below had a chance to execute.
        if ($settingsExisted) {
            $currentVersion = (int) self::scalar(
                'SELECT value FROM settings WHERE key = ?',
                ['schema_version'],
                '0'
            );
            if ($currentVersion >= $targetVersion) {
                self::reconcileRangePendingIndex($pdo);

                return;
            }
        }

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Unable to read the database schema.');
        }

        // SQLite DDL is transactional. One outer transaction covers the schema, legacy cleanup,
        // table rebuild, indexes, and version marker; rebuildPrices() must never commit independently.
        $pdo->beginTransaction();
        try {
            $pdo->exec($schema);

            // v7 retires the Momentum/Turtle lab. Drop only their paper-state tables; formal
            // portfolios, transactions, cash, prices, and settings are never touched.
            $pdo->exec('DROP TABLE IF EXISTS lab_turtle_events');
            $pdo->exec('DROP TABLE IF EXISTS lab_turtle_runs');
            $pdo->exec("DELETE FROM settings WHERE substr(key, 1, 4) = 'lab_'");

            if ($settingsExisted) {
                // CREATE TABLE IF NOT EXISTS never changes an existing table. Add the columns explicitly and
                // tolerate only the duplicate-column race where another worker has just performed the same ALTER.
                self::addColumn($pdo, 'prices', 'adj_close', 'REAL');
                self::addColumn($pdo, 'prices', 'high', 'REAL CHECK (high > 0)');
                self::addColumn($pdo, 'prices', 'low', 'REAL CHECK (low > 0)');
                self::addColumn($pdo, 'instruments', 'exchange', "TEXT NOT NULL DEFAULT ''");
                self::normalisePriceRanges($pdo);
                self::cleanInvalidPrices($pdo);

                // v8 reconciles existing tables with the declared schema. v9 also snapshots each trade's
                // currency, so changing an instrument later cannot reinterpret historical settlement cash.
                self::rebuildPrices($pdo);
                self::addColumn(
                    $pdo,
                    'transactions',
                    'currency',
                    "TEXT NOT NULL DEFAULT '' CHECK (currency = '' OR length(currency) BETWEEN 2 AND 5)"
                );
                $pdo->exec("UPDATE transactions
                            SET currency = upper(trim(i.currency))
                            FROM instruments i
                            WHERE i.id = transactions.instrument_id
                              AND transactions.currency = ''");
                self::rebuildTransactions($pdo);
                self::rebuildCashMovements($pdo);
            }

            // These reference columns added above, so legacy databases cannot create them from schema.sql.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prices_adj_pending ON prices(instrument_id) WHERE adj_close IS NULL');
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prices_fetched_recent
                        ON prices(instrument_id, fetched_at DESC) WHERE source <> 'manual'");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prices_range_pending
                        ON prices(instrument_id, as_of) WHERE source <> 'manual' AND (high IS NULL OR low IS NULL)");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_portfolio ON transactions(portfolio_id, traded_on)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tx_instrument ON transactions(instrument_id, traded_on)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cash_portfolio ON cash_movements(portfolio_id, moved_on)');

            $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '{$targetVersion}')
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function reconcileRangePendingIndex(PDO $pdo): void
    {
        $sql = self::scalar(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'idx_prices_range_pending'",
            [],
            ''
        );
        if (is_string($sql) && preg_match('/\bON prices\s*\(\s*instrument_id\s*,\s*as_of\s*\)/i', $sql) === 1) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP INDEX IF EXISTS idx_prices_range_pending');
            $pdo->exec("CREATE INDEX idx_prices_range_pending
                        ON prices(instrument_id, as_of)
                        WHERE source <> 'manual' AND (high IS NULL OR low IS NULL)");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * A half-present or impossible daily range must not be mistaken for real OHLC data. Keep the valid
     * close and clear only the range, so a bad legacy high/low never destroys an otherwise useful bar.
     */
    private static function normalisePriceRanges(PDO $pdo): void
    {
        $pdo->exec("UPDATE prices SET high = NULL, low = NULL
                    WHERE (high IS NULL) <> (low IS NULL)
                       OR (high IS NOT NULL AND low IS NOT NULL AND (
                           typeof(high) NOT IN ('integer', 'real')
                           OR typeof(low) NOT IN ('integer', 'real')
                           OR high <= 0 OR low <= 0 OR high < low OR high < close OR low > close
                       ))");
    }

    private static function cleanInvalidPrices(PDO $pdo): void
    {
        // Existing tables cannot gain CHECK constraints through CREATE TABLE IF NOT EXISTS. Drop only rows
        // the new schema would reject, so old malformed dates and non-positive prices cannot poison history.
        $invalid = "as_of IS NULL OR typeof(as_of) <> 'text'
            OR as_of NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
            OR substr(as_of, 1, 4) NOT BETWEEN '1000' AND '9999'
            OR date(as_of) IS NULL OR date(as_of) <> as_of
            OR typeof(close) NOT IN ('integer', 'real') OR close <= 0
            OR (prev_close IS NOT NULL AND (typeof(prev_close) NOT IN ('integer', 'real') OR prev_close <= 0))
            OR (adj_close IS NOT NULL AND (typeof(adj_close) NOT IN ('integer', 'real') OR adj_close <= 0))
            OR currency IS NULL OR typeof(currency) <> 'text' OR length(currency) NOT BETWEEN 2 AND 5
            OR source IS NULL OR source NOT IN ('yahoo', 'manual')";
        $removed = $pdo->exec('DELETE FROM prices WHERE ' . $invalid);
        if ($removed > 0) {
            error_log("資料庫遷移：移除 {$removed} 筆不符合價格資料契約的舊紀錄。");
        }
    }

    /** Called only inside migrate()'s transaction; the caller owns commit and rollback. */
    private static function rebuildPrices(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS prices_new');
        $pdo->exec('CREATE TABLE prices_new (
            instrument_id INTEGER NOT NULL REFERENCES instruments(id) ON DELETE CASCADE,
            as_of         TEXT NOT NULL CHECK (
                length(as_of) = 10
                AND as_of GLOB \'[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]\'
                AND substr(as_of, 1, 4) BETWEEN \'1000\' AND \'9999\'
                AND date(as_of) IS NOT NULL
                AND date(as_of) = as_of
            ),
            close         REAL NOT NULL CHECK (close > 0),
            high          REAL CHECK (high > 0),
            low           REAL CHECK (low > 0),
            prev_close    REAL CHECK (prev_close > 0),
            adj_close     REAL CHECK (adj_close > 0),
            currency      TEXT NOT NULL CHECK (length(currency) BETWEEN 2 AND 5),
            source        TEXT NOT NULL DEFAULT \'yahoo\' CHECK (source IN (\'yahoo\', \'manual\')),
            fetched_at    TEXT NOT NULL DEFAULT (datetime(\'now\',\'+8 hours\')),
            PRIMARY KEY (instrument_id, as_of)
        )');
        $pdo->exec("INSERT INTO prices_new (instrument_id, as_of, close, high, low, prev_close, adj_close, currency, source, fetched_at)
                    SELECT instrument_id, as_of, close, high, low, prev_close, adj_close, currency, source,
                           COALESCE(fetched_at, datetime('now', '+8 hours'))
                    FROM (
                        SELECT *, ROW_NUMBER() OVER (
                            PARTITION BY instrument_id, as_of ORDER BY fetched_at DESC, rowid DESC
                        ) AS rn
                        FROM prices
                    )
                    WHERE rn = 1");
        $pdo->exec('DROP TABLE prices');
        $pdo->exec('ALTER TABLE prices_new RENAME TO prices');
    }

    private static function rebuildTransactions(PDO $pdo): void
    {
        $invalid = "typeof(traded_on) <> 'text'
            OR traded_on NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
            OR date(traded_on) IS NULL OR date(traded_on) <> traded_on";
        $bad = (int) self::scalar('SELECT COUNT(*) FROM transactions WHERE ' . $invalid, [], 0);
        if ($bad > 0) {
            throw new RuntimeException("資料庫遷移：{$bad} 筆交易日期不正確，已取消 v9 遷移。");
        }

        $pdo->exec('DROP TABLE IF EXISTS transactions_new');
        $pdo->exec('CREATE TABLE transactions_new (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            portfolio_id INTEGER NOT NULL REFERENCES portfolios(id) ON DELETE CASCADE,
            instrument_id INTEGER NOT NULL REFERENCES instruments(id) ON DELETE RESTRICT,
            side         TEXT NOT NULL CHECK (side IN (\'BUY\', \'SELL\')),
            traded_on    TEXT NOT NULL CHECK (
                length(traded_on) = 10
                AND traded_on GLOB \'[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]\'
                AND date(traded_on) IS NOT NULL
                AND date(traded_on) = traded_on
            ),
            currency     TEXT NOT NULL DEFAULT \'\' CHECK (currency = \'\' OR length(currency) BETWEEN 2 AND 5),
            quantity     REAL NOT NULL CHECK (quantity > 0),
            price        REAL NOT NULL CHECK (price >= 0),
            fee          REAL NOT NULL DEFAULT 0 CHECK (fee >= 0),
            note         TEXT NOT NULL DEFAULT \'\',
            created_at   TEXT NOT NULL DEFAULT (datetime(\'now\',\'+8 hours\'))
        )');
        $pdo->exec('INSERT INTO transactions_new
                    (id, portfolio_id, instrument_id, side, traded_on, currency, quantity, price, fee, note, created_at)
                    SELECT id, portfolio_id, instrument_id, side, traded_on, currency, quantity, price, fee, note, created_at
                    FROM transactions');
        $pdo->exec('DROP TABLE transactions');
        $pdo->exec('ALTER TABLE transactions_new RENAME TO transactions');
    }

    private static function rebuildCashMovements(PDO $pdo): void
    {
        $invalid = "typeof(moved_on) <> 'text'
            OR moved_on NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
            OR date(moved_on) IS NULL OR date(moved_on) <> moved_on";
        $bad = (int) self::scalar('SELECT COUNT(*) FROM cash_movements WHERE ' . $invalid, [], 0);
        if ($bad > 0) {
            throw new RuntimeException("資料庫遷移：{$bad} 筆現金日期不正確，已取消 v9 遷移。");
        }

        $pdo->exec('DROP TABLE IF EXISTS cash_movements_new');
        $pdo->exec('CREATE TABLE cash_movements_new (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            portfolio_id INTEGER NOT NULL REFERENCES portfolios(id) ON DELETE CASCADE,
            moved_on     TEXT NOT NULL CHECK (
                length(moved_on) = 10
                AND moved_on GLOB \'[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]\'
                AND date(moved_on) IS NOT NULL
                AND date(moved_on) = moved_on
            ),
            direction    TEXT NOT NULL CHECK (direction IN (\'IN\', \'OUT\')),
            amount       REAL NOT NULL CHECK (amount > 0),
            currency     TEXT NOT NULL,
            note         TEXT NOT NULL DEFAULT \'\',
            created_at   TEXT NOT NULL DEFAULT (datetime(\'now\',\'+8 hours\'))
        )');
        $pdo->exec('INSERT INTO cash_movements_new
                    (id, portfolio_id, moved_on, direction, amount, currency, note, created_at)
                    SELECT id, portfolio_id, moved_on, direction, amount, currency, note, created_at
                    FROM cash_movements');
        $pdo->exec('DROP TABLE cash_movements');
        $pdo->exec('ALTER TABLE cash_movements_new RENAME TO cash_movements');
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (\PDOException $e) {
            $driverMessage = strtolower((string) ($e->errorInfo[2] ?? ''));
            if (!str_starts_with($driverMessage, 'duplicate column name:')) {
                throw $e;
            }
        }
    }

    /** Run a statement with bound parameters and return the prepared statement. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function scalar(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }
}
