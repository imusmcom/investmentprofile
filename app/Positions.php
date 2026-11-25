<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Weighted-average-cost position engine.
 *
 * Buy fees are capitalised into the cost basis; sell fees are netted off the proceeds.
 * Realised P&L is booked at each sell against the running average cost.
 */
final class Positions
{
    /**
     * 取目前真正可用來估值的價格：快取報價與手動價都必須是有限正數，日期正規成 Y-m-d 後再比較；
     * 手動價不早於快取價時才採用，讓 Performance 與 Positions 不會各自維護不同的新舊規則。
     *
     * @param array<string, mixed> $inst instruments 列，至少含 manual_price、manual_price_at
     * @param array{price?:mixed, as_of?:mixed}|null $quote 目前快取報價
     * @return array{price:float, as_of:string}|null
     */
    public static function effectivePrice(array $inst, ?array $quote): ?array
    {
        $quotePrice = self::valuationPrice($quote['price'] ?? null);
        $quoteOn = self::priceDate($quote['as_of'] ?? null);
        $manualPrice = self::valuationPrice($inst['manual_price'] ?? null);
        $manualOn = self::priceDate($inst['manual_price_at'] ?? null);

        if ($manualPrice !== null && $manualOn !== null
            && ($quoteOn === null || strcmp($manualOn, $quoteOn) >= 0)) {
            return ['price' => $manualPrice, 'as_of' => $manualOn];
        }
        if ($quotePrice !== null && $quoteOn !== null) {
            return ['price' => $quotePrice, 'as_of' => $quoteOn];
        }

        return null;
    }

    /** 只有有限正數才是價格；null、非數字與零都不會進入市值計算。 */
    private static function valuationPrice(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $price = (float) $value;

        return is_finite($price) && $price > 0.0 ? $price : null;
    }

    /** 資料庫日期或時間戳都取前 10 字元，但剩下來的 Y-m-d 必須是實際存在的日期。 */
    private static function priceDate(mixed $value): ?string
    {
        $date = substr((string) $value, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : null;
    }

    /** Latest quote per instrument: a newer valid manual price can replace the fetched close. */
    public static function priceMap(): array
    {
        // Driving from instruments avoids scanning/grouping every historical bar. The correlated MAX
        // and outer lookup both use the (instrument_id, as_of) primary key, and manual fields come from
        // the same row so their date can be compared with the latest quote without another query.
        $rows = Database::all(
            'SELECT i.id, i.manual_price, i.manual_price_at,
                    p.as_of, p.close, p.prev_close, p.currency
             FROM instruments i
             LEFT JOIN prices p ON p.instrument_id = i.id
               AND p.as_of = (SELECT MAX(latest.as_of) FROM prices latest WHERE latest.instrument_id = i.id)'
        );

        $map = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            // 先保留最新報價的日期，讓較舊的手動價不能因為快取價本身是壞值就越級獲選。
            $existing = $r['as_of'] === null ? null : [
                'price'    => $r['close'],
                'prev'     => $r['prev_close'] === null ? null : (float) $r['prev_close'],
                'as_of'    => $r['as_of'],
                'source'   => 'quote',
                'currency' => $r['currency'],
            ];
            $manual = self::effectivePrice($r, null);
            $effective = self::effectivePrice($r, $existing);
            if ($effective === null) {
                // 最新一根快取價與手動價都不可用時，不能把 0 或非數字留在報價表裡當成市值。
                unset($map[$id]);
                continue;
            }
            if ($manual !== null && $effective['as_of'] === $manual['as_of']) {
                $map[$id] = [
                    'price'    => $effective['price'],
                    'prev'     => $existing['prev'] ?? null,
                    'as_of'    => $effective['as_of'],
                    'source'   => 'manual',
                    'currency' => $existing['currency'] ?? '',
                ];
            } elseif ($existing !== null) {
                $map[$id] = $existing;
                $map[$id]['price'] = $effective['price'];
                $map[$id]['as_of'] = $effective['as_of'];
            }
        }

        foreach ($map as $id => $quote) {
            $effective = self::effectivePrice([], $quote);
            if ($effective === null) {
                unset($map[$id]);
            } else {
                $map[$id]['price'] = $effective['price'];
                $map[$id]['as_of'] = $effective['as_of'];
            }
        }

        return $map;
    }

