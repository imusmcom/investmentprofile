<?php
declare(strict_types=1);

/**
 * 回報鏈結算式的獨立驗算；資料庫只使用 /tmp 下本次自建的 scratch。
 * Reflection 只呼叫純計算的 private chain()，另外用公開 API 驗同日存入＋買進與賣超／手續費現金。
 */
$dir = '/tmp/portfolio-performance-math-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "無法建立 /tmp scratch：{$dir}\n");
    exit(2);
}
putenv('PORTFOLIO_DATA_DIR=' . $dir);
require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Handlers;
use App\Performance;
use App\Positions;
use App\Settings;

register_shutdown_function(static function () use ($dir): void {
    if (!is_dir($dir)) {
        return;
    }
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
$near = static fn (float $a, float $b): bool => abs($a - $b) < 0.000001;

final class ReplayCountingPdo extends PDO
{
    public int $replays = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM transactions t') && str_contains($query, 'WHERE t.portfolio_id IN (')) {
            $this->replays++;
        }

        return parent::prepare($query, $options);
    }
}

$chain = new ReflectionMethod(Performance::class, 'chain');
$ret = static fn (array $values, array $flows, int $from = 0): mixed => $chain->invoke(null, $values, $flows, $from, count($values) - 1);
$retCash = static fn (array $values, array $flows, int $from = 0): mixed => $chain->invoke(null, $values, $flows, $from, count($values) - 1, true);

echo "-- 純鏈結算式\n";
$r = $ret([1000.0, 2000.0, 2200.0], [0.0, 1000.0, 0.0]);
$ok('同日存入 1000 再買進，持股價值增加不算報酬，只留下真正的 10%', is_float($r) && $near($r, 10.0), var_export($r, true));
$r = $ret([1000.0, 2000.0, 2100.0], [0.0, 1000.0, 0.0]);
$ok('同日外部存入 1000 後總值 2100，對應 5% 而非 110%', is_float($r) && $near($r, 5.0), var_export($r, true));
$r = $ret([1000.0, 1100.0, 1000.0, 1200.0], [0.0, 100.0, -100.0, 0.0]);
$ok('跨日存入再提領淨額歸零，兩段仍各自鏈結成 20%', is_float($r) && $near($r, 20.0), var_export($r, true));
$r = $ret([1000.0, 1200.0], [0.0, -200.0]);
$ok('賣出現金流是負值，200 元流出後 20% 成長仍是 40%', is_float($r) && $near($r, 40.0), var_export($r, true));
$r = $ret([0.0, 100.0, 150.0], [0.0, 0.0, 0.0]);
$ok('基值小於等於零時略過整段，不把後面 50% 冒充全期間報酬', $r === null, var_export($r, true));
$r = $ret([-10.0, 0.0, 20.0], [0.0, 0.0, 0.0]);
$ok('整段都沒有可計算基值時回 null', $r === null, var_export($r, true));
$r = $retCash([1000.0, 0.0, 0.0], [0.0, -1000.0, 0.0]);
$ok('現金全部買進後，本金流出與零餘額相抵，現金報酬為 0%', is_float($r) && $near($r, 0.0), var_export($r, true));
$r = $retCash([0.0, 1000.0, 1000.0], [0.0, 1000.0, 0.0]);
$ok('賣出回到現金的本金不算收益，後續零變動仍為 0%', is_float($r) && $near($r, 0.0), var_export($r, true));
$r = $retCash([1000.0, 895.0], [0.0, -100.0]);
$ok('買進本金 100 與手續費 5 分開，現金報酬為 -0.5%', is_float($r) && $near($r, -0.5), var_export($r, true));
$r = $retCash([-100.0, -100.0], [0.0, 0.0]);
$ok('負現金沒有可計算基值，不冒充 0%', $r === null, var_export($r, true));
$r = $retCash([100.0, -50.0], [0.0, 0.0]);
$ok('現金在期間內轉負時回報未知，不顯示小於 -100% 的假報酬', $r === null, var_export($r, true));
$r = $ret([0.0, 0.0], [0.0, 0.0]);
$ok('一般 chain 呼叫的零基值規則保持原樣，不因現金慣例而放寬', $r === null, var_export($r, true));
$r = $retCash([0.0, 0.0], [0.0, 0.0]);
$ok('帳戶總值前後都為零且無費用時，合計顯示 0%', is_float($r) && $near($r, 0.0), var_export($r, true));
$r = $retCash([0.0, 100.0], [0.0, 0.0]);
$ok('總值由零成長為正數時百分比無定義，仍顯示 --', $r === null, var_export($r, true));

$indexAt = new ReflectionMethod(Performance::class, 'indexAt');
$testAxis = ['2024-12-31', '2025-01-02', '2025-06-02', '2025-12-31'];
$ok('起點搜尋取目標日前最後一筆，而不是當年第一筆', $indexAt->invoke(null, $testAxis, '2025-12-30', '2025-12-31') === 2,
    var_export($indexAt->invoke(null, $testAxis, '2025-12-30', '2025-12-31'), true));
$ok('起點搜尋也正確處理非年底的中間目標日', $indexAt->invoke(null, $testAxis, '2025-03-01', '2025-12-31') === 1,
    var_export($indexAt->invoke(null, $testAxis, '2025-03-01', '2025-12-31'), true));
$ok('目標日早於資料起點時仍回 null', $indexAt->invoke(null, $testAxis, '2024-01-01', '2025-12-31') === null,
    var_export($indexAt->invoke(null, $testAxis, '2024-01-01', '2025-12-31'), true));

Settings::put('base_currency', 'USD');
$portfolio = static function (string $name) use ($ok): int {
    Database::run('INSERT INTO portfolios (name, description) VALUES (?, ?)', [$name, '算式驗算']);
    $id = (int) Database::pdo()->lastInsertId();
    $ok("建立 scratch 組合：{$name}", $id > 0, (string) $id);
    return $id;
};
$instrument = static function (string $symbol) use ($ok): int {
    Database::run(
        "INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES (?,?, 'US', ?, 'USD', 'EQUITY')",
        [$symbol, $symbol, $symbol]
    );
    $id = (int) Database::pdo()->lastInsertId();
    $ok("建立 scratch 投資物：{$symbol}", $id > 0, (string) $id);
    return $id;
};
$trade = static function (int $pid, int $iid, string $side, string $date, float $qty, float $price, float $fee = 0.0, string $currency = 'USD'): void {
    Database::run(
        'INSERT INTO transactions (portfolio_id,instrument_id,side,traded_on,currency,quantity,price,fee) VALUES (?,?,?,?,?,?,?,?)',
        [$pid, $iid, $side, $date, $currency, $qty, $price, $fee]
    );
};
$price = static function (int $iid, string $date, float $value, string $currency = 'USD'): void {
    Database::run(
        'INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source) VALUES (?,?,?,?,?,?)',
        [$iid, $date, $value, $value, $currency, 'manual']
    );
};
$today = new DateTimeImmutable('today');
$day1 = $today->modify('-2 day')->format('Y-m-d');
$day2 = $today->modify('-1 day')->format('Y-m-d');
$day3 = $today->format('Y-m-d');

echo "-- priceMap：手動價日期與無效價格\n";
$pid = $portfolio('手動價日期');
$iid = $instrument('MANUALDATE');
$trade($pid, $iid, 'BUY', $day2, 1.0, 100.0);
$price($iid, $day2, 100.0);
Database::run(
    "INSERT INTO prices (instrument_id,as_of,close,prev_close,adj_close,currency,source)
     VALUES (?, ?, 110, 109, 110, 'USD', 'yahoo')",
    [$iid, $day3]
);
Database::run('UPDATE instruments SET manual_price = 105, manual_price_at = ? WHERE id = ?', [$day2, $iid]);
$map = Positions::priceMap();
$quote = $map[$iid] ?? null;
$ok('較舊手動價不取代最新報價，prev 與幣別保留', $quote !== null
    && (float) $quote['price'] === 110.0 && $quote['as_of'] === $day3 && $quote['source'] === 'quote'
    && (float) $quote['prev'] === 109.0 && $quote['currency'] === 'USD', var_export($quote, true));
$positions = Positions::forPortfolio($pid);
$ok('報價來源的日漲幅仍由最新 close / prev_close 計算', isset($positions['rows'][0])
    && $near((float) $positions['rows'][0]['day_change_pct'], (110.0 - 109.0) / 109.0 * 100.0),
    var_export($positions['rows'][0] ?? null, true));

Database::run('UPDATE instruments SET manual_price_at = ? WHERE id = ?', [$day3, $iid]);
$quote = Positions::priceMap()[$iid] ?? null;
$ok('同日手動價勝出但保留報價 prev 與幣別', $quote !== null
    && (float) $quote['price'] === 105.0 && $quote['as_of'] === $day3 && $quote['source'] === 'manual'
    && (float) $quote['prev'] === 109.0 && $quote['currency'] === 'USD', var_export($quote, true));
$positions = Positions::forPortfolio($pid);
$ok('手動價勝出時不顯示快取日漲幅', isset($positions['rows'][0])
    && $positions['rows'][0]['day_change_pct'] === null, var_export($positions['rows'][0] ?? null, true));

$newer = $today->modify('+1 day')->format('Y-m-d');
Database::run('UPDATE instruments SET manual_price_at = ? WHERE id = ?', [$newer, $iid]);
$quote = Positions::priceMap()[$iid] ?? null;
$ok('較新手動價採用其日期', $quote !== null && $quote['source'] === 'manual' && $quote['as_of'] === $newer,
    var_export($quote, true));
Database::run('UPDATE instruments SET manual_price = 0 WHERE id = ?', [$iid]);
$quote = Positions::priceMap()[$iid] ?? null;
$ok('無效較新手動價不移除有效報價', $quote !== null && $quote['source'] === 'quote'
    && (float) $quote['price'] === 110.0, var_export($quote, true));
Database::run('UPDATE instruments SET manual_price = 120, manual_price_at = ? WHERE id = ?', ['2026-02-30', $iid]);
$quote = Positions::priceMap()[$iid] ?? null;
$ok('手動價日期無效時仍使用有效報價', $quote !== null && $quote['source'] === 'quote'
    && $quote['as_of'] === $day3, var_export($quote, true));

$badLatest = $instrument('BADLATEST');
$price($badLatest, $day2, 100.0);
Database::pdo()->exec('PRAGMA ignore_check_constraints = ON');
Database::run(
    "INSERT INTO prices (instrument_id,as_of,close,currency,source) VALUES (?, ?, 0, 'USD', 'yahoo')",
    [$badLatest, $day3]
);
Database::pdo()->exec('PRAGMA ignore_check_constraints = OFF');
Database::run('UPDATE instruments SET manual_price = 105, manual_price_at = ? WHERE id = ?', [$day2, $badLatest]);
$ok('最新快取價無效時，較舊手動價不得越級回填', !isset(Positions::priceMap()[$badLatest]),
    var_export(Positions::priceMap()[$badLatest] ?? null, true));
Database::run('UPDATE instruments SET manual_price_at = ? WHERE id = ?', [$day3, $badLatest]);
$quote = Positions::priceMap()[$badLatest] ?? null;
$ok('最新快取價無效但同日手動價有效時仍採手動價', $quote !== null
    && (float) $quote['price'] === 105.0 && $quote['source'] === 'manual', var_export($quote, true));

$badManual = $instrument('BADMANUAL');
Database::run('UPDATE instruments SET manual_price = ?, manual_price_at = ? WHERE id = ?', ['bad', $day3, $badManual]);
$ok('只有無效手動價時不留在 priceMap', !isset(Positions::priceMap()[$badManual]),
    var_export(Positions::priceMap()[$badManual] ?? null, true));
$manualOnly = $instrument('MANUALONLY');
Database::run('UPDATE instruments SET manual_price = 50, manual_price_at = ? WHERE id = ?', [$day3, $manualOnly]);
$quote = Positions::priceMap()[$manualOnly] ?? null;
$ok('無快取價時有效手動價仍可用，prev 為 NULL、幣別空白', $quote !== null
    && (float) $quote['price'] === 50.0 && $quote['prev'] === null && $quote['currency'] === ''
    && $quote['source'] === 'manual', var_export($quote, true));

echo "-- 公開 API：同日存入與買進\n";
$pid = $portfolio('同日存入買進');
$iid = $instrument('SAMEDAY');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 10.0, 100.0);
$price($iid, $day1, 100.0);
$price($iid, $day2, 110.0);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('同日存入＋買進的公開回報，持股與總報酬都為 10%', $one !== null
    && $near((float) $one['holdings_pct'], 10.0) && $near((float) $one['total_pct'], 10.0), var_export($one, true));
