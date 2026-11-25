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

function candidate(string $path, bool $formula): void
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
    $pdo->exec('INSERT INTO portfolios (id, name) VALUES (200, \'Deleted\')');
    $pdo->exec('DELETE FROM portfolios WHERE id = 200');
    $pdo->exec("UPDATE sqlite_sequence SET seq = 200 WHERE name = 'portfolios'");
}

$pdo = Database::pdo();
$pdo->exec("INSERT INTO portfolios (id, name) VALUES (100, 'Current')");
$pdo->exec('DELETE FROM portfolios WHERE id = 100');
Settings::put('quote_source', 'off');
Settings::put('quote_endpoint', 'https://local.example');
Settings::put('quote_token', 'local-secret');
Settings::put('quote_refresh_budget_seconds', '30');
Settings::put('quote_cooldown_until', '2099-01-01 00:00:00');

$bad = $dir . '/bad.sqlite';
candidate($bad, true);
try {
    Backup::restore($bad);
    fwrite(STDERR, "formula backup was accepted\n");
    exit(1);
} catch (App\UserError $e) {
    if (!str_contains($e->getMessage(), '數值欄位型別不正確')) {
        fwrite(STDERR, 'unexpected rejection: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$good = $dir . '/good.sqlite';
candidate($good, false);
Backup::restore($good);
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
// next connection performs the v8 migration without losing formal data.
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
    || (string) $pdo->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn() !== '8') {
    fwrite(STDERR, "post-restore v8 migration did not remove retired lab settings\n");
    exit(1);
}

echo "BACKUP SAFETY ALL PASS\n";
