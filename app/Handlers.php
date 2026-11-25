<?php
declare(strict_types=1);

namespace App;

use PDOException;
use RuntimeException;

final class Handlers
{
    private const CURRENCY_PATTERN = '/^[A-Z]{2,5}$/';

    // ------------------------------------------------------------------ auth

    public static function loginForm(): void
    {
        if (Auth::signedIn()) {
            self::redirect(App::safeBack('/'));
        }

        self::render('login', [
            'title'  => '登入',
            'back'   => App::input('back'),
            'setup'  => !Auth::configured(),
            'error'  => '',
        ]);
    }

    public static function loginCreate(): void
    {
        $back = App::safeBack('/');
        // Never trim a password: spaces at either end are the user's, not sloppiness to clean up.
        $error = Auth::attempt(App::input('username'), self::rawInput('password'));

        if ($error === '') {
            self::redirect($back);
        }

        http_response_code(401);
        self::render('login', ['title' => '登入', 'back' => App::input('back'), 'setup' => !Auth::configured(), 'error' => $error]);
    }

    public static function logoutCreate(): void
    {
        Auth::logout();
        flash('已登出。');
        self::redirect('/login');
    }

    public static function passwordSave(): void
    {
        Auth::changePassword(self::rawInput('current_password'), self::rawInput('new_password'), self::rawInput('new_password2'));
        flash('密碼已更新，這台瀏覽器還留著，其他地方的登入狀態都要重新輸入。');
        self::redirect('/settings');
    }

