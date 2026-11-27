<?php
declare(strict_types=1);

$dir = '/tmp/portfolio-backup-safety-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$cleanup = static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (glob($dir . '/backups/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($dir . '/backups')) {
        rmdir($dir . '/backups');
    }
    rmdir($dir);
};
register_shutdown_function($cleanup);

putenv('PORTFOLIO_DATA_DIR=' . $dir);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Backup;
use App\Database;
use App\Settings;

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function candidate(string $path, bool $formula, ?callable $mutate = null): void
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/app/schema.sql'));
    $pdo->exec("INSERT INTO portfolios (id, name) VALUES (1, 'Restored')");
    $pdo->exec("INSERT INTO instruments (id, symbol, market, yahoo_symbol, currency) VALUES (1, 'A', 'US', 'A', 'USD')");
    $quantity = $formula ? '=1+1' : 2;
    $put = $pdo->prepare('INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price, fee)
                          VALUES (1, 1, \'BUY\', \'2026-01-01\', ?, 10, 0)');
    $put->execute([$quantity]);
    $pdo->exec("UPDATE settings SET value = 'yahoo' WHERE key = 'quote_source'");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('quote_endpoint', 'https://evil.example')");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('quote_token', 'evil')");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('quote_refresh_budget_seconds', '999')");
    // Automatic FX rows use manual=0: a valid snapshot must not be mistaken for a positive-id row.
    $pdo->exec("INSERT INTO fx_rates (currency, units_per_usd, as_of, source, manual, fetched_at)
                VALUES ('EUR', 0.9, '2026-01-01', 'yahoo', 0, '2026-01-01 00:00:00')");
    $pdo->exec('INSERT INTO portfolios (id, name) VALUES (200, \'Deleted\')');
    $pdo->exec('DELETE FROM portfolios WHERE id = 200');
    $pdo->exec("UPDATE sqlite_sequence SET seq = 200 WHERE name = 'portfolios'");
    if ($mutate !== null) {
        $mutate($pdo);
    }
}

/** Rebuild a scratch table when a malformed candidate needs a constraint or key removed. */
function rebuildScratchTable(PDO $pdo, string $table, string $definition): void
{
    $columns = array_map(
        static fn (array $row): string => '"' . $row['name'] . '"',
        $pdo->query("PRAGMA table_info({$table})")->fetchAll()
    );
    $list = implode(', ', $columns);
    $pdo->exec("ALTER TABLE {$table} RENAME TO {$table}_broken");
    $pdo->exec("CREATE TABLE {$table} ({$definition})");
    $pdo->exec("INSERT INTO {$table} ({$list}) SELECT {$list} FROM {$table}_broken");
    $pdo->exec("DROP TABLE {$table}_broken");
}

