<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Whole-database snapshots, taken and restored from the settings page.
 *
 * A snapshot is `VACUUM INTO`: one standalone file, WAL contents folded in, without asking the
 * rest of the app to stop. Restoring deliberately does NOT swap `data/portfolio.sqlite` — the
 * built-in server holds that file open and Windows will not let a running process replace it, so
 * the backup is attached and copied table by table inside one transaction instead.
 */
final class Backup
{
    /** Snapshots older than this many are pruned, counting manual ones and pre-restore ones together. */
    public const KEEP = 7;

    /** Self-imposed ceiling, so the message is ours rather than PHP's silent discard. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Copy order. Parents before children, because `PRAGMA foreign_keys = OFF` is only lifted again
     * once the whole set is in place and `foreign_key_check` is what proves the order was right.
     */
    private const TABLES = [
        'portfolios', 'instruments', 'transactions', 'cash_movements', 'prices', 'fx_rates', 'settings',
    ];

    /** Tables whose next auto id must be pushed past whatever the restore brought in. */
    private const SEQUENCES = [
        'portfolios', 'instruments', 'transactions', 'cash_movements',
    ];

    /** Numeric payloads; identifiers are checked separately so they must be positive integers. */
    private const NUMERIC_COLUMNS = [
        'portfolios' => [],
        'instruments' => ['manual_price'],
        'transactions' => ['quantity', 'price', 'fee'],
        'cash_movements' => ['amount'],
        'prices' => ['close', 'high', 'low', 'prev_close', 'adj_close'],
        'fx_rates' => ['units_per_usd', 'manual'],
        'settings' => [],
    ];

    /** The key columns the live tables rely on for uniqueness and conflict handling. */
    private const PRIMARY_KEYS = [
        'portfolios' => ['id'],
        'instruments' => ['id'],
        'transactions' => ['id'],
        'cash_movements' => ['id'],
        'prices' => ['instrument_id', 'as_of'],
        'fx_rates' => ['currency'],
        'settings' => ['key'],
    ];

    /** Every identifier copied as an integer, including table-local and foreign keys. */
    private const ID_COLUMNS = [
        'portfolios' => ['id'],
        'instruments' => ['id'],
        'transactions' => ['id', 'portfolio_id', 'instrument_id'],
        'cash_movements' => ['id', 'portfolio_id'],
        'prices' => ['instrument_id'],
        'fx_rates' => [],
        'settings' => [],
    ];

    /** Dates whose exact format and validity are required by the current application schema. */
    private const DATE_COLUMNS = [
        'portfolios' => [],
        'instruments' => [],
        'transactions' => ['traded_on'],
        'cash_movements' => ['moved_on'],
        'prices' => ['as_of'],
        'fx_rates' => ['as_of'],
        'settings' => [],
    ];

    /** Only files this class wrote are ever offered back, which also rules out `..` and any odd name. */
    private const NAME_PATTERN = '/^(manual|pre-restore)-\d{8}-\d{6}(-\d{2})?\.sqlite$/';