    private static function rawInput(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    // ---------------------------------------------------------------- reads

    public static function dashboard(): void
    {
        Database::read(static function (): void {
            if (Database::scalar('SELECT COUNT(*) FROM portfolios', [], 0) == 0) {
                self::render('welcome', ['title' => '歡迎使用']);

                return;
            }

            $portfolios = Database::all('SELECT id, name FROM portfolios ORDER BY id');
            $aggregate = self::dashboardScope();
            $ids = $aggregate
                ? array_map(static fn (array $p): int => (int) $p['id'], $portfolios)
                : [current_portfolio_id()];

            $snapshot = Positions::snapshot($ids);
            $data = $snapshot['positions'];
            $cash = $snapshot['cash'];

            $holders = implode(',', array_fill(0, count($ids), '?'));
            $recent = Database::all(
                "SELECT t.*, i.symbol, i.name, i.market, i.currency, p.name AS portfolio_name
                 FROM transactions t
                 JOIN instruments i ON i.id = t.instrument_id
                 JOIN portfolios p ON p.id = t.portfolio_id
                 WHERE t.portfolio_id IN ($holders)
                 ORDER BY t.traded_on DESC, t.id DESC LIMIT 10",
                $ids
            );

            self::render('dashboard', [
                'title'      => '總覽',
                // The refresh bar sends 0 for "everything", which is what the combined view is looking at.
                'pid'        => $aggregate ? 0 : $ids[0],
                'aggregate'  => $aggregate,
                'scope_ids'  => $ids,
                'portfolios' => $portfolios,
                'portfolio'  => $aggregate ? null : Database::one('SELECT * FROM portfolios WHERE id = ?', [$ids[0]]),
                'rows'       => $data['rows'],
                'summary'    => $data['summary'],
                'cash'       => $cash,
                'breakdown'  => self::currencyBreakdown($data['summary'], $cash),
                'recent'     => $recent,
                'lastRefresh' => Settings::get('last_refresh'),
            ]);
        });
    }

    /**
     * 總覽、回報與交易紀錄共用同一個工作階段範圍；GET 只讀取，切換範圍由 POST /scope 負責。
     */
    private static function dashboardScope(): bool
    {
        return ($_SESSION['dashboard_scope'] ?? '') === 'all';
    }

    /** 切換範圍是刻意的一次寫入，不讓 GET 或重新整理偷偷改掉目前選擇。 */
    public static function scopeSelect(): void
    {
        $requested = App::input('scope');
        if ($requested === 'all') {
            $_SESSION['dashboard_scope'] = 'all';
        } elseif (ctype_digit($requested) && (int) $requested > 0
            && Database::scalar('SELECT COUNT(*) FROM portfolios WHERE id = ?', [(int) $requested], 0) > 0) {
            $id = (int) $requested;
            $switched = current_portfolio_id() !== $id;
            $_SESSION['portfolio_id'] = $id;
            if ($switched) {
                flash('已切換投資組合。');
            }
            unset($_SESSION['dashboard_scope']);
        } else {
            throw new UserError('請選擇有效的投資組合或「所有組合」。');
        }

        self::redirect(App::safeBack('/'));
    }

    /**
     * Holdings value and cash side by side per currency, so 港幣持股 can be read in HKD on the dashboard.
     *
     * @return array{rows: array<int,array<string,mixed>>, base: string, total_base: float|null}
     */
    private static function currencyBreakdown(array $summary, array $cash): array
    {
        $rows = [];
        foreach ($summary['by_currency'] as $c) {
            // 這一幣別只要有一筆持股沒報價，原幣小計就是個偏小的數，寧可顯示「缺報價」。
            $rows[$c['currency']] = [
                'currency'     => $c['currency'],
                'market_value' => empty($c['unpriced']) ? (float) $c['market_value'] : null,
                'unpriced'     => !empty($c['unpriced']),
                'balance'      => null,
                'base_value'   => $c['base_ok'] ? (float) $c['base_market_value'] : null,
            ];
        }

        foreach ($cash['rows'] as $r) {
            $row = $rows[$r['currency']] ?? [
                'currency' => $r['currency'], 'market_value' => null, 'unpriced' => false,
                'balance' => null, 'base_value' => 0.0,
            ];
            $row['balance'] = (float) $r['balance'];
            $row['base_value'] = !$r['base_ok'] || $row['base_value'] === null
                ? null
                : $row['base_value'] + (float) $r['base_value'];
            $rows[$r['currency']] = $row;
        }

        ksort($rows);

        $total = 0.0;
        $complete = $rows !== [];
        foreach ($rows as $row) {
            if ($row['base_value'] === null) {
                $complete = false;
            } else {
                $total += $row['base_value'];
            }
        }

        return [
            'rows'       => array_values($rows),
            'base'       => $cash['base'],
            'total_base' => $complete ? $total : null,
        ];
    }

    /**
     * 回報 reads the same 範圍 control as 總覽, so the two pages always describe the same set of
     * portfolios; performance itself lives in App\Performance.
     */
    public static function reports(): void
    {
        Database::read(static function (): void {
            if (Database::scalar('SELECT COUNT(*) FROM portfolios', [], 0) == 0) {
                self::render('welcome', ['title' => '歡迎使用']);

                return;
            }

            $portfolios = Database::all('SELECT id, name FROM portfolios ORDER BY id');
            $aggregate = self::dashboardScope();
            $ids = $aggregate
                ? array_map(static fn (array $p): int => (int) $p['id'], $portfolios)
                : [current_portfolio_id()];

            self::render('reports', [
                'title'       => '回報',
                'pid'         => $aggregate ? 0 : $ids[0],
                'aggregate'   => $aggregate,
                'scope_ids'   => $ids,
                'portfolios'  => $portfolios,
                'portfolio'   => $aggregate ? null : Database::one('SELECT * FROM portfolios WHERE id = ?', [$ids[0]]),
                'report'      => Performance::forPortfolios($ids),
                'lastRefresh' => Settings::get('last_refresh'),
            ]);
        });
    }

    public static function transactions(): void
    {
        // The list follows the same 範圍 control as 總覽 and 回報, but the forms below it still write
        // into one concrete portfolio, so 新增一筆 and 現金 stay bound to the current one.
        $portfolios = Database::all('SELECT id, name FROM portfolios ORDER BY id');
        $aggregate = self::dashboardScope();
        $pid = current_portfolio_id();
        $ids = $aggregate
            ? array_map(static fn (array $p): int => (int) $p['id'], $portfolios)
            : ($pid > 0 ? [$pid] : []);
        $instrumentId = App::intInput('instrument');

        $slots = [];
        $params = [];
        foreach ($ids as $n => $id) {
            $slots[] = ':scope' . $n;
            $params['scope' . $n] = $id;
        }

        $where = 'WHERE ' . ($slots === [] ? '0' : 't.portfolio_id IN (' . implode(',', $slots) . ')');
        if ($instrumentId > 0) {
            $where .= ' AND t.instrument_id = :iid';
            $params['iid'] = $instrumentId;
        }
        if (($from = App::input('from')) !== '') {
            $where .= ' AND t.traded_on >= :from';
            $params['from'] = $from;
        }
        if (($to = App::input('to')) !== '') {
            $where .= ' AND t.traded_on <= :to';
            $params['to'] = $to;
        }

        // Count the whole filtered scope for the CSV and pager; only the table query is limited.
        $txCount = (int) Database::scalar('SELECT COUNT(*) FROM transactions t ' . $where, $params, 0);
        $txPages = max(1, (int) ceil($txCount / 100));
        $pageInput = App::input('page', '1');
        $txPage = ctype_digit($pageInput) ? max(1, min((int) $pageInput, $txPages)) : 1;
        $txOffset = ($txPage - 1) * 100;
        $sql = 'SELECT t.*, i.symbol, i.name, i.market, i.currency, i.yahoo_symbol, p.name AS portfolio_name
                FROM transactions t
                JOIN instruments i ON i.id = t.instrument_id
                JOIN portfolios p ON p.id = t.portfolio_id '
            . $where . ' ORDER BY t.traded_on DESC, t.id DESC LIMIT 100 OFFSET :offset';
        $rows = Database::all($sql, $params + ['offset' => $txOffset]);

        // The 匯出 CSV link carries this same view: scope, and every filter applied to the table.
        $csvQuery = http_build_query(array_filter([
            'scope' => $aggregate ? 'all' : ($pid > 0 ? $pid : null),
            'instrument' => $instrumentId > 0 ? $instrumentId : null,
            'from' => App::input('from') !== '' ? App::input('from') : null,
            'to' => App::input('to') !== '' ? App::input('to') : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $hasPortfolio = $ids !== [];
        $cashLogCount = $hasPortfolio && $pid > 0
            ? (int) Database::scalar('SELECT COUNT(*) FROM cash_movements WHERE portfolio_id = ?', [$pid], 0)
            : 0;
        $cashPages = max(1, (int) ceil($cashLogCount / 100));
        $cashPage = max(1, min(App::intInput('cash_page', 1), $cashPages));
        $cashOffset = ($cashPage - 1) * 100;
        $cashLogs = $cashLogCount > 0 ? Database::all(
            'SELECT * FROM cash_movements WHERE portfolio_id = ? ORDER BY moved_on DESC, id DESC LIMIT 100 OFFSET ?',
            [$pid, $cashOffset]
        ) : [];

        self::render('transactions', [
            'title'       => '交易紀錄',
            'pid'         => $pid,
            'aggregate'   => $aggregate,
            'scope_ids'   => $ids,
            'txs'         => $rows,
            'tx_count'    => $txCount,
            'tx_page'     => $txPage,
            'tx_pages'    => $txPages,
            'csv_url'     => url('/export') . ($csvQuery === '' ? '' : '?' . $csvQuery),
            'csv_rows'    => $txCount,
            'cash'        => $hasPortfolio ? Positions::cashFor($pid) : ['rows' => [], 'total_base' => null, 'base' => base_currency(), 'complete' => true],
            'cash_logs'   => $cashLogs,
            'cash_count'  => $cashLogCount,
            'cash_page'   => $cashPage,
            'cash_pages'  => $cashPages,
            'instruments' => self::instrumentsForForm(),
            'portfolios'  => $portfolios,
            'markets'     => Markets::MARKETS,
            'filters'     => ['instrument' => $instrumentId, 'from' => App::input('from'), 'to' => App::input('to')],
        ]);
    }

    public static function instruments(): void
    {
        self::render('instruments', [
            'title'       => '投資物資料庫',
            'instruments' => Database::all('SELECT * FROM instruments ORDER BY market, symbol'),
            'prices'      => Positions::priceMap(),
            'markets'     => Markets::MARKETS,
            'classes'     => Markets::assetClasses(),
        ]);
    }

    public static function portfolios(): void
    {
        $rows = Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM transactions t WHERE t.portfolio_id = p.id) AS txs,
                    COALESCE((SELECT SUM(t.side = 'BUY') FROM transactions t WHERE t.portfolio_id = p.id), 0) AS buys,
                    (SELECT COUNT(*) FROM cash_movements c WHERE c.portfolio_id = p.id) AS cash
             FROM portfolios p ORDER BY p.id"
        );
        foreach ($rows as &$row) {
            $row['stats'] = [
                'txs'  => (int) $row['txs'],
                'buys' => (int) $row['buys'],
                'cash' => (int) $row['cash'],
            ];
            unset($row['txs'], $row['buys'], $row['cash']);
        }
        unset($row);

        self::render('portfolios', [
            'title'      => '投資組合',
            'portfolios' => $rows,
            'active'     => current_portfolio_id(),
        ]);
    }

    public static function settings(): void
    {
        self::render('settings', [
            'title'  => '設定',
            'base'   => Settings::baseCurrency(),
            'fx'     => Database::all('SELECT * FROM fx_rates ORDER BY currency'),
            'rates'  => self::currencyOptions(),
            'quoteEnabled' => Settings::get('quote_source', 'yahoo') !== 'off',
            'quoteEndpoint' => QuoteService::endpoint(),
            'quoteToken'    => QuoteService::token(),
            'refreshBudget' => Settings::get('quote_refresh_budget_seconds', ''),
            'cooldownLeft'  => QuoteService::cooldownLeft(),
            'lastRefresh'  => Settings::get('last_refresh'),
            'backups'      => Backup::list(),
            'backupDir'    => Backup::directory(),
            'uploadLimit'  => Backup::uploadLimit(),
            'loginUser'    => Auth::user(),
        ]);
    }

    /** Formal strategy page: the Five-Line analysis is always available. */
    public static function strategy(): void
    {
        self::render('strategy', [
            'title' => '樂活五線譜',
            'rows'  => Strategy::rows(),
        ]);
    }

    public static function laboratory(): void
    {
        self::render('laboratory', [
            'title' => '實驗室',
        ]);
    }

    /** Chart data for one instrument on the formal strategy page. */
    public static function strategyChart(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            self::jsonResponse(['error' => '投資物編號不正確。'], 400);
            return;
        }

        $data = Strategy::chartData($id);
        if ($data === null) {
            self::jsonResponse(['error' => '查無這個投資物的圖表資料。'], 404);
            return;
        }

        self::jsonResponse($data);
    }

    // --------------------------------------------------------------- writes

    public static function portfolioCreate(): void
    {
        $name = self::textInput('name', 'name');
        $description = self::textInput('description', 'description');
        if ($name === '') {
            throw new UserError('請輸入投資組合名稱。');
        }

        try {
            Database::run(
                'INSERT INTO portfolios (name, description, created_at) VALUES (?, ?, ?)',
                [$name, $description, now_stamp()]
            );
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique constraint')) {
                throw new UserError("「{$name}」已經存在。");
            }
            throw $e;
        }

        $id = (int) Database::pdo()->lastInsertId();
        $_SESSION['portfolio_id'] = $id;
        unset($_SESSION['dashboard_scope']);
        flash("已建立投資組合「{$name}」。");
        self::redirectFirst();
    }

