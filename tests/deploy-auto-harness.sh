#!/usr/bin/env bash
# 本地驗證 deploy-bt.sh 的 --auto：用一份假的寶塔站台設定 + 一個假的 nginx 指令，
# 驗「備份 → 放片段 → nginx -t → 通過才 reload → 不通自動還原」這一整條路徑。
# 純 scratch：site 目錄是假的，資料庫預設在工作目錄建立合成 SQLite；只有明確設定 SNAPSHOT_SRC
# 才會使用指定快照，未設或空值都不會讀取專案或伺服器上的真實資料。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"   # stock-portfolio
case "$ROOT" in
    '' | /) echo "安全的專案根目錄確認失敗：$ROOT" >&2; exit 2 ;;
esac
if [ ! -f "$ROOT/deploy-bt.sh" ]; then
    echo "找不到 $ROOT/deploy-bt.sh，請從完整的專案目錄執行這個 harness" >&2
    exit 2
fi

TMP_BASE="${TMPDIR:-/tmp}"
if [ ! -d "$TMP_BASE" ]; then
    echo "暫存目錄不存在：$TMP_BASE；請設定 TMPDIR 或改用 /tmp" >&2
    exit 2
fi
TMP_BASE="$(cd "$TMP_BASE" && pwd)"
WORK="$(mktemp -d "${TMP_BASE%/}/portfolio-deploy-auto.XXXXXX")"
work_is_safe() {
    [ -n "$WORK" ] || return 1
    case "$WORK" in
        "${TMP_BASE%/}/portfolio-deploy-auto."*) return 0 ;;
        *) return 1 ;;
    esac
}
cleanup() {
    if work_is_safe; then
        rm -rf -- "$WORK" || true
    fi
}
trap cleanup EXIT

PHP="${PHP_BIN:-php}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "找不到 PHP：$PHP；請安裝 PHP 8.1 以上，或設定 PHP_BIN=/path/to/php" >&2
    exit 2
fi
if ! "$PHP" -v >/dev/null 2>&1; then
    echo "PHP 無法執行：$PHP；請設定 PHP_BIN=/path/to/php" >&2
    exit 2
fi
SNAPSHOT_SRC="${SNAPSHOT_SRC:-}"
SITE="$WORK/site"
NGINX_DIR="$WORK/nginx-bin"
VHOST="$WORK/panel/vhost/nginx"
EXT_DIR="$VHOST/extension/bt.example.com"
EXT_TARGET="$EXT_DIR/portfolio-deploy.conf"
EXT_CONF="$VHOST/bt.example.com.conf"      # 有寶塔留的 extension include
INLINE_CONF="$VHOST/plain.example.com.conf"  # 沒有，得插進 server{ }
TAG="deploy-bt.sh 放的部署片段"

PASS=0; FAILED=0
t() {
    if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  ok   $1"
    else FAILED=$((FAILED+1)); echo "  FAIL $1（期望 [$2] 實際 [$3]）"; fi
}
n() { echo; echo "---- $* ----"; }
md5() { md5sum "$1" | cut -d' ' -f1; }
cnt() { grep -c -F -- "$2" "$1" 2>/dev/null || true; }
loghas() { cnt "$WORK/log/$1.out" "$2"; }
has() { local n; n=$(cnt "$1" "$2"); [ "${n:-0}" -ge "${3:-1}" ] && echo true || echo false; }
rc() { [ "$(cat "$WORK/log/$1.rc")" = "$2" ] && echo true || echo false; }
reloads() { grep -c -- '-s reload' "$WORK/log/$1.log" 2>/dev/null || true; }
exists() { [ -e "$1" ] && echo present || echo missing; }

