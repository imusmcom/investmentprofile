<?php require __DIR__ . '/_header.php'; ?>

<?php
/**
 * @var array<int, array<string, mixed>> $rows
 */
$fit_label = [
    'trend'   => ['通道可用', ''],
    'caution' => ['謹慎參考', 'manual'],
    'none'    => ['不適用', 'manual'],
];
$zone_class = ['buy' => 'buy', 'sell' => 'sell', 'hold' => '', 'none' => 'manual'];

/** 本名常是三四個英文單字，吃掉寬度比整欄數字還多：截短，全名放進滑鼠提示。 */
$short_name = static function (array $r): string {
    return mb_strimwidth((string) $r['name'], 0, 26, '…');
};

/** 兩張表共用的第一欄。第二張緊接在第一張之後、順序相同，所以那張只留代號。 */
$ident = static function (array $r, bool $full = true) use ($short_name): string {
    if (!$full) {
        return '<strong>' . e($r['symbol']) . '</strong>';
    }

    $name = (string) $r['name'];
    $cut = $short_name($r);
    $out = '<strong>' . e($r['symbol']) . '</strong>';
    if ($cut !== '') {
        $out .= ' <span class="muted small"' . ($cut === $name ? '' : ' title="' . e($name) . '"') . '>'
            . e($cut) . '</span>';
    }

    return $out . ' <span class="badge">' . e($r['currency']) . '</span>'
        . ' <span class="muted small">' . e($r['market']) . '</span>';
};

$chart_row_label = static function (array $r): string {
    $name = trim((string) $r['name']);

    return '查看 ' . (string) $r['symbol'] . ($name === '' ? '' : '（' . $name . '）')
        . ' 的五線譜與通道圖';
};

$ready = 0;
$lines = [];   // 算不了的、以及算出來但有話要講的，全部集中到同一個區塊，不讓每檔各佔一段紅字
foreach ($rows as $r) {
    $s = $r['spectrum'];
    $c = $r['channel'];
    $cut = $short_name($r);
    $who = (string) $r['symbol'] . ($cut === '' ? '' : '（' . $cut . '）');
    if ($s['state'] === 'ok') {
        $ready++;
    }

    // 兩個門檻不同（五線譜要 250 根日線，通道只要 20 週），所以「畫不出」要分開講，不能合併成一句。
    if ($s['state'] !== 'ok' || $c['state'] !== 'ok') {
        $both = $s['state'] !== 'ok' && $c['state'] !== 'ok';
        $text = $both
            ? '五線譜與通道都畫不出：' . (string) $s['note']
            : ($s['state'] !== 'ok' ? '五線譜畫不出：' . (string) $s['note'] : '通道畫不出：' . (string) $c['note']);
        $raw = max((int) $s['raw'], (int) $c['raw']);
        $lines[] = ['who' => $who, 'text' => $text
            . ($raw > 0 ? '另外這串日線有 ' . $raw . ' 根沒有含股利的數值可用（人工標價也算在內）。' : ''), 'soft' => true];
    }

    foreach ($s['warn'] as $w) {
        $lines[] = ['who' => $who, 'text' => (string) $w, 'soft' => false];
    }

    if ($s['state'] === 'ok' && $s['note'] !== '') {
        $lines[] = ['who' => $who, 'text' => (string) $s['note'], 'soft' => true];
    }

    foreach ($c['warn'] as $w) {
        $lines[] = ['who' => $who, 'text' => '通道：' . (string) $w, 'soft' => false];
    }
}
?>

<div class="page-title">
  <h2>樂活五線譜</h2>
  <span class="meta">
    樂活五線譜與樂活通道・只讀日線・不改任何資料。
    <?= count($rows) ?> 個投資物裡有 <?= $ready ?> 個的歷史夠長、算得出五線譜（通道只要 20 週，門檻比較低）。這不是投資建議。
  </span>
</div>

<?php if ($rows === []): ?>
  <div class="panel">
    <div class="empty" style="border:0;padding:0">
      投資物資料庫是空的。先到<a href="<?= e(url('/instruments')) ?>">投資物</a>加入代號、抓日線，這頁才有東西可算。
    </div>
  </div>
<?php else: ?>

