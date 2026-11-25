<?php require __DIR__ . '/_header.php'; ?>

<div class="page-title"><h2>歡迎使用投資組合追蹤系統</h2></div>

<div class="panel">
  <h3>先建立第一個投資組合</h3>
  <form method="post" action="<?= e(url('/portfolios')) ?>" class="inline">
    <?= csrf_field() ?>
    <label class="field">組合名稱 *
      <input type="text" name="name" maxlength="60" placeholder="長期投資" required>
    </label>
    <label class="field" style="flex:1">描述
      <input type="text" name="description" maxlength="200" placeholder="可留空">
    </label>
    <button type="submit">建立並開始記交易</button>
  </form>

  <p class="hint" style="margin-top:18px">接著的三步：</p>
  <ol class="hint">
    <li>到<strong>交易紀錄</strong>輸入一筆買進（代號、股數、成交價、手續費）——新投資物可以同表單直接建立。</li>
    <li>按<strong>更新報價與匯率</strong>抓最新價。抓不到時到<strong>投資物</strong>頁用手動價格輸入券商 App 的收盤價。</li>
    <li>到<strong>總覽</strong>的持股明細看加權平均成本、未實現／已實現損益；跨幣別持有會先換算成記帳幣別再加總。</li>
  </ol>

  <p class="hint">
    市場代號自動對應：台股 <code>2330 → 2330.TW</code>、港股 <code>700 → 0700.HK</code>、
    上海 <code>600000 → 600000.SS</code>、東京 <code>7203 → 7203.T</code>；美股直接打 <code>AAPL</code>。
    每個代號名稱旁邊的連結會開 Yahoo Finance 讓你比對（系統用 Yahoo 抓數據，所以連結也直接用 Yahoo）。
  </p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
