<?php
declare(strict_types=1);

/** Yahoo OHLC parsing, storage, unchanged-row detection, leases, and range-backfill eligibility. */
if (($argv[1] ?? '') === '--manual-lock-worker') {
    putenv('PORTFOLIO_DATA_DIR=' . $argv[2]);
    require __DIR__ . '/../app/bootstrap.php';
    echo json_encode(App\QuoteService::fetchAndWrite((int) $argv[3]), JSON_THROW_ON_ERROR);
    exit(0);
}

if (($argv[1] ?? '') === '--unsupported-lock-worker') {
    // Model a filesystem/stream where flock exists but always reports unsupported. Defining the
    // namespaced function before autoload exercises QuoteService's real fail-open branch without FUSE.
    eval(<<<'PHP'
namespace App;
function flock($handle, int $operation): bool
{
    return false;
}
PHP);
    putenv('PORTFOLIO_DATA_DIR=' . $argv[2]);
    require __DIR__ . '/../app/bootstrap.php';
    $method = new ReflectionMethod(App\QuoteService::class, 'acquireInstrumentLock');
    $lease = $method->invoke(null, (int) $argv[3]);
    echo $lease['busy'] ? "busy\n" : "acquired\n";
    (new ReflectionMethod(App\QuoteService::class, 'releaseInstrumentLock'))->invoke(null, $lease['handle']);
    echo "released\n";
    exit(0);
}