<div id="chart-panel" class="panel" style="display:none">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
    <h3 id="chart-title" style="margin:0"></h3>
    <span id="chart-zone" class="badge" style="margin-left:4px"></span>
    <span id="chart-fit" class="badge manual" style="margin-left:4px"></span>
    <button id="chart-close" class="ghost" style="margin-left:auto;padding:4px 12px;cursor:pointer" type="button">✕ 關閉</button>
  </div>
  <div class="chart-grid">
    <div class="chart-box" id="spectrum-box">
      <h4 class="small" style="margin:0 0 6px">五線譜（42 個月回歸）</h4>
      <canvas id="spectrum-chart"></canvas>
      <p id="spectrum-note" class="hint small" style="margin:6px 0 0"></p>
    </div>
    <div class="chart-box" id="channel-box">
      <h4 class="small" style="margin:0 0 6px">20 週布林通道</h4>
      <canvas id="channel-chart"></canvas>
      <p id="channel-note" class="hint small" style="margin:6px 0 0"></p>
    </div>
  </div>
</div>

<div class="panel">
  <h3>判讀</h3>
  <div class="table-wrap">
    <table id="spectrum-table">
      <thead>
        <tr>
          <th class="left">投資物</th>
          <th>現在價</th><th>距離趨勢線</th><th>σ 位置</th>
          <th class="left col-gap">區間</th><th class="left">動作</th>
          <th class="left">貼合度</th><th>年化斜率</th><th>窗口</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $s = $r['spectrum']; ?>
          <tr class="chart-row <?= $s['state'] === 'ok' ? '' : 'closed' ?>" data-instrument-id="<?= (int) $r['id'] ?>"
              tabindex="0" role="button" aria-label="<?= e($chart_row_label($r)) ?>">
            <td class="left"><?= $ident($r) ?></td>
            <?php if ($s['state'] !== 'ok'): ?>
              <td class="muted">--</td><td class="muted">--</td><td class="muted">--</td>
              <td class="left col-gap"><span class="badge manual">資料不足</span></td>
              <td class="left muted small">等日線累積夠</td>
              <td class="left muted">--</td><td class="muted">--</td>
              <td class="muted small" title="<?= e((string) $s['from']) ?>～<?= e((string) $s['to']) ?>">
                <?= (int) $s['bars'] ?> 根
              </td>
            <?php else: ?>
              <?php $f = $fit_label[$s['fit']] ?? ['--', 'manual']; ?>
              <td class="num"><strong><?= fmt_price((float) $s['close'], (string) $r['currency']) ?></strong></td>
              <td class="num <?= e(tone($s['gap'])) ?>"><?= fmt_pct($s['gap']) ?></td>
              <td class="num"><?= $s['z'] === null ? '--' : fmt_num((float) $s['z'], 2) . 'σ' ?></td>
              <td class="left col-gap"><span class="badge <?= $zone_class[$s['color']] ?? '' ?>"><?= e($s['zone']) ?></span></td>
              <td class="left"><?= e($s['rule']) ?></td>
              <td class="left"><span class="badge <?= e($f[1]) ?>"><?= e($f[0]) ?></span></td>
              <td class="num <?= e(tone($s['slope'])) ?>"><?= fmt_pct($s['slope'], 1) ?></td>
              <td class="num muted small" title="<?= e((string) $s['from']) ?>～<?= e((string) $s['to']) ?>">
                <?= (int) $s['bars'] ?> 根・<?= fmt_num((float) $s['months'], 1) ?> 個月
                <?php if ((int) $s['raw'] > 0): ?>
                  <span class="badge manual" title="窗口裡有 <?= (int) $s['raw'] ?> 根只能用未調整收盤，不含股利">未含股利</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">
    σ 位置＝現在價離趨勢線幾個標準差（正數在線上方），距離趨勢線是同一件事換成百分比；
    游標停在「窗口」上看得出具體起訖日。
  </p>
  <p class="hint">
    這一頁的<strong>價格口徑是含股利</strong>（<code>prices.adj_close</code>，等於假設配息持續投入）：
    線與現價走同一條路徑，除息那個缺口不會被當成跌價，配息越頻繁的那一檔兩條口徑差越多。
    被換算的只有歷史那一端（這台的資料庫裡最老一根 <code>VT</code> 折到 0.81 倍、<code>VNQ</code> 0.67 倍），
    最新那一天和看盤收盤是同一個數，所以「現在價」這一欄不會和券商對不上。
    某一根真的沒有含股利數值可用時才退回未調整收盤，那一列會標「未含股利」，不讓它假裝可比。
  </p>
</div>

