<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('此腳本僅限命令列執行。');
}

/**
 * Fill the database with a small demo portfolio so the UI can be evaluated without typing trades.
 * Refuses to touch a database that already holds anything of yours unless --force is passed.
 * --force replaces only the exact, unmodified demo portfolios recorded by this tool; a familiar
 * "Demo" prefix is never proof of ownership.
 *
 *   php bin/seed-demo.php [--force]
 */

require __DIR__ . '/../app/cli.php';

$usage = 'php bin/seed-demo.php [--force] [--data-dir=目錄] [--tz=時區]';
$opts = cli_setup($argv, ['help', 'h', 'force'], ['data-dir', 'tz'], $usage);

if (isset($opts['help']) || isset($opts['h'])) {
    echo <<<TXT
用法：php bin/seed-demo.php [--force] [--data-dir=目錄] [--tz=時區]

  --force      只重播本工具建立且完全未被修改的示範組合；不會刪除其他 Demo 開頭或同名的人為資料
  --data-dir   寫入哪個目錄裡的 portfolio.sqlite；預設讀 PORTFOLIO_DATA_DIR，否則使用正式部署資料庫或專案下的 data/
  --tz         時間戳用的時區；未給時沿用 php.ini 的 date.timezone，它也沒設就用 Asia/Hong_Kong（UTC+8，不撥夏令時間）

示範資料只插不刪，除非明確使用 --force。--force 會先核對本工具記錄的組合 id、內容與指紋，
只刪除完全一致的組合，再在同一交易裡重新 seeding；任何無法識別、事後被修改或寫入失敗都會整筆回復。
既有資料筆數不為零時，沒有 --force 就直接中止（回 1）。
寫錯的參數會被拒絕（回 2），不會被忽略後照樣動到預設資料庫。

TXT;
    exit(0);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Markets;
use App\QuoteService;
use App\Settings;

$force = isset($opts['force']);
const SEED_DEMO_OWNER_KEY = 'seed_demo.ownership.v1';

$portfolios = [
    'Demo 長期投資' => '核心持股，定期定額',
    'Demo 波段交易' => '已實現損益與停損示範',
];

/** Hash the complete portfolio state, including every trade and cash row owned by it. */
function seed_demo_fingerprint(PDO $pdo, int $portfolioId): string
{
    $portfolio = $pdo->prepare('SELECT id, name, description, created_at FROM portfolios WHERE id = ?');
    $portfolio->execute([$portfolioId]);
    $head = $portfolio->fetch(PDO::FETCH_ASSOC);
    if ($head === false) {
        throw new RuntimeException("找不到示範組合 id {$portfolioId}，無法安全識別。");
    }

    $trades = $pdo->prepare(
        'SELECT id, instrument_id, side, traded_on, quantity, price, fee, note, created_at
         FROM transactions WHERE portfolio_id = ? ORDER BY id'
    );
    $trades->execute([$portfolioId]);
    $cash = $pdo->prepare(
        'SELECT id, moved_on, direction, amount, currency, note, created_at
         FROM cash_movements WHERE portfolio_id = ? ORDER BY id'
    );
    $cash->execute([$portfolioId]);

    return hash('sha256', json_encode([
        $head,
        $trades->fetchAll(PDO::FETCH_ASSOC),
        $cash->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Return only ids proved to be this tool's untouched demo portfolios. Anything ambiguous throws
 * before a DELETE is issued, so a user's similarly named or subsequently edited portfolio survives.
 */
function seed_demo_owned_ids(PDO $pdo, array $definitions): array
{
    $raw = Database::scalar('SELECT value FROM settings WHERE key = ?', [SEED_DEMO_OWNER_KEY], null);
    if ($raw === null) {
        return [];
    }

    try {
        $manifest = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('示範組合所有權記錄不是有效 JSON，拒絕刪除。');
    }
    if (!is_array($manifest)) {
        throw new RuntimeException('示範組合所有權記錄格式不完整，拒絕刪除。');
    }
    $entries = $manifest['portfolios'] ?? null;
    if (($manifest['version'] ?? null) !== 1
        || !is_array($entries) || count($entries) !== count($definitions)) {
        throw new RuntimeException('示範組合所有權記錄格式不完整，拒絕刪除。');
    }

    $ids = [];
    $seen = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('示範組合所有權記錄含無效項目，拒絕刪除。');
        }
        $name = $entry['name'] ?? null;
        $id = $entry['id'] ?? null;
        $description = $entry['description'] ?? null;
        $createdAt = $entry['created_at'] ?? null;
        $fingerprint = $entry['fingerprint'] ?? null;
        if (!is_string($name) || !isset($definitions[$name]) || $description !== $definitions[$name]
            || !is_int($id) || $id < 1 || !is_string($createdAt) || $createdAt === ''
            || !is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1
            || isset($seen[$name])) {
            throw new RuntimeException("示範組合所有權記錄無法精確識別「{$name}」，拒絕刪除。");
        }

        $row = Database::one('SELECT id FROM portfolios WHERE id = ? AND name = ?', [$id, $name]);
        if ($row === null || !hash_equals($fingerprint, seed_demo_fingerprint($pdo, $id))) {
            throw new RuntimeException("示範組合「{$name}」已不存在、被修改或指紋不符，拒絕刪除。");
        }
        $seen[$name] = true;
        $ids[] = $id;
    }
    if (count($seen) !== count($definitions)) {
        throw new RuntimeException('示範組合所有權記錄缺少預期的組合，拒絕刪除。');
    }

    return $ids;
}

// Checked before anything is written: base_currency defaults and the demo rows would otherwise land
// in the operator's own database on a run that then refuses the seed.
$counts = [
    '交易紀錄' => (int) Database::scalar('SELECT COUNT(*) FROM transactions', [], 0),
    '現金流' => (int) Database::scalar('SELECT COUNT(*) FROM cash_movements', [], 0),
    '投資組合' => (int) Database::scalar('SELECT COUNT(*) FROM portfolios', [], 0),
];
$held = array_filter($counts);

if ($held !== [] && !$force) {
    $parts = [];
    foreach ($held as $label => $n) {
        $parts[] = "{$label} {$n} 筆";
    }
    $db = rtrim((string) (require __DIR__ . '/../app/config.php')['data_dir'], "/\\") . '/portfolio.sqlite';
    fwrite(STDERR, "這個資料庫已經有資料（" . implode('、', $parts) . '）：' . $db . "\n");
    fwrite(STDERR, "確定要另外插入示範投資組合，請加 --force；要試功能請改用另一份資料庫，例如 PORTFOLIO_DATA_DIR=/tmp/demo php bin/seed-demo.php。\n");
    exit(1);
}

$pdo = Database::pdo();
$pdo->beginTransaction();

try {
    if (!Settings::get('base_currency')) {
        Settings::put('base_currency', 'TWD');
    }

    if ($force) {
        foreach (seed_demo_owned_ids($pdo, $portfolios) as $ownedId) {
            Database::run('DELETE FROM transactions WHERE portfolio_id = ?', [$ownedId]);
            Database::run('DELETE FROM cash_movements WHERE portfolio_id = ?', [$ownedId]);
            $deleted = Database::run('DELETE FROM portfolios WHERE id = ?', [$ownedId]);
            if ($deleted->rowCount() !== 1) {
                throw new RuntimeException("示範組合 id {$ownedId} 刪除筆數不符，整筆回復。");
            }
        }
    }

    // Never attach this tool's trades to an existing portfolio merely because its name matches.
    foreach ($portfolios as $name => $description) {
        if (Database::scalar('SELECT id FROM portfolios WHERE name = ?', [$name]) !== null) {
            throw new RuntimeException("已有同名組合「{$name}」，但無法證明是本工具建立且未被修改，拒絕刪除或寫入。");
        }
    }

    $ids = [];
    // The trades are dated in the past on purpose; when they were written down is now.
    $now = now_stamp();
    foreach ($portfolios as $name => $description) {
        Database::run('INSERT INTO portfolios (name, description, created_at) VALUES (?, ?, ?)', [$name, $description, $now]);
        $ids[$name] = (int) Database::scalar('SELECT id FROM portfolios WHERE name = ?', [$name]);
    }

    $instruments = [
        ['2330', '台積電', 'TW'],
        ['0050', '元大台灣50', 'TW'],
        ['AAPL', 'Apple', 'US'],
        ['NVDA', 'NVIDIA', 'US'],
        ['0700', '騰訊', 'HK'],
        ['BRK-B', 'Berkshire Hathaway', 'US'],
    ];
    $iid = [];
    $currency = [];
    foreach ($instruments as [$symbol, $name, $market]) {
        $yahoo = Markets::toYahoo($symbol, $market);
        $currency[$symbol] = Markets::currencyFor($market);
        Database::run(
            'INSERT OR IGNORE INTO instruments (symbol, name, market, yahoo_symbol, currency, asset_class, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$symbol, $name, $market, $yahoo, $currency[$symbol], 'EQUITY', $now]
        );
        $iid[$symbol] = (int) Database::scalar('SELECT id FROM instruments WHERE yahoo_symbol = ?', [$yahoo]);
    }

    // [portfolio, symbol, side, date, qty, price, fee, note]
    $trades = [
        ['Demo 長期投資', '2330', 'BUY', '2024-06-14', 200, 995, 34, '定期定額'],
        ['Demo 長期投資', '2330', 'BUY', '2024-12-16', 150, 1185, 29, '定期定額'],
        ['Demo 長期投資', '2330', 'BUY', '2025-06-13', 100, 1320, 20, '定期定額'],
        ['Demo 長期投資', '0050', 'BUY', '2024-01-15', 300, 88.5, 30, '年終投入'],
        ['Demo 長期投資', '0050', 'BUY', '2025-01-16', 200, 102.4, 25, ''],
        ['Demo 長期投資', 'AAPL', 'BUY', '2024-03-01', 40, 168.2, 5, ''],
        ['Demo 長期投資', 'AAPL', 'BUY', '2025-05-02', 25, 192.8, 5, '加碼'],
        ['Demo 長期投資', 'NVDA', 'BUY', '2024-08-05', 30, 121.4, 5, ''],
        ['Demo 長期投資', '0700', 'BUY', '2024-09-10', 200, 372.5, 60, ''],

        ['Demo 波段交易', 'AAPL', 'BUY', '2025-01-06', 60, 186.0, 6, ''],
        ['Demo 波段交易', 'AAPL', 'SELL', '2025-07-07', 40, 231.5, 6, '部分獲利了結'],
        ['Demo 波段交易', 'NVDA', 'BUY', '2025-02-10', 50, 136.7, 6, ''],
        ['Demo 波段交易', 'NVDA', 'SELL', '2025-09-15', 50, 178.3, 6, '全部出清'],
        ['Demo 波段交易', 'BRK-B', 'BUY', '2025-03-03', 15, 412.0, 4, ''],
        ['Demo 波段交易', 'BRK-B', 'SELL', '2025-11-03', 15, 398.5, 4, '停損出場'],
    ];

    foreach ($trades as [$portfolio, $symbol, $side, $date, $qty, $price, $fee, $note]) {
        Database::run(
            'INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, currency, quantity, price, fee, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ids[$portfolio], $iid[$symbol], $side, $date, $currency[$symbol], $qty, $price, $fee, $note, $now]
        );
    }

    // [portfolio, date, direction, amount, currency, note] -- sized so each demo balance stays positive.
    $cash = [
        ['Demo 長期投資', '2024-01-02', 'IN',  700000, 'TWD', '薪資與儲蓄存入'],
        ['Demo 長期投資', '2024-03-01', 'IN',   16000, 'USD', '換匯存入'],
        ['Demo 長期投資', '2024-09-05', 'IN',   80000, 'HKD', '換匯存入'],
        ['Demo 波段交易', '2025-01-05', 'IN',   40000, 'USD', '券商入金'],
        ['Demo 波段交易', '2025-11-05', 'OUT',  10000, 'USD', '部分出金'],
    ];

    foreach ($cash as [$portfolio, $date, $direction, $amount, $currency, $note]) {
        Database::run(
            'INSERT INTO cash_movements (portfolio_id, moved_on, direction, amount, currency, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$ids[$portfolio], $date, $direction, $amount, $currency, $note, $now]
        );
    }

    $ownership = ['version' => 1, 'portfolios' => []];
    foreach ($portfolios as $name => $description) {
        $id = $ids[$name];
        $ownership['portfolios'][] = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'created_at' => (string) Database::scalar('SELECT created_at FROM portfolios WHERE id = ?', [$id]),
            'fingerprint' => seed_demo_fingerprint($pdo, $id),
        ];
    }
    Database::run(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        [SEED_DEMO_OWNER_KEY, json_encode($ownership, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Seeded ' . count($portfolios) . ' portfolios, ' . count($instruments) . ' instruments, '
    . count($trades) . ' transactions, ' . count($cash) . " cash movements.\n";

echo 'Fetching quotes...' . PHP_EOL;
$result = QuoteService::refresh(null);
if ($result === false) {
    fwrite(STDERR, "報價更新失敗，示範資料的價格可能過時或缺漏。\n");
    exit(1);
}
printf("quotes ok=%d failed=%d | fx ok=%d failed=%d\n", $result['updated'], $result['failed'], $result['fx']['updated'], count($result['fx']['failed']));
foreach ($result['errors'] as $error) {
    echo '  ! ' . $error . "\n";
}

echo "Done. Open the app and switch portfolio on the 投資組合 page.\n";