    public static function portfolioSelect(array $p): void
    {
        $id = (int) $p['id'];
        if (Database::scalar('SELECT COUNT(*) FROM portfolios WHERE id = ?', [$id], 0) == 0) {
            throw new UserError('投資組合不存在。');
        }
        $_SESSION['portfolio_id'] = $id;
        unset($_SESSION['dashboard_scope']);
        flash('已切換投資組合。');
        self::redirectFirst();
    }

    public static function portfolioUpdate(array $p): void
    {
        $id = (int) $p['id'];
        $name = self::textInput('name', 'name');
        $description = self::textInput('description', 'description');
        if ($name === '') {
            throw new UserError('投資組合名稱不能是空的。');
        }

        $clash = (int) Database::scalar(
            'SELECT COUNT(*) FROM portfolios WHERE name = ? AND id <> ?', [$name, $id], 0
        );
        if ($clash > 0) {
            throw new UserError("「{$name}」已經存在，請換一個名稱。");
        }

        $changed = Database::run(
            'UPDATE portfolios SET name = ?, description = ? WHERE id = ?',
            [$name, $description, $id]
        )->rowCount();
        if ($changed === 0) {
            throw new UserError('投資組合不存在。');
        }

        flash("已更新組合「{$name}」。");
        self::redirect('/portfolios');
    }

    public static function portfolioDelete(array $p): void
    {
        $id = (int) $p['id'];
        $portfolio = Database::one('SELECT name FROM portfolios WHERE id = ?', [$id]);
        if ($portfolio === null) {
            throw new UserError('投資組合不存在。');
        }
        $name = (string) $portfolio['name'];
        $txs = (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE portfolio_id = ?', [$id], 0);
        $cash = (int) Database::scalar('SELECT COUNT(*) FROM cash_movements WHERE portfolio_id = ?', [$id], 0);

        if (($txs > 0 || $cash > 0) && App::input('confirm_name') !== $name) {
            throw new UserError("此組合有 {$txs} 筆交易與 {$cash} 筆現金紀錄，請輸入組合名稱「{$name}」確認刪除。");
        }

        Backup::snapshot('pre-restore');
        $removed = Database::run('DELETE FROM portfolios WHERE id = ?', [$id])->rowCount();
        if ($removed !== 1) {
            throw new UserError('投資組合不存在或已遭刪除，請重新整理後再試。');
        }
        if ((int) ($_SESSION['portfolio_id'] ?? 0) === $id) {
            unset($_SESSION['portfolio_id']);
        }
        flash("已刪除投資組合「{$name}」，連同 {$txs} 筆交易與 {$cash} 筆現金紀錄一併刪除。", 'warn');
        self::redirect('/portfolios');
    }

    public static function instrumentCreate(): void
    {
        $symbol = self::textInput('symbol', 'symbol');
        $name = self::textInput('name', 'name');
        $notes = self::textInput('notes', 'notes');
        $market = App::input('market', 'US');

        if ($symbol === '') {
            throw new UserError('請輸入投資物代號。');
        }
        if (!isset(Markets::MARKETS[$market])) {
            throw new UserError('不支援的市場。');
        }

        $yahoo = Markets::toYahoo($symbol, $market);
        $currency = self::currencyInput('currency', Markets::currencyFor($market));
        $assetClass = self::assetClassInput('asset_class', 'EQUITY');

        $existing = Database::one('SELECT id, symbol FROM instruments WHERE yahoo_symbol = ?', [$yahoo]);
        if ($existing !== null) {
            flash("「{$yahoo}」已存在於投資物資料庫，直接到交易紀錄加入買進即可。", 'warn');
            self::redirect('/instruments');
        }

        Database::run(
            'INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency, asset_class, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $symbol,
                $name,
                $market,
                $yahoo,
                $currency,
                $assetClass,
                $notes,
                now_stamp(),
            ]
        );

        flash("已新增投資物 {$symbol}（{$yahoo}，{$currency}）。");

        if (App::input('auto_quote') === '1') {
            $result = self::applyQuote((int) Database::pdo()->lastInsertId());
            flash($result['ok']
                ? "已抓到最新價 " . fmt_money($result['price'], $currency) . "（{$result['as_of']}）。"
                : "自動報價失敗：{$result['error']}。可改用手動輸入價格。", $result['ok'] ? 'ok' : 'warn');
        } else {
            // applyQuote already refreshes the rate for that symbol; this path never reaches it.
            self::ensureFxFor($currency);
        }

        self::redirect('/instruments');
    }

