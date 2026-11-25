# 投資組合追蹤系統（PHP + SQLite）

單機執行的股票投資組合與損益追蹤網站。不需要 Composer、不需要框架、不需要安裝資料庫伺服器——
把資料放進一個 SQLite 檔案就能跑。

核心功能刻意只做以下八件事，不主動寄送提醒；「交易策略」下的「樂活五線譜」頁另提供只讀的樂活五線譜與通道判讀：

- **總覽**：一頁看完持股明細（加權平均成本、股數、最新價、市值、佔比、當日漲跌，含已出清）、現金與各幣別、最近交易與各大合計
- **交易紀錄**：買進／賣出、手續費、日期、備註，可隨時補登與刪除；也能用「範圍」選單看單一組合或所有組合
  （歷史成交每頁 100 筆；「匯出 CSV」下載的是同一範圍與篩選條件下的**全部**結果，不只目前這一頁）。
  CSV 以逐列串流輸出，不會先把整份匯出載進記憶體；匯出會自動跳脫公式注入：備註欄若以 `=`、`+`、`@`、`-`、Tab 或 CR 開頭，
  前面會加一個單引號，Excel 開啟時不會把它當公式執行，避免惡意連結被誤觸。
- **損益**：未實現／已實現／合計，跨幣別自動換算成記帳幣別
- **現金**：存入／提取由你記，買進賣出自動增減，各幣別各自結算，總覽一併顯示現金與各幣別的原幣市值，以及持股佔整個投資組合的百分比
- **回報**：1 天、7 天、1／3／6／9／12 個月、本年至今、24／60／120 個月與每個年度的時間加權報酬，持股與持股＋現金並列
- **樂活五線譜**：樂活五線譜與 20 週布林通道，判讀每檔投資物目前相對自身歷史的價格位置；只讀、不會下單
- **備份與還原**：整個資料庫存成一個檔案、列表管理、上傳還原，覆蓋前自動留一份可以退回的快照
- **登入**：一組共用密碼擋掉外人，放在門後的是一模一樣的資料（不是多使用者，各自看不到對方）

介面把每一檔被追蹤的東西（股票、ETF、指數）統稱**投資物**，放在共用的「投資物資料庫」裡；
「資產類別」下拉仍保留「股票」這個選項，那是分類名稱，不是上面那個總稱。

支援多組合併存於同一個資料庫：組合之間各自計算持股與損益，投資物資料庫與匯率則共用，
同一個投資物不會重複抓價。組合名稱與描述在「組合」頁直接改成，按「儲存修改」即可。

---

## 需求

| 項目 | 最低版本 |
| --- | --- |
| PHP | 8.1（開發時用 8.3.37 NTS） |
| 擴充 | `pdo_sqlite`、`curl`、`mbstring`、`openssl` |

`php -m | grep -E "pdo_sqlite|curl|mbstring|openssl"` 四行都有就沒問題。

## 快速開始

```bash
# Linux / macOS / Git-Bash
bash start.sh 8000

# Windows
start-windows.bat 8000
```

然後建立登入帳號（沒有它，網頁全部關在門後，頁面也會直接告訴你這行指令）：

```bash
php bin/create-admin.php --username=你的帳號
```

它會要求輸入密碼，資料庫裡只存 hash、不存明文（密碼至少 10 個字元）。終端機支援時輸入不顯示；
遇到無法隱藏的場合（cmd.exe 就是）會直接提醒你「密碼會顯示在螢幕上」，這時改用 `--password-from-stdin`。
之後打開 <http://localhost:8000> 登入，第一次會看到引導頁，按提示建立第一個組合即可。（瀏覽器打 `127.0.0.1` 可能連不到：`php -S` 綁的是 `localhost`，在這台機器上解析成 `::1`。）

想先看看真實資料長什麼樣子，灌一筆示範資料（含台股、港股、美股、賣出紀錄與現金存入／提取）：

```bash
php bin/seed-demo.php          # 資料庫已有交易、現金流或投資組合時就拒絕
php bin/seed-demo.php --force  # 只重播本工具建立且完全未改的示範組合；陌生或同名資料不會被刪
```

`--force` 不以 `Demo%` 當所有權判斷。工具會在 `settings` 記錄示範組合的 id、建立時間與完整內容指紋；
重播前逐項核對，只刪除完全一致的組合及其交易／現金流，再與新 seeding 放在同一交易。缺記錄、被改過、
同名衝突或任何寫入失敗都會整筆回復；無法安全識別時寧可拒絕。共用的投資物與匯率不會被刪除。

### Windows 的 HTTPS 憑證檔（第一次一定要處理）

Windows 的 PHP 不附 CA 憑證包，抓報價會回報
`連線失敗：error adding trust anchors from file: cacert.pem`。解法是下載一份並寫進 `php.ini`：

```ini
; 絕對路徑，相對路徑會跟著 PHP 的工作目錄跑掉
curl.cainfo = "C:\\path\\to\\cacert.pem"
openssl.cafile = "C:\\path\\to\\cacert.pem"
```

`cacert.pem` 取自 <https://curl.se/ca/cacert.pem>。沒設好時，按下「更新報價與匯率」後頁面的失敗明細會直接點名這個原因與解法。

---

## 報價怎麼來

**自動**：Yahoo Finance 的公開 chart 端點（`v8/finance/chart/{symbol}`），不用金鑰、不用註冊，
一次回最新報價加半年日線，寫進 `prices` 資料表後頁面完全離線也能看。
**回報頁需要十年日線**，所以每檔投資物在第一次更新時會多要一次 `10y`（`QUOTE_HISTORY_RANGE`）；
日線寫過就不會再抓第二次，之後的更新只補最近半年。

**兩種收盤價**：`prices` 每一列同時存 `close`（來源那天的收盤）與 `adj_close`（除息／除權調整後，含股利）。
兩欄回答不同問題：成交、總覽、持股成本與回報使用 `close`，與實際成交金額保持同一口徑；策略分析使用
`adj_close`，避免除息被誤判成跌幅。兩者不混用，尤其不能把調整後市值配上原始成交金額，否則會算出假報酬。
樂活五線譜與通道因此走含股利口徑。外匯與加密貨幣的來源本來就沒有 `adjclose`，那一欄就留 `NULL`，
不拿 `close` 假裝是它。舊資料庫由 `app/Database.php` 的 `migrate()` 補出這一欄，已有的日線再用
`php bin/backfill-adj.php` 回填（見「排程更新報價」）。

**每日 high／low**：`prices.high` 與 `prices.low` 保存 Yahoo chart 的未調整日高／日低，供需要日內區間的分析使用。
兩欄一起通過 `0 < low ≤ close ≤ high` 檢查才採用；缺一半、不是正數、
高低倒置或高低不含收盤時存成 `NULL`，不拿 `close` 捏一根零幅 K 棒。最新一根若歷史陣列沒有可用範圍，
會用 metadata 的 `regularMarketDayHigh`／`regularMarketDayLow`；部分回應以 `COALESCE` 保留舊值。
一般六個月更新會順手補最近缺漏；已有十年歷史但沒有高低價的舊資料，即使歷史深度已經足夠，
`deepOwed()` 也會為了 `deep_high_low_<投資物 id>` 這道記號放行一次 `range=10y` 深抓。深抓後若最早仍有缺漏，
會再以該日加減緩衝的 `period1`／`period2` 定區間補一次，取回已滑出滾動十年範圍的舊日線；缺漏數不變的記號
九十天內不重問，失敗不寫記號，缺漏數增加會重新放行；刪除投資物時兩種深抓記號都一起刪掉。
執行 `php bin/refresh-prices.php` 就會回補，不需要另外寫程式；回補後可在這一頁的選單逐檔看覆蓋率。

**手動**：每個投資物都能填同一橫列的「手動價格 + 日期」。手動價格與快取報價比日期，**較新者生效**，
總覽的持股明細會標成 `手動` 徽章。網路不通、股票停牌、代號不在 Yahoo 上時用這條路。
日期欄留空就以今天計算（否則這個價格永遠贏不過已快取的報價）；填了不成立的日期會被擋下來，不會默默失效。
清除手動價格後就改回自動報價。投資物頁的「刪除」不再彈出確認或要求輸入代號；仍有交易紀錄的投資物會由伺服器拒絕刪除。

**單檔抓取**：總覽的持股明細每列有一個「抓取」按鈕，只更新那一檔，不用刷整套。

> 報價來源與連結都用 Yahoo Finance。Yahoo 有穩定的 JSON API，一條 HTTP 請求就涵蓋台／港／日／中／美股與外匯，
> 所以系統用它抓數據，代號旁邊的外鏈也直接連到 Yahoo 的行情頁（`https://finance.yahoo.com/quote/{yahoo_symbol}`）。
> 不需要維護交易所對照表，因為 `yahoo_symbol` 已經包含正確的後綴（`.TW`、`.HK`、`.L`、`-USD` 等）。

代號對照（`app/Markets.php`）：`2330` → `2330.TW`、`700` → `0700.HK`（港股要補滿四位）、
`600000` → `600000.SS`、`VOD` → `VOD.L`（倫敦）、`AAPL` → `AAPL`。選「其他／指數」時輸入什麼就用什麼，
可以直接填 `^GSPC`、`BTC-USD`。加密貨幣有獨立的「加密貨幣」市場：打 `BTC` 自動變成 `BTC-USD`，
預設 USD 計價、資產類別自動是「加密貨幣」，股數欄可以打小數（`0.0035` 這種零碎幣也記得了）。

