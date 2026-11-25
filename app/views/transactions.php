<?php require __DIR__ . '/_header.php';
$scopeName = '';
foreach ($portfolios as $p) {
    if ((int) $p['id'] === (int) $pid) {
        $scopeName = (string) $p['name'];
    }
}
?>

<div class="page-title">
  <h2>交易紀錄</h2>
  <span class="meta">買進／賣出與手續費，損益即時重算</span>
  <?php $scopeAction = url('/transactions'); require __DIR__ . '/_scope_picker.php'; ?>
  <?php if ((int) $csv_rows > 0): ?>
    <span style="margin-left:auto"><a href="<?= e($csv_url) ?>" download>匯出 CSV（<?= (int) $csv_rows ?> 筆）</a></span>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>新增一筆</h3>
  <?php if ($portfolios === []): ?>
    <div class="empty">請先到<a href="<?= e(url('/portfolios')) ?>">投資組合</a>建立一個組合。</div>
  <?php else: ?>
  <form method="post" action="<?= e(url('/transactions')) ?>">
    <?= csrf_field() ?>
    <div class="grid-forms">
      <div>
        <div class="grid-forms" style="grid-template-columns:1fr 1fr">
          <label class="field">投資組合
            <select name="portfolio_id">
              <?php foreach ($portfolios as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $pid ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">方向
            <select name="side">
              <option value="BUY">買進</option>
              <option value="SELL">賣出</option>
            </select>
          </label>
          <label class="field">成交日期
            <input type="date" name="traded_on" value="<?= e(date('Y-m-d')) ?>" required>
          </label>
          <label class="field">股數／口數
            <input type="number" name="quantity" step="any" min="0.0001" placeholder="1000" required>
          </label>
          <label class="field">成交價
            <input type="number" name="price" step="any" min="0" placeholder="612.5" required>
          </label>
          <label class="field">手續費／交易成本
            <input type="number" name="fee" step="any" min="0" value="0">
          </label>
        </div>
        <label class="field" style="margin-top:10px">備註
          <input type="text" name="note" maxlength="200" placeholder="例如：定期定額、除息再投入">
        </label>
      </div>

      <div>
        <label class="field">投資物（既有）
          <select name="instrument_id">
            <option value="">— 選擇已有投資物 —</option>
            <?php foreach ($instruments as $i): ?>
              <option value="<?= (int) $i['id'] ?>"><?= e($i['symbol'] . '　' . ($i['name'] !== '' ? $i['name'] : \App\Markets::label((string) $i['market'])) . '（' . $i['currency'] . '）') ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <details style="margin-top:10px">
          <summary class="small muted">或同時建立新投資物（上面選「—」時填寫）</summary>
          <div class="grid-forms" style="grid-template-columns:1fr 1fr; margin-top:10px">
            <label class="field">投資物代號
              <input type="text" name="new_symbol" placeholder="2330 / AAPL / 0700 / BTC" style="text-transform:uppercase">
            </label>
            <label class="field">市場
              <select name="new_market">
                <?php foreach ($markets as $code => $m): ?>
                  <option value="<?= e($code) ?>"><?= e($m['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field">名稱（可留空，抓價時自動帶入）
              <input type="text" name="new_name" placeholder="台積電">
            </label>
            <label class="field">計價幣別
              <input type="text" name="new_currency" maxlength="5" placeholder="TWD">
            </label>
          </div>
        </details>

        <div style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap">
          <button type="submit">存入紀錄</button>
        </div>
      </div>
    </div>
  </form>
  <p class="hint">
    代號會依市場自動組出 Yahoo symbol（台股 <code>2330</code> → <code>2330.TW</code>、港股 <code>700</code> → <code>0700.HK</code>）。
    賣出股數超過現有持股時會被擋下，避免平均成本算負。
  </p>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>歷史成交</h3>
  <form method="get" action="<?= e(url('/transactions')) ?>" class="inline" style="margin-bottom:14px">
    <label class="field">投資物
      <select name="instrument">
        <option value="0">全部</option>
        <?php foreach ($instruments as $i): ?>
          <option value="<?= (int) $i['id'] ?>" <?= (int) $filters['instrument'] === (int) $i['id'] ? 'selected' : '' ?>>
            <?= e($i['symbol']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">起
      <input type="date" name="from" value="<?= e($filters['from']) ?>">
    </label>
    <label class="field">迄
      <input type="date" name="to" value="<?= e($filters['to']) ?>">
    </label>
    <button type="submit" class="ghost">篩選</button>
  </form>

  <?php
  $filterQuery = array_filter([
      'scope' => $aggregate ? 'all' : $pid,
      'instrument' => (int) $filters['instrument'] > 0 ? (int) $filters['instrument'] : null,
      'from' => $filters['from'] !== '' ? $filters['from'] : null,
      'to' => $filters['to'] !== '' ? $filters['to'] : null,
  ], static fn (mixed $v): bool => $v !== null && $v !== '');
  $txPageUrl = static function (int $page) use ($filterQuery, $cash_page): string {
      return url('/transactions') . '?' . http_build_query($filterQuery + ['cash_page' => (int) $cash_page, 'page' => $page]);
  };
  ?>

  <?php if ($txs === []): ?>
    <div class="empty">符合條件的交易為空。</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th class="left">日期</th><?php if ($aggregate): ?><th class="left">組合</th><?php endif; ?><th class="left">投資物</th><th>方向</th><th>股數</th>
        <th>成交價</th><th>手續費</th><th>成交金額</th><th class="left">備註</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($txs as $t): ?>
      <tr>
        <td class="left"><?= e($t['traded_on']) ?></td>
        <?php if ($aggregate): ?><td class="left small"><?= e($t['portfolio_name']) ?></td><?php endif; ?>
        <td class="left">
          <strong><?= e($t['symbol']) ?></strong> <span class="small muted"><?= e($t['name']) ?></span>
          <span class="badge"><?= e($t['currency']) ?></span>
        </td>
        <td><span class="badge <?= strtolower($t['side']) ?>"><?= $t['side'] === 'BUY' ? '買進' : '賣出' ?></span></td>
        <td class="num"><?= e(rtrim(rtrim(number_format((float) $t['quantity'], 4, '.', ''), '0'), '.')) ?></td>
        <td class="num"><?= e(fmt_price((float) $t['price'], (string) $t['currency'])) ?></td>
        <td class="num"><?= e(fmt_money((float) $t['fee'], (string) $t['currency'])) ?></td>
        <td class="num"><?= e(fmt_price((float) $t['quantity'] * (float) $t['price'], (string) $t['currency'])) ?></td>
        <td class="left small muted"><?= e($t['note']) ?></td>
        <td>
          <form method="post" action="<?= e(url('/transactions/' . (int) $t['id'] . '/delete')) ?>"
                onsubmit="return confirm('確定刪除這筆交易？損益會一併重算。')">
            <?= csrf_field() ?>
            <button class="tiny danger" type="submit">刪除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <p class="hint">
    共 <?= (int) $tx_count ?> 筆<?= $aggregate ? '（所有組合合計）' : '' ?>，每頁 100 筆。
    本頁顯示 <?= $txs === [] ? '0' : (($tx_page - 1) * 100 + 1) . '–' . min((int) $tx_count, $tx_page * 100) ?> 筆。
  </p>

  <?php if ((int) $tx_pages > 1): ?>
    <nav class="inline" aria-label="交易紀錄分頁" style="margin-top:14px">
      <?php if ((int) $tx_page > 1): ?>
        <a class="ghost" rel="prev" href="<?= e($txPageUrl((int) $tx_page - 1)) ?>">上一頁</a>
      <?php endif; ?>
      <span class="small muted" aria-current="page">第 <?= (int) $tx_page ?>／<?= (int) $tx_pages ?> 頁</span>
      <?php if ((int) $tx_page < (int) $tx_pages): ?>
        <a class="ghost" rel="next" href="<?= e($txPageUrl((int) $tx_page + 1)) ?>">下一頁</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</div>

<?php if ($pid > 0): ?>
<div class="panel">
  <h3>現金</h3>
  <?php if ($aggregate && $scopeName !== ''): ?>
    <p class="hint">上面是 <?= count($scope_ids) ?> 個組合合計的交易；現金與下面的存入／提取只屬於<strong><?= e($scopeName) ?></strong>，要看別的組合就把範圍切過去。</p>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/cash')) ?>" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="portfolio_id" value="<?= (int) $pid ?>">
    <label class="field">存入／提取
      <select name="direction">
        <option value="IN">存入</option>
        <option value="OUT">提取</option>
      </select>
    </label>
    <label class="field">金額
      <input type="number" name="amount" step="any" min="0.01" placeholder="50000" required>
    </label>
    <label class="field">幣別
      <input type="text" name="currency" maxlength="5" value="<?= e($cash['base']) ?>" required>
    </label>
    <label class="field">日期
      <input type="date" name="moved_on" value="<?= e(date('Y-m-d')) ?>" required>
    </label>
    <label class="field">備註
      <input type="text" name="note" maxlength="200" placeholder="例如：銀行轉入、券商出金">
    </label>
    <button type="submit">記一筆</button>
  </form>

  <div class="table-wrap" style="margin-top:14px">
    <table>
      <thead>
        <tr><th class="left">幣別</th><th>存入合計</th><th>提取合計</th><th>買進支出</th><th>賣出收入</th><th>現金餘額</th><th>換算（<?= e($cash['base']) ?>）</th></tr>
      </thead>
      <?php if ($cash['rows'] === []): ?>
        <tbody><tr><td class="left muted" colspan="7">目前還沒有任何現金紀錄。</td></tr></tbody>
      <?php else: ?>
        <tbody>
        <?php foreach ($cash['rows'] as $c): ?>
          <tr>
            <td class="left"><strong><?= e($c['currency']) ?></strong></td>
            <td class="num"><?= e(fmt_money($c['deposit_in'], $c['currency'])) ?></td>
            <td class="num"><?= e(fmt_money($c['withdraw_out'], $c['currency'])) ?></td>
            <td class="num"><?= e(fmt_money($c['trade_out'], $c['currency'])) ?></td>
            <td class="num"><?= e(fmt_money($c['trade_in'], $c['currency'])) ?></td>
            <td class="num <?= e(tone($c['balance'])) ?>"><?= e(fmt_signed($c['balance'], $c['currency'])) ?></td>
            <td class="num"><?= $c['base_ok'] ? e(fmt_money((float) $c['base_value'], $cash['base'])) : '<span class="muted">--</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="left">合計（<?= e($cash['base']) ?>）</td>
            <td colspan="5"></td>
            <td class="num"><?= $cash['total_base'] === null ? '--' : e(fmt_money($cash['total_base'], $cash['base'])) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php if ($cash_logs !== []): ?>
  <p class="hint" style="margin-top:14px">現金流水：共 <?= (int) $cash_count ?> 筆，每頁 100 筆。</p>
  <div class="table-wrap" style="margin-top:14px">
    <table>
      <thead>
        <tr><th class="left">日期</th><th>方向</th><th>金額</th><th class="left">備註</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($cash_logs as $m): ?>
        <tr>
          <td class="left"><?= e($m['moved_on']) ?></td>
          <td><span class="badge <?= $m['direction'] === 'IN' ? 'buy' : 'sell' ?>"><?= $m['direction'] === 'IN' ? '存入' : '提取' ?></span></td>
          <td class="num"><?= e(fmt_money((float) $m['amount'], (string) $m['currency'])) ?></td>
          <td class="left small muted"><?= e($m['note']) ?></td>
          <td>
            <form method="post" action="<?= e(url('/cash/' . (int) $m['id'] . '/delete')) ?>"
                  onsubmit="return confirm('確定刪除這筆現金紀錄？餘額會一併重算。')">
              <?= csrf_field() ?>
              <button class="tiny danger" type="submit">刪除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ((int) $cash_pages > 1): ?>
    <?php
    $cashPageUrl = static function (int $page) use ($filterQuery, $tx_page): string {
        return url('/transactions') . '?' . http_build_query($filterQuery + ['page' => (int) $tx_page, 'cash_page' => $page]);
    };
    ?>
    <nav class="inline" aria-label="現金紀錄分頁" style="margin-top:14px">
      <?php if ((int) $cash_page > 1): ?>
        <a class="ghost" href="<?= e($cashPageUrl((int) $cash_page - 1)) ?>">上一頁</a>
      <?php endif; ?>
      <span class="small muted">第 <?= (int) $cash_page ?>／<?= (int) $cash_pages ?> 頁</span>
      <?php if ((int) $cash_page < (int) $cash_pages): ?>
        <a class="ghost" href="<?= e($cashPageUrl((int) $cash_page + 1)) ?>">下一頁</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

  <p class="hint">
    買進會依「股數 × 成交價 + 手續費」扣現金，賣出會依「股數 × 成交價 − 手續費」加現金，
    改價或刪除紀錄時餘額自動跟隨，不需要另外平帳。
    餘額為負代表這筆買進沒有相對應的存入，不影響持股與損益的計算。缺匯率的幣別換算欄顯示 <code>--</code>。
  </p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