$ok('同日存入＋買進後現金為零，公開回報仍為 0%', $one !== null
    && $near((float) $one['cash_pct'], 0.0), var_export($one, true));

echo "-- 公開 API：天數期間起點\n";
$pid = $portfolio('七天起點');
$iid = $instrument('SEVENDAY');
$weekStart = $today->modify('-10 day')->format('Y-m-d');
$weekBase = $today->modify('-8 day')->format('Y-m-d');
$weekMid = $today->modify('-6 day')->format('Y-m-d');
$trade($pid, $iid, 'BUY', $weekStart, 1.0, 100.0);
$price($iid, $weekStart, 100.0);
$price($iid, $weekBase, 101.0);
$price($iid, $weekMid, 102.0);
$price($iid, $day3, 103.0);
$report = Performance::forPortfolios([$pid]);
$seven = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '7 天') {
        $seven = $row;
        break;
    }
}
$ok('7 天起點是期末往前 7 天，不是往前 7 個月', $seven !== null
    && $seven['start'] === $weekBase && $seven['end'] === $day3, var_export($seven, true));

echo "-- 公開 API：現金本金與手續費\n";
$pid = $portfolio('現金手續費');
$iid = $instrument('CASHFEE');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$price($iid, $day1, 100.0);
$trade($pid, $iid, 'BUY', $day2, 1.0, 100.0, 5.0);
$price($iid, $day2, 100.0);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('期間內買進只移走本金，手續費讓現金報酬為 -0.5%', $one !== null
    && $near((float) $one['cash_pct'], -0.5), var_export($one, true));