**倫敦**：市場選「倫敦」、代號打 `VOD` 就好，系統補 `.L` 向 Yahoo 取英鎊報價；直接貼在別處看到的
`VOD.L` 也接受，不會變成 `VOD.L.L`。外鏈直接用 Yahoo 的 `VOD.L`。富時 100 指數 `^FTSE` 走「其他／指數」。
以 `.IL` 結尾的倫敦國際訂單簿（IOB）不在無金鑰端點可抓的範圍，所以不支援；想以美元計價就改用「美股」市場，
Yahoo 那端的 `VOD`（不附 `.L`）正是美股 ADR。

**法蘭克福、多倫多與吉隆坡**：同一套規則——市場選「德國」打 `SAP` 補成 `SAP.F`（歐元），選「加拿大」打 `SHOP`
補成 `SHOP.TO`（加幣），選「馬來西亞」打 `1155` 補成 `1155.KL`（令吉）。貼上 `SAP.F` 也不會多補成 `SAP.F.F`。

### 出口被擋時：轉接位址

Yahoo 會整段封掉資料中心的位址。被封的現象是**每個端點都秒回 429**（連 `Retry-After` 都不給），
換 UA、換 HTTP 版本、換 `query1`／`query2` 都一樣，同一間雲廠商再要一個位址也一樣——
被列入的是整段 ASN，不是那個 IP。那種時候要換的是**出口**，不是這支程式。

設定頁的「轉接位址」把要求改由你指定的位址轉發，欄位本身是通用的：任何會把
`/v8/finance/chart/…` 原封不動轉給 Yahoo、並把回應（狀態碼與 `Retry-After` 也算在內）原封不動送回來
的位址都能填，自己架的 nginx 反代或正向代理都行。專案附的 `worker/yahoo-passthrough.js`
是一份可直接部署到 Cloudflare Workers 的轉發程式：

```bash
# 明確把 script path 當 entrypoint；沒有 wrangler.toml 時，預設 src/index.js 並不存在
npx wrangler deploy worker/yahoo-passthrough.js --name yahoo-passthrough --compatibility-date 2024-09-01
npx wrangler secret put QUOTE_TOKEN --name yahoo-passthrough   # 必填，不是選填
```

也可以在 Cloudflare 後台建立 Worker 時，直接把 `worker/yahoo-passthrough.js` 內容貼成 entrypoint；
若改用 `wrangler.toml`，入口是 `main = "worker/yahoo-passthrough.js"`。`QUOTE_TOKEN` 未設定、請求缺少
`X-Quote-Token` 或金鑰錯誤都回 **401**，不會當成出口被封而觸發暫停。

然後把 `https://yahoo-passthrough.<你的子網域>.workers.dev` 貼進「轉接位址」，並在「存取金鑰」填同一個
token。Worker 只轉 GET 的 `/v8/finance/chart/{symbol}`；每次上游請求有 8 秒 `AbortController` 逾時，
回應串流超過 2 MiB 會中止。上游明確回 429／403 時會保留狀態與 `Retry-After`，不換第二個上游猛打。

配額預設使用每個 isolate 內、每 IP／每 token 60 次／分鐘的記憶體 guardrail。正式使用建議在 Cloudflare
建立名為 `QUOTE_RATE_LIMITER` 的 Rate Limiting binding（60 次／60 秒），讓配額跨 isolate 共用；
Worker 會用 `ip:<來源 IP>` 與 `token:<SHA-256 token hash>` 兩個 key。綁定存在但無法使用時回 **503**，
配額超額回 **429** 與 `Retry-After`；沒有綁定時才使用原本的 isolate 內配額。

接之前先確認那個出口真的乾淨（在**那台**機器上打，在這台上測是測不出來的）：

```bash
curl -s 'https://query1.finance.yahoo.com/v8/finance/chart/VT?range=5d&interval=1d' | head -c 200
curl -s -H 'X-Quote-Token: <同一個金鑰>' \
  'https://你的轉接位址/v8/finance/chart/VT?range=5d&interval=1d' | head -c 200
```

看得到 `chart`、`result` 的 JSON 才有希望；還是 429 就是那個出口同樣被標，換一台再試。
轉接位址與金鑰存在資料庫的 `settings` 裡（`quote_endpoint`／`quote_token`），所以網頁上改一改就生效，
不用動 php.ini、也不用重啟 PHP；留空就是直接打 Yahoo。通過它的只有代號與日期區間，
你的持股、交易、金額一律不會離開這台伺服器。

### 被擋下來之後不再猛打

換到乾淨的出口只解決了一半，另一半是不要把新出口也打回黑名單，所以 `app/QuoteService.php`：

- 任何一個來源回 429 或 403，那個來源就暫停 30 分鐘（對方給了 `Retry-After` 就聽對方的，
  下限 1 分鐘、上限 6 小時）。暫停期間按「更新報價」一個要求都不發出去，`bin/refresh-prices.php` 亦然。
- 同一輪也不會繼續問剩下的投資物：換一檔、換一個網域，出去的還是同一個出口。
  那些在訊息裡是「N 檔略過」，不是「N 個失敗」。
- 一檔投資物在 10 分鐘之內抓過就不再抓，按兩次更新不會變成兩倍要求；手動輸入的價格不算「抓過」，
  鎖成手動的匯率也不會每輪占用一次要求。**唯一的例外**是還欠著十年歷史的那檔（見下一節）。
- 相鄰兩檔之間歇 300 ms。
- 網頁按「更新報價」另有整體時間上限，預設 240 秒；快到上限時不再開新要求，並把剩下的列成略過。
  命令列排程不受這個網頁預算限制。
- 不想等：到設定頁按「儲存並解除暫停」；換一個轉接位址也會自動把暫停清掉。

四個數字不想用預設值的話，用資料庫裡的設定列改（對應的環境變數也行，但這台的 php.ini
可能由主機面板在管、跟別的站台共用同一個 PHP pool，環境變數反而不是個能轉的旋鈕）：

```bash
sqlite3 data/portfolio.sqlite "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '3')
  ON CONFLICT(key) DO UPDATE SET value = '3'"
```

| 設定列 | 對應環境變數 | 預設 |
| --- | --- | --- |
| `quote_min_refresh_minutes` | `QUOTE_MIN_REFRESH_MINUTES` | 10（分鐘內同一檔不重抓；0＝每輪都重抓） |
| `quote_cooldown_minutes` | `QUOTE_COOLDOWN_MINUTES` | 30（被擋下來之後暫停多久） |
| `quote_refresh_budget_seconds` | `QUOTE_REFRESH_BUDGET` | 240（網頁單次更新上限；設定頁可填 5–600 秒） |
| — | `QUOTE_GAP_MS` | 300（相鄰兩檔之間歇多久） |

不管出口怎麼換，有兩件事不變：不在 Yahoo 上的代號（私人持股、`.IL` 那種國際訂單簿）一律走手動價格；
而暫停與略過都不影響手動價格——人填的價格永遠照樣生效。

### 十年日線只補一次

回報要畫十年曲線，而一般更新只抓六個月，所以 `syncHistory()` 會替「最老那根日線還沒回到十年前」的
投資物多補一發 `range=10y`。這一發是整條流程裡最大的要求：一支 ETF 約 250 KB、兩千五百根日線。

問題是很多代號永遠到不了那條線——2020 年成立的基金沒有 2017 年的日線，Yahoo 也不會憑空變出來。
少了下面這道記號，每一輪更新都會多打幾發最大的要求、拖幾百 KB，再把幾千行原封不動重寫一次
（實測：四檔共 445 KB、4,180 行寫入）。於是 `syncHistory()` 有兩道門檻：歷史深度與每日 high／low 覆蓋；
兩者任一還欠，就會共用同一次 `range=10y` 要求補齊：

1. 最老的那根已經回到 115 個月之前 → 不補（`HISTORY_DEEP_MONTHS`）。
2. 還沒回到那條線，但上一次補抓之後最老的那根並沒有再往前移動 → 這個代號的歷史就到那兒了，
   九十天內不補（`DEEP_RETRY_DAYS`）。

第二道結論記在 `settings` 表，鍵名 `deep_oldest_<投資物 id>`，值長成 `2020-06-01|2026-11-22 12:05:02`
（到得了的那一天 ＋ 什麼時候問的）。三個刻意的細節：

- **補抓失敗不會寫下這個結論**。打不通與「沒有更早的資料」是兩件事，混為一談會讓一個代號再也沒補過。
- **讀不懂的值一律當「還得再問」**。寧可多發一發，也不要某個代號悄悄少了幾十年。
- **移除投資物時記號跟著刪**。`id` 會被下一個投資物重用（刪掉最大那筆之後 SQLite 就還給你同一個）。

還欠這一發的投資物**不受 10 分鐘暫停約束**（`refresh()` 先問 `deepOwed()`，再問 `recentlyFetched()`）：
失敗了下一輪就要再試，不能因為「十幾秒前才抓過」一直被擋。少了這一句，以後每加一檔都會先假裝
「資料不足」整整一個排程週期——正式區踩過這個坑：七檔新建的 ETF 在建立該輪深抓失敗，之後每一輪都被
暫停跳過，畫面上只看到「資料不足」，一個錯誤訊息都沒有。