<div class="panel">
  <h3>線與通道的數字</h3>
  <div class="table-wrap">
    <table id="line-table">
      <thead>
        <tr>
          <th class="left">投資物</th>
          <th>＋2SD</th><th>＋1SD</th><th>趨勢線</th><th>－1SD</th><th>－2SD</th>
          <th>20 週上軌</th><th>20 週中軸</th><th>20 週下軌</th>
          <th class="center col-gap">通道位置</th><th class="left">週線偏向</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $s = $r['spectrum']; $c = $r['channel']; ?>
          <tr class="chart-row <?= $s['state'] === 'ok' && $c['state'] === 'ok' ? '' : 'closed' ?>" data-instrument-id="<?= (int) $r['id'] ?>"
              tabindex="0" role="button" aria-label="<?= e($chart_row_label($r)) ?>">
            <td class="left"><?= $ident($r, false) ?></td>
            <?php if ($s['state'] !== 'ok'): ?>
              <td class="muted">--</td><td class="muted">--</td><td class="muted">--</td><td class="muted">--</td><td class="muted">--</td>
            <?php else: ?>
              <td class="num"><?= fmt_num((float) $s['u2']) ?></td>
              <td class="num"><?= fmt_num((float) $s['u1']) ?></td>
              <td class="num"><strong><?= fmt_num((float) $s['tl']) ?></strong></td>
              <td class="num"><?= fmt_num((float) $s['d1']) ?></td>
              <td class="num"><?= fmt_num((float) $s['d2']) ?></td>
            <?php endif; ?>
            <?php if ($c['state'] !== 'ok'): ?>
              <td class="muted">--</td><td class="muted">--</td><td class="muted">--</td>
              <td class="center col-gap muted small">畫不出</td>
              <td class="left muted">--</td>
            <?php else: ?>
              <td class="num"><?= fmt_num((float) $c['upper']) ?></td>
              <td class="num"><?= fmt_num((float) $c['mid']) ?></td>
              <td class="num"><?= fmt_num((float) $c['lower']) ?></td>
              <td class="center col-gap">
                <span title="20 週窗口以 <?= e((string) $c['week']) ?> 那根週收盤結算<?= $c['partial'] ? '（這一週還沒收盤，數字會隨今天的報價動）' : '' ?>">
                <?php if ($c['where'] === 'upper'): ?>
                  <span class="badge sell">出上軌</span>
                <?php elseif ($c['where'] === 'lower'): ?>
                  <span class="badge buy">出下軌</span>
                <?php else: ?>
                  <span class="badge">通道內</span>
                <?php endif; ?>
                </span>
                <?php if ((int) $c['raw'] > 0): ?>
                  <div class="muted small" style="margin-top:2px">
                    <span class="badge manual" title="這 20 週裡有 <?= (int) $c['raw'] ?> 根只能用未調整收盤，不含股利">未含股利</span>
                  </div>
                <?php endif; ?>
              </td>
              <td class="left">
                <?php if ($c['bias'] === 'up'): ?>
                  <span class="badge buy" title="2 週均線 <?= fmt_num((float) $c['ma2']) ?> ≥ 19 週均線 <?= fmt_num((float) $c['ma19']) ?>">2 週線在上</span>
                <?php elseif ($c['bias'] === 'down'): ?>
                  <span class="badge sell" title="2 週均線 <?= fmt_num((float) $c['ma2']) ?> ＜ 19 週均線 <?= fmt_num((float) $c['ma19']) ?>">2 週線在下</span>
                <?php else: ?>
                  <span class="muted">--</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">
    五條線取自<strong>最近 42 個月</strong>的算術回歸；通道是另一套東西——<strong>20 週布林通道</strong>：
    日線先收成週線，取最近 20 週的週收盤算均線（中軸）與母數標準差，上下軌為 ±2 倍標準差。
    兩者窗口與算法都不同，數字本來就不會一樣。「週線偏向」是 2 週與 19 週均線的相對位置（同一份週線，
    和通道同一個尺度），只當進場時機的輔助；出軌卻還沒轉向時，「要留意的」會點出來。
    這些數字全部出自含股利的收盤（<code>adj_close</code>），口徑見上一節說明。
  </p>
</div>