$ok('同一筆手續費也反映在總報酬，持股本身仍為 0%', $one !== null
    && $near((float) $one['holdings_pct'], 0.0) && $near((float) $one['total_pct'], -0.5), var_export($one, true));

$pid = $portfolio('閒置現金');
$iid = $instrument('IDLECASH');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',2000,'USD')", [$pid, '2025-01-02']);
$trade($pid, $iid, 'BUY', '2025-01-02', 10.0, 100.0);
$price($iid, '2025-01-02', 100.0);
$price($iid, '2025-06-02', 150.0);
$report = Performance::forPortfolios([$pid]);
$year = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
$ok('年度閒置現金沒有流入流出與費用時為 0%', $year !== null
    && $near((float) $year['cash_pct'], 0.0), var_export($year, true));

echo "-- 公開 API：存入與提取計入現金及合計報酬\n";
$pid = $portfolio('本金計入報酬');
$iid = $instrument('PRINCIPALRETURN');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',2000,'USD')", [$pid, '2025-01-02']);
$trade($pid, $iid, 'BUY', '2025-01-02', 10.0, 100.0);
$price($iid, '2025-01-02', 100.0);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',100,'USD')", [$pid, '2025-06-02']);
$price($iid, '2025-06-02', 150.0);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'OUT',50,'USD')", [$pid, '2025-12-31']);
$price($iid, '2025-12-31', 150.0);
$report = Performance::forPortfolios([$pid]);
$year = null;
$one = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('年度現金存入 100、提取 50 後，報酬為 +5%', $year !== null
    && $near((float) $year['cash_pct'], 5.0), var_export($year, true));
$ok('年度合計把存入 100、提取 50 全額計入，報酬為 +27.5%', $year !== null
    && $near((float) $year['total_pct'], 27.5), var_export($year, true));
$ok('持股仍排除本金流動，年度 TWR 保持 +50%', $year !== null
    && $near((float) $year['holdings_pct'], 50.0), var_export($year, true));
$ok('期間提取 50 讓現金報酬轉負，而不是剔除成 0%', $one !== null
    && $near((float) $one['cash_pct'], -50.0 / 1100.0 * 100.0), var_export($one, true));
$ok('期間合計也把提取 50 計入負報酬', $one !== null
    && $near((float) $one['total_pct'], -50.0 / 2600.0 * 100.0), var_export($one, true));

echo "-- 公開 API：年度起點必須接前一年年底\n";
$pid = $portfolio('跨年起點');
$iid = $instrument('YEARSTART');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',100,'USD')", [$pid, '2024-12-31']);
$trade($pid, $iid, 'BUY', '2024-12-31', 1.0, 100.0);
$price($iid, '2024-12-31', 100.0);
$price($iid, '2025-12-31', 120.0);
$report = Performance::forPortfolios([$pid]);
$year = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
$ok('2025 年度起點接上 2024-12-31，不從當年第一筆重來', $year !== null
    && $year['start'] === '2024-12-31', var_export($year, true));
$ok('跨年起點正確後持股、現金、合計分別為 20%、0%、20%', $year !== null
    && $near((float) $year['holdings_pct'], 20.0) && $near((float) $year['cash_pct'], 0.0)
    && $near((float) $year['total_pct'], 20.0), var_export($year, true));

echo "-- 公開 API：YTD 接上一年最後價\n";
$pid = $portfolio('YTD跨年');
$iid = $instrument('YTDBOUNDARY');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',100,'USD')", [$pid, '2024-12-31']);
$trade($pid, $iid, 'BUY', '2024-12-31', 1.0, 100.0);
$price($iid, '2024-12-31', 100.0);
$price($iid, '2025-01-01', 110.0);
$price($iid, '2025-12-31', 120.0);
$report = Performance::forPortfolios([$pid]);
$ytd = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '本年至今') {
        $ytd = $row;
        break;
    }
}
$ok('YTD 起點是前一年 12/31，包含 1/1 當日漲跌', $ytd !== null
    && $ytd['start'] === '2024-12-31' && $near((float) $ytd['total_pct'], 20.0), var_export($ytd, true));