    public static function instrumentPrice(array $p): void
    {
        $id = (int) $p['id'];
        if (Database::one('SELECT id FROM instruments WHERE id = ?', [$id]) === null) {
            throw new UserError('投資物不存在。');
        }

        if (App::input('action') === 'fetch') {
            $result = self::applyQuote($id);
            flash($result['ok']
                ? "已更新報價（{$result['as_of']}）。"
                : "更新失敗：{$result['error']}", $result['ok'] ? 'ok' : 'warn');
            self::redirect(App::safeBack('/instruments'));
        }

        $price = App::numInput('manual_price');
        if ($price === null) {
            Database::run('UPDATE instruments SET manual_price = NULL, manual_price_at = NULL WHERE id = ?', [$id]);
            flash('已清除手動價格，改回自動報價。');
        } else {
            if (!is_finite($price) || $price <= 0) {
                throw new UserError('手動價格必須是大於 0 的有限數值。');
            }
            // The date decides whether this price beats the cached quote, so it has to be a real one:
            // an empty date input used to be stored verbatim and silently lose to the quote.
            $rawDate = App::input('manual_date');
            $manualOn = $rawDate === '' ? date('Y-m-d') : self::normaliseDate($rawDate);
            Database::run(
                'UPDATE instruments SET manual_price = ?, manual_price_at = ? WHERE id = ?',
                [$price, $manualOn, $id]
            );
            flash("已設定手動價格 " . fmt_money($price) . "（{$manualOn}）。");
        }

        self::redirect(App::safeBack('/instruments'));
    }