<?php if ($lines !== []): ?>
  <div class="panel">
    <h3>要留意的（<?= count($lines) ?> 條）</h3>
    <ul class="small" style="line-height:1.9;margin:0;padding-left:20px">
      <?php foreach ($lines as $line): ?>
        <li class="<?= $line['soft'] ? 'muted' : 'loss' ?>">
          <strong><?= e($line['who']) ?></strong>：<?= e($line['text']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<details class="panel">
  <summary class="small" style="font-weight:600;cursor:pointer">這頁在算什麼、以及哪些事它做不來</summary>

  <h3>算法</h3>
  <p class="hint" style="margin-top:0">
    每個投資物取<strong>最近 42 個月（3.5 年）的日線</strong>，對「含股利的收盤價 vs 第幾天」做最小平方法，
    得到一條回歸直線（<strong>趨勢線</strong>）與一組殘差標準差（<strong>SDE</strong>）。
    把直線上下各平移 1 倍與 2 倍 SDE，就是五條線。現在價離這條線多遠、偏在哪一邊，就是這個策略要的訊號：
    <strong>線上方太遠是貪婪，線下方太遠是恐懼</strong>。
  </p>
  <p class="hint">
    <strong>為什麼用含股利的收盤：</strong>策略量的是一個價格相對自己歷史線的位置，所以線和現價必須同一個口徑。
    未調整的收盤在每一次除息那天被砸下一個缺口，那個缺口不是虧損，是配息進了你帳戶；拿它畫線等於假設你從來
    沒拿到這些股利，線的形狀就被一連串階梯扭掉——斜率被壓平、殘差被階梯自己撐大或縮小，而且每一檔被扭的程度
    都不一樣。這台自己的資料庫兩條口徑各算一次（2026-11-23）：短券那檔 <code>SGOV</code> 的 SDE 從未調整的
    0.12 變成含股利的 0.24，σ 位置從 <code>+0.67</code>（趨勢區）變成 <code>−1.68</code>（恐懼），
    判讀整句換掉；<code>VT</code>、<code>VNQ</code> 這類則幾乎不動。含股利的口徑把除息那一天接回連續，
    才是「持續持有」的那條路徑。代價是<strong>歷史那一端會被往下換算</strong>，所以這裡的五條線與上下軌會和
    拿看盤收盤算出來的差一點（現價那一欄不會），這一頁也不能拿去和外部文獻的通道數字對帳。
  </p>
  <p class="hint">
    判讀規則照來源的框架：±1SD 之內是合理價；跌破 －1SD 分批布局、跌破 －2SD 重倉布局（要基本面配合）；
    衝上 ＋1SD 分批減碼、＋2SD 獲利了結。三道門檻會改掉語氣——
    <strong>R² 低於 0.5</strong> 表示這檔的價走根本不沿著一條線走，五線譜在它身上不適用；
    <strong>斜率為負</strong>時「越跌越買」的勝率比較低；
    <strong>殘差變異係數（CV）</strong>是 SDE 占均價的百分比，每檔用自己歷史的波動定距離，所以跨檔不適用同一個百分比門檻。
  </p>
  <p class="hint">
    通道是另一件事。「樂活通道」這個名字查不到任何寫着自己參數的權威來源，搜尋得到的「三條線／±2 倍標準差」
    講的都是<strong>布林（保利加）通道</strong>，所以這一欄就照布林最多人對帳的那個定義實作，名字也講清楚是 20 週布林：
    日線按 ISO 週收成週線（同一週取最後一個有報價的日子），取<strong>最近 20 週的週收盤</strong>算簡單均線當中軸，
    上下軌為中軸加減 <strong>2 倍母數標準差</strong>（除以 20，不是 19——掃圖軟體用的是這個）。
    窗口裡包含「這一週還沒收盤」的那一棒，所以數字會隨今天的報價動；滑鼠停在「通道位置」上看得出具結算到哪天。
  </p>
  <p class="hint">
    <strong>為什麼換掉前一版：</strong>前一版拿全期對數回歸通道頂替，窗口一路拉到十年，上下軌寬到十檔投資物
    永遠顯示「通道內」——一格永遠不變的狀態不可能是訊號。20 週窗口跟著近況走，出界與否才有意義。
    對數尺度是原方法刻意選的（波動性隨價格變大時，算術通道會把後段的高檔波動平均掉），布林則一律用算術均價；
    這一欄既然是布林，就照布林的算法，不去混用兩種口徑。
  </p>
  <p class="hint">
    <strong>線會重畫。</strong>每天新增一根 K 棒，五條線與 SDE 全部重算，今天的「－2SD」和去年的不是同一個位置；
    拿它回測會比實盤樂觀，因為過去那條線是帶著之後的資料畫出來的。
  </p>

  <h3>做不來的部分</h3>
  <ul class="small" style="line-height:1.9;margin:0;padding-left:20px">
    <li><strong>儀表板／GPS 那套燈號沒有實作。</strong>來源的五線譜只解決「價位」一題，另外三題
      （成長性、安全性、經營績效）要財報資料，這個系統裡沒有，所以這一頁不會告訴你某檔股票「值不值得買」。</li>
    <li><strong>現金流調整的報酬曲線不在這裡。</strong>這一頁只看單一投資物的價格序列，
      存提與買賣對報酬的影響在<a href="<?= e(url('/reports')) ?>">回報</a>頁。</li>
    <li><strong>不會通知你。</strong>要看得自己開這頁；把訊號推出去（郵件、排程）是另一件事，還沒做。</li>
    <li><strong>資料不夠就直接留白，不會猜。</strong>不到 250 根日線算不出五線譜，湊不滿 20 週算不出通道，
      兩者門檻不同、各自留白；等每天的報價把歷史累積起來，這兩欄自己會變可用。</li>
    <li><strong>週線是從日線合成出來的，不是另外抓的。</strong>同一週取最後一個有報價的日子當週收盤，
      所以週長短不一（連假那一週可能只有三根），完全沒有報價的那一週直接少一根——和自備週線資料的掃圖軟體
      可能差個把週，對帳時要記得這一點。</li>
    <li><strong>人工標的那一棒沒有含股利口徑。</strong>手動填寫的價格就是它本身，換算不出「配息有再投入」的數值，
      所以用到它的序列會被標成未含股利；要一個純淨的總報酬口徑，得讓報價來源把歷史補齊。</li>
  </ul>
</details>

<script src="<?= e(asset('assets/chart.umd.min.js')) ?>"></script>
<script>
(function () {
  var panel = document.getElementById('chart-panel');
  var title = document.getElementById('chart-title');
  var zoneEl = document.getElementById('chart-zone');
  var fitEl = document.getElementById('chart-fit');
  var closeBtn = document.getElementById('chart-close');
  var specBox = document.getElementById('spectrum-box');
  var specNote = document.getElementById('spectrum-note');
  var chanBox = document.getElementById('channel-box');
  var chanNote = document.getElementById('channel-note');
  var specChart = null;
  var chanChart = null;
  var requestSeq = 0;
  var spectrumTable = document.getElementById('spectrum-table');
  var lineTable = document.getElementById('line-table');

  var zoneClass = {buy: 'buy', sell: 'sell', hold: '', none: 'manual'};
  var fitLabel = {trend: ['通道可用', ''], caution: ['謹慎參考', 'manual'], none: ['不適用', 'manual']};

  function rowFor(target) {
    var row = target.closest('tr.chart-row');
    if (!row || (!spectrumTable.contains(row) && !lineTable.contains(row))) return null;
    return row;
  }

  function activateRow(row) {
    var id = row.dataset.instrumentId;
    if (id) loadChart(id);
  }

  document.addEventListener('click', function (e) {
    var row = rowFor(e.target);
    if (row) activateRow(row);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var row = rowFor(e.target);
    if (!row) return;
    e.preventDefault();
    activateRow(row);
  });

  closeBtn.addEventListener('click', function () {
    requestSeq++;
    panel.style.display = 'none';
    if (specChart) { specChart.destroy(); specChart = null; }
    if (chanChart) { chanChart.destroy(); chanChart = null; }
  });

  function loadChart(id) {
    var seq = ++requestSeq;
    fetch(<?= js(url('/strategy')) ?> + '/' + id + '/chart')
      .then(function (r) {
        if (!r.ok) throw new Error('chart request failed');
        return r.json();
      })
      .then(function (data) {
        if (seq !== requestSeq) return;
        render(data);
      })
      .catch(function () {
        if (seq === requestSeq) panel.style.display = 'none';
      });
  }

  function render(data) {
    if (typeof Chart === 'undefined') return;

    if (specChart) { specChart.destroy(); specChart = null; }
    if (chanChart) { chanChart.destroy(); chanChart = null; }

    var hasSpec = !!(data.spectrumSeries && data.spectrumSeries.dates && data.spectrumSeries.dates.length > 0);
    var hasChan = !!(data.channelSeries && data.channelSeries.weeks && data.channelSeries.weeks.length > 0);
    if (!hasSpec && !hasChan) {
      panel.style.display = 'none';
      return;
    }

    panel.style.display = 'block';
    title.textContent = data.symbol + ' ' + data.name;

    var s = data.spectrum || {};
    zoneEl.textContent = hasSpec ? (s.zone || '') : '';
    zoneEl.className = 'badge ' + (zoneClass[s.color] || '');
    zoneEl.style.display = hasSpec && s.zone ? '' : 'none';

    var f = fitLabel[s.fit] || ['--', 'manual'];
    fitEl.textContent = hasSpec ? f[0] : '';
    fitEl.className = 'badge ' + f[1];
    fitEl.style.display = hasSpec && s.fit ? '' : 'none';

    specBox.style.display = hasSpec ? '' : 'none';
    chanBox.style.display = hasChan ? '' : 'none';

    if (hasSpec) renderSpectrum(data);
    if (hasChan) renderChannel(data);

    panel.scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  function renderSpectrum(data) {
    var series = data.spectrumSeries;
    specNote.textContent = '';

    var s = data.spectrum || {};
    var warns = (s.warn || []).join(' ');
    if (warns) specNote.textContent = warns;

    var ctx = document.getElementById('spectrum-chart').getContext('2d');
    specChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: series.dates,
        datasets: [
          {label: '價格', data: series.prices, borderColor: '#555', borderWidth: 1.5, pointRadius: 0, tension: 0},
          {label: '趨勢線', data: series.trend, borderColor: '#3498db', borderWidth: 2, pointRadius: 0, tension: 0},
          {label: '+1SD', data: series.u1, borderColor: '#27ae60', borderWidth: 1, borderDash: [6, 3], pointRadius: 0, tension: 0},
          {label: '+2SD', data: series.u2, borderColor: '#27ae60', borderWidth: 1, borderDash: [2, 2], pointRadius: 0, tension: 0},
          {label: '−1SD', data: series.d1, borderColor: '#e67e22', borderWidth: 1, borderDash: [6, 3], pointRadius: 0, tension: 0},
          {label: '−2SD', data: series.d2, borderColor: '#e67e22', borderWidth: 1, borderDash: [2, 2], pointRadius: 0, tension: 0}
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2,
        interaction: {intersect: false, mode: 'index'},
        plugins: {
          legend: {position: 'bottom', labels: {boxWidth: 14, font: {size: 11}}},
          tooltip: {enabled: true}
        },
        scales: {
          x: {
            display: true,
            ticks: {maxTicksLimit: 10, maxRotation: 0, font: {size: 10}},
            grid: {display: false}
          },
          y: {
            display: true,
            ticks: {font: {size: 10}},
            grid: {color: '#eee'}
          }
        }
      }
    });
  }

  function renderChannel(data) {
    var series = data.channelSeries;
    chanNote.textContent = '';

    var c = data.channel || {};
    var warns = (c.warn || []).join(' ');
    if (warns) chanNote.textContent = warns;

    var ctx = document.getElementById('channel-chart').getContext('2d');
    chanChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: series.weeks,
        datasets: [
          {label: '週收盤', data: series.closes, borderColor: '#555', borderWidth: 1.5, pointRadius: 0, tension: 0},
          {label: '中軸 (SMA20)', data: series.mid, borderColor: '#3498db', borderWidth: 2, pointRadius: 0, tension: 0, spanGaps: false},
          {label: '上軌 (+2σ)', data: series.upper, borderColor: '#e74c3c', borderWidth: 1, borderDash: [6, 3], pointRadius: 0, tension: 0, spanGaps: false},
          {label: '下軌 (−2σ)', data: series.lower, borderColor: '#27ae60', borderWidth: 1, borderDash: [6, 3], pointRadius: 0, tension: 0, spanGaps: false}
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2,
        interaction: {intersect: false, mode: 'index'},
        plugins: {
          legend: {position: 'bottom', labels: {boxWidth: 14, font: {size: 11}}},
          tooltip: {enabled: true}
        },
        scales: {
          x: {
            display: true,
            ticks: {maxTicksLimit: 10, maxRotation: 0, font: {size: 10}},
            grid: {display: false}
          },
          y: {
            display: true,
            ticks: {font: {size: 10}},
            grid: {color: '#eee'}
          }
        }
      }
    });
  }
})();
</script>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