而這一發失敗現在會講出來，四個地方都看得到：

- 畫面閃訊寫明是哪幾檔，同一個原因只列一行（`3 檔的十年歷史沒補上（來源回覆 HTTP 500）：A、B、C…`，
  超過八檔截斷）。來源整個掛掉時，幾十行重複訊息會把這條最該一眼看到的訊息淹死。
- `bin/refresh-prices.php` 那行回報多一個計數：`十年歷史 +2514 根、1 檔沒補上`。
- 兩者的回傳碼：欠的十年歷史沒補上就回 1，就算報價本身有更新到。少了它，排程會以為這一輪是乾淨的。
- 新增的高低價記號可用下面的 SQL 查看；它不是錯誤，而是「這個代號還有幾根缺 high／low、上次什麼時候問過」。
  滾動 `range=10y` 的最早日期會隨時間往前移，因此深抓後仍缺最早幾根時，`syncOldestMissingRange()` 會用
  最早缺漏日前後 7／45 天的定區間再問一次；Yahoo 本身回 `NULL` 的日期仍留空，不會用收盤價補造。

這兩個數字是常數，沒做成旋鈕：要改就是 `app/QuoteService.php` 裡那一行。想手動看或清：

```bash
sqlite3 data/portfolio.sqlite "SELECT key, value FROM settings WHERE key LIKE 'deep_oldest%'"
sqlite3 data/portfolio.sqlite "SELECT key, value FROM settings WHERE key LIKE 'deep_high_low_%'"
sqlite3 data/portfolio.sqlite "DELETE FROM settings WHERE key LIKE 'deep_oldest%' OR key LIKE 'deep_high_low_%'"  # 下一輪全部重補一次
```

## 匯率與多幣別

`fx_rates.units_per_usd` = 「一美元換多少單位的該幣別」，USD 永遠是 1。換算公式：

```
目標金額 = 原金額 ÷ 原幣別每美元單位數 × 記帳幣別每美元單位數
```

所以只要美元對各幣別匯率齊全，跨市場組合的總值就是正確的。本系統使用的 Yahoo `XXX=X` 報價本身就是
「一美元換多少單位的該幣別」，例如 `GBP=X` 0.78、`HKD=X` 7.82、`JPY=X` 150.25；系統會原樣寫入，
不做倒數。匯率隨報價一起更新；新幣別不需要動手填數字——新增投資物、補一筆該幣別的交易、或把記帳幣別切過去，
都會順帶向 Yahoo 抓一次匯率（`Handlers::ensureFxFor()`）。設定頁的「每美元」欄留空按儲存也是同樣的意思：
先刪除手動列，再直接向 Yahoo 抓取。自動列也可按「重新抓取」強制更新；曾寫入反向外匯的資料庫，
可用 `php bin/refresh-prices.php --fx-only` 只修復非手動匯率，不重抓任何投資物價格。
幣別代碼服務端會驗證格式（2-5 個大寫英文字母），避免 XSS 或 SQL 注入透過幣別欄位進入系統。

只有填了數值的才會被鎖成 `manual = 1`，之後的自動更新不會蓋掉它；要改回自動，按該列的「改回自動」。
每一列都有「刪除」，USD 是基準（固定 1，不存進資料庫）所以擋掉不讓刪。
某幣別缺匯率時，該筆持股的換算金額顯示 `--`，不會假設 1:1 蒙混過去；
若刪掉的剛好是記帳幣別，合計也會暫時變成 `--`，下次按「更新報價與匯率」會自動把它抓回來。

## 成本與損益算法

- **平均成本** = （買進金額 + 買進手續費）÷ 股數。手續費資本化進成本，報酬率不會虛高。
- **賣出**：以賣出當下的平均成本結轉，`已實現損益 = (賣出金額 − 賣出手續費) − 股數 × 平均成本`，
  剩餘持股的成本池同步扣減，之後再買不會把已實現的損益攪混。
- **未實現損益** = 市值 −（現持股數 × 平均成本）。
- 賣超過持股會被擋掉（`賣出 99999 股超過目前持股 150 股`），不會寫進資料庫。

---

## 現金

交易紀錄頁下方的「現金」區塊只管一件事：**餘額**。你記「存入／提取」，系統把每筆交易的結算
自動算進去，同一張表分成存入合計、提取合計、買進支出、賣出收入與餘額六欄，最後一欄換算成記帳幣別。

- 買進扣掉 `股數 × 成交價 + 手續費`，賣出加入 `股數 × 成交價 − 手續費`，都用該投資物的計價幣別入帳。
- 餘額是**推算**出來的，不是另一份要維護的帳：補登、改價、刪除任何一筆交易，餘額自己跟著變，
  不存在「交易改了但現金忘記調」這種不一致。測試就專門守著這件事（刪除賣出紀錄後，那筆進項會回到餘額裡）。
- 每個幣別獨立一列，合計欄先各自換算再加總；缺匯率的幣別顯示 `--`，合計也跟著顯示 `--`，
  按「更新報價與匯率」補齊後就恢復。存入一個從沒出現過的幣別，會順帶向 Yahoo 抓它的匯率。
- 餘額可以為負，那是「這筆買進沒有相對應的存入」，不是錯誤，也不會動到持股與損益的計算。
- 現金紀錄有自己的刪除按鈕，刪掉一筆只少那一個金額，不會連帶影響任何交易。

### 範圍：所有組合或單一組合

總覽右上角有一個「範圍」選單，可在**所有組合**與任何一個個別組合之間切換，選起來就固定，下次開網頁還是在同一個範圍。
回報與交易紀錄頁也有同一個選單，而且三個頁面共用同一個選擇：在總覽切到「所有組合」，回報與交易紀錄跟著看所有組合。
選「所有組合」時，同一檔投資物在幾個組合各有持股會**合併成一行**，股數相加、成本加權平均，代號旁標「N 個組合」；
合併後的已實現損益仍是各組合自己算出來的數字相加，不會讓 A 組合的賣出去實現 B 組合的成本。
「最近交易」與交易紀錄的「歷史成交」此時會多一欄「組合」，「現金與各幣別」與各卡片的合計也一併跨組合加總；「抓取最新報價」按鈕則改為更新全部組合。
選單一組合時就是那個組合自己的持股與成本；投資物頁一律跟著目前選定的那個組合，不受「所有組合」影響。
交易紀錄頁的**寫入**動作（新增一筆、存入／提取）永遠只進一個真實組合，不會寫進「合計」；合併顯示時現金區上方會標明那是哪一個組合的現金。

### 總覽上的現金與各幣別市值

總覽頁有一張「現金」卡（換算成記帳幣別的合計，下方列出各幣別餘額），下面接著一張「現金與各幣別」表：
每個幣別一列，分別是**持股市值（原幣）**、**現金餘額（原幣）**，最後一欄才是兩者相加後換算成記帳幣別的金額。
所以港幣持股的市值直接看 HKD 那一列的 `HK$`，不用自己拿匯率回推；美元與日幣同理。
某一幣別只有持股沒有現金（或相反）時，該欄顯示「無持股」／「無現金紀錄」而不是 0，避免把「沒記」當成「剛好為零」。
缺匯率的幣別換算欄顯示 `--`，合計也跟著 `--`，補齊匯率就恢復。

「組合市值」卡下方有一行**佔整個投資組合百分之幾**：分母是「持股市值＋現金」都換算成記帳幣別之後的總數，
所以全部是現金時顯示 0%，全押在持股上時顯示 100%。任何一邊缺價或缺匯率時，這行改寫「佔比需等持股市值與匯率齊全」，
不拿一個偏小的分母算出誇大的百分比。「現金」卡只顯示現金本身——合計與各幣別餘額——不重複列第二個百分比。
現金為負（買進沒有對應存入）時，持股的佔比會超過 100%，是同一個算法的正確結果，不是誤差。

### 回報頁

選單上排在「總覽」與「交易紀錄」之間，範圍選單與總覽共用同一個（在總覽選「所有組合」，回報就看所有組合）。
兩張表：**期間表現**（1 天、7 天、1／3／6／9／12 個月、本年至今、24／60／120 個月）與**每年度表現**（資料裡出現的每個年份，
今年那一列會標「年度尚未結束」）。每一列兩欄並列：

| 欄位 | 算法 |
| --- | --- |
| **持股** | 每天以「當日股數 × 當日收盤」算市值。碰到買賣的那一天才用成交金額切段，各段以時間加權（chain-linking）串起來，所以中途加碼買進不會被當成報酬、部分賣出也不會。 |
| **持股＋現金** | 持股市值加現金餘額的總值，用同樣方式切段；切段點換成**存入／提取**（視為注資／抽資而剔除）。手續費是從現金付出去的，所以自然反映在這一欄。 |

其餘要知道的：