    public static function instrumentDelete(array $p): void
    {
        $id = (int) $p['id'];
        $instrument = Database::one('SELECT symbol FROM instruments WHERE id = ?', [$id]);
        if ($instrument === null) {
            throw new UserError('投資物不存在。');
        }
        $symbol = (string) $instrument['symbol'];
        $txs = (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE instrument_id = ?', [$id], 0);
        $prices = (int) Database::scalar('SELECT COUNT(*) FROM prices WHERE instrument_id = ?', [$id], 0);

        if ($txs > 0) {
            throw new UserError("這個投資物有 {$txs} 筆交易紀錄，資料庫不允許刪除；請先刪除相關交易。");
        }

        $removed = Database::run('DELETE FROM instruments WHERE id = ?', [$id])->rowCount();
        if ($removed !== 1) {
            throw new UserError('投資物不存在或已遭刪除，請重新整理後再試。');
        }
        // 記號是跟著 id 走的，而 id 會被下一個投資物重用（刪掉最大那筆之後 SQLite 就還給你同一個）。
        QuoteService::forgetHistoryMark($id);
        flash("已移除投資物 {$symbol}，連同 {$prices} 筆報價紀錄一併刪除。", 'warn');
        self::redirect('/instruments');
    }

    public static function transactionCreate(): void
    {
        $pid = self::portfolioInput();
        $note = self::textInput('note', 'note');

        $side = strtoupper(App::input('side', 'BUY'));
        if (!in_array($side, ['BUY', 'SELL'], true)) {
            throw new UserError('交易方向只能是買進或賣出。');
        }

        $quantity = App::numInput('quantity');
        $price = App::numInput('price');
        if ($quantity === null || $quantity <= 0) {
            throw new UserError('請輸入大於 0 的張數／股數。');
        }
        if ($price === null || $price < 0) {
            throw new UserError('請輸入成交價。');
        }

        $fee = App::numInput('fee') ?? 0.0;
        if ($fee < 0) {
            throw new UserError('手續費不可為負。');
        }
        $gross = $quantity * $price;
        $settlement = $side === 'BUY' ? $gross + $fee : $gross - $fee;
        if (!is_finite($gross) || !is_finite($settlement)) {
            throw new UserError('成交金額或結算金額超出可計算範圍。');
        }

        $tradedOn = self::normaliseDate(App::input('traded_on', date('Y-m-d')));

        // 「買進順便新建代號」是兩筆寫入：投資物先進、交易才進。中途任何一步不過（賣超檢查、外鍵），
        // 那檔投資物會孤零零留在投資物資料庫裡，沒人買過它。所以兩筆一起成、一起敗。
        // 匯率改在 commit 之後抓：那是一次可能等滿 12 秒的對外要求，不該把它關在寫入鎖裡面。
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $instrument = self::resolveInstrument();

            $held = self::heldQuantity($pid, (int) $instrument['id'], $tradedOn);
            if (!is_finite($held['on']) || !is_finite($held['total'])
                || ($side === 'BUY' && (!is_finite($held['on'] + $quantity) || !is_finite($held['total'] + $quantity)))) {
                throw new UserError('持股數量累計超出可計算範圍。');
            }

            // A sell can only close what the record already holds on its own date: book() caps the closed
            // quantity the same way, so a sale cleared against a later purchase would be stored and then do
            // nothing at all to either the holdings or the cash.
            if ($side === 'SELL') {
                if ($quantity > $held['on']) {
                    if ($quantity <= $held['total']) {
                        throw new UserError(sprintf(
                            '賣出 %s 股在 %s 還沒有那麼多持股，差的部分是之後才買進的；請把日期移到買進之後。',
                            number_format($quantity, 4, '.', ''),
                            $tradedOn
                        ));
                    }

                    throw new UserError(sprintf(
                        '賣出 %s 股超過目前持股 %s 股，請確認股數。',
                        number_format($quantity, 4, '.', ''),
                        number_format($held['total'], 4, '.', '')
                    ));
                }
            }

            Database::run(
                'INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price, fee, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, (int) $instrument['id'], $side, $tradedOn, $quantity, $price, $fee, $note, now_stamp()]
            );

            try {
                Positions::snapshot([$pid]);
            } catch (RuntimeException $e) {
                throw new UserError($e->getMessage(), 0, $e);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        if ($instrument['fresh'] === true) {
            self::ensureFxFor((string) $instrument['currency']);
        }

        flash(sprintf(
            '已紀錄%s %s %s 股 @ %s。',
            $side === 'BUY' ? '買進' : '賣出',
            $instrument['symbol'],
            number_format($quantity, 4, '.', ''),
            number_format($price, 4, '.', '')
        ));

        self::redirect('/transactions');
    }

    public static function transactionDelete(array $p): void
    {
        $id = (int) $p['id'];
        $row = Database::one('SELECT * FROM transactions WHERE id = ?', [$id]);
        if ($row === null) {
            throw new UserError('交易紀錄不存在。');
        }

        Database::run('DELETE FROM transactions WHERE id = ?', [$id]);
        flash("已刪除 {$row['traded_on']} 的 " . ($row['side'] === 'BUY' ? '買進' : '賣出') . " 紀錄。", 'warn');
        self::redirect('/transactions');
    }

    public static function cashCreate(): void
    {
        $pid = self::portfolioInput();
        $note = self::textInput('note', 'note');

        $direction = strtoupper(App::input('direction', 'IN'));
        if (!in_array($direction, ['IN', 'OUT'], true)) {
            throw new UserError('現金方向只能是存入或提取。');
        }

        $amount = App::numInput('amount');
        if ($amount === null || $amount <= 0) {
            throw new UserError('請輸入大於 0 的金額。');
        }

        $currency = self::currencyCode('currency');

        $movedOn = self::normaliseDate(App::input('moved_on', date('Y-m-d')));

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::run(
                'INSERT INTO cash_movements (portfolio_id, moved_on, direction, amount, currency, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$pid, $movedOn, $direction, $amount, $currency, $note, now_stamp()]
            );
            try {
                Positions::snapshot([$pid]);
            } catch (RuntimeException $e) {
                throw new UserError($e->getMessage(), 0, $e);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
        // A deposit in a currency the portfolio has never touched pulls its rate along, the same way a trade does.
        self::ensureFxFor($currency);

        flash(sprintf(
            '已%s %s %s。',
            $direction === 'IN' ? '存入' : '提取',
            $currency,
            number_format($amount, 2, '.', '')
        ));

        self::redirect('/transactions');
    }

    public static function cashDelete(array $p): void
    {
        $id = (int) $p['id'];
        $row = Database::one('SELECT * FROM cash_movements WHERE id = ?', [$id]);
        if ($row === null) {
            throw new UserError('現金紀錄不存在。');
        }

        Database::run('DELETE FROM cash_movements WHERE id = ?', [$id]);
        flash(sprintf(
            '已刪除 %s 的%s %s。',
            $row['moved_on'],
            $row['direction'] === 'IN' ? '存入' : '提取',
            fmt_money((float) $row['amount'], (string) $row['currency'])
        ), 'warn');
        self::redirect('/transactions');
    }

    public static function priceRefresh(): void
    {
        $pid = App::intInput('portfolio_id') ?: null;
        if ($pid !== null && Database::scalar('SELECT COUNT(*) FROM portfolios WHERE id = ?', [$pid], 0) === 0) {
            throw new UserError('找不到要更新的投資組合。');
        }
        $scope = $pid === null ? '全部投資物' : '目前的投資組合';

        $result = QuoteService::refresh($pid);

        $parts = [];
        $parts[] = sprintf('%s更新 %d 檔', $scope, $result['updated']);
        if ($result['failed'] > 0) {
            $parts[] = sprintf('%d 個失敗', $result['failed']);
        }
        if ($result['skipped'] > 0) {
            $parts[] = sprintf('%d 檔略過', $result['skipped']);
        }
        if ($result['history'] > 0) {
            $parts[] = sprintf('補齊回報所需歷史日線 %d 筆', $result['history']);
        }
        if ($result['history_failed'] > 0) {
            $parts[] = sprintf('%d 檔的十年歷史沒補上', $result['history_failed']);
        }
        $parts[] = sprintf('匯率更新 %d 組', $result['fx']['updated']);
        if ($result['fx']['skipped'] > 0) {
            $parts[] = sprintf('匯率略過 %d 組', $result['fx']['skipped']);
        }

        flash(implode('，', $parts) . '。', $result['failed'] > 0 || $result['errors'] !== [] ? 'warn' : 'ok');

        if ($result['errors'] !== []) {
            $_SESSION['flash_detail'] = $result['errors'];
        }
        if ($result['fx']['failed'] !== []) {
            $_SESSION['flash_detail'][] = '匯率未取得：' . implode('、', $result['fx']['failed'])
                . '（可到設定頁手動輸入）';
        }

        self::redirect(App::safeBack('/'));
    }

    public static function settingsSave(): void
    {
        $baseField = App::input('base_currency') === '__other__' ? 'base_currency_custom' : 'base_currency';
        $base = self::currencyCode($baseField);
        $source = App::input('quote_source', 'yahoo');
        if ($source === '') {
            $source = 'yahoo';
        }
        if (!in_array($source, ['yahoo', 'off'], true)) {
            throw new UserError('不支援的報價來源。');
        }

        $token = App::input('quote_token');
        if (preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
            throw new UserError('報價金鑰不可包含換行或其他控制字元。');
        }
        $normalised = QuoteService::normaliseEndpoint(App::input('quote_endpoint'), $token);
        if (!is_array($normalised) || $normalised['ok'] !== true) {
            throw new UserError(is_array($normalised) && is_string($normalised['error'] ?? null)
                ? $normalised['error']
                : '轉接位址要寫成完整的 http:// 或 https:// 網址，不要帶帳號密碼，也不要帶報價路徑以外的內容。');
        }
        $endpoint = (string) ($normalised['endpoint'] ?? '');
        $clearCooldown = App::input('clear_cooldown') === '1';
        $endpointChanged = $endpoint !== QuoteService::endpoint();
        $refreshBudget = trim(App::input('quote_refresh_budget'));
        if ($refreshBudget !== ''
            && (!ctype_digit($refreshBudget) || (int) $refreshBudget < 5 || (int) $refreshBudget > 600)) {
            throw new UserError('網頁報價更新時間上限必須是 5 到 600 的整數秒數。');
        }

        // 全部設定一起提交；全部驗證完才開始寫，任何一步失敗都回到原狀。
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Settings::put('quote_endpoint', $endpoint);
            Settings::put('base_currency', $base);
            Settings::put('quote_source', $source);
            QuoteService::setToken($token);
            Settings::put('quote_refresh_budget_seconds', $refreshBudget);
            if ($endpointChanged || $clearCooldown) {
                QuoteService::clearCooldown();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        flash("已更新設定，組合金額以 {$base} 表達。");
        self::ensureFxFor($base);

        self::redirect('/settings');
    }

    public static function fxSave(): void
    {
        $code = self::currencyCode('currency');
        if ($code === 'USD') {
            throw new UserError('USD 是固定基準（每美元 1.0），不需要存匯率。');
        }

        $rate = App::numInput('units_per_usd');
        if ($rate === null) {
            $previous = Database::one(
                'SELECT units_per_usd, as_of, fetched_at FROM fx_rates WHERE currency = ? AND manual = 1',
                [$code]
            );
            $automatic = false;
            try {
                // 自動來源會跳過 manual=1，所以先刪；finally 保證失敗或例外時把原列恢復。
                if ($previous !== null) {
                    Database::run('DELETE FROM fx_rates WHERE currency = ? AND manual = 1', [$code]);
                }
                $fx = QuoteService::refreshFx([$code], null, App::input('force') === '1');
                $automatic = $fx['failed'] === [];
            } finally {
                if (!$automatic && $previous !== null) {
                    self::restoreManualFx($code, $previous);
                }
            }

            if ($automatic) {
                flash("已自動抓取 {$code} 匯率。");
            } else {
                flash(Settings::get('quote_source', 'yahoo') === 'off'
                    ? "自動抓取目前是關閉的，請先把報價來源切回 Yahoo，或在「每美元」欄填數值。"
                    : "Yahoo 沒有 {$code} 的匯率資料，請在「每美元」欄填數值。", 'warn');
            }
        } else {
            if (!is_finite($rate) || $rate <= 0) {
                throw new UserError('匯率必須是大於 0 的有限數值。');
            }
            $rawAsOf = App::input('as_of');
            $asOf = $rawAsOf === '' ? date('Y-m-d') : self::normaliseDate($rawAsOf);
            self::restoreManualFx($code, [
                'units_per_usd' => $rate,
                'as_of' => $asOf,
                'fetched_at' => now_stamp(),
            ]);
            flash("已設定 {$code} 匯率 " . number_format($rate, 6, '.', '') . "。");
        }

        self::redirect('/settings');
    }

    /** 以 upsert 恢復手動匯率，不清掉同一幣別可能已存在的自動列。 */
    private static function restoreManualFx(string $code, array $row): void
    {
        Database::run(
            "INSERT INTO fx_rates (currency, units_per_usd, as_of, source, manual, fetched_at)
             VALUES (?, ?, ?, 'manual', 1, ?)
             ON CONFLICT(currency) DO UPDATE SET units_per_usd = excluded.units_per_usd,
               as_of = excluded.as_of, source = 'manual', manual = 1, fetched_at = excluded.fetched_at",
            [$code, (float) $row['units_per_usd'], (string) $row['as_of'], (string) $row['fetched_at']]
        );
    }

    public static function fxDelete(): void
    {
        $code = self::currencyCode('currency');
        if ($code === 'USD') {
            throw new UserError('幣別請填 2 到 5 個英文字母；USD 是固定基準，不用存匯率。');
        }

        $removed = Database::run('DELETE FROM fx_rates WHERE currency = ?', [$code])->rowCount();
        $base = Settings::baseCurrency();

        if ($removed === 0) {
            flash("{$code} 沒有匯率資料可刪。", 'warn');
        } elseif ($code === $base) {
            flash("已刪除 {$code} 匯率。記帳幣別就是 {$code}，跨幣別金額與合計會先顯示 --，"
                . '下次按「更新報價與匯率」會自動把它抓回來。', 'warn');
        } else {
            flash("已刪除 {$code} 匯率。該幣別持股的換算金額會顯示 --，直到你補一個匯率。");
        }

        self::redirect('/settings');
    }

    // ------------------------------------------------------- 備份與還原

    public static function backupCreate(): void
    {
        $name = Backup::snapshot('manual');
        flash("已建立備份「{$name}」。超過 " . Backup::KEEP . " 份時會自動刪掉最舊的。");
        self::redirect('/settings');
    }

    public static function backupDownload(array $p): void
    {
        Backup::send($p['name']);
    }

    public static function backupDelete(array $p): void
    {
        Backup::delete($p['name']);
        flash("已刪除備份「{$p['name']}」。");
        self::redirect('/settings');
    }

    public static function backupRestore(): void
    {
        // The browser asks twice (checkbox then confirm); this is the copy that still runs with JS off.
        if (self::rawInput('confirm') !== '1') {
            throw new UserError('請先勾選「我了解這會覆蓋現在的全部資料」。');
        }

        $staged = Backup::stageUpload($_FILES['backup'] ?? []);

        try {
            self::applyRestore($staged);
        } finally {
            // An uploaded file is never left lying around in data/backups where the list would show it.
            @unlink($staged);
        }

        self::redirect('/settings');
    }

    public static function backupRestoreSnapshot(array $p): void
    {
        $name = (string) ($p['name'] ?? '');
        if (!str_starts_with($name, 'pre-restore-')) {
            throw new UserError('只能退回系統自動留下的還原前快照；手動備份請使用設定頁的「上傳備份檔還原」。');
        }
        if (self::rawInput('confirm') !== '1') {
            throw new UserError('請先勾選「我了解這會覆蓋現在的全部資料」。');
        }

        self::applyRestore(Backup::path($name));
        self::redirect('/settings');
    }

    /** @throws RuntimeException with the database left exactly as it was */
    private static function applyRestore(string $path): void
    {
        $done = Backup::restore($path);
        $counts = $done['counts'];

        // The ids now come from the restored file, and a sticky 「所有組合」 view has nothing to do with it.
        unset($_SESSION['portfolio_id'], $_SESSION['dashboard_scope']);

        flash('已還原 ' . (int) $counts['portfolios'] . ' 個投資組合、'
            . (int) $counts['instruments'] . ' 檔投資物、'
            . (int) $counts['transactions'] . ' 筆交易、'
            . (int) $counts['cash_movements'] . ' 筆現金紀錄、'
            . (int) $counts['prices'] . ' 筆報價。'
            . '報價出口與金鑰維持還原前的本機設定，不跟著備檔走。'
            . '覆蓋前的資料存成「' . $done['guard'] . '」，按那一份的「退回」就能換回去。');
    }

    public static function exportCsv(): void
    {
        // The file has to match the table on screen: the same 範圍選單 and the same 只看這檔 filter,
        // read from the query string the 匯出 CSV link builds. Reading only the current portfolio
        // meant a merged view silently handed over one portfolio's trades.
        $requested = App::input('scope');
        $ids = [];
        if ($requested === 'all') {
            $ids = array_map(
                static fn (array $r): int => (int) $r['id'],
                Database::all('SELECT id FROM portfolios ORDER BY id')
            );
        } elseif (ctype_digit($requested)) {
            $ids = [(int) $requested];
        } else {
            // No scope on the link is the fallback for a hand-typed /export: keep the sticky choice.
            $pid = current_portfolio_id();
            $ids = $pid > 0 ? [$pid] : [];
        }

        $instrumentId = App::intInput('instrument');
        $slots = [];
        $params = [];
        foreach ($ids as $n => $id) {
            $slots[] = ':scope' . $n;
            $params['scope' . $n] = $id;
        }
        if ($instrumentId > 0) {
            $params['iid'] = $instrumentId;
        }

        // Nothing in scope is not an error: the header-only file says so in the name and opens empty.
        $sql = 'SELECT t.traded_on, p.name AS portfolio, i.symbol, i.name AS instrument, i.market, i.currency,
                       t.side, t.quantity, t.price, t.fee, (t.quantity * t.price) AS gross, t.note
                FROM transactions t
                JOIN instruments i ON i.id = t.instrument_id
                JOIN portfolios  p ON p.id = t.portfolio_id
                WHERE' . ($slots === [] ? ' 0' : ' t.portfolio_id IN (' . implode(',', $slots) . ')')
            . ($instrumentId > 0 ? ' AND t.instrument_id = :iid' : '');
        if (($from = App::input('from')) !== '') {
            $sql .= ' AND t.traded_on >= :from';
            $params['from'] = $from;
        }
        if (($to = App::input('to')) !== '') {
            $sql .= ' AND t.traded_on <= :to';
            $params['to'] = $to;
        }
        $stmt = Database::run($sql . ' ORDER BY t.traded_on, t.id', $params);

        $filename = 'transactions-' . ($requested === 'all' ? 'all' : (string) ($ids[0] ?? 'none'))
            . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
        fputcsv($out, ['日期', '投資組合', '代號', '名稱', '市場', '幣別', '方向', '股數', '成交價', '手續費', '成交金額', '備註']);
        while (($r = $stmt->fetch()) !== false) {
            fputcsv($out, [
                self::csvCell($r['traded_on']), self::csvCell($r['portfolio']), self::csvCell($r['symbol']),
                self::csvCell($r['instrument']), self::csvCell($r['market']), self::csvCell($r['currency']),
                self::csvCell($r['side'] === 'BUY' ? '買進' : '賣出'),
                self::csvCell($r['quantity']), self::csvCell($r['price']), self::csvCell($r['fee']),
                self::csvCell($r['gross']), self::csvCell($r['note']),
            ]);
        }
        fclose($out);
    }

    /**
     * Excel 會把 =、+、@、減號開頭的儲存格當公式算，所以備註裡填 =HYPERLINK("http://evil/",點我) 匯出後
     * 就是一颗別人點的按鈕。本系統沒有 CSV 匯入，前導一個單引號只會在人工對照時看見，不會被吃回去。
     * 還原過的舊庫或惡意備份可能把公式文字放進 REAL 欄，所以數值欄也一律經過同一層跳脫。
     */
    private static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+@\-\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    /** 先完成編碼再送標頭；資料含無效 UTF-8 時也回傳可解析的 JSON 錯誤。 */
    private static function jsonResponse(mixed $data, int $status = 200): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $status = 500;
            $json = '{"error":"圖表資料無法編製，請稍後再試。"}';
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
    }

    // -------------------------------------------------------------- helpers

    /** @return array{ok:bool, price:?float, as_of:?string, error:?string} */
    private static function applyQuote(int $instrumentId): array
    {
        $inst = Database::one('SELECT * FROM instruments WHERE id = ?', [$instrumentId]);
        if ($inst === null) {
            return ['ok' => false, 'price' => null, 'as_of' => null, 'error' => '投資物不存在'];
        }

        $quote = QuoteService::get((string) $inst['yahoo_symbol']);
        if (!$quote['ok'] || $quote['price'] === null) {
            return ['ok' => false, 'price' => null, 'as_of' => null, 'error' => $quote['error'] ?? '查無資料'];
        }

        $quote['currency'] = self::safeCurrency($quote['currency'] ?? null, (string) $inst['currency']);
        try {
            $currency = QuoteService::writeQuote($instrumentId, $quote);
        } catch (UserError $e) {
            return ['ok' => false, 'price' => null, 'as_of' => null, 'error' => $e->getMessage()];
        }
        QuoteService::refreshFx([$currency]);

        return ['ok' => true, 'price' => $quote['price'], 'as_of' => $quote['as_of'], 'error' => null];
    }

    /** @return array{id:int, symbol:string, currency:string, fresh:bool} */
    private static function resolveInstrument(): array
    {
        $id = App::intInput('instrument_id');
        if ($id > 0) {
            $row = Database::one('SELECT id, symbol FROM instruments WHERE id = ?', [$id]);
            if ($row === null) {
                throw new UserError('選取的投資物不存在。');
            }

            return ['id' => (int) $row['id'], 'symbol' => (string) $row['symbol'], 'currency' => '', 'fresh' => false];
        }

        $symbol = self::textInput('new_symbol', 'symbol');
        $name = self::textInput('new_name', 'name');
        $market = App::input('new_market', 'US');
        if ($symbol === '') {
            throw new UserError('請選擇既有投資物，或輸入新的投資物代號。');
        }
        if (!isset(Markets::MARKETS[$market])) {
            throw new UserError('不支援的市場。');
        }

        $yahoo = Markets::toYahoo($symbol, $market);
        $existing = Database::one('SELECT id, symbol FROM instruments WHERE yahoo_symbol = ?', [$yahoo]);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'symbol' => (string) $existing['symbol'],
                'currency' => '', 'fresh' => false];
        }

        $currency = self::currencyInput('new_currency', Markets::currencyFor($market));
        $assetClass = self::assetClassInput('new_asset_class', Markets::defaultAssetClass($market));

        Database::run(
            'INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency, asset_class, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $symbol,
                $name,
                $market,
                $yahoo,
                $currency,
                $assetClass,
                now_stamp(),
            ]
        );

        return ['id' => (int) Database::pdo()->lastInsertId(), 'symbol' => $symbol,
            'currency' => $currency, 'fresh' => true];
    }

