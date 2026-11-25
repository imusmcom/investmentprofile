<?php
declare(strict_types=1);

/**
 * 供 smoke 使用的確定性 Yahoo 報價端點；不連任何外部網路：
 *
 *   QUOTE_STUB_DIR=/tmp/stub php -S 127.0.0.1:8999 tests/quote-stub.php
 *
 * 端點只回 Yahoo chart 的結構，每次要求都把代號與範圍記到 <dir>/hits.txt。
 * <dir>/limit 放秒數會回 429；<dir>/token 放權杖就要求 X-Quote-Token；
 * <dir>/shallow 只回最近三日；<dir>/faildeep 只讓深度範圍失敗。
 * QUOTE_STUB_TODAY=YYYY-MM-DD 可固定基準日，smoke 會傳入應用時區的今天，
 * 因此跨午夜或主機時區不同，不會把同一份 fixtures 變成另一組日期。
 */
$dir = rtrim((string) (getenv('QUOTE_STUB_DIR') ?: ''), '/');
$hits = $dir !== '' ? $dir . '/hits.txt' : '';
$limit = ($dir !== '' && is_file($dir . '/limit')) ? trim((string) file_get_contents($dir . '/limit')) : '';
$wantToken = ($dir !== '' && is_file($dir . '/token')) ? trim((string) file_get_contents($dir . '/token')) : '';
$shallow = ($dir !== '' && is_file($dir . '/shallow'));
$failDeep = ($dir !== '' && is_file($dir . '/faildeep'));
$delay = ($dir !== '' && is_file($dir . '/delay')) ? trim((string) file_get_contents($dir . '/delay')) : '';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$query = [];
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $query);
$range = (string) ($query['range'] ?? '');

if ($path === '/health') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Portfolio-Quote-Stub: yes');
    echo "ok\n";
    exit;
}

if (!str_starts_with($path, '/v8/finance/chart/')) {
    http_response_code(404);
    echo json_encode(['chart' => ['error' => ['description' => 'no such path on the stub']]]);
    exit;
}

$symbol = rawurldecode(substr($path, strlen('/v8/finance/chart/')));
if ($hits !== '') {
    file_put_contents($hits, $symbol . ' ' . $range . "\n", FILE_APPEND);
}
if (ctype_digit($delay) && (int) $delay > 0) {
    usleep((int) $delay * 1_000_000);
}

if ($limit !== '') {
    header('Retry-After: ' . $limit);
    http_response_code(429);
    echo json_encode(['chart' => ['error' => ['description' => 'Too many requests']]]);
    exit;
}

if ($wantToken !== '' && (string) ($_SERVER['HTTP_X_QUOTE_TOKEN'] ?? '') !== $wantToken) {
    http_response_code(401);
    echo json_encode(['chart' => ['error' => ['description' => 'wrong or missing X-Quote-Token']]]);
    exit;
}

if ($failDeep && $range !== '' && $range !== '5d' && $range !== '6mo') {
    http_response_code(500);
    echo json_encode(['chart' => ['error' => ['description' => 'the deep range fails on purpose']]]);
    exit;
}

$fx = str_ends_with($symbol, '=X');
$currency = match (strtoupper($symbol)) {
    'TWD=X' => 'TWD',
    'GBP=X' => 'GBP',
    'JPY=X' => 'JPY',
    'HKD=X' => 'HKD',
    'EUR=X' => 'EUR',
    'CAD=X' => 'CAD',
    'MYR=X' => 'MYR',
    default => $fx ? strtoupper(substr($symbol, 0, 3)) : 'USD',
};
$suffix = strtoupper(substr($symbol, (int) strrpos($symbol, '.') + 1));
if (!$fx) {
    $currency = match ($suffix) {
        'L' => 'GBP',
        'F', 'DE' => 'EUR',
        'TO' => 'CAD',
        'KL' => 'MYR',
        'TW', 'TWO' => 'TWD',
        'HK' => 'HKD',
        default => $currency,
    };
}
$price = match (strtoupper($symbol)) {
    'BTC-USD' => 60000.0,
    'TWD=X' => 30.25,
    'GBP=X' => 0.78,
    'JPY=X' => 150.25,
    'HKD=X' => 7.82,
    'EUR=X' => 0.92,
    'CAD=X' => 1.37,
    'MYR=X' => 4.32,
    default => $fx ? 7.5 : 42.5,
};
$today = (string) (getenv('QUOTE_STUB_TODAY') ?: '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
}
$base = strtotime($today . ' 12:00:00 UTC');
if ($base === false) {
    http_response_code(500);
    echo json_encode(['chart' => ['error' => ['description' => 'invalid stub date']]]);
    exit;
}
$now = $base;
$bars = [];
if (!$shallow) {
    // 一根回到十二年前，讓「補抓十年」在一般情況下第一次就達標，測試才能數著一行一次要求。
    $bars[] = ['t' => strtotime('-12 years -4 days', $base), 'c' => 18.0, 'a' => 17.6];
}
$bars[] = ['t' => strtotime('-3 days', $base), 'c' => 19.0, 'a' => 18.6];
$bars[] = ['t' => strtotime('-2 days', $base), 'c' => 20.0, 'a' => 19.5];
$bars[] = ['t' => $now, 'c' => $price, 'a' => round($price * 0.975, 2)];

$indicators = ['quote' => [[
    'close' => array_column($bars, 'c'),
    'high' => array_map(static fn (array $bar): float => $bar['c'] + 1.0, $bars),
    'low' => array_map(static fn (array $bar): float => $bar['c'] - 1.0, $bars),
]]];
// 真實 Yahoo 對加密貨幣與外匯通常沒有 adjclose；不要讓假來源製造不存在的含息口徑。
if (!$fx && !str_ends_with(strtoupper($symbol), '-USD')) {
    $indicators['adjclose'] = [['adjclose' => array_column($bars, 'a')]];
}

header('Content-Type: application/json');
$exchange = match (strtoupper($symbol)) {
    'AAPL', 'MSFT', 'GOOG', 'ANT' => 'NMS',
    'BRK-B', 'V', 'WMT' => 'NYQ',
    'SPY', 'VOO', 'VT', 'QQQ' => 'PCX',
    default => 'NMS',
};
echo json_encode([
    'chart' => [
        'error'  => null,
        'result' => [[
            'meta' => [
                'currency'           => $currency,
                'gmtoffset'          => 0,
                'regularMarketPrice' => $price,
                'regularMarketDayHigh' => $price + 1.0,
                'regularMarketDayLow' => $price - 1.0,
                'regularMarketTime'  => $now,
                'longName'           => 'stub ' . $symbol,
                'exchangeName'       => $exchange,
                'chartPreviousClose' => $fx ? round($price - 0.1, 2) : ($bars === [] ? 21.0 : $bars[count($bars) - 2]['c']),
            ],
            'timestamp'  => array_column($bars, 't'),
            'indicators' => $indicators,
        ]],
    ],
]);
