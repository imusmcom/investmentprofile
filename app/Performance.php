<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Time-weighted performance for the 回報 page, built from the daily closes already in `prices`.
 *
 * These rules make the numbers defensible:
 *  - a trade only moves value between the holdings sleeve and cash, so the holdings column chain-links
 *    at every trade date instead of letting a purchase look like a gain;
 *  - 存入／提取 are principal changes and count directly in the cash and 持股＋現金 returns;
 *  - the cash sleeve treats trade principal as a transfer and keeps fees in its return;
 *  - one exchange rate per currency is used across the whole series, because `fx_rates` keeps a single
 *    row per currency and has no history. Multi-currency returns are therefore constant-rate returns.
 */
final class Performance
{
    private const EPSILON = 1.0e-9;

    /** Read wider than the longest window, so a ten-year backfill is never trimmed away here. */
    private const READ_MONTHS = 132;

    /** @var array<int, array<string, string|int>> the windows the 回報 page offers, in display order */
    private const PERIODS = [
        ['label' => '1 天', 'kind' => 'prev'],
        ['label' => '7 天', 'kind' => 'days', 'n' => 7],
        ['label' => '1 個月', 'kind' => 'months', 'n' => 1],
        ['label' => '3 個月', 'kind' => 'months', 'n' => 3],
        ['label' => '6 個月', 'kind' => 'months', 'n' => 6],
        ['label' => '9 個月', 'kind' => 'months', 'n' => 9],
        ['label' => '12 個月', 'kind' => 'months', 'n' => 12],
        ['label' => '本年至今', 'kind' => 'ytd'],
        ['label' => '24 個月', 'kind' => 'months', 'n' => 24],
        ['label' => '60 個月', 'kind' => 'months', 'n' => 60],
        ['label' => '120 個月', 'kind' => 'months', 'n' => 120],
    ];

    /**
     * @param array<int, int> $portfolioIds
     * @return array{periods: array<int,array<string,mixed>>, years: array<int,array<string,mixed>>, meta: array<string,mixed>}
     */
    public static function forPortfolios(array $ids): array
    {
        return Database::read(static fn (): array => self::calculate($ids));
    }

