<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('此腳本僅限命令列執行。');
}

/**
 * CLI quote refresh, for cron / Windows Task Scheduler.
 *
 *   php bin/refresh-prices.php            refresh every instrument
 *   php bin/refresh-prices.php --portfolio=2
 */

require __DIR__ . '/../app/cli.php';

$usage = 'php bin/refresh-prices.php [--portfolio=編號] [--fx-only] [--data-dir=目錄] [--tz=時區]';
$opts = cli_setup($argv, ['help', 'h', 'fx-only'], ['portfolio', 'data-dir', 'tz'], $usage);

if (isset($opts['help']) || isset($opts['h'])) {
    echo <<<TXT
用法：php bin/refresh-prices.php [--portfolio=編號] [--fx-only] [--data-dir=目錄] [--tz=時區]

  --portfolio  只更新那個投資組合用過的投資物；不給就全部更新
  --fx-only    只強制重新抓取非手動匯率，不更新投資物報價；供修正舊版自動匯率方向使用
  --data-dir   更新哪個目錄裡的 portfolio.sqlite；預設讀 PORTFOLIO_DATA_DIR，否則使用正式部署資料庫或專案下的 data/
  --tz         時間戳用的時區；未給時沿用 php.ini 的 date.timezone，它也沒設就用 Asia/Hong_Kong（UTC+8，不撥夏令時間）

回傳碼：0 全部成功；1 有報價、十年歷史或匯率沒抓到（含一筆都沒更新到）；2 參數寫錯或找不到那個投資組合。

TXT;
    exit(0);
}

if (isset($opts['fx-only']) && isset($opts['portfolio'])) {
    fwrite(STDERR, "--fx-only 不能與 --portfolio 一起使用。\n");
    exit(2);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\QuoteService;
use App\Settings;

$portfolioId = null;
if (isset($opts['portfolio'])) {
    if (preg_match('/^\d+$/', (string) $opts['portfolio']) !== 1) {
        fwrite(STDERR, "--portfolio 要寫成數字編號，例如 --portfolio=2\n");
        exit(2);
    }
    $portfolioId = (int) $opts['portfolio'];
    // A typo'd id used to be taken as "refresh nothing" and still exit 0, so a scheduled job reported
    // success while no price was fetched at all.
    if (Database::scalar('SELECT id FROM portfolios WHERE id = ?', [$portfolioId]) === null) {
        fwrite(STDERR, "找不到編號 {$portfolioId} 的投資組合，因此沒有更新任何報價。\n");
        exit(2);
    }
}

if (isset($opts['fx-only'])) {
    $currencies = QuoteService::usedCurrencies();
    foreach (Database::all('SELECT currency FROM fx_rates WHERE manual = 0') as $row) {
        $currencies[] = (string) $row['currency'];
    }
    $currencies = array_values(array_unique(array_filter($currencies)));
    $fx = QuoteService::refreshFx($currencies, null, true);
    printf("fx-only ok=%d skipped=%d failed=%d\n", $fx['updated'], $fx['skipped'], count($fx['failed']));
    foreach ($fx['failed'] as $code) {
        echo '  ! FX ' . $code . " 未取得\n";
    }
    exit($fx['failed'] === [] ? 0 : 1);
}

$inScope = (int) Database::scalar('SELECT COUNT(*) FROM instruments', [], 0);
if ($inScope === 0) {
    // A scheduled job on an empty database has nothing to fetch; say so instead of printing zeros.
    echo "這個資料庫還沒有任何投資物，因此沒有更新任何報價。\n";
    exit(1);
}

$result = QuoteService::refresh($portfolioId);
Settings::put('last_cli_refresh', date('Y-m-d H:i:s'));

printf(
    "[%s] quotes ok=%d failed=%d skipped=%d | 十年歷史 +%d 根、%d 檔沒補上 | fx ok=%d skipped=%d failed=%d\n",
    date('Y-m-d H:i:s'),
    $result['updated'],
    $result['failed'],
    $result['skipped'],
    $result['history'],
    $result['history_failed'],
    $result['fx']['updated'],
    $result['fx']['skipped'],
    count($result['fx']['failed'])
);

foreach ($result['errors'] as $error) {
    echo '  ! ' . $error . "\n";
}
foreach ($result['fx']['failed'] as $code) {
    echo '  ! FX ' . $code . " 未取得\n";
}

// README promises a non-zero code whenever quotes did not land, which includes a run that did not
// try anything at all (an empty instrument list, or a portfolio that turned out to hold none).
// 欠的十年歷史沒補上也算失敗：那正是「報價看起来有更新、但十年曲線與動能還是算不了」的那種夜間任務。
$nothingFetched = $result['updated'] === 0;

exit($nothingFetched || $result['failed'] > 0 || $result['history_failed'] > 0
    || $result['fx']['failed'] !== [] ? 1 : 0);