if (($argv[1] ?? '') === '--refresh-fail-worker') {
    $at = time();
    $GLOBALS['quote_test_payload'] = json_encode([
        'chart' => ['result' => [[
            'meta' => [
                'currency' => 'USD', 'gmtoffset' => 0,
                'regularMarketPrice' => 333.0, 'regularMarketTime' => $at,
                'regularMarketPreviousClose' => 330.0,
            ],
            'timestamp' => [$at - 3 * 86400, $at],
            'indicators' => ['quote' => [[
                'close' => [330.0, 333.0], 'high' => [331.0, 334.0], 'low' => [329.0, 332.0],
            ]]],
        ]]],
    ], JSON_THROW_ON_ERROR);
    eval(<<<'PHP'
namespace App;
function dns_get_record(string $hostname, int $type = DNS_ANY): array
{
    return [['host' => $hostname, 'class' => 'IN', 'ttl' => 60, 'type' => 'A', 'ip' => '93.184.216.34']];
}
function curl_setopt_array(\CurlHandle $handle, array $options): bool
{
    if (array_key_exists(CURLOPT_WRITEFUNCTION, $options)) {
        $GLOBALS['quote_test_write'] = $options[CURLOPT_WRITEFUNCTION];
    }
    return \curl_setopt_array($handle, $options);
}
function curl_exec(\CurlHandle $handle): string|false
{
    $callback = $GLOBALS['quote_test_write'] ?? null;
    if (is_callable($callback)) {
        $callback($handle, $GLOBALS['quote_test_payload']);
    }
    return $GLOBALS['quote_test_payload'];
}
PHP);
    putenv('PORTFOLIO_DATA_DIR=' . $argv[2]);
    require __DIR__ . '/../app/bootstrap.php';
    App\Database::run("CREATE TRIGGER fail_refresh_lease_test BEFORE INSERT ON prices
                      BEGIN SELECT RAISE(ABORT, 'forced refresh lease write failure'); END");
    echo json_encode(App\QuoteService::refresh(), JSON_THROW_ON_ERROR);
    exit(0);
}

if (($argv[1] ?? '') === '--refresh-worker') {
    putenv('PORTFOLIO_DATA_DIR=' . $argv[2]);
    require __DIR__ . '/../app/bootstrap.php';
    echo json_encode(App\QuoteService::refresh(), JSON_THROW_ON_ERROR);
    exit(0);
}

if (($argv[1] ?? '') === '--lock-worker') {
    putenv('PORTFOLIO_DATA_DIR=' . $argv[2]);
    require __DIR__ . '/../app/bootstrap.php';
    $method = new ReflectionMethod(App\QuoteService::class, 'acquireInstrumentLock');
    $lease = $method->invoke(null, (int) $argv[3]);
    echo $lease['busy'] ? "busy\n" : "acquired\n";
    if (isset($argv[4]) && $argv[4] === 'hold') {
        usleep(300000);
    }
    (new ReflectionMethod(App\QuoteService::class, 'releaseInstrumentLock'))->invoke(null, $lease['handle']);
    echo "released\n";
    exit(0);
}

$dir = '/tmp/portfolio-quote-range-' . bin2hex(random_bytes(6));
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
$parse = new ReflectionMethod(QuoteService::class, 'parse');
$payload = static function (array $quote, array $meta = []): array {
    $base = strtotime('2026-11-24 12:00:00 UTC');

    return [
        'meta' => array_replace([
            'currency' => 'USD',
            'gmtoffset' => 0,
            'regularMarketPrice' => 100.0,
            'regularMarketTime' => $base,
        ], $meta),
        'timestamp' => [$base - 2 * 86400, $base - 86400, $base],
        'indicators' => ['quote' => [$quote]],
    ];
};

$parsed = $parse->invoke(null, $payload([
    'close' => [96.0, 98.0, 100.0],
    'high' => [97.0, 99.0, 101.0],
    'low' => [95.0, 97.0, 99.0],
]));
$ok('parse 保留當日與歷史 high/low', ($parsed['high'] ?? null) === 101.0
    && ($parsed['low'] ?? null) === 99.0
    && ($parsed['history'][0]['high'] ?? null) === 97.0
    && ($parsed['history'][0]['low'] ?? null) === 95.0
    && ($parsed['history'][1]['high'] ?? null) === 99.0
    && ($parsed['history'][1]['low'] ?? null) === 97.0, json_encode($parsed));

$parsed = $parse->invoke(null, $payload(
    ['close' => [96.0, 98.0, 100.0], 'high' => [97.0, 99.0, 99.0], 'low' => [95.0, 97.0, 101.0]],
    ['regularMarketDayHigh' => 102.0, 'regularMarketDayLow' => 98.0]
));
$ok('當日 quote 高低價不完整時使用 meta，而非 close 冒充', ($parsed['high'] ?? null) === 102.0
    && ($parsed['low'] ?? null) === 98.0, json_encode($parsed));

$parsed = $parse->invoke(null, $payload([
    'close' => [96.0, 98.0, 100.0],
    'high' => [97.0, 96.0, null],
    'low' => [95.0, null, null],
]));
$ok('歷史缺一半或高低倒置時整組留 NULL', ($parsed['history'][1]['high'] ?? null) === null
    && ($parsed['history'][1]['low'] ?? null) === null
    && ($parsed['history'][0]['high'] ?? null) === 97.0, json_encode($parsed));

Database::run("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class)
               VALUES ('RANGE','RANGE','US','RANGE','USD','EQUITY')");
$id = (int) Database::pdo()->lastInsertId();
$quote = [
    'ok' => true, 'price' => 100.0, 'high' => 101.0, 'low' => 99.0,
    'prev' => 98.0, 'adj' => 100.0, 'currency' => 'USD', 'as_of' => '2026-11-24',
    'history' => [
        ['date' => '2026-11-23', 'close' => 98.0, 'high' => 99.0, 'low' => 97.0, 'adj' => 98.0],
        ['date' => '2026-11-22', 'close' => 98.0, 'high' => 96.0, 'low' => 99.0, 'adj' => 98.0],
        ['date' => '2026-11-21', 'close' => 96.0, 'high' => 97.0, 'low' => null, 'adj' => 96.0],
    ],
];
QuoteService::writeQuote($id, $quote, '2026-11-24 10:00:00');
$stored = static fn (string $date): array => Database::one(
    'SELECT * FROM prices WHERE instrument_id = ? AND as_of = ?', [$id, $date]
) ?? [];
$ok('現價與有效歷史範圍寫入，無效範圍不污染 close', ($stored('2026-11-24')['high'] ?? null) == 101.0
    && ($stored('2026-11-24')['low'] ?? null) == 99.0
    && ($stored('2026-11-23')['high'] ?? null) == 99.0
    && ($stored('2026-11-23')['low'] ?? null) == 97.0
    && ($stored('2026-11-22')['close'] ?? null) == 98.0
    && ($stored('2026-11-22')['high'] ?? null) === null
    && ($stored('2026-11-22')['low'] ?? null) === null
    && ($stored('2026-11-21')['high'] ?? null) === null
    && ($stored('2026-11-21')['low'] ?? null) === null, json_encode($stored('2026-11-22')));

QuoteService::writeQuote($id, $quote, '2026-11-24 11:00:00');
$ok('完全相同的歷史高低價不刷新 fetched_at', ($stored('2026-11-23')['fetched_at'] ?? null) === '2026-11-24 10:00:00');
$ok('現價列仍刷新 fetched_at', ($stored('2026-11-24')['fetched_at'] ?? null) === '2026-11-24 11:00:00');

$quote['history'][0]['high'] = 100.0;
$quote['history'][0]['low'] = 96.0;
QuoteService::writeQuote($id, $quote, '2026-11-24 12:00:00');
$ok('只有 high/low 變動也會回填並刷新歷史列', ($stored('2026-11-23')['high'] ?? null) == 100.0
    && ($stored('2026-11-23')['low'] ?? null) == 96.0
    && ($stored('2026-11-23')['fetched_at'] ?? null) === '2026-11-24 12:00:00', json_encode($stored('2026-11-23')));

$quote['high'] = null;
$quote['low'] = null;
unset($quote['history'][0]['high'], $quote['history'][0]['low']);
QuoteService::writeQuote($id, $quote, '2026-11-24 13:00:00');
$ok('來源缺 high/low 不會抹掉已知範圍', ($stored('2026-11-24')['high'] ?? null) == 101.0
    && ($stored('2026-11-24')['low'] ?? null) == 99.0
    && ($stored('2026-11-23')['high'] ?? null) == 100.0
    && ($stored('2026-11-23')['fetched_at'] ?? null) === '2026-11-24 12:00:00');

Database::run("INSERT INTO prices (instrument_id,as_of,close,currency,source)
               VALUES (?, '2010-01-04', 50, 'USD', 'yahoo'),
                      (?, '2010-01-05', 50, 'USD', 'manual')", [$id, $id]);
$rangeOwed = new ReflectionMethod(QuoteService::class, 'rangeOwed');
$remember = new ReflectionMethod(QuoteService::class, 'rememberRangeCoverage');
$ok('舊 yahoo 列缺 high/low 時需要深抓，人工列不強迫', $rangeOwed->invoke(null, $id) === true);
$remember->invoke(null, $id);
$ok('深抓後同樣缺漏數在重試期內不反覆要求', $rangeOwed->invoke(null, $id) === false);
Database::run("INSERT INTO prices (instrument_id,as_of,close,currency,source)
               VALUES (?, '2010-01-06', 50, 'USD', 'yahoo')", [$id]);
$ok('缺漏數再增加時重新放行深抓', $rangeOwed->invoke(null, $id) === true);

$badPeriod = QuoteService::get('VT', null, null, 100, 100);
$ok('定區間回補會拒絕無效的 period1／period2', $badPeriod['ok'] === false
    && $badPeriod['error'] === '歷史區間無效。');

echo "-- 跨程序 lease 與過期回應\n";
$lock = new ReflectionMethod(QuoteService::class, 'acquireInstrumentLock');
$unlock = new ReflectionMethod(QuoteService::class, 'releaseInstrumentLock');
$worker = static function (string $mode) use ($dir, $id): string {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, $mode, $dir, (string) $id], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('無法啟動 quote 子程序');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('quote 子程序失敗：' . $err);
    }

    return (string) $out;
};
$lease = $lock->invoke(null, $id);
$ok('lease 放在既有可寫資料目錄', is_resource($lease['handle'])
    && is_file($dir . '/locks/quote-' . $id . '.lock'));
$ok('另一程序不能取得同一標的的 lease', $worker('--lock-worker') === "busy\nreleased\n");
$busyRefresh = json_decode($worker('--refresh-worker'), true, 512, JSON_THROW_ON_ERROR);
$ok('refresh 在 freshness 與網路前發現 lease 忙碌並略過', ($busyRefresh['updated'] ?? null) === 0
    && ($busyRefresh['skipped'] ?? null) === 1 && ($busyRefresh['failed'] ?? null) === 0,
    json_encode($busyRefresh));
$busyManual = json_decode($worker('--manual-lock-worker'), true, 512, JSON_THROW_ON_ERROR);
$ok('手動報價在取得 lease 前不查價或寫入', $busyManual['ok'] === false
    && $busyManual['price'] === null
    && $busyManual['error'] === '另一項更新正在處理這檔投資物，請稍後再試。', json_encode($busyManual));
$unlock->invoke(null, $lease['handle']);
$ok('釋放後另一程序可取得並正常釋放', $worker('--lock-worker') === "acquired\nreleased\n");
$ok('鎖檔保留以免 unlink／flock inode 競態', is_file($dir . '/locks/quote-' . $id . '.lock'));

$configProperty = new ReflectionProperty(QuoteService::class, 'config');
$config = $configProperty->getValue();
$configProperty->setValue(null, array_replace($config, ['data_dir' => '/proc/portfolio-locks-unavailable']));
$fallback = $lock->invoke(null, $id);
$configProperty->setValue(null, $config);
$ok('檔案鎖不可用時 fail open', $fallback === ['handle' => null, 'busy' => false]);
$ok('flock 存在但不支援鎖時也 fail open，不誤判為忙碌', $worker('--unsupported-lock-worker') === "acquired\nreleased\n");

$freshQuote = $quote;
$freshQuote['price'] = 210.0;
$freshQuote['as_of'] = '2026-11-25';
$freshQuote['history'] = [
    ['date' => '2026-11-24', 'close' => 205.0, 'high' => 206.0, 'low' => 204.0, 'adj' => 205.0],
    ['date' => '2026-11-23', 'close' => 201.0, 'high' => 202.0, 'low' => 200.0, 'adj' => 201.0],
];
QuoteService::writeQuote($id, $freshQuote, '2026-11-25 12:00:00');
$staleQuote = $quote;
$staleQuote['price'] = 110.0;
$staleQuote['history'] = [
    ['date' => '2026-11-24', 'close' => 108.0, 'high' => 109.0, 'low' => 107.0, 'adj' => 108.0],
    ['date' => '2026-11-23', 'close' => 106.0, 'high' => 107.0, 'low' => 105.0, 'adj' => 106.0],
    ['date' => '2026-11-26', 'close' => 109.0, 'adj' => 109.0],
];
QuoteService::writeQuote($id, $staleQuote, '2026-11-25 11:00:00');
$newCurrent = Database::one('SELECT * FROM prices WHERE instrument_id = ? AND as_of = ?', [$id, '2026-11-25']) ?? [];
$newHistory = Database::one('SELECT * FROM prices WHERE instrument_id = ? AND as_of = ?', [$id, '2026-11-24']) ?? [];
$ok('較舊現價回應不能覆蓋較新 fetched_at', (float) ($newCurrent['close'] ?? 0) === 210.0
    && $newCurrent['fetched_at'] === '2026-11-25 12:00:00', json_encode($newCurrent));
$ok('較舊現價被拒時也不混入它的歷史', (float) ($newHistory['close'] ?? 0) === 205.0
    && $newHistory['fetched_at'] === '2026-11-25 12:00:00'
    && Database::one('SELECT 1 FROM prices WHERE instrument_id = ? AND as_of = ?', [$id, '2026-11-26']) === null,
    json_encode($newHistory));

// Keep the current row older so the history-only stale guard is exercised independently.
$historyQuote = $staleQuote;
$historyQuote['as_of'] = '2026-11-27';
$historyQuote['price'] = 210.0;
QuoteService::writeQuote($id, $historyQuote, '2026-11-25 11:00:00');
$newHistory = Database::one('SELECT * FROM prices WHERE instrument_id = ? AND as_of = ?', [$id, '2026-11-24']) ?? [];
$ok('歷史逐列也拒絕較舊 fetched_at', (float) ($newHistory['close'] ?? 0) === 205.0
    && $newHistory['fetched_at'] === '2026-11-25 12:00:00', json_encode($newHistory));

Database::run("CREATE TRIGGER fail_lease_test BEFORE INSERT ON prices
               BEGIN SELECT RAISE(ABORT, 'forced lease write failure'); END");
$failed = false;
$freshQuote['as_of'] = '2026-11-28';
try {
    QuoteService::writeQuote($id, $freshQuote, '2026-11-25 13:00:00');
} catch (UserError $e) {
    $failed = true;
}
Database::run('DROP TRIGGER fail_lease_test');
$afterFailure = $lock->invoke(null, $id);
$ok('寫入失敗後 lease 仍可重新取得', $failed && !$afterFailure['busy'] && is_resource($afterFailure['handle']));
$unlock->invoke(null, $afterFailure['handle']);

echo $fail === 0 ? "QUOTE RANGE ALL PASS\n" : "QUOTE RANGE FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