mkdir -p "$NGINX_DIR" "$EXT_DIR" "$VHOST" "$WORK/log" "$WORK/caseconf"
mkdir -p "$SITE/public" "$SITE/app" "$SITE/bin" "$SITE/data"
if [ -z "$SNAPSHOT_SRC" ]; then
    echo "未設 SNAPSHOT_SRC，在 $WORK 建立合成 SQLite（不讀取真實快照）"
    SNAPSHOT_SRC="$WORK/synthetic.sqlite"
    if ! "$PHP" -r '
        $p = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $p->exec(file_get_contents($argv[2]));
        $p->exec("INSERT OR IGNORE INTO access (id, username, password_hash) VALUES (1, \"harness\", \"synthetic\");");
    ' "$SNAPSHOT_SRC" "$ROOT/app/schema.sql"; then
        echo "無法建立合成快照；請確認 PHP 有 PDO SQLite" >&2
        exit 2
    fi
elif [ ! -f "$SNAPSHOT_SRC" ]; then
    echo "明確指定的 SNAPSHOT_SRC 不存在或不是檔案：$SNAPSHOT_SRC；未改用合成庫" >&2
    exit 2
fi
cp -- "$SNAPSHOT_SRC" "$WORK/import.sqlite"
chmod 750 "$SITE/data"
mkdir -p "$SITE/data/backups"
chmod 700 "$SITE/data/backups"
cp -- "$WORK/import.sqlite" "$SITE/data/backups/keep.sqlite"
chmod 600 "$WORK/import.sqlite" "$SITE/data/backups/keep.sqlite"
for f in public/index.php app/App.php app/bootstrap.php app/schema.sql bin/create-admin.php; do
    printf '<?php\n' > "$SITE/$f"
done
printf '<?php echo "stub refresh\\n"; exit(0);\n' > "$SITE/bin/refresh-prices.php"

# 假 nginx：模擬真 nginx 的 `-t`，三條規則寫在下面的註解裡（哪些檔案會被載入、socket 打錯、location 重複）。
cat > "$NGINX_DIR/nginx" <<'SH'
#!/usr/bin/env bash
echo "nginx $*" >> "${NGINX_LOG:-/dev/null}"
if [ "$1" = "-t" ]; then
    BT_DEFAULT='location ~ [^/]\.php(/|$)'
    # (0) 只有主設定裡真的寫了 include .../extension/<站名>/*.conf，那個目錄的內容才會被載入。
    #     路徑要用陣列存：這個工作區的路徑中有空白，用未加引號的變數展開會被切開。
    sources=("$NGINX_CONF")
    uncomment() { sed -E 's/[[:space:]]*#.*$//' "$1" 2>/dev/null || true; }
    if [ -f "$NGINX_CONF" ] && uncomment "$NGINX_CONF" \
        | grep -q -E 'include[[:space:]]+[^;]*/extension/[^;/]+/\*\.conf'; then
        sources+=("$NGINX_EXT_DIR")
    fi
    # (1) 掃描範圍內出現 NGINX_BAD 那個 token 就當驗證不過（模擬 socket 打錯那種）。
    if [ -n "${NGINX_BAD:-}" ]; then
        for where in "${sources[@]}"; do
            [ -e "$where" ] || continue
            if grep -r -q -F --include='*.conf' -- "$NGINX_BAD" "$where" 2>/dev/null; then
                echo "nginx: [emerg] bad reference found in $where" >&2
                exit 1
            fi
        done
    fi
    # (2) 同一個 server 裡 location 字面重複就不過：寶塔的 enable-php-83.conf 已經用過 PHP 那條。
    #     這正是 2026-11-22 那輪 --auto 在真 nginx 上被打回的原因（腳本當時的字面跟寶塔的一模一樣）。
    for where in "${sources[@]}"; do
        if [ -f "$where" ]; then
            grep -hoE '^[[:space:]]*location[^{]+' "$where" 2>/dev/null
        elif [ -d "$where" ]; then
            find "$where" -type f -name '*.conf' -exec grep -hoE '^[[:space:]]*location[^{]+' {} + 2>/dev/null
        fi
    done | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//' \
      | { printf '%s\n' "$BT_DEFAULT"; cat; } | sort | uniq -d | grep -q . && {
        echo "nginx: [emerg] the configuration file test failed (duplicate location)" >&2
        exit 1
    }
    exit 0
fi
exit 0
SH
chmod +x "$NGINX_DIR/nginx"