echo "-- 公開 API：純現金與最後報價後事件\n";
$pid = $portfolio('純現金');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, '2025-01-02']);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',100,'USD')", [$pid, '2025-12-31']);
$report = Performance::forPortfolios([$pid]);
$year = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
$ok('沒有交易與投資物的純現金組合仍可計算', $year !== null
    && $year['start'] === '2025-01-02' && $near((float) $year['cash_pct'], 10.0)
    && $near((float) $year['total_pct'], 10.0) && $report['meta']['missing_price'] === [], var_export($report, true));
$ok('純現金端點不會被標成陳舊', $report['meta']['stale'] === false
    && $report['meta']['price_end'] === null, var_export($report['meta'], true));

$pid = $portfolio('報價後存入');
$iid = $instrument('POSTPRICE');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',100,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 1.0, 100.0);
$price($iid, $day1, 100.0);
$price($iid, $day2, 110.0);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',50,'USD')", [$pid, $day3]);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('最後報價後的存入進入期末現金與總值', $report['meta']['end'] === $day3
    && $report['meta']['price_end'] === $day2 && $report['meta']['stale'] === true
    && abs((float) $report['meta']['end_cash'] - 50.0) < 0.000001
    && abs((float) $report['meta']['end_total'] - 160.0) < 0.000001, var_export($report['meta'], true));
$ok('陳舊報價事件日仍可計算區間並附註', $one !== null && $one['stale_end'] === true
    && $near((float) $one['total_pct'], 50.0 / 110.0 * 100.0)
    && str_contains($one['note'], '最後已知價'), var_export($one, true));

echo "-- 公開 API：混合新舊報價與無持股端點\n";
$pid = $portfolio('混合報價新舊');
$fresh = $instrument('FRESHMIX');
$stale = $instrument('STALEMIX');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',2000,'USD')", [$pid, $day1]);
$trade($pid, $fresh, 'BUY', $day1, 1.0, 100.0);
$trade($pid, $stale, 'BUY', $day1, 1.0, 100.0);
$price($fresh, $day1, 100.0);
$price($fresh, $day2, 110.0);
$price($fresh, $day3, 120.0);
$price($stale, $day1, 100.0);
$price($stale, $day2, 105.0);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('新投資物的當日價不會掩蓋另一投資物的陳舊價', $report['meta']['end'] === $day3
    && $report['meta']['price_end'] === $day2 && $report['meta']['stale'] === true
    && abs((float) $report['meta']['end_holdings'] - 225.0) < 0.000001
    && $one !== null && $one['stale_end'] === true
    && str_contains($one['note'], '最後已知價'), var_export($report, true));

$pid = $portfolio('端點已清倉');
$iid = $instrument('NOENDINGHOLD');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 1.0, 100.0);
$price($iid, $day1, 100.0);
$trade($pid, $iid, 'SELL', $day2, 1.0, 100.0);
$price($iid, $day2, 100.0);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',10,'USD')", [$pid, $day3]);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('已清倉端點即使現金事件晚於最後報價也不算陳舊', $report['meta']['end'] === $day3
    && $report['meta']['end_holdings'] === 0.0 && $report['meta']['price_end'] === null
    && $report['meta']['stale'] === false && $one !== null && $one['stale_end'] === false,
    var_export($report, true));

echo "-- 公開 API：歷史幣別不被目前幣別重寫\n";
$pid = $portfolio('歷史幣別');
$iid = $instrument('HISTCUR');
Database::run("INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual) VALUES ('HKD',10,'2026-11-24','manual',1)
               ON CONFLICT(currency) DO UPDATE SET units_per_usd=excluded.units_per_usd");
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'HKD')", [$pid, '2025-01-02']);
$trade($pid, $iid, 'BUY', '2025-01-02', 1.0, 100.0, 0.0, 'HKD');
$price($iid, '2025-01-02', 100.0, 'HKD');
$price($iid, '2025-06-02', 2.0, 'USD');
Database::run('UPDATE instruments SET currency = ? WHERE id = ?', ['HKD', $iid]);
$report = Performance::forPortfolios([$pid]);
$year = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
$ok('歷史價格與成交各自使用保存的幣別，持股 -80%、合計 -8%', $year !== null
    && $near((float) $year['holdings_pct'], -80.0) && $near((float) $year['total_pct'], -8.0), var_export($year, true));
$positions = Positions::forPortfolio($pid);
$row = $positions['rows'][0] ?? null;
$cash = Positions::cashFor($pid);
$cashRows = [];
foreach ($cash['rows'] as $cashRow) {
    $cashRows[$cashRow['currency']] = $cashRow;
}
$ok('Positions 估值取價格列 USD，成交成本與現金仍為 HKD', $row !== null
    && $row['valuation_currency'] === 'USD'
    && $near((float) $row['recorded_cost_basis'], 100.0)
    && $near((float) $row['cost_basis'], 10.0)
    && $near((float) $row['base_cost_basis'], 10.0)
    && $near((float) $row['market_value'], 2.0)
    && $near((float) $row['base_unrealized'], -8.0)
    && ($cashRows['HKD']['trade_out'] ?? null) === 100.0
    && $near((float) ($cashRows['HKD']['base_value'] ?? 0.0), 90.0)
    && !isset($cashRows['USD']), var_export(['positions' => $positions, 'cash' => $cash], true));

