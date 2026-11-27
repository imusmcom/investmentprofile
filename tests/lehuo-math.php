<?php
declare(strict_types=1);

/**
 * 樂活五線譜算式的獨立驗算（tests/lehuo-math.php）。
 *
 * 純算式與 /tmp scratch 分開驗算：視窗裁掉多少根、R² 落在哪一檔、五個區間對應到的動作、
 * 通道的上下軌與均線位置，以及 bars() 的 42 個月 SQL 視窗，都要在已知的答案上對得起來。
 * 不碰正式資料、不碰網路；有失敗就 exit 1。
 *
 * 序列刻意用「每 3 天一根」而不是每根交易日連號：這樣「第幾根」不等於「第幾天」，
 * 任何把根數當日數用的斜率錯誤都會立刻對不上數。通道那一節改用「每週正好一根」（週一起算），
 * 因為 20 週均線與母數標準差要能手算對驗（1～20 的均線是 10.5、離差平方和 665），
 * 每 3 天一根的序列會落在哪個週、收成幾根都不固定，對不出確切的數字。
 */
$dir = '/tmp/portfolio-lehuo-math-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "無法建立 /tmp scratch：{$dir}\n");
    exit(2);
}
putenv('PORTFOLIO_DATA_DIR=' . $dir);
require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Strategy as Lehuo;

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
    if ($pass) {
        echo "  ok   {$name}\n";

        return;
    }
    $fail++;
    echo '  FAIL ' . $name . ($got === '' ? '' : "（{$got}）") . "\n";
};

$bars = static function (int $n, callable $close, string $start = '2016-01-02'): array {
    $first = new DateTimeImmutable($start);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = ['d' => $first->modify('+' . ($i * 3) . ' days')->format('Y-m-d'), 'c' => (float) $close($i)];
    }

    return $out;
};

$zig = static fn (int $i, float $amp = 1.2): float => ($i % 4 < 2 ? $amp : -$amp);

/** 每週正好一根（週一起算），週線數＝根數，布林的數字才能手算對驗。 */
$weeks = static function (array $closes, string $start = '2020-01-06'): array {
    $first = new DateTimeImmutable($start);
    $out = [];
    foreach ($closes as $i => $close) {
        $out[] = ['d' => $first->modify('+' . ($i * 7) . ' days')->format('Y-m-d'), 'c' => (float) $close];
    }

    return $out;
};
$N = 420;      // 每 3 天一根 ≈ 41.4 個月：超過 250 根的下限，又填不滿 42 個月的窗口
$LONG = 1300;  // ≈ 10.7 年：一定要被窗口裁掉

echo "-- 視窗\n";
$full = $bars($LONG, static fn (int $i): float => 100 + 0.02 * $i + $zig($i));
$s = Lehuo::spectrum($full);
$last = (new DateTimeImmutable('2016-01-02'))->modify('+' . (($LONG - 1) * 3) . ' days')->format('Y-m-d');
$target = (new DateTimeImmutable($last))->modify('-42 months')->format('Y-m-d');
$inside = 0;
foreach ($full as $b) {
    if (strcmp((string) $b['d'], $target) >= 0) {
        $inside++;
    }
}
$ok('一萬年的資料也只取最近 42 個月', $s['state'] === 'ok' && $s['bars'] === $inside && $s['bars'] < $LONG, "bars={$s['bars']} inside={$inside}");
$ok('起點不早於 42 個月前的那一天', strcmp((string) $s['from'], $target) >= 0, "from={$s['from']} target={$target}");
$ok('終點與現在價就是最後一根', $s['to'] === $last && abs((float) $s['close'] - (100 + 0.02 * ($LONG - 1) + $zig($LONG - 1))) < 1e-9, "to={$s['to']}");
$ok('窗口長度標在 42 個月附近', $s['months'] > 40.0 && $s['months'] <= 42.0, 'months=' . var_export($s['months'], true));
$ok('窗口夠長時不會多嘴說歷史不足', !str_contains(implode(' ', $s['warn']), '窗口只有'), implode(' ', $s['warn']));

