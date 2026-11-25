<?php require __DIR__ . '/_header.php'; ?>

<div class="page-title">
  <h2>總覽</h2>
  <span class="meta">
    <?= $aggregate ? '所有組合合計（' . count($scope_ids) . ' 個）' : e($portfolio['name'] ?? '') ?>
    <?php if (!empty($lastRefresh)): ?>
      ｜上次更新 <?= e($lastRefresh) ?>
    <?php endif; ?>
  </span>
  <?php $scopeAction = url('/'); require __DIR__ . '/_scope_picker.php'; ?>
  <span style="margin-left:auto"><?php require __DIR__ . '/_refreshbar.php'; ?></span>
</div>

<?php require __DIR__ . '/_summary.php'; ?>

<div class="panel">
  <h3>現金與各幣別</h3>
  <?php if ($breakdown['rows'] === []): ?>
    <div class="empty"><?= $aggregate ? '這些組合' : '這個組合' ?>還沒有持股，也沒有現金紀錄。</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th class="left">幣別</th><th>持股市值</th><th>現金餘額</th>
        <th>持股＋現金<br><span class="small muted">(<?= e($breakdown['base']) ?>)</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($breakdown['rows'] as $b): ?>
      <tr>
        <td class="left"><strong><?= e($b['currency']) ?></strong></td>
        <td class="num">
          <?php if ($b['market_value'] !== null): ?>
            <?= e(fmt_money($b['market_value'], $b['currency'])) ?>
          <?php elseif (!empty($b['unpriced'])): ?>
            <span class="loss">缺報價</span>
          <?php else: ?>
            <span class="muted">無持股</span>
          <?php endif; ?>
        </td>
        <td class="num <?= $b['balance'] === null ? '' : e(tone($b['balance'])) ?>">
          <?= $b['balance'] === null ? '<span class="muted">無現金紀錄</span>' : e(fmt_signed($b['balance'], $b['currency'])) ?>
        </td>
        <td class="num"><?= $b['base_value'] === null ? '--' : e(fmt_money($b['base_value'], $breakdown['base'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="left">合計（<?= e($breakdown['base']) ?>）</td>
        <td colspan="2"></td>
        <td class="num"><?= $breakdown['total_base'] === null ? '--' : e(fmt_money($breakdown['total_base'], $breakdown['base'])) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <p class="hint">
    持股市值與現金都以該幣別的<strong>原幣</strong>顯示，港幣持股就看 HKD 那一列；
    最右欄才是換算成記帳幣別後的合計，缺匯率的幣別顯示 <code>--</code>，總計也暫時是 <code>--</code>。
    這一幣別只要有持股還沒抓到報價，市值就顯示<strong>缺報價</strong>而不是少算的合計。
    現金的存入／提取在<a href="<?= e(url('/transactions')) ?>">交易紀錄</a>頁記。
  </p>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>持股明細（含已出清）<?= $aggregate ? '（所有組合合計）' : '' ?></h3>
  <?php require __DIR__ . '/_positions.php'; ?>
  <?php if ($aggregate && $rows !== []): ?>
    <p class="hint">
      這裡 <?= count($rows) ?> 筆是跨組合合併後的股數與加權成本；要看單一組合自己的成本，
      把上面的範圍切過去就好。
    </p>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>最近交易</h3>
  <?php if ($recent === []): ?>
    <div class="empty">還沒有交易紀錄。<a href="<?= e(url('/transactions')) ?>">新增買進 →</a></div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th class="left">日期</th>
        <?php if ($aggregate): ?><th class="left">組合</th><?php endif; ?>
        <th class="left">投資物</th><th>方向</th><th>股數</th><th>成交價</th><th>金額</th><th class="left">備註</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($recent as $t): ?>
      <tr>
        <td class="left"><?= e($t['traded_on']) ?></td>
        <?php if ($aggregate): ?><td class="left small"><?= e($t['portfolio_name']) ?></td><?php endif; ?>
        <td class="left"><strong><?= e($t['symbol']) ?></strong> <span class="small muted"><?= e($t['name']) ?></span></td>
        <td><span class="badge <?= strtolower($t['side']) ?>"><?= $t['side'] === 'BUY' ? '買進' : '賣出' ?></span></td>
        <td class="num"><?= e(rtrim(rtrim(number_format((float) $t['quantity'], 4, '.', ''), '0'), '.')) ?></td>
        <td class="num"><?= e(fmt_price((float) $t['price'], (string) $t['currency'])) ?></td>
        <td class="num"><?= e(fmt_price((float) $t['quantity'] * (float) $t['price'], (string) $t['currency'])) ?></td>
        <td class="left small muted"><?= e($t['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint"><a href="<?= e(url('/transactions')) ?>">全部交易紀錄 →</a></p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