- 報表是**表格不是圖**，也沒有警示與提醒。
- 兩欄都是**固定匯率**下的表現：`fx_rates` 只保留每種幣別的最新一筆，歷史匯率無從取得，所以跨幣別的數字不含匯率漲跌。
- 持股與總值報酬使用 `close`，與成交價同口徑；現金股利不會自動入帳，因此這兩欄是價格與已記錄現金流的表現，不是完整的含股息總回報。策略頁另用 `adj_close` 分析含股利路徑。
- 期間起點在資料裡找不到（歷史還沒那麼長）時該列顯示 `--` 並寫明原因；起點只差一個月之內時會沿用最早一筆並在備註講清楚。
- 缺歷史日線的投資物會被排除在報酬之外，頁面上方會點名；沒有匯率時整個報酬不計算（避免用錯的幣別比出來）。
- 期末仍有持股缺價時，期末持股與總值顯示 `--` 並標明「總值不完整」，不會把缺價那一檔當成零而得出偏小的合計。
- 120 個月與較早的年度需要十年歷史，按過「更新報價與匯率」才有資料；那一輪之後的更新只補最近半年，不會重複抓十年。

---

## 排程更新報價

```bash
php bin/refresh-prices.php                  # 全部組合
php bin/refresh-prices.php --portfolio=2    # 只更新某個組合
php bin/refresh-prices.php --fx-only        # 只強制修復非手動外匯，不抓投資物價格
php bin/refresh-prices.php --help           # 含 --data-dir、--tz
```

`--fx-only` 不能與 `--portfolio` 同時使用；它會把使用中的幣別與所有非手動匯率列都重新向 Yahoo 查一次，
並在寫入前反轉 Yahoo 的外匯方向。手動匯率不會被覆蓋。

回傳碼：0 全部抓到；1 有報價或匯率沒抓到（**一筆都沒更新到也算**，例如資料庫還沒有任何投資物）；
2 參數寫錯或 `--portfolio` 指向不存在的組合。以前打錯編號會讓排程拿到 0，看起來像更新成功了。

寫錯的參數一律中止而不是忽略：`--data-dir` 之類的選項被默默丟掉時，那支程式會照樣動到預設的
正式資料庫。`bin/` 的四支腳本（`refresh-prices.php`、`backfill-adj.php`、`seed-demo.php`、
`create-admin.php`）都使用同一套解析（`app/cli.php`）與 `app/config.php` 的路徑規則；未指定時使用
`PORTFOLIO_DATA_DIR`，再否則使用正式部署資料庫或專案下的 `data/`。

```bash
php bin/backfill-adj.php                 # 把所有已有的日線補上 adj_close
php bin/backfill-adj.php --instrument=9  # 只補那一檔
php bin/backfill-adj.php --dry-run       # 只報告會寫多少列，不動資料庫
```

這一支只在 `UPDATE prices SET adj_close = ... WHERE ... AND adj_close IS NULL`：不改 `close`、不新增日線列，
所以跑錯、跑兩次、跑到一半斷掉都不會動到任何人的持股成本或總覽數字，重複執行是安全的。它刻意只接受
日線口徑的區間（`1mo`…`10y`、`ytd`），**`max` 會被擋下來**：那個區間讓來源退回月度 K 棒（實測 221 根，日期
全是月底），和 `prices` 裡一日一列對不上，回填等於白跑一趟。來源那批沒有 `adjclose` 的（加密貨幣、部分外匯）
會回報 0 列，那一檔就繼續用未調整價格，讀它的地方會把口徑標出來。`--dry-run` 計算的是來源日期與
資料庫既有且 `adj_close IS NULL` 的日線交集，不會把來源回傳的 bar 數當成寫入數，也不會寫任何資料；
指定 `--instrument` 時，待補總數與剩餘數也只統計那一檔。

- Windows 工作排程器：`schtasks /Create /SC DAILY /TN quotes /TR "php C:\path\bin\refresh-prices.php" /ST 09:30`
- Linux crontab：`30 9 * * 1-5 cd /srv/stock-portfolio && php bin/refresh-prices.php`

## 登入

整個系統一組共用密碼，作用只有擋掉外人，不做多使用者資料隔離：登得進來的人看到的是同一份組合。

```bash
php bin/create-admin.php --username=你的帳號          # 建立或更新那唯一一組帳號
php bin/create-admin.php --username=改名 --force      # 忘記密碼／換帳號名稱，整組蓋掉
php bin/create-admin.php --help                       # 含 --password-from-stdin、--data-dir
```

- **只有命令列能建立帳號**，這是刻意的。網頁上留一個「第一次進來先設密碼」的欄位，公網上一掃描到端口就有人能抢先設定。
  會動這台機器的人本來就摸得到 `data/portfolio.sqlite`，所以從 CLI 建立並沒有多要求什麼權限。
- 密碼至少 10 個字元，存進資料庫的是 `password_hash()` 的結果（bcrypt），不是明文，也永遠不會出現在網頁上。
- **登入狀態**：關瀏覽器即登出；連續 8 小時沒有動作，或登入滿 **6 小時**，都會要求重新登入。
  登出會換掉 session id，登入成功時再換一次，所以登入前拿到的那個 id 之後沒有用。
  session 強制使用 cookie、啟用 strict mode，不接受網址帶進來的 session id。
- **暴力嘗試**：同一來源 IP 連續錯 5 次鎖定 15 分鐘，計數與解鎖時間存在資料庫的 `login_throttle` 表裡
  （重啟服務也不會讓它消失）。計數以 `REMOTE_ADDR` 為 key，所以從別處亂試密碼的人鎖不到你——這一版之前
  是把失敗次數記在 `access` 那一列，等於任何人都能把機主鎖在門外 15 分鐘。一小時沒有動靜就重新計數
  （呼應「連續」），超過一天沒出現的來源 IP 會被清掉。
  鎖定期間即使打對密碼也照樣拒絕，訊息會寫還剩幾分幾秒。帳號與密碼都只回同一句「帳號或密碼不正確」，
  不會告訴你哪一半錯了。比對只看 `REMOTE_ADDR`，不採信 `X-Forwarded-For`，否則任何人都可以自己挑要鎖誰。
- **改密碼**在設定頁的「登入與密碼」：要填對目前密碼，新密碼要輸兩次且不能跟舊的相同。
  改完之後這個瀏覽器還在上線，其他瀏覽器的登入狀態下一次請求就失效（session 裡記的是密碼 hash 的指紋，
  hash 一變就被擋在門外）。
- **登入這組資料不在備份檔裡**：`access` 與 `login_throttle` 都刻意不列進備份，還原舊備份不會把密碼一起倒回，
  也不會順帶解開（或加上）誰的鎖定，更不會因為備份檔裡沒有這兩張表而報錯。
  換到新機器還原之後，要在那台機器上重新跑一次 `php bin/create-admin.php`。
- 走 HTTPS 時 session cookie 自動加上 `Secure`（偵測 `$_SERVER['HTTPS']`），不用改 `app/config.php`；
  若是 TLS 在反向代理終止、網頁層只看得到 `X-Forwarded-Proto`，就把 `session_secure_cookie` 設成 `true`。

## 備份與還原

設定頁最下面有一個「備份與還原」。一份備份就是整個業務資料庫：投資組合、投資物、交易、現金紀錄、
報價、匯率與設定都在同一個 `.sqlite` 檔裡；帳號、密碼雜碼、暴力破解計數與本機報價出口／金鑰會先移除。

- **立即備份**：用 SQLite 的 `VACUUM INTO` 取一致快照（WAL 的內容也一併折進去），寫進
  `data/backups/manual-YYYYMMDD-HHMMSS.sqlite`，不用停服務。超過 **7 份**會自動刪掉最舊的。
- **只做手動備份**：系統沒有自動備份排程；需要定期保護時，由管理員手動執行或另行安排受控的備份工作。
- **下載／刪除**：列表上每一份都能單獨下載或刪除。檔名只認 `manual-` 與 `pre-restore-` 兩種、
  後面接時間戳的檔案，所以 `../portfolio.sqlite` 之類的路徑進不來。
- **上傳並還原**：選一個備份檔、勾「我了解這會覆蓋現在的全部資料」，系統先驗證 SQLite 完整性、
  外鍵與必要欄位，通過才替現況存一份 `pre-restore-*.sqlite`，再把七張業務表放進**同一個交易**逐表覆蓋，
  最後再跑一次完整性與外鍵檢查。驗證或還原失敗都不動現有資料，也不會白白耗掉一份安全快照。
  那份 `pre-restore` 會上列表，按「退回」就換得回去，而換回去之前同樣會先驗證、再存一份快照。
- 還原是「掛載來源檔、逐表複製」，不是把 `data/portfolio.sqlite` 蓋掉：`php -S` 抓著那個檔，
  Windows 不讓正在使用的檔案被替換。也因為如此，`AUTOINCREMENT` 的計數器不會跟著備份走，
  系統會把它推過還原進來的最大 id，否則下一筆新交易會直接撞上剛還原進來的那一列。
- 還原時**報價出口與金鑰維持本機設定**：備份檔裡的 `quote_endpoint` 與 `quote_token` 不會覆蓋
  當前機器的設定，避免還原舊備份時悄悄把報價要求導向別台主機（SSRF 防護）。
- 上傳大小由 php.ini 決定；`start-windows.bat`／`start.sh` 已把 `upload_max_filesize` 與
  `post_max_size` 提到 64 MB。超過上限時頁面會明講是檔案太大、資料庫沒有動到，
  不會讓你誤以為表單過期。
- 不想用網頁也行：停服務後整份複製 `data/` 就是完整備份，或在 shell 用
  `sqlite3 data/portfolio.sqlite ".backup backup.sqlite"`。