// 整個歷史填不滿 42 個月時改用全部，並且老實說窗口不夠長。
$thin = $bars(300, static fn (int $i): float => 100 + 0.03 * $i + $zig($i));
$s = Lehuo::spectrum($thin);
$ok('填不滿窗口時用全部歷史', $s['state'] === 'ok' && $s['bars'] === 300, "state={$s['state']} bars={$s['bars']}");
$ok('並且警告窗口不到 3.5 年', str_contains(implode(' ', $s['warn']), '窗口只有'), implode(' ', $s['warn']));

$s = Lehuo::spectrum($bars(200, static fn (int $i): float => 100 + $i));
$ok('不到 250 根就明說資料不足', $s['state'] === 'short' && str_contains((string) $s['note'], '不足'), "state={$s['state']} note={$s['note']}");
$ok('資料不足時不會編出五條線', $s['tl'] === null && $s['zone'] === null && $s['close'] === null);
$ok('空序列也是老實回報', Lehuo::spectrum([])['state'] === 'empty' && Lehuo::channel([])['state'] === 'empty');

echo "-- 斜率與線的位置\n";
$line = $bars($N, static fn (int $i): float => 100 + 0.02 * $i);
$s = Lehuo::spectrum($line);
// 每天漲 0.02/3 元，以窗口中點的價位換成百分比，再乘 365。
$annual = 0.02 / 3.0 * 365.0 / (100 + 0.02 * (($N - 1) / 2)) * 100.0;
$ok('完全線性時五條線重疊、並說明間距失去意義', abs((float) $s['sde']) < 1.0e-9
    && abs((float) $s['tl'] - (float) $s['close']) < 1.0e-9
    && str_contains((string) $s['note'], '重疊'), 'sde=' . var_export($s['sde'], true) . " note={$s['note']}");
$ok('完全線性時 R² 就是 1', $s['r2'] !== null && abs((float) $s['r2'] - 1.0) < 1.0e-9, 'r2=' . var_export($s['r2'], true));
$ok('σ 位置不會除以零', $s['z'] === 0.0 && $s['zone'] === '趨勢區', 'z=' . var_export($s['z'], true));
$ok('年化斜率按「天」而不是按「根」算', $s['slope'] !== null && abs((float) $s['slope'] - $annual) < 0.02,
    'slope=' . var_export($s['slope'], true) . " want={$annual}");
$ok('斜率為正時不會出現向下傾的警告', $s['slope'] > 0.0 && !str_contains(implode(' ', $s['warn']), '向下傾'), implode(' ', $s['warn']));

$down = $bars($N, static fn (int $i): float => 300 - 0.05 * $i);
$s = Lehuo::spectrum($down);
$ok('下跌通道會標出「負斜率」這條警告', $s['slope'] < 0.0 && str_contains(implode(' ', $s['warn']), '通道向下傾'), implode(' ', $s['warn']));

echo "-- 五個區間\n";
$probe = Lehuo::spectrum($thin);
$ok('帶雜訊的上升通道也算得出間距', (float) $probe['sde'] > 0.5 && $probe['fit'] === 'trend', 'sde=' . var_export($probe['sde'], true));
// 把最後一根放到指定的 σ 位置上，看區間與動作對不對。
foreach ([[2.7, '極度貪婪'], [1.5, '貪婪'], [0.0, '趨勢區'], [-1.5, '恐懼'], [-2.7, '極度恐懼']] as [$want, $zone]) {
    $case = $thin;
    $case[count($case) - 1]['c'] = (float) $probe['tl'] + $want * (float) $probe['sde'];
    $s = Lehuo::spectrum($case);
    $ok('價在 ' . ($want > 0 ? '+' : '') . $want . 'σ 時判為' . $zone,
        $s['zone'] === $zone && abs((float) $s['z'] - $want) < 0.6, 'zone=' . $s['zone'] . ' z=' . var_export($s['z'], true));
}
$case = $thin;
$case[count($case) - 1]['c'] = (float) $probe['tl'] - 2.7 * (float) $probe['sde'];
$s = Lehuo::spectrum($case);
$ok('跌破 −2σ 時追加尾部警告', str_contains(implode(' ', $s['warn']), '2.5%'), implode(' ', $s['warn']));
$ok('現在價到趨勢線的距離用百分比講', abs((float) $s['gap'] - ((float) $s['close'] / (float) $s['tl'] - 1) * 100) < 1.0e-9 && $s['gap'] < 0.0,
    'gap=' . var_export($s['gap'], true));
