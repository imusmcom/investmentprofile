<?php
declare(strict_types=1);

/**
 * 移動平均成本池的獨立驗算；只在 /tmp 建立本次 scratch 資料庫。
 * 用 Reflection 讀 private book() 的純結果，驗買進費用資本化、賣出費用抵收入、部分出清與出清後歸零。
 */
$dir = '/tmp/portfolio-positions-math-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "無法建立 /tmp scratch：{$dir}\n");
    exit(2);
}
putenv('PORTFOLIO_DATA_DIR=' . $dir);
require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Positions;

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
$book = new ReflectionMethod(Positions::class, 'book');

$case = static function (string $name) use ($book, $ok): array {
    Database::run('INSERT INTO portfolios (name, description) VALUES (?, ?)', [$name, '成本池驗算']);
    $pid = (int) Database::pdo()->lastInsertId();
    Database::run(
        "INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES (?,?, 'US', ?, 'USD', 'EQUITY')",
        [$name, $name, $name]
    );
    $iid = (int) Database::pdo()->lastInsertId();
    $ok("建立 scratch 組合與投資物：{$name}", $pid > 0 && $iid > 0, "pid={$pid}, iid={$iid}");
    return [$pid, $iid];
};
$trade = static function (int $pid, int $iid, string $side, string $date, float $qty, float $price, float $fee): void {
    Database::run(
        'INSERT INTO transactions (portfolio_id,instrument_id,side,traded_on,quantity,price,fee) VALUES (?,?,?,?,?,?,?)',
        [$pid, $iid, $side, $date, $qty, $price, $fee]
    );
};

echo "-- 部分出清與平均成本\n";
[$pid, $iid] = $case('PARTIAL');
$trade($pid, $iid, 'BUY', '2025-01-02', 100.0, 10.0, 5.0);
$trade($pid, $iid, 'BUY', '2025-01-03', 100.0, 20.0, 5.0);
$pos = $book->invoke(null, $pid)[$iid];
$ok('買進手續費資本化：成本池 (100×10+5)+(100×20+5)=3010', $near((float) $pos['cost_pool'], 3010.0), (string) $pos['cost_pool']);
$ok('移動平均成本 3010/200=15.05', $near((float) $pos['avg'], 15.05), (string) $pos['avg']);
$trade($pid, $iid, 'SELL', '2025-01-04', 50.0, 30.0, 7.0);
$pos = $book->invoke(null, $pid)[$iid];
$ok('賣出 50 股後持股 150、成本池 2257.50', $near((float) $pos['qty'], 150.0) && $near((float) $pos['cost_pool'], 2257.5), var_export($pos, true));
$ok('賣出手續費抵收入，已實現 (50×30−7)−50×15.05=740.50', $near((float) $pos['realized'], 740.5), (string) $pos['realized']);
$ok('現金流出含買費 3010，現金流入已扣賣費 1493', $near((float) $pos['cash_out'], 3010.0) && $near((float) $pos['cash_in'], 1493.0), var_export($pos, true));
$ok('三段手續費合計 17，沒有賣超標記', $near((float) $pos['fees'], 17.0) && (int) $pos['oversold'] === 0, var_export($pos, true));

echo "-- 完全出清後成本池歸零\n";
[$pid, $iid] = $case('CLEARED');
$trade($pid, $iid, 'BUY', '2025-02-02', 10.0, 100.0, 5.0);
$trade($pid, $iid, 'SELL', '2025-02-03', 10.0, 120.0, 7.0);
$pos = $book->invoke(null, $pid)[$iid];
$ok('出清後 qty、cost_pool、avg 都必須是 0', $near((float) $pos['qty'], 0.0)
    && $near((float) $pos['cost_pool'], 0.0) && $near((float) $pos['avg'], 0.0), var_export($pos, true));
$ok('含兩端手續費的已實現為 (10×120−7)−(10×100+5)=188', $near((float) $pos['realized'], 188.0), (string) $pos['realized']);

echo "-- 賣超只結清實際持股\n";
[$pid, $iid] = $case('OVERCLAMP');
$trade($pid, $iid, 'BUY', '2025-03-02', 10.0, 100.0, 5.0);
$trade($pid, $iid, 'SELL', '2025-03-03', 20.0, 120.0, 5.0);
$pos = $book->invoke(null, $pid)[$iid];
$ok('持有 10 股卻賣 20 股，持股與成本池仍歸零', $near((float) $pos['qty'], 0.0)
    && $near((float) $pos['cost_pool'], 0.0), var_export($pos, true));
$ok('不存在的另外 10 股不產生收入，收入僅 10×120−5=1195', $near((float) $pos['cash_in'], 1195.0), (string) $pos['cash_in']);
$ok('賣超次數記為 1，已實現只來自實際結清的 10 股', (int) $pos['oversold'] === 1
    && $near((float) $pos['realized'], 190.0), var_export($pos, true));

echo "-- 極小持股與累計溢位\n";
[$pid, $iid] = $case('DUST');
$trade($pid, $iid, 'BUY', '2025-03-02', 1.0e-10, 100.0, 0.0);
$trade($pid, $iid, 'SELL', '2025-03-03', 1.0e-10, 101.0, 0.0);
$pos = $book->invoke(null, $pid)[$iid];
$ok('極小持股完整回轉，已實現仍為 1e-10 而不是被灰塵門檻歸零',
    $near((float) $pos['realized'], 1.0e-10) && (float) $pos['qty'] === 0.0,
    'realized=' . $pos['realized'] . ' qty=' . $pos['qty']);

[$pid, $iid] = $case('OVERFLOW');
$trade($pid, $iid, 'BUY', '2025-03-02', 1.0e308, 1.0, 0.0);
$trade($pid, $iid, 'BUY', '2025-03-03', 1.0e308, 1.0, 0.0);
$overflow = null;
try {
    $book->invoke(null, $pid);
} catch (RuntimeException $e) {
    $overflow = $e->getMessage();
}
$ok('單筆有限但累計數量／成本溢出時明確拒絕，不輸出 INF',
    $overflow !== null && str_contains($overflow, '超出可計算範圍'), $overflow ?? 'no exception');

[$pid, $iid] = $case('CASHOVERFLOW');
Database::run("INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency)
               VALUES (?, '2025-03-02', 'IN', 1e308, 'USD'), (?, '2025-03-03', 'IN', 1e308, 'USD')", [$pid, $pid]);
$cashOverflow = null;
try {
    Positions::cashFor($pid);
} catch (RuntimeException $e) {
    $cashOverflow = $e->getMessage();
}
$ok('現金累計溢出時明確拒絕，不輸出 INF',
    $cashOverflow !== null && str_contains($cashOverflow, '超出可計算範圍'), $cashOverflow ?? 'no exception');

echo $fail === 0 ? "POSITIONS COST ALL PASS\n" : "POSITIONS COST FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