備份檔本身就是一般 SQLite 資料庫，可以用任何工具開起來看，但**放在 `data/` 底下，等於你的持股與
現金資料**，別傳到不放心的地方。這個專案沒有 `.gitignore`；若另建版本庫，請自行排除整個 `data/`，
提交前也要確認沒有把資料庫或備份交給不信任的人。

`data/`、`app/`、`bin/`、`tests/`、`worker/` 與專案根各有一份 `.htaccess` 全拒規則，防的是「Web 前端的
文件根被指到專案根」這種配置——那時 `data/portfolio.sqlite` 會整包被下載走，而快照包含持股與現金資料。
nginx 不讀 `.htaccess`，所以只要文件根仍是 `public/`（宝塔預設的 nginx 設定就是），這些檔案完全不起作用、
也不影響現行站台；它們只在改用 Apache／httpd 時成為第二道柵欄。

### 把舊的時間戳搬正

統一之前的 `created_at`／`fetched_at` 是 UTC 寫的，比現在的鐘慢八個小時。它們只是紀錄，不參與任何計算
（持股、現金、回報讀的都是你自己填的 `traded_on`／`moved_on` 與日線的 `as_of`），放著不會算錯錢。
要把它們一起搬正，先按上面按一次「立即備份」，再跑這一段：

```sql
UPDATE portfolios    SET created_at = datetime(created_at, '+8 hours');
UPDATE instruments   SET created_at = datetime(created_at, '+8 hours');
UPDATE transactions  SET created_at = datetime(created_at, '+8 hours');
UPDATE cash_movements SET created_at = datetime(created_at, '+8 hours');
UPDATE prices        SET fetched_at = datetime(fetched_at, '+8 hours');
UPDATE fx_rates      SET fetched_at = datetime(fetched_at, '+8 hours');
UPDATE access        SET created_at = datetime(created_at, '+8 hours');
```

這一段不講條件，跑兩次就多八小時，所以要嘛先備份、要嘛先在副本上試。方向錯了就把 `'+8 hours'` 改成 `'-8 hours'`。
`settings` 裡那筆「上次更新」由來就是 PHP 蓋的章，已經在 +8 這一邊，不要在這一節動它——同一檔資料庫裡兩個鐘差八小時，
正是這一節在修的事。搬完之後新寫進去的列由 PHP 蓋章，不會再分岔。

> 本專案的 `data/portfolio.sqlite` 已在 2026-11-21 跑過一次這一段（6,723 列），**不要再跑第二次**。
> 跑之前可以先確認有沒有必要：七個欄位最新的一枚章若都還在 UTC 現在時間之前，就還沒搬；已經超出來的就是搬過了。

## 交易策略：樂活五線譜與樂活通道

左側導覽「設定」下方是「交易策略」下拉選單，點擊後可進入「樂活五線譜」（`/strategy`）。它是正式頁面，不需要實驗室開關。它跟回報頁沒有關係——
那邊算的是「你的錢賺了多少」，這一頁算的是「某一檔投資物現在的價格，對它自己的歷史而言算高還是低」：
拿一段期間的股價算出一條趨勢線，再看現在的價離這條線多遠（以它自己歷史的波動為單位），
遠到上方就是貪婪、遠到下方就是恐懼。

- **五線譜**（`app/Strategy.php`）：取該檔最近 **42 個月（3.5 年）** 的日線，對「含股利的收盤價 vs 第幾天」
  做最小平方法，得到趨勢線與一組殘差標準差 SDE；把趨勢線上下各平移 1 與 2 倍 SDE 就是五條線。
  現在價到趨勢線的距離除以 SDE 得到 σ 位置，對應到五個區間與一句動作（－2SD 以下重倉布局、
  ±1SD 之內持有、＋2SD 以上獲利了結）。
- **價格口徑用 `prices.adj_close`**：五條線要貼的是「配息有續投入」的那條路徑，線與現價必須同一個口徑。
  某一根沒有 `adj_close`（或它 ≤ 0）時退回 `close`，並在結果裡回報「有幾根未含股利」；人工標價那一棒
  天生沒有含息口徑，一律算未含股利。畫面會把混用口徑的警告集中列出，不讓它假裝可比。
- **三道門檻**：R² < 0.5 直接標成「不適用」、0.5～0.7 標「謹慎參考」、斜率為負時追加警告。
  SDE 一併換算成占均價的百分比（CV），因為每檔是用自己歷史的波動定距離。
- **通道**：20 週**布林**通道。日線先按 ISO 週收成週線，取最近 20 週的週收盤算簡單均線與母數標準差，
  上下軌為中軸 ±2 倍標準差；另附 2 週／19 週均線作為時機參考。窗口含本週最後一根，因此會隨最新報價變動。
- **算不了就留白**：不到 250 根日線算不出五線譜，湊不滿 20 週算不出通道；兩條門檻分開標示，
  不讓兩三根 K 棒連出來的線假裝是趨勢。
- **只讀**：這一頁只讀 `prices` 與 `instruments`，不寫任何表格、不碰交易、現金、持股成本或報價抓取，
  也不會替你下單。
- **線會重畫**：每天多一根 K 棒，五條線與 SDE 全部重算，所以它只能講「現在的判讀」。拿它回測會比實盤
  樂觀，因為過去那條線是帶著之後的資料畫出來的。財報、成長性、安全性那些來源要靠儀表板補的區塊，
  這個系統沒有資料，也就不會假裝有。
- **版面是兩張表**：判讀表與數字表同順序對齊，所有警告與留白原因集中在「要留意的」區塊；
  點擊投資物可查看五線譜與通道圖。

`tests/lehuo-math.php` 在合成序列上驗 42 個月窗口、按天斜率、R² 分級、五個 σ 區間、20 週布林通道、
同週只留最後一根、窗口裁切與未含股利標記。`tests/smoke.sh` 驗 `/strategy` 需要登入、沒有任何實驗開關
也能使用、圖表端點回傳 JSON、舊的 `/lab*` 網址回 404，以及造訪前後正式表格筆數完全一致。

## 實驗室

左側導覽「交易策略」下方保留「實驗室」（`/laboratory`），目前是沒有啟用項目的空狀態頁。
新功能與源碼會先在獨立環境測試，確認不影響現有系統後再提升；此頁沒有寫入端點、沒有新資料表或
`lab_*` 設定，schema 維持 v8，舊 `/lab*` 與已移除的 `/laboratory/source-check` 均回 404。
smoke 驗證登入保護、導覽順序、空狀態內容，以及造訪前後正式表格完全一致。

## 環境變數

| 變數 | 預設 | 用途 |
| --- | --- | --- |
| `PORTFOLIO_DATA_DIR` | 正式部署為 `/www/portfolio-data`；其他部署為專案下的 `data` | 資料庫位置，相對路徑會以專案根目錄解析 |
| `QUOTE_ENABLED` | 開啟 | 設 `false` 完全停用網路抓取（離線機、CI） |
| `QUOTE_TIMEOUT` | 12 | 單次 HTTP 秒數 |
| `PORTFOLIO_TZ` | 沒有 | 日期與時間戳用的時區，例如 `Asia/Hong_Kong`。沒設時：php.ini 的 `date.timezone` 生效，它也沒設就由 app 補 `Asia/Hong_Kong`（UTC+8，不撥夏令時間，也不再跟著伺服器的 UTC 跑）。四支 `bin/` 程式另有 `--tz=` |
| `QUOTE_RANGE` | 6mo | 每次更新抓的日線長度 |
| `QUOTE_MIN_REFRESH_MINUTES` | 10 | 同一檔投資物在這麼久之內不重抓（資料庫裡的同名設定列優先，見「出口被擋時」） |
| `QUOTE_COOLDOWN_MINUTES` | 30 | 來源回 429/403 之後暫停多久（資料庫裡的同名設定列優先） |
| `QUOTE_GAP_MS` | 300 | 一次更新裡相鄰兩檔之間歇多久 |
| `QUOTE_HISTORY_RANGE` | 10y | 回報頁需要的長歷史長度，每檔只抓一次 |

**正式資料庫只有一份。** 專案位於 `/www/wwwroot/portfolio` 且 `/www/portfolio-data/portfolio.sqlite` 存在時，
網頁、CLI 與部署腳本都預設使用 `/www/portfolio-data/portfolio.sqlite`，不再讀取專案內的舊 `data/` 預設庫。
其他部署位置仍使用專案下的 `data/`。明確設定 `PORTFOLIO_DATA_DIR` 或 `--data-dir` 永遠優先；
測試則必須指向自己的 `/tmp` scratch，不能繼承正式路徑。

**時間戳只有一個時鐘。** 資料庫裡每一列的 `created_at` 與 `fetched_at` 都是 PHP 寫進去的
（`now_stamp()`，`app/helpers.php`），所以它們跟頁面上的「上次更新」、跟交易表單預設的「今天」一定是同一個時區；
SQLite 自己的 `datetime('now')` 是 UTC，跟 `PORTFOLIO_TZ` 無關，混用就會讓同一個檔案裡出現兩種時間。
`app/schema.sql` 殘留的那幾個 `DEFAULT (datetime('now','+8 hours'))` 只在你用 `sqlite3` 手動插列時才會用到。
這之前寫進資料庫的戳記仍是 UTC（早八個小時）——它們只是紀錄用的，不參與任何計算，
持股、現金、損益、回報讀的都是 `traded_on`／`moved_on`／`as_of` 這些你自己填的日期；
要把它們一起搬正，見上面「把舊的時間戳搬正」。

