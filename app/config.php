<?php
declare(strict_types=1);

$root = dirname(__DIR__);

// PORTFOLIO_DATA_DIR may be relative, but php -S changes the working directory to the
// document root — resolve it against the project root so the database cannot drift.
$dataDir = (string) (getenv('PORTFOLIO_DATA_DIR') ?: '');
if ($dataDir === '') {
    $deployedDataDir = '/www/portfolio-data';
    $dataDir = $root === '/www/wwwroot/portfolio' && is_file($deployedDataDir . '/portfolio.sqlite')
        ? $deployedDataDir
        : $root . '/data';
} elseif (!preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $dataDir)) {
    $dataDir = $root . '/' . $dataDir;
}

return [
    'data_dir' => $dataDir,

    // Set to true once you terminate TLS in front of this app.
    'session_secure_cookie' => false,

    'quote' => [
        // QUOTE_ENABLED=false turns off all outbound requests; prices stay fully manual.
        'enabled'    => getenv('QUOTE_ENABLED') !== 'false',
        'timeout'    => (int) (getenv('QUOTE_TIMEOUT') ?: 12),
        'range'      => (string) (getenv('QUOTE_RANGE') ?: '6mo'),
        // How far the 回報 page's history goes back; 'max' works too but writes many more rows.
        'history_range' => (string) (getenv('QUOTE_HISTORY_RANGE') ?: '10y'),
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',

        // 節流三個預設值，都可以在設定頁用資料庫裡的設定值蓋掉（檔名同樣的 key），因為這台的 php.ini
        // 是主機面板在管、跟別的站台共用同一個 pool，環境變數不是個能轉的旋鈕。
        // 一個投資物在這麼多久之內抓過，按「更新報價」就不會再打一次。
        'min_refresh_minutes' => (int) (getenv('QUOTE_MIN_REFRESH_MINUTES') ?: 10),
        // 來源回報 429/403 之後整個來源暫停多久（它有給 Retry-After 就聽它的）。
        'cooldown_minutes'    => (int) (getenv('QUOTE_COOLDOWN_MINUTES') ?: 30),
        // 相鄰兩檔之間歇多久，免得一次更新讀起來像打炮。
        'gap_ms'              => (int) (getenv('QUOTE_GAP_MS') ?: 300),
        // Web 報價更新自行收工的秒數；設定頁可覆寫，CLI 不受此預算限制。
        'refresh_budget_seconds' => (int) (getenv('QUOTE_REFRESH_BUDGET') ?: 240),
    ],
];
