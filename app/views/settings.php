<?php require __DIR__ . '/_header.php'; ?>

<div class="page-title">
  <h2>設定</h2>
  <span class="meta">
    資料庫：<code><?= e((string) (require __DIR__ . '/../../app/config.php')['data_dir']) ?>/portfolio.sqlite</code>
    <?php if ($lastRefresh !== ''): ?>｜上次更新 <?= e($lastRefresh) ?><?php endif; ?>
  </span>
</div>

<div class="grid-forms">
  <div class="panel">
    <h3>記帳與報價</h3>
    <form method="post" action="<?= e(url('/settings')) ?>">
      <?= csrf_field() ?>
      <label class="field">組合總值使用的幣別
        <select name="base_currency">
          <?php foreach ($rates as $c): ?>
            <option value="<?= e($c) ?>" <?= $c === $base ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
          <option value="__other__" <?= isset($rates[$base]) ? '' : 'selected' ?>>其他（自行輸入）</option>
        </select>
      </label>
      <label class="field" style="margin-top:8px">自訂幣代碼（選「其他」時填）
        <input type="text" name="base_currency_custom" maxlength="5" placeholder="THB">
      </label>
      <label class="field" style="margin-top:12px">報價來源
        <select name="quote_source">
          <option value="yahoo" <?= $quoteEnabled ? 'selected' : '' ?>>自動：Yahoo Finance 公開端點</option>
          <option value="off" <?= $quoteEnabled ? '' : 'selected' ?>>關閉自動抓取，只用手動價格</option>
        </select>
      </label>
      <label class="field" style="margin-top:12px">轉接位址（選填，只在 Yahoo 擋掉這台伺服器時才需要）
        <input type="url" name="quote_endpoint" value="<?= e($quoteEndpoint) ?>" autocomplete="off"
               placeholder="https://yahoo-proxy.…workers.dev">
      </label>
      <label class="field" style="margin-top:12px">轉接位址的存取金鑰（選填）
        <input type="password" name="quote_token" autocomplete="new-password"
               placeholder="Worker 那邊設的 QUOTE_TOKEN">
        <span class="hint">留空＝保留目前的金鑰；填寫新值＝更換。現有金鑰不會顯示在畫面上。</span>
      </label>
      <label class="field" style="margin-top:8px">
        <input type="checkbox" name="clear_quote_token" value="1">
        明確清除目前金鑰（勾選時請將上方金鑰欄位留空）
      </label>
      <label class="field" style="margin-top:12px">單次網頁報價更新時間上限（秒）
        <input type="number" name="quote_refresh_budget" min="5" max="600" step="1"
               value="<?= e((string) $refreshBudget) ?>" placeholder="240">
      </label>
      <?php if ($cooldownLeft > 0): ?>
        <p class="hint" style="margin-top:12px">
          <strong>來源暫停中</strong>：約 <?= (int) ceil($cooldownLeft / 60) ?> 分鐘後自動恢復。
          換好轉接位址的話，按下面的「儲存並解除暫停」可以馬上再試，不用等。
        </p>
      <?php endif; ?>
      <div style="margin-top:14px">
        <button type="submit">儲存設定</button>
        <?php if ($cooldownLeft > 0): ?>
          <button class="ghost" type="submit" name="clear_cooldown" value="1">儲存並解除暫停</button>
        <?php endif; ?>
      </div>
    </form>
    <p class="hint">
      換算方向：某幣別金額 ÷ 該幣別的「每美元單位數」× 記帳幣別的「每美元單位數」。
      USD 永遠是 1，所以只要美元對各幣匯率齊全，跨市場組合的總值就正確。
    </p>
    <p class="hint">
      轉接位址要能把 <code>/v8/finance/chart/…</code> 原封不動轉給 Yahoo（專案裡的
      <code>worker/yahoo-passthrough.js</code> 就是做這件事，填它給的網址即可）；留空＝直接打 Yahoo。
      一個位址被回報「要求過多」之後，系統會先暫停 30 分鐘（來源有給等待時間就聽它的），期間按更新不會重複打出去。
    </p>
  </div>

  <div class="panel">
    <h3>匯率（每 1 美元兌換的單位數）</h3>
    <form method="post" action="<?= e(url('/fx')) ?>" class="inline" style="margin-bottom:14px">
      <?= csrf_field() ?>
      <label class="field">幣別
        <input type="text" name="currency" maxlength="5" placeholder="JPY" required style="text-transform:uppercase;width:90px">
      </label>
      <label class="field">每美元
        <input type="number" name="units_per_usd" step="any" min="0.000001" placeholder="留空＝自動抓取" style="width:130px">
      </label>
      <label class="field">日期
        <input type="date" name="as_of" value="<?= e(date('Y-m-d')) ?>">
      </label>
      <button type="submit">儲存匯率</button>
    </form>
    <p class="hint" style="margin-top:-6px">
      「每美元」留空按儲存＝直接向 Yahoo 抓取這個幣別，新增幣別用這條就好。填了數值才會鎖成手動，自動更新不會蓋掉它。
    </p>

    <?php if ($fx === []): ?>
      <p class="hint" style="margin-top:0">目前沒有匯率資料。按「更新報價與匯率」自動抓取，或在上方手動輸入。</p>
    <?php endif; ?>
    <div class="table-wrap">
    <table>
      <thead><tr><th class="left">幣別</th><th>每美元</th><th class="left">日期</th><th class="left">來源</th><th></th></tr></thead>
      <tbody>
        <tr>
          <td class="left"><strong>USD</strong></td>
          <td class="num">1.000000</td>
          <td class="left small muted">—</td>
          <td class="left"><span class="badge">基準（固定 1，不存進資料庫）</span></td>
          <td></td>
        </tr>
      <?php foreach ($fx as $row): ?>
        <tr>
          <td class="left"><strong><?= e($row['currency']) ?></strong></td>
          <td class="num"><?= e(number_format((float) $row['units_per_usd'], 6, '.', '')) ?></td>
          <td class="left small muted"><?= e((string) $row['as_of']) ?></td>
          <td class="left">
            <?= (int) $row['manual'] === 1
                ? '<span class="badge manual">手動（自動更新不會蓋掉）</span>'
                : '<span class="badge">Yahoo</span>' ?>
          </td>
          <td>
            <div class="row-actions">
            <?php if ((int) $row['manual'] === 1): ?>
              <form method="post" action="<?= e(url('/fx')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="currency" value="<?= e($row['currency']) ?>">
                <input type="hidden" name="units_per_usd" value="">
                <button class="tiny ghost" type="submit">改回自動</button>
              </form>
            <?php else: ?>
              <form method="post" action="<?= e(url('/fx')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="currency" value="<?= e($row['currency']) ?>">
                <input type="hidden" name="units_per_usd" value="">
                <input type="hidden" name="force" value="1">
                <button class="tiny ghost" type="submit">重新抓取</button>
              </form>
            <?php endif; ?>
              <form method="post" action="<?= e(url('/fx/delete')) ?>"
                    onsubmit="return confirm(<?= e(js('刪除 ' . $row['currency'] . ' 匯率？該幣別持股的換算金額會先顯示 --。')) ?>)">
                <?= csrf_field() ?>
                <input type="hidden" name="currency" value="<?= e($row['currency']) ?>">
                <button class="tiny danger" type="submit">刪除</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="hint">缺某幣別匯率時，該筆持股的換算金額會顯示 <code>--</code>，不會用 1:1 蒙混過去。</p>
  </div>