## 部署到 VPS（nginx + php-fpm）

`public/index.php` 是前端控制器，路由走 `PATH_INFO`（`/index.php/transactions`），
**不需要任何 rewrite 規則**，`php -S`、Apache、nginx + php-fpm 都是同一份設定。
DocumentRoot 指到 `public/`，讓 `data/` 落在網頁根目錄之外。以下以 Debian 12／Ubuntu 24.04、
程式放 `/var/www/stock-portfolio` 為例。

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-sqlite3 php-curl php-mbstring
php -m | grep -E "pdo_sqlite|curl|mbstring|openssl"        # 四行都要有

# 上傳備份檔還原要用，預設 2M 太小；Apache／php-fpm 讀的是 php.ini，不是 start.sh 的 -d 參數
sudo sed -i 's/^;\?upload_max_filesize =.*/upload_max_filesize = 64M/' /etc/php/8.2/fpm/php.ini
sudo sed -i 's/^;\?post_max_size =.*/post_max_size = 64M/' /etc/php/8.2/fpm/php.ini
# 不設的話 PHP 用 UTC，「今天」的預設日期與上次更新時間會差一天（app 自己會補 Asia/Hong_Kong，但 php.ini 寫清楚比較省事）
sudo sed -i 's/^;\?date\.timezone =.*/date.timezone = Asia/Hong_Kong/' /etc/php/8.2/fpm/php.ini
sudo systemctl restart php8.2-fpm

