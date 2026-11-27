<?php
declare(strict_types=1);

namespace App;

use App\Database;

/**
 * 樂活五線譜／樂活通道：對每個投資物回溯一條回歸趨勢線，算出它自己的波動幅度，再告訴使用者現在落在哪一區。
 *
 * 五線譜是薛兆亨《五線譜投資術》一路下來的算法輪廓（3.5 年回歸線 ＋ ±1/±2 倍標準差平行線），
 * 不是他書中那套儀表板燈號的重現——那需要季報數據，這裡沒有。
 * 通道則照公開可查、也最多人對帳的那個定義：20 週布林通道（週線 SMA20 ± 2 倍標準差）。查過一輪，
 * 「樂活通道」沒有任何權威來源寫着自己的參數，搜尋得到的「三條線／±2 倍標準差」講的都是布林（保利加）
 * 通道；前一版拿全期對數回歸通道頂替，窗口拉到十年，上下軌寬到十檔投資物永遠顯示「通道內」——那一欄
 * 就不可能是訊號。所以現在用 20 週，名字也講清楚是 20 週布林。
 *
 * 只讀 `prices` ＋ `instruments.manual_price`，不寫任何表格；一台機器上跑完全部投資物也不需要快取。
 *
 * 價格口徑一律改用含股利的 `prices.adj_close`：五條線要貼的是「股利有續投入」的那條路徑，而策略量的
 * 是現價相對那條線的距離，線與現價就必須同一個口徑。未調整的 `close` 把每次除息讀成一次跌價，線被一連串階梯
 * 扭掉（斜率壓平、殘差被階梯撐大或縮小），配息越頻繁那一檔扭得越多——這台的資料庫裡 `SGOV` 的 σ 位置從＋0.67
 * 變成－1.68，判讀整句換掉。某一根沒有 `adj_close` 或它 ≤ 0 時退回 `close`，並在結果裡回報「有幾根未含股利」；
 * 人工標的那一棒沒有含息口徑可用，一律算未含股利。
 */
final class Strategy
{
    /** 五線譜的回歸窗口。3.5 年 ≈ 875 個交易日，是這個策略預設的「景氣週期」。 */
    private const WINDOW_MONTHS = 42;

    /** 低於這個根數，回歸線只是連兩點的直線，沒有統計意義。 */
    private const MIN_BARS = 250;

    /** 回退到較早歷史時，只補最早的一批日線；避免稀疏標的把整張 prices 表載入記憶體。 */
    private const MAX_EARLIER_HISTORY_BARS = 5000;

    /** 通道（布林）的經典參數：20 期均線加減 2 倍標準差。這裡的「期」是週。 */
    private const BB_WEEKS = 20;
    private const BB_MULT = 2.0;

    /** R² 的兩道門檻：低於 0.5 這檔不適用，0.5～0.7 只能謹慎參考。 */
    private const FIT_STRONG = 0.7;
    private const FIT_WEAK = 0.5;

    private const EPSILON = 1.0e-12;

