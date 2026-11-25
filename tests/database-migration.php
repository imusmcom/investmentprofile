<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$work = '/tmp/portfolio-migration-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
umask(0022);
$fail = 0;
$ok = static function (string $name, bool $pass, string $got = '') use (&$fail): void {
    echo ($pass ? '  ok   ' : '  FAIL ') . $name . (!$pass && $got !== '' ? "（{$got}）" : '') . "\n";
    if (!$pass) {
        $fail++;
    }
};
$removeTree = static function (string $dir) use (&$removeTree): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
};
register_shutdown_function(static function () use (&$fail, $work, $removeTree): void {
    if ($fail > 0) {
        fwrite(STDERR, "Migration 除錯資料保留在：{$work}\n");
        return;
    }
    $removeTree($work);
});

require $root . '/app/bootstrap.php';

echo "-- data directory --\n";
putenv('PORTFOLIO_DATA_DIR=relative-data');
$relativeConfig = require $root . '/app/config.php';
putenv('PORTFOLIO_DATA_DIR=/tmp/absolute-data');
$absoluteConfig = require $root . '/app/config.php';
putenv('PORTFOLIO_DATA_DIR');
$defaultConfig = require $root . '/app/config.php';
$expectedDefault = $root === '/www/wwwroot/portfolio' && is_file('/www/portfolio-data/portfolio.sqlite')
    ? '/www/portfolio-data'
    : $root . '/data';
$ok('explicit data directories resolve against the project or remain absolute',
    $relativeConfig['data_dir'] === $root . '/relative-data'
    && $absoluteConfig['data_dir'] === '/tmp/absolute-data',
    'relative=' . $relativeConfig['data_dir'] . ' absolute=' . $absoluteConfig['data_dir']);
$ok('normal production and CLI default to the authoritative deployed database',
    $defaultConfig['data_dir'] === $expectedDefault, $defaultConfig['data_dir']);