bt_conf() { # bt_conf <path> <有extension include:1|0> <域名>
    local ext=''
    [ "$2" = "1" ] && ext="
    include $VHOST/extension/$3/*.conf;"
    cat > "$1" <<CONF
server
{
    listen 80;
    server_name $3;
    index index.php index.html index.htm default.php default.htm;
    root $SITE;
    include enable-php-83.conf;
    location ~ /nginx.conf { deny all; }
    access_log off;
    error_log off;$ext
}
CONF
}
run_case() { # run_case <case> <conf> <extra...>；參數裡出現 BROKEN-SOCKET 就讓假 nginx 驗證不過
    local name="$1" conf="$2"; shift 2
    local bad='' case_rc=0
    case "$*" in *BROKEN-SOCKET*) bad='BROKEN-SOCKET' ;; esac
    : > "$WORK/log/$name.log"
    if PATH="$NGINX_DIR:$PATH" NGINX_LOG="$WORK/log/$name.log" \
        NGINX_BAD="$bad" NGINX_CONF="$conf" NGINX_EXT_DIR="$EXT_DIR" \
        bash "$ROOT/deploy-bt.sh" --site-dir="$SITE" --php-bin="$PHP" \
            --snapshot="$WORK/import.sqlite" --conf="$conf" --skip-net "$@" \
            > "$WORK/log/$name.out" 2>&1; then
        case_rc=0
    else
        case_rc=$?
    fi
    printf '%s\n' "$case_rc" > "$WORK/log/$name.rc"
}

bt_conf "$EXT_CONF" 1 bt.example.com
bt_conf "$INLINE_CONF" 0 plain.example.com
ORIG_INLINE="$(md5 "$INLINE_CONF")"
cp "$INLINE_CONF" "$WORK/caseconf/inline-original"
ORIG_INLINE="$(md5 "$WORK/caseconf/inline-original")"
ORIG_EXT="$(md5 "$EXT_CONF")"

# ============================================================ 1. check
n "1. 預設（check）：不寫檔、不碰站台設定、不 reload"
CASE1="$WORK/caseconf/case1.conf"
bt_conf "$CASE1" 0 case1
C1_BEFORE="$(md5 "$CASE1")"
run_case check "$CASE1"
t "站台設定一個字節都沒變" "$C1_BEFORE" "$(md5 "$CASE1")"
t "沒有 reload" 0 "$(reloads check)"
t "片段檔還沒落碟" missing "$(exists "$SITE/deploy-portfolio.conf")"
t "片段內容印在輸出裡" 1 "$(loghas check 'client_max_body_size 64M')"
t "第 5 段自報跳過寫入" 1 "$(loghas check '跳過 寫入')"
t "第 5 段說明 --auto 才會動設定" 1 "$(loghas check '--auto 才會動 nginx 的設定')"
t "資料庫快照被讀出來（含帳號那行）" 1 "$(loghas check '帳號已在這份檔案裡')"
t "總結 FAIL 0" 1 "$(loghas check '總結：FAIL 0、注意')"
t "唯讀檢查沒有建立來源 WAL" missing "$(exists "$WORK/import.sqlite-wal")"
t "唯讀檢查沒有建立來源 SHM" missing "$(exists "$WORK/import.sqlite-shm")"
t "來源快照維持 600" 600 "$(stat -c '%a' "$WORK/import.sqlite")"
t "備份目錄維持 700" 1 "$(loghas check '備份目錄 權限 700')"
t "備份檔維持 600" 1 "$(loghas check 'SQLite 備份／快照 權限 600（目標 600）：'"$SITE/data/backups/keep.sqlite")"
t "exit 0" true "$(rc check 0)"

# ============================================================ 2. --apply
n "2. --apply：只寫片段檔，不動站台設定"
run_case apply "$CASE1" --apply
t "站台設定仍未被動" "$C1_BEFORE" "$(md5 "$CASE1")"
t "片段檔寫出來了" present "$(exists "$SITE/deploy-portfolio.conf")"
t "片段檔裡那段完整" 1 "$(cnt "$SITE/deploy-portfolio.conf" 'fastcgi_split_path_info')"
t "沒有 reload" 0 "$(reloads apply)"
t "說明還沒動設定" 1 "$(loghas apply '跳過 動站台設定')"
t "正式資料庫是 640" 640 "$(stat -c '%a' "$SITE/data/portfolio.sqlite")"
t "舊備份沒有被放寬，仍是 600" 600 "$(stat -c '%a' "$SITE/data/backups/keep.sqlite")"
t "exit 0" true "$(rc apply 0)"

# ============================================================ 3. --auto / extension 目錄
n "3. --auto（寶塔留了 extension include）：主設定一個字不改"
run_case auto_ext "$EXT_CONF" --apply --write-nginx --root-dir="$SITE/public"
t "主設定檔一個字節都沒變" "$ORIG_EXT" "$(md5 "$EXT_CONF")"
t "片段落在 extension 目錄" present "$(exists "$EXT_TARGET")"
t "那邊只有一段" 1 "$(cnt "$EXT_TARGET" "$TAG")"
t "補了 root 指向 public" 1 "$(cnt "$EXT_TARGET" "root $SITE/public;")"
t "socket 名字對（83 不帶點）" 1 "$(cnt "$EXT_TARGET" 'fastcgi_pass  unix:/tmp/php-cgi-83.sock;')"
t "沒有重複宣告寶塔 enable-php 那條 location" 0 "$(cnt "$EXT_TARGET" 'location ~ [^/]\.php(/|$)')"
t "用的是 (/.\*)? 那種不同字面" true "$(has "$EXT_TARGET" 'location ~ [^/]\.php(/.*)?$')"
t "server scope 一律拒絕 framing" 1 "$(cnt "$EXT_TARGET" 'add_header X-Frame-Options DENY always;')"
t "PHP 的 frame header 不會跟 nginx 重複" 1 "$(cnt "$EXT_TARGET" 'fastcgi_hide_header X-Frame-Options;')"
t "保留 HSTS" 1 "$(cnt "$EXT_TARGET" 'add_header Strict-Transport-Security "max-age=31536000" always;')"
t "try_files 擋不存在的腳本而不是整個 URL" true "$(has "$EXT_TARGET" 'try_files $fastcgi_script_name =404;')"
t "網頁根不對這件事有提醒" 1 "$(loghas auto_ext '這套系統要的是')"
t "nginx -t 通過" true "$(has "$WORK/log/auto_ext.out" 'nginx -t 通過')"
t "reload 一次" 1 "$(reloads auto_ext)"
t "報告印出怎麼收回" 1 "$(loghas auto_ext '要恢復原樣：rm ')"
t "exit 0" true "$(rc auto_ext 0)"

n "3b. 同一條 --auto 再跑一次（冪等）"
run_case auto_ext2 "$EXT_CONF" --apply --write-nginx --root-dir="$SITE/public"
t "主設定檔仍未被動" "$ORIG_EXT" "$(md5 "$EXT_CONF")"
t "extension 那邊還是只有一段" 1 "$(cnt "$EXT_TARGET" "$TAG")"
t "exit 0" true "$(rc auto_ext2 0)"
EXT_BAK="$(ls -1 "$EXT_TARGET".bak-* 2>/dev/null | head -1)"
t "重跑前先備份既有同名片段" present "$(exists "$EXT_BAK")"
t "備份內容與重跑前的片段一致" 1 "$(cnt "$EXT_BAK" "$TAG")"

# ============================================================ 4. --auto / 插進 server{ }
n "4. --auto（沒有 extension include）：備份後插進 server{ } 結尾之前"
run_case auto_inline "$INLINE_CONF" --apply --write-nginx --root-dir="$SITE/public"
BAKS="$(ls -1 "$INLINE_CONF".bak-* 2>/dev/null | wc -l)"
BAK="$(ls -1 "$INLINE_CONF".bak-* 2>/dev/null | head -1)"
t "留了一份備份" 1 "$BAKS"
t "備份與原檔逐字節相同" "$(md5 "$WORK/caseconf/inline-original")" "$(md5 "$BAK")"
t "片段插進了主設定" 1 "$(cnt "$INLINE_CONF" "$TAG")"
t "那段在最後一個 } 之前" 0 "$(awk -v tag="$TAG" '$0 ~ tag {t=NR} /^\}/ {c=NR} END {print (t<c)?0:1}' "$INLINE_CONF")"
t "檔案結尾仍是那個 }" 1 "$(tail -1 "$INLINE_CONF" | grep -c '^}$')"
t "nginx -t 通過" true "$(has "$WORK/log/auto_inline.out" 'nginx -t 通過')"
t "reload 一次" 1 "$(reloads auto_inline)"
t "報告給出 cp 回去的命令" 1 "$(loghas auto_inline '要恢復原樣：cp ')"
t "沒留 .new 垃圾" missing "$(exists "$INLINE_CONF.new")"

n "4b. 再跑一次 --auto：不重複插入、不重複備份"
run_case auto_inline2 "$INLINE_CONF" --apply --write-nginx
t "設定裡還是只有一段" 1 "$(cnt "$INLINE_CONF" "$TAG")"
t "備份還是只有一份" 1 "$(ls -1 "$INLINE_CONF".bak-* 2>/dev/null | wc -l)"
t "說明不重複插入" 1 "$(loghas auto_inline2 '已經有上次插進去的那段')"

n "4c. 註解掉的 extension include 不算有效配置"
COMMENT_CONF="$WORK/caseconf/comment.conf"
bt_conf "$COMMENT_CONF" 0 comment.example.com
printf '\n# include %s/extension/bt.example.com/*.conf;\n' "$VHOST" >> "$COMMENT_CONF"
EXT_BEFORE_COMMENT="$(md5 "$EXT_TARGET")"
run_case auto_comment "$COMMENT_CONF" --apply --write-nginx --root-dir="$SITE/public"
t "片段改走 inline，而不是 extension" 1 "$(cnt "$COMMENT_CONF" "$TAG")"
t "既有 extension 片段沒有被碰" "$EXT_BEFORE_COMMENT" "$(md5 "$EXT_TARGET")"
t "nginx -t 通過" true "$(has "$WORK/log/auto_comment.out" 'nginx -t 通過')"
t "exit 0" true "$(rc auto_comment 0)"

# ============================================================ 5. nginx -t 不通（inline）
n "5. nginx -t 不通：inline 那條路自動還原，而且絕不 reload"
cp "$WORK/caseconf/inline-original" "$INLINE_CONF"
rm -f "$INLINE_CONF".bak-*
run_case broken_inline "$INLINE_CONF" --apply --write-nginx --root-dir="$SITE/public" --socket=unix:/tmp/BROKEN-SOCKET.sock
BAK2="$(ls -1 "$INLINE_CONF".bak-* 2>/dev/null | head -1)"
t "站台設定回到原樣（逐字節）" "$(md5 "$WORK/caseconf/inline-original")" "$(md5 "$INLINE_CONF")"
t "設定裡已沒有那段" 0 "$(cnt "$INLINE_CONF" "$TAG")"
t "備份留著給人比對" present "$(exists "$BAK2")"
t "完全沒有 reload" 0 "$(reloads broken_inline)"
t "nginx -t 至少跑過兩次（改後一次、還原後一次）" present "$([ "$(grep -c '^nginx -t' "$WORK/log/broken_inline.log")" -ge 2 ] && echo present || echo missing)"
t "講明自動還原" 1 "$(loghas broken_inline '改動已自動還原')"
t "還原後驗證又過了" 1 "$(loghas broken_inline '還原之後 nginx -t 又通過了')"
t "沒有誤報『還原後仍不通』" 0 "$(loghas broken_inline '還原後 nginx -t 仍不通')"
t "exit 1（有 FAIL）" true "$(rc broken_inline 1)"

# ============================================================ 6. nginx -t 不通（extension）
n "6. nginx -t 不通：extension 既有同名檔先備份，失敗後逐字節還原"
rm -f "$EXT_TARGET".bak-*
printf '# 既有設定，不是本工具建立的\nkeep_me yes;\n' > "$EXT_TARGET"
chmod 640 "$EXT_TARGET"
ORIG_PATCH="$(md5 "$EXT_TARGET")"
ORIG_PATCH_MODE="$(stat -c '%a' "$EXT_TARGET")"
run_case broken_ext "$EXT_CONF" --apply --write-nginx --root-dir="$SITE/public" --socket=unix:/tmp/BROKEN-SOCKET.sock
EXT_BAK2="$(ls -1 "$EXT_TARGET".bak-* 2>/dev/null | head -1)"
t "既有片段精確還原（逐字節）" "$ORIG_PATCH" "$(md5 "$EXT_TARGET")"
t "既有片段權限也還原" "$ORIG_PATCH_MODE" "$(stat -c '%a' "$EXT_TARGET")"
t "覆蓋前留下的備份還在" present "$(exists "$EXT_BAK2")"
t "備份與既有原件逐字節相同" "$ORIG_PATCH" "$(md5 "$EXT_BAK2")"
t "主設定檔從頭到尾沒被動" "$ORIG_EXT" "$(md5 "$EXT_CONF")"
t "沒有 reload" 0 "$(reloads broken_ext)"
t "沒有多出主設定備份檔" 0 "$(ls -1 "$EXT_CONF".bak-* 2>/dev/null | wc -l)"
t "沒有殘留片段暫存檔" missing "$(exists "$(ls -1 "$EXT_TARGET".new.* 2>/dev/null | head -1)")"
t "講明既有片段已精確還原" 1 "$(loghas broken_ext '既有同名片段已從備份精確還原')"
t "exit 1" true "$(rc broken_ext 1)"

n "6b. extension 同名檔是符號連結：無法識別，拒絕覆蓋"
rm -f "$EXT_TARGET"
printf 'do not touch\n' > "$WORK/extension-sentinel"
ln -s "$WORK/extension-sentinel" "$EXT_TARGET"
SENTINEL_BEFORE="$(md5 "$WORK/extension-sentinel")"
run_case symlink_ext "$EXT_CONF" --apply --write-nginx --root-dir="$SITE/public"
t "拒絕符號連結同名片段" 1 "$(loghas symlink_ext '同名片段不是一般檔案')"
t "連結目標一個字節都沒變" "$SENTINEL_BEFORE" "$(md5 "$WORK/extension-sentinel")"
t "沒有 reload" 0 "$(reloads symlink_ext)"
t "exit 1" true "$(rc symlink_ext 1)"
rm -f "$EXT_TARGET"

# ============================================================ 7. 線上叫不通
n "7. reload 之後第 7 步發現整個站叫不通：改動收回，並再 reload 回落"
rm -f "$EXT_TARGET"
run_case live_down "$EXT_CONF" --apply --write-nginx --root-dir="$SITE/public" --domain=https://127.0.0.1:9
t "片段收回了" missing "$(exists "$EXT_TARGET")"
t "收回後又 reload 一次（共 2 次）" 2 "$(reloads live_down)"
t "講明是自查收回" 1 "$(loghas live_down '這輪改動先收回')"
t "PATH_INFO 那條 FAIL 仍在（如實報）" 1 "$(loghas live_down '不是 200 就代表第 5 步那段沒生效')"
t "exit 1" true "$(rc live_down 1)"

# ============================================================ 8. 找不到設定檔
n "8. --auto 但 --conf 指到不存在的檔案：不動任何東西"
BEFORE_EXT="$(exists "$EXT_TARGET")"
run_case noconf "$WORK/nope.conf" --apply --write-nginx
t "有 reload" 0 "$(reloads noconf)"
t "報錯指向 --conf/--domain" 1 "$(loghas noconf '找不到該改哪一份站台設定')"
t "片段檔仍寫得出來（給人手動貼）" present "$(exists "$SITE/deploy-portfolio.conf")"
t "exit 1" true "$(rc noconf 1)"

# ============================================================ 9. 用法
n "9. --help 與參數"
if bash "$ROOT/deploy-bt.sh" --help > "$WORK/log/help.out" 2>&1; then HELP=true; else HELP=false; fi
t "exit 0" true "$HELP"
t "usage 裡有 --auto" true "$(has "$WORK/log/help.out" '--auto')"
t "usage 裡有 --conf=PATH" true "$(has "$WORK/log/help.out" '--conf=PATH')"
t "usage 裡有 --root-dir=PATH" true "$(has "$WORK/log/help.out" '--root-dir=PATH')"
t "usage 裡有 --write-nginx" true "$(has "$WORK/log/help.out" '--write-nginx')"
t "usage 講明 nginx -t 通過才 reload" true "$(has "$WORK/log/help.out" 'nginx -t 通過才 reload')"
if bash "$ROOT/deploy-bt.sh" --nope >/dev/null 2>&1; then BAD=false; else BAD=true; fi
t "不認識的參數不是 exit 0" true "$BAD"
if bash -n "$ROOT/deploy-bt.sh"; then SYN=true; else SYN=false; fi
t "bash -n 語法過" true "$SYN"

n "9b. --domain 只接受完整 HTTPS 網址"
DOMAIN_CASE=0
for invalid_domain in '' 'http://example.com' 'file:///tmp/example' '-https://example.com' 'https://' 'https://example.com:70000'; do
    DOMAIN_CASE=$((DOMAIN_CASE + 1))
    if bash "$ROOT/deploy-bt.sh" --site-dir="$SITE" --php-bin="$PHP" \
        --domain="$invalid_domain" > "$WORK/log/domain-$DOMAIN_CASE.out" 2>&1; then
        DOMAIN_RC=0
    else
        DOMAIN_RC=$?
    fi
    t "拒絕不合格網址 [$invalid_domain] 並回 2" true "$([ "$DOMAIN_RC" = 2 ] && echo true || echo false)"
done

# ============================================================ 10. 假 nginx 的自我檢查
# 2026-11-22 那輪在真 VPS 上被打回，是因為片段裡的 location 字面跟寶塔 enable-php-83.conf 裡那條一模一樣
# （[emerg] duplicate location）。這裡把同一條規則放進假 nginx，為了讓那個 bug 在本地就會被抓住，而不是等線上。
n "10. 假 nginx 會擋下舊寫法（這條路徑不能再靠線上驗證）"
rm -f "$EXT_TARGET" "$EXT_DIR"/*.conf
cat > "$EXT_DIR/legacy-bug.conf" <<'L'
location ~ [^/]\.php(/|$) {
    fastcgi_pass unix:/tmp/php-cgi-83.sock;
}
L
if NGINX_CONF="$EXT_CONF" NGINX_EXT_DIR="$EXT_DIR" NGINX_LOG=/dev/null "$NGINX_DIR/nginx" -t 2>/dev/null; then LEGACY=false; else LEGACY=true; fi
t "舊字面（跟寶塔同一條）被擋" true "$LEGACY"
rm -f "$EXT_DIR/legacy-bug.conf"
if NGINX_CONF="$EXT_CONF" NGINX_EXT_DIR="$EXT_DIR" NGINX_LOG=/dev/null "$NGINX_DIR/nginx" -t 2>/dev/null; then CLEAN=true; else CLEAN=false; fi
t "拿掉之後又通過（不是常駐誤判）" true "$CLEAN"
fragment_now="$(bash "$ROOT/deploy-bt.sh" --site-dir="$SITE" --conf="$EXT_CONF" --root-dir="$SITE/public" 2>/dev/null | sed -n '/  | /p' | sed 's/^  | //')"
printf '%s\n' "$fragment_now" > "$EXT_DIR/now.conf"
if NGINX_CONF="$EXT_CONF" NGINX_EXT_DIR="$EXT_DIR" NGINX_LOG=/dev/null "$NGINX_DIR/nginx" -t 2>/dev/null; then NOWOK=true; else NOWOK=false; fi
t "目前腳本產出的片段過得了這條規則" true "$NOWOK"
rm -f "$EXT_DIR/now.conf"

echo
echo "== deploy-auto harness: PASS $PASS、FAIL $FAILED =="
[ "$FAILED" = 0 ] || exit 1
