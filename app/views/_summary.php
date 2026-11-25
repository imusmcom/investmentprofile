<?php
/** @var array $summary */
$s = $summary;
$base = $s['base'];
$native = $s['native_totals'];

// 佔比只列在「組合市值」這一張卡上，分母是持股市值＋現金，兩邊都得先換算成記帳幣別。
// 直接拿市值除以分母（而不是 100 減現金佔比），頁面顯示的兩位才不會因四捨五入差 0.1。
// 任一邊缺價或缺匯率時不顯示這個比例，免得一個偏小的分母把百分比抬高。
$whole = null;
if (isset($cash) && $cash['complete'] && $s['complete'] && $cash['total_base'] !== null) {
    $sum = (float) $s['market_value'] + (float) $cash['total_base'];
    if ($sum > 0.0) {
        $whole = $sum;
    }
}
$share = static fn (float $p): string => sprintf('佔整個投資組合 %s', number_format($p, 1) . '%');
$holdingsPosition = !isset($cash)
    ? ''
    : ($whole === null
        ? '<span class="muted">佔比需等持股市值與匯率齊全</span>'
        : e($share((float) $s['market_value'] / $whole * 100.0)));
?>
<div class="cards">
  <div class="card">
    <div class="label">組合市值<?= $native ? '（' . e($native['currency']) . '）' : '（' . e($base) . '）' ?></div>
    <div class="value"><?= e(fmt_money($native ? $native['market_value'] : $s['market_value'], $native ? $native['currency'] : $base)) ?></div>
    <div class="sub">
      <?php if ($holdingsPosition !== ''): ?>
        <?= $holdingsPosition ?><br>
      <?php endif; ?>
      <?= $native ? '約 ' . e(fmt_money($s['market_value'], $base)) : '投資成本 ' . e(fmt_money($s['cost_basis'], $base)) ?>
    </div>
  </div>

  <?php if (isset($cash)): ?>
  <div class="card">
    <div class="label">現金（<?= e($cash['base']) ?>）</div>
    <div class="value <?= $cash['total_base'] !== null && $cash['total_base'] < 0 ? 'loss' : '' ?>">
      <?= $cash['total_base'] === null ? '--' : e(fmt_money($cash['total_base'], $cash['base'])) ?>
    </div>
    <div class="sub">
      <?php if ($cash['rows'] === []): ?>
        <a href="<?= e(url('/transactions')) ?>">還沒有現金紀錄，記一筆 →</a>
      <?php elseif (!$cash['complete']): ?>
        <span class="loss">缺匯率，合計無法換算</span>
      <?php else: ?>
        <?= e(implode('　', array_map(
            static fn (array $r): string => fmt_signed($r['balance'], $r['currency']),
            $cash['rows']
        ))) ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="label">未實現損益</div>
    <div class="value <?= e(tone($s['unrealized'])) ?>"><?= e(fmt_signed($s['unrealized'], $base)) ?></div>
    <div class="sub <?= e(tone($s['unrealized'])) ?>">
      <?= $s['unrealized'] !== null && $s['cost_basis'] > 0 ? e(fmt_pct($s['unrealized'] / $s['cost_basis'] * 100)) : '--' ?>
    </div>
  </div>

  <div class="card">
    <div class="label">已實現損益</div>
    <div class="value <?= e(tone($s['realized'])) ?>"><?= e(fmt_signed($s['realized'], $base)) ?></div>
    <div class="sub">含賣出手續費，探加權平均成本法</div>
  </div>

  <div class="card">
    <div class="label">合計損益</div>
    <div class="value <?= e(tone($s['total_gain'])) ?>"><?= e(fmt_signed($s['total_gain'], $base)) ?></div>
    <div class="sub">未實現 ＋ 已實現</div>
  </div>

  <div class="card">
    <div class="label">持股／已出清</div>
    <div class="value"><?= (int) $s['open_positions'] ?> <span class="muted small">/ <?= (int) $s['closed_positions'] ?></span></div>
    <div class="sub">
      <?php
      // 兩個理由可能同時成立（缺價又缺匯率），只報其中一個的話，另一個就變成沒有解釋的 --。
      $gaps = [];
      if (!empty($s['missing_price'])) {
          $gaps[] = count($s['missing_price']) . ' 個缺價';
      }
      if (!empty($s['missing_fx'])) {
          $gaps[] = '缺匯率：' . implode('、', $s['missing_fx']);
      }
      ?>
      <?php if ($gaps !== []): ?>
        <span class="loss"><?= e(implode('　', $gaps)) ?></span>
      <?php else: ?>
        資料完整
      <?php endif; ?>
    </div>
  </div>
</div>
