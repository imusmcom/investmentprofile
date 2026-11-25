<?php require __DIR__ . '/_header.php'; ?>

<div class="page-title">
  <h2>投資物資料庫</h2>
  <span class="meta">這裡管理代號、幣別與手動價格；只有出現在交易紀錄的投資物才會計入總覽的持股明細</span>
  <span style="margin-left:auto"><?php $pid = 0; require __DIR__ . '/_refreshbar.php'; ?></span>
</div>

<div class="panel">
  <h3>新增投資物</h3>
  <form method="post" action="<?= e(url('/instruments')) ?>">
    <?= csrf_field() ?>
    <div class="instrument-form-grid">
      <label class="field">投資物代號 *
        <input type="text" name="symbol" placeholder="2330 / AAPL / 0700 / BTC" required style="text-transform:uppercase">
      </label>
      <label class="field">市場
        <select name="market">
          <?php foreach ($markets as $code => $m): ?>
            <option value="<?= e($code) ?>"><?= e($m['label']) ?><?= $code === 'RAW' ? '' : '（' . e($m['currency']) . '）' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">幣別
        <input type="text" name="currency" maxlength="5" placeholder="依市場自動帶入">
      </label>
      <label class="field">資產類別
        <select name="asset_class">
          <?php foreach ($classes as $code => $label): ?>
            <option value="<?= e($code) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">名稱
        <input type="text" name="name" placeholder="留空則抓價時自動帶入">
      </label>
      <label class="field">備註
        <input type="text" name="notes" maxlength="200">
      </label>
    </div>
    <div style="margin-top:12px; display:flex; gap:14px; align-items:center; flex-wrap:wrap">
      <button type="submit">加入資料庫</button>
      <label class="field" style="flex-direction:row; align-items:center; gap:6px">
        <input type="checkbox" name="auto_quote" value="1" checked style="width:auto"> 加入時順便抓最新價
      </label>
    </div>
  </form>
</div>

<div class="panel">
  <h3>所有投資物（<?= count($instruments) ?>）</h3>
  <?php if ($instruments === []): ?>
    <div class="empty">還沒有投資物，先用上面的表單加入，或直接到<a href="<?= e(url('/transactions')) ?>">交易紀錄</a>輸入代號。</div>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th class="left">代號／名稱</th><th class="left">Yahoo symbol</th>
        <th>幣別</th><th>最新價</th><th class="left">手動價格／日期</th><th>筆數</th><th></th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($instruments as $i):
        $id = (int) $i['id'];
        $q = $prices[$id] ?? null;
        $held = \App\Database::scalar(
            'SELECT COUNT(*) FROM transactions WHERE instrument_id = ?', [$id], 0
        );
    ?>
      <tr>
        <td class="left">
          <a href="<?= e(\App\Markets::quoteUrl((string) $i['yahoo_symbol'])) ?>"
             target="_blank" rel="noopener noreferrer"><strong><?= e($i['symbol']) ?></strong></a>
          <div class="small muted"><?= e($i['name']) ?></div>
        </td>
        <td class="left small"><code><?= e($i['yahoo_symbol']) ?></code></td>
        <td><?= e($i['currency']) ?></td>
        <td class="num"><?= $q === null ? '<span class="muted">--</span>' : e(fmt_price($q['price'], (string) $i['currency'])) ?></td>
        <td class="left">
          <form method="post" action="<?= e(url('/instruments/' . $id . '/price')) ?>" class="inline manual-price-form">
            <?= csrf_field() ?>
            <div class="manual-entry">
              <input type="number" name="manual_price" step="any" min="0" placeholder="自行輸入"
                     value="<?= $i['manual_price'] === null ? '' : e($i['manual_price']) ?>">
              <input type="date" name="manual_date"
                     value="<?= e((string) ($i['manual_price_at'] ?: date('Y-m-d'))) ?>">
            </div>
            <div class="manual-actions">
              <button class="tiny" type="submit">存檔</button>
              <?php if ($i['manual_price'] !== null): ?>
                <button class="tiny ghost" type="submit" name="manual_price" value="">清除手動</button>
              <?php endif; ?>
            </div>
            <div class="small muted manual-quote-date">
              <?php if ($q === null): ?>
                尚未報價
              <?php elseif ($q['source'] === 'manual'): ?>
                <span class="badge manual">手動 <?= e($q['as_of']) ?></span>
              <?php else: ?>
                報價日期 <?= e($q['as_of']) ?>
              <?php endif; ?>
            </div>
          </form>
        </td>
        <td class="num"><?= (int) $held ?></td>
        <td>
          <form method="post" action="<?= e(url('/instruments/' . $id . '/price')) ?>">
            <?= csrf_field() ?>
            <button class="tiny ghost" type="submit" name="action" value="fetch">抓價</button>
          </form>
        </td>
        <td>
          <?php if ((int) $held === 0): ?>
            <form method="post" action="<?= e(url('/instruments/' . $id . '/delete')) ?>">
              <?= csrf_field() ?>
              <button class="tiny danger" type="submit">刪除</button>
            </form>
          <?php else: ?>
            <span class="small muted">使用中</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <p class="hint">
    自動報價走 Yahoo Finance 的公開 chart 端點（不需 API key）。抓取失敗時，用「手動價格」欄位輸入你在券商 App 看到的收盤價即可。
    手動價格與快取報價比日期，較新者生效；日期留空以今天計算，清除手動價格後改回自動報價。
  </p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
