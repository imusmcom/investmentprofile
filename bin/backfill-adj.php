<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('此腳本僅限命令列執行。');
}

/**
 * CLI backfill of prices.adj_close (除息／除權調整後的收盤).
 *
 *   php bin/backfill-adj.php                  回填所有投資物
 *   php bin/backfill-adj.php --instrument=9    只回填那一個
 *   php bin/backfill-adj.php --dry-run         只報告會寫多少，不動資料庫
 *
 * 只 UPDATE 已經存在的日線列，而且只動 adj_close 這個欄位：close 一個字節都不改，一列新的都不加。
 * 這樣這個腳本就算跑錯、跑兩次、跑到一半斷掉，都不可能把誰的持倉成本或總覽數字改壞。
 */

require __DIR__ . '/../app/cli.php';

$usage = 'php bin/backfill-adj.php [--instrument=編號] [--range=10y] [--dry-run] [--data-dir=目錄] [--tz=時區]';
$opts = cli_setup($argv, ['help', 'h', 'dry-run'], ['instrument', 'range', 'data-dir', 'tz'], $usage);

if (isset($opts['help']) || isset($opts['h'])) {
    echo <<<TXT
用法：php bin/backfill-adj.php [--instrument=編號] [--dry-run] [--data-dir=目錄] [--tz=時區]

  --instrument  只回填那個投資物；不給就全部回填
  --range       問來源要多長的歷史，預設沿用設定的 QUOTE_HISTORY_RANGE（10y）。
                這裡必須給日線區間（1y/2y/5y/10y），**不要給 max**：max 會讓我們退回
                月度 K 棒，日期對不上資料庫裡的一日一列，回填等於白跑一趟。
  --dry-run     只試算來源日期與資料庫中 adj_close IS NULL 列的交集，不動資料庫
  --data-dir    哪個目錄裡的 portfolio.sqlite；預設讀 PORTFOLIO_DATA_DIR，否則使用正式部署資料庫或專案下的 data/
  --tz          時間戳用的時區；未給時沿用 php.ini 的 date.timezone，它也沒設就用 Asia/Hong_Kong

只改 prices.adj_close，不改 close、不新增日線列。已有一個非空 adj_close 的列不會被覆寫，
所以同一指令重複執行是安全的；來源那批沒有 adjclose（加密貨幣、部分外匯）的投資物會回報 0 列。

回傳碼：0 全部完成；1 有投資物沒抓到或一列都沒寫進去了；2 參數寫錯或找不到那個投資物。

TXT;
    exit(0);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\QuoteService;

$dryRun = isset($opts['dry-run']);
$onlyId = null;

if (isset($opts['instrument'])) {
    if (preg_match('/^\d+$/', (string) $opts['instrument']) !== 1) {
        fwrite(STDERR, "--instrument 要寫成數字編號，例如 --instrument=9\n");
        exit(2);
    }
    $onlyId = (int) $opts['instrument'];
    if (Database::scalar('SELECT id FROM instruments WHERE id = ?', [$onlyId]) === null) {
        fwrite(STDERR, "找不到編號 {$onlyId} 的投資物，因此沒有回填任何資料。\n");
        exit(2);
    }
}

$priceScope = $onlyId === null ? '' : ' AND instrument_id = :iid';
$priceParams = $onlyId === null ? [] : ['iid' => $onlyId];
$missing = (int) Database::scalar(
    'SELECT COUNT(*) FROM prices WHERE adj_close IS NULL' . $priceScope,
    $priceParams,
    0
);
printf("[%s] 待補的日線列：%d%s\n", date('Y-m-d H:i:s'), $missing, $dryRun ? '（--dry-run，不會寫入）' : '');

// 區間一律用日線口徑。來源對 `max` 會退回月度 K 棒（這台實測 221 根，日期全是月底），
// 那些日期和 prices 裡一日一列對不上，回填等於沒做——所以這裡只接受設定裡的 history_range
// 或明確的一日區間，並把 max 擋下來。
$config = require __DIR__ . '/../app/config.php';
$range = isset($opts['range']) ? (string) $opts['range'] : (string) $config['quote']['history_range'];
$dailyRanges = ['1mo', '3mo', '6mo', '1y', '2y', '5y', '10y', 'ytd'];
if (!in_array($range, $dailyRanges, true)) {
    fwrite(STDERR, "--range 只能是 " . implode('/', $dailyRanges) . "（max 會拿到月度棒而不是日線）；現在是「{$range}」\n");
    exit(2);
}
printf("[%s] 抓取區間：%s\n", date('Y-m-d H:i:s'), $range);

$sql = 'SELECT id, symbol, yahoo_symbol FROM instruments' . ($onlyId === null ? '' : ' WHERE id = ?');
$instruments = Database::all($sql, $onlyId === null ? [] : [$onlyId]);

if ($instruments === []) {
    echo "這個資料庫沒有符合的投資物，因此沒有回填任何資料。\n";
    exit(1);
}

// 只動 adj_close，而且只補還是 NULL 的那幾列：寫錯也不會把 close 帶走。
$update = Database::pdo()->prepare(
    'UPDATE prices SET adj_close = :adj WHERE instrument_id = :iid AND as_of = :as_of AND adj_close IS NULL'
);

$failed = 0;
$written = 0;

foreach ($instruments as $inst) {
    $iid = (int) $inst['id'];
    $quote = QuoteService::get((string) $inst['yahoo_symbol'], $range);

    if (!$quote['ok']) {
        $failed++;
        printf("  ! %-8s %s\n", $inst['symbol'], (string) $quote['error']);
        continue;
    }

    $rows = [];
    foreach ($quote['history'] as $bar) {
        if (is_numeric($bar['adj'] ?? null)) {
            $rows[(string) $bar['date']] = (float) $bar['adj'];
        }
    }
    // 今天這根是從 meta 帶回來的，不在 history 裡（寫入前被 pop 掉了）。
    if ($quote['as_of'] !== null && is_numeric($quote['adj'] ?? null)) {
        $rows[(string) $quote['as_of']] = (float) $quote['adj'];
    }

    if ($rows === []) {
        printf("  · %-8s 來源沒有 adjclose，跳過（這檔只能沿用未調整價格）\n", $inst['symbol']);
        continue;
    }

    $pdo = Database::pdo();
    $touched = 0;
    if ($dryRun) {
        // Count the intersection with real, still-NULL price rows. Source bar count is not a write
        // estimate: it can contain dates this database does not have or rows already backfilled.
        $pending = Database::all(
            'SELECT as_of FROM prices WHERE instrument_id = ? AND adj_close IS NULL',
            [$iid]
        );
        $pendingDates = array_fill_keys(array_column($pending, 'as_of'), true);
        foreach ($rows as $date => $adj) {
            if (isset($pendingDates[$date])) {
                $touched++;
            }
        }
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($rows as $date => $adj) {
                $update->execute(['adj' => $adj, 'iid' => $iid, 'as_of' => $date]);
                $touched += $update->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $failed++;
            printf("  ! %-8s 寫入失敗，這一檔全部回復：%s\n", $inst['symbol'], $e->getMessage());
            continue;
        }
    }

    $written += $touched;
    printf("  ok %-8s 來源給了 %d 根 adjclose，補進 %s %d 列\n", $inst['symbol'], count($rows), $dryRun ? '會寫' : '已寫', $touched);
}

$left = (int) Database::scalar(
    'SELECT COUNT(*) FROM prices WHERE adj_close IS NULL' . $priceScope,
    $priceParams,
    0
);
if ($dryRun) {
    printf("試算：預計補入 %d 列，仍沒有 adj_close 的日線列 %d 列，失敗 %d 檔；沒有寫入任何資料。\n", $written, $left, $failed);
} else {
    printf("完成：寫入 %d 列，仍沒有 adj_close 的日線列 %d 列，失敗 %d 檔\n", $written, $left, $failed);
}

exit($failed > 0 || ($written === 0 && !$dryRun) ? 1 : 0);