    /**
     * 幣別是換算與刪除確認共用的 key；ISO 代碼與 USDT 這類四位代碼都接受，但只允許 2 到 5 個英文字母。
     */
    private static function currencyInput(string $field, string $default): string
    {
        $code = self::currencyCode($field, false);

        return $code !== '' ? $code : strtoupper($default);
    }

    private static function currencyCode(string $field, bool $required = true): string
    {
        $code = strtoupper(App::input($field));
        if (!$required && $code === '') {
            return '';
        }
        if (preg_match(self::CURRENCY_PATTERN, $code) !== 1) {
            throw new UserError('幣別請填 2 到 5 個英文字母，例如 USD、HKD、USDT。');
        }

        return $code;
    }

    /** 網路回傳的幣別不做猜測或補字，只接受嚴格的大寫格式，否則沿用投資物幣別。 */
    private static function safeCurrency(mixed $fromQuote, string $fallback): string
    {
        return is_string($fromQuote) && preg_match(self::CURRENCY_PATTERN, $fromQuote) === 1
            ? $fromQuote
            : $fallback;
    }

    private static function assetClassInput(string $field, string $default): string
    {
        $value = App::input($field, $default);
        $value = $value === '' ? $default : $value;
        if (!isset(Markets::assetClasses()[$value])) {
            throw new UserError('不支援的資產類別。');
        }

        return $value;
    }