    /** @param array<int, int> $portfolioIds */
    private static function calculate(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
        $base = Settings::baseCurrency();

        $meta = [
            'base'          => $base,
            'start'         => null,
            'end'           => null,
            'price_end'     => null,
            'stale'         => false,
            'points'        => 0,
            'trades'        => 0,
            'end_holdings'  => null,
            'end_cash'      => null,
            'end_total'     => null,
            'missing_price' => [],
            'missing_fx'    => [],
            'note'          => '',
        ];

        if ($ids === []) {
            return ['periods' => [], 'years' => [], 'meta' => array_merge($meta, ['note' => '請先建立投資組合。'])];
        }

        $holders = implode(',', array_fill(0, count($ids), '?'));
        $numericFailure = static fn (string $message): array => [
            'periods' => [],
            'years'   => [],
            'meta'    => array_merge($meta, ['note' => $message]),
        ];

        $instruments = Database::all(
            "SELECT DISTINCT i.id, i.symbol, i.name, i.market, i.currency, i.manual_price, i.manual_price_at
             FROM instruments i
             JOIN transactions t ON t.instrument_id = i.id
             WHERE t.portfolio_id IN ($holders)
             ORDER BY i.market, i.symbol",
            $ids
        );
        if ($instruments === [] && Database::scalar(
            'SELECT COUNT(*) FROM cash_movements WHERE portfolio_id IN (' . $holders . ')',
            $ids,
            0
        ) == 0) {
            return ['periods' => [], 'years' => [], 'meta' => array_merge($meta, ['note' => '這個範圍還沒有交易或現金紀錄。'])];
        }

        $currencies = [$base => true];
        $symbolById = [];
        $barsByDate = [];
        $cutoff = Calendar::subtractMonths(date('Y-m-d'), self::READ_MONTHS);
        $instrumentIds = array_map(static fn (array $inst): int => (int) $inst['id'], $instruments);
        $newestByInstrument = [];
        if ($instrumentIds !== []) {
            $priceHolders = implode(',', array_fill(0, count($instrumentIds), '?'));
            foreach (Database::all(
                "SELECT instrument_id, as_of, close, currency FROM prices
                 WHERE instrument_id IN ($priceHolders) AND as_of >= ? ORDER BY instrument_id, as_of",
                [...$instrumentIds, $cutoff]
            ) as $row) {
                $iid = (int) $row['instrument_id'];
                $rawPrice = self::positivePrice($row['close']);
                $barCurrency = strtoupper(trim((string) $row['currency']));
                $barsByDate[(string) $row['as_of']][$iid] = [
                    'close' => $rawPrice,
                    'currency' => $barCurrency,
                ];
                $currencies[$barCurrency] = true;
                $newestByInstrument[$iid] = (string) $row['as_of'];
            }
        }

        foreach ($instruments as $inst) {
            $iid = (int) $inst['id'];
            $cur = strtoupper(trim((string) $inst['currency']));
            $symbolById[$iid] = (string) $inst['symbol'];
            $currencies[$cur] = true;

            $newest = $newestByInstrument[$iid] ?? null;

            // 手動價格與目前快取價共用 Positions 的新舊與有效性規則；人工價沒有含股利口徑，
            // 所以同時放進未調整與調整後序列，並標成未含股利。
            $latestQuote = $newest === null
                ? null
                : ['price' => $barsByDate[$newest][$iid]['close'] ?? null, 'as_of' => $newest];
            $manual = Positions::effectivePrice($inst, null);
            $effective = Positions::effectivePrice($inst, $latestQuote);
            if ($manual !== null && $effective !== null && $effective['as_of'] === $manual['as_of']
                && strcmp($effective['as_of'], $cutoff) >= 0) {
                $barsByDate[$effective['as_of']][$iid] = [
                    'close' => $effective['price'],
                    'currency' => $cur,
                ];
            }
        }

        $tradeEvents = [];
        foreach (Database::all(
            "SELECT portfolio_id, instrument_id, side, quantity, price, fee, traded_on,
                    COALESCE(NULLIF(t.currency, ''), i.currency) AS currency
             FROM transactions t
             JOIN instruments i ON i.id = t.instrument_id
             WHERE portfolio_id IN ($holders) ORDER BY traded_on, t.id",
            $ids
        ) as $t) {
            $currencies[strtoupper(trim((string) $t['currency']))] = true;
            $tradeEvents[(string) $t['traded_on']][] = $t;
        }

        // Every trade and every deposit belongs on the axis: the quantities and balances at the start of
        // a window come from what happened long before it, and Yahoo's range may not reach that far back.
        $cashEvents = [];
        foreach (Database::all(
            "SELECT currency, direction, amount, moved_on
             FROM cash_movements WHERE portfolio_id IN ($holders) ORDER BY moved_on, id",
            $ids
        ) as $c) {
            $cur = strtoupper(trim((string) $c['currency']));
            if ($cur === '') {
                continue;
            }
            $currencies[$cur] = true;
            $cashEvents[(string) $c['moved_on']][] = $c;
        }

        $rates = [];
        $missingFx = [];
        $fxCache = [];
        foreach (array_keys($currencies) as $cur) {
            $conv = convert(1.0, (string) $cur, $base, $fxCache);
            if (!$conv['converted'] || (float) $conv['value'] <= 0.0) {
                // A blank currency on an old row is as unconvertible as a rate nobody fetched.
                $missingFx[] = (string) $cur === '' ? '（未填幣別）' : (string) $cur;
                continue;
            }
            $rates[(string) $cur] = (float) $conv['value'];
        }
        // The base currency is always its own rate, even when fx_rates has no row for it.
        $rates[$base] = 1.0;
        $missingFx = array_values(array_filter($missingFx, static fn (string $c): bool => $c !== $base));

        if ($missingFx !== []) {
            return [
                'periods' => [],
                'years'   => [],
                'meta'    => array_merge($meta, ['missing_fx' => $missingFx, 'note' => '缺匯率，報酬無法換算成記帳幣別。']),
            ];
        }

        $priced = [];
        foreach ($barsByDate as $iidMap) {
            foreach ($iidMap as $iid => $bar) {
                if ($bar['close'] !== null) {
                    $priced[(int) $iid] = true;
                }
            }
        }
        $missingPrice = [];
        foreach ($symbolById as $iid => $symbol) {
            if (!isset($priced[$iid])) {
                $missingPrice[] = $symbol;
            }
        }

        $points = [];
        $axis = [];
        $qty = $cash = $lastRaw = [];
        $lastPriceDateByInstrument = [];
        $endpointPriceDate = null;
        $pendingTrade = 0.0;
        // Actual price dates remain the preferred baseline. Once pricing has begun, later event-only dates
        // carry the last known prices forward so deposits/trades are not silently omitted from the endpoint.
        $dates = array_keys($barsByDate + $tradeEvents + $cashEvents);
        sort($dates);

        $meta['trades'] = array_sum(array_map('count', $tradeEvents));

        foreach ($dates as $date) {
            $netTrade = 0.0;

            foreach ($cashEvents[$date] ?? [] as $c) {
                $cur = strtoupper(trim((string) $c['currency']));
                $delta = (float) $c['amount'] * ((string) $c['direction'] === 'IN' ? 1.0 : -1.0);
                $cash[$cur] = ($cash[$cur] ?? 0.0) + $delta;
                if (!is_finite($delta) || !is_finite($cash[$cur])) {
                    return $numericFailure('現金流量累計超出可計算範圍，報酬略過不算。');
                }
            }

            foreach ($barsByDate[$date] ?? [] as $iid => $bar) {
                if ($bar['close'] !== null) {
                    $lastRaw[(int) $iid] = $bar;
                    $lastPriceDateByInstrument[(int) $iid] = $date;
                }
            }

            foreach ($tradeEvents[$date] ?? [] as $t) {
                $pid = (int) $t['portfolio_id'];
                $iid = (int) $t['instrument_id'];
                $cur = strtoupper(trim((string) $t['currency']));
                $q = (float) $t['quantity'];
                $px = (float) $t['price'];
                $fee = (float) $t['fee'];
                $held = $qty[$pid][$iid] ?? 0.0;

                if ((string) $t['side'] === 'BUY') {
                    $qty[$pid][$iid] = $held + $q;
                    $cash[$cur] = ($cash[$cur] ?? 0.0) - ($q * $px + $fee);
                    $netTrade += $q * $px * $rates[$cur];
                } else {
                    // Never let one portfolio's sleeve go short or consume another portfolio's holding.
                    $closing = min($q, $held);
                    $qty[$pid][$iid] = $held - $closing;
                    $cash[$cur] = ($cash[$cur] ?? 0.0) + ($closing * $px - $fee);
                    $netTrade -= $closing * $px * $rates[$cur];
                }
                if (!is_finite($q) || !is_finite($px) || !is_finite($fee)
                    || !is_finite($qty[$pid][$iid]) || !is_finite($cash[$cur]) || !is_finite($netTrade)) {
                    return $numericFailure('交易數量、成交金額或結算現金累計超出可計算範圍，報酬略過不算。');
                }
            }

            $h = 0.0;
            $isPartial = false;
            $hasHoldings = false;
            $endpointPriceDate = null;
            foreach ($qty as $positions) {
                foreach ($positions as $iid => $q) {
                    if ($q <= self::EPSILON) {
                        continue;
                    }
                    $hasHoldings = true;
                    if (!isset($lastRaw[$iid])) {
                        $isPartial = true;
                    } else {
                        $bar = $lastRaw[$iid];
                        $h += $q * $bar['close'] * $rates[$bar['currency']];
                        $priceDate = $lastPriceDateByInstrument[$iid] ?? null;
                        if ($priceDate !== null
                            && ($endpointPriceDate === null || strcmp($priceDate, $endpointPriceDate) < 0)) {
                            $endpointPriceDate = $priceDate;
                        }
                    }
                }
            }

            if (!is_finite($h)) {
                return $numericFailure('持股市值累計超出可計算範圍，報酬略過不算。');
            }
            $c = 0.0;
            foreach ($cash as $cur => $amt) {
                $c += $amt * $rates[$cur];
            }
            if (!is_finite($c)) {
                return $numericFailure('現金餘額累計超出可計算範圍，報酬略過不算。');
            }

            $pricedDate = isset($barsByDate[$date]);
            $cashOnly = $barsByDate === [] && $instruments === [] && $cashEvents !== [];
            if (!$pricedDate && $points === [] && !$cashOnly) {
                // Before the first usable price, an event-only state is the baseline, not a return point.
                $pendingTrade += $netTrade;
                if (!is_finite($pendingTrade)) {
                    return $numericFailure('期間本金累計超出可計算範圍，報酬略過不算。');
                }
                continue;
            }

            $pendingTrade += $netTrade;
            if (!is_finite($pendingTrade)) {
                return $numericFailure('期間本金累計超出可計算範圍，報酬略過不算。');
            }
            $total = $h + $c;
            if (!is_finite($total)) {
                return $numericFailure('持股與現金合計超出可計算範圍，報酬略過不算。');
            }

            $axis[] = $date;
            $points[] = [
                'date'      => $date,
                'hold'      => $h,
                'cash'      => $c,
                'total'     => $total,
                // Buying moves principal out of cash; selling moves it back in. Deposits and withdrawals
                // deliberately stay in the cash return, so only trade principal is removed here.
                'cash_flow' => -(float) $pendingTrade,
                // The event-only post-state is already the baseline value; its principal offsets it.
                'trade_net' => (float) $pendingTrade,
                'partial'   => $isPartial,
                // Only prices carried by holdings matter here. A fresh quote for a sold instrument must
                // not hide another holding's stale price, and cash-only endpoints have no price to age.
                'stale'     => $hasHoldings && $endpointPriceDate !== null
                    && strcmp($date, $endpointPriceDate) > 0,
            ];
            $pendingTrade = 0.0;
        }

        if ($points === []) {
            return [
                'periods' => [],
                'years'   => [],
                'meta'    => array_merge($meta, [
                    'missing_price' => $missingPrice,
                    'note' => $instruments === [] && $cashEvents === []
                        ? '這個範圍還沒有交易或現金紀錄。'
                        : '還沒有任何歷史日線，按「更新報價與匯率」抓取。',
                ]),
            ];
        }

        // Extract column arrays for chain() and indexAt() which expect flat arrays
        $hold = array_column($points, 'hold');
        $cashV = array_column($points, 'cash');
        $total = array_column($points, 'total');
        $cashFlow = array_column($points, 'cash_flow');
        $tradeNet = array_column($points, 'trade_net');
        $partial = array_column($points, 'partial');
        $stale = array_column($points, 'stale');

        $series = [
            'axis' => $axis, 'hold' => $hold,
            'cash' => $cashV, 'total' => $total,
            'cash_flow' => $cashFlow, 'trade_net' => $tradeNet,
            'no_flow' => array_fill(0, count($points), 0.0), 'partial' => $partial, 'stale' => $stale,
        ];
        $to = count($points) - 1;
        $anchor = $axis[$to];

        $periods = [];
        foreach (self::PERIODS as $period) {
            $kind = (string) $period['kind'];
            $note = '';

            if ($kind === 'prev') {
                $from = $to > 0 ? $to - 1 : null;
            } else {
                if ($kind === 'days') {
                    $target = (new DateTimeImmutable($anchor))
                        ->modify('-' . (int) $period['n'] . ' days')
                        ->format('Y-m-d');
                } else {
                    $target = $kind === 'ytd'
                        ? sprintf('%04d-12-31', (int) substr($anchor, 0, 4) - 1)
                        : Calendar::subtractMonths($anchor, (int) $period['n']);
                }
                $from = self::indexAt($axis, $target, $anchor);
                if ($from === null && strcmp((string) $axis[0], $target) <= 0) {
                    $from = 0;
                    $note = "起點沿用資料最早一筆（{$axis[0]}）";
                }
            }

            $periods[] = self::row($series, (string) $period['label'], $from, $to, $note);
        }

        // One row per calendar year the history touches, oldest first.
        $buckets = [];
        foreach ($axis as $i => $date) {
            $year = (int) substr((string) $date, 0, 4);
            $buckets[$year]['from'] ??= $i;
            $buckets[$year]['to'] = $i;
        }

        $years = [];
        foreach ($buckets as $year => $bucket) {
            $note = '';
            $from = self::indexAt($axis, sprintf('%04d-12-31', (int) $year - 1), (string) $axis[$bucket['to']]);
            if ($from === null) {
                $from = (int) $bucket['from'];
                $note = "起點沿用資料最早一筆（{$axis[$from]}）";
            }
            if ((int) substr($anchor, 0, 4) === (int) $year) {
                $note = $note === '' ? '年度尚未結束' : $note . '，且年度尚未結束';
            }
            $years[] = self::row($series, (string) $year, $from, (int) $bucket['to'], $note);
        }

        return [
            'periods' => $periods,
            'years'   => $years,
            'meta'    => [
                'base'          => $base,
                'start'         => $axis[0],
                'end'           => $anchor,
                'price_end'     => $endpointPriceDate,
                'stale'         => $stale[$to],
                'points'        => count($axis),
                'trades'        => $meta['trades'],
                'end_holdings'  => $hold[$to],
                'end_cash'      => $cashV[$to],
                'end_total'     => $total[$to],
                'end_complete'  => !$partial[$to],
                'missing_price' => $missingPrice,
                'missing_fx'    => [],
                'note'          => '',
            ],
        ];
    }