echo "-- Positions：投資物改幣別不重寫歷史成交\n";
$pid = $portfolio('歷史 USD 成交');
$iid = $instrument('USDEURTRADE');
Database::run("INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual) VALUES ('EUR',2,'2026-11-24','manual',1)
               ON CONFLICT(currency) DO UPDATE SET units_per_usd=excluded.units_per_usd");
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 1.0, 100.0, 5.0, 'USD');
Database::run('UPDATE instruments SET currency = ? WHERE id = ?', ['EUR', $iid]);
$price($iid, $day3, 120.0, 'EUR');
$positions = Positions::forPortfolio($pid);
$row = $positions['rows'][0] ?? null;
$cash = Positions::cashFor($pid);
$cashRows = [];
foreach ($cash['rows'] as $cashRow) {
    $cashRows[$cashRow['currency']] = $cashRow;
}
$ok('USD 成交成本保留，EUR 現價與 USD 記帳成本分開換算', $row !== null
    && $row['valuation_currency'] === 'EUR'
    && $near((float) $row['recorded_cost_basis'], 105.0)
    && $near((float) $row['cost_basis'], 210.0)
    && $near((float) $row['base_cost_basis'], 105.0)
    && $near((float) $row['market_value'], 120.0)
    && $near((float) $row['base_market_value'], 60.0)
    && $near((float) $row['base_unrealized'], -45.0)
    && ($cashRows['USD']['trade_out'] ?? null) === 105.0
    && $near((float) ($cashRows['USD']['balance'] ?? 0.0), 895.0)
    && $near((float) $cash['total_base'], 895.0)
    && !isset($cashRows['EUR']), var_export(['positions' => $positions, 'cash' => $cash], true));

$trade($pid, $iid, 'SELL', $day3, 1.0, 130.0, 0.0, 'EUR');
$positions = Positions::forPortfolio($pid);
$row = $positions['rows'][0] ?? null;
$cash = Positions::cashFor($pid);
$cashRows = [];
foreach ($cash['rows'] as $cashRow) {
    $cashRows[$cashRow['currency']] = $cashRow;
}
$report = Performance::forPortfolios([$pid]);
$ok('後續 EUR 賣出以 USD 歷史成本計算已實現與合計現金', $row !== null
    && $row['is_open'] === false
    && $near((float) $row['base_cost_basis'], 0.0)
    && $near((float) $row['base_realized'], -40.0)
    && $near((float) ($positions['summary']['realized'] ?? 0.0), -40.0)
    && ($cashRows['EUR']['trade_in'] ?? null) === 130.0
    && $near((float) ($cashRows['EUR']['base_value'] ?? 0.0), 65.0)
    && $near((float) $cash['total_base'], 960.0)
    && $near((float) $report['meta']['end_cash'], 960.0),
    var_export(['positions' => $positions, 'cash' => $cash, 'report' => $report], true));

Database::run("DELETE FROM fx_rates WHERE currency = 'EUR'");
$positions = Positions::forPortfolio($pid);
$row = $positions['rows'][0] ?? null;
$ok('已出清的零成本與零市值不需要匯率，不會誤標成未知', $row !== null
    && $row['cost_basis'] === 0.0 && $row['base_cost_basis'] === 0.0
    && $row['base_market_value'] === 0.0 && $positions['summary']['cost_basis'] === 0.0,
    var_export($positions, true));
Database::run("INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual)
               VALUES ('EUR',2,'2026-11-24','manual',1)
               ON CONFLICT(currency) DO UPDATE SET units_per_usd=excluded.units_per_usd");

$pid = $portfolio('缺匯率歷史幣別');
$iid = $instrument('JPYCHANGE');
$trade($pid, $iid, 'BUY', $day1, 1.0, 100.0, 5.0, 'JPY');
Database::run('UPDATE instruments SET currency = ? WHERE id = ?', ['EUR', $iid]);
$price($iid, $day3, 120.0, 'EUR');
$positions = Positions::forPortfolio($pid);
$row = $positions['rows'][0] ?? null;
$ok('缺匯率只列出真正缺少的歷史幣別，不誤報目前估值幣別', $row !== null
    && $row['cost_basis'] === null && $positions['summary']['cost_basis'] === null
    && $positions['summary']['missing_fx'] === ['JPY']
    && $near((float) $row['base_market_value'], 60.0), var_export($positions, true));

echo "-- 公開 API：合計相加溢位\n";
$pid = $portfolio('合計溢位');
$iid = $instrument('SUMOVERFLOW');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1e308,'USD')", [$pid, '2025-01-02']);
$trade($pid, $iid, 'BUY', '2025-01-02', 1.0, 1e308);
$price($iid, '2025-01-02', 1e308);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1e308,'USD')", [$pid, '2025-12-31']);
$price($iid, '2025-12-31', 1e308);
$report = Performance::forPortfolios([$pid]);
$ok('兩個各自有限值的持股與現金相加溢位時整份回報略過', $report['periods'] === []
    && str_contains((string) $report['meta']['note'], '合計超出'), var_export($report, true));

echo "-- 公開 API：缺價時各欄獨立判斷\n";
$pid = $portfolio('缺價分欄');
$priced = $instrument('LATEPRICE');
$other = $instrument('PARTIALB');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',4000,'USD')", [$pid, '2025-01-02']);
$trade($pid, $other, 'BUY', '2025-01-02', 10.0, 100.0);
$trade($pid, $priced, 'BUY', '2025-01-03', 10.0, 100.0);
$price($other, '2025-01-02', 100.0);
$price($other, '2025-01-03', 100.0);
$price($other, '2025-01-04', 100.0);
$price($priced, '2025-01-04', 150.0);
$report = Performance::forPortfolios([$pid]);
$year = null;
$one = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('年度期中新持股缺價時持股 TWR 仍為 --', $year !== null
    && $year['holdings_pct'] === null, var_export($year, true));
$ok('年度中間缺價不影響現金，買進本金剔除後仍為 0%', $year !== null
    && $near((float) $year['cash_pct'], 0.0), var_export($year, true));
$ok('年度期初與期末完整時，合計仍算出 12.5%', $year !== null
    && $near((float) $year['total_pct'], 500.0 / 4000.0 * 100.0), var_export($year, true));
$ok('期間起點缺價時合計為 --，不拿不完整基值硬算', $one !== null
    && $one['total_pct'] === null && $one['start'] === '2025-01-03', var_export($one, true));