</div>

<div class="panel">
  <h3>登入與密碼</h3>
  <p class="hint" style="margin-top:0">
    目前登入者：<strong><?= e($loginUser) ?></strong>。整個系統只有這一組帳號密碼，沒有多使用者隔離——
    拿到它的人就看到全部組合，所以請把它當成跟券商密碼一樣的事情來管。
  </p>
  <form method="post" action="<?= e(url('/password')) ?>">
    <?= csrf_field() ?>
    <label class="field" style="margin-top:10px">目前密碼
      <input type="password" name="current_password" autocomplete="current-password" required>
    </label>
    <label class="field" style="margin-top:10px">新密碼（至少 <?= (int) \App\Auth::MIN_PASSWORD ?> 個字元）
      <input type="password" name="new_password" autocomplete="new-password" required>
    </label>
    <label class="field" style="margin-top:10px">再輸入一次新密碼
      <input type="password" name="new_password2" autocomplete="new-password" required>
    </label>
    <div style="margin-top:14px"><button type="submit">更新密碼</button></div>
  </form>
  <p class="hint">
    改完密碼，這頁還留著，其他瀏覽器的登入狀態會立刻失效。帳號名稱改了要回伺服器跑
    <code>php bin/create-admin.php --username=新帳號 --force</code>（它會整組蓋掉，包含密碼）。
    這組資料不在備份檔裡：還原舊備份不會把密碼一起倒回，換新機器時要重新跑一次那行指令。
  </p>
</div>