    /**
     * @param array<string, array<int, float|string|bool>> $s
     * @return array<string, mixed>
     */
    private static function row(array $s, string $label, ?int $from, int $to, string $note = ''): array
    {
        $row = [
            'label'        => $label,
            'start'        => null,
            'end'          => (string) $s['axis'][$to],
            'holdings_pct' => null,
            'cash_pct'     => null,
            'total_pct'    => null,
            'holdings_end' => (float) $s['hold'][$to],
            'cash_end'     => (float) $s['cash'][$to],
            'complete'     => !$s['partial'][$to],
            'stale_end'    => !empty($s['stale'][$to]),
            'note'         => '',
        ];

        if ($from === null || $from >= $to) {
            $row['note'] = $from === null
                ? '歷史還不夠長，無法計算此期間'
                : '此期間只有一個資料點';

            return $row;
        }

        $row['start'] = (string) $s['axis'][$from];
        $endpointsComplete = $s['partial'][$from] !== true && $s['partial'][$to] !== true;

        // 現金餘額不需要持股報價；合計現在只看期初與期末總值，所以中間缺價不影響這兩欄。
        $row['cash_pct'] = self::chain($s['cash'], $s['cash_flow'], $from, $to, true);
        if ($endpointsComplete) {
            $row['total_pct'] = self::chain($s['total'], $s['no_flow'], $from, $to, true);
        }

        // 持股仍是時間加權報酬，中途缺一天就會讓前後兩段不是同一串可比值，必須逐日檢查。
        $holdingsComplete = $endpointsComplete;
        for ($i = $from + 1; $holdingsComplete && $i < $to; $i++) {
            if ($s['partial'][$i] === true) {
                $holdingsComplete = false;
            }
        }
        if ($holdingsComplete) {
            $row['holdings_pct'] = self::chain($s['hold'], $s['trade_net'], $from, $to);
        }

        if ($row['holdings_pct'] === null && $row['total_pct'] === null && $row['cash_pct'] === null) {
            $row['note'] = $endpointsComplete
                ? '期間內有區段無法計價，或起點還沒有持股，報酬略過不算'
                : '期間起點或期末有持股查不到歷史日線';
        } else {
            $issues = [];
            if ($row['holdings_pct'] === null) {
                $issues[] = '持股報酬有區段無法計價，報酬略過不算';
            }
            if ($row['total_pct'] === null) {
                $issues[] = '合計報酬無法計價（基值為零／負數，或期初／期末缺價）';
            }
            if ($row['cash_pct'] === null) {
                $issues[] = '現金報酬有區段無法計價，報酬略過不算';
            }
            if ($issues !== []) {
                $row['note'] = implode('；', $issues);
                if ($note !== '') {
                    $row['note'] .= '；' . $note;
                }
            } elseif ($note !== '') {
                $row['note'] = $note;
            }
        }

        if ($row['stale_end']) {
            $staleNote = '期末沒有當日報價，持股沿用最後已知價';
            $row['note'] = $row['note'] === '' ? $staleNote : $row['note'] . '；' . $staleNote;
        }

        return $row;
    }