$ok('期間起點缺價也不影響現金欄', $one !== null
    && $near((float) $one['cash_pct'], 0.0), var_export($one, true));

echo "-- 公開 API：賣超截斷與手續費符號\n";
$pid = $portfolio('賣超與手續費');
$iid = $instrument('OVERSELL');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 10.0, 100.0, 5.0);
$trade($pid, $iid, 'SELL', $day2, 20.0, 120.0, 5.0);
$price($iid, $day1, 100.0);
$price($iid, $day2, 120.0);
$price($iid, $day3, 120.0);
$report = Performance::forPortfolios([$pid]);
$meta = $report['meta'];
$ok('賣超只結清實際持有的 10 股，期末持股不變負', abs((float) $meta['end_holdings']) < 0.000001, var_export($meta, true));
$ok('買進手續費減現金、賣出手續費減收入：1000−1005+(10×120−5)=1190', abs((float) $meta['end_cash'] - 1190.0) < 0.000001, var_export($meta, true));
$ok('沒有股份就沒有期末總值灌水，總值等同現金 1190', abs((float) $meta['end_total'] - 1190.0) < 0.000001, var_export($meta, true));

echo "-- 公開 API：中段買進本金與一次性流量\n";
$pid = $portfolio('中段買進');
$iid = $instrument('MIDBUY');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, '2025-01-02']);
$trade($pid, $iid, 'BUY', '2025-01-02', 10.0, 100.0);
$trade($pid, $iid, 'BUY', '2025-01-03', 10.0, 120.0);
$price($iid, '2025-01-02', 100.0);
$price($iid, '2025-01-03', 120.0);
$price($iid, '2025-01-04', 150.0);
$report = Performance::forPortfolios([$pid]);
$year = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
$ok('同日價格上的中段買進計入本金，持股 20% 與 25% 鏈結成 50%', $year !== null
    && $near((float) $year['holdings_pct'], 50.0), var_export($year, true));
$ok('既有現金支付的買進不重複當外部存入，總值報酬為 80%', $year !== null
    && $near((float) $year['total_pct'], 80.0), var_export($year, true));

$pid = $portfolio('無價存入');
$iid = $instrument('PENDINGEXT');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, '2025-01-02']);
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',100,'USD')", [$pid, '2025-01-03']);
$trade($pid, $iid, 'BUY', '2025-01-02', 1.0, 100.0);
$price($iid, '2025-01-02', 100.0);
$price($iid, '2025-01-04', 100.0);
$price($iid, '2025-01-06', 100.0);
$report = Performance::forPortfolios([$pid]);
$year = null;
foreach ($report['years'] as $row) {
    if ((string) $row['label'] === '2025') {
        $year = $row;
        break;
    }
}
$ok('無價格日的存入併入下一有價日，合計全額計入而為 10%', $year !== null
    && $near((float) $year['total_pct'], 10.0), var_export($year, true));

echo "-- 無價格事件與截止日事件軸\n";
$ok('3 月 31 日減一個月落在 2 月最後一天', App\Calendar::subtractMonths('2024-03-31', 1) === '2024-02-29', App\Calendar::subtractMonths('2024-03-31', 1));
$ok('閏年 2 月 29 日減十二個月不會溢出', App\Calendar::subtractMonths('2024-02-29', 12) === '2023-02-28', App\Calendar::subtractMonths('2024-02-29', 12));
$ok('跨年減三個月：3 月 31 日對準 12 月 31 日', App\Calendar::subtractMonths('2024-03-31', 3) === '2023-12-31', App\Calendar::subtractMonths('2024-03-31', 3));

$pid = $portfolio('截止日前事件');
$iid = $instrument('CUTOFF');
$old = $today->modify('-12 year')->format('Y-m-d');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',2000,'USD')", [$pid, $old]);
$trade($pid, $iid, 'BUY', $old, 10.0, 100.0);
$trade($pid, $iid, 'BUY', $old, 5.0, 90.0);
$price($iid, $old, 50.0);
$price($iid, $day1, 100.0);
$price($iid, $day2, 100.0);
$price($iid, $day3, 110.0);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
    }
}
$ok('截止日前交易保留基線持股，但不產生圖表軸點', $report['meta']['points'] === 3
    && $report['meta']['start'] === $day1 && abs((float) $report['meta']['end_holdings'] - 1650.0) < 0.000001,
    var_export($report['meta'], true));
$ok('截止日前基線交易不跨越到近期 1 天報酬', $one !== null && $one['start'] === $day2
    && abs((float) $one['holdings_pct'] - 10.0) < 0.000001, var_export($one, true));

echo "-- SQL 截止日：舊日線不載入，交易仍回放，手動價規則不變\n";
$pid = $portfolio('SQL 截止與手動價');
$iid = $instrument('SQLWINDOW');
$old = $today->modify('-12 year')->format('Y-m-d');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',2000,'USD')", [$pid, $old]);
$trade($pid, $iid, 'BUY', $old, 10.0, 100.0);
$price($iid, $old, 80.0);
$price($iid, $day1, 100.0);
$price($iid, $day2, 110.0);
Database::run('UPDATE instruments SET manual_price = 95, manual_price_at = ? WHERE id = ?', [$old, $iid]);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('SQL 排除舊日線後仍保留舊交易基線與相同 10% 鏈結', $report['meta']['points'] === 2
    && $report['meta']['start'] === $day1 && abs((float) $report['meta']['end_holdings'] - 1100.0) < 0.000001
    && $one !== null && $one['start'] === $day1 && $near((float) $one['holdings_pct'], 10.0),
    'meta=' . json_encode($report['meta']) . ' one=' . json_encode($one));
Database::run('UPDATE instruments SET manual_price = 105, manual_price_at = ? WHERE id = ?', [$day2, $iid]);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('截止日內的同日手動價仍取代收盤並得到 5% 鏈結', abs((float) $report['meta']['end_holdings'] - 1050.0) < 0.000001
    && $one !== null && $near((float) $one['holdings_pct'], 5.0), 'meta=' . json_encode($report['meta']) . ' one=' . json_encode($one));