    /**
     * Build the position table for one portfolio.
     *
     * @return array{rows: array<int,array<string,mixed>>, summary: array<string,mixed>}
     */
    public static function forPortfolio(int $portfolioId): array
    {
        return self::buildRows(self::book($portfolioId));
    }

    /**
     * Several portfolios as one. Each portfolio is costed on its own and only then are the cost pools
     * added: a single shared pass would let one portfolio's sell realise against another portfolio's
     * average cost, which is not what the trade records say.
     *
     * @param array<int, int> $portfolioIds
     * @return array{rows: array<int,array<string,mixed>>, summary: array<string,mixed>}
     */
    public static function forPortfolios(array $portfolioIds): array
    {
        return self::buildRows(self::mergeBooks(self::booksForList($portfolioIds)));
    }

    /** Price holdings and cash from one shared trade replay for dashboard and detail pages. */
    public static function snapshot(array $portfolioIds): array
    {
        return Database::read(static function () use ($portfolioIds): array {
            $books = self::booksForList($portfolioIds);
            $fxCache = [];

            return [
                'positions' => self::buildRows(self::mergeBooks($books), $fxCache),
                'cash'      => self::cashFromBooks($books, $fxCache),
            ];
        });
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private static function booksForList(array $portfolioIds): array
    {
        $portfolioIds = array_values(array_unique(array_map('intval', $portfolioIds)));
        if ($portfolioIds === []) {
            return [];
        }
        $books = array_fill_keys($portfolioIds, []);
        $holders = implode(',', array_fill(0, count($portfolioIds), '?'));

        $instruments = Database::all(
            "SELECT DISTINCT t.portfolio_id, i.*
             FROM instruments i
             JOIN transactions t ON t.instrument_id = i.id
             WHERE t.portfolio_id IN ($holders)
             ORDER BY t.portfolio_id, i.market, i.symbol",
            $portfolioIds
        );
        foreach ($instruments as $inst) {
            $pid = (int) $inst['portfolio_id'];
            unset($inst['portfolio_id']);
            $books[$pid][(int) $inst['id']] = [
                'instrument' => $inst,
                'qty'        => 0.0,
                'cost_pool'  => 0.0,
                'avg'        => 0.0,
                'realized'   => 0.0,
                'fees'       => 0.0,
                'buy_count'  => 0,
                'sell_count' => 0,
                'first_on'   => null,
                'last_on'    => null,
                'oversold'   => 0,
                'cash_in'    => 0.0,
                'cash_out'   => 0.0,
                'pids'       => [$pid],
            ];
        }

        $txs = Database::all(
            'SELECT t.*, i.symbol, i.name, i.market, i.currency, i.asset_class
             FROM transactions t
             JOIN instruments i ON i.id = t.instrument_id
             WHERE t.portfolio_id IN (' . $holders . ')
             ORDER BY t.portfolio_id, t.traded_on ASC, t.id ASC',
            $portfolioIds
        );

        /** @var array<int, array<int, array<string, mixed>>> $books */
        foreach ($txs as $t) {
            $pid = (int) $t['portfolio_id'];
            $id = (int) $t['instrument_id'];
            if (!isset($books[$pid][$id])) {
                continue;
            }
            $pos = &$books[$pid][$id];

            $qty = (float) $t['quantity'];
            $price = (float) $t['price'];
            $fee = (float) $t['fee'];

            $pos['fees'] += $fee;
            $pos['first_on'] ??= $t['traded_on'];
            $pos['last_on'] = $t['traded_on'];

            if ($t['side'] === 'BUY') {
                $pos['buy_count']++;
                $newQty = $pos['qty'] + $qty;
                $pos['cost_pool'] += $qty * $price + $fee;
                $pos['cash_out'] += $qty * $price + $fee;
                $pos['qty'] = $newQty;
                $pos['avg'] = $newQty > 0.0 ? $pos['cost_pool'] / $newQty : 0.0;
            } else {
                $pos['sell_count']++;
                $closing = min($qty, $pos['qty']);
                if ($qty > $pos['qty']) {
                    $pos['oversold']++;
                }
                $proceeds = $closing * $price - $fee;
                // The cash leg follows $closing, not $qty: whatever was sold beyond the holding never
                // existed to be sold, and booking its proceeds would raise the total value out of nothing.
                $pos['cash_in'] += $proceeds;
                $removed = $closing * $pos['avg'];
                $pos['realized'] += $proceeds - $removed;
                $pos['cost_pool'] -= $removed;
                $pos['qty'] -= $closing;
                if ($pos['qty'] <= 0.0) {
                    $pos['qty'] = 0.0;
                    $pos['cost_pool'] = 0.0;
                    $pos['avg'] = 0.0;
                } else {
                    $pos['avg'] = $pos['cost_pool'] / $pos['qty'];
                }
            }
            self::assertFinitePosition($pos);
            unset($pos);
        }

        return $books;
    }

    /**
     * Walk one portfolio's trades in date order and cost every instrument it touches.
     *
     * @return array<int, array<string, mixed>> positions keyed by instrument id
     */
    private static function book(int $portfolioId): array
    {
        return self::booksForList([$portfolioId])[$portfolioId] ?? [];
    }

    /** @param array<int, array<int, array<string, mixed>>> $books
     *  @return array<int, array<string, mixed>>
     */
    private static function mergeBooks(array $books): array
    {
        $merged = [];
        foreach ($books as $book) {
            foreach ($book as $id => $pos) {
                if (!isset($merged[$id])) {
                    $merged[$id] = $pos;
                    continue;
                }

                $merged[$id]['qty'] += $pos['qty'];
                $merged[$id]['cost_pool'] += $pos['cost_pool'];
                $merged[$id]['realized'] += $pos['realized'];
                $merged[$id]['fees'] += $pos['fees'];
                $merged[$id]['cash_in'] += $pos['cash_in'];
                $merged[$id]['cash_out'] += $pos['cash_out'];
                $merged[$id]['buy_count'] += $pos['buy_count'];
                $merged[$id]['sell_count'] += $pos['sell_count'];
                $merged[$id]['oversold'] += $pos['oversold'];
                $merged[$id]['pids'] = array_merge($merged[$id]['pids'], $pos['pids']);
                $merged[$id]['first_on'] = $pos['first_on'] === null ? $merged[$id]['first_on']
                    : ($merged[$id]['first_on'] === null ? $pos['first_on'] : min($merged[$id]['first_on'], $pos['first_on']));
                $merged[$id]['last_on'] = $pos['last_on'] === null ? $merged[$id]['last_on']
                    : ($merged[$id]['last_on'] === null ? $pos['last_on'] : max($merged[$id]['last_on'], $pos['last_on']));
                $merged[$id]['avg'] = $merged[$id]['qty'] > 0.0 ? $merged[$id]['cost_pool'] / $merged[$id]['qty'] : 0.0;
                self::assertFinitePosition($merged[$id]);
            }
        }

        return $merged;
    }

    private static function assertFinitePosition(array $pos): void
    {
        foreach (['qty', 'cost_pool', 'avg', 'realized', 'fees', 'cash_in', 'cash_out'] as $field) {
            self::finite((float) $pos[$field], '持股數量、成本或現金');
        }
    }

    private static function finite(float $value, string $label): float
    {
        if (!is_finite($value)) {
            throw new RuntimeException("{$label}累計超出可計算範圍。");
        }

        return $value;
    }

    /**
     * Price the costed positions and total them, per currency and in the base currency.
     *
     * @param array<int, array<string, mixed>> $book positions keyed by instrument id
     * @return array{rows: array<int,array<string,mixed>>, summary: array<string,mixed>}
     */
    private static function buildRows(array $book, ?array &$fxCache = null): array
    {
        $base = Settings::baseCurrency();
        $prices = self::priceMap();
        $fxCache ??= [];

        $rows = [];
        $currencies = [];
        $missingFx = [];
        $missingPrice = [];

        foreach ($book as $id => $p) {
            $inst = $p['instrument'];
            $native = strtoupper((string) $inst['currency']);
            $quote = $prices[$id] ?? null;

            // Fully closed positions still carry realised P&L, so keep them if anything happened. A sell
            // that found nothing to close is the only trace an oversold record leaves: its proceeds are
            // not in the cash either, so dropping the row here would make the money vanish silently.
            if ($p['qty'] <= 0.0 && $p['realized'] === 0.0
                && $p['buy_count'] === 0 && $p['oversold'] === 0) {
                continue;
            }

            $costBasis = $p['qty'] * $p['avg'];
            $effective = self::effectivePrice($inst, $quote);
            $lastPrice = $effective['price'] ?? null;
            $marketValue = $lastPrice === null ? null : $p['qty'] * $lastPrice;
            $unrealized = $marketValue === null ? null : $marketValue - $costBasis;
            // 已出清的持股未實現恆為 0，所以它的合計損益就是已實現。還握在手裡卻拿不到報價時，
            // 合計損益無從得知：把未實現當成 0 加進去，等於宣稱這筆持股一文不值。
            $unrealForGain = $unrealized ?? ($p['qty'] <= 0.0 ? 0.0 : null);
            $totalGain = $unrealForGain === null ? null : $unrealForGain + $p['realized'];
            self::finite($costBasis, '持股成本');
            if ($marketValue !== null) {
                self::finite($marketValue, '持股市值');
            }
            if ($unrealized !== null) {
                self::finite($unrealized, '未實現損益');
            }
            if ($totalGain !== null) {
                self::finite($totalGain, '合計損益');
            }

            $unrealizedPct = $costBasis > 0.0 && $unrealized !== null
                ? $unrealized / $costBasis * 100.0
                : null;
            if ($unrealizedPct !== null) {
                self::finite($unrealizedPct, '未實現損益百分比');
            }

            $row = [
                'id'            => $id,
                'instrument'    => $inst,
                'native'        => $native,
                'qty'           => $p['qty'],
                'avg_cost'      => $p['avg'],
                'cost_basis'    => $costBasis,
                'last_price'    => $lastPrice,
                'price_source'  => $lastPrice === null ? null : ($quote['source'] ?? null),
                'price_as_of'   => $effective['as_of'] ?? null,
                'day_change_pct' => self::dayChange($quote),
                'market_value'  => $marketValue,
                'unrealized'    => $unrealized,
                'unrealized_pct' => $unrealizedPct,
                'realized'      => $p['realized'],
                'total_gain'    => $p['buy_count'] > 0 || $p['sell_count'] > 0 ? $totalGain : null,
                'fees'          => $p['fees'],
                'buy_count'     => $p['buy_count'],
                'sell_count'    => $p['sell_count'],
                'first_on'      => $p['first_on'],
                'last_on'       => $p['last_on'],
                'oversold'      => $p['oversold'],
                'is_open'       => $p['qty'] > 0.0,
                'portfolio_count' => max(1, count($p['pids'])),
            ];

            if ($native !== '') {
                $currencies[$native] = true;
            }
            if ($lastPrice === null && $row['is_open']) {
                $missingPrice[] = (string) $inst['symbol'];
            }

            $conv = convert((float) ($marketValue ?? 0.0), $native, $base, $fxCache);
            $row['base_market_value'] = $marketValue === null ? null : $conv['value'];
            $row['base_ok'] = $conv['converted'];
            if (!$conv['converted'] && $marketValue !== null) {
                $missingFx[$native] = true;
            }

            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => (float) $b['market_value'] <=> (float) $a['market_value']);

        $totalBase = 0.0;
        $totalCostBase = 0.0;
        $totalUnrealBase = 0.0;
        $totalRealBase = 0.0;
        $allConverted = true;
        // 「不知道值多少」不等於「值 0」：只要有一筆還在手上的持股拿不到報價，所有把市值加總起來
        // 的數字都偏小，與其顯示一個假的合計，不如顯示 --，讓畫面上那顆「N 個缺價」的提示有意義。
        $unpriced = $missingPrice !== [];
        // 成本和已實現不需要報價，只需要匯率：它們各自有「換算不掉」的開關，不被缺價連著一起清空。
        $costBaseUnknown = false;
        $realBaseUnknown = false;
        $byCurrency = [];

        foreach ($rows as $i => $row) {
            if ($row['is_open']) {
                $mv = $row['base_market_value'];
                if ($mv === null) {
                    $allConverted = false;
                } else {
                    $totalBase += $mv;
                    // Weight is only meaningful once every holding has a price.
                    $rows[$i]['weight'] = $mv;
                }
            }
            // Native-currency subtotals, so a HKD holding can be read in HKD without converting first.
            if ($row['is_open'] && $row['native'] !== '') {
                $cur = $row['native'];
                // 先為這筆持股立一個幣別列，才不會因為它缺價就整列從表裡消失，讓人以為這個幣別沒持股。
                $byCurrency[$cur] ??= [
                    'currency' => $cur,
                    'market_value' => 0.0,
                    'unpriced' => false,
                    'base_market_value' => 0.0,
                    'base_ok' => true,
                ];
                if ($row['market_value'] === null) {
                    $byCurrency[$cur]['unpriced'] = true;
                } else {
                    $byCurrency[$cur]['market_value'] += (float) $row['market_value'];
                }
                if ($row['base_market_value'] === null) {
                    $byCurrency[$cur]['base_ok'] = false;
                } else {
                    $byCurrency[$cur]['base_market_value'] += (float) $row['base_market_value'];
                }
            }
            $costInBase = convert((float) $row['cost_basis'], $row['native'], $base, $fxCache);
            $unrInBase = $row['unrealized'] === null
                ? ['value' => null, 'converted' => true]
                : convert((float) $row['unrealized'], $row['native'], $base, $fxCache);
            $realInBase = convert((float) $row['realized'], $row['native'], $base, $fxCache);

            // 換算不了不等於金額是 0，但 0 換成任何幣別都還是 0，不需要匯率：只有真的帶著金額的
            // 換算失敗，才會讓下面那些記帳幣別的合計變成一半的數。
            $dropped = static fn (array $conv, float $native): bool => $conv['value'] === null && $native !== 0.0;
            $costDropped = $dropped($costInBase, (float) $row['cost_basis']);
            $realDropped = $dropped($realInBase, (float) $row['realized']);
            if ($costDropped || $realDropped) {
                $missingFx[$row['native']] = true;
            }

            $rows[$i]['base_cost_basis'] = $costInBase['value'];
            $rows[$i]['base_unrealized'] = $unrInBase['value'];
            $rows[$i]['base_realized'] = $realInBase['value'];
            // 缺價的開放持股、或缺匯率而真的弄丟了金額的已出清持股，換算成記帳幣別的合計損益都是未知。
            $rows[$i]['base_total_gain'] = $realDropped || ($unrInBase['value'] === null && $row['is_open'])
                ? null
                : ($unrInBase['value'] ?? 0.0) + ($realInBase['value'] ?? 0.0);
            $rows[$i]['base_ok'] = $costInBase['converted'] && $realInBase['converted'];

            $totalCostBase += (float) ($costInBase['value'] ?? 0.0);
            $totalUnrealBase += (float) ($unrInBase['value'] ?? 0.0);
            $totalRealBase += (float) ($realInBase['value'] ?? 0.0);
            // 已實現全來自交易紀錄：報價缺不缺跟它無關，匯率才是它唯一的未知。
            if ($costDropped) {
                $costBaseUnknown = true;
            }
            if ($realDropped) {
                $realBaseUnknown = true;
            }
        }

        self::finite($totalBase, '持股市值合計');
        self::finite($totalCostBase, '持股成本合計');
        self::finite($totalUnrealBase, '未實現損益合計');
        self::finite($totalRealBase, '已實現損益合計');
        foreach ($byCurrency as $subtotal) {
            self::finite((float) $subtotal['market_value'], '原幣持股市值合計');
            self::finite((float) $subtotal['base_market_value'], '記帳幣別持股市值合計');
        }

        if ($allConverted && $totalBase > 0.0) {
            // 分母（totalBase）缺了任何一把持股都會讓每一列的佔比偏高，所以寧可整欄留白。
            foreach ($rows as $i => $row) {
                if (isset($row['weight'])) {
                    $rows[$i]['weight_pct'] = $row['weight'] / $totalBase * 100.0;
                }
            }
        }

        ksort($byCurrency);

        $single = count($currencies) === 1 ? array_key_first($currencies) : null;
        $nativeTotals = null;
        if ($single !== null) {
            $nativeTotals = [
                'currency'     => $single,
                'cost_basis'   => array_sum(array_column($rows, 'cost_basis')),
                // 原幣合計只看缺價，不看缺匯率：匯率影響的是換算，不影響這個幣別自己值多少。
                'market_value' => $unpriced ? null : array_sum(array_map(
                    static fn (array $r): float => (float) ($r['market_value'] ?? 0.0),
                    $rows
                )),
                'unrealized'   => $unpriced ? null : array_sum(array_map(
                    static fn (array $r): float => (float) ($r['unrealized'] ?? 0.0),
                    $rows
                )),
                'realized'     => array_sum(array_column($rows, 'realized')),
                'fees'         => array_sum(array_column($rows, 'fees')),
            ];
            foreach (['cost_basis', 'market_value', 'unrealized', 'realized', 'fees'] as $field) {
                if ($nativeTotals[$field] !== null) {
                    self::finite((float) $nativeTotals[$field], '原幣合計');
                }
            }
        }

        return [
            'rows' => $rows,
            'summary' => [
                'base'             => $base,
                'market_value'     => $allConverted ? $totalBase : null,
                'cost_basis'       => $costBaseUnknown ? null : $totalCostBase,
                'unrealized'       => $allConverted ? $totalUnrealBase : null,
                'realized'         => $realBaseUnknown ? null : $totalRealBase,
                'total_gain'       => $allConverted && !$realBaseUnknown ? $totalUnrealBase + $totalRealBase : null,
                'complete'         => $allConverted,
                'open_positions'   => count(array_filter($rows, static fn (array $r): bool => $r['is_open'])),
                'closed_positions' => count(array_filter($rows, static fn (array $r): bool => !$r['is_open'])),
                'native_totals'    => $nativeTotals,
                'currencies'       => array_keys($currencies),
                'by_currency'      => array_values($byCurrency),
                'missing_fx'       => array_keys($missingFx),
                'missing_price'    => $missingPrice,
            ],
        ];
    }

    /**
     * Cash by currency: deliberate deposits/withdrawals plus whatever each trade settles to.
     * A buy takes quantity x price and its fee out of cash, a sell pays in quantity x price less the
     * fee, so editing or deleting a trade moves cash by itself and no ledger has to be kept in sync.
     *
     * @return array{rows: array<int,array<string,mixed>>, base: string, total_base: float|null, complete: bool}
     */
    public static function cashFor(int $portfolioId): array
    {
        return self::cashForList([$portfolioId]);
    }

    /**
     * The same balance summed across several portfolios: every component is additive per currency.
     *
     * @param array<int, int> $portfolioIds
     * @return array{rows: array<int,array<string,mixed>>, base: string, total_base: float|null, complete: bool}
     */
    public static function cashForList(array $portfolioIds): array
    {
        return self::cashFromBooks(self::booksForList($portfolioIds));
    }

    /** @param array<int, array<int, array<string, mixed>>> $books
     *  @return array{rows: array<int,array<string,mixed>>, base: string, total_base: float|null, complete: bool}
     */
    private static function cashFromBooks(array $books, ?array &$fxCache = null): array
    {
        $ids = array_keys($books);
        $base = Settings::baseCurrency();
        $fxCache ??= [];
        $blank = [
            'currency' => '', 'deposit_in' => 0.0, 'withdraw_out' => 0.0, 'trade_in' => 0.0, 'trade_out' => 0.0,
        ];
        $byCurrency = [];

        $in = $ids === [] ? '(NULL)' : '(' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;

        $merge = static function (string $currency, array $parts) use (&$byCurrency, $blank): void {
            $currency = strtoupper($currency);
            if ($currency === '') {
                return;
            }
            $row = $byCurrency[$currency] ?? $blank;
            $row['currency'] = $currency;
            foreach ($parts as $key => $value) {
                $row[$key] = self::finite((float) $row[$key] + (float) $value, '現金');
            }
            $byCurrency[$currency] = $row;
        };

        foreach (Database::all(
            "SELECT currency,
                    SUM(CASE WHEN direction = 'IN' THEN amount ELSE 0 END)  AS deposit_in,
                    SUM(CASE WHEN direction = 'OUT' THEN amount ELSE 0 END) AS withdraw_out
             FROM cash_movements WHERE portfolio_id IN $in GROUP BY currency",
            $params
        ) as $r) {
            $merge((string) $r['currency'], [
                'deposit_in'   => (float) $r['deposit_in'],
                'withdraw_out' => (float) $r['withdraw_out'],
            ]);
        }

        // The trade legs come out of the same walk that costs the holdings, so both columns agree about
        // how much was really sold. Summing the transaction rows here would also hand back the proceeds
        // of an oversell, which is value that never existed.
        foreach ($books as $book) {
            foreach ($book as $pos) {
                $merge((string) $pos['instrument']['currency'], [
                    'trade_in'  => (float) $pos['cash_in'],
                    'trade_out' => (float) $pos['cash_out'],
                ]);
            }
        }

        $rows = [];
        $totalBase = 0.0;
        $complete = true;
        foreach ($byCurrency as $currency => $r) {
            $balance = self::finite(
                $r['deposit_in'] + $r['trade_in'] - $r['withdraw_out'] - $r['trade_out'],
                '現金餘額'
            );
            $conv = convert($balance, $currency, $base, $fxCache);
            if ($conv['converted']) {
                $totalBase = self::finite($totalBase + (float) $conv['value'], '現金合計');
            } else {
                $complete = false;
            }
            $rows[] = $r + [
                'balance'     => $balance,
                'base_ok'     => $conv['converted'],
                'base_value'  => $conv['value'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['currency'], $b['currency']));

        return [
            'rows'       => $rows,
            'base'       => $base,
            'total_base' => $complete && $rows !== [] ? $totalBase : null,
            'complete'   => $complete,
        ];
    }

    private static function dayChange(?array $quote): ?float
    {
        if ($quote === null || !is_numeric($quote['price'] ?? null) || !is_finite((float) $quote['price'])
            || (float) $quote['price'] <= 0.0 || !is_numeric($quote['prev'] ?? null)
            || !is_finite((float) $quote['prev']) || (float) $quote['prev'] <= 0.0 || $quote['source'] !== 'quote') {
            return null;
        }

        return ((float) $quote['price'] - (float) $quote['prev']) / (float) $quote['prev'] * 100.0;
    }
}