$case = $thin;
$case[count($case) - 1]['c'] = (float) $probe['tl'] + 2.7 * (float) $probe['sde'];
$s = Lehuo::spectrum($case);
$ok('衝上 ＋2σ 時改說等拉回', str_contains(implode(' ', $s['warn']), '拉回'), implode(' ', $s['warn']));
$ok('五條線的順序永遠是 d2<d1<tl<u1<u2', $s['d2'] < $s['d1'] && $s['d1'] < $s['tl'] && $s['tl'] < $s['u1'] && $s['u1'] < $s['u2'],
    $s['d2'] . '/' . $s['d1'] . '/' . $s['tl'] . '/' . $s['u1'] . '/' . $s['u2']);

echo "-- R² 三檔\n";
$cases = [
    'trend'   => static fn (int $i): float => 100 + 0.02 * $i + $zig($i, 1.0),
    'caution' => static fn (int $i): float => 100 + 0.0105 * $i + $zig($i, 1.0),
    'none'    => static fn (int $i): float => 100 + $zig($i, 1.0),
];
$seen = [];
foreach ($cases as $want => $f) {
    $s = Lehuo::spectrum($bars($N, $f));
    $seen[$want] = (float) $s['r2'];
    $ok("貼合度分級落在 {$want}", $s['fit'] === $want, 'fit=' . $s['fit'] . ' r2=' . var_export($s['r2'], true));
}
$ok('R² 由線到雜訊單調下降', $seen['trend'] > $seen['caution'] && $seen['caution'] > $seen['none'], json_encode($seen));
$ok('不適用與謹慎都會講進警示裡',
    str_contains(implode(' ', Lehuo::spectrum($bars($N, $cases['none']))['warn']), '不適用')
    && str_contains(implode(' ', Lehuo::spectrum($bars($N, $cases['caution']))['warn']), '謹慎'));
$flat = Lehuo::spectrum($bars($N, static fn (): float => 50.0));
$ok('一價不動的序列不會假稱貼合度完美', $flat['r2'] === null && $flat['fit'] === 'none' && $flat['cv'] === 0.0,
    'r2=' . var_export($flat['r2'], true) . ' cv=' . var_export($flat['cv'], true));

echo "-- 通道（20 週布林）\n";
// 1～20 這串週線是能手算的基準：均線 210/20＝10.5，離差平方和 20(20²−1)/12＝665。
$w20 = $weeks(range(1, 20));
$c = Lehuo::channel($w20);
$pop = sqrt(665 / 20);      // 母數標準差：布林用的是這個
$smp = sqrt(665 / 19);      // n−1：如果哪天有人改回樣本標準差，這條會先紅
$ok('每週一根日線時，週線數就是根數', $c['state'] === 'ok' && $c['weeks'] === 20 && $c['bars'] === 20,
    "state={$c['state']} weeks={$c['weeks']} bars={$c['bars']}");
$ok('中軸＝最近 20 週收盤的算術平均', $c['mid'] !== null && abs((float) $c['mid'] - 10.5) < 1.0e-12, 'mid=' . var_export($c['mid'], true));
$ok('標準差是母數（除以 20）不是 n−1', abs((float) $c['sd'] - $pop) < 1.0e-12 && abs((float) $c['sd'] - $smp) > 0.05,
    'sd=' . var_export($c['sd'], true) . " 母數={$pop} 樣本={$smp}");