<div class="panel">
  <h3>備份與還原</h3>
  <p class="hint" style="margin-top:0">
    一份備份就是整個資料庫：<code>data/portfolio.sqlite</code> 的完整快照，投資組合、投資物、交易、
    現金紀錄、報價、匯率與設定全在裡面，不需要停服務（用 SQLite 的 <code>VACUUM INTO</code> 取一致快照，
    WAL 的內容也一起折進去）。還原是逐表放進一個交易裡覆蓋，檢查不過就整筆退回，不會蓋到一半。
  </p>

  <div class="grid-forms">
    <form method="post" action="<?= e(url('/backup/create')) ?>">
      <?= csrf_field() ?>
      <button type="submit">立即備份</button>
      <p class="hint">存進 <code><?= e($backupDir) ?></code>，超過 <?= (int) \App\Backup::KEEP ?> 份自動刪掉最舊的。</p>
    </form>

    <form method="post" action="<?= e(url('/backup/restore')) ?>" enctype="multipart/form-data"
          onsubmit="return confirm('還原會用這個檔案覆蓋現在的全部資料（系統會先把現況存成一份「還原前快照」，可以一鍵退回）。確定繼續？')">
      <?= csrf_field() ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $uploadLimit['bytes'] ?>">
      <label class="field">上傳備份檔還原
        <input type="file" name="backup" required
               accept=".sqlite,.db,application/x-sqlite3,application/octet-stream">
      </label>
      <label style="display:block;margin-top:10px;font-size:13px">
        <input type="checkbox" name="confirm" value="1" required style="width:auto"> 我了解這會覆蓋現在的全部資料
      </label>
      <div style="margin-top:10px"><button class="ghost" type="submit">上傳並還原</button></div>
    </form>
  </div>

  <p class="hint">
    這台伺服器目前允許的上傳大小約 <?= e($uploadLimit['human']) ?>（由 php.ini 的
    <code>upload_max_filesize</code>／<code>post_max_size</code> 決定，兩者取小的那個才算）。
    用 <code>start-windows.bat</code>／<code>start.sh</code> 啟動已把兩個上限提高到 64 MB。
    超過上限時整份表單會被伺服器丟棄，頁面會直接顯示原因，不會讓你以為按了沒反應。
  </p>

  <?php if ($backups === []): ?>
    <p class="hint" style="margin-top:0">還沒有備份。按「立即備份」存第一份。</p>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead><tr><th class="left">時間</th><th class="left">種類</th><th class="left">檔案</th><th>大小</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td class="left small"><?= e($b['moment']) ?></td>
            <td class="left">
              <?= $b['kind'] === '還原前快照'
                  ? '<span class="badge manual">還原前快照</span>'
                  : '<span class="badge">手動備份</span>' ?>
            </td>
            <td class="left small muted"><code><?= e($b['name']) ?></code></td>
            <td class="num"><?= e(\App\Backup::humanBytes((int) $b['bytes'])) ?></td>
            <td>
              <div class="row-actions">
                <a class="tiny ghost" href="<?= e(url('/backup/' . $b['name'] . '/download')) ?>">下載</a>
                <?php if ($b['kind'] === '還原前快照'): ?>
                  <form method="post" action="<?= e(url('/backup/' . $b['name'] . '/restore')) ?>"
                        onsubmit="return confirm(<?= e(js('把這份快照「' . $b['name'] . '」還原回去？現在的資料會先存成另一份快照。')) ?>)">
                    <?= csrf_field() ?>
                    <label style="display:block;margin-bottom:6px;font-size:13px">
                      <input type="checkbox" name="confirm" value="1" required style="width:auto"> 我了解還原會覆蓋現在的全部資料
                    </label>
                    <button class="tiny ghost" type="submit">退回</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/backup/' . $b['name'] . '/delete')) ?>"
                      onsubmit="return confirm(<?= e(js('刪除 ' . $b['name'] . '？這個檔案就沒有還原退路了。')) ?>)">
                  <?= csrf_field() ?>
                  <button class="tiny danger" type="submit">刪除</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <p class="hint">
    要搬到另一台電腦：按「立即備份」，再從列表下載那個檔案，放進新環境的 <code>data/</code>（改名成
    <code>portfolio.sqlite</code>），或直接在上面上傳還原。也可以停止服務後複製
    <code>data/portfolio.sqlite</code>，或在 shell 用 <code>sqlite3 data/portfolio.sqlite ".backup backup.sqlite"</code>。
  </p>
</div>

<div class="panel">
  <h3>排程更新報價</h3>
  <p class="hint" style="margin-top:0">
    呼叫 <code>php bin/refresh-prices.php</code>（Windows 工作排程器／Linux crontab 皆可），
    它走跟網頁同一套抓價與匯率邏輯。
  </p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
