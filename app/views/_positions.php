<?php
/** @var array $rows */
/** @var array $summary */
$multiCurrency = count($summary['currencies']) > 1 || ($summary['currencies'][0] ?? '') !== $summary['base'];
?>
<?php if ($rows === []): ?>
  <div class="empty">
    <?= !empty($aggregate) ? '這些投資組合還沒有交易紀錄。<br>' : '這個投資組合還沒有交易紀錄。<br>' ?>
    <a href="<?= e(url('/transactions')) ?>">＋ 新增第一筆買進</a>
  </div>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead>
  <tr>
    <th class="left">投資物</th>
    <th>股數</th>
    <th>平均成本</th>
    <th>最新價</th>
    <th>市值</th>
    <th>未實現損益</th>
    <th>已實現損益</th>
    <th>合計損益<br><span class="small muted">(<?= e($summary['base']) ?>)</span></th>
    <th>佔比</th>
    <th class="left">報價</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      /** @var array $inst */
      $inst = $r['instrument'];
      $cur = $r['native'];
  ?>
    <tr class="<?= $r['is_open'] ? '' : 'closed' ?>">
      <td class="left">
        <a href="<?= e(\App\Markets::quoteUrl((string) $inst['yahoo_symbol'])) ?>"
           target="_blank" rel="noopener noreferrer" title="到 Yahoo Finance 檢視"><strong><?= e($inst['symbol']) ?></strong></a>
        <?php if ($inst['name'] !== ''): ?>
          <div class="small muted"><?= e($inst['name']) ?></div>
        <?php endif; ?>
        <span class="badge"><?= e(\App\Markets::label((string) $inst['market'])) ?></span>
        <span class="badge"><?= e($cur) ?></span>
        <?php if (!$r['is_open']): ?><span class="badge">已出清</span><?php endif; ?>
        <?php if (!empty($aggregate) && (int) $r['portfolio_count'] > 1): ?>
          <span class="badge"><?= (int) $r['portfolio_count'] ?> 個組合</span>
        <?php endif; ?>
        <?php if ($r['oversold'] > 0): ?><span class="badge manual">賣超 <?= (int) $r['oversold'] ?> 次</span><?php endif; ?>
      </td>
      <td class="num"><?= e(rtrim(rtrim(number_format($r['qty'], 4, '.', ''), '0'), '.')) ?></td>
      <td class="num"><?= $r['is_open'] ? e(fmt_price($r['avg_cost'], $cur)) : '--' ?></td>
      <td class="num">
        <?= $r['last_price'] === null ? '<span class="loss">無</span>' : e(fmt_price($r['last_price'], $cur)) ?>
        <?php if ($r['day_change_pct'] !== null): ?>
          <div class="small <?= e(tone($r['day_change_pct'])) ?>"><?= e(fmt_pct($r['day_change_pct'])) ?></div>
        <?php endif; ?>
      </td>
      <td class="num">
        <?= $r['market_value'] === null ? '--' : e(fmt_money($r['market_value'], $cur)) ?>
        <?php if ($multiCurrency && $r['market_value'] !== null): ?>
          <div class="small muted">≈ <?= e(fmt_money($r['base_market_value'], $summary['base'])) ?></div>
        <?php endif; ?>
      </td>
      <td class="num <?= e(tone($r['unrealized'])) ?>">
        <?php if ($r['unrealized'] === null): ?>
          --
        <?php else: ?>
          <?= e(fmt_signed($r['unrealized'], $cur)) ?>
          <div class="small"><?= e(fmt_pct($r['unrealized_pct'])) ?></div>
        <?php endif; ?>
      </td>
      <td class="num <?= e(tone($r['realized'])) ?>"><?= e(fmt_signed($r['realized'], $cur)) ?></td>
      <td class="num <?= e(tone($r['base_total_gain'])) ?>">
        <?= e(fmt_signed($r['base_total_gain'], $summary['base'])) ?>
      </td>
      <td class="num"><?= isset($r['weight_pct']) ? e(number_format($r['weight_pct'], 1)) . '%' : '--' ?></td>
      <td class="left small">
        <?php if ($r['price_source'] === 'manual'): ?>
          <span class="badge manual">手動 <?= e($r['price_as_of']) ?></span>
        <?php elseif ($r['price_source'] === 'quote'): ?>
          <span class="muted"><?= e($r['price_as_of']) ?></span>
        <?php else: ?>
          <span class="badge manual">待填價</span>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/instruments/' . (int) $r['id'] . '/price')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="/">
          <button class="tiny ghost" type="submit" name="action" value="fetch">抓取</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td class="left">合計（<?= e($summary['base']) ?>）</td>
      <td></td>
      <td class="num"><?= e(fmt_money($summary['cost_basis'], $summary['base'])) ?></td>
      <td></td>
      <td class="num"><?= e(fmt_money($summary['market_value'], $summary['base'])) ?></td>
      <td class="num <?= e(tone($summary['unrealized'])) ?>"><?= e(fmt_signed($summary['unrealized'], $summary['base'])) ?></td>
      <td class="num <?= e(tone($summary['realized'])) ?>"><?= e(fmt_signed($summary['realized'], $summary['base'])) ?></td>
      <td class="num <?= e(tone($summary['total_gain'])) ?>"><?= e(fmt_signed($summary['total_gain'], $summary['base'])) ?></td>
      <?php
      // 這一列只要有任何一把在手的持股沒被列進佔比（缺價、或缺匯率換不了），個別儲存的都是留白，
      // 合計就不能再單獨宣稱 100%；全出清時分母是 0，同樣沒有「佔多少」可言。
      $held = array_filter($rows, static fn (array $r): bool => $r['is_open']);
      $weighted = array_filter($held, static fn (array $r): bool => isset($r['weight_pct']));
      ?>
      <td class="num"><?= ($held !== [] && count($weighted) === count($held)) ? '100%' : '--' ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>
</div>
<p class="hint">
  平均成本＝（買進金額＋手續費）÷ 股數；賣出時以當時平均成本結轉到「已實現損益」，因此手續費已一併反映在報酬率裡。
  跨幣別持有時，市值以 fx 匯率換算成 <?= e($summary['base']) ?>；匯率可在<a href="<?= e(url('/settings')) ?>">設定</a>手動覆蓋。
</p>
<?php endif; ?>
