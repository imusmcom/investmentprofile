<?php
declare(strict_types=1);

/** Targeted writeQuote currency guard; only a self-created /tmp scratch database is used. */
$dir = '/tmp/portfolio-quote-currency-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "無法建立 /tmp scratch：{$dir}\n");
    exit(2);
}
putenv('PORTFOLIO_DATA_DIR=' . $dir);
require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\QuoteService;
use App\UserError;

register_shutdown_function(static function () use ($dir): void {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
});

$fail = 0;
$ok = static function (string $name, bool $pass, string $got = '') use (&$fail): void {
    echo ($pass ? '  ok   ' : '  FAIL ') . $name . (!$pass && $got !== '' ? "（{$got}）" : '') . "\n";
    if (!$pass) {
        $fail++;
    }
};

Database::run("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES ('CUR','CUR','US','CUR','USD','EQUITY')");
$id = (int) Database::pdo()->lastInsertId();
$quote = [
    'ok' => true, 'price' => 100.0, 'prev' => 99.0, 'adj' => 100.0,
    'currency' => 'TWD', 'as_of' => '2026-11-24',
    'history' => [['date' => '2026-11-23', 'close' => 99.0, 'adj' => 99.0]],
];
$rejected = false;
$message = '';
try {
    QuoteService::writeQuote($id, $quote);
} catch (UserError $e) {
    $rejected = true;
    $message = $e->getMessage();
}
$ok('不同來源幣別拋出 UserError', $rejected, $message);
$ok('錯誤訊息明確列出來源與標的幣別', str_contains($message, 'TWD') && str_contains($message, 'USD')
    && str_contains($message, '拒絕寫入'), $message);
$ok('拒絕後不寫入現價或歷史', (int) Database::scalar('SELECT COUNT(*) FROM prices WHERE instrument_id = ?', [$id]) === 0, '');
$ok('拒絕後不修改投資物幣別或名稱', (string) Database::scalar('SELECT currency FROM instruments WHERE id = ?', [$id]) === 'USD'
    && (string) Database::scalar('SELECT name FROM instruments WHERE id = ?', [$id]) === 'CUR', '');

$quote['currency'] = 'USD';
$currency = QuoteService::writeQuote($id, $quote, '2026-11-24 10:00:00');
$ok('同幣別來源正常寫入', $currency === 'USD'
    && (int) Database::scalar('SELECT COUNT(*) FROM prices WHERE instrument_id = ?', [$id]) === 2, $currency);

Database::run(
    "INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source,fetched_at)
     VALUES (?, '2026-11-20', 95, 95, 'USD', 'manual', '2026-11-24 09:00:00'),
            (?, '2026-11-19', 94, 94, 'USD', 'manual', '2026-11-24 09:00:00')",
    [$id, $id]
);
$stored = static fn (string $date): array => Database::one(
    'SELECT * FROM prices WHERE instrument_id = ? AND as_of = ?', [$id, $date]
) ?? [];

echo "-- 歷史日線只寫入新值或變動\n";
QuoteService::writeQuote($id, $quote, '2026-11-24 11:00:00');
$ok('完全相同的歷史列不刷新 fetched_at', ($stored('2026-11-23')['fetched_at'] ?? null) === '2026-11-24 10:00:00',
    var_export($stored('2026-11-23'), true));
$ok('現價即使相同也刷新 fetched_at', ($stored('2026-11-24')['fetched_at'] ?? null) === '2026-11-24 11:00:00',
    var_export($stored('2026-11-24'), true));

$quote['history'] = [
    ['date' => '2026-11-23', 'close' => 99.0, 'adj' => 99.0],
    ['date' => '2026-11-22', 'close' => 98.0, 'adj' => 98.0],
    ['date' => '2026-11-21', 'close' => 97.0, 'adj' => 97.0],
];
QuoteService::writeQuote($id, $quote, '2026-11-24 12:00:00');
$quote['history'] = [
    ['date' => '2026-11-23', 'close' => 101.0, 'adj' => null],
    ['date' => '2026-11-22', 'close' => 98.0, 'adj' => 96.0],
    ['date' => '2026-11-21', 'close' => 97.0, 'adj' => 97.0],
    ['date' => '2026-11-20', 'close' => 95.0, 'adj' => null],
    ['date' => '2026-11-19', 'close' => 93.0, 'adj' => null],
    ['date' => '2026-11-18', 'close' => 92.0, 'adj' => null],
    ['date' => 'not-a-date', 'close' => 1.0, 'adj' => 1.0],
    ['date' => '2026-11-17', 'close' => 0.0, 'adj' => 0.0],
];
QuoteService::writeQuote($id, $quote, '2026-11-24 13:00:00');
$ok('close 變動且新 adj 缺漏時保留舊 adj_close', (float) ($stored('2026-11-23')['close'] ?? 0) === 101.0
    && (float) ($stored('2026-11-23')['adj_close'] ?? 0) === 99.0
    && ($stored('2026-11-23')['fetched_at'] ?? null) === '2026-11-24 13:00:00', var_export($stored('2026-11-23'), true));