$ok('上下軌＝中軸加減 2 倍標準差', abs((float) $c['upper'] - (10.5 + 2 * $pop)) < 1.0e-12
    && abs((float) $c['lower'] - (10.5 - 2 * $pop)) < 1.0e-12 && $c['upper'] > $c['mid'] && $c['mid'] > $c['lower'],
    $c['lower'] . '/' . $c['mid'] . '/' . $c['upper']);
$ok('收盤在本週收盤價那一棒上', abs((float) $c['close'] - 20.0) < 1.0e-12, 'close=' . var_export($c['close'], true));
$ok('軌道之內就是軌道之內，不多話', $c['where'] === 'inside' && $c['warn'] === [], $c['where'] . '/' . implode(' ', $c['warn']));

// 更早的歷史不該動到任何一個數字：前面塞 40 週荒謬的價位，窗口那 20 週不變。
$long = $weeks(array_merge(array_fill(0, 40, 9999.0), range(1, 20)));
$cl = Lehuo::channel($long);
$ok('窗口只取最後 20 週，之前的歷史不進數', $cl['weeks'] === 60 && abs((float) $cl['mid'] - 10.5) < 1.0e-12
    && abs((float) $cl['sd'] - $pop) < 1.0e-12, "weeks={$cl['weeks']} mid={$cl['mid']}");

$rail_up = $weeks(array_merge(range(1, 19), [30]));
$c = Lehuo::channel($rail_up);
$ok('週收盤衝過上軌會被標出來並說短期過熱', $c['where'] === 'upper' && (float) $c['close'] > (float) $c['upper']
    && str_contains(implode(' ', $c['warn']), '上軌之外'), $c['where'] . '/' . implode(' ', $c['warn']));
$rail_dn = $weeks(array_merge(array_fill(0, 19, 100.0), [40]));
$c = Lehuo::channel($rail_dn);
$ok('週收盤跌到下軌之下會被標成低位', $c['where'] === 'lower' && (float) $c['close'] < (float) $c['lower']
    && str_contains(implode(' ', $c['warn']), '下軌之外'), $c['where'] . '/' . implode(' ', $c['warn']));

// 通道出界、週均線卻反向：兩個訊號互相矛盾，這一頁不替使用者挑一個。
$off = $weeks(array_fill(0, 19, 100.0));
$off[] = ['d' => date('Y-m-d', strtotime($off[18]['d'] . ' +8 day')), 'c' => 20.0];
$off[] = ['d' => date('Y-m-d', strtotime($off[18]['d'] . ' +9 day')), 'c' => 160.0];
$c = Lehuo::channel($off);
$ok('同一週裡最後一根才是週收盤', $c['weeks'] === 20 && abs((float) $c['close'] - 160.0) < 1.0e-12,
    "weeks={$c['weeks']} close=" . var_export($c['close'], true));
$ok('出上軌、2 週線也在上時兩個訊號同調，不多嘴', $c['where'] === 'upper' && $c['bias'] === 'up'
    && !str_contains(implode(' ', $c['warn']), '不同調'), $c['where'] . '/' . $c['bias'] . '/' . implode(' ', $c['warn']));
// 上一週衝高、這一週摔到下軌之下：週收盤出界了，2 週均線卻還掛在 19 週均線上面＝還沒轉向。
$lag = $weeks(array_merge(array_fill(0, 18, 100.0), [190.0, 55.0]));
$c = Lehuo::channel($lag);
$ok('通道說lower、2 週均線卻還在上的不同調會被點出來', $c['where'] === 'lower' && $c['bias'] === 'up'
    && str_contains(implode(' ', $c['warn']), '不同調'), $c['where'] . '/' . $c['bias'] . '/' . implode(' ', $c['warn']));

$zero = $weeks(array_merge([1, 2, 0], range(4, 21)));
$c = Lehuo::channel($zero);
$ok('週收盤出現 0 時停止判讀，不產生通道或訊號', $c['state'] === 'data'
    && $c['upper'] === null && $c['where'] === null && str_contains((string) $c['note'], '不是正數'),
    "state={$c['state']} upper=" . var_export($c['upper'], true));