    /**
     * 每個投資物一列，含五線譜與通道兩種判讀。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array
    {
        return Database::read(static fn (): array => self::calculateRows());
    }

    private static function calculateRows(): array
    {
        $out = [];
        $instruments = Database::all(
            'SELECT id, symbol, name, market, currency, manual_price, manual_price_at
             FROM instruments ORDER BY market, symbol'
        );
        if ($instruments === []) {
            return [];
        }
        $ids = array_map(static fn (array $inst): int => (int) $inst['id'], $instruments);
        $holders = implode(',', array_fill(0, count($ids), '?'));
        $newest = [];
        foreach (Database::all(
            "SELECT instrument_id, MAX(as_of) AS as_of FROM prices
             WHERE instrument_id IN ($holders) GROUP BY instrument_id",
            $ids
        ) as $row) {
            $newest[(int) $row['instrument_id']] = $row['as_of'];
        }

        $cutoffs = [];
        $params = [];
        foreach ($instruments as $inst) {
            $iid = (int) $inst['id'];
            $on = substr((string) ($inst['manual_price_at'] ?? ''), 0, 10);
            $latest = $newest[$iid] ?? null;
            $useManual = $inst['manual_price'] !== null
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) === 1
                && ($latest === null || strcmp($on, (string) $latest) >= 0);
            $anchor = $useManual && ($latest === null || strcmp($on, (string) $latest) > 0) ? $on : $latest;
            if ($anchor !== null) {
                $cutoffs[$iid] = self::monthsBefore((string) $anchor, self::WINDOW_MONTHS);
                $params[] = $iid;
                $params[] = $cutoffs[$iid];
            }
        }

        $history = [];
        if ($cutoffs !== []) {
            $values = implode(',', array_fill(0, count($cutoffs), '(?, ?)'));
            foreach (Database::all(
                "WITH cutoff(instrument_id, cutoff) AS (VALUES $values)
                 SELECT p.instrument_id, p.as_of, p.close, p.adj_close
                 FROM prices p JOIN cutoff c ON c.instrument_id = p.instrument_id
                 WHERE p.as_of >= c.cutoff ORDER BY p.instrument_id, p.as_of",
                $params
            ) as $row) {
                $history[(int) $row['instrument_id']][] = $row;
            }
        }

        // A sparse instrument can have fewer than MIN_BARS inside 42 months even though its database
        // history is long. Fetch the earliest stored bars for those instruments only, in one extra batch.
        foreach (self::widenHistory($cutoffs, $history) as $iid => $earlier) {
            $history[$iid] = array_merge($history[$iid] ?? [], $earlier);
        }

        foreach ($instruments as $inst) {
            $iid = (int) $inst['id'];
            $bars = self::barsFromHistory(
                $history[$iid] ?? [],
                $inst['manual_price'],
                $inst['manual_price_at'] ?? null,
                $newest[$iid] ?? null
            );
            $out[] = [
                'id'       => (int) $inst['id'],
                'symbol'   => (string) $inst['symbol'],
                'name'     => (string) $inst['name'],
                'market'   => (string) $inst['market'],
                'currency' => strtoupper(trim((string) $inst['currency'])),
                'spectrum' => self::spectrum($bars),
                'channel'  => self::channel($bars),
            ];
        }

        return $out;
    }

    /**
     * 日線序列，和 Performance 同一個規則：手動價格只有在不早於最後一根快取日線時才算一棒，
     * 否則它會在序列裡打出一個一天的洞。
     *
     * 數值取 `adj_close`（含股利），缺了才退回 `close` 並把那根標成 `raw`。五線譜與通道都吃這個輸出，
     * 所以換口徑只改這裡一個地方，兩張表會一起換。
     *
     * @param mixed $manual instruments.manual_price，原樣傳進來就好
     * @return array<int, array{d:string, c:float, raw:bool}>
     */
    public static function bars(int $instrumentId, mixed $manual = null, mixed $manualAt = null): array
    {
        return Database::read(static function () use ($instrumentId, $manual, $manualAt): array {
            $newest = Database::scalar('SELECT MAX(as_of) FROM prices WHERE instrument_id = ?', [$instrumentId]);
            $newest = is_string($newest) && $newest !== '' ? $newest : null;
            $on = substr((string) $manualAt, 0, 10);
            $useManual = $manual !== null
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) === 1
                && ($newest === null || strcmp($on, $newest) >= 0);
            $anchor = $useManual && ($newest === null || strcmp($on, $newest) > 0) ? $on : $newest;
            $rows = $anchor === null ? [] : Database::all(
                'SELECT as_of, close, adj_close FROM prices WHERE instrument_id = ? AND as_of >= ? ORDER BY as_of',
                [$instrumentId, self::monthsBefore($anchor, self::WINDOW_MONTHS)]
            );

            if ($anchor !== null && count($rows) < self::MIN_BARS) {
                $earlier = self::widenHistory(
                    [$instrumentId => self::monthsBefore($anchor, self::WINDOW_MONTHS)],
                    [$instrumentId => $rows]
                );
                $rows = array_merge($rows, $earlier[$instrumentId] ?? []);
            }

            return self::barsFromHistory($rows, $manual, $manualAt, $newest);
        });
    }

    /**
     * Supply earlier bars only when the normal 42-month query cannot reach MIN_BARS. The earliest
     * MAX_EARLIER_HISTORY_BARS extend the regression window while keeping memory bounded.
     *
     * @param array<int,string> $cutoffs instrument id => 42-month cutoff
     * @param array<int,array<int,array<string,mixed>>> $recent recent rows already loaded per instrument
     * @return array<int,array<int,array<string,mixed>>>
     */
    private static function widenHistory(array $cutoffs, array $recent): array
    {
        $params = [];
        $values = [];
        foreach ($cutoffs as $iid => $cutoff) {
            if (count($recent[$iid] ?? []) >= self::MIN_BARS) {
                continue;
            }
            $values[] = '(?, ?)';
            $params[] = $iid;
            $params[] = $cutoff;
        }
        if ($values === []) {
            return [];
        }

        $holders = implode(',', $values);
        $rows = Database::all(
            "WITH needed(instrument_id, cutoff) AS (VALUES $holders),
             earlier AS (
                 SELECT p.instrument_id, p.as_of, p.close, p.adj_close,
                        ROW_NUMBER() OVER (PARTITION BY p.instrument_id ORDER BY p.as_of) AS rn
                 FROM prices p JOIN needed n ON n.instrument_id = p.instrument_id
                 WHERE p.as_of < n.cutoff
             )
             SELECT instrument_id, as_of, close, adj_close FROM earlier
             WHERE rn <= " . self::MAX_EARLIER_HISTORY_BARS . ' ORDER BY instrument_id, as_of',
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['instrument_id']][] = $row;
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function barsFromHistory(array $rows, mixed $manual, mixed $manualAt, ?string $newest): array
    {
        $on = substr((string) $manualAt, 0, 10);
        $useManual = $manual !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) === 1
            && ($newest === null || strcmp($on, $newest) >= 0);

        $map = [];
        foreach ($rows as $row) {
            $adj = self::priceValue($row['adj_close']);
            $raw = $adj <= self::EPSILON;
            $map[(string) $row['as_of']] = [
                'c'   => $raw ? self::priceValue($row['close']) : $adj,
                'raw' => $raw,
            ];
        }

        if ($useManual) {
            // 人工標價沒有含股利的口徑可用，這一棒天生未調整；不假裝它和別的棒可比。
            // 非數字與零保留成 0，交給 spectrum/channel 明確標成資料異常，不能拿去除出極端 z 值。
            $map[$on] = ['c' => self::priceValue($manual), 'raw' => true];
        }

        ksort($map);
        $bars = [];
        foreach ($map as $date => $bar) {
            $bars[] = ['d' => (string) $date, 'c' => (float) $bar['c'], 'raw' => (bool) $bar['raw']];
        }

        return $bars;
    }

    /** 這一串取樣裡有幾根只能用未調整的價格。合成序列沒有 `raw` 這欄時，當它全是含股利口徑。 */
    public static function rawBars(array $bars): int
    {
        $n = 0;
        foreach ($bars as $bar) {
            if (!empty($bar['raw'])) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * 五線譜：窗口內對「含股利的收盤價 vs 交易日」做最小平方法，殘差標準差就是各線之間的間距。
     *
     * @param array<int, array{d:string, c:float, raw?:bool}> $bars
     * @return array<string, mixed>
     */
    public static function spectrum(array $bars): array
    {
        $blank = [
            'state'  => 'empty',
            'note'   => '還沒有這檔的日線。',
            'bars'   => 0,
            'raw'    => 0,
            'from'   => null,
            'to'     => null,
            'months' => null,
            'close'  => null,
            'tl'     => null,
            'u1'     => null, 'u2' => null, 'd1' => null, 'd2' => null,
            'sde'    => null,
            'cv'     => null,
            'r2'     => null,
            'slope'  => null,
            'z'      => null,
            'gap'    => null,
            'zone'   => null,
            'rule'   => null,
            'fit'    => null,
            'color'  => 'none',
            'warn'   => [],
        ];

        if ($bars === []) {
            return $blank;
        }

        $anchor = (string) $bars[count($bars) - 1]['d'];
        $cutoff = self::monthsBefore($anchor, self::WINDOW_MONTHS);
        $win = $bars;
        $widened = false;
        if (count($bars) > self::MIN_BARS) {
            $cut = array_values(array_filter($bars, static fn (array $b): bool => strcmp((string) $b['d'], $cutoff) >= 0));
            if (count($cut) >= self::MIN_BARS) {
                $win = $cut;
            } else {
                $widened = true;
            }
        }

        // 「有幾根未含股利」只算窗口裡真的用到的那幾根；窗口外再舊的歷史不進入這格的判斷。
        $raw = self::rawBars($win);

        $n = count($win);
        $bad = 0;
        foreach ($win as $bar) {
            if (!is_numeric($bar['c'] ?? null) || !is_finite((float) $bar['c']) || (float) $bar['c'] <= self::EPSILON) {
                $bad++;
            }
        }
        if ($bad > 0) {
            $blank['state'] = 'data';
            $blank['bars'] = $n;
            $blank['raw'] = $raw;
            $blank['from'] = (string) $win[0]['d'];
            $blank['to'] = (string) $win[$n - 1]['d'];
            $blank['months'] = self::monthsBetween((string) $win[0]['d'], (string) $win[$n - 1]['d']);
            $blank['note'] = "窗口裡有 {$bad} 根的收盤不是正數或不是有效數字，價格資料有異常，五線譜略過不算。";

            return $blank;
        }

        if ($n < self::MIN_BARS) {
            $blank['state'] = 'short';
            $blank['bars'] = $n;
            $blank['raw'] = $raw;
            $blank['from'] = (string) $win[0]['d'];
            $blank['to'] = (string) $win[$n - 1]['d'];
            $blank['months'] = self::monthsBetween((string) $win[0]['d'], (string) $win[$n - 1]['d']);
            $blank['note'] = "日線只有 {$n} 根（" . $blank['from'] . '～' . $blank['to'] . '），不足 '
                . self::MIN_BARS . " 根，回歸通道沒有統計意義。等歷史累積夠了再看這一欄。";

            return $blank;
        }

        $fit = self::regress($win);
        if ($fit === null) {
            $blank['state'] = 'short';
            $blank['bars'] = $n;
            $blank['raw'] = $raw;
            $blank['note'] = '窗口內的日期無法解析，算不出回歸線。';

            return $blank;
        }

        $close = (float) $win[$n - 1]['c'];
        $tl = $fit['a'] + $fit['b'] * $fit['x_last'];
        $sde = $fit['sde'];
        $mean = $fit['mean'];
        $cv = $mean > self::EPSILON ? $sde / $mean * 100.0 : null;
        $midX = ($fit['x_first'] + $fit['x_last']) / 2.0;
        $midLevel = $fit['a'] + $fit['b'] * $midX;
        $slope = $midLevel > self::EPSILON ? $fit['b'] * 365.0 / $midLevel * 100.0 : null;
        $flat = $sde <= 1.0e-9;

        $row = [
            'state'  => 'ok',
            'bars'   => $n,
            'raw'    => $raw,
            'from'   => (string) $win[0]['d'],
            'to'     => (string) $win[$n - 1]['d'],
            'months' => self::monthsBetween((string) $win[0]['d'], (string) $win[$n - 1]['d']),
            'close'  => $close,
            'tl'     => $tl,
            'u1'     => $tl + $sde,
            'u2'     => $tl + 2.0 * $sde,
            'd1'     => $tl - $sde,
            'd2'     => $tl - 2.0 * $sde,
            'sde'    => $sde,
            'cv'     => $cv,
            'r2'     => $fit['r2'],
            'slope'  => $slope,
            'z'      => $flat ? 0.0 : ($close - $tl) / $sde,
            'gap'    => $tl > self::EPSILON ? ($close / $tl - 1.0) * 100.0 : null,
            'zone'   => null,
            'rule'   => null,
            'fit'    => null,
            'color'  => 'none',
            'warn'   => [],
            'note'   => $flat ? '窗口幾乎是一條直線，五條線重疊在一起，間距失去意義。' : '',
        ];
        foreach (['close', 'tl', 'u1', 'u2', 'd1', 'd2', 'sde', 'cv', 'r2', 'slope', 'z', 'gap'] as $field) {
            if ($row[$field] !== null && !is_finite((float) $row[$field])) {
                $blank['state'] = 'data';
                $blank['bars'] = $n;
                $blank['raw'] = $raw;
                $blank['from'] = (string) $win[0]['d'];
                $blank['to'] = (string) $win[$n - 1]['d'];
                $blank['note'] = '回歸結果超出可計算範圍，五線譜略過不算。';

                return $blank;
            }
        }

        $z = (float) $row['z'];
        if ($z >= 2.0) {
            $row['zone'] = '極度貪婪';
            $row['rule'] = '獲利了結，不追高';
            $row['color'] = 'sell';
        } elseif ($z >= 1.0) {
            $row['zone'] = '貪婪';
            $row['rule'] = '分批減碼';
            $row['color'] = 'sell';
        } elseif ($z > -1.0) {
            $row['zone'] = '趨勢區';
            $row['rule'] = '持有，或照常定期定額';
            $row['color'] = 'hold';
        } elseif ($z > -2.0) {
            $row['zone'] = '恐懼';
            $row['rule'] = '分批布局';
            $row['color'] = 'buy';
        } else {
            $row['zone'] = '極度恐懼';
            $row['rule'] = '重倉布局，前提是基本面沒有惡化';
            $row['color'] = 'buy';
        }

        if ($row['r2'] === null) {
            $row['fit'] = 'none';
            $row['warn'][] = '整個窗口價格毫無變動，貼合度與區間都無從判斷。';
        } elseif ($row['r2'] >= self::FIT_STRONG) {
            $row['fit'] = 'trend';
        } elseif ($row['r2'] >= self::FIT_WEAK) {
            $row['fit'] = 'caution';
            $row['warn'][] = sprintf('R² 只有 %s，通道可用但要謹慎，別只看這一個訊號。', fmt_num((float) $row['r2'], 2));
        } else {
            $row['fit'] = 'none';
            $row['warn'][] = sprintf('R² 只有 %s：這檔價走不像沿著一條線走，五線譜在它身上不適用。', fmt_num((float) $row['r2'], 2));
        }

        if ($raw > 0) {
            $row['warn'][] = sprintf('窗口裡有 %d 根沒有含股利的收盤可用，那幾根退回未調整價（人工標價那一棒也算在內）：'
                . '它和其他棒不同口徑，線與 σ 位置會被拉偏。', $raw);
        }

        if ($slope !== null && $slope < 0.0) {
            $row['warn'][] = '通道向下傾（年化 ' . fmt_pct($slope, 1) . '）：策略自己警告的類型，越跌越買在這檔勝率比較低。';
        }
        if ((float) $row['z'] <= -2.0) {
            $row['warn'][] = '已跌破－2SD：常態假設下這是 2.5% 的尾部，通常代表結構改變，先確認基本面再接手。';
        }
        if ((float) $row['z'] >= 2.0) {
            $row['warn'][] = '已衝上＋2SD：來源的作法是等它拉回測試通道，而不是在最高點加碼。';
        }
        if ($widened) {
            $row['warn'][] = sprintf('42 個月窗口不足 %d 根，已補入較早歷史；合計跨度 %s 個月、%d 根，回歸線涵蓋較長期間，對近期價格變化較不敏感。',
                self::MIN_BARS, fmt_num((float) $row['months'], 1), $n);
        }
        if ($row['months'] !== null && (float) $row['months'] < self::WINDOW_MONTHS - 2) {
            $row['warn'][] = sprintf('窗口只有 %s 個月，不到 3.5 年：線比較會跟著最近的價格擺。', fmt_num((float) $row['months'], 1));
        }

        return $row;
    }

    /**
     * 通道：20 週布林通道——日線先按 ISO 週收成週線，取最近 20 週的「週收盤」算簡單均線與母數標準差，
     * 上下軌為 ±2 倍標準差；進場時機的輔助也從同一份週線算，取 2 週與 19 週均線的相對位置。
     *
     * 窗口裡包含「現在還沒收盤」的那一週（和掃圖軟體的算法一致，才對得上外面看到的圖），所以這一欄會隨
     * 著今天的報價動；那根一併回報成 `partial`，畫面上要講出來。
     *
     * @param array<int, array{d:string, c:float, raw?:bool}> $bars
     * @return array<string, mixed>
     */
    public static function channel(array $bars): array
    {
        $blank = [
            'state'   => 'empty',
            'note'    => '還沒有這檔的日線。',
            'bars'    => 0,
            'weeks'   => 0,
            'raw'     => 0,
            'from'    => null,
            'to'      => null,
            'week'    => null,
            'partial' => false,
            'close'   => null,
            'upper'   => null,
            'mid'     => null,
            'lower'   => null,
            'sd'      => null,
            'where'   => null,
            'ma2'     => null,
            'ma19'    => null,
            'bias'    => null,
            'warn'    => [],
        ];

        $n = count($bars);
        if ($n === 0) {
            return $blank;
        }

        $weekly = self::weekly($bars);
        $m = count($weekly);
        $win = array_slice($weekly, -self::BB_WEEKS);
        // 通道只看這 20 週，所以未含股利的根數也只算這 20 週用到幾根。
        $raw = self::rawBars($win);

        $blank['bars'] = $n;
        $blank['weeks'] = $m;
        $blank['raw'] = $raw;
        $blank['from'] = (string) $bars[0]['d'];
        $blank['to'] = (string) $bars[$n - 1]['d'];
        $blank['week'] = (string) $weekly[$m - 1]['d'];

        if ($m < self::BB_WEEKS) {
            $blank['state'] = 'short';
            $blank['note'] = sprintf('週線只有 %d 根，算 %d 週通道要 %d 根（日線 %d 根）。',
                $m, self::BB_WEEKS, self::BB_WEEKS, $n);

            return $blank;
        }

        $values = array_column($win, 'c');
        $count = count($values);

        // 不到 2 個值，母數標準差恆為 0，通道失去意義。
        if ($count < 2) {
            $blank['state'] = 'insufficient';
            $blank['note'] = sprintf('通道窗口裡只有 %d 個值，不足以計算標準差。', $count);

            return $blank;
        }

        $bad = 0;
        foreach ($values as $value) {
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= self::EPSILON) {
                $bad++;
            }
        }
        if ($bad > 0) {
            $blank['state'] = 'data';
            $blank['note'] = sprintf('這 %d 週裡有 %d 根的週收盤不是正數或不是有效數字，價格資料有異常，樂活通道略過不算。',
                self::BB_WEEKS, $bad);

            return $blank;
        }

        $scale = (float) max($values);
        $normalized = array_map(static fn (float $value): float => $value / $scale, $values);
        $midScaled = array_sum($normalized) / $count;
        $var = 0.0;
        foreach ($normalized as $value) {
            $var += ($value - $midScaled) ** 2;
        }
        // 布林原本的標準差是母數（除以 n），不是 n−1；跟著對，數字才和掃圖軟體一致。
        $mid = $midScaled * $scale;
        $sd = sqrt($var / $count) * $scale;

        $close = (float) $weekly[$m - 1]['c'];
        $upper = $mid + self::BB_MULT * $sd;
        $lower = $mid - self::BB_MULT * $sd;

        $lastWeek = (string) $weekly[$m - 1]['d'];
        $stamp = strtotime($lastWeek);
        $partial = $stamp !== false && date('oW', $stamp) === date('oW');

        // 進場時機的輔助也走週線：尺度要和通道一致，否則 2 日／19 日的交叉幾天就翻一次向，
        // 等於把「週收盤出下軌」這件事再唸一次，永遠講不出「還沒轉向」。
        $ma2 = self::ma($weekly, 2);
        $ma19 = self::ma($weekly, 19);
        foreach (['mid', 'sd', 'upper', 'lower', 'ma2', 'ma19'] as $field) {
            if (${$field} !== null && !is_finite((float) ${$field})) {
                $blank['state'] = 'data';
                $blank['note'] = '通道結果超出可計算範圍，樂活通道略過不算。';

                return $blank;
            }
        }

        $row = $blank;
        $row['state'] = 'ok';
        $row['note'] = '';
        $row['bars'] = $n;
        $row['raw'] = $raw;
        $row['from'] = (string) $bars[0]['d'];
        $row['to'] = (string) $bars[$n - 1]['d'];
        $row['week'] = $lastWeek;
        $row['partial'] = $partial;
        $row['close'] = $close;
        $row['mid'] = $mid;
        $row['upper'] = $upper;
        $row['lower'] = $lower;
        $row['sd'] = $sd;
        $row['where'] = $close > $upper ? 'upper' : ($close < $lower ? 'lower' : 'inside');
        $row['ma2'] = $ma2;
        $row['ma19'] = $ma19;
        $row['bias'] = $ma2 === null || $ma19 === null ? null : ($ma2 >= $ma19 ? 'up' : 'down');

        if ($raw > 0) {
            $row['warn'][] = sprintf('這 %d 週裡有 %d 根沒有含股利的收盤可用，那幾根退回未調整價（人工標價那一棒也算在內）：'
                . '除息那個缺口會被當成跌價，標準差與上下軌都會被它撐寬。', self::BB_WEEKS, $raw);
        }

        if ($row['where'] === 'upper') {
            $row['warn'][] = '週收盤在 20 週通道上軌之外：布林的讀法是強勢但短期過熱，五線譜的讀法是等拉回測試再說。';
        } elseif ($row['where'] === 'lower') {
            $row['warn'][] = '週收盤跌到 20 週通道下軌之外：這是策略要找的低位，但仍要基本面同意。';
        }

        // 通道位置說「出界了」、均線卻說「還沒轉向」時，兩個訊號互相矛盾，不替使用者挑一個。
        $off_rail = $row['where'] !== 'inside';
        $maAgrees = ($row['where'] === 'lower' && $row['bias'] === 'down')
            || ($row['where'] === 'upper' && $row['bias'] === 'up');
        if ($off_rail && $row['bias'] !== null && !$maAgrees) {
            $row['warn'][] = '通道位置與 2 週／19 週均線不同調：通道說出界了、週均線卻還沒轉向，兩個訊號方向相反，這一個先別當訊號用。';
        }

        return $row;
    }

    /**
     * 單一投資物的圖表資料：五線譜的每日迴歸線＋通道帶，以及 20 週布林的每週帶。
     * 回傳 null 表示找不到這檔投資物。
     *
     * @return ?array{symbol:string, name:string, currency:string, spectrum:array, channel:array, spectrumSeries:?array, channelSeries:?array}
     */
    public static function chartData(int $instrumentId): ?array
    {
        $inst = Database::one(
            'SELECT id, symbol, name, currency, manual_price, manual_price_at FROM instruments WHERE id = ?',
            [$instrumentId]
        );
        if ($inst === null) {
            return null;
        }

        $bars = self::bars((int) $inst['id'], $inst['manual_price'], $inst['manual_price_at'] ?? null);
        $spectrumSummary = self::spectrum($bars);
        $channelSummary = self::channel($bars);

        $result = [
            'symbol'   => (string) $inst['symbol'],
            'name'     => (string) $inst['name'],
            'currency' => strtoupper(trim((string) $inst['currency'])),
            'spectrum' => $spectrumSummary,
            'channel'  => $channelSummary,
            'spectrumSeries' => null,
            'channelSeries'  => null,
        ];

        if ($spectrumSummary['state'] === 'ok') {
            $result['spectrumSeries'] = self::spectrumSeries($bars);
        }

        if ($channelSummary['state'] === 'ok') {
            $result['channelSeries'] = self::channelSeries($bars);
        }

        return $result;
    }

    /**
     * 五線譜圖表的每日序列：窗口內每一根的趨勢線、±1SD、±2SD。
     *
     * @return array{dates:array<int,string>, prices:array<int,float>, trend:array<int,float>, u1:array<int,float>, u2:array<int,float>, d1:array<int,float>, d2:array<int,float>}
     */
    private static function spectrumSeries(array $bars): array
    {
        $anchor = (string) $bars[count($bars) - 1]['d'];
        $cutoff = self::monthsBefore($anchor, self::WINDOW_MONTHS);
        $win = $bars;
        if (count($bars) > self::MIN_BARS) {
            $cut = array_values(array_filter($bars, static fn (array $b): bool => strcmp((string) $b['d'], $cutoff) >= 0));
            if (count($cut) >= self::MIN_BARS) {
                $win = $cut;
            }
        }

        $fit = self::regress($win);
        if ($fit === null) {
            return ['dates' => [], 'prices' => [], 'trend' => [], 'u1' => [], 'u2' => [], 'd1' => [], 'd2' => []];
        }

        $base = date_create((string) $win[0]['d']);
        $dates = $prices = $trend = $u1 = $u2 = $d1 = $d2 = [];

        foreach ($win as $bar) {
            $day = date_create((string) $bar['d']);
            $x = $base !== false && $day !== false ? (float) $base->diff($day)->days : 0.0;
            $tl = $fit['a'] + $fit['b'] * $x;
            $sde = $fit['sde'];

            $dates[] = (string) $bar['d'];
            $prices[] = (float) $bar['c'];
            $trend[] = $tl;
            $u1[] = $tl + $sde;
            $u2[] = $tl + 2.0 * $sde;
            $d1[] = $tl - $sde;
            $d2[] = $tl - 2.0 * $sde;
        }

        return compact('dates', 'prices', 'trend', 'u1', 'u2', 'd1', 'd2');
    }

    /**
     * 通道圖表的每週序列：所有週收盤，以及從第 20 週開始的滾動布林帶。
     *
     * @return array{weeks:array<int,string>, closes:array<int,float>, mid:array<int,float|null>, upper:array<int,float|null>, lower:array<int,float|null>}
     */
    private static function channelSeries(array $bars): array
    {
        $weekly = self::weekly($bars);
        $m = count($weekly);

        $weeks = $closes = $mid = $upper = $lower = [];
        foreach ($weekly as $w) {
            $weeks[] = (string) $w['d'];
            $closes[] = (float) $w['c'];
        }

        for ($i = 0; $i < $m; $i++) {
            if ($i < self::BB_WEEKS - 1) {
                $mid[] = null;
                $upper[] = null;
                $lower[] = null;
                continue;
            }
            $values = [];
            for ($j = $i - self::BB_WEEKS + 1; $j <= $i; $j++) {
                $values[] = (float) $weekly[$j]['c'];
            }
            $count = count($values);
            $avg = array_sum($values) / $count;
            $var = 0.0;
            foreach ($values as $v) {
                $var += ($v - $avg) ** 2;
            }
            $sd = sqrt($var / $count);
            $mid[] = $avg;
            $upper[] = $avg + self::BB_MULT * $sd;
            $lower[] = $avg - self::BB_MULT * $sd;
        }

        return compact('weeks', 'closes', 'mid', 'upper', 'lower');
    }

    /** 日線按 ISO 週收成週線：同一週後來的一定較新（序列已排序），直接蓋掉就得到該週最後一個有報價的日子。 */
    public static function weekly(array $bars): array
    {
        $byWeek = [];
        foreach ($bars as $bar) {
            $stamp = strtotime((string) $bar['d']);
            if ($stamp === false) {
                continue;
            }
            $byWeek[date('oW', $stamp)] = [
                'd' => (string) $bar['d'],
                'c' => (float) $bar['c'],
                'raw' => !empty($bar['raw']),
            ];
        }

        return array_values($byWeek);
    }

    /** 無效價格變成 0，只供上層判定資料異常；任何平均數、迴歸或 z 值都不會把它當有效報價。 */
    private static function priceValue(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        $price = (float) $value;

        return is_finite($price) ? $price : 0.0;
    }

    /**
     * 最小平方法：x 用「距離第一根 K 棒的日數」，所以間距不等（停牌、假期）也不會把斜率拉歪。
     *
     * @param array<int, array{d:string, c:float}> $bars 依日期遞增
     * @return ?array{a:float, b:float, sde:float, r2:?float, mean:float, n:int, x_first:float, x_last:float}
     */
    private static function regress(array $bars): ?array
    {
        $n = count($bars);
        if ($n < 3) {
            return null;
        }

        $base = date_create((string) $bars[0]['d']);
        if ($base === false) {
            return null;
        }

        $scale = 0.0;
        foreach ($bars as $bar) {
            $scale = max($scale, (float) $bar['c']);
        }
        if (!is_finite($scale) || $scale <= 0.0) {
            return null;
        }

        $sx = $sy = $sxx = $sxy = 0.0;
        $xs = [];
        foreach ($bars as $i => $bar) {
            $day = date_create((string) $bar['d']);
            if ($day === false) {
                return null;
            }
            // 用日期差而不是時間戳差：後者在一趟跨夏令時的日子會多出（或少掉）一小時，
            // 把整條線的斜率拉歪一個看不見的小量。
            $x = (float) $base->diff($day)->days;
            $y = (float) $bar['c'] / $scale;
            $xs[$i] = $x;
            $sx += $x;
            $sy += $y;
            $sxx += $x * $x;
            $sxy += $x * $y;
        }

        $den = $n * $sxx - $sx * $sx;
        if (abs($den) <= self::EPSILON) {
            return null;
        }

        $bScaled = ($n * $sxy - $sx * $sy) / $den;
        $aScaled = ($sy - $bScaled * $sx) / $n;
        $meanScaled = $sy / $n;

        $sse = 0.0;
        $sst = 0.0;
        foreach ($bars as $i => $bar) {
            $y = (float) $bar['c'] / $scale;
            $res = $y - ($aScaled + $bScaled * $xs[$i]);
            $sse += $res * $res;
            $dev = $y - $meanScaled;
            $sst += $dev * $dev;
        }

        $fit = [
            'a'       => $aScaled * $scale,
            'b'       => $bScaled * $scale,
            'sde'     => $n > 2 ? sqrt($sse / ($n - 2)) * $scale : 0.0,
            'r2'      => $sst > self::EPSILON ? 1.0 - $sse / $sst : null,
            'mean'    => $meanScaled * $scale,
            'n'       => $n,
            'x_first' => $xs[0],
            'x_last'  => $xs[$n - 1],
        ];
        foreach (['a', 'b', 'sde', 'r2', 'mean'] as $field) {
            if ($fit[$field] !== null && !is_finite((float) $fit[$field])) {
                return null;
            }
        }

        return $fit;
    }

    /** @param array<int, array{d:string, c:float}> $bars */
    private static function ma(array $bars, int $len): ?float
    {
        $n = count($bars);
        if ($n < $len) {
            return null;
        }

        $scale = 0.0;
        for ($i = $n - $len; $i < $n; $i++) {
            $scale = max($scale, (float) $bars[$i]['c']);
        }
        if (!is_finite($scale) || $scale <= 0.0) {
            return null;
        }
        $sum = 0.0;
        for ($i = $n - $len; $i < $n; $i++) {
            $sum += (float) $bars[$i]['c'] / $scale;
        }
        $mean = $sum / $len * $scale;

        return is_finite($mean) ? $mean : null;
    }

    /** 某日期往前 $months 個月的同一天；目標月沒有該日時取該月最後一天。 */
    private static function monthsBefore(string $date, int $months): string
    {
        $day = date_create($date);
        if ($day === false) {
            return $date;
        }
        $originalDay = (int) $day->format('j');
        $target = $day->setDate((int) $day->format('Y'), (int) $day->format('n'), 1)
            ->modify('-' . abs($months) . ' months');
        if ($target === false) {
            return $date;
        }
        $last = (clone $target)->modify('+1 month')->modify('-1 day')->format('j');
        $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($originalDay, (int) $last));

        return $target->format('Y-m-d');
    }

    /** 兩個日期之間大約幾個月，只為顯示與「窗口不夠長」那條警告用。 */
    private static function monthsBetween(string $from, string $to): ?float
    {
        $a = date_create($from);
        $b = date_create($to);
        if ($a === false || $b === false) {
            return null;
        }

        $days = (int) $a->diff($b)->days;

        return round((strcmp($to, $from) < 0 ? -$days : $days) / 30.436875, 1);
    }
}