$pid = $portfolio('SQL 截止與手動價-only');
$iid = $instrument('SQLMANUALONLY');
$trade($pid, $iid, 'BUY', $old, 1.0, 100.0);
$price($iid, $old, 80.0);
Database::run('UPDATE instruments SET manual_price = 95, manual_price_at = ? WHERE id = ?', [$old, $iid]);
$report = Performance::forPortfolios([$pid]);
$ok('沒有區間內報價時，截止日前手動價不憑空產生軸點', $report['meta']['points'] === 0
    && in_array('SQLMANUALONLY', $report['meta']['missing_price'], true), var_export($report['meta'], true));
Database::run('UPDATE instruments SET manual_price = 105, manual_price_at = ? WHERE id = ?', [$day1, $iid]);
$report = Performance::forPortfolios([$pid]);
$ok('沒有區間內報價時，區間內手動價仍可產生價格軸點', $report['meta']['points'] === 1
    && $report['meta']['start'] === $day1 && abs((float) $report['meta']['end_holdings'] - 105.0) < 0.000001,
    var_export($report['meta'], true));

$pid = $portfolio('無價事件');
$iid = $instrument('NOBAR');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 10.0, 100.0);
$price($iid, $day2, 100.0);
$price($iid, $day3, 110.0);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
    }
}
$ok('無價格事件日不當起點，第一個有價日仍是期初基值', $one !== null
    && $one['start'] === $day2 && abs((float) $one['holdings_pct'] - 10.0) < 0.000001
    && abs((float) $one['total_pct'] - 10.0) < 0.000001, var_export($one, true));

echo "-- 公開 API：跨組合超賣不得互相消耗持股\n";
$pidA = $portfolio('跨組合甲');
$pidB = $portfolio('跨組合乙');
$iid = $instrument('CROSSPORT');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',2000,'USD')", [$pidA, $day1]);
$trade($pidA, $iid, 'BUY', $day1, 100.0, 10.0);
$trade($pidB, $iid, 'SELL', $day2, 50.0, 15.0);
$price($iid, $day1, 10.0);
$price($iid, $day2, 20.0);
$price($iid, $day3, 25.0);
$report = Performance::forPortfolios([$pidA, $pidB]);
$positions = Positions::forPortfolios([$pidA, $pidB]);
$cash = Positions::cashForList([$pidA, $pidB]);
$dashboardTotal = (float) $positions['summary']['market_value'] + (float) $cash['total_base'];
$ok('甲持股不被乙的超賣截斷，報表與總覽期末總值一致', abs((float) $report['meta']['end_holdings'] - 2500.0) < 0.000001
    && abs((float) $report['meta']['end_total'] - $dashboardTotal) < 0.000001,
    'report=' . json_encode($report['meta']) . ' dashboard=' . $dashboardTotal);
$ok('乙沒有持股時超賣不結算成交，現金只保留甲的真實餘額', abs((float) $report['meta']['end_cash'] - 1000.0) < 0.000001,
    'cash=' . $report['meta']['end_cash']);

$pdoProperty = new ReflectionProperty(Database::class, 'pdo');
$originalPdo = $pdoProperty->getValue();
$countingPdo = new ReplayCountingPdo('sqlite:' . $dir . '/portfolio.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdoProperty->setValue(null, $countingPdo);
$snapshot = Positions::snapshot([$pidA, $pidB]);
$replays = $countingPdo->replays;
$pdoProperty->setValue(null, $originalPdo);
$ok('總覽快照以單一批次重播所有組合且與分開查詢一致', $replays === 1
    && $snapshot['positions'] === $positions && $snapshot['cash'] === $cash,
    'replays=' . $replays);

echo "-- 缺價總值與換算溢位\n";
$pid = $portfolio('缺價總值');
$priced = $instrument('PRICED');
$unpriced = $instrument('UNPRICED');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency) VALUES (?,?,'IN',1000,'USD')", [$pid, $day1]);
$trade($pid, $priced, 'BUY', $day1, 1.0, 100.0);
$trade($pid, $unpriced, 'BUY', $day1, 1.0, 100.0);
$price($priced, $day1, 100.0);
$price($priced, $day2, 100.0);
$price($priced, $day3, 100.0);
$report = Performance::forPortfolios([$pid]);
$ok('期末有持股缺價時明確標記總值不完整', ($report['meta']['end_complete'] ?? null) === false
    && in_array('UNPRICED', $report['meta']['missing_price'], true), var_export($report['meta'], true));
$ok('年度列也保留完整性旗標，畫面可把總值顯示為 --', $report['years'] !== []
    && ($report['years'][count($report['years']) - 1]['complete'] ?? null) === false, var_export($report['years'], true));
$sameOverflow = convert(INF, 'USD', 'USD');
$ok('同幣別的非有限金額也不會被標成可換算', $sameOverflow === ['value' => null, 'converted' => false], var_export($sameOverflow, true));
Database::run("INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual) VALUES ('GBP',1e308,'2026-11-24','manual',1)");
$crossOverflow = convert(1.0e308, 'USD', 'GBP');
$ok('跨幣別換算溢位回報未知而不是 INF', $crossOverflow === ['value' => null, 'converted' => false], var_export($crossOverflow, true));

echo "-- 請求內 FX 快取與舊呼叫相容\n";
Database::run(
    "INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual) VALUES ('EUR',2,'2026-11-24','manual',1)
     ON CONFLICT(currency) DO UPDATE SET units_per_usd = excluded.units_per_usd"
);
$fxCache = [];
$first = convert(10.0, 'EUR', 'USD', $fxCache);
Database::run("UPDATE fx_rates SET units_per_usd = 4 WHERE currency = 'EUR'");
$cached = convert(10.0, 'eur', 'usd', $fxCache);
$fresh = convert(10.0, 'EUR', 'USD');
$ok('同一請求快取重複換算，新請求與三參數呼叫讀取新匯率', $first['value'] == 5.0
    && $cached['value'] == 5.0 && $fresh['value'] == 2.5 && fx_to_usd('EUR') == 4.0,
    'first=' . json_encode($first) . ' cached=' . json_encode($cached) . ' fresh=' . json_encode($fresh));