    /** 文字長度以字元計算；代號另限制為可安全用於 Yahoo 代號的列印字元。 */
    private static function textInput(string $field, string $kind): string
    {
        [$label, $limit] = match ($kind) {
            'symbol' => ['代號', 20],
            'name' => ['名稱', 120],
            'notes' => ['備註', 2000],
            'note' => ['備註', 500],
            'description' => ['描述', 200],
            default => throw new RuntimeException('Invalid text input configuration.'),
        };
        $value = $kind === 'symbol' ? strtoupper(App::input($field)) : App::input($field);
        if (mb_strlen($value, 'UTF-8') > $limit) {
            throw new UserError("{$label}不可超過 {$limit} 個字元。");
        }
        if ($kind === 'symbol' && preg_match('/^[A-Z0-9._\-:=|]*$/', $value) !== 1) {
            throw new UserError('代號只能使用英文字母、數字，以及 . _ - : = | 符號。');
        }

        return $value;
    }

    /** 沒有帶 portfolio_id 時沿用目前組合；明確帶了無效編號則不悄悄改選別的組合。 */
    private static function portfolioInput(): int
    {
        $requested = App::input('portfolio_id');
        $pid = $requested === '' ? current_portfolio_id() : App::intInput('portfolio_id');
        if ($pid <= 0) {
            throw new UserError($requested === ''
                ? '請先建立投資組合。'
                : '選取的投資組合不存在，請重新整理後再選。');
        }
        if (Database::scalar('SELECT COUNT(*) FROM portfolios WHERE id = ?', [$pid], 0) == 0) {
            throw new UserError('選取的投資組合不存在，請重新整理後再選。');
        }

        return $pid;
    }

