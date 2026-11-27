<?php require __DIR__ . '/_header.php'; ?>

<div class="page-title">
  <h2>投資組合</h2>
  <span class="meta">每個組合各自計算持股與損益，投資物資料庫共用以避免同一個投資物重複抓價
    <?= !empty($aggregate) ? '｜總覽範圍：所有組合' : '' ?>
  </span>
</div>

<div class="panel">
  <h3>建立新組合</h3>
  <form method="post" action="<?= e(url('/portfolios')) ?>" class="inline">
    <?= csrf_field() ?>
    <label class="field">名稱 *
      <input type="text" name="name" maxlength="60" placeholder="例如：長期持有、台指期對沖" required>
    </label>
    <label class="field" style="flex:1">描述
      <input type="text" name="description" maxlength="200" placeholder="可留空">
    </label>
    <button type="submit">建立</button>
  </form>
</div>

<div class="panel">
  <h3>現有組合</h3>
  <?php if ($portfolios === []): ?>
    <div class="empty">還沒有投資組合，用上面的表單建立第一個。</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th class="left">名稱</th><th class="left">描述</th><th>交易筆數</th><th class="portfolio-status">狀態</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($portfolios as $p): $isCurrent = (int) $p['id'] === (int) $active; $formId = 'pf' . (int) $p['id']; ?>
      <tr>
        <td class="left">
          <input type="text" name="name" value="<?= e($p['name']) ?>" form="<?= e($formId) ?>"
                 maxlength="60" required aria-label="組合名稱" style="width:150px">
        </td>
        <td class="left">
          <input type="text" name="description" value="<?= e($p['description']) ?>" form="<?= e($formId) ?>"
                 maxlength="200" placeholder="可留空" aria-label="組合描述" style="width:190px">
        </td>
        <td class="num"><?= (int) $p['stats']['txs'] ?></td>
        <td class="portfolio-status"><?= $isCurrent ? '<span class="badge buy">使用中</span>' : '' ?></td>
        <td>
          <div class="row-actions portfolio-actions">
            <form id="<?= e($formId) ?>" method="post" action="<?= e(url('/portfolios/' . (int) $p['id'] . '/update')) ?>">
              <?= csrf_field() ?>
              <button class="tiny ghost" type="submit">儲存修改</button>
            </form>
            <?php if (!$isCurrent): ?>
              <form method="post" action="<?= e(url('/portfolios/' . (int) $p['id'] . '/select')) ?>">
                <?= csrf_field() ?>
                <button class="tiny ghost" type="submit">切換過來</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/portfolios/' . (int) $p['id'] . '/delete')) ?>"
                  onsubmit="return confirm(<?= e(js('刪除「' . $p['name'] . '」會連同它的交易與現金紀錄一起移除，資料筆數多時建議先備份。')) ?>)">
              <?= csrf_field() ?>
              <?php if ((int) $p['stats']['txs'] > 0 || (int) $p['stats']['cash'] > 0): ?>
                <input type="text" name="confirm_name" placeholder="輸入名稱確認" style="width:130px" required>
              <?php endif; ?>
              <button class="tiny danger" type="submit">刪除</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">直接修改名稱或描述欄，按「儲存修改」即更新；名稱與其他組合重複會被擋下。刪除仍要輸入完整名稱確認。</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