$resetDatabase = static function (): void {
    $property = new ReflectionProperty(App\Database::class, 'pdo');
    $property->setValue(null, null);
};
$seedLegacy = static function (string $dir): PDO {
    mkdir($dir, 0750);
    $pdo = new PDO('sqlite:' . $dir . '/portfolio.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE instruments (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE prices (
        id INTEGER PRIMARY KEY,
        instrument_id INTEGER,
        as_of TEXT,
        close REAL,
        prev_close REAL,
        adj_close REAL,
        currency TEXT,
        source TEXT,
        fetched_at TEXT
    )');
    $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '2')");
    $pdo->exec("INSERT INTO instruments (id) VALUES (1)");
    $pdo->exec('CREATE TABLE portfolios (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'+8 hours\'))
    )');
    $pdo->exec('CREATE TABLE transactions (
        id INTEGER PRIMARY KEY, portfolio_id INTEGER, instrument_id INTEGER, side TEXT,
        traded_on TEXT, quantity REAL, price REAL, fee REAL, note TEXT, created_at TEXT
    )');
    $pdo->exec('CREATE TABLE cash_movements (
        id INTEGER PRIMARY KEY, portfolio_id INTEGER, moved_on TEXT, direction TEXT,
        amount REAL, currency TEXT, note TEXT, created_at TEXT
    )');
    $pdo->exec("INSERT INTO portfolios (id, name) VALUES (1, 'legacy')");
    $pdo->exec("INSERT INTO transactions
        (id, portfolio_id, instrument_id, side, traded_on, quantity, price, fee, note, created_at)
        VALUES (1, 1, 1, 'BUY', '2024-01-01', 2, 10, 0, 'legacy', '2024-01-01 08:00:00')");
    $pdo->exec("INSERT INTO cash_movements
        (id, portfolio_id, moved_on, direction, amount, currency, note, created_at)
        VALUES (1, 1, '2024-01-01', 'IN', 100, 'USD', 'legacy', '2024-01-01 08:00:00')");
    $put = $pdo->prepare('INSERT INTO prices (instrument_id, as_of, close, prev_close, adj_close, currency, source) VALUES (1,?,?,?,?,?,?)');
    $valid = [
        ['2024-01-01', 10, 9, 8, 'USD', 'yahoo'],
        ['2024-01-01', 11, 10, 9, 'USD', 'yahoo'],
        ['2024-01-02', 20, null, null, 'JPY', 'manual'],
        ['2024-01-03', 30, null, null, 'EUR', 'yahoo'],
    ];
    $invalid = [
        ['2024/01/01', 10, null, null, 'USD', 'yahoo'],
        ['2024-02-30', 10, null, null, 'USD', 'yahoo'],
        ['0000-01-01', 10, null, null, 'USD', 'yahoo'],
        [null, 10, null, null, 'USD', 'yahoo'],
        ['2024-01-04', 0, null, null, 'USD', 'yahoo'],
        ['2024-01-05', -1, null, null, 'USD', 'yahoo'],
        ['2024-01-06', 'not-a-number', null, null, 'USD', 'yahoo'],
        ['2024-01-07', 10, 0, null, 'USD', 'yahoo'],
        ['2024-01-08', 10, null, -1, 'USD', 'yahoo'],
        ['2024-01-09', 10, null, null, null, 'yahoo'],
        ['2024-01-10', 10, null, null, 'TOOLONG', 'yahoo'],
        ['2024-01-11', 10, null, null, 'USD', 'math'],
        ['2024-01-12', 10, null, null, 'USD', null],
    ];
    foreach ([...$valid, ...$invalid] as $row) {
        $put->execute($row);
    }

    // Simulate a partially upgraded v4 table: the columns already exist, but malformed legacy ranges
    // must be normalised and valid ranges must survive the prices table rebuild.
    $pdo->exec('ALTER TABLE prices ADD COLUMN high REAL CHECK (high > 0)');
    $pdo->exec('ALTER TABLE prices ADD COLUMN low REAL CHECK (low > 0)');
    $pdo->exec("UPDATE prices SET high = 12, low = 8 WHERE as_of = '2024-01-01'");
    $pdo->exec("UPDATE prices SET high = 21, low = 19 WHERE as_of = '2024-01-02'");
    $pdo->exec("UPDATE prices SET high = 29, low = 31 WHERE as_of = '2024-01-03'");

    // Simulate the retired v6 lab state. The v8 migration must still remove only these tables and lab_* settings.
    $pdo->exec('CREATE TABLE lab_turtle_runs (id INTEGER PRIMARY KEY, state_json TEXT)');
    $pdo->exec('CREATE TABLE lab_turtle_events (id INTEGER PRIMARY KEY, run_id INTEGER, note TEXT)');
    $pdo->exec("INSERT INTO lab_turtle_runs (id, state_json) VALUES (1, '{}')");
    $pdo->exec("INSERT INTO lab_turtle_events (run_id, note) VALUES (1, 'retired')");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('lab_lehuo', '1'), ('lab_momentum', '1'), ('lab_turtle', '1')");

    return $pdo;
};
$snapshot = static function (PDO $pdo): array {
    $schema = $pdo->query(
        "SELECT type, name, tbl_name, sql FROM sqlite_master
         WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $data = [];
    foreach ($tables as $table) {
        $quoted = '"' . str_replace('"', '""', (string) $table) . '"';
        $data[$table] = $pdo->query('SELECT * FROM ' . $quoted . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }

    return ['schema' => $schema, 'data' => $data];
};

echo "-- permissions --\n";
$createdDir = $work . '/nested/app-created';
putenv('PORTFOLIO_DATA_DIR=' . $createdDir);
$created = App\Database::pdo();
$createdDirMode = fileperms($createdDir) & 0777;
$createdDbMode = fileperms($createdDir . '/portfolio.sqlite') & 0777;
$ok('app-created data directory is 0750 and DB is 0640',
    $createdDirMode === 0750 && $createdDbMode === 0640,
    'dir=' . decoct($createdDirMode) . ' db=' . decoct($createdDbMode));
$ok('new database enforces foreign keys', (int) $created->query('PRAGMA foreign_keys')->fetchColumn() === 1);
$created = null;
$resetDatabase();

$missing = $work . '/does-not-exist';
$inspectionFailure = '';
try {
    $method = new ReflectionMethod(App\Database::class, 'tightenMode');
    $method->invoke(null, $missing, 0750);
} catch (RuntimeException $e) {
    $inspectionFailure = $e->getMessage();
}
$ok('permission inspection failure is fatal', str_contains($inspectionFailure, 'Cannot inspect permissions'));

final class DenyChmodStream
{
    public $context;
    public static int $attempts = 0;

    public function url_stat(string $path, int $flags): array|false
    {
        return ['mode' => 0100666, 'uid' => posix_geteuid()];
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        self::$attempts++;

        return false;
    }
}
stream_wrapper_register('denychmod', DenyChmodStream::class);
$chmodFailure = '';
try {
    $method = new ReflectionMethod(App\Database::class, 'tightenMode');
    $method->invoke(null, 'denychmod://portfolio.sqlite', 0640);
} catch (RuntimeException $e) {
    $chmodFailure = $e->getMessage();
} finally {
    stream_wrapper_unregister('denychmod');
}
$ok('failed chmod is visible and fatal', DenyChmodStream::$attempts === 1
    && str_contains($chmodFailure, 'Cannot tighten permissions'), $chmodFailure);

$linkDir = $work . '/linked-data';
mkdir($linkDir, 0750);
$linkTarget = $work . '/must-not-open.sqlite';
symlink($linkTarget, $linkDir . '/portfolio.sqlite');
putenv('PORTFOLIO_DATA_DIR=' . $linkDir);
$linkFailure = '';
try {
    App\Database::pdo();
} catch (RuntimeException $e) {
    $linkFailure = $e->getMessage();
}
$ok('symbolic-link DB is rejected before opening', str_contains($linkFailure, 'symbolic-link database')
    && !file_exists($linkTarget), $linkFailure);
$resetDatabase();

echo "-- migration --\n";
$dir = $work . '/legacy';
$legacy = $seedLegacy($dir);
$legacy = null;
chmod($dir, 0775);
chmod($dir . '/portfolio.sqlite', 0664);
putenv('PORTFOLIO_DATA_DIR=' . $dir);
$pdo = App\Database::pdo();
$remaining = $pdo->query('SELECT instrument_id, as_of FROM prices ORDER BY as_of')->fetchAll(PDO::FETCH_NUM);
$version = (string) $pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn();
$adjColumn = (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('prices') WHERE name = 'adj_close'")->fetchColumn();
$highColumn = (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('prices') WHERE name = 'high'")->fetchColumn();
$lowColumn = (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('prices') WHERE name = 'low'")->fetchColumn();
$exchangeColumn = (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('instruments') WHERE name = 'exchange'")->fetchColumn();
$primary = [];
foreach ($pdo->query('PRAGMA table_info(prices)')->fetchAll() as $column) {
    if ((int) $column['pk'] > 0) {
        $primary[(int) $column['pk']] = (string) $column['name'];
    }
}
ksort($primary);
$duplicateClose = (float) $pdo->query("SELECT close FROM prices WHERE instrument_id = 1 AND as_of = '2024-01-01'")->fetchColumn();
$ranges = $pdo->query("SELECT as_of, high, low FROM prices ORDER BY as_of")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec("INSERT INTO prices (instrument_id, as_of, close, currency, source)
            VALUES (1, '2024-01-01', 12, 'USD', 'yahoo')
            ON CONFLICT(instrument_id, as_of) DO UPDATE SET close = excluded.close");
$upsertedClose = (float) $pdo->query("SELECT close FROM prices WHERE instrument_id = 1 AND as_of = '2024-01-01'")->fetchColumn();
$dirMode = fileperms($dir) & 0777;
$dbMode = fileperms($dir . '/portfolio.sqlite') & 0777;

$rangeOk = count($ranges) === 3
    && (float) $ranges[0]['high'] === 12.0 && (float) $ranges[0]['low'] === 8.0
    && (float) $ranges[1]['high'] === 21.0 && (float) $ranges[1]['low'] === 19.0
    && $ranges[2]['high'] === null && $ranges[2]['low'] === null;

$strategyTables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('lab_turtle_runs','lab_turtle_events')")->fetchColumn();
$labSettings = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE substr(key,1,4) = 'lab_'")->fetchColumn();
$transactionCount = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$cashCount = (int) $pdo->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn();

if ($version !== '8' || $strategyTables !== 0 || $labSettings !== 0 || $remaining !== [[1, '2024-01-01'], [1, '2024-01-02'], [1, '2024-01-03']]
    || $transactionCount !== 1 || $cashCount !== 1
    || $adjColumn !== 1 || $highColumn !== 1 || $lowColumn !== 1 || $exchangeColumn !== 1
    || array_values($primary) !== ['instrument_id', 'as_of']
    || $duplicateClose !== 11.0 || $upsertedClose !== 12.0 || !$rangeOk
    || $dirMode !== 0750 || $dbMode !== 0640
    || (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_prices_fetched_recent'")->fetchColumn() !== 1
    || (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_prices_range_pending'")->fetchColumn() !== 1) {
    fwrite(STDERR, 'migration failed: version=' . $version . ' rows=' . json_encode($remaining)
        . ' adj=' . $adjColumn . ' high=' . $highColumn . ' low=' . $lowColumn
        . ' exchange=' . $exchangeColumn . ' pk=' . json_encode($primary)
        . ' duplicate=' . $duplicateClose . ' upsert=' . $upsertedClose
        . ' ranges=' . json_encode($ranges)
        . ' dir_mode=' . decoct($dirMode) . ' db_mode=' . decoct($dbMode) . PHP_EOL);
    exit(1);
}
$ok('legacy migration, cleanup, rebuild, and live-like permission tightening pass', true);

$orphanRejected = false;
try {
    $pdo->exec("INSERT INTO prices (instrument_id, as_of, close, currency, source)
                VALUES (999999, '2024-02-01', 1, 'USD', 'manual')");
} catch (PDOException $e) {
    $orphanRejected = true;
}
$foreignKey = $pdo->query('PRAGMA foreign_key_list(prices)')->fetch(PDO::FETCH_ASSOC);
$ok('rebuilt prices retains enforced cascading foreign key', (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1
    && $orphanRejected && ($foreignKey['table'] ?? '') === 'instruments'
    && ($foreignKey['on_delete'] ?? '') === 'CASCADE');
$legacyFinancial = $pdo->query("SELECT
    (SELECT traded_on FROM transactions WHERE id = 1),
    (SELECT quantity FROM transactions WHERE id = 1),
    (SELECT moved_on FROM cash_movements WHERE id = 1),
    (SELECT amount FROM cash_movements WHERE id = 1)")->fetch(PDO::FETCH_NUM);
$ok('legacy transaction and cash rows survive the v8 rebuilds',
    $legacyFinancial === ['2024-01-01', 2.0, '2024-01-01', 100.0], json_encode($legacyFinancial));
$planUses = static function (PDO $db, string $sql, string $index): bool {
    $stmt = $db->prepare('EXPLAIN QUERY PLAN ' . $sql);
    $stmt->execute([1]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (str_contains((string) $row['detail'], 'USING INDEX ' . $index)) {
            return true;
        }
    }

    return false;
};
$ok('v8 partial indexes are selected by the pending-data queries',
    $planUses($pdo, 'SELECT COUNT(*) FROM prices WHERE instrument_id = ? AND adj_close IS NULL', 'idx_prices_adj_pending')
    && $planUses($pdo, "SELECT MAX(fetched_at) FROM prices WHERE instrument_id = ? AND source <> 'manual'", 'idx_prices_fetched_recent')
    && $planUses($pdo, "SELECT MIN(as_of) FROM prices WHERE instrument_id = ? AND source <> 'manual' AND (high IS NULL OR low IS NULL)", 'idx_prices_range_pending'));
$pdo->exec('DROP INDEX idx_prices_range_pending');
$pdo->exec("CREATE INDEX idx_prices_range_pending ON prices(instrument_id)
            WHERE source <> 'manual' AND (high IS NULL OR low IS NULL)");
$pdo = null;
$resetDatabase();
$pdo = App\Database::pdo();
$rangeIndexSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'idx_prices_range_pending'")->fetchColumn();
$ok('an already-versioned v8 database reconciles the older range-index definition',
    preg_match('/\bON prices\s*\(\s*instrument_id\s*,\s*as_of\s*\)/i', $rangeIndexSql) === 1
    && $planUses($pdo, "SELECT MIN(as_of) FROM prices WHERE instrument_id = ? AND source <> 'manual' AND (high IS NULL OR low IS NULL)", 'idx_prices_range_pending'),
    $rangeIndexSql);
$reject = static function (PDO $db, string $sql, array $params = []): bool {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return false;
    } catch (PDOException) {
        return true;
    }
};
$ok('v8 prices rejects malformed dates, non-positive close, currency and source',
    $reject($pdo, "INSERT INTO prices (instrument_id,as_of,close,currency,source) VALUES (1,'2024-02-30',1,'USD','yahoo')")
    && $reject($pdo, "INSERT INTO prices (instrument_id,as_of,close,currency,source) VALUES (1,'2024-03-01',0,'USD','yahoo')")
    && $reject($pdo, "INSERT INTO prices (instrument_id,as_of,close,currency,source) VALUES (1,'2024-03-02',1,'X','yahoo')")
    && $reject($pdo, "INSERT INTO prices (instrument_id,as_of,close,currency,source) VALUES (1,'2024-03-03',1,'USD','math')"));
$ok('v8 rejects impossible transaction and cash dates instead of silently deleting records',
    $reject($pdo, "INSERT INTO transactions (portfolio_id,instrument_id,side,traded_on,quantity,price)
                   VALUES (1,1,'BUY','2024-02-30',1,1)")
    && $reject($pdo, "INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency)
                   VALUES (1,'2024-02-30','IN',1,'USD')"));
$pdo = null;
$resetDatabase();

echo "-- atomic rollback --\n";
$atomicDir = $work . '/atomic';
$legacy = $seedLegacy($atomicDir);
$legacy->exec("CREATE TRIGGER fail_schema_version BEFORE INSERT ON settings
               WHEN NEW.key = 'schema_version'
               BEGIN SELECT RAISE(ABORT, 'injected migration failure'); END");
$before = $snapshot($legacy);
$legacy = null;
putenv('PORTFOLIO_DATA_DIR=' . $atomicDir);
$injectedFailure = null;
try {
    App\Database::pdo();
} catch (Throwable $e) {
    $injectedFailure = $e;
}
$ok('late migration failure is injected', $injectedFailure instanceof PDOException
    && str_contains($injectedFailure->getMessage(), 'injected migration failure'),
    $injectedFailure?->getMessage() ?? 'no exception');

$probe = new PDO('sqlite:' . $atomicDir . '/portfolio.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$after = $snapshot($probe);
$ok('failure rolls back all schema, cleanup, rebuild, and version changes', $before === $after,
    'before=' . json_encode($before) . ' after=' . json_encode($after));
$cached = (new ReflectionProperty(App\Database::class, 'pdo'))->getValue();
$ok('failed migration does not cache a partial connection', $cached === null);
$probe->exec('DROP TRIGGER fail_schema_version');
$probe = null;

$retry = App\Database::pdo();
$ok('retry after removing injected failure completes migration',
    (int) $retry->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn() === 8
    && (int) $retry->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('lab_turtle_runs','lab_turtle_events')")->fetchColumn() === 0
    && (int) $retry->query("SELECT COUNT(*) FROM settings WHERE substr(key,1,4) = 'lab_'")->fetchColumn() === 0
    && (int) $retry->query("SELECT COUNT(*) FROM sqlite_master WHERE name='prices_new'")->fetchColumn() === 0
    && (int) $retry->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_prices_fetched_recent'")->fetchColumn() === 1
    && (int) $retry->query('SELECT COUNT(*) FROM prices')->fetchColumn() === 3
    && (int) $retry->query('PRAGMA foreign_keys')->fetchColumn() === 1);

echo $fail === 0 ? "MIGRATION ALL PASS\n" : "MIGRATION FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