$missingCache = [];
$missing = convert(1.0, 'JPY', 'USD', $missingCache);
Database::run("INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual) VALUES ('JPY',100,'2026-11-24','manual',1)");
$cachedMissing = convert(1.0, 'JPY', 'USD', $missingCache);
$freshMissing = convert(1.0, 'JPY', 'USD');
$ok('缺匯率也只查一次，且不會跨請求快取缺值', $missing['converted'] === false
    && $cachedMissing['converted'] === false && $freshMissing['value'] == 0.01,
    'missing=' . json_encode($missing) . ' cached=' . json_encode($cachedMissing) . ' fresh=' . json_encode($freshMissing));

$r = $ret([1.0, 1.0e308], [0.0, -1.0e308]);
$ok('鏈結過程溢位時回 null，不把 INF 當成報酬', $r === null, var_export($r, true));

echo "-- 公開 API：回報使用未調整收盤\n";
$pid = $portfolio('RAWCLOSE');
$iid = $instrument('RAWCLOSE');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency)
               VALUES (?,?,'IN',100,'USD')", [$pid, $day1]);
$trade($pid, $iid, 'BUY', $day1, 1.0, 100.0);
Database::run("INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source)
               VALUES (?, ?, 100, 90, 'USD', 'yahoo'), (?, ?, 100, 99, 'USD', 'yahoo')",
    [$iid, $day1, $iid, $day2]);
$report = Performance::forPortfolios([$pid]);
$one = null;
foreach ($report['periods'] as $row) {
    if ($row['label'] === '1 天') {
        $one = $row;
        break;
    }
}
$ok('raw close 不變時持股與總報酬均為 0%，adj_close 差異不污染回報',
    $one !== null && $near((float) $one['holdings_pct'], 0.0) && $near((float) $one['total_pct'], 0.0)
    && abs((float) $report['meta']['end_holdings'] - 100.0) < 0.000001,
    'one=' . json_encode($one) . ' meta=' . json_encode($report['meta']));

echo "-- SQLite 讀取快照\n";
$before = Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid]);
$writer = new PDO('sqlite:' . $dir . '/portfolio.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$writer->exec('PRAGMA busy_timeout = 5000');
$seen = Database::read(static function () use ($writer, $pid): array {
    $first = Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid]);
    $writer->exec("UPDATE portfolios SET name = 'SNAPSHOTWRITE' WHERE id = " . (int) $pid);

    return [$first, Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid])];
});
$after = Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid]);
$ok('同一讀取快照內的兩次查詢不受另一連線寫入影響，完成後可讀到新值',
    $seen === [$before, $before] && $after === 'SNAPSHOTWRITE',
    'seen=' . json_encode($seen) . ' after=' . $after);

Database::pdo()->beginTransaction();
Database::run("UPDATE portfolios SET name = 'NESTED' WHERE id = ?", [$pid]);
$nested = Database::read(static fn (): string => (string) Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid]));
$stillWriting = Database::pdo()->inTransaction();
Database::pdo()->rollBack();
$rolledBack = Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid]);
$ok('巢狀讀取不會提交呼叫者的寫入交易，外層 rollback 仍有效',
    $nested === 'NESTED' && $stillWriting && $rolledBack === $after,
    'nested=' . $nested . ' writing=' . var_export($stillWriting, true) . ' rolled_back=' . $rolledBack);

$snapshotFailure = null;
try {
    Database::read(static function () use ($pid): void {
        Database::run("UPDATE portfolios SET name = 'SHOULDROLLBACK' WHERE id = ?", [$pid]);
        throw new RuntimeException('injected snapshot failure');
    });
} catch (RuntimeException $e) {
    $snapshotFailure = $e->getMessage();
}
$ok('讀取回呼例外會回滾交易且不留下半筆寫入',
    $snapshotFailure === 'injected snapshot failure' && !Database::pdo()->inTransaction()
    && Database::scalar('SELECT name FROM portfolios WHERE id = ?', [$pid]) === $after,
    'failure=' . $snapshotFailure);

echo "-- 總覽幣別小計與合計溢位\n";
$breakdown = new ReflectionMethod(Handlers::class, 'currencyBreakdown');
$summary = ['by_currency' => [
    ['currency' => 'USD', 'unpriced' => 0, 'market_value' => 1e308, 'base_ok' => true, 'base_market_value' => 1e308],
    ['currency' => 'HKD', 'unpriced' => 0, 'market_value' => 1e308, 'base_ok' => true, 'base_market_value' => 1e308],
]];
$cash = ['base' => 'TWD', 'rows' => []];
$result = $breakdown->invoke(null, $summary, $cash);
$ok('總覽跨幣別小計相加溢位時合計顯示未知', $result['total_base'] === null, json_encode($result));
$summary['by_currency'] = [
    ['currency' => 'USD', 'unpriced' => 0, 'market_value' => 1e308, 'base_ok' => true, 'base_market_value' => 1e308],
];
$cash['rows'] = [
    ['currency' => 'USD', 'balance' => 1e308, 'base_ok' => true, 'base_value' => 1e308],
];
$result = $breakdown->invoke(null, $summary, $cash);
$ok('總覽同幣別持股加現金溢位時該列與合計留未知', $result['rows'][0]['base_value'] === null
    && $result['total_base'] === null, json_encode($result));
$summary['by_currency'][0]['base_market_value'] = 100.0;
$cash['rows'][0]['base_value'] = 30.0;
$result = $breakdown->invoke(null, $summary, $cash);
$ok('總覽有限幣別小計仍正常相加', $result['rows'][0]['base_value'] == 130.0
    && $result['total_base'] == 130.0, json_encode($result));

echo $fail === 0 ? "PERFORMANCE CHAIN ALL PASS\n" : "PERFORMANCE CHAIN FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