    /**
     * Chain-link the sub-period returns that start where a flow landed, so flows never read as gains.
     * A zero balance with no fee is a neutral 0% segment; a negative balance has no valid base.
     *
     * @param array<int, float|int|string|null> $values
     * @param array<int, float|int|string|null> $flows
     * @return ?float percent, or null when nothing in the window had a value to grow
     */
    private static function chain(array $values, array $flows, int $from, int $to, bool $flatZero = false): ?float
    {
        $acc = 1.0;
        $measured = false;
        $skipped = false;
        $start = $from;
        $net = 0.0;

        for ($i = $from + 1; $i <= $to; $i++) {
            $net += (float) $flows[$i];
            if (!is_finite($net)) {
                return null;
            }
            if (abs($net) <= self::EPSILON && $i !== $to) {
                continue;
            }

            $base = (float) $values[$start];
            $end = (float) $values[$i];
            if (!is_finite($base) || !is_finite($end)) {
                return null;
            }
            if ($flatZero && ($base < -self::EPSILON || $end < -self::EPSILON)) {
                return null;
            }
            $growth = $end - $base - $net;
            if ($base > self::EPSILON) {
                $acc *= 1.0 + $growth / $base;
                if (!is_finite($acc)) {
                    return null;
                }
                $measured = true;
            } elseif ($flatZero && $base >= -self::EPSILON && $end >= -self::EPSILON
                && is_finite($growth) && abs($growth) <= self::EPSILON) {
                // Fully invested or newly sold cash is still a valid, unchanged cash sleeve when all
                // of its balance change is explained by principal. Do not label an ordinary 0% as missing.
                $measured = true;
            } else {
                // 只要有一段沒有可計算的基值，整段相乘就會漏掉那段績效，不能假裝剩下來的就是全期間。
                $skipped = true;
            }

            $net = 0.0;
            $start = $i;
        }

        if (!$measured || $skipped) {
            return null;
        }
        $result = ($acc - 1.0) * 100.0;

        return is_finite($result) ? $result : null;
    }

    /** 歷史股價與手動價共用的有效性檢查；無效價格寧可留空，也不冒充零市值。 */
    private static function positivePrice(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $price = (float) $value;

        return is_finite($price) && $price > 0.0 ? $price : null;
    }

    /** Index of the last actual price point on or before $date, never later than the window's end. */
    private static function indexAt(array $axis, string $date, ?string $through = null): ?int
    {
        if ($axis === [] || ($through !== null && strcmp((string) $axis[0], $through) > 0)) {
            return null;
        }

        $lo = 0;
        $hi = count($axis) - 1;
        if ($through !== null) {
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                if (strcmp((string) $axis[$mid], $through) <= 0) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
        }
        if (strcmp((string) $axis[0], $date) > 0) {
            return null;
        }

        $hi = $lo;
        $lo = 0;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (strcmp((string) $axis[$mid], $date) <= 0) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo;
    }
}