sudo rsync -av ./stock-portfolio/ /var/www/stock-portfolio/
sudo chown -R www-data:www-data /var/www/stock-portfolio/data     # 只有 data/ 需要可寫
sudo chmod 750 /var/www/stock-portfolio/data                      # PHP 啟動時也會收緊為 0750／DB 0640
```

```nginx
server {
    listen 443 ssl http2;
    server_name portfolio.example.com;
    root /var/www/stock-portfolio/public;          # 網頁根只到 public/，data/ 進不去
    client_max_body_size 64M;                      # 跟 php.ini 的兩個上限對齊
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers EECDH+CHACHA20:EECDH+CHACHA20-draft:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:!MD5;
    add_header X-Frame-Options DENY always;

    location ~ \.php(/|$) {
        fastcgi_split_path_info ^(.+\.php)(/.*)$;  # 少了這一行，/index.php/transactions 會 404
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        include fastcgi_params;
        fastcgi_hide_header X-Frame-Options;       # PHP 已送同名標頭，避免瀏覽器收到兩份
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
php /var/www/stock-portfolio/bin/create-admin.php --username=你的帳號   # 在伺服器上建立那組密碼
crontab -e    # 加一行：15 8,13,18 * * * cd /var/www/stock-portfolio && /usr/bin/php bin/refresh-prices.php >> data/refresh.log 2>&1
```

Linux 的 PHP 直接吃系統信任的 CA 封包，Windows 那份 `cacert.pem` 的麻煩在這裡不存在。
有 TLS 之後 session cookie 自己會帶 `Secure`；如果 TLS 在反向代理就終止了，把 `app/config.php` 的
`session_secure_cookie` 設成 `true`。

`bin/create-admin.php` 與網頁伺服器同時寫同一個 SQLite 檔案是安全的（WAL 模式，兩邊都等 5 秒鎖），
所以不必停服務就能建帳號或改密碼。

樣式檔的網址自帶檔案的修改時間（`asset()` 生出 `?v=`）：宝塔那套 nginx 對 `.js`／`.css` 設了
`expires 12h`，改了樣式若不換網址，瀏覽器最長 12 小時還在拿舊檔，看起來就像「改了沒用」。代價是每次動過
樣式，各頁面下一次載入會多抓一次 CSS；沒動過就還是同一個網址，照樣命中快取。

### Apache

DocumentRoot 指到 `public/`，`AllowOverride None`，`DirectoryIndex index.php` 就好；此時 Apache 不讀
`.htaccess`，但 `data/` 本來就在文件根之外，不需要那層全拒規則。`PATH_INFO` 在 Apache 預設可用。
若故意保留文件根誤指到專案根、要依靠各目錄的 `.htaccess` 阻擋下載，就必須把 `AllowOverride` 設成
`All`（或至少允許所需指令），不能同時寫 `None`。要限制備份上傳，可直接在 `php.ini` 設；使用 mod_php
且要讓 `.htaccess` 的 `php_value` 生效時，則需允許 `FileInfo` override。

## 部署腳本的安全用法

先看說明，再用預設的只讀檢查模式；不要直接把 `--apply` 或 `--auto` 當成試跑：

```bash
bash deploy-bt.sh --help
bash deploy-bt.sh                              # 只檢查、不寫入、不連網路更新
```

- `--apply` 會寫入資料庫、調整 `data/` 權限並產生 nginx 片段；依預設還會執行一次**真實報價更新**。
  正式 `portfolio.sqlite` 為 640，`backups/` 為 700，備份／匯入快照為 600；不會把 0600 備份放寬成 0640。
  不該連外部來源時加 `--skip-net`，並先用 `--data-dir=/tmp/...`、`--snapshot=/tmp/...` 指定暫存目標。
- `--auto`（或 `--write-nginx`）還會修改寶塔站台設定。偵測 `root` 與 extension include 時會忽略 nginx 註解；
  片段則只認工具產生的完整標記行，避免重複插入。extension 已有同名檔時先備份並核對，失敗時逐字節還原，
  符號連結或無法識別的檔案直接拒絕。其他路徑先備份主設定、跑 `nginx -t`，通過才 reload，失敗自動還原；
  正式站仍應先另外備份資料庫與站台設定，並自行確認 `--conf` 指向正確的站台。
- 預設 check 以真正的唯讀 PDO 開啟 SQLite；沒有 WAL 時使用 immutable 唯讀模式，不會建立 WAL／SHM。
- `tests/deploy-auto-harness.sh` 是本地假站台／假 nginx 的驗證，不會修改正式站。它預設強制建立合成 SQLite，
  不會讀取正式快照；只有明確設定 `SNAPSHOT_SRC=/path/to/file.sqlite` 才使用指定快照。工作檔放在自己的
  `/tmp` scratch，並由 `tests/run.sh` 在完整 smoke 前自動執行：

```bash
bash tests/run.sh
# 或只跑部署驗證（預設合成庫）：
bash tests/deploy-auto-harness.sh
# 只有要測指定快照時才顯式指定：
SNAPSHOT_SRC=/tmp/example.sqlite bash tests/deploy-auto-harness.sh
```

正式機的 OPcache 由 `/www/server/php/83/etc/php.ini` 載入模組，但只在本專案的
`/www/server/php/83/etc/portfolio-fpm.conf` pool 啟用；記憶體 128 MB、檔案時間戳每 2 秒檢查，CLI 不使用。
修改後先跑 `/www/server/php/83/sbin/php-fpm -tt`，成功再 `systemctl reload php-fpm-83`；備份與還原資料庫不需要
為此關閉 OPcache。

## 效能設計

- 報價刷新只寫入新日線或內容變動的歷史列；完全相同的舊列不會被刷新 `fetched_at`，當日現價仍每次更新。
- 最新價由投資物清單驅動，以主鍵查找每檔最近日期，不再分組掃描整張歷史價格表。
- 回報與策略頁的日線查詢在 SQL 先限制所需時間窗；較早交易仍全部回放，保留長期持倉的成本與基值。
- 總覽先以兩次批次查詢取得所有選定組合的投資物與交易，再一次重播成本池；持股、現金、最近交易與頁面資訊共用同一個短暫 WAL 讀取快照。
- 回報的跨投資物日線改為一次載入；策略總表以投資物、最新日期、依各檔截止日取歷史三次批次查詢完成，不再每檔重複查詢。
- 報價寫入前先完成驗證；既有日線只讀取本次 payload 實際包含的日期，並以每批 400 個日期分塊，避免深抓時把整段歷史重讀進記憶體。
- `idx_prices_adj_pending`、`idx_prices_fetched_recent` 與 `idx_prices_range_pending` 都是只涵蓋待處理資料的部分索引；schema v8 自動建立，遷移測試也驗證實際查詢計劃會選用它們。
- portfolio FPM pool 啟用專用 OPcache（128 MB、每 2 秒檢查檔案時間戳）；其他 pool 與 CLI 不啟用，部署後仍會自動看到新碼。
- 交易紀錄每頁 100 筆，CSV 用游標逐列輸出；設定儲存前只正規化轉接位址一次，不在資料庫交易內重複驗證。

## 測試

```bash
bash tests/run.sh
```

`tests/run.sh` 用 `mktemp` 在 `/tmp` 建立全新的資料庫與工作目錄；未設 `PORT` 時選系統分配的臨時埠，
設了就先行檢查占用。啟動後除 HTTP 200 外，還要求回應內文與安全標頭能識別本系統，才開始 smoke。
若以服務帳號執行，可用 `runuser -u www -- env TMPDIR=/tmp bash tests/run.sh`。smoke 的設計是在所有報價與匯率操作前啟動
`tests/quote-stub.php`，使用固定日線與固定匯率，不連 Yahoo 或其他外部網路；結束時還原原出口，
清理伺服器、記錄與 scratch。高風險 POST 另逐條驗未登入 303、壞 CSRF 419與未知 ID 400，樂活圖表端點
驗開啟時 200 JSON、`id=abc` 400、關閉時 404。

測試伺服器透過 `auto_prepend_file` 載入 `tests/quote-transport.php`，把已經通過正式 SSRF 驗證的
`example.com` 測試出口轉到本機假伺服器；正式程式沒有這個 hook，正式 SSRF 與 DNS pinning 防護也沒有放寬。
完整 smoke 已可重複執行並印出 `ALL PASS`。另可獨立執行純算式、安全與遷移檢查（資料庫類測試使用自己的 `/tmp` scratch）：

```bash
php tests/lehuo-math.php
php tests/quote-high-low.php
php tests/link-math.php
php tests/performance-chain-math.php
php tests/positions-cost-math.php
php tests/quote-currency-math.php
php tests/database-migration.php
php tests/auth-throttle-race.php
php tests/backup-restore-safety.php
php tests/cli-safety.php
```

`link-math.php` 目前驗 16 項 Yahoo 連結；遷移測試會用合成 schema v2 舊庫確認升到 v8、
補上 `adj_close`／`high`／`low`／`exchange` 與三個待處理部分索引、移除已退役的 `lab_turtle_*` 資料表與
`lab_*` 設定，清除不完整或違反價格契約的舊高低價，並移除 13 筆不符合價格契約的舊紀錄。
v8 也會在單一交易內重建 `prices`、`transactions` 與 `cash_movements`，讓既有資料正式受日期、幣別、來源與外鍵約束；
遇到無法挽救的無效交易或現金日期會整次回滾，不會靜默刪除財務紀錄。測試另外驗證部分索引查詢計劃、正式預設路徑與明確暫存路徑。
`quote-high-low.php` 另外驗 Yahoo 高低價解析、當日 metadata 後備、部分回應不抹掉既有值，
以及十年歷史深補的標記與重試節流。

斷言涵蓋：日期與數量驗證、加權平均成本與已實現損益手算、跨幣別獨立重算、手動價格覆蓋與清除、
歷史日線不抹掉已存前收、新幣別自動匯率、組合管理與 `back` 白名單、Yahoo 連結格式、小數股數與單價精度、
CSV 的 UTF-8 BOM／公式跳脫、404／405 路由、深歷史補抓與節流、七個頁面無 PHP notice。
`performance-chain-math.php` 與 `positions-cost-math.php` 另在自己的 `/tmp` scratch 資料庫驗算同日存入＋買進、
跨日淨額歸零、非正基值略過、賣超截斷、費用符號、極小持股與出清後成本池歸零、累計數量／現金溢位、
`adj_close` 不污染原始收盤報酬，以及 WAL 快照、巢疊讀取與例外回滾。策略測試另驗總表批次查詢次數。

任一生失敗就以非零狀態結束；環境上限或 fixtures 不足而無法執行的段落會明確列成 `SKIP`，
總結只會印 `PASS WITH SKIPS`，不會印 `ALL PASS`（`SKIP` 本身不改變退出碼）。
直接跑 `tests/smoke.sh` 屬進階用法：必須明確提供本機 `BASE`、暫存 `DB` 與相同的 `PORTFOLIO_DATA_DIR`，
並拒絕正式 `data/portfolio.sqlite`；通常不要繞過 `tests/run.sh`。
現金一節獨立用一個組合跑，好讓每個數字都能手算：存入多少、買進扣 `股數×價+手續費` 多少、
賣出加 `股數×價−手續費` 多少、沒有存入也能買（餘額轉為負數）、刪除賣出交易後那筆進項自己回到餘額、
刪除現金紀錄只影響現金，外加金額／幣別／方向／日期四種驗證都回 400 且資料庫一筆也不增加。
總覽一節在同一個組合再買一檔港股，檢查「現金與各幣別」表有沒有 HKD 那一列、該列的市值是不是直接以
`HK$` 顯示（而不是先換算掉）、沒存入時的負餘額，最後把頁面上「組合市值」卡＋「現金」卡兩個數字相加，
和表格合計欄核對到小數點下兩位，確定各幣別加總的換算沒有錯接。
回報一節另用一個組合手灌兩根日線（2025-01-02 收 100、2025-06-02 收 150），把兩個百分比算成手可驗證的數字：
持股 +50.00%（1,000→1,500）、持股＋現金 +25.00%（2,000→2,500，閒置的 1,000 現金拖住分母），
11 個期間各有一列、比歷史長的期間明講「歷史還不夠長」、年度列標出「年度尚未結束」，
並確認選單上「回報」排在總覽與交易紀錄之間、`back=/reports` 在白名單內、更新報價的按鈕留在回報頁。
同一節也檢查第一次更新真的從本機假來源存入十二年前的日線（AAPL 早於兩年的日線固定一根），以及總覽的「組合市值」卡顯示 60.0%
（同一個分母的另一半），而「現金」卡只剩現金本身的數字，不再多列一個 40.0%。
範圍一節另外用兩個組合同一檔投資物（甲 buy 100@10 再 sell 50@30、乙 buy 100@20，手動價 25）把合併那一行的
150 股／加權成本 `US$16.67`／市值 `US$3,750.00`／已實現 `+US$1,000.00` 一一對住手算結果——若改成單一成本池，
已實現會變成 750、均價會變成 15.00，兩個數字都不同時守住。也測了「N 個組合」badge、最近交易多出的「組合」欄、
選擇會記住、切回單一組合、不存在的 `scope` 與 `scope=2 OR 1=1` 這種非純數字的參數都退回上一次的選擇。
交易紀錄那一節用同樣兩個組合清點交易筆數：甲 2 筆、乙 1 筆、選「所有組合」時 3 筆且多一欄「組合」寫著各筆來自哪個組合，
日期篩選在合併清單上照樣生效（06-03～06-05 恰好抓到乙的買進與甲的賣出），合併時現金區會標明它只屬於其中一個組合，
而「新增一筆」的投資組合下拉仍然開在剛選的那個組合上。
同一節也數「匯出 CSV」交出去的列數：`scope=all` 是資料庫裡的全部交易（此時遠超過甲＋乙那三筆，兩個數字不同才證明它真的跟著範圍走）、
`scope=甲` 兩筆、`scope=乙` 一筆，投資物與日期篩選也一起帶進檔案，而列數和頁面上「匯出 CSV（N 筆）」寫的那個 N 是同一個數；
範圍指到沒有交易的組合就只出一個 column header，那一列連結本身也不出現。
持股明細那一節直接用 SQL 灌一個十二筆持股的組合（刻意不動到 session 的目前組合），清點總覽那個表格真的有十二列：
以前只列前十大、其餘要再進另一個頁面看，現在全列在同一頁，也確認頁面上不再出現 `/holdings` 連結；
路由一節另外把 `/holdings` 當未知路徑測一次 404，選單上那一項也真的不見了。
刪除確認框那一節用一個名字裡帶單引號的組合（`Tom's 長期`）與一檔代號帶 `'` 的投資物驗：頁面要輸出 `Tom\u0027s`
這種跳脫後的 JSON 字串，而不是讓那顆引號把字串收掉——屬性解完實體之後若留下半截 JS，`confirm()` 丟出 SyntaxError、
`return false` 永遠不會跑到，按鈕按下去就繞過確認把資料刪了，而自備的引號也能順勢在那一列塞進自己的程式碼。
表格欄位那一節掃六個頁面（連同合併範圍的交易紀錄）渲染出來的每一張表：表頭有幾個 `<th>`，每一列就得有幾個 `<td>`
（`colspan` 折算在內）——少一個表頭不會報錯，只會讓它後面的標題全部錯一位。
命令列那一節跑的是 `bin/` 那四支：看不懂參數就回 2，而且是在開啟資料庫之前中止，所以 `--data-dir` 打錯字時不會照樣動到
`data/portfolio.sqlite`；`--portfolio` 沒寫 `=`、寫成 `2,3`、或指到不存在的組合都中止；一筆報價都沒更新到的排程回 1 而不是 0，
頁面上的「上次更新」也不會被蓋成剛剛。示範資料的護欄數的是交易、現金流與投資組合三張表，所以「剛建好第一個組合、還沒有成交」
的資料庫不會被灌進去；`--force` 只重播有所有權記錄且內容未被修改的示範組合，不會刪除其他 Demo 資料。時區一節分別用 `date.timezone` 留空、`UTC` 與 `Europe/Berlin`
三種起點，守住「php.ini 沒提」與「明寫 UTC」不是一回事（PHP 8 的 `ini_get()` 兩者都回報 UTC，這裡看的是 `get_cfg_var()`），
而 `PORTFOLIO_TZ` 給了一個不存在的時區時會講出來、然後沿用原本的時區而不是當機。時間戳那一節把四張表的 `created_at`
與 `prices`／`fx_rates` 的 `fetched_at` 逐一跟同一次執行的「上次更新」相比，差必須在 30 秒內，也守住沒有一列是沒帶時間戳就寫進去的；
其中一列是繞過 PHP、用 `sqlite3` 手插的，為的是確認 schema 那個 `datetime('now','+8 hours')` 預設值落在同一個時鐘上，而不是 UTC。
`bin/create-admin.php` 那一節把 STDIN 接成 pipe
——藏不住輸入的那種場合——要求它先警告「會顯示在螢幕上」，同時不能把打進去的那串密碼印出來；存進資料庫的是 `$2y$10$`
開頭的 bcrypt hash，`--force` 換的是同一列帳號。
報價與匯率全程使用本機確定性假來源，不依賴外部網路；同一份 fixtures 可反覆重跑。
備份一節跑完整個 round trip：快照真的寫進 `data/backups/`、下載下來是正經 SQLite 檔且筆數對得上、
刪一筆交易再用那份檔案還原後交易自己回來、覆蓋前先存的 `pre-restore` 快照內容確實是覆蓋前的狀態、
按「退回」也確實退回去；再把備份檔塞一筆高 id 的組合進去還原，確認新的 `AUTOINCREMENT` 計數器不會撞上它。
其餘是拒收：文字檔、沒勾確認、沒選檔案、沒 token、不認識的檔名、`../portfolio.sqlite` 這種路徑，
以及超過 `upload_max_filesize`／`post_max_size` 的兩種不同超限情況（後者是 PHP 整份丟掉請求，頁面仍要講對原因），
每種都確認資料庫一筆也沒有動到；最後連備九份只留七份與刪除也一起驗。
登入一節跑在整個套件的最前面與最後面：先確認還沒有帳號時，每個頁面都只講出那行 CLI 指令、不漏出任何資料，
再用 CLI 建立帳號（太短的密碼、含空格的帳號都建立不了，被拒絕時也不會留下半筆資料）。
接著驗證：簽入前 `/` 與 `/transactions` 都 303 到登入頁並記住原本要去哪裡；`POST /instruments` 這種寫入被擋掉且資料庫仍然 0 筆；
登入頁有 CSRF 欄位但不會把密碼回顯；沒帶 token 的登入請求回 419；帳號打錯與密碼打錯得到同一句話；
連續錯五次真的鎖住——`locked_until` 寫在資料庫裡，此時連正確的密碼也被拒，重啟服務也不會解開；
解鎖之後登入成功會把計數與鎖一起清掉，而登入前拿到的那個 session id 在升級之後不管用。
鎖也是按來源 IP 分開記的（`login_throttle` 那一張表）：用 SQL 先灌一筆外人 IP 的鎖進去，擋不到這裡登入，
而這裡登入成功只清掉自己那一列，那個 IP 的鎖與計數照舊留在表裡，等它自己的時間到。
最後一節驗登出（同一個 cookie 立刻開不了網頁，頁面寫「已登出」而不是默默失敗）、四種改密碼的拒收
（舊密碼錯、太短、兩次不一樣、跟現用的相同）都不動到 hash；改成功之後舊密碼失效、其他瀏覽器被踢、
這個瀏覽器還在上線。再把一份「把帳號密碼改成別人」的備份檔還原進去：資料倒回去了，門鎖那組資料一個位元組也沒有變；
而一份根本沒有 `access` 表的舊備份，也照樣要能還原——這一條是特意測的，它同時守住超載上傳的說明訊息
必須排在登入守門之前（PHP 的啟動警告會讓 `session_start()` 失敗，順序錯了就一直看到的是登入頁）。

## 安全設計

- 每個 POST 都要 CSRF token，不符回 **HTTP 419**（比 302 回同一頁更難被忽略）。登入表單也不例外。
- 所有 SQL 走 prepared statement；所有輸出走 `e()`（`htmlspecialchars` + `ENT_QUOTES|ENT_SUBSTITUTE`）。
- 導向用的 `back` 參數有白名單，外部網址不會被反射。
- Session cookie 為 HttpOnly／SameSite=Lax；偵測到 HTTPS 自動加 `Secure`。
- 所有頁面送 `X-Frame-Options: DENY`，nginx 也會在 server scope 強制同一規則並隱藏 PHP 的重複標頭。
- nginx 僅啟用 TLS 1.2／1.3，不再啟用 TLS 1.1 或 3DES；正式部署應先以 `nginx -t` 驗證再 reload。
- SQLite 的 `data/` 啟動時收緊為 0750、資料庫為 0640；權限收緊失敗會直接中止，不會無聲退回過寬權限。
  整個 schema 遷移在單一交易內完成，失敗會整筆回滾，不會留下半套新欄位或新價格表。
- 整個系統只有一組共用密碼（`access` 資料表那唯一一列），作用是擋掉外人，不是把資料切成一人一份：
  登得進來的人看到的都是同一份組合，也包括按下「上傳並還原」能把整份資料換掉的能力。
  資料庫裡只有 bcrypt hash，密碼不會出現在網頁、備份檔或 log；帳號與鎖定的計數只存在本機資料庫。
- **這層密碼抵不住不安全的網路**：HTTP 明文傳輸時，同一路段的人仍能側看到你的 cookie 或密碼。
  要跨網路用就掛 TLS（上面 nginx 那段已是 443），或只在 LAN／VPN 內部開著。
- 備份檔名只接受 `manual-*`／`pre-restore-*` 兩種格式，`../portfolio.sqlite` 這種路徑進不去；上傳的檔案先驗
  SQLite 的檔頭（`SQLite format 3`）才碰資料庫，還原本身也在一個交易裡跑，檢查不過整筆退回。
- 備份檔本身是**沒有密碼的一般 SQLite 資料庫**（登入那組刻意不進備份），拿到檔案等於拿到你的持股與現金，
  存放位置請自己把關。

## 目錄結構

```
app/            bootstrap, config, helpers, cli, Database, App(路由), Auth(登入／登出／改密碼／鎖定),
                Handlers(控制器), Positions(成本、損益與現金引擎), Performance(回報的時間加權報酬),
                QuoteService(Yahoo 報價與十年日線、轉接位址與節流), Markets(代號/幣別),
                Settings, Backup(備份與還原), Strategy(樂活五線譜與通道), views/, schema.sql
public/         index.php（唯一網頁根）+ assets/style.css、assets/chart.umd.min.js
bin/            refresh-prices.php、backfill-adj.php（回填 `prices.adj_close`）、seed-demo.php、
                create-admin.php（唯一能建立帳號的地方）
data/           portfolio.sqlite、backups/（自動建立；沒有 .gitignore，請自行排除版本庫）
tests/          run.sh（/tmp scratch＋臨時埠＋部署驗證＋完整 smoke）+ smoke.sh（端到端檢查與 SKIP 統計）+
                quote-stub.php（確定性假報價端點）與 quote-transport.php（僅測試的 transport hook）+
                lehuo-math.php（樂活算式）+ quote-high-low.php（高低價抓取與深補）+
                link-math.php（16 項 Yahoo 連結，不是績效鏈結）+ performance-chain-math.php（回報鏈結）+
                positions-cost-math.php（成本池）＋ quote-currency-math.php（來源幣別防護）+
                backup-restore-safety.php（惡意備份與本機政策）＋ auth-throttle-race.php（併發鎖定）+
                cli-safety.php（seed 所有權／rollback 與 backfill dry-run）+
                database-migration.php（v2→v8 價格契約、財務表重建、退役實驗表清理與部分索引）＋ deploy-auto-harness.sh（合成部署測試）
worker/         yahoo-passthrough.js（要換出口時部署到 Cloudflare Workers 的轉發程式，網站本身不跑它）
```

## 已知限制

- Yahoo 報價有延遲，正式對帳請以券商對帳單為準（頁面頁尾也這麼寫）。
- 加密貨幣 24/7 交易，但「當日漲跌」仍沿用一般日線的最後一根計算，那是 Yahoo 的日界（UTC），
  對幣別來說不等於你想看的時段；持股本身的損益計算不受影響。
- 單價小於 1 的投資物會自動顯示 4～6 位小數（`US$0.0513` 不會被壓成 `US$0.05`），
  市值與損益的合計仍一律取 2 位；股數欄的精度到小數點下 4 位。
- 持股、現金與組合的表格欄位很多，窄視窗下會在表格容器內橫向捲動（頁面本身不會溢出，刪除欄位要往右捲才看得到）。
- 現金只承認兩件事：你親手記的存入／提取，以及每筆交易的結算。股利、利息、券商贈金這種進項不會自動入帳，
  看到就自己記一筆；網站不會去猜配息日該給你多少錢。
- 回報頁的持股與持股＋現金欄是**固定匯率**下的 **`close` 口徑**：成交價、持股市值與報酬不再混用
  `adj_close`。現金股利不會自動入帳，所以結果不包含未記錄的股息；策略頁才使用 `adj_close` 的含股利口徑。
  跨幣別的報酬不反映匯率漲跌；十年以上的期間要按過「更新報價與匯率」才有資料。
- 匯率只存「每美元單位數」，若記帳幣別是美元以外的冷門幣別且 Yahoo 無對應匯率代碼，請在設定頁手動填。
- 還原是整份資料庫覆蓋，沒有「只還原某一個投資組合」這種事；想保留兩個版本就各別下載一份備份檔存著，
  或把 `data/portfolio.sqlite` 複製到別處。
- 登入只有一組共用密碼：不做多使用者，也沒有一人一份資料的隔離。忘記密碼只能到那台機器上重跑
  `php bin/create-admin.php --username=新名字 --force`（整組蓋掉），沒有「寄信重置」這條路，因為這套工具
  本來就不寄信；也沒有兩步驗證（TOTP／SMS）。要兩個人各看各的資料，目前的做法是各自跑一份、各自一個 `data/`。
