<?php require __DIR__ . '/_header.php';
$m = $report['meta'];
$scopeName = $aggregate
    ? '所有組合合計（' . count($scope_ids) . ' 個）'
    : (string) ($portfolio['name'] ?? '');
$note = (string) $m['note'];
?>

<div class="page-title">
  <h2>回報</h2>
  <span class="meta">
    <?= e($scopeName) ?>
    <?php if ($m['end'] !== null): ?>
      ｜資料自 <?= e($m['start']) ?> 至 <?= e($m['end']) ?>（<?= (int) $m['points'] ?> 個資料點）
    <?php endif; ?>
    <?php if (!empty($lastRefresh)): ?>
      ｜上次更新 <?= e($lastRefresh) ?>
    <?php endif; ?>
  </span>
  <?php $scopeAction = url('/reports'); require __DIR__ . '/_scope_picker.php'; ?>
  <span style="margin-left:auto"><?php require __DIR__ . '/_refreshbar.php'; ?></span>
</div>

<?php if (!empty($m['stale'])): ?>
  <div class="panel">
    <p class="hint" style="margin-top:0">
      資料已計算到 <?= e($m['end']) ?>，但最後有效報價是 <?= e($m['price_end'] ?? '--') ?>；
      之後的現金與交易事件已列入，持股只沿用最後已知價，不代表當日市值。
    </p>
  </div>
<?php endif; ?>

<?php if ($m['missing_fx'] !== []): ?>
  <div class="panel">
    <div class="empty">
      缺 <?= e(implode('、', $m['missing_fx'])) ?> 的匯率，報酬無法換算成 <?= e($m['base']) ?>。<br>
      <a href="<?= e(url('/settings')) ?>">到設定補匯率 →</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($m['missing_price'] !== []): ?>
  <div class="panel">
    <div class="empty">
      以下投資物在 <?= e($m['start'] ?? '近十年') ?> 之後查不到日線，未列入報酬計算：
      <?= e(implode('、', $m['missing_price'])) ?>。
      <?php if ($note === ''): ?>按「更新報價與匯率」可補齊（第一次會多抓一些時間）。<?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <h3>期間表現</h3>
  <?php if ($report['periods'] === []): ?>
    <div class="empty"><?= e($note === '' ? '尚無可計算的資料。' : $note) ?></div>
  <?php else: ?>
    <p class="hint">
      期末（<?= e($m['end']) ?>）持股
      <strong><?= e(($m['end_complete'] ?? true) ? fmt_money($m['end_holdings'], $m['base']) : '--') ?></strong>
      ＋現金 <strong><?= e(fmt_money($m['end_cash'], $m['base'])) ?></strong>
      ＝總值 <strong><?= e(($m['end_complete'] ?? true) ? fmt_money($m['end_total'], $m['base']) : '--') ?></strong>
      <?php if (!($m['end_complete'] ?? true)): ?>（有持股缺價，總值不完整）<?php endif; ?>
    </p>
    <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th class="left">期間</th><th>起點</th><th>期末</th>
        <th>持股</th><th>持股＋現金</th><th class="left">備註</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($report['periods'] as $r): ?>
        <tr>
          <td class="left"><strong><?= e($r['label']) ?></strong></td>
          <td class="small muted"><?= e($r['start'] ?? '--') ?></td>
          <td class="small muted"><?= e($r['end']) ?></td>
          <td class="num <?= e(tone($r['holdings_pct'])) ?>"><?= e(fmt_pct($r['holdings_pct'])) ?></td>
          <td class="num <?= e(tone($r['total_pct'])) ?>"><?= e(fmt_pct($r['total_pct'])) ?></td>
          <td class="left small muted"><?= e($r['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>每年度表現</h3>
  <?php if ($report['years'] === []): ?>
    <div class="empty"><?= e($note === '' ? '尚無可計算的資料。' : $note) ?></div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th class="left">年度</th><th>起點</th><th>期末</th>
        <th>持股</th><th>現金</th><th>持股＋現金</th>
        <th>期末總值<br><span class="small muted">(<?= e($m['base']) ?>)</span></th>
        <th class="left">備註</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($report['years'] as $r): ?>
        <tr>
          <td class="left"><strong><?= e($r['label']) ?></strong></td>
          <td class="small muted"><?= e($r['start'] ?? '--') ?></td>
          <td class="small muted"><?= e($r['end']) ?></td>
          <td class="num <?= e(tone($r['holdings_pct'])) ?>"><?= e(fmt_pct($r['holdings_pct'])) ?></td>
          <td class="num <?= e(tone($r['cash_pct'])) ?>"><?= e(fmt_pct($r['cash_pct'])) ?></td>
          <td class="num <?= e(tone($r['total_pct'])) ?>"><?= e(fmt_pct($r['total_pct'])) ?></td>
          <td class="num"><?= e(($r['complete'] ?? true) ? fmt_money((float) $r['holdings_end'] + (float) $r['cash_end'], $m['base']) : '--') ?></td>
          <td class="left small muted"><?= e($r['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>怎麼算</h3>
  <p class="hint">
    <strong>持股</strong>＝每天以「當日股數 × 當日收盤」計市值，遇到買賣的那一天才用成交金額切段，
    所以加碼買進不會被當成報酬，賣出也不會；各段再用時間加權（chain-linking）串起來。<br>
    <strong>現金</strong>＝現金餘額單獨的帳戶報酬；買賣只當成本金進出而剔除，<strong>存入視為正報酬、提取視為負報酬</strong>，
    手續費也會反映在現金報酬。零現金且沒有手續費的區段視為 0%，現金轉負或無法計價時顯示 <code>--</code>。<br>
    <strong>持股＋現金</strong>＝直接看持股市值加現金餘額的總值變化，<strong>存入／提取全額計入</strong>，不再剔除本金；
    所以這一欄是含本金變動的帳戶報酬，不是純投資的時間加權報酬。只需期初與期末可計價，中間缺價不影響這一欄；
    手續費與未賣出的已實現損益都已反映在現金與市值裡。<br>
    各欄都是<strong>固定匯率</strong>下的表現：匯率表只存最新一筆，歷史匯率無從取得，
    所以跨幣別持有看到的報酬不含匯率漲跌；歷史股價使用該日保存的幣別，交易使用成交時保存的幣別。<br>
    最後報價日之後的現金與交易事件仍會列入，持股沿用最後已知價並標示報價陳舊；純現金組合也能計算。
    存入／提取只影響現金與持股＋現金，不影響持股 TWR；若把現金股利記成「存入」，也會被當成正報酬計入。
    回報與市值一律採用未調整收盤（<code>close</code>），與成交價保持同一口徑；策略頁才使用含股利的 <code>adj_close</code>。<br>
    120 個月與較早的年度需要十年歷史，第一次按「更新報價與匯率」會多抓一點時間，之後只補每日收盤。
  </p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