$negative = $weeks(array_merge(range(1, 19), [-1.0]));
$c = Lehuo::channel($negative);
$ok('負價也被視為資料異常', $c['state'] === 'data' && $c['mid'] === null && $c['close'] === null, 'state=' . $c['state']);
$nonfinite = $weeks(range(1, 20));
$nonfinite[19]['c'] = INF;
$c = Lehuo::channel($nonfinite);
$ok('無限大的週收盤不會製造 NaN 通道', $c['state'] === 'data' && $c['upper'] === null, 'state=' . $c['state']);

// 20 週要的是「週」，日線根數再多也補不出沒有的週。
$few = $bars(40, static fn (int $i): float => 100 + $i);
$c = Lehuo::channel($few);
$want = count(Lehuo::weekly($few));
$ok('不到 20 週就留白，講的是週數而不是日線根數', $c['state'] === 'short' && $c['upper'] === null
    && $c['weeks'] === $want && str_contains((string) $c['note'], "日線 40 根"), "state={$c['state']} weeks={$c['weeks']} note={$c['note']}");

// 兩條輔助均線也要跟着換尺度：和通道同一份週線，最後兩週對上最後十九週。
$ma = Lehuo::channel($w20);
$ok('2 週均線取最後兩根週收盤', $ma['ma2'] !== null && abs((float) $ma['ma2'] - 19.5) < 1.0e-9, 'ma2=' . var_export($ma['ma2'], true));
$ok('19 週均線取最後十九根週收盤（不是二十根）', $ma['ma19'] !== null && abs((float) $ma['ma19'] - 11.0) < 1.0e-9,
    'ma19=' . var_export($ma['ma19'], true));
$ok('2 週線在 19 週線上方時偏向為 up', $ma['bias'] === 'up');
$ok('一路上漲的通道不會亂報出界', $ma['where'] === 'inside' && $ma['warn'] === [], implode(' ', $ma['warn']));

$daily = $bars(300, static fn (int $i): float => 100 + $i);
$scale = Lehuo::channel($daily);
$wk = Lehuo::weekly($daily);
$two = ((float) $wk[count($wk) - 1]['c'] + (float) $wk[count($wk) - 2]['c']) / 2.0;
$ok('偏向算的是週線，不是最後兩根日線', abs((float) $scale['ma2'] - $two) < 1.0e-9 && abs((float) $scale['ma2'] - 398.5) > 0.01,
    'ma2=' . var_export($scale['ma2'], true) . " 週線口徑={$two} 日線口徑=398.5");

// 這一週還沒收盤，所以這一欄會隨今天的報價動：要讓使用者看得出來。
$live = $weeks(range(1, 19));
$live[] = ['d' => date('Y-m-d'), 'c' => 20.0];
$c = Lehuo::channel($live);
$ok('含進還沒收盤的本週，並標成 partial', $c['state'] === 'ok' && $c['partial'] === true
    && $c['week'] === date('Y-m-d'), "state={$c['state']} partial=" . var_export($c['partial'], true) . " week={$c['week']}");
$ok('歷史結算過的序列不會被標成本週', $cl['partial'] === false && $w20 !== [] && Lehuo::channel($w20)['partial'] === false);

echo "-- 日曆月視窗\n";
$monthsBefore = new ReflectionMethod(Lehuo::class, 'monthsBefore');
$monthsBefore->setAccessible(true);
$monthCases = [
    ['2025-03-31', 1, '2025-02-28'],
    ['2024-03-31', 1, '2024-02-29'],
    ['2025-05-31', 1, '2025-04-30'],
    ['2025-01-31', 1, '2024-12-31'],
    ['2025-03-31', 13, '2024-02-29'],
    ['2025-03-30', 1, '2025-02-28'],
    ['2025-03-01', 42, '2021-09-01'],
];
foreach ($monthCases as [$date, $months, $want]) {
    $got = $monthsBefore->invoke(null, $date, $months);
    $ok("{$date} 減 {$months} 個月＝{$want}", $got === $want, "got={$got}");
}

