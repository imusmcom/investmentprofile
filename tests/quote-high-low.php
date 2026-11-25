<?php
declare(strict_types=1);

/** Yahoo OHLC parsing, storage, unchanged-row detection, and range-backfill eligibility. */
$dir = '/tmp/portfolio-quote-range-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "無法建立 /tmp scratch：{$dir}\n");
    exit(2);
}
putenv('PORTFOLIO_DATA_DIR=' . $dir);
require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\QuoteService;

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

echo $fail === 0 ? "QUOTE RANGE ALL PASS\n" : "QUOTE RANGE FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