function expectRejected(string $path, string $messagePart): void
{
    global $dir, $pdo;
    $guards = glob($dir . '/backups/pre-restore-*.sqlite') ?: [];
    $before = $pdo->query('SELECT id, name FROM portfolios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    try {
        Backup::restore($path);
        fail("unsafe backup was accepted: {$path}");
    } catch (App\UserError $e) {
        if (!str_contains($e->getMessage(), $messagePart)) {
            fail('unexpected rejection: ' . $e->getMessage());
        }
    }
    if ((glob($dir . '/backups/pre-restore-*.sqlite') ?: []) !== $guards) {
        fail("rejected backup created a safety snapshot: {$path}");
    }
    if ($pdo->query('SELECT id, name FROM portfolios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) !== $before) {
        fail("rejected backup mutated live portfolios: {$path}");
    }
}

$pdo = Database::pdo();
$pdo->exec("INSERT INTO portfolios (id, name) VALUES (100, 'Current')");
$pdo->exec('DELETE FROM portfolios WHERE id = 100');
Settings::put('quote_source', 'off');
Settings::put('quote_endpoint', 'https://local.example');
Settings::put('quote_token', 'local-secret');
Settings::put('quote_refresh_budget_seconds', '30');
Settings::put('quote_cooldown_until', '2099-01-01 00:00:00');

// A new directory is secured even under a restrictive umask; existing modes are never changed.
$oldUmask = umask(0077);
try {
    $backupDir = Backup::directory();
} finally {
    umask($oldUmask);
}
clearstatcache(true, $backupDir);
if ((int) (fileperms($backupDir) & 0777) !== 0750) {
    fail('new backup directory was not secured to 0750');
}
foreach ([0700, 0755] as $mode) {
    chmod($backupDir, $mode);
    clearstatcache(true, $backupDir);
    if (Backup::directory() !== $backupDir || (int) (fileperms($backupDir) & 0777) !== $mode) {
        fail('existing backup directory permissions were changed: ' . decoct($mode));
    }
}

$bad = $dir . '/bad.sqlite';
candidate($bad, true);
expectRejected($bad, '不是有限數值');

// SQLite CHECK (quantity > 0) accepts positive REAL infinity produced by the literal 1e999.
$inf = $dir . '/infinite.sqlite';
candidate($inf, false, static function (PDO $db): void {
    $db->exec('UPDATE transactions SET quantity = 1e999');
});
expectRejected($inf, '不是有限數值');
$negativeInf = $dir . '/negative-infinite.sqlite';
candidate($negativeInf, false, static function (PDO $db): void {
    $db->exec('UPDATE instruments SET manual_price = -1e999');
});
expectRejected($negativeInf, '不是有限數值');

$missingTable = $dir . '/missing-table.sqlite';
candidate($missingTable, false, static function (PDO $db): void {
    $db->exec('DROP TABLE prices');
});
expectRejected($missingTable, '沒有「prices」這個表');
$missingColumn = $dir . '/missing-column.sqlite';
candidate($missingColumn, false, static function (PDO $db): void {
    $db->exec('ALTER TABLE instruments DROP COLUMN exchange');
});
expectRejected($missingColumn, '缺少欄位：exchange');
$wrongKey = $dir . '/wrong-key.sqlite';
candidate($wrongKey, false, static function (PDO $db): void {
    rebuildScratchTable($db, 'prices', 'instrument_id INTEGER NOT NULL, as_of TEXT NOT NULL, close REAL NOT NULL,
        high REAL, low REAL, prev_close REAL, adj_close REAL, currency TEXT NOT NULL, source TEXT NOT NULL,
        fetched_at TEXT NOT NULL, PRIMARY KEY (as_of, instrument_id)');
});
expectRejected($wrongKey, '主鍵結構不相容');
$negativeId = $dir . '/negative-id.sqlite';
candidate($negativeId, false, static function (PDO $db): void {
    $db->exec('UPDATE portfolios SET id = -1 WHERE id = 1');
});
expectRejected($negativeId, '不是正整數');
$realId = $dir . '/real-id.sqlite';
candidate($realId, false, static function (PDO $db): void {
    $db->exec('UPDATE transactions SET instrument_id = 1.5');
});
expectRejected($realId, '不是正整數');
$badDate = $dir . '/bad-date.sqlite';
candidate($badDate, false, static function (PDO $db): void {
    $db->exec('PRAGMA ignore_check_constraints = ON');
    $db->exec("UPDATE transactions SET traded_on = '2026-02-30'");
});
expectRejected($badDate, '日期不正確');

$good = $dir . '/good.sqlite';
candidate($good, false, static function (PDO $db): void {
    $db->exec('UPDATE transactions SET price = 1.7976931348623157e308');
});
Backup::restore($good);
if (!is_finite((float) $pdo->query('SELECT price FROM transactions')->fetchColumn())) {
    fail('largest finite REAL was not preserved by a valid restore');
}
$expected = [
    'quote_source' => 'off',
    'quote_endpoint' => 'https://local.example',
    'quote_token' => 'local-secret',
    'quote_refresh_budget_seconds' => '30',
    'quote_cooldown_until' => '2099-01-01 00:00:00',
];
foreach ($expected as $key => $value) {
    if (Settings::get($key) !== $value) {
        fwrite(STDERR, "local setting changed: {$key}\n");
        exit(1);
    }
}
$pdo->exec("INSERT INTO portfolios (name) VALUES ('Next')");
if ((int) $pdo->lastInsertId() !== 201) {
    fwrite(STDERR, 'autoincrement high-water mark was lost: ' . $pdo->lastInsertId() . "\n");
    exit(1);
}

// A v6 snapshot may still carry retired lab tables/settings. Restore ignores those extras and the
// next connection performs the current migration without losing formal data.
$old = $dir . '/v6.sqlite';
if (!copy($good, $old)) {
    fwrite(STDERR, "cannot create synthetic v6 snapshot\n");
    exit(1);
}
$legacy = new PDO('sqlite:' . $old, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec('CREATE TABLE lab_turtle_runs (id INTEGER PRIMARY KEY, state_json TEXT)');
$legacy->exec('CREATE TABLE lab_turtle_events (id INTEGER PRIMARY KEY, run_id INTEGER, note TEXT)');
$legacy->exec("INSERT INTO settings (key, value) VALUES ('lab_turtle', '1')");
$legacy->exec("UPDATE settings SET value = '6' WHERE key = 'schema_version'");
$legacy = null;
Backup::restore($old);
if ((int) $pdo->query('SELECT COUNT(*) FROM portfolios')->fetchColumn() < 1
    || (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('lab_turtle_runs','lab_turtle_events')")->fetchColumn() !== 0) {
    fwrite(STDERR, "v6 restore did not preserve formal data and ignore retired tables\n");
    exit(1);
}
$pdo = null;
$property = new ReflectionProperty(Database::class, 'pdo');
$property->setValue(null, null);
$pdo = Database::pdo();
if ((int) $pdo->query("SELECT COUNT(*) FROM settings WHERE substr(key,1,4) = 'lab_'")->fetchColumn() !== 0
    || (string) $pdo->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn() !== '9') {
    fwrite(STDERR, "post-restore current migration did not remove retired lab settings\n");
    exit(1);
}

echo "BACKUP SAFETY ALL PASS\n";