echo "-- 週線收盤\n";
$week = Lehuo::weekly([
    ['d' => '2021-01-04', 'c' => 1.0],
    ['d' => '2021-01-05', 'c' => 2.0],
    ['d' => '2021-01-06', 'c' => 3.0],
    ['d' => '2021-01-11', 'c' => 4.0],
]);
$ok('同一週只留最後一根，跨週才換新一根', count($week) === 2 && (float) $week[0]['c'] === 3.0 && (float) $week[1]['c'] === 4.0,
    json_encode($week));
$ok('空序列收不出週線', Lehuo::weekly([]) === []);

echo "-- 口徑（未含股利的那幾根要講出來）\n";
// 合成序列不帶 raw 欄：老規則，缺欄就当它是含股利口徑，不能憑空多出警告。
$clean = $bars($N, static fn (int $i): float => 100 + 0.02 * $i + $zig($i));
$s = Lehuo::spectrum($clean);
$c = Lehuo::channel($clean);
$ok('沒有 raw 標記時不會憑空報未含股利', $s['raw'] === 0 && $c['raw'] === 0
    && !str_contains(implode(' ', $s['warn']) . implode(' ', $c['warn']), '含股利'), implode(' ', $s['warn']) . ' / ' . implode(' ', $c['warn']));
$ok('缺 raw 欄的舊序列照樣能用', Lehuo::rawBars($clean) === 0 && Lehuo::rawBars([['d' => '2020-01-02', 'c' => 1.0], ['d' => '2020-01-05', 'c' => 1.0, 'raw' => true]]) === 1);

// 旗標只講口徑，不得動到任何一個算出來的數字。
$mixed = $clean;
$mixed[5]['raw'] = true;
$mixed[count($mixed) - 1]['raw'] = true;
$s2 = Lehuo::spectrum($mixed);
$c2 = Lehuo::channel($mixed);
$ok('旗標不會改動線、間距與現價', abs((float) $s2['tl'] - (float) $s['tl']) < 1e-12
    && abs((float) $s2['sde'] - (float) $s['sde']) < 1e-12
    && abs((float) $s2['close'] - (float) $s['close']) < 1e-12
    && abs((float) $c2['upper'] - (float) $c['upper']) < 1e-12, '旗標動到數字了');
// 五線譜的窗口是 42 個月（整個 $clean 都在裡面），通道只有最近 20 週：同一串日線，兩邊根數本來就不同。
$ok('兩邊各自回報自己窗口裡用到的根數', $s2['raw'] === 2 && $c2['raw'] === 1, "spectrum={$s2['raw']} channel={$c2['raw']}");
$ok('未含股利會被寫進五線譜的警示', str_contains(implode(' ', $s2['warn']), '未調整價'), implode(' ', $s2['warn']));
$ok('通道那一邊也同樣講一次', str_contains(implode(' ', $c2['warn']), '未調整價'), implode(' ', $c2['warn']));

// 全期都是人工標價那種序列：通道最多只有 20 根週線，不能回報出 21 根。
$hand = $bars(300, static fn (int $i): float => 100 + $i);
foreach ($hand as $k => $b) {
    $hand[$k]['raw'] = true;
}
$s3 = Lehuo::spectrum($hand);
$c3 = Lehuo::channel($hand);
$ok('整串未含股利時根數就是根數', $s3['raw'] === $s3['bars'] && $c3['raw'] === 20 && $c3['weeks'] > 20,
    "s={$s3['raw']}/{$s3['bars']} c={$c3['raw']}/{$c3['weeks']}");

$thinraw = $bars(40, static fn (int $i): float => 100 + $i);
$thinraw[count($thinraw) - 1]['raw'] = true;
$s4 = Lehuo::spectrum($thinraw);
$c4 = Lehuo::channel($thinraw);
$ok('算不了的序列也把口徑問題留著（五線譜與通道都要）', $s4['state'] === 'short' && $c4['state'] === 'short'
    && $s4['raw'] === 1 && $c4['raw'] === 1, "s={$s4['state']}/{$s4['raw']} c={$c4['state']}/{$c4['raw']}");