    public static function directory(): string
    {
        $config = require __DIR__ . '/config.php';
        $dir = rtrim((string) $config['data_dir'], "/\\") . '/backups';

        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0750, true);
            if (!$created && !is_dir($dir)) {
                error_log("無法建立備份資料夾：{$dir}");
                throw new RuntimeException('無法建立備份資料夾，請檢查伺服器設定。');
            }
            // Another request may win the mkdir race; it owns securing the directory it just created.
            // Never chmod an existing directory: its owner may deliberately keep it private.
            if ($created && !@chmod($dir, 0750)) {
                error_log("無法設定新建備份資料夾權限：{$dir}");
                throw new RuntimeException('無法設定新建備份資料夾權限，請檢查伺服器設定。');
            }
        }

        return $dir;
    }

    /**
     * @return list<array{name:string, kind:string, moment:string, bytes:int}> newest first
     */
    public static function list(): array
    {
        $out = [];
        foreach (self::names() as $name) {
            $path = self::directory() . '/' . $name;
            $out[] = [
                'name'   => $name,
                'kind'   => str_starts_with($name, 'pre-restore') ? '還原前快照' : '手動備份',
                'moment' => self::moment($name),
                'bytes'  => (int) filesize($path),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = array_values(array_filter(
            (array) (scandir(self::directory()) ?: []),
            static fn (string $n): bool => preg_match(self::NAME_PATTERN, $n) === 1
        ));

        // The kind prefix must not outrank the timestamp: otherwise seven pre-restore snapshots can
        // evict every newer manual backup merely because "pre" sorts after "manual".
        usort($names, static function (string $a, string $b): int {
            $key = static function (string $name): string {
                preg_match('/^(?:manual|pre-restore)-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})(?:-(\d{2}))?\.sqlite$/', $name, $m);

                return implode('', array_slice($m, 1, 6)) . ($m[7] ?? '00');
            };

            return strcmp($key($b), $key($a)) ?: strcmp($b, $a);
        });

        return $names;
    }

    public static function path(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new UserError('備份檔名不正確，請從列表上選擇。');
        }

        $path = self::directory() . '/' . $name;
        if (!is_file($path)) {
            throw new UserError('找不到備份檔，請重新整理列表後再試。');
        }

        return $path;
    }

    /** @return array{bytes:int, human:string} the limits this server would actually accept */
    public static function uploadLimit(): array
    {
        // A 0 from either ini means that one is unlimited, so it must not win the min().
        $caps = array_values(array_filter(
            [self::bytesOf(ini_get('upload_max_filesize')), self::postLimitBytes()],
            static fn (int $v): bool => $v > 0
        ));
        $bytes = $caps === [] ? self::MAX_BYTES : min(min($caps), self::MAX_BYTES);

        return ['bytes' => $bytes, 'human' => self::humanBytes($bytes)];
    }

    /**
     * post_max_size is the one that makes PHP discard a request body, upload caps only ever reject the
     * file inside it. 0 means the ini left it unlimited.
     */
    public static function postLimitBytes(): int
    {
        $bytes = self::bytesOf(ini_get('post_max_size'));

        return $bytes < 0 ? 0 : $bytes;
    }

    /**
     * Freezes the current database into data/backups and returns its file name.
     */
    public static function snapshot(string $kind = 'manual'): string
    {
        if (!in_array($kind, ['manual', 'pre-restore'], true)) {
            throw new UserError('備份種類不正確。');
        }

        $dir = self::directory();
        $stamp = date('Ymd-His');
        $name = "{$kind}-{$stamp}.sqlite";
        // Two clicks in the same second are possible, and VACUUM INTO refuses an existing target.
        for ($i = 1; is_file($dir . '/' . $name); $i++) {
            if ($i > 99) {
                throw new UserError('同一秒內的備份次數太多，請稍後再試。');
            }
            $name = sprintf('%s-%s-%02d.sqlite', $kind, $stamp, $i);
        }

        // Written under a name the list ignores, then renamed: a snapshot cut short by a crash must not
        // look like a real one sitting in the list.
        $tmp = $dir . '/' . $name . '.writing';
        $final = $dir . '/' . $name;
        $renamed = false;
        $scrub = null;
        try {
            // The temporary copy contains credentials until it is scrubbed, so it must be owner-only
            // from the instant SQLite creates it, not only after the scrub has finished.
            $previousUmask = umask(0177);
            try {
                Database::pdo()->exec('VACUUM INTO ' . self::literal($tmp));
            } finally {
                umask($previousUmask);
            }
            if (!@chmod($tmp, 0600)) {
                @unlink($tmp);
                throw new RuntimeException('無法設定暫存備份檔權限。');
            }

            // 快照是整份檔案的複製，連帳號雜碼都在裡面；下載出去的備份檔不該帶著可離線破解的密碼，
            // 所以這裡把 access 與 login_throttle 兩張表的列抹掉，再 VACUUM 一次把空頁清乾淨。
            $scrub = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $scrub->exec("DELETE FROM access; DELETE FROM login_throttle; DELETE FROM settings WHERE key IN ('quote_token', 'quote_endpoint');");
            $scrub->exec('VACUUM');
            unset($scrub);
            if (!@rename($tmp, $final)) {
                throw new RuntimeException('無法把暫存備份檔改成正式檔名。');
            }
            $renamed = true;
            if (!@chmod($final, 0600)) {
                throw new RuntimeException('無法設定備份檔權限。');
            }
        } catch (Throwable $e) {
            unset($scrub);
            @unlink($tmp);
            if ($renamed) {
                @unlink($final);
            }
            error_log("建立備份失敗（暫存檔：{$tmp}）：" . $e->getMessage());
            throw new RuntimeException('備份失敗，請檢查伺服器記錄或聯絡管理員。');
        }

        self::prune();

        return $name;
    }

    public static function prune(): void
    {
        $dir = self::directory();
        foreach (array_slice(self::names(), self::KEEP) as $name) {
            @unlink($dir . '/' . $name);
        }

        // A process killed mid-snapshot can leave an unscrubbed .writing file behind. Keep a fresh
        // one alone in case another request is still writing it, but reclaim stale orphans.
        $cutoff = time() - 3600;
        foreach ((array) (scandir($dir) ?: []) as $name) {
            if (!str_ends_with($name, '.writing')) {
                continue;
            }
            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
            }
        }
    }

    public static function delete(string $name): void
    {
        $path = self::path($name);
        if (!@unlink($path)) {
            error_log("刪除備份失敗：{$path}");
            throw new RuntimeException('備份檔刪除失敗，請檢查伺服器記錄或聯絡管理員。');
        }
    }

    /** Streams a snapshot as a download. The name is pattern-checked, so it is header-safe. */
    public static function send(string $name): void
    {
        $path = self::path($name);

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    /**
     * Stages an uploaded file inside data/backups under a name we made up. The client's file name is
     * never used as a path, so `../../` in it cannot reach outside.
     */
    public static function stageUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        // Which of the two caps was hit depends on whether the whole body made it in, so ask post_max_size
        // rather than guessing from the (possibly discarded) reported size.
        $limit = self::uploadLimit();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > $limit['bytes']) {
            throw new UserError('備份檔超過這台伺服器允許的上限（' . $limit['human']
                . '）。請改用 start-windows.bat／start.sh 啟動（兩個上限都提到 64 MB），'
                . '或調整 php.ini 的 upload_max_filesize 與 post_max_size。');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UserError('請選擇要還原的備份檔。');
        }
        if ($error === UPLOAD_ERR_PARTIAL) {
            throw new UserError('上傳中斷，請再試一次。');
        }
        if ($error !== UPLOAD_ERR_OK || !isset($file['tmp_name'])) {
            throw new UserError('上傳失敗（錯誤碼 ' . $error . '），請重新選擇備份檔。');
        }

        $tmp = (string) $file['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new UserError('上傳不成立，請重新選擇備份檔。');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new UserError('備份檔是空的，不會覆蓋任何資料。');
        }
        if ($size > self::MAX_BYTES) {
            throw new UserError('備份檔超過 ' . self::humanBytes(self::MAX_BYTES) . '，請先縮小。');
        }
        if (!self::looksLikeSqlite($tmp)) {
            throw new UserError('這個檔案不是 SQLite 資料庫，看起來不像是本系統做出的備份。');
        }

        $name = 'incoming-' . bin2hex(random_bytes(8));
        $path = self::directory() . '/' . $name . '.sqlite';
        if (!@move_uploaded_file($tmp, $path)) {
            error_log("無法暫存上傳的備份檔：{$path}");
            throw new RuntimeException('無法暫存上傳的備份檔，請檢查伺服器記錄或聯絡管理員。');
        }

        return $path;
    }

    /**
     * Replaces every row in the live database with the rows of the snapshot, and returns the counts.
     * The uploaded database is fully validated first, so a wrong file cannot consume a KEEP slot or
     * cause a pre-restore snapshot to be written.
     *
     * @return array{guard: string, counts: array<string,int>} the safety copy made first, and the rows
     *          each table ended up with
     */
    public static function restore(string $path): array
    {
        try {
            if (!is_file($path) || !is_readable($path)) {
                throw new UserError('備份檔不存在或無法讀取。');
            }
            if (!self::looksLikeSqlite($path)) {
                throw new UserError('這個檔案不是 SQLite 資料庫，不會覆蓋任何資料。');
            }

            $pdo = Database::pdo();
            [$columns, $counts] = self::validateSource($path, $pdo);
            $guard = self::snapshot('pre-restore');

            $pdo->exec('ATTACH DATABASE ' . self::literal($path) . ' AS src');
            try {
                // 報價出口、金鑰、開關與節流政策是這台機器的設定，不跟著備檔走：備檔可以帶著走整份資料，
                // 但不能悄悄把下一次的外連位址換掉，或把原本關閉的報價重新打開。
                $outlet = Database::all("SELECT key, value FROM settings WHERE key LIKE 'quote_%'");

                // A no-op inside a transaction, so it has to happen before BEGIN.
                $pdo->exec('PRAGMA foreign_keys = OFF');
                try {
                    $pdo->beginTransaction();
                    try {
                        foreach (self::TABLES as $table) {
                            $pdo->exec('DELETE FROM main.' . $table);
                        }
                        foreach ($columns as $table => $cols) {
                            $list = implode(', ', $cols);
                            $pdo->exec("INSERT INTO main.{$table} ({$list}) SELECT {$list} FROM src.{$table}");
                        }
                        self::pushSequences($pdo);
                        self::restoreOutlet($pdo, $outlet);

                        // Both checks run before COMMIT, so an unusable result leaves the old data untouched.
                        $broken = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
                        if ($broken !== []) {
                            throw new UserError('還原後的資料關聯不一致，已放棄這次還原。');
                        }
                        $damage = $pdo->query('PRAGMA main.integrity_check')->fetchAll(PDO::FETCH_COLUMN);
                        if (array_map('strval', $damage) !== ['ok']) {
                            throw new UserError('還原後的資料庫檢查沒有通過，已放棄這次還原。');
                        }

                        $pdo->commit();
                    } catch (Throwable $e) {
                        try {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                        } catch (Throwable $rollback) {
                            error_log("還原回滾失敗（來源：{$path}）：" . $rollback->getMessage());
                        }
                        throw $e;
                    }
                } finally {
                    $pdo->exec('PRAGMA foreign_keys = ON');
                }

                return ['guard' => $guard, 'counts' => $counts];
            } finally {
                // DETACH cannot happen while the transaction is remembered as failed, so it is last either way.
                try {
                    $pdo->exec('DETACH DATABASE src');
                } catch (Throwable $e) {
                    error_log("還原後無法分離備份資料庫（來源：{$path}）：" . $e->getMessage());
                }
            }
        } catch (UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log("還原失敗（來源：{$path}）：" . $e->getMessage());
            throw new RuntimeException('還原失敗，請檢查伺服器記錄或聯絡管理員。');
        }
    }

    /**
     * Opens the candidate read-only and proves it is a complete, compatible portfolio database before
     * any pre-restore snapshot is created.
     *
     * @return array{0: array<string,list<string>>, 1: array<string,int>}
     */
    private static function validateSource(string $path, PDO $main): array
    {
        $source = null;
        try {
            $source = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);

            $damage = $source->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            if (array_map('strval', $damage) !== ['ok']) {
                throw new UserError('備份檔完整性檢查沒有通過，不會覆蓋任何資料。');
            }

            $columns = self::columnPlan($main, $source);
            self::validateNumericTypes($source, $columns);
            self::validateIds($source, $columns);
            self::validateDates($source, $columns);
            $counts = self::sourceCounts($source, $columns);
            if ($source->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new UserError('備份檔的資料關聯不一致，不會覆蓋任何資料。');
            }

            return [$columns, $counts];
        } catch (UserError $e) {
            throw $e;
        } catch (PDOException $e) {
            error_log("備份檔驗證失敗（來源：{$path}）：" . $e->getMessage());
            throw new UserError('無法讀取或檢查這個備份檔，請選擇完整且未損壞的 SQLite 備份。');
        } finally {
            unset($source);
        }
    }

    /**
     * Columns to copy per table: everything our schema has, taken by name so a backup from a newer
     * or older build cannot feed an unexpected column into the wrong slot.
     */
    private static function columnPlan(PDO $main, PDO $source): array
    {
        $tables = (array) $source->query("SELECT name FROM main.sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        $plan = [];
        foreach (self::TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                throw new UserError("備份檔裡沒有「{$table}」這個表，它應該不是本系統的備份。");
            }

            $ours = self::columns($main, 'main', $table);
            $theirs = self::columns($source, 'main', $table);
            $missing = array_diff($ours, $theirs);
            if ($missing !== []) {
                throw new UserError("備份檔的「{$table}」缺少欄位：" . implode('、', $missing) . '。它太舊了，不能拿來還原。');
            }

            // A different key shape can make a seemingly valid copy collide or lose rows. Compare
            // ordinals, not just the set of names, because prices is a composite key.
            if (self::primaryKey($source, $table) !== self::PRIMARY_KEYS[$table]) {
                throw new UserError("備份檔的「{$table}」主鍵結構不相容，不會覆蓋任何資料。");
            }

            $plan[$table] = array_map(
                static fn (string $c): string => self::identifier($c),
                $ours
            );
        }

        return $plan;
    }

    private static function validateNumericTypes(PDO $source, array $plan): void
    {
        foreach (self::NUMERIC_COLUMNS as $table => $numeric) {
            if ($numeric === [] || !isset($plan[$table])) {
                continue;
            }
            $available = array_map(static fn (string $column): string => trim($column, '"'), $plan[$table]);
            $checks = [];
            foreach ($numeric as $column) {
                if (!in_array($column, $available, true)) {
                    continue;
                }
                $quoted = self::identifier($column);
                // SQLite can store 1e999 as REAL infinity even when a CHECK such as value > 0
                // succeeds. Comparing against the IEEE-754 limit rejects both signs without
                // overflowing abs() on the minimum 64-bit integer. SQLite NaN becomes NULL.
                $checks[] = "({$quoted} IS NOT NULL AND (typeof({$quoted}) NOT IN ('integer', 'real')"
                    . " OR {$quoted} > 1.7976931348623157e308"
                    . " OR {$quoted} < -1.7976931348623157e308))";
            }
            if ($checks === []) {
                continue;
            }
            $bad = (int) $source->query('SELECT COUNT(*) FROM main.' . $table . ' WHERE ' . implode(' OR ', $checks))->fetchColumn();
            if ($bad > 0) {
                throw new UserError("備份檔的「{$table}」有 {$bad} 列數值不是有限數值，不會覆蓋任何資料。");
            }
        }
    }

    private static function validateIds(PDO $source, array $plan): void
    {
        foreach (self::ID_COLUMNS as $table => $ids) {
            $available = array_map(static fn (string $column): string => trim($column, '"'), $plan[$table]);
            $checks = [];
            foreach ($ids as $column) {
                if (!in_array($column, $available, true)) {
                    continue;
                }
                $quoted = self::identifier($column);
                $checks[] = "(typeof({$quoted}) <> 'integer' OR {$quoted} <= 0)";
            }
            if ($checks === []) {
                continue;
            }
            $bad = (int) $source->query('SELECT COUNT(*) FROM main.' . $table . ' WHERE ' . implode(' OR ', $checks))->fetchColumn();
            if ($bad > 0) {
                throw new UserError("備份檔的「{$table}」有 {$bad} 列識別欄位不是正整數，不會覆蓋任何資料。");
            }
        }
    }

    private static function validateDates(PDO $source, array $plan): void
    {
        foreach (self::DATE_COLUMNS as $table => $dates) {
            $available = array_map(static fn (string $column): string => trim($column, '"'), $plan[$table]);
            $checks = [];
            foreach ($dates as $column) {
                if (!in_array($column, $available, true)) {
                    continue;
                }
                $quoted = self::identifier($column);
                $checks[] = "(typeof({$quoted}) <> 'text'"
                    . " OR {$quoted} NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'"
                    . " OR date({$quoted}) IS NULL OR date({$quoted}) <> {$quoted})";
            }
            if ($checks === []) {
                continue;
            }
            $bad = (int) $source->query('SELECT COUNT(*) FROM main.' . $table . ' WHERE ' . implode(' OR ', $checks))->fetchColumn();
            if ($bad > 0) {
                throw new UserError("備份檔的「{$table}」有 {$bad} 列日期不正確，不會覆蓋任何資料。");
            }
        }
    }

    /** @return list<string> primary-key columns in their declared ordinal order */
    private static function primaryKey(PDO $pdo, string $table): array
    {
        $rows = $pdo->query("PRAGMA main.table_info({$table})")->fetchAll();
        $keyed = [];
        foreach ($rows as $row) {
            $ordinal = (int) $row['pk'];
            if ($ordinal > 0) {
                $keyed[$ordinal] = (string) $row['name'];
            }
        }
        ksort($keyed);

        return array_values($keyed);
    }

    /** @return list<string> */
    private static function columns(PDO $pdo, string $schema, string $table): array
    {
        $rows = $pdo->query("PRAGMA {$schema}.table_info({$table})")->fetchAll();

        return array_values(array_map(static fn (array $r): string => (string) $r['name'], $rows));
    }

    /** @return array<string,int> */
    private static function sourceCounts(PDO $source, array $plan): array
    {
        $out = [];
        foreach (array_keys($plan) as $table) {
            $out[$table] = (int) $source->query('SELECT COUNT(*) FROM main.' . $table)->fetchColumn();
        }

        return $out;
    }

    /** 不可把 AUTOINCREMENT 高水位調低；目前、備份與還原後最大 id 三者取最大值。 */
    private static function pushSequences(PDO $pdo): void
    {
        $has = (int) $pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn();
        if ($has === 0) {
            return;
        }
        $hasSource = (int) $pdo->query("SELECT COUNT(*) FROM src.sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn();

        foreach (self::SEQUENCES as $table) {
            $current = (int) $pdo->query("SELECT seq FROM main.sqlite_sequence WHERE name = '{$table}'")->fetchColumn();
            $source = $hasSource === 1
                ? (int) $pdo->query("SELECT seq FROM src.sqlite_sequence WHERE name = '{$table}'")->fetchColumn()
                : 0;
            $max = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM main.{$table}")->fetchColumn();
            $seq = max($current, $source, $max);
            $exists = (int) $pdo->query("SELECT COUNT(*) FROM main.sqlite_sequence WHERE name = '{$table}'")->fetchColumn();
            if ($exists === 1) {
                $pdo->exec("UPDATE main.sqlite_sequence SET seq = {$seq} WHERE name = '{$table}'");
            } else {
                $pdo->exec("INSERT INTO main.sqlite_sequence (name, seq) VALUES ('{$table}', {$seq})");
            }
        }
    }

    /** 還原後把所有 quote_* 設定換回本機原本的值，備份不能改動外連政策或節流狀態。 */
    private static function restoreOutlet(PDO $pdo, array $outlet): void
    {
        $pdo->exec("DELETE FROM main.settings WHERE key LIKE 'quote_%'");
        $stmt = $pdo->prepare('INSERT INTO main.settings (key, value) VALUES (?, ?)
                               ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach ($outlet as $row) {
            $stmt->execute([(string) $row['key'], (string) $row['value']]);
        }
    }

    private static function looksLikeSqlite(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = (string) fread($handle, 16);
        fclose($handle);

        return $header === 'SQLite format 3' . "\x00";
    }

    /** Quotes a path for ATTACH, which takes a literal rather than a bound parameter. */
    private static function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function identifier(string $name): string
    {
        if (preg_match('/^\w+$/', $name) !== 1) {
            throw new UserError('備份檔的欄位名稱不合法。');
        }

        return '"' . $name . '"';
    }

    private static function bytesOf(mixed $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        return match (strtoupper(substr($value, -1))) {
            'G'      => (int) $value * 1024 ** 3,
            'M'      => (int) $value * 1024 ** 2,
            'K'      => (int) $value * 1024,
            default  => (int) $value,
        };
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 1) . ' MB';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }

    private static function moment(string $name): string
    {
        if (preg_match('/-(\d{8})-(\d{6})/', $name, $m) !== 1) {
            return $name;
        }

        return sprintf(
            '%s/%s/%s %s:%s:%s',
            substr($m[1], 0, 4),
            substr($m[1], 4, 2),
            substr($m[1], 6, 2),
            substr($m[2], 0, 2),
            substr($m[2], 2, 2),
            substr($m[2], 4, 2)
        );
    }
}