    /**
     * What the trade record holds in total, and what it held as of one particular date.
     *
     * The walk caps every sale exactly like Positions::book() does, and reads trades in the same order,
     * because a purchase booked later cannot cover a sale dated earlier. Rows on that very date do count:
     * a new trade would sort after them by id, so a buy recorded the same day is already sellable.
     *
     * @return array{on: float, total: float}
     */
    private static function heldQuantity(int $portfolioId, int $instrumentId, string $on): array
    {
        $rows = Database::all(
            'SELECT side, quantity, traded_on FROM transactions
             WHERE portfolio_id = :pid AND instrument_id = :iid
             ORDER BY traded_on, id',
            ['pid' => $portfolioId, 'iid' => $instrumentId]
        );

        $qty = 0.0;
        $onDate = null;
        foreach ($rows as $r) {
            if ($onDate === null && (string) $r['traded_on'] > $on) {
                $onDate = $qty;
            }
            $q = (float) $r['quantity'];
            $qty += (string) $r['side'] === 'BUY' ? $q : -min($q, $qty);
        }

        return ['on' => $onDate ?? $qty, 'total' => $qty];
    }

    /**
     * A currency that has just appeared in the portfolio should not need a hand-entered rate:
     * fetch it from the quote source and only fall back to asking the user when Yahoo lacks the pair.
     */
    private static function ensureFxFor(string $currency): void
    {
        $currency = strtoupper($currency);
        if ($currency === '' || $currency === 'USD' || Settings::get('quote_source', 'yahoo') === 'off') {
            return;
        }
        if (Database::scalar('SELECT COUNT(*) FROM fx_rates WHERE currency = ?', [$currency], 0) > 0) {
            return;
        }

        $fx = QuoteService::refreshFx([$currency]);
        if ($fx['failed'] !== []) {
            flash("Yahoo 沒有 {$currency} 的匯率資料，請在設定的匯率欄手動補一筆。", 'warn');

            return;
        }

        flash("已自動抓取 {$currency} 匯率。");
    }

    private static function normaliseDate(string $value): string
    {
        $value = str_replace('/', '-', trim($value));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            $normalised = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $normalised;
            }
        }

        throw new UserError("日期格式不正確：「{$value}」，請使用 YYYY-MM-DD。");
    }

    private static function instrumentsForForm(): array
    {
        return Database::all('SELECT * FROM instruments ORDER BY market, symbol');
    }

    /** @return array<string,string> */
    private static function currencyOptions(): array
    {
        $list = [];
        foreach (Markets::MARKETS as $market) {
            $list[$market['currency']] = $market['currency'];
        }
        foreach (Database::all('SELECT DISTINCT currency FROM instruments') as $r) {
            $list[$r['currency']] = $r['currency'];
        }
        foreach (Database::all('SELECT DISTINCT currency FROM fx_rates') as $r) {
            $list[$r['currency']] = $r['currency'];
        }
        $list['TWD'] = 'TWD';
        $list['USD'] = 'USD';
        $list['HKD'] = 'HKD';
        ksort($list);

        return $list;
    }

    private static function redirectFirst(): never
    {
        self::redirect('/transactions');
    }

    public static function render(string $view, array $data = []): void
    {
        $data['flashes'] = flash_take();
        $data['flash_detail'] = $_SESSION['flash_detail'] ?? null;
        unset($_SESSION['flash_detail']);

        extract($data, EXTR_SKIP);
        require __DIR__ . '/views/' . $view . '.php';
    }

    private static function redirect(string $path, array $query = []): never
    {
        App::redirect($path, $query);
    }
}