$ok('空序列一根都沒有，根數也是零', Lehuo::spectrum([])['raw'] === 0 && Lehuo::channel([])['raw'] === 0);

echo "-- bars() SQL 視窗與人工標價\n";
Database::run("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES ('LH-WINDOW','LH-WINDOW','US','LH-WINDOW','USD','EQUITY')");
$windowId = (int) Database::pdo()->lastInsertId();
$insert = static function (int $id, string $date, float $close, float $adj): void {
    Database::run("INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source) VALUES (?,?,?,?,?, 'yahoo')",
        [$id, $date, $close, $adj, 'USD']);
};
$insert($windowId, '2010-01-04', 9999.0, 9999.0);
$insert($windowId, '2020-09-29', 9998.0, 9998.0);
$insert($windowId, '2020-09-30', 100.0, 100.0);
$insert($windowId, '2024-03-28', 110.0, 111.0);
$manualBars = Lehuo::bars($windowId, 125.0, '2024-03-31 12:00:00');
$ok('較新有效人工標價把 42 個月 SQL 起點移到正確日期',
    array_column($manualBars, 'd') === ['2010-01-04', '2020-09-29', '2020-09-30', '2024-03-28', '2024-03-31']
    && (float) $manualBars[4]['c'] === 125.0 && $manualBars[4]['raw'] === true,
    json_encode($manualBars));
$cachedBars = Lehuo::bars($windowId, 125.0, '2024-03-27');
$ok('較舊人工標價不改變快照日線的 42 個月視窗',
    array_column($cachedBars, 'd') === ['2010-01-04', '2020-09-29', '2020-09-30', '2024-03-28']
    && (float) $cachedBars[3]['c'] === 111.0 && $cachedBars[3]['raw'] === false,
    json_encode($cachedBars));
$sameDayBars = Lehuo::bars($windowId, 130.0, '2024-03-28');
$ok('同日人工標價仍覆蓋快照日線並標成未含股利',
    count($sameDayBars) === 4 && (float) $sameDayBars[3]['c'] === 130.0 && $sameDayBars[3]['raw'] === true,
    json_encode($sameDayBars));

Database::run("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES ('LH-WIDEN','LH-WIDEN','US','LH-WIDEN','USD','EQUITY')");
$widenId = (int) Database::pdo()->lastInsertId();
$widenInsert = Database::pdo()->prepare("INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source) VALUES (?,?,?,?,?,'yahoo')");
$firstWiden = new DateTimeImmutable('2010-01-04');
for ($i = 0; $i < 260; $i++) {
    $widenInsert->execute([$widenId, $firstWiden->modify('+' . ($i * 20) . ' days')->format('Y-m-d'), 100.0 + $i * 0.05, 100.0 + $i * 0.05, 'USD']);
}
$widenInsert->execute([$widenId, '2024-03-28', 120.0, 120.0, 'USD']);
$widenedBars = Lehuo::bars($widenId);
$widenedSpectrum = Lehuo::spectrum($widenedBars);
$ok('42 個月不足 250 根時實際補查最早 stored 日線', count($widenedBars) === 261
    && $widenedBars[0]['d'] === '2010-01-04' && $widenedSpectrum['bars'] === 261,
    'bars=' . count($widenedBars) . ' from=' . $widenedBars[0]['d']);
$ok('較早歷史回退如實報告拓寬窗口', str_contains(implode(' ', $widenedSpectrum['warn']), '已補入較早歷史'),
    implode(' ', $widenedSpectrum['warn']));