$ok('adj_close 單獨變動會更新該列', (float) ($stored('2026-11-22')['adj_close'] ?? 0) === 96.0
    && ($stored('2026-11-22')['fetched_at'] ?? null) === '2026-11-24 13:00:00', var_export($stored('2026-11-22'), true));
$ok('新列寫入，未提供 adj_close 時保持 NULL', (float) ($stored('2026-11-18')['close'] ?? 0) === 92.0
    && array_key_exists('adj_close', $stored('2026-11-18')) && $stored('2026-11-18')['adj_close'] === null
    && ($stored('2026-11-18')['fetched_at'] ?? null) === '2026-11-24 13:00:00', var_export($stored('2026-11-18'), true));
$ok('前一輪的新列再次取得時不刷新', ($stored('2026-11-21')['fetched_at'] ?? null) === '2026-11-24 12:00:00',
    var_export($stored('2026-11-21'), true));
$ok('相同人工歷史列保留時間與 source', ($stored('2026-11-20')['fetched_at'] ?? null) === '2026-11-24 09:00:00'
    && ($stored('2026-11-20')['source'] ?? null) === 'manual', var_export($stored('2026-11-20'), true));
$ok('變動人工歷史列保留 source 與 COALESCE 後 adj_close', ($stored('2026-11-19')['source'] ?? null) === 'manual'
    && (float) ($stored('2026-11-19')['close'] ?? 0) === 93.0
    && (float) ($stored('2026-11-19')['adj_close'] ?? 0) === 94.0, var_export($stored('2026-11-19'), true));
$ok('無效歷史列仍略過', $stored('2026-11-17') === [], var_export($stored('2026-11-17'), true));

Database::run('UPDATE instruments SET currency = ? WHERE id = ?', ['EUR', $id]);
$quote['currency'] = 'EUR';
$quote['history'] = [['date' => '2026-11-21', 'close' => 97.0, 'adj' => 97.0]];
$currency = QuoteService::writeQuote($id, $quote, '2026-11-24 14:00:00');
$ok('歷史幣別變動會更新並回傳標的幣別', $currency === 'EUR'
    && ($stored('2026-11-21')['currency'] ?? null) === 'EUR'
    && ($stored('2026-11-21')['fetched_at'] ?? null) === '2026-11-24 14:00:00'
    && ($stored('2026-11-23')['currency'] ?? null) === 'USD', var_export($stored('2026-11-21'), true));

Database::run(
    "CREATE TRIGGER fail_quote_test BEFORE INSERT ON prices WHEN NEW.as_of = '2026-11-17'
     BEGIN SELECT RAISE(ABORT, 'forced quote failure'); END"
);
$quote['history'] = [
    ['date' => '2026-11-21', 'close' => 96.0, 'adj' => 96.0],
    ['date' => '2026-11-17', 'close' => 91.0, 'adj' => 91.0],
];
$rejected = false;
try {
    QuoteService::writeQuote($id, $quote, '2026-11-24 15:00:00');
} catch (UserError $e) {
    $rejected = true;
}
$ok('後半段失敗會回滾現價與已變動歷史', $rejected
    && ($stored('2026-11-24')['fetched_at'] ?? null) === '2026-11-24 14:00:00'
    && (float) ($stored('2026-11-21')['close'] ?? 0) === 97.0
    && ($stored('2026-11-21')['fetched_at'] ?? null) === '2026-11-24 14:00:00'
    && $stored('2026-11-17') === [], 'quote transaction did not roll back');

echo $fail === 0 ? "QUOTE CURRENCY ALL PASS\n" : "QUOTE CURRENCY FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