Database::run("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES ('LH-CAP','LH-CAP','US','LH-CAP','USD','EQUITY')");
$capId = (int) Database::pdo()->lastInsertId();
$capInsert = Database::pdo()->prepare("INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source) VALUES (?,?,?,?,?,'yahoo')");
$firstCap = new DateTimeImmutable('2006-01-01');
Database::pdo()->beginTransaction();
for ($i = 0; $i < 5001; $i++) {
    $capInsert->execute([$capId, $firstCap->modify('+' . $i . ' days')->format('Y-m-d'), 100.0, 100.0, 'USD']);
}
$capInsert->execute([$capId, '2024-03-28', 120.0, 120.0, 'USD']);
Database::pdo()->commit();
$cappedBars = Lehuo::bars($capId);
$ok('較早歷史回退限制每檔最多 5000 根，不把整張表載入', count($cappedBars) === 5001
    && $cappedBars[0]['d'] === '2006-01-01' && end($cappedBars)['d'] === '2024-03-28',
    'bars=' . count($cappedBars));

Database::run("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES ('LH-MANUAL','LH-MANUAL','US','LH-MANUAL','USD','EQUITY')");
$manualOnlyId = (int) Database::pdo()->lastInsertId();
$manualOnly = Lehuo::bars($manualOnlyId, 50.0, '2024-03-31');
$ok('完全沒有快照日線時仍保留有效人工標價',
    $manualOnly === [['d' => '2024-03-31', 'c' => 50.0, 'raw' => true]], json_encode($manualOnly));

$huge = $bars(300, static fn (int $i): float => 1.0e308);
$hs = Lehuo::spectrum($huge);
$hc = Lehuo::channel($huge);
$ok('極大但有限的價格不會讓迴歸平方和溢出', $hs['state'] === 'ok'
    && is_finite((float) $hs['tl']) && (float) $hs['tl'] === 1.0e308 && $hs['r2'] === null,
    'state=' . $hs['state'] . ' tl=' . var_export($hs['tl'], true));
$ok('極大但有限的價格不會讓布林變異數溢出', $hc['state'] === 'ok'
    && is_finite((float) $hc['mid']) && (float) $hc['mid'] === 1.0e308
    && (float) $hc['sd'] === 0.0 && is_finite((float) $hc['upper']),
    'state=' . $hc['state'] . ' mid=' . var_export($hc['mid'], true) . ' sd=' . var_export($hc['sd'], true));

final class StrategyRowsCountingPdo extends PDO
{
    public int $instrumentQueries = 0;
    public int $newestQueries = 0;
    public int $historyQueries = 0;
    public int $fallbackQueries = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM instruments ORDER BY market, symbol')) {
            $this->instrumentQueries++;
        }
        if (str_contains($query, 'MAX(as_of) AS as_of FROM prices')) {
            $this->newestQueries++;
        }
        if (str_starts_with($query, 'WITH cutoff(instrument_id, cutoff)')) {
            $this->historyQueries++;
        }
        if (str_starts_with($query, 'WITH needed(instrument_id, cutoff)')) {
            $this->fallbackQueries++;
        }

        return parent::prepare($query, $options);
    }
}

$pdoProperty = new ReflectionProperty(Database::class, 'pdo');
$originalPdo = $pdoProperty->getValue();
$countingPdo = new StrategyRowsCountingPdo('sqlite:' . $dir . '/portfolio.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdoProperty->setValue(null, $countingPdo);
$rows = Lehuo::rows();
$pdoProperty->setValue(null, $originalPdo);
$ok('策略總表以批次查詢載入，只對 42 個月不足的標的追加一次早期歷史查詢',
    $countingPdo->instrumentQueries === 1 && $countingPdo->newestQueries === 1
    && $countingPdo->historyQueries === 1 && $countingPdo->fallbackQueries === 1 && count($rows) === 4,
    'instruments=' . $countingPdo->instrumentQueries . ' newest=' . $countingPdo->newestQueries
    . ' history=' . $countingPdo->historyQueries . ' fallback=' . $countingPdo->fallbackQueries . ' rows=' . count($rows));

echo $fail === 0 ? "ALL PASS\n" : "{$fail} FAILED\n";
exit($fail === 0 ? 0 : 1);
