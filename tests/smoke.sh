#!/usr/bin/env bash
# 端到端 smoke：請用 tests/run.sh 啟動 /tmp 暫存伺服器，不要手接正式站。
#   bash tests/run.sh
# 進階用法必須同時明確給出 BASE、DB、PORTFOLIO_DATA_DIR；本腳本沒有正式資料庫預設值。
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
: "${BASE:?請先執行 bash tests/run.sh，或明確提供本機測試用 BASE}"
: "${DB:?請提供暫存資料庫的完整路徑；不可使用正式 data/portfolio.sqlite}"
: "${PORTFOLIO_DATA_DIR:?請提供與 DB 相同的暫存資料目錄}"
PHPBIN="${PHP_BIN:-php}"
QUOTE_STUB_HOST="${PORTFOLIO_QUOTE_STUB_HOST:-}"
QUOTE_TRANSPORT="${PORTFOLIO_QUOTE_TEST_TRANSPORT:-$ROOT/tests/quote-transport.php}"
[ -n "$QUOTE_STUB_HOST" ] && [ -f "$QUOTE_TRANSPORT" ] || {
    echo "安全停止：缺少 tests/run.sh 提供的本機報價測試轉接。" >&2
    exit 2
}

# 不允許 Git-Bash 把 /instruments 等 back 值改寫成 Windows 路徑。
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

REAL_DATA="$(realpath -m "$ROOT/data")"
DB="$(realpath -m "$DB")"
DATADIR="$(realpath -m "$PORTFOLIO_DATA_DIR")"
if [ "$(dirname "$DB")" != "$DATADIR" ] || [ "$(basename "$DB")" != "portfolio.sqlite" ]; then
    echo "安全停止：DB 必須是 PORTFOLIO_DATA_DIR 下的 portfolio.sqlite。" >&2
    exit 2
fi
case "$DB" in
    "$REAL_DATA"/*)
        REL="${DB#"$REAL_DATA"/}"
        case "$REL" in
            smoke-*/*) ;;
            *) echo "安全停止：拒絕使用正式 data/ 下的資料庫（$DB）。" >&2; exit 2 ;;
        esac
        ;;
esac
[ -f "$DB" ] || { echo "安全停止：找不到暫存資料庫 $DB。" >&2; exit 2; }
if [[ "$BASE" =~ ^http://(127\.0\.0\.1|localhost)(:([0-9]{1,5}))?(/index\.php)?$ ]] \
    && { [ -z "${BASH_REMATCH[3]:-}" ] \
        || { [ "$((10#${BASH_REMATCH[3]}))" -ge 1 ] && [ "$((10#${BASH_REMATCH[3]}))" -le 65535 ]; }; }; then
    :
else
    echo "安全停止：smoke 只接受本機 HTTP BASE，且不可帶使用者資訊、路徑或查詢字串（$BASE）。" >&2
    exit 2
fi

WORK="$(mktemp -d /tmp/portfolio-smoke-work.XXXXXX)"
JAR="$WORK/cookies.txt"
LAST="$WORK/last.html"
FAIL=0
SKIP=0
SKIPS=()

# 結束時只清理本次啟動的假報價伺服器與 /tmp 工作目錄。
STUB_PID=""
cleanup() {
    local rc=$?
    trap - EXIT INT TERM
    if [ -n "$STUB_PID" ]; then
        kill "$STUB_PID" 2>/dev/null || true
        wait "$STUB_PID" 2>/dev/null || true
    fi
    if [ "${SMOKE_DEBUG_KEEP:-0}" = "1" ]; then
        echo "除錯保留 smoke 工作目錄：$WORK" >&2
    else
        rm -rf "$WORK"
    fi
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# 在任何資料庫操作前先確認 HTTP 回應確實來自本系統，而不是任意监听器。
PRE_BODY="$WORK/preflight.html"
PRE_HEAD="$WORK/preflight.headers"
PRE_CODE="$(curl -sS --max-time 5 -D "$PRE_HEAD" -o "$PRE_BODY" -w '%{http_code}' "$BASE/login" 2>/dev/null || true)"
if [ "$PRE_CODE" != "200" ] || ! grep -qF '投資組合追蹤系統' "$PRE_BODY" 2>/dev/null \
    || ! grep -qiE '^X-Content-Type-Options:[[:space:]]*nosniff' "$PRE_HEAD" 2>/dev/null; then
    echo "安全停止：BASE 未通過應用身分檢查（HTTP $PRE_CODE）。" >&2
    exit 2
fi

skip() {
    echo "  SKIP $*"
    SKIP=$((SKIP + 1))
    SKIPS+=("$*")
}

quote_php() {
    PORTFOLIO_QUOTE_STUB_HOST="$QUOTE_STUB_HOST" "$PHPBIN" \
        -d auto_prepend_file="$QUOTE_TRANSPORT" "$@"
}

app_date() {
    PORTFOLIO_DATA_DIR="$DATADIR" "$PHPBIN" -r \
        'require $argv[1]; $d = new DateTimeImmutable("now"); if (($argv[2] ?? "") !== "") { $d = $d->modify($argv[2]); } echo $d->format("Y-m-d");' \
        "$ROOT/app/bootstrap.php" "${1:-}"
}
TODAY="$(app_date)"
TWOYEARS="$(app_date '-2 years')"
DEEP12="$(app_date '-12 years -4 days')"

# q "SELECT ..." -> first column of the first row
q() {
  "$PHPBIN" -d display_errors=stderr -r \
    '$p = new PDO("sqlite:" . $argv[1]); $v = $p->query($argv[2])->fetchColumn(); echo $v === false ? "" : $v;' \
    "$DB" "$1" 2>/dev/null
}

# qf "file" "SELECT ..." and execq "file" "SQL ..." work on any sqlite file, which is how a backup
# gets read before it is uploaded and patched to carry an id the live database has never seen.
qf() {
  "$PHPBIN" -d display_errors=stderr -r \
    '$p = new PDO("sqlite:" . $argv[1]); $v = $p->query($argv[2])->fetchColumn(); echo $v === false ? "" : $v;' \
    "$1" "$2" 2>/dev/null
}

execq() {
  "$PHPBIN" -d display_errors=stderr -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);' \
    "$1" "$2" 2>/dev/null
}

# ini_bytes post_max_size -> 8388608, so the upload tests can be one byte over whatever this server allows
ini_bytes() {
  "$PHPBIN" -r '$v = (string) ini_get($argv[1]); $u = strtoupper(substr($v, -1));
    $m = ["G" => 1073741824, "M" => 1048576, "K" => 1024];
    echo isset($m[$u]) ? (int) $v * $m[$u] : (int) $v;' "$1" 2>/dev/null
}

csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE$1" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }

post() { # path token data...
  local path="$1" token="$2"; shift 2
  # Deliberately no -X POST: curl must downgrade to GET when the app answers a write with 303.
  curl -s -c "$JAR" -b "$JAR" -o "$LAST" -w "%{http_code}" "$BASE$path" -d "_csrf=$token" "$@" -L
}

get() { curl -s -c "$JAR" -b "$JAR" -o "$2" -w "%{http_code}" "$BASE$1"; }

scoped_get() {
    local path="$1" output="$2" scope token
    scope="${path#*scope=}"
    token=$(csrf "$path")
    SCOPE_POST_CODE=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w "%{http_code}" -X POST "$BASE/scope" \
        -d "_csrf=$token" -d "scope=$scope")
    get "$path" "$output"
}

postfile() { # path token -F fields...   (multipart, which -d cannot express)
  local path="$1" token="$2"; shift 2
  curl -s -c "$JAR" -b "$JAR" -o "$LAST" -w "%{http_code}" -X POST "$BASE$path" -F "_csrf=$token" "$@"
}

check()    { if grep -q -- "$2" "$3"; then echo "  ok   $1"; else echo "  FAIL $1 (missing: $2)"; FAIL=1; fi; }
notcheck() { if grep -q -- "$2" "$3"; then echo "  FAIL $1 (found: $2)"; FAIL=1; else echo "  ok   $1"; fi; }
# Fixed-string variants, because \u0027 is a literal backslash in the page and a regex escape in grep.
checkf()    { if grep -qF -- "$2" "$3"; then echo "  ok   $1"; else echo "  FAIL $1 (missing: $2)"; FAIL=1; fi; }
notcheckf() { if grep -qF -- "$2" "$3"; then echo "  FAIL $1 (found: $2)"; FAIL=1; else echo "  ok   $1"; fi; }

start_quote_stub() {
    STUB_DIR="$WORK/stub"
    HITS="$STUB_DIR/hits.txt"
    mkdir -p "$STUB_DIR"
    : > "$HITS"
    local port health code headers
    port="$("$PHPBIN" -r '$s = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$s) { exit(1); } $n = stream_socket_get_name($s, false); fclose($s); echo substr(strrchr($n, ":"), 1);')" || return 1
    QUOTE_STUB_DIR="$STUB_DIR" QUOTE_STUB_TODAY="$TODAY" \
        "$PHPBIN" -S "127.0.0.1:$port" "$ROOT/tests/quote-stub.php" >"$STUB_DIR/server.log" 2>&1 &
    STUB_PID=$!
    for _ in $(seq 1 40); do
        kill -0 "$STUB_PID" 2>/dev/null || return 1
        code="$(curl -sS --max-time 1 -D "$STUB_DIR/health.headers" -o "$STUB_DIR/health.txt" \
            -w '%{http_code}' "http://127.0.0.1:$port/health" 2>/dev/null || true)"
        if [ "$code" = "200" ] && grep -qF 'ok' "$STUB_DIR/health.txt" 2>/dev/null \
            && grep -qi '^X-Portfolio-Quote-Stub: yes' "$STUB_DIR/health.headers" 2>/dev/null; then
            STUB_URL="http://127.0.0.1:$port"
            STUB_ENDPOINT="https://$QUOTE_STUB_HOST:$port"
            return 0
        fi
        sleep 0.1
    done
    return 1
}

stop_quote_stub() {
    if [ -n "$STUB_PID" ]; then
        kill "$STUB_PID" 2>/dev/null || true
        wait "$STUB_PID" 2>/dev/null || true
        STUB_PID=""
    fi
}

echo "login (登入)"
# One shared credential guards the whole app, so this section must come first: the account is made
# from the CLI, and every later section runs inside the session it opens. $AJAR stays signed out on
# purpose and is reused for the password tests at the end of the file.
ADMIN=smoke-admin
PASS=smoke-pass-123
NEWPASS=smoke-pass-456
AJAR="$WORK/anon.txt"
rm -f "$AJAR"

# Before any account exists the app has to say how to get one, on every page, without leaking data.
get "/" "$LAST" >/dev/null
check "an installation with no account explains the CLI" "bin/create-admin.php" "$LAST"
check "and says the pages are closed until then" "還沒有登入帳號" "$LAST"

"$PHPBIN" bin/create-admin.php "--username=$ADMIN" "--password=shorty" "--data-dir=$DATADIR" > "$LAST" 2>&1
check "the CLI refuses a password under 10 characters" "至少要 10 個字元" "$LAST"
[ "$(q "SELECT COUNT(*) FROM access")" = "0" ] \
  && echo "  ok   a refused account left no half-written row" \
  || { echo "  FAIL the rejected account was stored anyway"; FAIL=1; }
"$PHPBIN" bin/create-admin.php "--username=a b" "--password=$PASS" "--data-dir=$DATADIR" > "$LAST" 2>&1
check "the CLI refuses a username with a space" "帳號只能是" "$LAST"

"$PHPBIN" bin/create-admin.php "--username=$ADMIN" "--password=$PASS" "--data-dir=$DATADIR" > "$LAST" 2>&1
check "the CLI creates the shared account" "已建立帳號" "$LAST"
HASH1=$(q "SELECT password_hash FROM access WHERE id = 1")
case "$HASH1" in
  '$2y$'*) echo "  ok   the password is stored as a bcrypt hash" ;;
  *) echo "  FAIL password_hash is not a hash ($HASH1)"; FAIL=1 ;;
esac
notcheck "no plaintext password sits in the database file" "$PASS" "$DB"

# Signed out: every path bounces, and a write creates nothing.
R=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$AJAR" -b "$AJAR" "$BASE/")
[ "$R" = "303 ${BASE}/login?back=%2F" ] && echo "  ok   / while signed out redirects to the login page" || { echo "  FAIL $R"; FAIL=1; }
R=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$AJAR" -b "$AJAR" "$BASE/transactions")
[ "$R" = "303 ${BASE}/login?back=%2Ftransactions" ] && echo "  ok   the redirect remembers where it was going" || { echo "  FAIL $R"; FAIL=1; }
R=$(curl -s -o /dev/null -w "%{http_code}" -c "$AJAR" -b "$AJAR" -X POST "$BASE/instruments" -d "new_symbol=NOPE" -d "new_market=US" -d "new_name=偷打的" -d "back=/instruments")
[ "$R" = "303" ] && [ "$(q "SELECT COUNT(*) FROM instruments")" = "0" ] \
  && echo "  ok   a write attempt while signed out is turned away and creates nothing" \
  || { echo "  FAIL an unauthenticated POST got through ($R)"; FAIL=1; }

get "/login" "$LAST" >/dev/null
check "the login page carries a csrf field" 'name="_csrf"' "$LAST"
check "it asks for a password" 'type="password"' "$LAST"
notcheck "it does not print the app navigation" "交易紀錄" "$LAST"
notcheck "and it never echoes the password field back" 'name="password" value=' "$LAST"

CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/login" -d "username=$ADMIN" -d "password=$PASS")
[ "$CODE" = "419" ] && echo "  ok   a login POST without a token is 419" || { echo "  FAIL gave $CODE"; FAIL=1; }

LT() { curl -s -c "$AJAR" -b "$AJAR" "$BASE/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
try_login() { # body-file token username password -> http code, always as the signed-out $AJAR
  local out="$1" token="$2" user="$3" pass="$4"
  curl -s -o "$out" -w "%{http_code}" -b "$AJAR" -c "$AJAR" -X POST "$BASE/login" \
    -d "_csrf=$token" -d "username=$user" -d "password=$pass"
}

CODE=$(try_login "$WORK/bad.html" "$(LT)" "$ADMIN" "wrongwrongwrong")
check "a wrong password says 帳號或密碼不正確" "帳號或密碼不正確。" "$WORK/bad.html"
[ "$CODE" = "401" ] && echo "  ok   and answers 401" || { echo "  FAIL gave $CODE"; FAIL=1; }
CODE=$(try_login "$WORK/none.html" "$(LT)" "nobody-here" "$PASS")
check "an unknown account gets the same sentence" "帳號或密碼不正確。" "$WORK/none.html"
notcheck "so nothing leaks which half was wrong" "帳號不存在" "$WORK/none.html"
[ "$(q "SELECT fail_count FROM login_throttle WHERE ip <> '*global'")" = "2" ] \
  && echo "  ok   failures are counted in the database (2)" \
  || { echo "  FAIL fail_count is $(q "SELECT fail_count FROM login_throttle WHERE ip <> '*global'")"; FAIL=1; }

# Five failures lock the door, and the lock holds even against the right password.
for i in 3 4 5; do try_login "$WORK/lock$i.html" "$(LT)" "$ADMIN" "wrongwrongwrong" >/dev/null; done
CODE=$(try_login "$WORK/locked.html" "$(LT)" "$ADMIN" "$PASS")
check "the sixth try is told to wait" "嘗試次數過多" "$WORK/locked.html"
[ "$CODE" = "401" ] && echo "  ok   a locked door still answers 401" || { echo "  FAIL gave $CODE"; FAIL=1; }
[ "$(q "SELECT locked_until FROM login_throttle")" -gt "$(date +%s)" ] \
  && echo "  ok   the lockout lives in the database, so a server restart cannot clear it" \
  || { echo "  FAIL locked_until did not move"; FAIL=1; }
[ "$(q "SELECT fail_count FROM login_throttle")" = "0" ] \
  && echo "  ok   arming the lock resets the counter, so it cannot re-arm instantly" || { echo "  FAIL fail_count not reset"; FAIL=1; }

# Counting per source address is the point of the table: a stranger hammering the door must not be
# able to lock this machine's owner out. A second address cannot be connected from in a one-box suite,
# so the foreign row is written directly and the local login below has to stay unaffected.
execq "$DB" "INSERT INTO login_throttle (ip, fail_count, locked_until, seen_at) VALUES ('203.0.113.9', 5, $(( $(date +%s) + 900 )), $(date +%s))"
[ "$(q "SELECT locked_until FROM login_throttle WHERE ip = '203.0.113.9'")" -gt "$(date +%s)" ] \
  && echo "  ok   a foreign address can hold a lock of its own" || { echo "  FAIL the foreign row was not stored"; FAIL=1; }

# The lock is a 15-minute thing by design, so the suite expires it in the file instead of sleeping.
NOW=$(date +%s)
execq "$DB" "UPDATE login_throttle SET locked_until = $NOW - 1 WHERE ip <> '203.0.113.9' AND ip <> '*global'"
OLDSESS=$(grep portfolio_session "$AJAR" | awk '{print $7}' | tail -1)
CODE=$(try_login "$WORK/after-lock.html" "$(LT)" "$ADMIN" "$PASS")
[ "$CODE" = "303" ] && echo "  ok   login works again the moment the lock expires" || { echo "  FAIL gave $CODE"; FAIL=1; }
[ "$(q "SELECT COUNT(*) FROM login_throttle WHERE ip <> '203.0.113.9' AND ip <> '*global'")" = "0" ] \
  && echo "  ok   a good login clears the lock and the counter" || { echo "  FAIL the lock survived a good login"; FAIL=1; }
# and only this address was cleared: the other one keeps its own countdown for the next visitor.
[ "$(q "SELECT locked_until FROM login_throttle WHERE ip = '203.0.113.9'")" -gt "$(date +%s)" ] \
  && echo "  ok   and it is the only address that was unlocked" || { echo "  FAIL the other address was unlocked too"; FAIL=1; }
execq "$DB" "DELETE FROM login_throttle WHERE ip = '203.0.113.9'"
# The id handed out on the login page must be worthless now that the session was upgraded.
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "portfolio_session=$OLDSESS" "$BASE/")
[ "$CODE" = "303" ] && echo "  ok   the pre-login session id is not accepted (session id rotated)" || { echo "  FAIL the old id still worked ($CODE)"; FAIL=1; }
# $AJAR goes back to being the signed-out jar, which is how it is used further down.
AJT=$(curl -s -b "$AJAR" -c "$AJAR" "$BASE/" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$AJAR" -c "$AJAR" -X POST "$BASE/logout" -d "_csrf=$AJT")
[ "$CODE" = "303" ] && echo "  ok   登出 from the second jar closes it again" || { echo "  FAIL gave $CODE"; FAIL=1; }

# Signed in for good now, so every section below behaves the way it always did.
T=$(csrf "/login")
CODE=$(curl -s -o "$LAST" -w "%{http_code}" -c "$JAR" -b "$JAR" -X POST "$BASE/login" -d "_csrf=$T" -d "username=$ADMIN" -d "password=$PASS")
[ "$CODE" = "303" ] && echo "  ok   the suite signs in as $ADMIN" || { echo "  FAIL login gave $CODE"; exit 1; }
get "/" "$LAST" >/dev/null
check "the sidebar shows who is signed in" "登入者 $ADMIN" "$LAST"
check "and offers a way out" ">登出<" "$LAST"
# Already signed in, so the token comes from any page of this session rather than the login page.
T=$(csrf "/")
CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" -X POST "$BASE/login" -d "_csrf=$T" -d "username=$ADMIN" -d "password=$PASS" -d "back=/instruments")
[ "$CODE" = "303" ] && echo "  ok   re-logging in with a remembered page goes straight there" || { echo "  FAIL gave $CODE"; FAIL=1; }
R=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" -X POST "$BASE/login" -d "_csrf=$T" -d "username=$ADMIN" -d "password=$PASS" -d "back=http://evil.example/")
[ "$R" = "$BASE" ] && echo "  ok   a crafted back target on the login form is dropped" || { echo "  FAIL bounced to $R"; FAIL=1; }

echo "bootstrap"
T=$(csrf "/")
[ -n "$T" ] && echo "  ok   csrf token acquired" || { echo "  FAIL no csrf token"; exit 1; }

echo "create portfolio"
post "/portfolios" "$T" -d "name=測試組合" -d "description=測試用" >/dev/null
PID=$(q "SELECT id FROM portfolios WHERE name='測試組合'")
[ -n "$PID" ] && echo "  ok   portfolio id=$PID（HTTP 寫入目標與 DB 相同）" \
    || { echo "安全停止：HTTP 建立的組合沒有出現在指定 DB，兩個目標不同。"; exit 1; }

# 反向再證明一次：直接寫入指定 DB 的資料必須能從這個 HTTP 站讀到。
MARKER="HTTP-DB-契約-$$"
execq "$DB" "INSERT INTO portfolios (name, description) VALUES ('$MARKER','身分檢查')"
MPID=$(q "SELECT id FROM portfolios WHERE name='$MARKER'")
scoped_get "/?scope=$MPID" "$WORK/db-handshake.html" >/dev/null
if ! grep -qF "$MARKER" "$WORK/db-handshake.html"; then
    echo "安全停止：指定 DB 的標記沒有出現在 HTTP 回應，兩個目標不同。"
    exit 1
fi
execq "$DB" "DELETE FROM portfolios WHERE id=$MPID"
echo "  ok   DB 與 HTTP 讀寫目標雙向一致"

echo "overview: all portfolios is the fresh-session default"
# Keep this in a separate cookie jar: the suite's working session has explicitly selected $PID and
# must not be changed by the default-scope checks below.
DEFAULT_JAR="$WORK/default-scope-cookies.txt"
rm -f "$DEFAULT_JAR"
DT=$(curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" -X POST "$BASE/login" \
    -d "_csrf=$DT" -d "username=$ADMIN" -d "password=$PASS")
[ "$CODE" = "303" ] && echo "  ok   a fresh session signs in for the default-scope checks" \
    || { echo "  FAIL default-scope login gave $CODE"; FAIL=1; }
DEFAULT_COUNT=$(q "SELECT COUNT(*) FROM portfolios")
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" > "$WORK/default-all.html"
check "a fresh session starts with all portfolios selected" '<option value="all" selected' "$WORK/default-all.html"
check "the aggregate heading counts every portfolio" "所有組合合計（$DEFAULT_COUNT 個）" "$WORK/default-all.html"
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/portfolios" > "$WORK/default-portfolios.html"
check "the portfolio page names the aggregate scope" "總覽範圍：所有組合" "$WORK/default-portfolios.html"
notcheck "aggregate mode does not mark one portfolio active" "使用中" "$WORK/default-portfolios.html"

execq "$DB" "INSERT INTO portfolios (name, description) VALUES ('HSBC','預設範圍測試')"
HPID=$(q "SELECT id FROM portfolios WHERE name='HSBC'")
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" > "$WORK/default-with-hsbc.html"
check "adding HSBC does not replace the all-portfolios default" '<option value="all" selected' "$WORK/default-with-hsbc.html"

DT=$(curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" -X POST "$BASE/scope" \
    -d "_csrf=$DT" -d "scope=$PID"
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" > "$WORK/default-explicit.html"
check "an explicit single-portfolio choice is honoured" "value=\"$PID\" selected" "$WORK/default-explicit.html"
notcheck "the single portfolio is not shown as an aggregate" '<option value="all" selected' "$WORK/default-explicit.html"
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/portfolios" > "$WORK/default-single-portfolios.html"
check "single-portfolio mode marks one portfolio active" "使用中" "$WORK/default-single-portfolios.html"
notcheck "single-portfolio mode does not claim aggregate scope" "總覽範圍：所有組合" "$WORK/default-single-portfolios.html"

DT=$(curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" -X POST "$BASE/scope" \
    -d "_csrf=$DT" -d "scope=all"
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" > "$WORK/default-explicit-all.html"
check "switching back to all portfolios is remembered" '<option value="all" selected' "$WORK/default-explicit-all.html"

SCOPE_STATE=$(PORTFOLIO_DATA_DIR="$DATADIR" "$PHPBIN" -d display_errors=stderr -r '
require $argv[1];
$method = new ReflectionMethod(App\Handlers::class, "dashboardScope");
$method->setAccessible(true);
$_SESSION = [];
$defaultAggregate = $method->invoke(null);
$_SESSION = ["dashboard_scope" => "all", "portfolio_id" => 123];
$legacyAggregate = $method->invoke(null);
$_SESSION = ["portfolio_id" => 123];
$singleAggregate = $method->invoke(null);
echo "default=" . (int) $defaultAggregate . ";legacy=" . (int) $legacyAggregate . ";single=" . (int) $singleAggregate;
' "$ROOT/app/bootstrap.php")
[ "$SCOPE_STATE" = "default=1;legacy=1;single=0" ] \
    && echo "  ok   aggregate is the default and a single portfolio remains an explicit override" \
    || { echo "  FAIL aggregate default state: $SCOPE_STATE"; FAIL=1; }

execq "$DB" "DELETE FROM portfolios WHERE id=$HPID"
curl -s -c "$DEFAULT_JAR" -b "$DEFAULT_JAR" "$BASE/" > "$WORK/default-after-delete.html"
check "the explicit all-portfolios choice survives deleting HSBC" '<option value="all" selected' "$WORK/default-after-delete.html"

echo "local quote source（在所有報價與匯率操作前啟動）"
ORIG_QUOTE_ENDPOINT="$(q "SELECT value FROM settings WHERE key='quote_endpoint'")"
ORIG_QUOTE_TOKEN="$(q "SELECT value FROM settings WHERE key='quote_token'")"
ORIG_QUOTE_SOURCE="$(q "SELECT value FROM settings WHERE key='quote_source'")"
BC=$(q "SELECT value FROM settings WHERE key='base_currency'")
[ -z "$BC" ] && BC=TWD
if ! start_quote_stub; then
    echo "安全停止：本機假報價端點無法啟動，不允許退回外部網路。"
    exit 1
fi
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT/" -d "quote_token=" -d "quote_refresh_budget=240" >/dev/null
if ! grep -qF '已更新設定' "$LAST" \
    || [ "$(q "SELECT value FROM settings WHERE key='quote_endpoint'")" != "$STUB_ENDPOINT" ]; then
    echo "安全停止：無法把報價出口切換到測試轉接端點；不可跳過 SSRF 檢查或退回外部網路。"
    if [ -s "$WORK/stub/server.log" ]; then
        echo "---- 本機假報價伺服器記錄 ----" >&2
        tail -n 40 "$WORK/stub/server.log" >&2
    fi
    echo "---- 設定表單回應 ----" >&2
    head -c 2000 "$LAST" >&2
    echo >&2
    exit 1
fi
echo "  ok   所有後續報價與匯率只會使用 $STUB_ENDPOINT（測試轉接至本機 stub）"
[ "$(q "SELECT value FROM settings WHERE key='quote_refresh_budget_seconds'")" = "240" ] \
    && echo "  ok   網頁報價時間預算可儲存" \
    || { echo "  FAIL the refresh budget did not persist"; FAIL=1; }
get "/settings" "$LAST" >/dev/null
checkf "設定頁顯示已儲存的時間預算" 'name="quote_refresh_budget"' "$LAST"
checkf "預算輸入帶安全範圍" 'min="5" max="600" step="1"' "$LAST"
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=visual-secret" -d "quote_refresh_budget=240" >/dev/null
get "/settings" "$WORK/quote-token.html" >/dev/null
checkf "轉接金鑰使用密碼欄位遮罩" 'type="password" name="quote_token"' "$WORK/quote-token.html"
notcheckf "settings HTML never renders the stored quote token" 'visual-secret' "$WORK/quote-token.html"
TOKEN_FIELD=$(grep 'name="quote_token"' "$WORK/quote-token.html")
case "$TOKEN_FIELD" in
    *value=*) echo "  FAIL quote token input still has a value attribute"; FAIL=1 ;;
    *) echo "  ok   quote token input is blank" ;;
esac
checkf "blank token is documented as preserve" '留空＝保留目前的金鑰' "$WORK/quote-token.html"
checkf "clearing a token has an explicit checkbox" 'name="clear_quote_token" value="1"' "$WORK/quote-token.html"
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" -d "quote_refresh_budget=240" >/dev/null
[ "$(q "SELECT value FROM settings WHERE key='quote_token'")" = "visual-secret" ] \
    && echo "  ok   a blank token submission preserves the stored key" \
    || { echo "  FAIL blank token submission erased the key"; FAIL=1; }
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" -d "clear_quote_token=yes" -d "quote_refresh_budget=240" >/dev/null
check "a malformed clear flag is refused" "清除報價金鑰的選項不正確" "$LAST"
[ "$(q "SELECT value FROM settings WHERE key='quote_token'")" = "visual-secret" ] \
    && echo "  ok   a refused clear leaves the token unchanged" \
    || { echo "  FAIL a refused clear changed the token"; FAIL=1; }
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=replacement" -d "clear_quote_token=1" -d "quote_refresh_budget=240" >/dev/null
check "clear and replacement together are refused" "不要同時填寫新的金鑰" "$LAST"
[ "$(q "SELECT value FROM settings WHERE key='quote_token'")" = "visual-secret" ] \
    && echo "  ok   an ambiguous clear leaves the token unchanged" \
    || { echo "  FAIL an ambiguous clear changed the token"; FAIL=1; }
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" -d "clear_quote_token=1" -d "quote_refresh_budget=240" >/dev/null
[ -z "$(q "SELECT value FROM settings WHERE key='quote_token'")" ] \
    && echo "  ok   the explicit clear flag empties the token" \
    || { echo "  FAIL explicit clear did not empty the token"; FAIL=1; }
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=visual-secret" -d "quote_refresh_budget=240" >/dev/null
for bad in 4 601 5.5 abc; do
    T=$(csrf "/settings")
    post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
        -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" -d "quote_refresh_budget=$bad" >/dev/null
    check "無效時間預算 $bad 被拒絕" "網頁報價更新時間上限必須是 5 到 600 的整數秒數" "$LAST"
done
[ "$(q "SELECT value FROM settings WHERE key='quote_refresh_budget_seconds'")" = "240" ] \
    && echo "  ok   被拒絕的預算沒有覆蓋原值" \
    || { echo "  FAIL a rejected budget was written"; FAIL=1; }

# A real slow source proves the web deadline is reported, while the same CLI pass keeps its
# existing unlimited budget. Everything is synthetic and removed before the main fixtures begin.
echo "網頁預算耗盡與 CLI 預算隔離"
printf '1' > "$STUB_DIR/delay"
: > "$STUB_DIR/shallow"
execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '0')
  ON CONFLICT(key) DO UPDATE SET value = '0';
  INSERT INTO portfolios (name, description) VALUES ('報價預算測試', '三分鐘後刪除');
  INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class)
    VALUES ('BUD1','BUD1','US','BUD1','USD','EQUITY'),
           ('BUD2','BUD2','US','BUD2','USD','EQUITY'),
           ('BUD3','BUD3','US','BUD3','USD','EQUITY');"
BPID=$(q "SELECT id FROM portfolios WHERE name='報價預算測試'")
BUD_IDS=""
for B in BUD1 BUD2 BUD3; do
  BIID=$(q "SELECT id FROM instruments WHERE yahoo_symbol='$B'")
  BUD_IDS="$BUD_IDS $BIID"
  execq "$DB" "INSERT INTO transactions (portfolio_id,instrument_id,side,traded_on,quantity,price,fee)
    VALUES ($BPID,$BIID,'BUY','2026-01-01',1,10,0);"
done
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=USD" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" -d "quote_refresh_budget=5" >/dev/null
: > "$HITS"
post "/prices/refresh" "$T" -d "portfolio_id=$BPID" >/dev/null
check "網頁預算耗盡時如實說明未處理完" "本次報價更新已達時間上限" "$LAST"
check "網頁預算耗盡時列出略過檔數" "檔略過" "$LAST"
WEB_HITS=$(wc -l < "$HITS" | tr -d ' ')
[ "$WEB_HITS" -ge 2 ] && [ "$WEB_HITS" -lt 6 ] \
  && echo "  ok   網頁在預算內收工，沒有把六次請求硬跑完（實際 $WEB_HITS 次）" \
  || { echo "  FAIL web budget made $WEB_HITS requests"; FAIL=1; }

BUDCOPY="$WORK/budget-cli"; mkdir -p "$BUDCOPY"
cp "$DB" "$BUDCOPY/portfolio.sqlite"
[ ! -e "$DB-wal" ] || cp "$DB-wal" "$BUDCOPY/portfolio.sqlite-wal"
[ ! -e "$DB-shm" ] || cp "$DB-shm" "$BUDCOPY/portfolio.sqlite-shm"
: > "$HITS"
QUOTE_REFRESH_BUDGET=1 quote_php bin/refresh-prices.php --data-dir="$BUDCOPY" --portfolio="$BPID" >"$WORK/budget-cli.txt" 2>&1
CLI_BUDGET_RC=$?
CLI_HITS=$(wc -l < "$HITS" | tr -d ' ')
[ "$CLI_BUDGET_RC" = "0" ] && grep -q 'quotes ok=3 failed=0 skipped=0' "$WORK/budget-cli.txt" \
  && [ "$CLI_HITS" -ge 3 ] && [ "$CLI_HITS" -le 6 ] \
  && echo "  ok   CLI 忽略網頁一秒預算，三檔報價都完成" \
  || { echo "  FAIL CLI budget isolation rc=$CLI_BUDGET_RC hits=$CLI_HITS: $(tr '\n' ' ' < "$WORK/budget-cli.txt")"; FAIL=1; }
rm -f "$STUB_DIR/delay" "$STUB_DIR/shallow"
execq "$DB" "DELETE FROM transactions WHERE portfolio_id=$BPID;
  DELETE FROM prices WHERE instrument_id IN (SELECT id FROM instruments WHERE yahoo_symbol LIKE 'BUD%');
  DELETE FROM instruments WHERE yahoo_symbol LIKE 'BUD%';
  DELETE FROM portfolios WHERE id=$BPID;
  DELETE FROM settings WHERE key='quote_min_refresh_minutes';"
for BIID in $BUD_IDS; do
  execq "$DB" "DELETE FROM settings WHERE key='deep_oldest_$BIID';"
done
T=$(csrf "/settings")
post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" -d "quote_refresh_budget=240" >/dev/null
[ "$(q "SELECT value FROM settings WHERE key='base_currency'")" = "$BC" ] \
  && [ "$(q "SELECT value FROM settings WHERE key='quote_refresh_budget_seconds'")" = "240" ] \
  && echo "  ok   預算測試後基礎幣別與時間上限已還原" \
  || { echo "  FAIL budget fixture settings were not restored"; FAIL=1; }
: > "$HITS"

echo "add transactions (AAPL, USD)"
# BUY 100 @ 150 fee 5   -> 15,005
# BUY 100 @ 170 fee 5   -> 17,005 ; total 32,010 -> avg 160.05
# SELL 50 @ 200 fee 5   -> realised (50*200 - 5) - 50*160.05 = 9,995 - 8,002.50 = 1,992.50 ; 150 left
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-01-02" \
  -d "quantity=100" -d "price=150" -d "fee=5" -d "instrument_id=" \
  -d "new_symbol=aapl" -d "new_market=US" -d "new_name=Apple" >/dev/null
AID=$(q "SELECT id FROM instruments WHERE yahoo_symbol='AAPL'")
[ -n "$AID" ] && echo "  ok   AAPL instrument created (id=$AID)" || { echo "  FAIL AAPL not created"; exit 1; }
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-02-03" \
  -d "quantity=100" -d "price=170" -d "fee=5" -d "instrument_id=$AID" >/dev/null
NTX=$(q "SELECT COUNT(*) FROM transactions WHERE instrument_id=$AID AND side='BUY'")
[ "$NTX" = "2" ] && echo "  ok   both AAPL buys stored" || { echo "  FAIL expected 2 buys, got '$NTX'"; FAIL=1; }
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=SELL" -d "traded_on=2025-03-04" \
  -d "quantity=50" -d "price=200" -d "fee=5" -d "instrument_id=$AID" >/dev/null
TX_CURRENCIES=$(q "SELECT group_concat(DISTINCT currency) FROM transactions WHERE instrument_id=$AID")
[ "$TX_CURRENCIES" = "USD" ] && echo "  ok   每筆交易保存成交幣別" \
  || { echo "  FAIL 交易幣別未保存：$TX_CURRENCIES"; FAIL=1; }
execq "$DB" "UPDATE instruments SET currency='EUR' WHERE id=$AID"
get "/export?instrument=$AID" "$WORK/historical-currency.csv" >/dev/null
check "CSV 使用交易當時的幣別，不被投資物目前幣別改寫" ",USD," "$WORK/historical-currency.csv"
notcheck "CSV 不把歷史交易誤標成目前 EUR" ",EUR," "$WORK/historical-currency.csv"
execq "$DB" "UPDATE instruments SET currency='USD' WHERE id=$AID"

echo "guards and validation"
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=SELL" -d "traded_on=2025-04-05" \
  -d "quantity=99999" -d "price=200" -d "fee=0" -d "instrument_id=$AID" >/dev/null
check "rejects selling more than held" "超過目前持股" "$LAST"
NOSUCH=$(q "SELECT COUNT(*) FROM transactions WHERE quantity=99999")
[ "$NOSUCH" = "0" ] && echo "  ok   rejected trade was not written" || { echo "  FAIL rejected trade persisted"; FAIL=1; }

# 之後才買進的股數不能拿來證明一筆更早的賣出成立：那種紀錄存進去之後，持股跟現金都不會動，
# 到頭來變成使用者記了一筆卻什麼都沒發生。
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=SELL" -d "traded_on=2025-01-03" \
  -d "quantity=150" -d "price=200" -d "fee=0" -d "instrument_id=$AID" >/dev/null
check "rejects a sale dated before the stock was bought" "還沒有那麼多持股" "$LAST"
NOSUCH=$(q "SELECT COUNT(*) FROM transactions WHERE instrument_id=$AID AND traded_on='2025-01-03'")
[ "$NOSUCH" = "0" ] && echo "  ok   the backdated sale was not written" || { echo "  FAIL backdated sale persisted"; FAIL=1; }

post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-13-99" \
  -d "quantity=10" -d "price=10" -d "instrument_id=$AID" >/dev/null
check "rejects impossible date" "日期格式不正確" "$LAST"
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-05-05" \
  -d "quantity=0" -d "price=10" -d "instrument_id=$AID" >/dev/null
check "rejects zero quantity" "大於 0" "$LAST"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE/transactions" -d "_csrf=deadbeef" -d "portfolio_id=$PID")
[ "$CODE" = "419" ] && echo "  ok   stale csrf token -> 419" || { echo "  FAIL csrf not enforced (got $CODE)"; FAIL=1; }

echo "高風險 POST 的負面矩陣（未登入、壞 CSRF、未知 ID）"
NEG_PATHS=(
  "/backup/restore" "/backup/pre-restore-19700101-000000.sqlite/restore"
  "/prices/refresh" "/settings" "/fx" "/fx/delete" "/password"
  "/instruments/999999/price" "/instruments/999999/delete"
)
NEG_DATA=(
  "confirm=1" "confirm=1" "portfolio_id=999999" "base_currency=USD quote_source=off quote_endpoint= quote_token="
  "currency=ZZZ units_per_usd=1" "currency=ZZZ" "current_password=x new_password=long-enough new_password2=long-enough"
  "manual_price=1 manual_date=$TODAY" "back=/instruments"
)
for i in "${!NEG_PATHS[@]}"; do
  CODE=$(curl -s -o "$WORK/neg-anon.html" -w '%{http_code}' -c "$AJAR" -b "$AJAR" -X POST \
    "$BASE${NEG_PATHS[$i]}" -d "_csrf=deadbeef" -d "${NEG_DATA[$i]}")
  [ "$CODE" = "303" ] && echo "  ok   未登入 ${NEG_PATHS[$i]} -> 303" \
    || { echo "  FAIL 未登入 ${NEG_PATHS[$i]} 給 $CODE，應為 303"; FAIL=1; }
  CODE=$(curl -s -o "$WORK/neg-csrf.html" -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST \
    "$BASE${NEG_PATHS[$i]}" -d "_csrf=deadbeef" -d "${NEG_DATA[$i]}")
  [ "$CODE" = "419" ] && echo "  ok   壞 CSRF ${NEG_PATHS[$i]} -> 419" \
    || { echo "  FAIL 壞 CSRF ${NEG_PATHS[$i]} 給 $CODE，應為 419"; FAIL=1; }
done
UNKNOWN_PATHS=(
  "/backup/pre-restore-19700101-000000.sqlite/restore" "/prices/refresh"
  "/instruments/999999/price" "/instruments/999999/delete"
)
UNKNOWN_DATA=("confirm=1" "portfolio_id=999999" "manual_price=1" "back=/instruments")
for i in "${!UNKNOWN_PATHS[@]}"; do
  CODE=$(curl -s -o "$WORK/neg-id.html" -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST \
    "$BASE${UNKNOWN_PATHS[$i]}" -d "_csrf=$T" -d "${UNKNOWN_DATA[$i]}")
  [ "$CODE" = "400" ] && echo "  ok   未知 ID／代號 ${UNKNOWN_PATHS[$i]} -> 400" \
    || { echo "  FAIL 未知 ID／代號 ${UNKNOWN_PATHS[$i]} 給 $CODE，應明確拒絕（400）"; FAIL=1; }
done

echo "add a TWD position (2330.TW)"
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-01-10" \
  -d "quantity=500" -d "price=900" -d "fee=16" -d "instrument_id=" \
  -d "new_symbol=2330" -d "new_market=TW" -d "new_name=台積電" >/dev/null
TWID=$(q "SELECT id FROM instruments WHERE yahoo_symbol='2330.TW'")
[ -n "$TWID" ] && echo "  ok   2330 mapped to yahoo symbol 2330.TW" || { echo "  FAIL 2330.TW not created"; FAIL=1; }

echo "price refresh（本機假來源，確定性資料）"
# 「剛抓過的投資物這輪不再打第二次」是對來源的保護，但它會讓下面這些一段接一段按更新的測試
# 只有第一次真的發出去玩成一串假失敗，所以在這裡把間隔關掉（最後那一節會自己開回來）。
execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '0')
             ON CONFLICT(key) DO UPDATE SET value = '0'"
post "/prices/refresh" "$T" -d "portfolio_id=$PID" -d "back=/" >/dev/null
check "the dashboard lists AAPL after refresh" "AAPL" "$LAST"
notcheck "no PHP warnings" "Warning:" "$LAST"
notcheck "no fatal errors" "Fatal error" "$LAST"

echo "stored quote + fx"
PX=$(q "SELECT close FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1")
ASOF=$(q "SELECT as_of FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1")
NBAR=$(q "SELECT COUNT(*) FROM prices WHERE instrument_id=$AID")
FX=$(q "SELECT units_per_usd FROM fx_rates WHERE currency='TWD'")
[ "$PX" = "42.5" ] && [ "$ASOF" = "$TODAY" ] \
    && echo "  ok   AAPL 假來源收盤價=$PX、日期=$ASOF" \
    || { echo "  FAIL AAPL 確定性報價應為 42.5 / $TODAY，實際 $PX / $ASOF"; FAIL=1; }
ADJ=$(q "SELECT adj_close FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1")
PREV=$(q "SELECT prev_close FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1")
[ "$ADJ" = "41.44" ] && echo "  ok   Yahoo adjclose 獨立欄位存入 adj_close=$ADJ" \
    || { echo "  FAIL adj_close 應為 41.44，實際 '$ADJ'"; FAIL=1; }
HIGH=$(q "SELECT high FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1")
LOW=$(q "SELECT low FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1")
[ "$HIGH" = "43.5" ] && [ "$LOW" = "41.5" ] \
    && echo "  ok   Yahoo 當日 high/low 獨立存入 prices（$HIGH / $LOW）" \
    || { echo "  FAIL 當日 high/low 應為 43.5 / 41.5，實際 '$HIGH' / '$LOW'"; FAIL=1; }
[ "$PREV" = "20" ] && echo "  ok   當日 prev_close 來自倒數第二根假日線的 20" \
    || { echo "  FAIL prev_close 應為 20，實際 '$PREV'"; FAIL=1; }
[ "$NBAR" = "4" ] && echo "  ok   確定性來源正好存入四根日線" \
    || { echo "  FAIL 應有四根日線，實際 ${NBAR:-0}"; FAIL=1; }
if [ -n "$FX" ] && "$PHPBIN" -r 'exit(abs((float) $argv[1] - 30.25) < 1e-12 ? 0 : 1);' "$FX"; then
  echo "  ok   USD/TWD 假來源原樣存為每美元 $FX TWD"
else
  echo "  FAIL TWD 匯率應為 30.25，實際 '$FX'"; FAIL=1
fi

echo "history bars keep their previous close"
# Today's live row stores prev_close so 當日漲跌 can be shown. Tomorrow's refresh writes the same day
# back as one of many history bars, and history has no previous close of its own — it must leave the
# stored value alone, otherwise every older bar ends up blanking the day change on a failed refresh.
"$PHPBIN" -r '$p = new PDO("sqlite:" . $argv[1]); $id = (int) $argv[2];
  $p->prepare("UPDATE prices SET prev_close = 1.25 WHERE instrument_id = ? AND as_of =
    (SELECT MAX(as_of) FROM prices WHERE instrument_id = ? AND as_of < (SELECT MAX(as_of) FROM prices WHERE instrument_id = ?))")
    ->execute([$id, $id, $id]);' "$DB" "$AID"
SEED=$(q "SELECT prev_close FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1 OFFSET 1")
[ "$SEED" = "1.25" ] && echo "  ok   older bar carries prev_close" || { echo "  FAIL seeding gave '$SEED'"; FAIL=1; }
post "/prices/refresh" "$T" -d "portfolio_id=$PID" -d "back=/" >/dev/null
KEPT=$(q "SELECT prev_close FROM prices WHERE instrument_id=$AID ORDER BY as_of DESC LIMIT 1 OFFSET 1")
[ "$KEPT" = "1.25" ] && echo "  ok   refresh did not blank the older prev_close" || { echo "  FAIL refresh left prev_close='$KEPT'"; FAIL=1; }

echo "position maths on 總覽 (native currency, no fx involved)"
check "AAPL average cost 160.05" "160.05" "$LAST"
check "AAPL realised P&L 1,992.50" "1,992.50" "$LAST"
check "AAPL remaining 150 shares" ">150<" "$LAST"
check "2330 average cost 900.03" "900.03" "$LAST"

echo "cross-currency conversion"
# Independently recompute AAPL's TWD market value: qty * last close * USD->TWD rate.
# Currency and id are bound, not interpolated, so no nested quoting is needed in the snippet.
cat > "$WORK/expect.php" <<'PHP'
<?php
$p = new PDO("sqlite:" . $argv[1]);
$px = (float) $p->query("SELECT close FROM prices WHERE instrument_id=" . (int) $argv[2] . " ORDER BY as_of DESC LIMIT 1")->fetchColumn();
$fx = $p->prepare("SELECT units_per_usd FROM fx_rates WHERE currency=?");
$fx->execute([$argv[3]]);
$rate = (float) $fx->fetchColumn();
echo number_format(150 * $px * $rate, 2, ".", ",");
PHP
EXPECTED=$("$PHPBIN" -d display_errors=stderr "$WORK/expect.php" "$DB" "$AID" TWD)
if [ -n "$EXPECTED" ] && grep -F "$EXPECTED" "$LAST" >/dev/null; then
  echo "  ok   AAPL shown as NT\$$EXPECTED after fx"
else
  echo "  FAIL expected TWD value '$EXPECTED' not found on 總覽"; FAIL=1
fi

echo "manual price override"
post "/instruments/$AID/price" "$T" -d "manual_price=250" -d "manual_date=$TODAY" -d "back=/" >/dev/null
check "manual price shown" "250.00" "$LAST"
check "manual badge rendered" "手動" "$LAST"
# 150 * 250 = 37,500 market value, less cost 150 * 160.05 = 24,007.50 -> unrealised 13,492.50
check "unrealised P&L recomputed from the manual price" "13,492.50" "$LAST"
post "/instruments/$AID/price" "$T" -d "manual_price=" -d "back=/" >/dev/null
MP=$(q "SELECT manual_price FROM instruments WHERE id=$AID")
[ -z "$MP" ] && echo "  ok   clearing manual price falls back to cached quote" || { echo "  FAIL manual_price still set ($MP)"; FAIL=1; }

echo "manual price dates and numeric guards"
# Clearing the <input type="date"> in a browser posts an empty string, and an empty date compares
# lower than every cached quote: the manual price was stored, acknowledged, and then ignored.
post "/instruments/$AID/price" "$T" -d "manual_price=251" -d "manual_date=" -d "back=/" >/dev/null
MD=$(q "SELECT manual_price_at FROM instruments WHERE id=$AID")
[ "$MD" = "$TODAY" ] && echo "  ok   a cleared price date becomes today ($MD)" || { echo "  FAIL manual_price_at is '$MD'"; FAIL=1; }
check "the blank-dated manual price still beats the cached quote" "251.00" "$LAST"
CODE=$(curl -s -o "$WORK/mp-bad.html" -w "%{http_code}" -X POST "$BASE/instruments/$AID/price" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "manual_price=300" -d "manual_date=abc")
[ "$CODE" = "400" ] && echo "  ok   a nonsense manual price date is refused (400)" || { echo "  FAIL bad date gave $CODE"; FAIL=1; }
check "the refusal names the expected format" "日期格式不正確" "$WORK/mp-bad.html"
MD=$(q "SELECT manual_price_at FROM instruments WHERE id=$AID")
[ "$MD" = "$TODAY" ] && echo "  ok   the refused write left the date alone" || { echo "  FAIL date became '$MD'"; FAIL=1; }
post "/instruments/$AID/price" "$T" -d "manual_price=" -d "back=/" >/dev/null
# is_numeric() accepts 1e999, which is INF. It would sit in the table and poison every total.
N0=$(q "SELECT COUNT(*) FROM transactions")
CODE=$(curl -s -o "$WORK/qty-inf.html" -w "%{http_code}" -X POST "$BASE/transactions" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$PID" -d "side=BUY" -d "quantity=1e999" -d "price=1" -d "instrument_id=$AID")
[ "$CODE" = "400" ] && echo "  ok   an overflowing quantity is refused (400)" || { echo "  FAIL 1e999 quantity gave $CODE"; FAIL=1; }
check "the refusal says the number is out of range" "超出可計算範圍" "$WORK/qty-inf.html"
N1=$(q "SELECT COUNT(*) FROM transactions")
[ "$N0" = "$N1" ] && echo "  ok   nothing was written by the rejected trade" || { echo "  FAIL transactions went $N0 -> $N1"; FAIL=1; }
CODE=$(curl -s -o "$WORK/qty-product.html" -w "%{http_code}" -X POST "$BASE/transactions" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$PID" -d "side=BUY" -d "quantity=1e308" -d "price=1e308" -d "fee=0" -d "instrument_id=$AID")
[ "$CODE" = "400" ] && echo "  ok   a finite quantity and price whose product overflows are refused (400)" || { echo "  FAIL overflowing product gave $CODE"; FAIL=1; }
check "the product refusal names the settlement range" "成交金額或結算金額超出可計算範圍" "$WORK/qty-product.html"
N1=$(q "SELECT COUNT(*) FROM transactions")
[ "$N0" = "$N1" ] && echo "  ok   the overflowing product wrote no transaction" || { echo "  FAIL transactions went $N0 -> $N1"; FAIL=1; }
execq "$DB" "INSERT INTO transactions (portfolio_id,instrument_id,side,traded_on,quantity,price,fee,note)
  VALUES ($PID,$AID,'BUY','2025-01-05',1e308,0,0,'累計溢位測試');"
N0=$(q "SELECT COUNT(*) FROM transactions")
CODE=$(curl -s -o "$WORK/qty-sum.html" -w "%{http_code}" -X POST "$BASE/transactions" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-01-06" \
  -d "quantity=1e308" -d "price=0" -d "fee=0" -d "instrument_id=$AID")
[ "$CODE" = "400" ] && echo "  ok   a buy whose cumulative quantity overflows is refused (400)" \
  || { echo "  FAIL cumulative quantity overflow gave $CODE"; FAIL=1; }
check "the cumulative refusal names the holdings range" "持股數量累計超出可計算範圍" "$WORK/qty-sum.html"
N1=$(q "SELECT COUNT(*) FROM transactions")
[ "$N0" = "$N1" ] && echo "  ok   the overflowing cumulative buy wrote no transaction" \
  || { echo "  FAIL cumulative buy changed transactions $N0 -> $N1"; FAIL=1; }
execq "$DB" "DELETE FROM transactions WHERE note='累計溢位測試';"
# The fx form has the same date field, and its as_of feeds the 更新日期 column on the settings page.
post "/fx" "$T" -d "currency=KRW" -d "units_per_usd=1300" -d "as_of=" >/dev/null
N0=$(q "SELECT COUNT(*) FROM fx_rates")
ASOF=$(q "SELECT as_of FROM fx_rates WHERE currency='KRW'")
[ "$ASOF" = "$TODAY" ] && echo "  ok   a cleared fx date becomes today ($ASOF)" || { echo "  FAIL fx as_of is '$ASOF'"; FAIL=1; }
CODE=$(curl -s -o "$WORK/fx-bad.html" -w "%{http_code}" -X POST "$BASE/fx" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "currency=KRW" -d "units_per_usd=1300" -d "as_of=abc")
[ "$CODE" = "400" ] && echo "  ok   a nonsense fx date is refused (400)" || { echo "  FAIL bad fx date gave $CODE"; FAIL=1; }
N1=$(q "SELECT COUNT(*) FROM fx_rates")
[ "$N0" = "$N1" ] && echo "  ok   the rejected fx write added no currency" || { echo "  FAIL fx_rates went $N0 -> $N1"; FAIL=1; }
post "/fx/delete" "$T" -d "currency=KRW" >/dev/null

echo "a brand-new currency arrives with the trade that needs it"
# Deliberately before the JPY section: at this point fx_rates holds only TWD (rowid 1) while the new
# instrument is rowid 3, so if resolveInstrument() re-read lastInsertId() after writing the GBP rate
# (rowid 2) the trade would land on 2330 instead of AZN.L.
N=$(q "SELECT COUNT(*) FROM fx_rates WHERE currency='GBP'")
[ "$N" = "0" ] && echo "  ok   GBP starts without a rate" || { echo "  FAIL GBP rate already present"; FAIL=1; }
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-06-06" \
  -d "quantity=100" -d "price=2400" -d "fee=3" -d "instrument_id=" \
  -d "new_symbol=azn" -d "new_market=GB" -d "new_name=AstraZeneca" >/dev/null
N=$(q "SELECT COUNT(*) FROM transactions t JOIN instruments i ON i.id = t.instrument_id WHERE i.yahoo_symbol='AZN.L'")
[ "$N" = "1" ] && echo "  ok   trade attached to the new AZN.L instrument" || { echo "  FAIL AZN.L trade points at the wrong instrument ($N rows)"; FAIL=1; }
GBP=$(q "SELECT units_per_usd FROM fx_rates WHERE currency='GBP'")
case "$GBP" in
  0.7*|0.8*) echo "  ok   GBP rate stored directly as units per USD ($GBP)";;
  "") echo "  FAIL GBP rate not fetched when the trade was booked"; FAIL=1;;
  *)  echo "  FAIL GBP rate implausible ($GBP)"; FAIL=1;;
esac

echo "crypto (加密貨幣)"
# The trade-entry path is the one that has no asset-class field, so it also proves the market default.
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-07-07" \
  -d "quantity=0.5" -d "price=60000" -d "fee=10" -d "instrument_id=" \
  -d "new_symbol=btc" -d "new_market=XC" -d "new_name=Bitcoin" >/dev/null
BID=$(q "SELECT id FROM instruments WHERE yahoo_symbol='BTC-USD'")
[ -n "$BID" ] && echo "  ok   BTC mapped to yahoo symbol BTC-USD (id=$BID)" || { echo "  FAIL BTC-USD not created"; FAIL=1; }
AC=$(q "SELECT asset_class FROM instruments WHERE id=$BID")
[ "$AC" = "CRYPTO" ] && echo "  ok   asset class defaults to CRYPTO" || { echo "  FAIL asset class is $AC"; FAIL=1; }
CUR=$(q "SELECT currency FROM instruments WHERE id=$BID")
[ "$CUR" = "USD" ] && echo "  ok   quoted in USD" || { echo "  FAIL currency is $CUR"; FAIL=1; }
QTY=$(q "SELECT quantity FROM transactions WHERE instrument_id=$BID")
[ "${QTY%.*}" = "0" ] && echo "  ok   fractional unit stored ($QTY BTC)" || { echo "  FAIL quantity is $QTY"; FAIL=1; }
post "/instruments/$BID/price" "$T" -d "action=fetch" -d "back=/instruments" >/dev/null
BTC_PX=$(q "SELECT close FROM prices WHERE instrument_id=$BID ORDER BY as_of DESC LIMIT 1")
if [ -n "$BTC_PX" ] && [ "$("$PHPBIN" -r 'echo (float) $argv[1] > 1000 ? 1 : 0;' "$BTC_PX")" = "1" ]; then
  echo "  ok   live coin quote stored ($BTC_PX)"
else
  echo "  FAIL no plausible BTC quote ($BTC_PX)"; FAIL=1
fi
get "/instruments" "$WORK/crypto.html" >/dev/null
check "the coin links straight to Yahoo's quote page" "finance.yahoo.com/quote/BTC-USD" "$WORK/crypto.html"
# Cheap coins must not round away: two decimals only start at 1 unit.
PREC=$("$PHPBIN" -r 'require "app/helpers.php"; echo fmt_price(0.05129, "USD"), "|", fmt_price(0.0000123, "USD"), "|", fmt_price(160.05, "USD");')
[ "$PREC" = 'US$0.0513|US$0.000012|US$160.05' ] && echo "  ok   unit prices keep 4-6 decimals below 1" || { echo "  FAIL fmt_price gave $PREC"; FAIL=1; }

echo "London Stock Exchange"
# Yahoo wants LSE tickers with .L suffix. The link goes directly to Yahoo Finance using the yahoo_symbol.
post "/instruments" "$T" -d "symbol=VOD.L" -d "market=GB" -d "currency=" -d "asset_class=EQUITY" -d "name=沃達丰" -d "notes=" >/dev/null
LID=$(q "SELECT id FROM instruments WHERE symbol='VOD.L'")
[ -n "$LID" ] && echo "  ok   dotted LSE ticker accepted (id=$LID)" || { echo "  FAIL VOD.L instrument not created"; FAIL=1; }
LY=$(q "SELECT yahoo_symbol FROM instruments WHERE id=$LID")
[ "$LY" = "VOD.L" ] && echo "  ok   yahoo symbol keeps the single .L suffix ($LY)" || { echo "  FAIL yahoo_symbol is $LY"; FAIL=1; }
LC=$(q "SELECT currency FROM instruments WHERE id=$LID")
[ "$LC" = "GBP" ] && echo "  ok   currency defaults to GBP" || { echo "  FAIL currency is $LC"; FAIL=1; }
post "/instruments" "$T" -d "symbol=HSBA" -d "market=GB" -d "currency=" -d "asset_class=EQUITY" -d "name=匯豐" -d "notes=" >/dev/null
HID=$(q "SELECT id FROM instruments WHERE symbol='HSBA'")
HY=$(q "SELECT yahoo_symbol FROM instruments WHERE id=$HID")
[ "$HY" = "HSBA.L" ] && echo "  ok   bare LSE ticker gains .L ($HY)" || { echo "  FAIL yahoo_symbol is $HY"; FAIL=1; }
get "/instruments" "$WORK/lse.html" >/dev/null
check "the dotted Yahoo symbol links to Yahoo quote page" "finance.yahoo.com/quote/VOD.L" "$WORK/lse.html"
check "the plain LSE ticker gets .L added and links to Yahoo" "finance.yahoo.com/quote/HSBA.L" "$WORK/lse.html"
notcheck "no dead LSE spelling in any link" "quote/VOD.L:" "$WORK/lse.html"
post "/instruments/$LID/price" "$T" -d "action=fetch" -d "back=/instruments" >/dev/null
LP=$(q "SELECT close FROM prices WHERE instrument_id=$LID ORDER BY as_of DESC LIMIT 1")
LPR=$(q "SELECT currency FROM prices WHERE instrument_id=$LID ORDER BY as_of DESC LIMIT 1")
if [ -n "$LP" ] && [ "$("$PHPBIN" -r 'echo (float) $argv[1] > 20 && (float) $argv[1] < 1000 ? 1 : 0;' "$LP")" = "1" ]; then
  echo "  ok   live LSE quote stored ($LP $LPR)"
else
  echo "  FAIL no plausible Vodafone quote ($LP)"; FAIL=1
fi
[ "$LPR" = "GBP" ] && echo "  ok   the quote comes back in GBP" || { echo "  FAIL quote currency is $LPR"; FAIL=1; }
# The trade goes into $PID, the portfolio the rest of the suite already uses. Creating a new one here would
# switch the session's active portfolio (portfolioCreate always points at the one it just made), and the CSV
# export further down exports the active portfolio, so it would start exporting pounds instead of AAPL.
post "/transactions" "$T" -d "portfolio_id=$PID" -d "side=BUY" -d "traded_on=2025-06-06" \
  -d "quantity=300" -d "price=105" -d "fee=7.5" -d "instrument_id=$LID" >/dev/null
post "/prices/refresh" "$T" -d "portfolio_id=$PID" >/dev/null
scoped_get "/?scope=$PID" "$WORK/lse-dash.html" >/dev/null
sed -n '/現金與各幣別/,/<\/table>/p' "$WORK/lse-dash.html" > "$WORK/lse-breakdown.html"
check "GBP gets its own currency line" ">GBP<" "$WORK/lse-breakdown.html"
if grep -q -- "£" "$WORK/lse-breakdown.html"; then
  echo "  ok   the London holding is valued in pounds (£)"
else
  echo "  FAIL no pound figure for the GBP row"; FAIL=1
fi
GR=$(q "SELECT units_per_usd FROM fx_rates WHERE currency='GBP'")
case "$GR" in
  0.7*|0.8*) echo "  ok   GBP rate auto-fetched directly as units per USD ($GR)";;
  "") echo "  FAIL no GBP rate"; FAIL=1;;
  *)  echo "  FAIL GBP rate implausible ($GR)"; FAIL=1;;
esac
GBBASE=$(sed -n '/>GBP</,/<\/tr>/p' "$WORK/lse-breakdown.html" | grep -c -- '--')
[ "$GBBASE" = "0" ] && echo "  ok   the GBP row converts into the base currency" || { echo "  FAIL the GBP row still shows --"; FAIL=1; }

echo "Frankfurt, Toronto and Bursa links"
# Yahoo's own suffixes (.F for Xetra, .TO for Toronto, .KL for Bursa) are not Google's codes. Verified live:
# SAP:FRA and SHOP:TSE open the quote page, while SAP:GER, SHOP:TSX and 1155:KLS all answer with the homepage.
# Bursa has no Google code at all, so its link is a "1155 stock" search, which does resolve Malayan Banking.
post "/instruments" "$T" -d "symbol=SAP" -d "market=DE" -d "currency=" -d "asset_class=EQUITY" -d "name=思愛普" -d "notes=" >/dev/null
DID=$(q "SELECT id FROM instruments WHERE symbol='SAP'")
[ -n "$DID" ] && echo "  ok   Frankfurt ticker gains Yahoo's .F" || { echo "  FAIL SAP.F instrument not created"; FAIL=1; }
DC=$(q "SELECT currency FROM instruments WHERE id=$DID")
[ "$DC" = "EUR" ] && echo "  ok   that market defaults to EUR" || { echo "  FAIL currency is $DC"; FAIL=1; }
post "/instruments" "$T" -d "symbol=BMW.F" -d "market=DE" -d "currency=" -d "asset_class=EQUITY" -d "name=寶馬" -d "notes=" >/dev/null
BMWID=$(q "SELECT id FROM instruments WHERE symbol='BMW.F'")
BY=$(q "SELECT yahoo_symbol FROM instruments WHERE id=$BMWID")
[ "$BY" = "BMW.F" ] && echo "  ok   a pasted .F is not doubled ($BY)" || { echo "  FAIL yahoo_symbol is $BY"; FAIL=1; }
post "/instruments" "$T" -d "symbol=SHOP" -d "market=CA" -d "currency=" -d "asset_class=EQUITY" -d "name=Shopify" -d "notes=" >/dev/null
CID=$(q "SELECT id FROM instruments WHERE symbol='SHOP'")
[ -n "$CID" ] && echo "  ok   Toronto ticker gains Yahoo's .TO" || { echo "  FAIL SHOP.TO instrument not created"; FAIL=1; }
post "/instruments" "$T" -d "symbol=1155" -d "market=KL" -d "currency=" -d "asset_class=EQUITY" -d "name=馬來銀行" -d "notes=" >/dev/null
KID=$(q "SELECT id FROM instruments WHERE symbol='1155'")
[ -n "$KID" ] && echo "  ok   Bursa ticker gains Yahoo's .KL" || { echo "  FAIL 1155.KL instrument not created"; FAIL=1; }
get "/instruments" "$WORK/links.html" >/dev/null
# All markets now link to Yahoo Finance, which uses the yahoo_symbol directly.
check "Frankfurt links to Yahoo with .F suffix"    "finance.yahoo.com/quote/SAP.F"    "$WORK/links.html"
check "a pasted .F stays as .F for Yahoo"          "finance.yahoo.com/quote/BMW.F"    "$WORK/links.html"
check "Toronto links to Yahoo with .TO suffix"      "finance.yahoo.com/quote/SHOP.TO"   "$WORK/links.html"
check "Bursa links to Yahoo with .KL suffix"         "finance.yahoo.com/quote/1155.KL" "$WORK/links.html"
notcheck "no dead GER spelling in any link"       "quote/SAP:GER"            "$WORK/links.html"
notcheck "no dead TSX spelling in any link"       "quote/SHOP:TSX"           "$WORK/links.html"
notcheck "no dead KLS spelling in any link"       "quote/1155:KLS"           "$WORK/links.html"

echo "fx rate auto-fetch, manual override and delete"
# Blank rate = fetch from the quote source, which is how a new currency gets a rate without typing numbers.
post "/fx" "$T" -d "currency=jpy" >/dev/null
JPY=$(q "SELECT units_per_usd FROM fx_rates WHERE currency='JPY'")
JM=$(q "SELECT manual FROM fx_rates WHERE currency='JPY'")
if [ -n "$JPY" ] && "$PHPBIN" -r 'exit(abs((float) $argv[1] - 150.25) < 1e-12 ? 0 : 1);' "$JPY"; then
  echo "  ok   JPY rate auto-fetched directly as units per USD ($JPY, manual=$JM)"
else
  echo "  FAIL JPY 匯率應為 150.25，實際 '$JPY'"; FAIL=1
fi
[ "$JM" = "0" ] && echo "  ok   auto-fetched rate is not flagged manual" || { echo "  FAIL manual flag is $JM"; FAIL=1; }
post "/fx" "$T" -d "currency=hkd" >/dev/null
HKD=$(q "SELECT units_per_usd FROM fx_rates WHERE currency='HKD'")
if [ -n "$HKD" ] && "$PHPBIN" -r 'exit(abs((float) $argv[1] - 7.82) < 1e-12 ? 0 : 1);' "$HKD"; then
  echo "  ok   HKD rate auto-fetched directly as units per USD ($HKD)"
else
  echo "  FAIL HKD 匯率應為 7.82，實際 '$HKD'"; FAIL=1
fi
CODE=$(curl -s -o "$WORK/fx-usd.html" -w "%{http_code}" -X POST "$BASE/fx" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "currency=USD" -d "units_per_usd=1")
[ "$CODE" = "400" ] && echo "  ok   USD rate rejected (400)" || { echo "  FAIL USD rate gave $CODE"; FAIL=1; }
post "/fx" "$T" -d "currency=thb" -d "units_per_usd=32.5" >/dev/null
N=$(q "SELECT COUNT(*) FROM fx_rates WHERE currency='THB'")
[ "$N" = "1" ] && echo "  ok   manual THB rate stored, code upper-cased" || { echo "  FAIL THB rate missing"; FAIL=1; }
# No -L here: these two assertions are about the status code the write itself returns.
CODE=$(curl -s -o "$WORK/fx-del.html" -w "%{http_code}" -X POST "$BASE/fx/delete" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "currency=THB")
[ "$CODE" = "303" ] && echo "  ok   POST /fx/delete -> 303" || { echo "  FAIL /fx/delete gave $CODE"; FAIL=1; }
N=$(q "SELECT COUNT(*) FROM fx_rates WHERE currency='THB'")
[ "$N" = "0" ] && echo "  ok   deleted rate is gone from the database" || { echo "  FAIL THB rate still present"; FAIL=1; }
CODE=$(curl -s -o "$WORK/usd-del.html" -w "%{http_code}" -X POST "$BASE/fx/delete" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "currency=USD")
[ "$CODE" = "400" ] && echo "  ok   USD anchor refuses to be deleted (400)" || { echo "  FAIL USD delete gave $CODE"; FAIL=1; }
check "the refusal says why" "固定基準" "$WORK/usd-del.html"
N=$(q "SELECT COUNT(*) FROM fx_rates WHERE currency='TWD'")
[ "$N" = "1" ] && echo "  ok   base currency rate untouched by the refused delete" || { echo "  FAIL TWD rate vanished"; FAIL=1; }

echo "back-parameter whitelist"
post "/prices/refresh" "$T" -d "portfolio_id=$PID" -d "back=https://evil.example/x" >/dev/null
if grep -qi "evil.example" "$LAST"; then echo "  FAIL untrusted back target reflected"; FAIL=1; else echo "  ok   untrusted back target ignored"; fi

echo "yahoo finance deep links"
get "/instruments" "$WORK/inst.html" >/dev/null
# All instruments now link to Yahoo Finance using their yahoo_symbol.
check "TW link goes to Yahoo with .TW suffix" "finance.yahoo.com/quote/2330.TW" "$WORK/inst.html"
check "US link goes to Yahoo with plain symbol" "finance.yahoo.com/quote/AAPL" "$WORK/inst.html"

echo "wording"
check "the instruments page calls itself 投資物資料庫" "投資物資料庫" "$WORK/inst.html"
notcheck "no 標的 wording left on that page" "標的" "$WORK/inst.html"
# The blanket rename must not have eaten the asset-class names, which legitimately contain 股票.
check "EQUITY is still labelled 股票" ">股票<" "$WORK/inst.html"
check "the ETF class still reads 指數股票型基金" "指數股票型基金" "$WORK/inst.html"
if grep -qE "投資物[[:space:]]*</a>" "$WORK/inst.html"; then echo "  ok   nav item reads 投資物"; else echo "  FAIL nav item is not 投資物"; FAIL=1; fi
check "the browser title carries the brand suffix" "｜投資組合追蹤系統</title>" "$WORK/inst.html"
check "the sidebar brand reads 投資組合追蹤系統" "投資組合追蹤系統 <span" "$WORK/inst.html"
if "$PHPBIN" -r '
    $html = file_get_contents($argv[1]);
    exit(preg_match("/<div class=\"manual-entry\">.*name=\"manual_price\".*name=\"manual_date\".*<\\/div>/s", $html) === 1 ? 0 : 1);
' "$WORK/inst.html"; then
  echo "  ok   manual price and date share one non-wrapping row"
else
  echo "  FAIL manual price and date are not in the same entry row"; FAIL=1
fi

echo "pages render"
for p in "" "/transactions" "/instruments" "/portfolios" "/settings" "/reports" "/laboratory"; do
  C=$(get "$p" "$WORK/page.html")
  E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/page.html")
  if [ "$C" = "200" ] && [ "$E" = "0" ]; then echo "  ok   GET ${p:-/} 200, no PHP notices"; else
    echo "  FAIL GET ${p:-/} code=$C notices=$E"; grep -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/page.html" | head -3; FAIL=1
  fi
done

echo "stylesheet reachable"
ORIGIN=$(printf '%s' "$BASE" | sed -E 's#^(https?://[^/]+).*#\1#')
HREF=$(grep -o 'rel="stylesheet" href="[^"]*"' "$WORK/page.html" | head -1 | sed 's/.*href="//;s/"//')
case "$HREF" in
  //*|http*) echo "  FAIL stylesheet href is absolute/protocol-relative: $HREF"; FAIL=1;;
  "")        echo "  FAIL no stylesheet link found"; FAIL=1;;
  *)         SC=$(curl -s -o "$WORK/css.txt" -w "%{http_code}" "$ORIGIN$HREF")
             if [ "$SC" = "200" ] && grep -q "topbar" "$WORK/css.txt"; then echo "  ok   $HREF -> 200, css served"; else
               echo "  FAIL $HREF -> $SC"; FAIL=1; fi;;
esac
check "the manual price entry row cannot wrap between the inputs" ".manual-entry { display: flex; flex-wrap: nowrap;" "$WORK/css.txt"
checkf "手動價格、日期與報價日期固定同一行" 'form.inline.manual-price-form { display: flex; flex-wrap: nowrap; gap: 8px; align-items: center; }' "$WORK/css.txt"
checkf "手動價格按鈕不換行" '.manual-actions { display: flex; flex-wrap: nowrap; gap: 8px; align-items: center; }' "$WORK/css.txt"
checkf "報價日期不換行" '.manual-quote-date { white-space: nowrap; }' "$WORK/css.txt"
checkf "新增投資物表單使用固定欄位類別" 'class="instrument-form-grid"' "$WORK/inst.html"
checkf "桌面的新增投資物表單固定六欄" '.instrument-form-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; }' "$WORK/css.txt"
checkf "窄螢幕的新增投資物表單改為三欄" '.instrument-form-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }' "$WORK/css.txt"
checkf "手機寬度的新增投資物表單改為兩欄" '.instrument-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }' "$WORK/css.txt"
checkf "組合狀態欄加寬並置中" '.portfolio-status { width: 200px; padding-left: 32px; padding-right: 32px; text-align: center; }' "$WORK/css.txt"
checkf "組合操作按鈕群靠左對齊" '.row-actions.portfolio-actions { justify-content: flex-start; padding-left: 12px; }' "$WORK/css.txt"

echo "csv export"
curl -s -c "$JAR" -b "$JAR" "$BASE/export" -o "$WORK/exp.csv"
grep -q "買進" "$WORK/exp.csv" && echo "  ok   csv contains trades" || { echo "  FAIL csv missing trades"; FAIL=1; }
grep -q "AAPL" "$WORK/exp.csv" && echo "  ok   csv contains AAPL" || { echo "  FAIL csv missing AAPL"; FAIL=1; }
head -c 3 "$WORK/exp.csv" | od -An -tx1 | grep -q "ef bb bf" && echo "  ok   csv has UTF-8 BOM for Excel" || { echo "  FAIL no BOM"; FAIL=1; }

echo "routing"
C=$(get "/nope" "$WORK/404.html")
[ "$C" = "404" ] && echo "  ok   unknown route -> 404" || { echo "  FAIL unknown route gave $C"; FAIL=1; }
C=$(get "/portfolios/99999/delete" "$WORK/x.html")
[ "$C" = "405" ] || [ "$C" = "404" ] && echo "  ok   GET-only-on-POST-route rejected ($C)" || { echo "  FAIL unexpected $C"; FAIL=1; }
# 持股損益 was deleted on purpose, so the old address has to die rather than quietly land elsewhere.
C=$(get "/holdings" "$WORK/gone.html")
[ "$C" = "404" ] && echo "  ok   the deleted 持股頁 answers 404" || { echo "  FAIL GET /holdings gave $C"; FAIL=1; }

echo "cash in and out (現金)"
# Own portfolio so every number below is exact: deposits/withdrawals plus each trade's settlement,
# nothing else moves cash in here.
post "/portfolios" "$T" -d "name=現金測試" -d "description=現金" >/dev/null
CPID=$(q "SELECT id FROM portfolios WHERE name='現金測試'")
[ -n "$CPID" ] && echo "  ok   cash portfolio created (id=$CPID)" || { echo "  FAIL cash portfolio missing"; exit 1; }
post "/portfolios/$CPID/select" "$T" >/dev/null

post "/cash" "$T" -d "portfolio_id=$CPID" -d "direction=IN" -d "amount=100000" \
  -d "currency=twd" -d "moved_on=2025-01-05" -d "note=銀行轉入" >/dev/null
CA=$(q "SELECT amount FROM cash_movements WHERE portfolio_id=$CPID AND direction='IN'")
[ "$CA" = "100000" ] && echo "  ok   deposit stored with the amount as typed" || { echo "  FAIL deposit is '$CA'"; FAIL=1; }
CC=$(q "SELECT currency FROM cash_movements WHERE portfolio_id=$CPID")
[ "$CC" = "TWD" ] && echo "  ok   lower-case currency is upper-cased on the way in" || { echo "  FAIL currency is '$CC'"; FAIL=1; }

# BUY 1000 @ 545 fee 30 -> 545,030 out of a 100,000 balance, so cash goes negative on purpose.
post "/transactions" "$T" -d "portfolio_id=$CPID" -d "side=BUY" -d "traded_on=2025-01-06" \
  -d "quantity=1000" -d "price=545" -d "fee=30" -d "instrument_id=$TWID" >/dev/null
get "/transactions" "$WORK/cash.html" >/dev/null
check "the deposit shows as 存入合計"      'NT$100,000\.00'  "$WORK/cash.html"
check "the buy shows as 買進支出 incl. fee" 'NT$545,030\.00'  "$WORK/cash.html"
check "cash goes negative without a matching deposit" '-NT$445,030\.00' "$WORK/cash.html"

# SELL 400 @ 600 fee 30 -> 400*600-30 = 239,970 in.
post "/transactions" "$T" -d "portfolio_id=$CPID" -d "side=SELL" -d "traded_on=2025-02-06" \
  -d "quantity=400" -d "price=600" -d "fee=30" -d "instrument_id=$TWID" >/dev/null
get "/transactions" "$WORK/cash.html" >/dev/null
check "the sell pays in net of fee" 'NT$239,970\.00' "$WORK/cash.html"
check "the sell lifts the balance" '-NT$205,060\.00' "$WORK/cash.html"

# OUT 5000 -> the negative balance accepts a withdrawal, and deleting it gives the money back.
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/cash" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$CPID" -d "direction=OUT" -d "amount=5000" -d "currency=TWD" -d "moved_on=2025-03-01")
[ "$CODE" = "303" ] && echo "  ok   withdrawal from a negative balance is allowed (303)" || { echo "  FAIL withdrawal gave $CODE"; FAIL=1; }
OUTM=$(q "SELECT id FROM cash_movements WHERE portfolio_id=$CPID AND direction='OUT'")
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/cash/$OUTM/delete" -b "$JAR" -c "$JAR" -d "_csrf=$T")
[ "$CODE" = "303" ] && echo "  ok   POST /cash/{id}/delete -> 303" || { echo "  FAIL cash delete gave $CODE"; FAIL=1; }
N=$(q "SELECT COUNT(*) FROM cash_movements WHERE id=$OUTM")
[ "$N" = "0" ] && echo "  ok   deleted movement is gone from the database" || { echo "  FAIL movement still present"; FAIL=1; }

# Deleting a trade moves cash back by itself -- no ledger to keep in sync.
TXSELL=$(q "SELECT id FROM transactions WHERE portfolio_id=$CPID AND side='SELL'")
post "/transactions/$TXSELL/delete" "$T" >/dev/null
get "/transactions" "$WORK/cash.html" >/dev/null
check "deleting the sell puts its proceeds back" '-NT$445,030\.00' "$WORK/cash.html"

echo "cash validation"
CODE=$(curl -s -o "$WORK/cash-b1.html" -w "%{http_code}" -X POST "$BASE/cash" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$CPID" -d "direction=IN" -d "amount=0" -d "currency=TWD")
[ "$CODE" = "400" ] && echo "  ok   amount of zero rejected (400)" || { echo "  FAIL zero amount gave $CODE"; FAIL=1; }
check "the refusal asks for an amount" "請輸入大於 0 的金額" "$WORK/cash-b1.html"
CODE=$(curl -s -o "$WORK/cash-b2.html" -w "%{http_code}" -X POST "$BASE/cash" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$CPID" -d "direction=IN" -d "amount=100" -d "currency=US$")
[ "$CODE" = "400" ] && echo "  ok   a currency containing punctuation rejected (400)" || { echo "  FAIL bad currency gave $CODE"; FAIL=1; }
CODE=$(curl -s -o "$WORK/cash-b3.html" -w "%{http_code}" -X POST "$BASE/cash" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$CPID" -d "direction=MAYBE" -d "amount=100" -d "currency=TWD")
[ "$CODE" = "400" ] && echo "  ok   unknown direction rejected (400)" || { echo "  FAIL bad direction gave $CODE"; FAIL=1; }
CODE=$(curl -s -o "$WORK/cash-b4.html" -w "%{http_code}" -X POST "$BASE/cash" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "portfolio_id=$CPID" -d "direction=IN" -d "amount=100" -d "currency=TWD" -d "moved_on=2025-13-40")
[ "$CODE" = "400" ] && echo "  ok   impossible date rejected (400)" || { echo "  FAIL bad date gave $CODE"; FAIL=1; }
N=$(q "SELECT COUNT(*) FROM cash_movements WHERE portfolio_id=$CPID")
[ "$N" = "1" ] && echo "  ok   every rejected write left the table alone" || { echo "  FAIL expected 1 movement, got $N"; FAIL=1; }

echo "cash panel wording"
check "the panel is titled 現金" ">現金<" "$WORK/cash.html"
check "in and out are labelled in chinese" ">存入<" "$WORK/cash.html"
check "the withdrawal option is there too" ">提取<" "$WORK/cash.html"
check "the hint explains trades move cash" "餘額自動跟隨" "$WORK/cash.html"
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/cash.html")
[ "$E" = "0" ] && echo "  ok   transactions page with cash rows renders clean" || { echo "  FAIL cash page has notices"; FAIL=1; }

echo "cash log pagination"
execq "$DB" "WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM n WHERE x<100)
  INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency,note)
  SELECT $CPID,date('2025-04-01','+'||x||' days'),'IN',1,'TWD','分頁測試-'||x FROM n;"
get "/transactions" "$WORK/cash-page1.html" >/dev/null
check "cash log states its total and page size" '現金流水：共 101 筆，每頁 100 筆' "$WORK/cash-page1.html"
check "first cash page shows page one of two" '第 1／2 頁' "$WORK/cash-page1.html"
check "cash pager links to the second page" 'cash_page=2' "$WORK/cash-page1.html"
notcheck "oldest deposit is not duplicated on page one" '2025-01-05' "$WORK/cash-page1.html"
get "/transactions?cash_page=2" "$WORK/cash-page2.html" >/dev/null
check "second cash page contains the remaining oldest deposit" '2025-01-05' "$WORK/cash-page2.html"
check "second cash page shows page two of two" '第 2／2 頁' "$WORK/cash-page2.html"
notcheck "last cash page has no next link" '下一頁' "$WORK/cash-page2.html"
execq "$DB" "DELETE FROM cash_movements WHERE portfolio_id=$CPID AND note LIKE '分頁測試-%';"
[ "$(q "SELECT COUNT(*) FROM cash_movements WHERE portfolio_id=$CPID")" = "1" ] \
  && echo "  ok   synthetic cash pages cleaned up" || { echo "  FAIL synthetic cash rows survived"; FAIL=1; }

echo "dashboard: 現金與各幣別"
# An HKD holding in a TWD-base portfolio: the point of this panel is to read 港幣市值 in HK dollars
# while the totals stay in the accounting currency.
post "/transactions" "$T" -d "portfolio_id=$CPID" -d "side=BUY" -d "traded_on=2025-03-03" \
  -d "quantity=200" -d "price=372.5" -d "fee=60" -d "instrument_id=" \
  -d "new_symbol=0700" -d "new_market=HK" -d "new_name=騰訊" >/dev/null
post "/prices/refresh" "$T" -d "portfolio_id=$CPID" >/dev/null
get "/" "$WORK/dash.html" >/dev/null
check "the overview carries a 現金 card" "現金（" "$WORK/dash.html"
check "the overview has its own currency table" "現金與各幣別" "$WORK/dash.html"
check "market value gets a column" ">持股市值<" "$WORK/dash.html"
sed -n '/現金與各幣別/,/<\/table>/p' "$WORK/dash.html" > "$WORK/breakdown.html"
check "HKD gets its own line" ">HKD<" "$WORK/breakdown.html"
check "the base-currency column is the sum of both" ">持股＋現金<" "$WORK/breakdown.html"
if grep -q -- "-HK[$]" "$WORK/breakdown.html"; then
  echo "  ok   a buy with no matching deposit shows as negative HKD cash"
else
  echo "  FAIL the HKD balance is not shown as negative"; FAIL=1
fi
if grep -q -- "HK[$][0-9]" "$WORK/breakdown.html"; then
  echo "  ok   the HKD row is valued in HK dollars, not converted away"
else
  echo "  FAIL no native HKD figure on the currency table"; FAIL=1
fi

# Independent invariant: holdings plus cash, as cards, must equal the table's own total.
cat > "$WORK/dashsum.php" <<'PHP'
<?php
$html = file_get_contents($argv[1]);
$pick = static function (string $pattern) use ($html): ?float {
    if (preg_match($pattern, $html, $m) !== 1) {
        return null;
    }
    $digits = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $m[1]));

    return $digits === '' ? null : (float) $digits;
};
$mv = $pick('/組合市值[^<]*<\/div>\s*<div class="value[^>]*>(.*?)<\/div>/s');
$cash = $pick('/現金（[^<]*<\/div>\s*<div class="value[^>]*>(.*?)<\/div>/s');
$sum = $pick('/合計（[A-Z]{3}）<\/td>\s*<td colspan="2"><\/td>\s*<td class="num">(.*?)<\/td>/s');
if ($mv === null || $cash === null || $sum === null) {
    printf('missing mv=%s cash=%s sum=%s', var_export($mv, true), var_export($cash, true), var_export($sum, true));
    exit(0);
}
printf('%s mv=%.2f cash=%.2f sum=%.2f', abs($mv + $cash - $sum) < 0.011 ? 'ok' : 'no', $mv, $cash, $sum);
PHP
INV=$("$PHPBIN" -d display_errors=stderr "$WORK/dashsum.php" "$WORK/dash.html")
case "$INV" in
  ok*) echo "  ok   持股＋現金 equals the two cards ($INV)";;
  *)   echo "  FAIL currency total does not reconcile: $INV"; FAIL=1;;
esac
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/dash.html")
[ "$E" = "0" ] && echo "  ok   dashboard with cash and fx rows renders clean" || { echo "  FAIL dashboard has notices"; FAIL=1; }

echo "overview: all portfolios as one scope"
# One symbol held by two portfolios at different costs. The merged row must be the sum of each
# portfolio's own costing, not one shared average-cost pool: with a pool, 聚合甲's sell at 30 would
# realise against 聚合乙's cost of 20 and report 750 instead of 1,000.
post "/portfolios" "$T" -d "name=聚合甲" -d "description=" >/dev/null
GA=$(q "SELECT id FROM portfolios WHERE name='聚合甲'")
post "/portfolios" "$T" -d "name=聚合乙" -d "description=" >/dev/null
GB=$(q "SELECT id FROM portfolios WHERE name='聚合乙'")
post "/instruments" "$T" -d "symbol=MRGE" -d "market=US" -d "currency=" -d "asset_class=EQUITY" -d "name=合併測試" >/dev/null
MID=$(q "SELECT id FROM instruments WHERE symbol='MRGE'")
post "/transactions" "$T" -d "instrument_id=$MID" -d "portfolio_id=$GA" -d "side=BUY" -d "quantity=100" -d "price=10" -d "traded_on=2025-06-02" >/dev/null
post "/transactions" "$T" -d "instrument_id=$MID" -d "portfolio_id=$GA" -d "side=SELL" -d "quantity=50" -d "price=30" -d "traded_on=2025-06-05" >/dev/null
post "/transactions" "$T" -d "instrument_id=$MID" -d "portfolio_id=$GB" -d "side=BUY" -d "quantity=100" -d "price=20" -d "traded_on=2025-06-03" >/dev/null
post "/instruments/$MID/price" "$T" -d "manual_price=25" >/dev/null

scoped_get "/?scope=$GA" "$WORK/scope-a.html" >/dev/null
mergerow() { awk '/MRGE/{f=1} f{print} f&&/<\/tr>/{exit}' "$1" > "$2"; }
mergerow "$WORK/scope-a.html" "$WORK/row-a.html"
check "甲 keeps its own 50 shares" ">50<" "$WORK/row-a.html"
check "甲 keeps its own average cost of 10" "US\$10\.00" "$WORK/row-a.html"
check "甲 books its own realised 1,000" '+US\$1,000\.00' "$WORK/row-a.html"
scoped_get "/?scope=$GB" "$WORK/scope-b.html" >/dev/null
mergerow "$WORK/scope-b.html" "$WORK/row-b.html"
check "乙 keeps its own 100 shares" ">100<" "$WORK/row-b.html"
check "乙 keeps its own average cost of 20" "US\$20\.00" "$WORK/row-b.html"
check "乙 has nothing realised" 'US\$0\.00' "$WORK/row-b.html"

scoped_get "/?scope=all" "$WORK/scope-all.html" >/dev/null
mergerow "$WORK/scope-all.html" "$WORK/row-all.html"
ALLP=$(q "SELECT COUNT(*) FROM portfolios")
check "the picker keeps 所有組合 selected" '<option value="all" selected' "$WORK/scope-all.html"
check "the heading counts every portfolio" "所有組合合計（$ALLP 個）" "$WORK/scope-all.html"
check "the two lots merge into 150 shares" ">150<" "$WORK/row-all.html"
check "cost is the weighted 2,500 over 150" 'US\$16\.67' "$WORK/row-all.html"
check "market value spans both portfolios" 'US\$3,750\.00' "$WORK/row-all.html"
check "realised P&L stays each portfolio's own, not a pooled 750" '+US\$1,000\.00' "$WORK/row-all.html"
notcheck "a pooled average would have read 15.00" 'US\$15\.00' "$WORK/row-all.html"
check "a shared lot is badged with its portfolio count" '2 個組合' "$WORK/row-all.html"
check "the trade list grows a 組合 column" '>組合</th>' "$WORK/scope-all.html"
sed -n '/現金與各幣別/,/<\/table>/p' "$WORK/scope-all.html" > "$WORK/breakdown-all.html"
check "every currency in play still gets a line" ">TWD<" "$WORK/breakdown-all.html"
INV=$("$PHPBIN" -d display_errors=stderr "$WORK/dashsum.php" "$WORK/scope-all.html")
case "$INV" in
  ok*) echo "  ok   the merged 持股＋現金 reconciles ($INV)";;
  *)   echo "  FAIL merged currency total does not reconcile: $INV"; FAIL=1;;
esac
get "/" "$WORK/scope-sticky.html" >/dev/null
check "the scope survives a plain visit" '所有組合合計' "$WORK/scope-sticky.html"
notcheck "nothing is merged twice over" '3 個組合' "$WORK/scope-sticky.html"
scoped_get "/?scope=all" "$WORK/scope-before-create.html" >/dev/null
T=$(csrf "/portfolios")
post "/portfolios" "$T" -d "name=範圍新建" -d "description=" >/dev/null
SCOPECREATE=$(q "SELECT id FROM portfolios WHERE name='範圍新建'")
get "/" "$WORK/scope-after-create.html" >/dev/null
check "creating a portfolio leaves the merged scope" "value=\"$SCOPECREATE\" selected" "$WORK/scope-after-create.html"
notcheck "new portfolio is not still pooled with every other one" '<option value="all" selected' "$WORK/scope-after-create.html"
scoped_get "/?scope=all" "$WORK/scope-before-select.html" >/dev/null
post "/portfolios/$GA/select" "$T" >/dev/null
get "/" "$WORK/scope-after-select.html" >/dev/null
check "selecting an existing portfolio leaves the merged scope" "value=\"$GA\" selected" "$WORK/scope-after-select.html"
notcheck "selected portfolio is no longer pooled" '<option value="all" selected' "$WORK/scope-after-select.html"
execq "$DB" "DELETE FROM portfolios WHERE id=$SCOPECREATE;"
scoped_get "/?scope=$GA" "$WORK/scope-back.html" >/dev/null
check "picking one portfolio drops the merge" "value=\"$GA\" selected" "$WORK/scope-back.html"
notcheck "a single portfolio is never badged" '個組合</span>' "$WORK/scope-back.html"
scoped_get "/?scope=99999" "$WORK/scope-bogus.html" >/dev/null
check "an unknown scope falls back to the last one picked" "value=\"$GA\" selected" "$WORK/scope-bogus.html"
scoped_get "/?scope=$GA%20OR%201=1" "$WORK/scope-sqli.html" >/dev/null
check "a non-numeric scope is ignored, not obeyed" "value=\"$GA\" selected" "$WORK/scope-sqli.html"
E=$(cat "$WORK"/scope-*.html | grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined")
[ "$E" = "0" ] && echo "  ok   every scope variant renders clean" || { echo "  FAIL $E notice(s) across scope pages"; FAIL=1; }

echo "transactions: 組合選擇"
# 聚合甲 has two trades and 聚合乙 one, so what each scope is supposed to list is countable by hand.
txrows() { grep -o '<strong>MRGE</strong>' "$1" | wc -l | tr -d ' '; }
scoped_get "/transactions?scope=$GA" "$WORK/tx-a.html" >/dev/null
[ "$(txrows "$WORK/tx-a.html")" = "2" ] && echo "  ok   甲 lists only its own two trades" \
  || { echo "  FAIL 甲 listed $(txrows "$WORK/tx-a.html") rows"; FAIL=1; }
notcheck "甲 does not borrow 乙's row" '>聚合乙</td>' "$WORK/tx-a.html"
notcheck "a single portfolio needs no 組合 column" '>組合</th>' "$WORK/tx-a.html"
check "交易紀錄 offers its own 範圍 picker" '<option value="all"' "$WORK/tx-a.html"
scoped_get "/transactions?scope=$GB" "$WORK/tx-b.html" >/dev/null
[ "$(txrows "$WORK/tx-b.html")" = "1" ] && echo "  ok   乙 lists only its own trade" \
  || { echo "  FAIL 乙 listed $(txrows "$WORK/tx-b.html") rows"; FAIL=1; }
scoped_get "/transactions?scope=all" "$WORK/tx-all.html" >/dev/null
[ "$(txrows "$WORK/tx-all.html")" = "3" ] && echo "  ok   所有組合 merges both portfolios" \
  || { echo "  FAIL merged scope listed $(txrows "$WORK/tx-all.html") rows"; FAIL=1; }
check "the merged list grows a 組合 column" '>組合</th>' "$WORK/tx-all.html"
check "and names where each trade came from" '>聚合甲</td>' "$WORK/tx-all.html"
check "the picker keeps 所有組合 selected" '<option value="all" selected' "$WORK/tx-all.html"
check "the tally says the list is merged" "（所有組合合計）" "$WORK/tx-all.html"
check "cash is still one portfolio's own" "只屬於" "$WORK/tx-all.html"
get "/transactions" "$WORK/tx-sticky.html" >/dev/null
check "the scope sticks for the next visit" '<option value="all" selected' "$WORK/tx-sticky.html"
# The date filter has to keep working across the merged list: 2025-06-03..05 catches 乙's buy and
# 甲's sell but not 甲's 06-02 buy, which is only visible because both portfolios are in scope.
get "/transactions?from=2025-06-03&to=2025-06-05" "$WORK/tx-filter.html" >/dev/null
[ "$(txrows "$WORK/tx-filter.html")" = "2" ] && echo "  ok   the date filter runs across the merged scope" \
  || { echo "  FAIL filtered merge listed $(txrows "$WORK/tx-filter.html") rows"; FAIL=1; }
scoped_get "/transactions?scope=99999" "$WORK/tx-bogus.html" >/dev/null
check "an unknown scope falls back to the last one picked" '<option value="all" selected' "$WORK/tx-bogus.html"
scoped_get "/transactions?scope=$GA%20OR%201=1" "$WORK/tx-sqli.html" >/dev/null
[ "$(txrows "$WORK/tx-sqli.html")" = "3" ] && echo "  ok   a non-numeric scope is ignored, not obeyed" \
  || { echo "  FAIL injection gave $(txrows "$WORK/tx-sqli.html") rows"; FAIL=1; }
scoped_get "/transactions?scope=$GB" "$WORK/tx-form.html" >/dev/null
check "picking one portfolio drops the merge" "value=\"$GB\" selected" "$WORK/tx-form.html"
check "and the new-trade form opens on it" "value=\"$GB\" selected>聚合乙</option>" "$WORK/tx-form.html"
E=$(cat "$WORK"/tx-*.html | grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined")
[ "$E" = "0" ] && echo "  ok   every transaction scope renders clean" || { echo "  FAIL $E notice(s) on 交易紀錄"; FAIL=1; }
# 匯出 CSV has to hand over the rows on screen, not whatever the session happened to be pointed at.
# 甲 holds two trades and 乙 one; the merged scope is the whole book, which by now is more than those three.
csvrows() { grep -c '^20' "$1"; }
TOTAL=$(q "SELECT COUNT(*) FROM transactions")
get "/export?scope=all" "$WORK/exp-all.csv" >/dev/null
[ "$(csvrows "$WORK/exp-all.csv")" = "$TOTAL" ] && echo "  ok   the merged scope exports all $TOTAL trades, not one portfolio's" \
  || { echo "  FAIL merged export has $(csvrows "$WORK/exp-all.csv") rows, the book holds $TOTAL"; FAIL=1; }
get "/export?scope=$GA" "$WORK/exp-a.csv" >/dev/null
[ "$(csvrows "$WORK/exp-a.csv")" = "2" ] && echo "  ok   and a single scope exports only its own (甲)" \
  || { echo "  FAIL 甲's export has $(csvrows "$WORK/exp-a.csv") rows, expected 2"; FAIL=1; }
[ "$TOTAL" -gt 3 ] && echo "  ok   the two files are provably different scopes ($TOTAL against 2)" \
  || { echo "  FAIL the merged book only held $TOTAL trades, so this proves nothing"; FAIL=1; }
get "/export?scope=$GB" "$WORK/exp-b.csv" >/dev/null
[ "$(csvrows "$WORK/exp-b.csv")" = "1" ] && echo "  ok   the other portfolio keeps its one row apart" \
  || { echo "  FAIL 乙's export has $(csvrows "$WORK/exp-b.csv") rows, expected 1"; FAIL=1; }
check "the merged page's link carries the scope" "export?scope=all" "$WORK/tx-all.html"
check "and says how many rows are coming" "匯出 CSV（$TOTAL 筆）" "$WORK/tx-all.html"
# An id that holds nothing is not a reason to fall back to the whole book.
get "/export?scope=99999" "$WORK/exp-none.csv" >/dev/null
[ "$(csvrows "$WORK/exp-none.csv")" = "0" ] && echo "  ok   a portfolio with no trades exports a header only" \
  || { echo "  FAIL the empty scope still handed over $(csvrows "$WORK/exp-none.csv") rows"; FAIL=1; }
[ "$(wc -l < "$WORK/exp-none.csv")" = "1" ] \
  && echo "  ok   and there is nothing after that header" || { echo "  FAIL rows followed the header"; FAIL=1; }
scoped_get "/transactions?scope=$GA" "$WORK/tx-reset.html" >/dev/null
# The on-screen filters are part of the promise too: the same picker narrowed to 合併測試 has to
# hand over the same three rows, and the date window has to survive into the file.
get "/export?scope=all&instrument=$MID" "$WORK/exp-mrge.csv" >/dev/null
[ "$(csvrows "$WORK/exp-mrge.csv")" = "3" ] && echo "  ok   the instrument filter narrows the file the same way" \
  || { echo "  FAIL the filtered export has $(csvrows "$WORK/exp-mrge.csv") rows, expected 3"; FAIL=1; }
get "/export?scope=all&instrument=$MID&from=2025-06-03&to=2025-06-05" "$WORK/exp-window.csv" >/dev/null
[ "$(csvrows "$WORK/exp-window.csv")" = "2" ] && echo "  ok   both filters together catch the same two trades" \
  || { echo "  FAIL the windowed export has $(csvrows "$WORK/exp-window.csv") rows, expected 2"; FAIL=1; }

echo "transaction pagination and filtered CSV"
execq "$DB" "WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM n WHERE x<105)
  INSERT INTO transactions (portfolio_id,instrument_id,side,traded_on,quantity,price,fee,note)
  SELECT $GA,$MID,'BUY',date('2025-08-01','+'||(x % 10)||' days'),1,10,0,'交易分頁-'||x FROM n;"
execq "$DB" "WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM n WHERE x<105)
  INSERT INTO cash_movements (portfolio_id,moved_on,direction,amount,currency,note)
  SELECT $GA,date('2025-08-01','+'||(x % 10)||' days'),'IN',1,'USD','交易分頁現金-'||x FROM n;"
TX_FILTER="scope=$GA&instrument=$MID&from=2025-08-01&to=2025-08-10"
get "/transactions?$TX_FILTER&cash_page=2" "$WORK/tx-page1.html" >/dev/null
[ "$(txrows "$WORK/tx-page1.html")" = "100" ] && echo "  ok   the first transaction page holds 100 rows" \
  || { echo "  FAIL first page listed $(txrows "$WORK/tx-page1.html") rows"; FAIL=1; }
checkf "the filtered total covers every page" '共 105 筆' "$WORK/tx-page1.html"
checkf "the visible range describes the current page" '本頁顯示 1–100 筆' "$WORK/tx-page1.html"
checkf "the CSV count is the full filtered scope" '匯出 CSV（105 筆）' "$WORK/tx-page1.html"
checkf "the CSV link keeps all filters" "export?scope=$GA&amp;instrument=$MID&amp;from=2025-08-01&amp;to=2025-08-10" "$WORK/tx-page1.html"
checkf "transaction paging has an accessible label" 'aria-label="交易紀錄分頁"' "$WORK/tx-page1.html"
checkf "the next control exposes its relation" 'rel="next"' "$WORK/tx-page1.html"
checkf "the next trade page preserves filters and cash page" "scope=$GA&amp;instrument=$MID&amp;from=2025-08-01&amp;to=2025-08-10&amp;cash_page=2&amp;page=2" "$WORK/tx-page1.html"
checkf "cash paging keeps the current trade page" 'page=1&amp;cash_page=1' "$WORK/tx-page1.html"
get "/transactions?$TX_FILTER&cash_page=2&page=2" "$WORK/tx-page2.html" >/dev/null
[ "$(txrows "$WORK/tx-page2.html")" = "5" ] && echo "  ok   the second transaction page holds the remaining five rows" \
  || { echo "  FAIL second page listed $(txrows "$WORK/tx-page2.html") rows"; FAIL=1; }
checkf "the second page reports its range" '本頁顯示 101–105 筆' "$WORK/tx-page2.html"
checkf "the previous control exposes its relation" 'rel="prev"' "$WORK/tx-page2.html"
checkf "the previous trade page keeps the independent cash page" "cash_page=2&amp;page=1" "$WORK/tx-page2.html"
checkf "cash paging follows the second trade page" 'page=2&amp;cash_page=1' "$WORK/tx-page2.html"
get "/transactions?$TX_FILTER&cash_page=2&page=999" "$WORK/tx-clamped.html" >/dev/null
[ "$(txrows "$WORK/tx-clamped.html")" = "5" ] && echo "  ok   an out-of-range trade page clamps to the last page" \
  || { echo "  FAIL clamped page listed $(txrows "$WORK/tx-clamped.html") rows"; FAIL=1; }
get "/transactions?$TX_FILTER&page=2invalid" "$WORK/tx-invalid.html" >/dev/null
[ "$(txrows "$WORK/tx-invalid.html")" = "100" ] && echo "  ok   an invalid trade page clamps to the first page" \
  || { echo "  FAIL invalid page listed $(txrows "$WORK/tx-invalid.html") rows"; FAIL=1; }
get "/export?$TX_FILTER&page=2" "$WORK/exp-paged.csv" >/dev/null
[ "$(csvrows "$WORK/exp-paged.csv")" = "105" ] && echo "  ok   CSV ignores table pagination and exports all 105 filtered rows" \
  || { echo "  FAIL paged export has $(csvrows "$WORK/exp-paged.csv") rows"; FAIL=1; }
execq "$DB" "DELETE FROM transactions WHERE portfolio_id=$GA AND note LIKE '交易分頁-%';
  DELETE FROM cash_movements WHERE portfolio_id=$GA AND note LIKE '交易分頁現金-%';"
[ "$(q "SELECT COUNT(*) FROM transactions WHERE portfolio_id=$GA")" = "2" ] \
  && echo "  ok   synthetic transaction pages cleaned up" || { echo "  FAIL synthetic trades survived"; FAIL=1; }

# The portfolio delete dialog interpolates what a person typed, and an attribute is entity-decoded
# before the script inside it is compiled. An apostrophe in the name therefore used to close the
# literal early: the handler threw SyntaxError, never returned false, and the form deleted the row
# without asking. The safe form is a JSON string literal, which is what this looks for.
T=$(csrf "/portfolios")
# -X POST without -L throughout this block, so the code under test is the redirect itself.
CODE=$(curl -s -o "$LAST" -w "%{http_code}" -X POST "$BASE/portfolios" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "name=Tom's 長期" -d "description=")
[ "$CODE" = "303" ] && echo "  ok   a portfolio name may contain an apostrophe" || { echo "  FAIL gave $CODE"; FAIL=1; }
get "/portfolios" "$WORK/apos.html" >/dev/null
checkf "組合狀態欄與交易筆數之間保留間距" '<th class="portfolio-status">狀態</th>' "$WORK/apos.html"
checkf "使用中狀態也套用相同欄距" '<td class="portfolio-status"><span class="badge buy">使用中</span></td>' "$WORK/apos.html"
checkf "組合操作按鈕靠左靠近狀態欄" 'class="row-actions portfolio-actions"' "$WORK/apos.html"
checkf "the dialog quotes it as a JS escape rather than a live quote" 'Tom\u0027s' "$WORK/apos.html"
notcheck "so nothing is left outside the string literal" "confirm('刪除「Tom" "$WORK/apos.html"
checkf "and the attribute itself stays closed" 'confirm(&quot;' "$WORK/apos.html"
APOS=$(q "SELECT id FROM portfolios WHERE name = 'Tom''s 長期'")
[ -n "$APOS" ] && echo "  ok   the odd name is stored as typed (id=$APOS)" || { echo "  FAIL the name did not store"; FAIL=1; }
# -X POST without -L, so the code under test is the redirect itself rather than the page after it.
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/portfolios/$APOS/delete" -b "$JAR" -c "$JAR" -d "_csrf=$T")
[ "$CODE" = "303" ] && [ "$(q "SELECT COUNT(*) FROM portfolios WHERE id = $APOS")" = "0" ] \
  && echo "  ok   and it deletes with the dialog intact" || { echo "  FAIL delete gave $CODE"; FAIL=1; }
# A symbol is user text too, and the 投資物 page still has to render it safely. Deletion now needs
# no browser dialog or typed symbol, even when the instrument has cached price rows.
T=$(csrf "/instruments")
CODE=$(curl -s -o "$WORK/apos-i-bad.html" -w "%{http_code}" -X POST "$BASE/instruments" -b "$JAR" -c "$JAR" \
  -d "_csrf=$T" -d "symbol=Q'T" -d "name=測試符號" -d "market=US")
[ "$CODE" = "400" ] && echo "  ok   an unsupported symbol is rejected at input (400)" || { echo "  FAIL gave $CODE"; FAIL=1; }
execq "$DB" "INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency) VALUES ('Q''T','舊版測試符號','US','LEGACYQT','USD');
  INSERT INTO prices (instrument_id, as_of, close, currency) VALUES
    ((SELECT id FROM instruments WHERE yahoo_symbol='LEGACYQT'), '$TODAY', 42.5, 'USD');"
get "/instruments" "$WORK/apos-i.html" >/dev/null
QID=$(q "SELECT id FROM instruments WHERE yahoo_symbol = 'LEGACYQT'")
[ -n "$QID" ] && echo "  ok   a legacy symbol still renders safely" || { echo "  FAIL the instrument is missing"; FAIL=1; }
checkf "the odd symbol is still entity-escaped" 'Q&#039;T' "$WORK/apos-i.html"
notcheck "instrument deletion has no browser confirmation" 'onsubmit="return confirm(' "$WORK/apos-i.html"
notcheck "instrument deletion has no typed-symbol confirmation" 'confirm_symbol' "$WORK/apos-i.html"
T=$(csrf "/instruments")
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/instruments/$QID/delete" -b "$JAR" -c "$JAR" -d "_csrf=$T")
[ "$CODE" = "303" ] && [ "$(q "SELECT COUNT(*) FROM instruments WHERE id = $QID")" = "0" ] \
  && [ "$(q "SELECT COUNT(*) FROM prices WHERE instrument_id = $QID")" = "0" ] \
  && echo "  ok   the odd instrument and its prices delete without extra confirmation" \
  || { echo "  FAIL instrument delete gave $CODE"; FAIL=1; }
scoped_get "/portfolios?scope=$GA" "$WORK/tx-apos-reset.html" >/dev/null

echo "portfolio rename and clash"
# Last section on purpose: portfolioCreate switches the active portfolio in the session.
post "/portfolios" "$T" -d "name=暫名二" -d "description=原始描述" >/dev/null
P2=$(q "SELECT id FROM portfolios WHERE name='暫名二'")
[ -n "$P2" ] && echo "  ok   second portfolio created (id=$P2)" || { echo "  FAIL second portfolio missing"; exit 1; }
post "/portfolios/$P2/update" "$T" -d "name=改名後" -d "description=改過描述" >/dev/null
NM=$(q "SELECT name FROM portfolios WHERE id=$P2")
[ "$NM" = "改名後" ] && echo "  ok   portfolio renamed" || { echo "  FAIL name is '$NM'"; FAIL=1; }
DESC=$(q "SELECT description FROM portfolios WHERE id=$P2")
[ "$DESC" = "改過描述" ] && echo "  ok   description updated" || { echo "  FAIL description is '$DESC'"; FAIL=1; }
CODE=$(curl -s -o "$WORK/clash.html" -w "%{http_code}" -X POST "$BASE/portfolios/$P2/update" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "name=測試組合" -d "description=")
[ "$CODE" = "400" ] && echo "  ok   duplicate name rejected (400)" || { echo "  FAIL duplicate rename gave $CODE"; FAIL=1; }
check "the rejection names the conflict" "已經存在" "$WORK/clash.html"
NM=$(q "SELECT name FROM portfolios WHERE id=$P2")
[ "$NM" = "改名後" ] && echo "  ok   rejected rename left the row alone" || { echo "  FAIL row was overwritten ($NM)"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/portfolios/$P2/update" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "name=" -d "description=x")
[ "$CODE" = "400" ] && echo "  ok   empty name rejected" || { echo "  FAIL empty name gave $CODE"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/portfolios/99999/update" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "name=不存在" -d "description=")
[ "$CODE" = "400" ] && echo "  ok   unknown portfolio id rejected" || { echo "  FAIL unknown id gave $CODE"; FAIL=1; }

echo
echo "回報：期間與年度表現"
# A portfolio of its own with two hand-written bars, so every percentage below is checkable by hand:
# 10 shares bought at 100 on 2025-01-02 against a 2,000 deposit, and the price is 150 on 2025-06-02.
# 持股 therefore gains 50% (1,000 -> 1,500), 現金 stays at 0% (1,000 -> 1,000), while 持股＋現金 gains
# 25% (2,000 -> 2,500): the idle 1,000 of cash is in the total column's denominator and earns nothing.
T=$(csrf "/reports")
post "/portfolios" "$T" -d "name=回報測試" -d "description=" >/dev/null
RPID=$(q "SELECT id FROM portfolios WHERE name='回報測試'")
[ -n "$RPID" ] && echo "  ok   report portfolio created (id=$RPID)" || { echo "  FAIL report portfolio missing"; exit 1; }
post "/instruments" "$T" -d "symbol=RPT" -d "market=US" -d "currency=USD" -d "asset_class=EQUITY" -d "name=報酬測試" -d "notes=" >/dev/null
RID=$(q "SELECT id FROM instruments WHERE symbol='RPT'")
[ -n "$RID" ] && echo "  ok   report instrument created (id=$RID)" || { echo "  FAIL report instrument missing"; FAIL=1; }
post "/cash" "$T" -d "portfolio_id=$RPID" -d "direction=IN" -d "amount=2000" -d "currency=usd" \
  -d "moved_on=2025-01-02" -d "note=入金" >/dev/null
post "/transactions" "$T" -d "portfolio_id=$RPID" -d "side=BUY" -d "traded_on=2025-01-02" \
  -d "quantity=10" -d "price=100" -d "fee=0" -d "instrument_id=$RID" >/dev/null
execq "$DB" "INSERT INTO prices (instrument_id, as_of, close, currency, source) VALUES ($RID,'2025-01-02',100,'USD','yahoo'),($RID,'2025-06-02',150,'USD','yahoo')"
NB=$(q "SELECT COUNT(*) FROM prices WHERE instrument_id=$RID")
[ "$NB" = "2" ] && echo "  ok   two hand-written bars seeded" || { echo "  FAIL seeded $NB bars, expected 2"; FAIL=1; }

C=$(scoped_get "/reports?scope=$RPID" "$WORK/reports.html")
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/reports.html")
[ "$C" = "200" ] && [ "$E" = "0" ] && echo "  ok   GET /reports 200, no PHP notices" || { echo "  FAIL code=$C notices=$E"; FAIL=1; }
check "the page is titled 回報" "<h2>回報</h2>" "$WORK/reports.html"
# The menu is the request made visible: 回報 sits between 總覽 and 交易紀錄, and 持股損益 is gone.
NAVORDER=$(sed -n '/<nav class="main">/,/<\/nav>/p' "$WORK/reports.html" | tr -d '\n' | sed 's/<[^>]*>//g' | tr -d '[:space:]')
[ "$NAVORDER" = "總覽回報交易紀錄投資物組合設定交易策略樂活五線譜實驗室" ] && echo "  ok   menu reads 總覽、回報、交易紀錄 with no 持股損益" || { echo "  FAIL menu reads $NAVORDER"; FAIL=1; }
check "交易策略是可點擊展開的下拉選單" '<details class="nav-dropdown">' "$WORK/reports.html"
STRATEGY_MENU=$(sed -n '/<details class="nav-dropdown">/,/<\/details>/p' "$WORK/reports.html" | tr -d '\n')
[[ "$STRATEGY_MENU" == *"<summary>交易策略</summary>"* && "$STRATEGY_MENU" == *">樂活五線譜</a>"* ]] \
  && echo "  ok   樂活五線譜放在交易策略下拉選單內" \
  || { echo "  FAIL 交易策略選單內容不正確"; FAIL=1; }
for P in "1 天" "7 天" "1 個月" "3 個月" "6 個月" "9 個月" "12 個月" "本年至今" "24 個月" "60 個月" "120 個月"; do
  check "window $P has its own row" "<strong>$P</strong>" "$WORK/reports.html"
done
check "持股 gains 50.00%" ">+50\.00%<" "$WORK/reports.html"
check "持股＋現金 gains only 25.00%" ">+25\.00%<" "$WORK/reports.html"
check "a window longer than the history says so" "歷史還不夠長" "$WORK/reports.html"
check "the yearly table is there" ">每年度表現<" "$WORK/reports.html"
YEAR_HEADER=$(sed -n '/<h3>每年度表現<\/h3>/,/<\/thead>/p' "$WORK/reports.html" | sed 's/<[^>]*>/ /g' | tr -s '[:space:]' ' ')
[[ "$YEAR_HEADER" == *"年度 起點 期末 持股 現金 持股＋現金"* ]] \
  && echo "  ok   yearly columns are 持股、現金、持股＋現金" \
  || { echo "  FAIL yearly column order: $YEAR_HEADER"; FAIL=1; }
YEAR_ROW=$(sed -n '/<strong>2025<\/strong>/,/<\/tr>/p' "$WORK/reports.html" | sed 's/<[^>]*>//g' | tr -s '[:space:]' ' ')
[[ "$YEAR_ROW" == *"2025 2025-01-02 2025-06-02 +50.00% 0.00% +25.00%"* ]] \
  && echo "  ok   yearly row shows holdings, idle cash, then total" \
  || { echo "  FAIL yearly row values: $YEAR_ROW"; FAIL=1; }
check "2025 gets a yearly row" ">2025<" "$WORK/reports.html"
check "the running year is marked" "年度尚未結束" "$WORK/reports.html"
check "the method is explained" ">怎麼算<" "$WORK/reports.html"
check "cash deposits and withdrawals count as return" "存入視為正報酬、提取視為負報酬" "$WORK/reports.html"
check "total includes principal changes" "存入／提取全額計入" "$WORK/reports.html"
check "total only needs complete endpoints" "只需期初與期末可計價" "$WORK/reports.html"
check "constant-rate FX is disclosed" "固定匯率" "$WORK/reports.html"
check "更新報價 stays on the report page" 'name="back" value="/reports"' "$WORK/reports.html"

# The whitelist has to carry the new section, or a write from 回報 bounces back to 總覽.
CODE=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$JAR" -b "$JAR" \
  -X POST "$BASE/instruments/$RID/price" -d "_csrf=$T" -d "manual_price=" -d "back=/reports")
[ "$CODE" = "303 ${BASE}/reports" ] && echo "  ok   back=/reports is honoured" || { echo "  FAIL back gave $CODE"; FAIL=1; }

# A trade in the middle of a window has to be removed from the gain in the same currency the holdings
# are valued in. Pinning TWD makes the wrong answer a fixed number, so this fails loudly rather than
# drifting: the mid-window buy is 10 @ 120 of a USD name carried at 30 TWD.
echo
echo "回報：跨幣別的中段買進以記帳幣別計流"
T=$(csrf "/reports")
post "/portfolios" "$T" -d "name=跨幣別報酬測試" -d "description=" >/dev/null
FPID=$(q "SELECT id FROM portfolios WHERE name='跨幣別報酬測試'")
[ -n "$FPID" ] && echo "  ok   fx portfolio created (id=$FPID)" || { echo "  FAIL fx portfolio missing"; FAIL=1; }
post "/fx" "$T" -d "currency=TWD" -d "units_per_usd=30" -d "as_of=2025-01-01" >/dev/null
RATE=$(q "SELECT units_per_usd FROM fx_rates WHERE currency='TWD'")
[ "${RATE%.*}" = "30" ] && echo "  ok   TWD pinned at 30 per USD" || { echo "  FAIL TWD rate is $RATE"; FAIL=1; }
execq "$DB" "INSERT INTO prices (instrument_id, as_of, close, currency, source) VALUES ($RID,'2025-03-02',120,'USD','yahoo')"
post "/transactions" "$T" -d "portfolio_id=$FPID" -d "side=BUY" -d "traded_on=2025-01-02" \
  -d "quantity=10" -d "price=100" -d "fee=0" -d "instrument_id=$RID" >/dev/null
post "/transactions" "$T" -d "portfolio_id=$FPID" -d "side=BUY" -d "traded_on=2025-03-02" \
  -d "quantity=10" -d "price=120" -d "fee=0" -d "instrument_id=$RID" >/dev/null

C=$(scoped_get "/reports?scope=$FPID" "$WORK/reports-fx.html")
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/reports-fx.html")
[ "$C" = "200" ] && [ "$E" = "0" ] && echo "  ok   the cross-currency report renders clean" || { echo "  FAIL code=$C notices=$E"; FAIL=1; }
# 1,000 -> 2,400 -> 3,000 USD of holdings, of which 1,200 was paid in: (2400-1000-1200)/1000 then
# (3000-2400)/2400 chains to exactly +50%. Valuing the flow in native USD instead gave +195%.
check "the mid-window buy is booked in 記帳幣別" ">+50\.00%<" "$WORK/reports-fx.html"
notcheck "an unconverted flow does not inflate the gain" "+195\.00%" "$WORK/reports-fx.html"
# Hand TWD back to the automatic source: the checks after this one expect a rate Yahoo fetched,
# not the 30 this section needed for its exact numbers.
post "/fx" "$T" -d "currency=TWD" -d "units_per_usd=" -d "as_of=" >/dev/null

# 賣超不是只有表單那一關：買進那筆事後刪掉，留著的賣出就變成賣超（刪除不會再驗證一次）。
# 持股那一邊本来就只平掉實際存在的股數，現金那一邊却照記全部賣出的金額，總值就憑空多出來。
echo
echo "賣超：現金腿要跟着持股一起封頂"
T=$(csrf "/transactions")
post "/portfolios" "$T" -d "name=賣超現金測試" -d "description=" >/dev/null
OPID=$(q "SELECT id FROM portfolios WHERE name='賣超現金測試'")
post "/cash" "$T" -d "portfolio_id=$OPID" -d "direction=IN" -d "amount=10000" -d "currency=TWD" \
  -d "moved_on=2025-01-02" -d "note=存入" >/dev/null
post "/transactions" "$T" -d "portfolio_id=$OPID" -d "instrument_id=$TWID" -d "side=BUY" \
  -d "quantity=60" -d "price=100" -d "fee=0" -d "traded_on=2025-01-02" >/dev/null
# 同一天先記下來的買進当然要能賣：日期相同不算「之後才買」，不該被擋。
post "/transactions" "$T" -d "portfolio_id=$OPID" -d "instrument_id=$TWID" -d "side=SELL" \
  -d "quantity=30" -d "price=200" -d "fee=0" -d "traded_on=2025-01-02" >/dev/null
N=$(q "SELECT COUNT(*) FROM transactions WHERE portfolio_id=$OPID AND side='SELL' AND traded_on='2025-01-02'")
[ "$N" = "1" ] && echo "  ok   a sale dated the same day as its buy is accepted" \
  || { echo "  FAIL the same-day sale was refused"; FAIL=1; }
post "/transactions" "$T" -d "portfolio_id=$OPID" -d "instrument_id=$TWID" -d "side=SELL" \
  -d "quantity=30" -d "price=200" -d "fee=0" -d "traded_on=2025-03-02" >/dev/null
OBUY=$(q "SELECT id FROM transactions WHERE portfolio_id=$OPID AND side='BUY'")
post "/transactions/$OBUY/delete" "$T" >/dev/null
N=$(q "SELECT COUNT(*) FROM transactions WHERE portfolio_id=$OPID")
[ "$N" = "2" ] && echo "  ok   the buy is gone and both sells are left with nothing to close" \
  || { echo "  FAIL expected 2 trades left, got '$N'"; FAIL=1; }

scoped_get "/?scope=$OPID" "$WORK/oversell-dash.html" >/dev/null
sed -n '/現金與各幣別/,/<\/table>/p' "$WORK/oversell-dash.html" > "$WORK/oversell-cash.html"
# 存入的 10,000 是這個組合裡唯一真的動過的錢。
check "the deposit is still on the books" "10,000\.00" "$WORK/oversell-cash.html"
notcheck "and the balance is not padded with what was never sold" "22,000" "$WORK/oversell-cash.html"

# 錢少了 12,000 卻查不到原因是最難溝通的事，所以這一列得留在持股表上，帶著它的賣超標記。
check "the oversold row stays on the holdings table" "賣超 2 次" "$WORK/oversell-dash.html"

# 交易紀錄頁把四個欄分开列，「賣出收入」這一格該是 0：平不掉任何持股的賣出不產生現金。
# 交易列表裡那筆賣出的成交金額仍然照樣顯示 12,000——它落在下面那段截取的範圍之外。
scoped_get "/transactions?scope=$OPID" "$WORK/oversell-txs.html" >/dev/null
sed -n '/存入合計/,/<\/table>/p' "$WORK/oversell-txs.html" > "$WORK/oversell-flow.html"
check "the deposit is still the whole balance" "10,000\.00" "$WORK/oversell-flow.html"
notcheck "a sale that closed nothing reports no 賣出收入" "12,000" "$WORK/oversell-flow.html"
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/oversell-txs.html")
[ "$E" = "0" ] && echo "  ok   the oversold cash tables render clean" || { echo "  FAIL oversold cash tables have notices"; FAIL=1; }

# 連同那筆賣出一起收走，後面的檢查才不會多看到一個組合。
post "/portfolios/$OPID/delete" "$T" -d "confirm_name=賣超現金測試" >/dev/null
N=$(q "SELECT COUNT(*) FROM portfolios WHERE id=$OPID")
[ "$N" = "0" ] && echo "  ok   the test portfolio and its oversold trade are gone" \
  || { echo "  FAIL test portfolio survived"; FAIL=1; }

scoped_get "/?scope=$RPID" "$WORK/cash-share.html" >/dev/null
# 佔比只留在市值那一張卡上；現金卡就是現金本身，不再出現第二個百分比。
check "組合市值顯示佔整個投資組合的佔比" "佔整個投資組合 60\.0%" "$WORK/cash-share.html"
notcheck "現金卡不再顯示佔比" "佔整個投資組合 40\.0%" "$WORK/cash-share.html"
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/cash-share.html")
[ "$E" = "0" ] && echo "  ok   the overview with a cash share renders clean" || { echo "  FAIL dashboard has notices"; FAIL=1; }

# 回報需要深於六個月的歷史；確定性假來源用一根十二年前的日線證明深抓資料有進資料庫。
HB=$(q "SELECT COUNT(*) FROM prices WHERE instrument_id=$AID AND as_of < '$TWOYEARS'")
OLDEST=$(q "SELECT MIN(as_of) FROM prices WHERE instrument_id=$AID")
if [ "${HB:-0}" = "1" ] && [ "$OLDEST" = "$DEEP12" ]; then
  echo "  ok   深歷史來源存入一根 $OLDEST 的日線"
else
  echo "  FAIL AAPL 深歷史應只有一根且最早為 $DEEP12，實際 ${HB:-0} 根 / $OLDEST"; FAIL=1
fi

C=$(scoped_get "/reports?scope=all" "$WORK/reports-all.html")
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/reports-all.html")
[ "$C" = "200" ] && [ "$E" = "0" ] && echo "  ok   回報 also merges 所有組合" || { echo "  FAIL code=$C notices=$E"; FAIL=1; }
check "the merged scope is labelled" "所有組合合計" "$WORK/reports-all.html"

echo
echo "持股明細 lives on 總覽 (more than ten rows still list)"
# The deleted 持股頁 used to be the only full list, and 總覽 capped itself at ten. These twelve rows go
# straight into the scratch database, so the session's current portfolio is never touched.
execq "$DB" "INSERT INTO portfolios (name, description) VALUES ('十二檔測試','超過十筆')"
MPID=$(q "SELECT id FROM portfolios WHERE name='十二檔測試'")
for N in 01 02 03 04 05 06 07 08 09 10 11 12; do
  execq "$DB" "INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency) VALUES ('TEST$N','測試$N','US','TEST$N','USD');
    INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price)
    VALUES ($MPID, (SELECT id FROM instruments WHERE yahoo_symbol='TEST$N'), 'BUY', '2025-01-05', 10, 20);"
done
C=$(scoped_get "/?scope=$MPID" "$WORK/many.html")
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/many.html")
[ "$C" = "200" ] && [ "$E" = "0" ] && echo "  ok   總覽 with twelve positions renders clean" || { echo "  FAIL code=$C notices=$E"; FAIL=1; }
ROWS=$(sed -n '/持股明細（含已出清）/,/<\/table>/p' "$WORK/many.html" | grep -c '<tr class=')
[ "$ROWS" = "12" ] && echo "  ok   all twelve rows list, no top-ten cap left" || { echo "  FAIL 持股明細 has $ROWS rows"; FAIL=1; }
notcheck "nothing links to the deleted 持股頁" '/holdings' "$WORK/many.html"
scoped_get "/?scope=$PID" "$WORK/restored.html" >/dev/null

echo
echo "缺報價：市值不確定的持股不能當成 0 進去合計"
# 兩把都是 TWD，所以這裡缺的只有報價而不是匯率：一把有手動價格、一把什麼價格都沒有。
# 有價格的那一把順便賣掉一半，已實現損益就變成一個「就算缺價也照樣算得出來」的對照組。
execq "$DB" "INSERT INTO portfolios (name, description) VALUES ('缺報價測試','市值不全');
  INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency)
    VALUES ('NOCLOSE','沒有報價','TW','NOCLOSE.TW','TWD'),
           ('HASQUOTE','有手動價格','TW','HASQUOTE.TW','TWD');
  INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price) VALUES
    ((SELECT id FROM portfolios WHERE name='缺報價測試'), (SELECT id FROM instruments WHERE yahoo_symbol='NOCLOSE.TW'), 'BUY',  '2025-01-05', 10, 100),
    ((SELECT id FROM portfolios WHERE name='缺報價測試'), (SELECT id FROM instruments WHERE yahoo_symbol='HASQUOTE.TW'), 'BUY',  '2025-01-05', 10, 200),
    ((SELECT id FROM portfolios WHERE name='缺報價測試'), (SELECT id FROM instruments WHERE yahoo_symbol='HASQUOTE.TW'), 'SELL', '2025-02-05',  5, 250);
  UPDATE instruments SET manual_price = 300, manual_price_at = '2025-06-01 00:00:00' WHERE yahoo_symbol='HASQUOTE.TW';"
UPID=$(q "SELECT id FROM portfolios WHERE name='缺報價測試'")
T=$(csrf "/transactions")

scoped_get "/?scope=$UPID" "$WORK/noprice-dash.html" >/dev/null
sed -n '/<div class="cards">/,/現金與各幣別/p' "$WORK/noprice-dash.html" > "$WORK/noprice-cards.html"
sed -n '/現金與各幣別/,/<\/table>/p' "$WORK/noprice-dash.html" > "$WORK/noprice-bk.html"
# 有報價的那一把值 5 股 × 300 = 1,500，未實現 +500、已實現 +250。把 1,500 當成整個組合的市值，
# 就是把那把沒報價的持股當成不值錢——三個數都要改成 --，等報價到位再報。
check "缺價的持股被點名" "1 個缺價" "$WORK/noprice-cards.html"
notcheck "組合市值不再是只有有價那把的 1,500" 'NT\$1,500\.00' "$WORK/noprice-cards.html"
notcheck "未實現損益不冒充 +500" 'NT\$500\.00' "$WORK/noprice-cards.html"
notcheck "合計損益不冒充 +750" 'NT\$750\.00' "$WORK/noprice-cards.html"
# 已實現完全來自交易紀錄，缺價不該連它也一起抹掉。
check "已實現損益照樣報得出 250" 'NT\$250\.00' "$WORK/noprice-cards.html"
check "該幣別的原幣小計改標缺報價" "缺報價" "$WORK/noprice-bk.html"
notcheck "原幣小計也不是只加有價的那一把" 'NT\$1,500\.00' "$WORK/noprice-bk.html"

# 同一把沒報價的持股在持股表那一列：合計損益該是 --，不是 0.00。
# 這一列本來就有一個合法的 0.00（已實現損益，因為它只買過沒賣過），所以數的是個數而不是有無。
awk '/NOCLOSE/{f=1} f{print} f&&/<\/tr>/{exit}' "$WORK/noprice-dash.html" > "$WORK/noprice-row.html"
ZEROS=$(grep -o -- 'NT\$0\.00' "$WORK/noprice-row.html" | wc -l)
[ "${ZEROS:-0}" = "1" ] && echo "  ok   沒有報價那一列只剩已實現那個 0.00" \
  || { echo "  FAIL 該列出現 ${ZEROS:-0} 個 0.00，合計損益被當成 0"; FAIL=1; }
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/noprice-dash.html")
[ "$E" = "0" ] && echo "  ok   缺價的總覽渲染無 PHP 訊息" || { echo "  FAIL 缺價總覽有 PHP 訊息"; FAIL=1; }

post "/portfolios/$UPID/delete" "$T" -d "confirm_name=缺報價測試" >/dev/null
N=$(q "SELECT COUNT(*) FROM portfolios WHERE id=$UPID")
[ "$N" = "0" ] && echo "  ok   測試組合連同它的三筆交易一併移除" \
  || { echo "  FAIL 測試組合還在"; FAIL=1; }

echo
echo "缺匯率：換算不掉的金額不能當成 0"
# 記帳幣別是 TWD。HKD 有手動匯率（7.8 兌一美元），XYZ 則任何匯率來源都沒有，所以 XYZ 的金額換不出去。
# 三把各有所司：HKDPART 是「換得掉」的對照組，XXTPART 帶著換不掉的持股成本，XYZCLOSED 帶著換不掉的已實現。
T=$(csrf "/transactions")
post "/fx" "$T" -d "currency=TWD" -d "units_per_usd=30" -d "as_of=2025-06-01" >/dev/null
post "/fx" "$T" -d "currency=HKD" -d "units_per_usd=7.8" -d "as_of=2025-06-01" >/dev/null
execq "$DB" "DELETE FROM fx_rates WHERE currency='XYZ';
  INSERT INTO portfolios (name, description) VALUES ('缺匯率測試','三把兩幣別');
  INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency)
    VALUES ('HKDPART','港幣部分出清','HK','HKDPART','HKD'),
           ('XXTPART','無匯率部分出清','US','XXTPART','XYZ'),
           ('XYZCLOSED','無匯率已出清','US','XYZCLOSED','XYZ');
  INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price) VALUES
    ((SELECT id FROM portfolios WHERE name='缺匯率測試'), (SELECT id FROM instruments WHERE symbol='HKDPART'),   'BUY',  '2025-01-06', 20,  78),
    ((SELECT id FROM portfolios WHERE name='缺匯率測試'), (SELECT id FROM instruments WHERE symbol='HKDPART'),   'SELL', '2025-02-06', 10,  88),
    ((SELECT id FROM portfolios WHERE name='缺匯率測試'), (SELECT id FROM instruments WHERE symbol='XXTPART'),   'BUY',  '2025-01-06', 20, 500),
    ((SELECT id FROM portfolios WHERE name='缺匯率測試'), (SELECT id FROM instruments WHERE symbol='XXTPART'),   'SELL', '2025-02-06', 10, 600),
    ((SELECT id FROM portfolios WHERE name='缺匯率測試'), (SELECT id FROM instruments WHERE symbol='XYZCLOSED'), 'BUY',  '2025-01-06', 10, 300),
    ((SELECT id FROM portfolios WHERE name='缺匯率測試'), (SELECT id FROM instruments WHERE symbol='XYZCLOSED'), 'SELL', '2025-02-06', 10, 400);
  UPDATE instruments SET manual_price = 100, manual_price_at = '2025-06-01 00:00:00' WHERE symbol='HKDPART';
  UPDATE instruments SET manual_price = 600, manual_price_at = '2025-06-01 00:00:00' WHERE symbol='XXTPART';"
XPID=$(q "SELECT id FROM portfolios WHERE name='缺匯率測試'")

scoped_get "/?scope=$XPID" "$WORK/nofx-dash.html" >/dev/null
sed -n '/<div class="cards">/,/現金與各幣別/p' "$WORK/nofx-dash.html" > "$WORK/nofx-cards.html"
sed -n '/持股明細/,/<\/tfoot>/p' "$WORK/nofx-dash.html" > "$WORK/nofx-table.html"
awk '/HKDPART/{f=1} f{print} f&&/<\/tr>/{exit}' "$WORK/nofx-dash.html" > "$WORK/nofx-hkrow.html"
awk '/XYZCLOSED/{f=1} f{print} f&&/<\/tr>/{exit}' "$WORK/nofx-dash.html" > "$WORK/nofx-crow.html"
# 換得掉的只有港幣那 780 HKD = 3,000 的成本、100 HKD = 384.62 的已實現；XYZ 那兩筆（5,000 成本、
# 1,000 已實現）根本換不出去，舊行為是把它們當 0 加進去，卡上的數看起來像全部，其實只有港幣那一邊。
check "缺匯率的幣別被點名" "缺匯率：XYZ" "$WORK/nofx-cards.html"
notcheck "投資成本不謊報只有港幣那半的 3,000" 'NT\$3,000\.00' "$WORK/nofx-cards.html"
notcheck "已實現損益不謊報只有港幣那半的 384.62" 'NT\$384\.62' "$WORK/nofx-cards.html"
# 匯率問題不該被報成報價問題：兩個理由的修法完全不同，提示給錯方向會让人白跑。
notcheck "缺匯率不被誤報成缺價" "個缺價" "$WORK/nofx-cards.html"
# 換得掉的那一列不受牽連：未實現 220 HKD ＋ 已實現 100 HKD = 1,230.77。
check "港幣那一列照樣報得出 1,230.77" 'NT\$1,230\.77' "$WORK/nofx-hkrow.html"
# 但它那一格的佔比不能再填 100.0%：分母漏了 XYZ 那一整把，每一列的比例都會被抬高。
notcheck "換得掉那把不再被當成整個組合" "100\.0%" "$WORK/nofx-hkrow.html"
# 全數出清的那把不需要報價，但它的已實現換不了幣：該留白，而不是寫成 0。
notcheck "換不掉的已實現不寫成 0" 'NT\$0\.00' "$WORK/nofx-crow.html"
# 佔比那一欄留白時，合計列就不能再單獨宣稱 100%。
notcheck "合計佔比不謊稱 100%" ">100%<" "$WORK/nofx-table.html"
E=$(grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined" "$WORK/nofx-dash.html")
[ "$E" = "0" ] && echo "  ok   缺匯率的總覽渲染無 PHP 訊息" || { echo "  FAIL 缺匯率總覽有 PHP 訊息"; FAIL=1; }

post "/portfolios/$XPID/delete" "$T" -d "confirm_name=缺匯率測試" >/dev/null
N=$(q "SELECT COUNT(*) FROM portfolios WHERE id=$XPID")
[ "$N" = "0" ] && echo "  ok   測試組合連同它的六筆交易一併移除" \
  || { echo "  FAIL 測試組合還在"; FAIL=1; }
# 匯率交回自動來源，後面的段落才不會讀到我這兩筆手動值。
post "/fx" "$T" -d "currency=TWD" -d "units_per_usd=" -d "as_of=" >/dev/null
post "/fx" "$T" -d "currency=HKD" -d "units_per_usd=" -d "as_of=" >/dev/null

echo
echo "backup and restore (備份與還原)"
# This section is last on purpose: a restore replaces every table, so nothing else may depend on the
# data afterwards. Everything is read from the scratch database, and $DB is a throwaway file.
BK="$(dirname "$DB")/backups"
BK_BEFORE="$WORK/backup-names-before.txt"
backup_names() { [ -d "$BK" ] && find "$BK" -maxdepth 1 -type f -printf '%f\n' | sort; }
new_backups() { comm -13 "$BK_BEFORE" <(backup_names); }
backup_names > "$BK_BEFORE"
T=$(csrf "/settings")
LIVE=$(q "SELECT COUNT(*) FROM transactions")

post "/backup/create" "$T" >/dev/null
BN=$(new_backups | grep '^manual-' | head -1)
[ -n "$BN" ] && echo "  ok   snapshot written into data/backups ($BN)" || { echo "  FAIL no snapshot file in $BK"; FAIL=1; }
case "$BN" in
  manual-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9].sqlite)
    echo "  ok   the file name carries the moment it was taken" ;;
  *) echo "  FAIL odd snapshot name ($BN)"; FAIL=1 ;;
esac

get "/settings" "$WORK/bk-page.html" >/dev/null
check "the snapshot is listed on the settings page" "$BN" "$WORK/bk-page.html"
check "listed as a 手動備份" "手動備份" "$WORK/bk-page.html"
check "one button makes a backup" ">立即備份<" "$WORK/bk-page.html"
check "the restore form uploads a file" 'enctype="multipart/form-data"' "$WORK/bk-page.html"
check "and demands a tick before overwriting" "我了解這會覆蓋現在的全部資料" "$WORK/bk-page.html"
check "the effective upload cap is shown" "上傳大小約" "$WORK/bk-page.html"
notcheck "an interrupted upload is never offered back" "incoming-" "$WORK/bk-page.html"

CODE=$(curl -s -b "$JAR" -c "$JAR" -D "$WORK/bk-head.txt" -o "$WORK/bk-got.sqlite" -w "%{http_code}" "$BASE/backup/$BN/download")
[ "$CODE" = "200" ] && echo "  ok   snapshot downloads (200)" || { echo "  FAIL download gave $CODE"; FAIL=1; }
check "as a file rather than a page" "attachment; filename=\"$BN\"" "$WORK/bk-head.txt"
MAGIC=$(head -c 15 "$WORK/bk-got.sqlite")
[ "$MAGIC" = "SQLite format 3" ] && echo "  ok   the download is a real SQLite database" || { echo "  FAIL header reads '$MAGIC'"; FAIL=1; }
DLTX=$(qf "$WORK/bk-got.sqlite" "SELECT COUNT(*) FROM transactions")
[ "$DLTX" = "$LIVE" ] && echo "  ok   the snapshot holds every trade ($DLTX)" || { echo "  FAIL snapshot has $DLTX trades, live has $LIVE"; FAIL=1; }

# Wreck one trade, then restore the downloaded file: the count must come back by itself.
TX=$(q "SELECT id FROM transactions ORDER BY id DESC LIMIT 1")
post "/transactions/$TX/delete" "$T" >/dev/null
GONE=$(q "SELECT COUNT(*) FROM transactions")
[ "$GONE" = "$((LIVE - 1))" ] && echo "  ok   trade deleted for the test ($GONE)" \
  || { echo "  FAIL deleted count is $GONE, expected $((LIVE - 1))"; FAIL=1; }
CODE=$(postfile "/backup/restore" "$T" -F "confirm=1" -F "backup=@$WORK/bk-got.sqlite;type=application/octet-stream")
[ "$CODE" = "303" ] && echo "  ok   restore accepted the upload (303)" || { echo "  FAIL restore gave $CODE"; cat "$LAST"; FAIL=1; }
BACK=$(q "SELECT COUNT(*) FROM transactions")
[ "$BACK" = "$LIVE" ] && echo "  ok   the deleted trade came back ($BACK)" || { echo "  FAIL trades are $BACK, backup held $LIVE"; FAIL=1; }
get "/settings" "$WORK/bk-done.html" >/dev/null
check "and says what it put back" "已還原" "$WORK/bk-done.html"
PG=$(new_backups | grep '^pre-restore-' | head -1)
[ -n "$PG" ] && echo "  ok   the data it overwrote was snapshotted first ($PG)" || { echo "  FAIL no pre-restore snapshot"; FAIL=1; }
HELD=$(qf "$BK/$PG" "SELECT COUNT(*) FROM transactions")
[ "$HELD" = "$GONE" ] && echo "  ok   that safety copy holds the pre-restore rows ($HELD)" || { echo "  FAIL guard holds $HELD, expected $GONE"; FAIL=1; }
check "the safety copy is marked as one" "還原前快照" "$WORK/bk-done.html"
notcheck "no leftover upload staged in the list" "incoming-" "$WORK/bk-done.html"

# 退回 walks back to the state the guard holds, which is the wrecked one. (Raw curl: post() follows
# the 303 and would report the landing page's 200.)
CODE=$(curl -s -b "$JAR" -c "$JAR" -o "$LAST" -w "%{http_code}" -X POST "$BASE/backup/$PG/restore" -d "_csrf=$T" -d "confirm=1")
[ "$CODE" = "303" ] && echo "  ok   退回 accepted (303)" || { echo "  FAIL undo gave $CODE"; grep -oE '不是 SQLite 資料庫|缺少欄位[^<]*|完整性檢查[^<]*|資料關聯不一致[^<]*|找不到備份檔[^<]*|還原失敗[^<]*' "$LAST"; FAIL=1; }
UNDONE=$(q "SELECT COUNT(*) FROM transactions")
[ "$UNDONE" = "$GONE" ] && echo "  ok   the restore was undone, rows back to $UNDONE" || { echo "  FAIL after undo there are $UNDONE, guard held $GONE"; FAIL=1; }

# A restored database can carry ids higher than this one's AUTOINCREMENT counter, and DELETE does not
# rewind that counter: without syncing it, the next insert would collide with a restored row.
SEQ=$(q "SELECT seq FROM sqlite_sequence WHERE name='portfolios'")
COLLIDE=$((SEQ + 1))
execq "$WORK/bk-got.sqlite" "INSERT INTO portfolios (id, name) VALUES ($COLLIDE, '撞號組合')"
CODE=$(postfile "/backup/restore" "$T" -F "confirm=1" -F "backup=@$WORK/bk-got.sqlite;type=application/octet-stream")
NEWID=$(q "SELECT id FROM portfolios WHERE name='撞號組合'")
[ "$NEWID" = "$COLLIDE" ] && echo "  ok   a restored row kept its own id ($NEWID)" || { echo "  FAIL 撞號組合 id is '$NEWID', expected $COLLIDE"; FAIL=1; }
post "/portfolios" "$T" -d "name=還原後新增" -d "description=" >/dev/null
AFTER=$(q "SELECT id FROM portfolios WHERE name='還原後新增'")
[ -n "$AFTER" ] && [ "$AFTER" -gt "$COLLIDE" ] \
  && echo "  ok   the next new row numbered past it ($AFTER)" \
  || { echo "  FAIL insert after restore gave '$AFTER' (needed > $COLLIDE)"; FAIL=1; }

head -c 262144 /dev/zero > "$WORK/notes.bin"
printf 'not a database at all' > "$WORK/notes.txt"
CODE=$(postfile "/backup/restore" "$T" -F "confirm=1" -F "backup=@$WORK/notes.txt;type=text/plain")
[ "$CODE" = "400" ] && echo "  ok   a text file is refused (400)" || { echo "  FAIL text upload gave $CODE"; FAIL=1; }
check "and named as not a database" "不是 SQLite 資料庫" "$LAST"
CODE=$(postfile "/backup/restore" "$T" -F "backup=@$WORK/bk-got.sqlite;type=application/octet-stream")
[ "$CODE" = "400" ] && echo "  ok   restore without the tick is refused (400)" || { echo "  FAIL unticked restore gave $CODE"; FAIL=1; }
check "the refusal says why" "請先勾選" "$LAST"
BEFORE=$(q "SELECT COUNT(*) FROM transactions")
CODE=$(postfile "/backup/restore" "$T" -F "confirm=1")
[ "$CODE" = "400" ] && echo "  ok   an empty file field is refused (400)" || { echo "  FAIL empty upload gave $CODE"; FAIL=1; }
check "and asks for the file" "請選擇要還原的備份檔" "$LAST"
[ "$(q "SELECT COUNT(*) FROM transactions")" = "$BEFORE" ] \
  && echo "  ok   a refused restore changes nothing ($BEFORE trades)" \
  || { echo "  FAIL a refused restore moved the trades"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$BASE/backup/create" -d "_csrf=deadbeef")
[ "$CODE" = "419" ] && echo "  ok   a forged backup POST is refused (419)" || { echo "  FAIL no-CSRF backup gave $CODE"; FAIL=1; }
CODE=$(curl -s -o "$WORK/bk-name.html" -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE/backup/nope.sqlite/download")
[ "$CODE" = "400" ] && echo "  ok   an unknown backup name is refused (400)" || { echo "  FAIL unknown name gave $CODE"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE/backup/..%2Fportfolio.sqlite/download")
case "$CODE" in
  400|404) echo "  ok   ../ in a backup name is explicitly refused ($CODE)" ;;
  *) echo "  FAIL traversal gave $CODE；只接受 400 或 404"; FAIL=1 ;;
esac
ALIVE=$(q "SELECT COUNT(*) FROM portfolios")
[ "$ALIVE" -gt 0 ] && echo "  ok   the live database is still readable ($ALIVE portfolios)" || { echo "  FAIL the live database broke"; FAIL=1; }

# Two different ways a too-large upload can arrive, and the user must get the real reason either way:
# a multipart body over upload_max_filesize still reaches the app (only the file is flagged), while a
# plain body over post_max_size is thrown away by PHP before anything runs — with its own warning
# printed first, which is why that case is matched on the sentence rather than the status code.
# Sizes come from the ini the server actually uses, so this holds at 2M, at 64M, or anywhere between.
UP=$(ini_bytes upload_max_filesize)
POST=$(ini_bytes post_max_size)
BEFORE=$(q "SELECT COUNT(*) FROM transactions")
if [ "$UP" -gt 0 ]; then
  MULTI=$(( (UP < POST ? UP : POST) + 1048576 ))
  head -c "$MULTI" /dev/zero > "$WORK/toobig.sqlite"
  CODE=$(postfile "/backup/restore" "$T" -F "confirm=1" -F "backup=@$WORK/toobig.sqlite;type=application/octet-stream")
  check "an over-cap upload names a size cap ($MULTI bytes)" "上限" "$LAST"
  notcheck "and never blames the form token" "表單憑證已過期" "$LAST"
else
  skip "upload_max_filesize 沒有正上限，略過 multipart 超限檢查"
fi
if [ "$POST" -gt 0 ]; then
  head -c "$((POST + 1048576))" /dev/zero > "$WORK/toobig2.bin"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/discard.html" -X POST "$BASE/backup/restore" \
    -H "Content-Type: application/octet-stream" --data-binary "@$WORK/toobig2.bin" >/dev/null
  check "a body PHP discarded whole still gets explained" "整份表單被丟掉了" "$WORK/discard.html"
else
  skip "post_max_size 沒有正上限，略過純文字超限表單檢查"
fi
[ "$(q "SELECT COUNT(*) FROM transactions")" = "$BEFORE" ] \
  && echo "  ok   neither of them changed anything ($BEFORE trades)" \
  || { echo "  FAIL an oversized upload touched the data"; FAIL=1; }

# Pruning: nine more backups leave seven newest overall, including snapshots from earlier steps.
for i in 1 2 3 4 5 6 7 8 9; do post "/backup/create" "$T" >/dev/null; done
KEPT=$(backup_names | wc -l | tr -d ' ')
[ "$KEPT" = "7" ] && echo "  ok   本次九份備份後全庫留下剛好七份" \
  || { echo "  FAIL 建立後全庫留下 $KEPT 份，預期七份"; FAIL=1; }
ODD=$(backup_names | grep -vcE '^(manual|pre-restore)-[0-9]{8}-[0-9]{6}(-[0-9]{2})?\.sqlite$')
[ "$ODD" = "0" ] && echo "  ok   本次留下的只有快照檔" || { echo "  FAIL 本次留下 $ODD 個非快照檔"; FAIL=1; }
CODE=$(post "/backup/manual-19700101-000000/delete" "$T")
[ "$CODE" = "400" ] && echo "  ok   deleting an unknown snapshot is refused (400)" || { echo "  FAIL delete gave $CODE"; FAIL=1; }
FIRST=$(backup_names | sort | head -1)
CODE=$(curl -s -b "$JAR" -c "$JAR" -o "$LAST" -w "%{http_code}" -X POST "$BASE/backup/$FIRST/delete" -d "_csrf=$T")
[ "$CODE" = "303" ] && [ ! -e "$BK/$FIRST" ] && echo "  ok   a real snapshot deletes from the list" || { echo "  FAIL delete of $FIRST gave $CODE"; FAIL=1; }
[ "$(q "SELECT COUNT(*) FROM transactions")" = "$BEFORE" ] \
  && echo "  ok   deleting a backup never touches the live data" \
  || { echo "  FAIL deleting a backup changed the trades"; FAIL=1; }

# 快照不留可離線破解的帳號資料；表結構可留，但內容必須清空。
SNAP=$(backup_names | grep '^manual-' | sort | tail -1)
AC=$(qf "$BK/$SNAP" "SELECT COUNT(*) FROM access")
LT=$(qf "$BK/$SNAP" "SELECT COUNT(*) FROM login_throttle")
[ "$AC" = "0" ] && [ "$LT" = "0" ] \
  && echo "  ok   snapshot excludes credential and throttle rows" \
  || { echo "  FAIL snapshot has access=$AC throttle=$LT"; FAIL=1; }

# 還原不帶走報價出口：備檔裡的 quote_endpoint/quote_token 不會覆蓋本機原本的值。
LOCAL_EP=$(q "SELECT value FROM settings WHERE key='quote_endpoint'")
LOCAL_TK=$(q "SELECT value FROM settings WHERE key='quote_token'")
execq "$WORK/bk-got.sqlite" "UPDATE settings SET value='https://evil.example/yahoo' WHERE key='quote_endpoint'"
execq "$WORK/bk-got.sqlite" "UPDATE settings SET value='LEAKED_TOKEN' WHERE key='quote_token'"
CODE=$(postfile "/backup/restore" "$T" -F "confirm=1" -F "backup=@$WORK/bk-got.sqlite;type=application/octet-stream")
[ "$CODE" = "303" ] && echo "  ok   restore with tampered outlet accepted (303)" || { echo "  FAIL restore gave $CODE"; FAIL=1; }
AFTER_EP=$(q "SELECT value FROM settings WHERE key='quote_endpoint'")
AFTER_TK=$(q "SELECT value FROM settings WHERE key='quote_token'")
[ "$AFTER_EP" = "$LOCAL_EP" ] && echo "  ok   restore kept local quote_endpoint ($AFTER_EP)" || { echo "  FAIL endpoint changed to $AFTER_EP"; FAIL=1; }
[ "$AFTER_TK" = "$LOCAL_TK" ] && echo "  ok   restore kept local quote_token" || { echo "  FAIL token leaked"; FAIL=1; }

# CSV 匯出跳脫公式注入：備註填 =HYPERLINK(...) 匯出後前面要加單引號，Excel 不會當公式算。
# 先建一筆測試交易（用第一個組合與第一個投資物）
TEST_PID=$(q "SELECT id FROM portfolios LIMIT 1")
TEST_IID=$(q "SELECT id FROM instruments LIMIT 1")
[ -n "$TEST_PID" ] && [ -n "$TEST_IID" ] && {
  post "/transactions" "$T" -d "portfolio_id=$TEST_PID" -d "instrument_id=$TEST_IID" -d "side=BUY" -d "traded_on=2026-11-23" -d "quantity=1" -d "price=10" -d "fee=0" -d "note==HYPERLINK(\"http://evil\",click)" >/dev/null
  curl -s -c "$JAR" -b "$JAR" "$BASE/export?scope=all" -o "$WORK/exp-fx.csv"
  grep -q "'=HYPERLINK" "$WORK/exp-fx.csv" && echo "  ok   csv escapes formula prefix with apostrophe" || { echo "  FAIL csv did not escape"; FAIL=1; }
  # 清掉那筆測試交易
  TXID=$(q "SELECT id FROM transactions WHERE note LIKE '%HYPERLINK%'")
  [ -n "$TXID" ] && post "/transactions/$TXID/delete" "$T" >/dev/null
} || skip "CSV 公式跳脫檢查（沒有可用的組合或投資物 fixtures）"

# 幣別服务端白名單：instruments.currency 不能塞引號或超長字串。
CODE=$(curl -s -o "$WORK/inst-bad.html" -w "%{http_code}" -X POST "$BASE/instruments" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "symbol=BAD" -d "market=US" -d "currency=US');alert(1);//" -d "name=bad" -d "asset_class=ETF")
[ "$CODE" = "400" ] && echo "  ok   instrument with malicious currency rejected (400)" || { echo "  FAIL malicious currency gave $CODE"; FAIL=1; }
check "and named the problem" "幣別請填" "$WORK/inst-bad.html"
CODE=$(curl -s -o "$WORK/inst-long.html" -w "%{http_code}" -X POST "$BASE/instruments" -b "$JAR" -c "$JAR" -d "_csrf=$T" -d "symbol=LONG" -d "market=US" -d "currency=TOOLONG" -d "name=long" -d "asset_class=ETF")
[ "$CODE" = "400" ] && echo "  ok   instrument with overlong currency rejected (400)" || { echo "  FAIL overlong currency gave $CODE"; FAIL=1; }

echo "表格欄位與命令列參數 (paranoia)"
# A header row with one cell fewer than the body shifts every label after it, and no PHP notice
# would ever say so. Counting the rendered pages is the only way to catch it on all six at once.
cat > "$WORK/parity.php" <<'PHP'
<?php
// Every <th> in a header row has to have a matching <td>, colspan included.
$bad = 0;
foreach (array_slice($argv, 1) as $file) {
    $html = (string) file_get_contents($file);
    preg_match_all('/<table\b.*?<\/table>/s', $html, $tables);
    foreach ($tables[0] as $n => $table) {
        if (preg_match('/<thead>.*?<\/thead>/s', $table, $head) !== 1) {
            continue;                                          // a header-less table is its own style
        }
        $th = preg_match_all('/<th\b/i', $head[0]);
        if (preg_match('/<tbody>.*?<\/tbody>/s', $table, $body) !== 1) {
            continue;                                          // nothing rendered, nothing to compare
        }
        preg_match_all('/<tr\b[^>]*>.*?<\/tr>/is', $body[0], $rows);
        foreach ($rows[0] as $row) {
            $cells = preg_match_all('/<td\b([^>]*)>/i', $row, $m);
            foreach ($m[1] as $attrs) {
                if (preg_match('/colspan="?(\d+)/i', (string) $attrs, $c) === 1) {
                    $cells += (int) $c[1] - 1;
                }
            }
            if ($cells !== $th) {
                echo basename($file) . " table#$n: th=$th td=$cells\n";
                $bad++;
            }
        }
    }
}
exit($bad === 0 ? 0 : 1);
PHP
for P in "" transactions instruments portfolios reports settings lab; do
  get "/$P" "$WORK/parity-${P:-dashboard}.html" >/dev/null
done
scoped_get "/transactions?scope=all" "$WORK/parity-merged-tx.html" >/dev/null
if "$PHPBIN" "$WORK/parity.php" "$WORK"/parity-*.html > "$WORK/parity.txt" 2>&1; then
  echo "  ok   every column on the six pages has a header, 抓價 and 刪除 included"
else
  echo "  FAIL column/header mismatch:"; sed 's/^/       /' "$WORK/parity.txt"; FAIL=1
fi
E=$(cat "$WORK"/parity-*.html | grep -c -i -E "Warning:|Fatal error|Deprecated:|Undefined")
[ "$E" = "0" ] && echo "  ok   the pages counted above render without a PHP notice" || { echo "  FAIL $E notice(s)"; FAIL=1; }

# A mistyped option used to be skipped, and the script carried on against data/portfolio.sqlite —
# the one file here that holds real holdings. Every bin/ script now refuses anything it does not know.
CLI="$WORK/cli1"; CLI2="$WORK/cli2"; CLI3="$WORK/cli3"; CLI4="$WORK/cli4"
rm -rf "$CLI" "$CLI2" "$CLI3" "$CLI4"; mkdir -p "$CLI" "$CLI2" "$CLI3" "$CLI4"
"$PHPBIN" bin/seed-demo.php --data-dir="$CLI" --zzz=1 >/dev/null 2>"$WORK/seed-bad.txt"
[ "$?" = "2" ] && check "an unknown seed-demo option stops instead of carrying on" "看不懂這個參數" "$WORK/seed-bad.txt" \
  || { echo "  FAIL unknown seed-demo option gave $?"; FAIL=1; }
[ ! -e "$CLI/portfolio.sqlite" ] \
  && echo "  ok   and it stops before the database is even opened" || { echo "  FAIL the database was touched"; FAIL=1; }

# Offline, so the demo seed costs no downloads: the point here is the guard, not the quotes.
QUOTE_ENABLED=false "$PHPBIN" bin/seed-demo.php --data-dir="$CLI" >"$WORK/seed-ok.txt" 2>&1
[ "$?" = "0" ] && [ "$(qf "$CLI/portfolio.sqlite" "SELECT COUNT(*) FROM portfolios")" = "2" ] \
  && echo "  ok   --data-dir really moves the database the script writes" \
  || { echo "  FAIL seeding into $CLI gave $?"; FAIL=1; }
QUOTE_ENABLED=false "$PHPBIN" bin/seed-demo.php --data-dir="$CLI" >/dev/null 2>"$WORK/seed-twice.txt"
[ "$?" = "1" ] && check "a second run on a full database is refused" "已經有資料" "$WORK/seed-twice.txt" \
  || { echo "  FAIL the second seed gave $?"; FAIL=1; }
check "and it names the file it is protecting" "portfolio.sqlite" "$WORK/seed-twice.txt"

# The old guard only counted transactions, so a database holding a portfolio and cash but no trade
# yet (the state right after 「建立第一個組合」) would have been seeded straight over.
QUOTE_ENABLED=false "$PHPBIN" bin/refresh-prices.php --data-dir="$CLI2" >/dev/null 2>&1
execq "$CLI2/portfolio.sqlite" "INSERT INTO portfolios (name, description) VALUES ('我的組合', '剛建好，還沒買')"
execq "$CLI2/portfolio.sqlite" "INSERT INTO cash_movements (portfolio_id, moved_on, direction, amount, currency, note)
                                 VALUES (1, '2025-01-02', 'IN', 100000, 'TWD', '存入')"
[ "$(qf "$CLI2/portfolio.sqlite" "SELECT COUNT(*) FROM transactions")" = "0" ] \
  && echo "  ok   a database with a portfolio and cash but no trade yet is the case under test" \
  || { echo "  FAIL could not build the case"; FAIL=1; }
QUOTE_ENABLED=false "$PHPBIN" bin/seed-demo.php --data-dir="$CLI2" >/dev/null 2>"$WORK/seed-partial.txt"
[ "$?" = "1" ] && check "and it is refused too, on the portfolio count alone" "投資組合 1 筆" "$WORK/seed-partial.txt" \
  || { echo "  FAIL the portfolios-only database gave $? and said: $(cat "$WORK/seed-partial.txt")"; FAIL=1; }
[ "$(qf "$CLI2/portfolio.sqlite" "SELECT COUNT(*) FROM portfolios WHERE name LIKE 'Demo%'")" = "0" ] \
  && echo "  ok   a refused seed writes nothing, not even a Demo portfolio" \
  || { echo "  FAIL the refused seed inserted $(qf "$CLI2/portfolio.sqlite" "SELECT COUNT(*) FROM portfolios WHERE name LIKE 'Demo%'") demo rows"; FAIL=1; }
QUOTE_ENABLED=false "$PHPBIN" bin/seed-demo.php --data-dir="$CLI2" --force >/dev/null 2>&1
[ "$(qf "$CLI2/portfolio.sqlite" "SELECT COUNT(*) FROM portfolios WHERE name LIKE 'Demo%'")" = "2" ] \
  && echo "  ok   --force is still the way in when you mean it" || { echo "  FAIL --force did not seed"; FAIL=1; }

# bin/refresh-prices.php: the same parser, and exit codes a scheduler can actually trust.
"$PHPBIN" bin/refresh-prices.php --data-dir="$CLI3" --portfolio 2 >/dev/null 2>"$WORK/rp-args.txt"
[ "$?" = "2" ] && check "a --portfolio written without = is refused, not read as 2" "--portfolio" "$WORK/rp-args.txt" \
  || { echo "  FAIL gave $?"; FAIL=1; }
"$PHPBIN" bin/refresh-prices.php --data-dir="$CLI3" --portfolio=2,3 >/dev/null 2>&1
[ "$?" = "2" ] && echo "  ok   and a non-numeric id is refused too" || { echo "  FAIL gave $?"; FAIL=1; }
"$PHPBIN" bin/refresh-prices.php --data-dir="$CLI" --portfolio=99999 >/dev/null 2>"$WORK/rp-ghost.txt"
[ "$?" = "2" ] && check "a portfolio that does not exist stops the run with 2" "找不到編號" "$WORK/rp-ghost.txt" \
  || { echo "  FAIL a bogus id gave $?"; FAIL=1; }
# Previously this reported success (ok=0 failed=0 -> 0) while fetching nothing at all.
QUOTE_ENABLED=false "$PHPBIN" bin/refresh-prices.php --data-dir="$CLI3" >"$WORK/rp-empty.txt" 2>&1
[ "$?" = "1" ] && check "an empty database says so and exits non-zero" "還沒有任何投資物" "$WORK/rp-empty.txt" \
  || { echo "  FAIL an empty database gave $?"; FAIL=1; }
[ -e "$CLI3/portfolio.sqlite" ] \
  && echo "  ok   and --data-dir kept that run off the real file" \
  || { echo "  FAIL --data-dir was ignored again"; FAIL=1; }
QUOTE_ENABLED=false "$PHPBIN" bin/refresh-prices.php --data-dir="$CLI" >"$WORK/rp-off.txt" 2>&1
[ "$?" = "1" ] && check "a quote source switched off is a failure a scheduler sees" "自動報價已在設定中關閉" "$WORK/rp-off.txt" \
  || { echo "  FAIL the disabled-source run gave $?"; FAIL=1; }
[ -z "$(qf "$CLI/portfolio.sqlite" "SELECT value FROM settings WHERE key = 'last_refresh'")" ] \
  && echo "  ok   and a run that fetched nothing does not claim a 上次更新 time" \
  || { echo "  FAIL last_refresh was stamped anyway"; FAIL=1; }
"$PHPBIN" bin/refresh-prices.php --help >"$WORK/rp-help.txt" 2>&1
[ "$?" = "0" ] && check "--help explains the codes rather than just the flags" "回傳碼" "$WORK/rp-help.txt" \
  || { echo "  FAIL --help gave $?"; FAIL=1; }

# The day boundary: PHP would otherwise follow the machine, and a 08:00 HKT trade would be
# dated yesterday in the ledger while the form offers 「today」. PHP 8 reports "UTC" for an unset
# date.timezone, so the app has to read the raw ini value to tell the two apart — both cases below.
TZCODE='require __DIR__ . "/app/bootstrap.php"; echo date_default_timezone_get();'
TZUTC=$("$PHPBIN" -d display_errors=stderr -d date.timezone= -r "$TZCODE" 2>/dev/null)
[ "$TZUTC" = "Asia/Hong_Kong" ] \
  && echo "  ok   with php.ini saying nothing, the app supplies the local day boundary" \
  || { echo "  FAIL the timezone came out as $TZUTC"; FAIL=1; }
"$PHPBIN" -d display_errors=stderr -d date.timezone=UTC -r "$TZCODE" >"$WORK/tz4.txt" 2>/dev/null
[ "$(cat "$WORK/tz4.txt")" = "UTC" ] \
  && echo "  ok   but an operator who did write UTC is taken at their word" \
  || { echo "  FAIL a configured UTC came out as $(cat "$WORK/tz4.txt")"; FAIL=1; }
PORTFOLIO_TZ=America/New_York "$PHPBIN" -d display_errors=stderr -d date.timezone=Europe/Berlin -r "$TZCODE" >"$WORK/tz.txt" 2>/dev/null
[ "$(cat "$WORK/tz.txt")" = "America/New_York" ] \
  && echo "  ok   PORTFOLIO_TZ overrides php.ini" || { echo "  FAIL PORTFOLIO_TZ gave $(cat "$WORK/tz.txt")"; FAIL=1; }
"$PHPBIN" -d display_errors=stderr -d date.timezone=Europe/Berlin -r "$TZCODE" >"$WORK/tz2.txt" 2>/dev/null
[ "$(cat "$WORK/tz2.txt")" = "Europe/Berlin" ] \
  && echo "  ok   and an operator who did set php.ini is not overridden" || { echo "  FAIL gave $(cat "$WORK/tz2.txt")"; FAIL=1; }
PORTFOLIO_TZ=Mars/Olympus "$PHPBIN" -d display_errors=stderr -d date.timezone=Europe/Berlin -r "$TZCODE" >"$WORK/tz3.txt" 2>"$WORK/tz3-err.txt"
check "a made-up zone is named as the problem" "不是有效的時區" "$WORK/tz3-err.txt"
[ "$(cat "$WORK/tz3.txt")" = "Europe/Berlin" ] \
  && echo "  ok   and the app carries on with the zone it already had" || { echo "  FAIL it ran on $(cat "$WORK/tz3.txt")"; FAIL=1; }
TODAY="$(app_date)"
get "/transactions" "$WORK/tz-form.html" >/dev/null
check "the trade form offers today in the app timezone" "value=\"$TODAY\"" "$WORK/tz-form.html"

echo "時間戳共用同一支時鐘 (UTC+8)"
# SQLite's datetime('now') is UTC no matter what PHP is configured to, so a database written half from
# the statement and half from PHP carries two clocks eight hours apart. Every stamp is written by the
# app now; a UTC leftover is exactly 28,800 seconds off, which is what lag() measures.
lag() {
  "$PHPBIN" -d display_errors=stderr -r 'require __DIR__ . "/app/bootstrap.php";
    $at = DateTime::createFromFormat("Y-m-d H:i:s", trim($argv[1]), new DateTimeZone(date_default_timezone_get()));
    echo $at === false ? "unreadable(" . $argv[1] . ")" : abs(time() - $at->getTimestamp());' "$1" 2>/dev/null
}
# 1,800 s is generous for a slow machine and nowhere near an eight-hour skew.
within() {
  local n; n=$(lag "$2")
  case "$n" in
    *[!0-9]*) echo "  FAIL $1 — $n"; FAIL=1; return ;;
  esac
  [ "$n" -lt 1800 ] && echo "  ok   $1" || { echo "  FAIL $1 is $n seconds off the app clock"; FAIL=1; }
}
for COL in "portfolios.created_at" "instruments.created_at" "transactions.created_at" "cash_movements.created_at"; do
  TBL=${COL%%.*}; FLD=${COL##*.}
  WITHIN_VAL=$(q "SELECT $FLD FROM $TBL ORDER BY id DESC LIMIT 1")
  within "$COL comes from the same clock as 上次更新 ($WITHIN_VAL)" "$WITHIN_VAL"
done
within "prices.fetched_at does too" "$(q "SELECT fetched_at FROM prices ORDER BY as_of DESC LIMIT 1")"
within "and so does fx_rates.fetched_at" "$(q "SELECT fetched_at FROM fx_rates ORDER BY fetched_at DESC LIMIT 1")"
within "the account made from the CLI keeps to it" "$(q "SELECT created_at FROM access WHERE id = 1")"
[ "$(q "SELECT COUNT(*) FROM transactions WHERE created_at = ''")" = "0" ] \
  && echo "  ok   no row was written without a stamp" || { echo "  FAIL an empty stamp got through"; FAIL=1; }
# The schema default is what a hand-written row gets. A freshly migrated database is the only place
# to look, because migrate() is CREATE TABLE IF NOT EXISTS and will not rewrite an existing file.
execq "$CLI3/portfolio.sqlite" "INSERT INTO portfolios (name) VALUES ('手寫的一列')"
HANDWRITTEN=$(qf "$CLI3/portfolio.sqlite" "SELECT created_at FROM portfolios WHERE name = '手寫的一列'")
within "a row added with sqlite3 lands on the same clock, not UTC ($HANDWRITTEN)" "$HANDWRITTEN"
UTCNOW=$("$PHPBIN" -r 'echo (new DateTime("now", new DateTimeZone("UTC")))->format("Y-m-d H:i");')
case "$HANDWRITTEN" in
  "$UTCNOW"*) echo "  FAIL the schema default is still UTC ($HANDWRITTEN)"; FAIL=1 ;;
  *) echo "  ok   and it is eight hours ahead of what datetime('now') would have said" ;;
esac

# bin/create-admin.php hides input where the terminal can, and has to say so where it cannot
# (cmd.exe cannot, and used to stay quiet while echoing the password back).
"$PHPBIN" bin/create-admin.php --help >"$WORK/ca-help.txt" 2>&1
[ "$?" = "0" ] && check "its --help lists the data directory switch" "PORTFOLIO_DATA_DIR" "$WORK/ca-help.txt" \
  || { echo "  FAIL --help gave $?"; FAIL=1; }
"$PHPBIN" bin/create-admin.php --data-dir="$CLI4" --username=alice --role=admin >/dev/null 2>&1
[ "$?" = "2" ] && echo "  ok   an option that does not exist is rejected (2)" || { echo "  FAIL gave $?"; FAIL=1; }
"$PHPBIN" bin/create-admin.php --data-dir="$CLI4" --username=alice --password >/dev/null 2>"$WORK/ca-pw.txt"
[ "$?" = "2" ] && check "and --password without a value is not read as one" "需要寫成" "$WORK/ca-pw.txt" \
  || { echo "  FAIL bare --password gave $?"; FAIL=1; }
"$PHPBIN" bin/create-admin.php --data-dir="$CLI4" --username= >/dev/null 2>"$WORK/ca-user.txt"
[ "$?" = "2" ] && check "an empty --username stops before asking for a password" "缺少 --username" "$WORK/ca-user.txt" \
  || { echo "  FAIL empty --username gave $?"; FAIL=1; }
printf '%s' 'stdin-pass-123' | "$PHPBIN" bin/create-admin.php --data-dir="$CLI4" --username=alice --password-from-stdin \
  >"$WORK/ca-stdin.txt" 2>&1
[ "$?" = "0" ] && check "stdin is how a script can hand over a password" "已建立帳號「alice」" "$WORK/ca-stdin.txt" \
  || { echo "  FAIL --password-from-stdin gave $?"; FAIL=1; }
# Here STDIN is a pipe rather than a terminal, which is exactly the case that cannot hide input.
printf '%s\n' 'prompt-pass-123' | "$PHPBIN" bin/create-admin.php --data-dir="$CLI4" --username=bob --force \
  >"$WORK/ca-echo.txt" 2>&1
[ "$?" = "0" ] && echo "  ok   reading a password off a pipe still works" || { echo "  FAIL the prompt run gave $?"; FAIL=1; }
check "and it warns that the input is not hidden" "會顯示在螢幕上" "$WORK/ca-echo.txt"
notcheck "without ever repeating what was typed" "prompt-pass-123" "$WORK/ca-echo.txt"
[ "$(qf "$CLI4/portfolio.sqlite" "SELECT username FROM access WHERE id = 1")" = "bob" ] \
  && echo "  ok   and --force really replaces the one account" || { echo "  FAIL the account did not change"; FAIL=1; }
[ "$(qf "$CLI4/portfolio.sqlite" "SELECT substr(password_hash,1,7) FROM access")" = "\$2y\$12$" ] \
  && echo "  ok   the stored secret is a bcrypt hash, not the text that was typed" \
  || { echo "  FAIL the hash came out as $(qf "$CLI4/portfolio.sqlite" "SELECT substr(password_hash,1,10) FROM access")"; FAIL=1; }

echo "login: 登出、改密碼、以及還原碰不到的那部分"
# Last section on purpose: it changes the credential, and everything above is signed in with the first one.

T=$(csrf "/")
CODE=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$JAR" -b "$JAR" -X POST "$BASE/logout" -d "_csrf=$T")
[ "$CODE" = "303 ${BASE}/login" ] \
  && echo "  ok   登出 closes the session and goes back to the login page" \
  || { echo "  FAIL logout gave $CODE"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE/transactions")
[ "$CODE" = "303" ] && echo "  ok   the same cookie no longer opens the app" || { echo "  FAIL still signed in ($CODE)"; FAIL=1; }
curl -s -L -b "$JAR" -c "$JAR" -o "$LAST" "$BASE/login" >/dev/null
check "and the page says 已登出 instead of failing silently" "已登出" "$LAST"

T=$(csrf "/login")
CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" -X POST "$BASE/login" -d "_csrf=$T" -d "username=$ADMIN" -d "password=$PASS")
[ "$CODE" = "303" ] && echo "  ok   signing in again after 登出 works" || { echo "  FAIL re-login gave $CODE"; FAIL=1; }

# A second browser, signed in with the same credential, so that a password change has something to evict.
CODE=$(try_login "$WORK/second.html" "$(LT)" "$ADMIN" "$PASS")
[ "$CODE" = "303" ] && echo "  ok   a second browser signs in with the same account" || { echo "  FAIL gave $CODE"; FAIL=1; }

BEFORE_HASH=$(q "SELECT password_hash FROM access WHERE id = 1")
T=$(csrf "/")
CODE=$(post "/password" "$T" -d "current_password=nope-nope-nope" -d "new_password=another-pass-1" -d "new_password2=another-pass-1")
check "a wrong current password is refused" "目前密碼不正確。" "$LAST"
[ "$CODE" = "400" ] && echo "  ok   with 400" || { echo "  FAIL gave $CODE"; FAIL=1; }
CODE=$(post "/password" "$T" -d "current_password=$PASS" -d "new_password=short" -d "new_password2=short")
check "a new password under 10 characters is refused" "至少要 10 個字元" "$LAST"
CODE=$(post "/password" "$T" -d "current_password=$PASS" -d "new_password=another-pass-1" -d "new_password2=another-pass-2")
check "the two new passwords have to match" "兩次輸入的新密碼不一樣" "$LAST"
CODE=$(post "/password" "$T" -d "current_password=$PASS" -d "new_password=$PASS" -d "new_password2=$PASS")
check "re-using the password you already have is refused" "新密碼不能跟現在用的相同" "$LAST"
[ "$(q "SELECT password_hash FROM access WHERE id = 1")" = "$BEFORE_HASH" ] \
  && echo "  ok   none of those four touched the stored hash" \
  || { echo "  FAIL a refused password change wrote the hash anyway"; FAIL=1; }

OLD_PASSWORD_SESSION=$(grep portfolio_session "$JAR" | awk '{print $7}' | tail -1)
CODE=$(post "/password" "$T" -d "current_password=$PASS" -d "new_password=$NEWPASS" -d "new_password2=$NEWPASS")
[ "$CODE" = "200" ] && check "the change is confirmed on the settings page" "密碼已更新" "$LAST"
NEW_PASSWORD_SESSION=$(grep portfolio_session "$JAR" | awk '{print $7}' | tail -1)
[ -n "$OLD_PASSWORD_SESSION" ] && [ "$NEW_PASSWORD_SESSION" != "$OLD_PASSWORD_SESSION" ] \
    && echo "  ok   a successful password change rotates the session id" \
    || { echo "  FAIL password change did not rotate the session id"; FAIL=1; }
NEWHASH=$(q "SELECT password_hash FROM access WHERE id = 1")
[ "$NEWHASH" != "$BEFORE_HASH" ] && echo "  ok   the stored hash really changed" || { echo "  FAIL hash unchanged"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "portfolio_session=$OLD_PASSWORD_SESSION" "$BASE/holdings")
[ "$CODE" = "303" ] \
    && echo "  ok   a copied pre-change cookie is invalid after the password change" \
    || { echo "  FAIL the copied old cookie survived ($CODE)"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$AJAR" "$BASE/holdings")
[ "$CODE" = "303" ] \
  && echo "  ok   the other browser is thrown out the moment the password changes" \
  || { echo "  FAIL the old session survived ($CODE)"; FAIL=1; }
CODE=$(get "/" "$LAST")
[ "$CODE" = "200" ] && echo "  ok   while this one stays signed in" || { echo "  FAIL own session dropped ($CODE)"; FAIL=1; }
CODE=$(try_login "$WORK/oldpw.html" "$(LT)" "$ADMIN" "$PASS")
[ "$CODE" = "401" ] && echo "  ok   the old password no longer opens the door" || { echo "  FAIL gave $CODE"; FAIL=1; }
CODE=$(try_login "$WORK/newpw.html" "$(LT)" "$ADMIN" "$NEWPASS")
[ "$CODE" = "303" ] && echo "  ok   and the new one does" || { echo "  FAIL gave $CODE"; FAIL=1; }

# The credential is deliberately outside a backup: rolling the data back must not move the lock.
T=$(csrf "/settings")
post "/backup/create" "$T" >/dev/null
SNAP=$(ls "$BK" | grep '^manual-' | sort | tail -1)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/auth-backup.sqlite" "$BASE/backup/$SNAP/download"
[ -s "$WORK/auth-backup.sqlite" ] && echo "  ok   downloaded a snapshot to tamper with" || { echo "  FAIL download empty"; FAIL=1; }
execq "$WORK/auth-backup.sqlite" "UPDATE access SET username = 'someone-else', password_hash = 'x' WHERE id = 1"
CODE=$(postfile "/backup/restore" "$T" -F "backup=@$WORK/auth-backup.sqlite;type=application/octet-stream" -F "confirm=1")
[ "$CODE" = "303" ] && echo "  ok   that backup still restores" || { echo "  FAIL restore gave $CODE"; grep -oE '不是 SQLite 資料庫|缺少欄位[^<]*|完整性檢查[^<]*|資料關聯不一致[^<]*|無法讀取[^<]*|還原失敗[^<]*' "$LAST"; FAIL=1; }
[ "$(q "SELECT username FROM access WHERE id = 1")" = "$ADMIN" ] \
  && echo "  ok   but it did not rename the account" \
  || { echo "  FAIL the restore replaced the username"; FAIL=1; }
[ "$(q "SELECT password_hash FROM access WHERE id = 1")" = "$NEWHASH" ] \
  && echo "  ok   nor handed out the password baked into the file" \
  || { echo "  FAIL the restore replaced the password hash"; FAIL=1; }
CODE=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" "$BASE/settings")
[ "$CODE" = "200" ] && echo "  ok   and the session that did it is still valid afterwards" || { echo "  FAIL ($CODE)"; FAIL=1; }

# Backups taken before this feature have no login table at all, and have to stay restorable.
execq "$WORK/auth-backup.sqlite" "DROP TABLE access"
CODE=$(postfile "/backup/restore" "$T" -F "backup=@$WORK/auth-backup.sqlite;type=application/octet-stream" -F "confirm=1")
[ "$CODE" = "303" ] && echo "  ok   a backup taken before the login feature still restores" || { echo "  FAIL restore gave $CODE"; grep -oE '不是 SQLite 資料庫|缺少欄位[^<]*|完整性檢查[^<]*|資料關聯不一致[^<]*|無法讀取[^<]*|還原失敗[^<]*' "$LAST"; FAIL=1; }
get "/settings" "$LAST" >/dev/null
check "the page confirms the data came back" "已還原" "$LAST"
[ "$(q "SELECT password_hash FROM access WHERE id = 1")" = "$NEWHASH" ] \
  && echo "  ok   and the door kept its own lock" || { echo "  FAIL the credential moved"; FAIL=1; }
get "/settings" "$LAST" >/dev/null
check "the settings page still offers the password form afterwards" "更新密碼" "$LAST"

echo "轉接位址與節流（沿用開場啟動的本機假來源）"
# 開場已啟動同一個假端點並切好出口；本節只檢查轉接、節流與補抓，不重新監聽固定候選埠。
if [ -z "${STUB_URL:-}" ] || [ -z "${STUB_PID:-}" ]; then
  echo "  FAIL 假報價端點未在開場啟動"
  FAIL=1
else

  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" \
    -d "quote_endpoint=$STUB_ENDPOINT/" -d "quote_token=" -d "quote_refresh_budget=240" >/dev/null
  check "the settings page takes a passthrough address" "已更新設定" "$LAST"
  grep -qF '已更新設定' "$LAST" || { echo "endpoint=[$STUB_ENDPOINT] base=[$BC]"; cp "$LAST" "$WORK/settings-passthrough-error.html"; php -r '$s=file_get_contents($argv[1]); preg_match("~<div class=\"panel\">(.*?)</div>~s",$s,$m); echo trim(strip_tags($m[1]??"")),"\n";' "$LAST"; }
  [ "$(q "SELECT value FROM settings WHERE key = 'quote_endpoint'")" = "$STUB_ENDPOINT" ] \
    && echo "  ok   stored without the trailing slash, as one prefix" \
    || { echo "  FAIL stored $(q "SELECT value FROM settings WHERE key = 'quote_endpoint'")"; FAIL=1; }

  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_endpoint=http://someone:pw@proxy.example.com" >/dev/null
  check "an address with credentials baked in is refused" "轉接位址不可包含帳號或密碼" "$LAST"
  [ "$(q "SELECT value FROM settings WHERE key = 'quote_endpoint'")" = "$STUB_ENDPOINT" ] \
    && echo "  ok   and the refused value was not written" \
    || { echo "  FAIL the bad address got stored"; FAIL=1; }

  TOKEN_BEFORE_REJECT=$(q "SELECT value FROM settings WHERE key = 'quote_token'")
  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_endpoint=http://proxy.example.com" -d "quote_token=sekret" -d "quote_refresh_budget=240" >/dev/null
  check "a token refuses to travel over plain HTTP" "已設定報價金鑰時，轉接位址必須使用 HTTPS" "$LAST"
  [ "$(q "SELECT value FROM settings WHERE key = 'quote_endpoint'")" = "$STUB_ENDPOINT" ] \
    && [ "$(q "SELECT value FROM settings WHERE key = 'quote_token'")" = "$TOKEN_BEFORE_REJECT" ] \
    && echo "  ok   the rejected endpoint and token were both left unchanged" \
    || { echo "  FAIL the insecure token settings were written"; FAIL=1; }

  execq "$DB" "INSERT INTO portfolios (name, created_at) VALUES ('轉接位址測試', '2025-01-01 00:00:00');"
  SPID=$(q "SELECT id FROM portfolios WHERE name='轉接位址測試'")
  execq "$DB" "
    INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency) VALUES
      ('SB1','轉接一號','US','STUBONE','USD'),
      ('SB2','轉接二號','US','STUBTWO','USD');
    INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price) VALUES
      ($SPID, (SELECT id FROM instruments WHERE yahoo_symbol='STUBONE'), 'BUY', '2025-01-05', 10, 30),
      ($SPID, (SELECT id FROM instruments WHERE yahoo_symbol='STUBTWO'), 'BUY', '2025-01-05', 10, 30);"
  I1=$(q "SELECT id FROM instruments WHERE yahoo_symbol='STUBONE'")
  execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '0')
               ON CONFLICT(key) DO UPDATE SET value = '0'"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$SPID" -d "back=/" >/dev/null
  check "quotes arrive from the configured address" "更新 2 檔" "$LAST"
  notcheck "no PHP notices while fetching through it" "Warning:" "$LAST"
  [ "$(q "SELECT close FROM prices WHERE instrument_id=$I1 ORDER BY as_of DESC LIMIT 1")" = "42.5" ] \
    && echo "  ok   the stored close is the stub's 42.5, not a live number" \
    || { echo "  FAIL stored close is $(q "SELECT close FROM prices WHERE instrument_id=$I1 ORDER BY as_of DESC LIMIT 1")"; FAIL=1; }
  [ "$(q "SELECT exchange FROM instruments WHERE yahoo_symbol='STUBONE'")" = "NMS" ] \
    && echo "  ok   the stored exchange came from the stub (NMS)" \
    || { echo "  FAIL stored exchange is $(q "SELECT exchange FROM instruments WHERE yahoo_symbol='STUBONE'")"; FAIL=1; }
  sort "$HITS" -o "$HITS" >/dev/null 2>&1
  EXPECT="$(printf '%s\n' 'STUBONE 6mo' 'STUBTWO 6mo' "$BC=X 5d" | sort | tr '\n' '~')"
  GOT="$(tr '\n' '~' < "$HITS")"
  [ "$GOT" = "$EXPECT" ] \
    && echo "  ok   exactly one request per instrument, plus the fx pair" \
    || { echo "  FAIL requests were: $GOT"; FAIL=1; }

  execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '10')
               ON CONFLICT(key) DO UPDATE SET value = '10'"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$SPID" -d "back=/" >/dev/null
  check "a second click says what it did not re-ask" "2 檔略過" "$LAST"
  [ ! -s "$HITS" ] && echo "  ok   and it sent no request at all" \
    || { echo "  FAIL it asked again: $(cat "$HITS" | tr '\n' ' ')"; FAIL=1; }
  execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '0')
               ON CONFLICT(key) DO UPDATE SET value = '0'"

  echo 120 > "$STUB_DIR/limit"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$SPID" -d "back=/" >/dev/null
  check "a refused source is named with its status" "來源拒絕要求（HTTP 429）" "$LAST"
  check "and the pass stops instead of walking the list" "來源暫停中" "$LAST"
  [ "$(grep -c . "$HITS" 2>/dev/null || true)" = "1" ] \
    && echo "  ok   two instruments, one request: the second was never asked" \
    || { echo "  FAIL $(grep -c . "$HITS" 2>/dev/null || true) requests went out: $(tr '\n' ' ' < "$HITS")"; FAIL=1; }
  UNTIL=$(q "SELECT value FROM settings WHERE key = 'quote_cooldown_until'")
  LEFT=$("$PHPBIN" -r 'echo (int) (strtotime((string) $argv[1]) - time());' "$UNTIL")
  [ "$LEFT" -ge 100 ] 2>/dev/null && [ "$LEFT" -le 125 ] 2>/dev/null \
    && echo "  ok   Retry-After set the pause (about ${LEFT}s left)" \
    || { echo "  FAIL pause is $UNTIL ($LEFT s)"; FAIL=1; }

  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$SPID" -d "back=/" >/dev/null
  check "while paused, clicking update asks nothing" "來源暫停中" "$LAST"
  [ ! -s "$HITS" ] && echo "  ok   not one request left the server" \
    || { echo "  FAIL requests while paused: $(cat "$HITS" | tr '\n' ' ')"; FAIL=1; }
  quote_php bin/refresh-prices.php --data-dir="$DATADIR" >"$WORK/rp-paused.txt" 2>&1
  RPCODE=$?
  [ "$RPCODE" = "1" ] && [ ! -s "$HITS" ] && grep -q "來源暫停中" "$WORK/rp-paused.txt" \
    && echo "  ok   the CLI respects the pause too, and still exits non-zero" \
    || { echo "  FAIL the CLI gave $RPCODE and asked $(grep -c . "$HITS" 2>/dev/null || true) more times"; FAIL=1; }

  rm -f "$STUB_DIR/limit"
  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=" \
    -d "clear_cooldown=1" -d "quote_refresh_budget=240" >/dev/null
  [ -z "$(q "SELECT value FROM settings WHERE key = 'quote_cooldown_until'")" ] \
    && echo "  ok   the settings page can lift the pause without waiting it out" \
    || { echo "  FAIL the pause survived the button"; FAIL=1; }

  echo 'sekret' > "$STUB_DIR/token"
  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=sekret" -d "quote_refresh_budget=240" >/dev/null
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$SPID" -d "back=/" >/dev/null
  [ -s "$HITS" ] && [ -z "$(q "SELECT value FROM settings WHERE key = 'quote_cooldown_until'")" ] \
    && echo "  ok   the key travels, and the matching request is not refused" \
    || { echo "  FAIL a correct key still got turned away"; FAIL=1; }

  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_endpoint=$STUB_ENDPOINT" -d "quote_token=wrong" -d "quote_refresh_budget=240" >/dev/null
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$SPID" -d "back=/" >/dev/null
  check "the wrong key is reported as its own status" "HTTP 401" "$LAST"
  [ -z "$(q "SELECT value FROM settings WHERE key = 'quote_cooldown_until'")" ] \
    && echo "  ok   and a typo is not mistaken for a rate limit (no pause)" \
    || { echo "  FAIL a 401 paused the source"; FAIL=1; }
  rm -f "$STUB_DIR/token"

  echo "十年補抓只問一次（同一支假位址，把回覆改成「只有這幾天」）"
  # 回報要畫十年曲線，所以補抓十年日線是必要的；但一個 2020 年才成立的基金永遠不會有 2017 年的那根。
  # 少了「有沒有進步」這個判斷，每一輪更新都會多發一個最大的要求、拖幾百 KB、再把幾千行原封不動重寫一次
  # （實測四檔共 445 KB、4,180 行）。把假位址改成只給最近三天的日線，這件事就變成數檔案行數能證明的東西。
  execq "$DB" "INSERT INTO portfolios (name, created_at) VALUES ('補抓測試', '2025-01-01 00:00:00');"
  HPID=$(q "SELECT id FROM portfolios WHERE name='補抓測試'")
  echo shallow > "$STUB_DIR/shallow"
  execq "$DB" "INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency) VALUES ('SH1','補抓一號','US','SHALONE','USD');
    INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price) VALUES
      ($HPID, (SELECT id FROM instruments WHERE yahoo_symbol='SHALONE'), 'BUY', '2025-01-05', 10, 30);"
  H1=$(q "SELECT id FROM instruments WHERE yahoo_symbol='SHALONE'")
  BACK3="$(app_date '-3 days')"

  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  sort "$HITS" -o "$HITS" >/dev/null 2>&1
  GOT="$(tr '\n' '~' < "$HITS")"
  EXPECT="$(printf '%s\n' 'SHALONE 10y' 'SHALONE 6mo' "$BC=X 5d" | sort | tr '\n' '~')"
  [ "$GOT" = "$EXPECT" ] \
    && echo "  ok   第一次照舊補抓一次十年" \
    || { echo "  FAIL requests were: $GOT"; FAIL=1; }
  check "補到的根數會講出來" "補齊回報所需歷史日線" "$LAST"
  [ "$(q "SELECT MIN(as_of) FROM prices WHERE instrument_id=$H1")" = "$BACK3" ] \
    && echo "  ok   資料庫裡最早的一根，就是這個代號到得了的地方" \
    || { echo "  FAIL oldest is $(q "SELECT MIN(as_of) FROM prices WHERE instrument_id=$H1"), expected $BACK3"; FAIL=1; }
  MARK=$(q "SELECT value FROM settings WHERE key = 'deep_oldest_$H1'")
  [ "${MARK%%|*}" = "$BACK3" ] \
    && echo "  ok   並且把「到得了的那根」記下來了" \
    || { echo "  FAIL the mark says「$MARK」"; FAIL=1; }
  AGE=$("$PHPBIN" -r 'echo (int) (time() - (strtotime((string) $argv[1]) ?: 0));' "${MARK##*|}")
  [ "$AGE" -ge 0 ] 2>/dev/null && [ "$AGE" -le 120 ] 2>/dev/null \
    && echo "  ok   記的還有時間（${AGE}s 前），那是這個結論的有效期起點" \
    || { echo "  FAIL the mark carries no usable stamp:「$MARK」"; FAIL=1; }

  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  [ "$(grep -c '10y' "$HITS" 2>/dev/null || true)" = "0" ] \
    && echo "  ok   第二輪不再補抓：最早那根沒有再往前，就代表歷史已經到底" \
    || { echo "  FAIL it backfilled again: $(tr '\n' ' ' < "$HITS")"; FAIL=1; }
  [ "$(grep -c . "$HITS" 2>/dev/null || true)" = "2" ] \
    && echo "  ok   這一輪只發了兩發：最新價與一組匯率" \
    || { echo "  FAIL $(grep -c . "$HITS" 2>/dev/null || true) requests went out: $(tr '\n' ' ' < "$HITS")"; FAIL=1; }
  notcheck "回報裡也不會再說補了歷史" "補齊回報所需歷史日線" "$LAST"
  [ "$(q "SELECT COUNT(*) FROM prices WHERE instrument_id=$H1")" = "3" ] \
    && echo "  ok   資料庫裡還是那三根，沒有被重複寫入" \
    || { echo "  FAIL the bar count moved to $(q "SELECT COUNT(*) FROM prices WHERE instrument_id=$H1")"; FAIL=1; }

  # 記號讀不懂時必須選擇再問一次，而不是把那個代號永久静音。
  execq "$DB" "INSERT INTO settings (key, value) VALUES ('deep_oldest_$H1', '$BACK3')
               ON CONFLICT(key) DO UPDATE SET value = '$BACK3';"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  [ "$(grep -c '10y' "$HITS" 2>/dev/null || true)" = "1" ] \
    && echo "  ok   讀不懂的記號不會把門關死：寧可多問一次" \
    || { echo "  FAIL an unreadable mark silenced the backfill"; FAIL=1; }
  MARK=$(q "SELECT value FROM settings WHERE key = 'deep_oldest_$H1'")
  case "$MARK" in
    *'|'*) echo "  ok   而且這一輪把記號寫回完整的形狀" ;;
    *) echo "  FAIL the mark is still half-written:「$MARK」"; FAIL=1 ;;
  esac

  # 來源哪天真的大方了：補到十年之前之後，改由原本那道門檻擋掉，記號在不在都無所謂。
  rm -f "$STUB_DIR/shallow"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  # DEEP12 在開場以同樣的應用時區算好；不可再用主機 shell 的 date 推日期。
  [ "$(q "SELECT MIN(as_of) FROM prices WHERE instrument_id=$H1")" = "$DEEP12" ] \
    && echo "  ok   更早的日線進得了資料庫" \
    || { echo "  FAIL oldest is $(q "SELECT MIN(as_of) FROM prices WHERE instrument_id=$H1")"; FAIL=1; }
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  [ "$(grep -c '10y' "$HITS" 2>/dev/null || true)" = "0" ] \
    && echo "  ok   已經夠深的投資物由原本那道門檻擋掉，不需要記號" \
    || { echo "  FAIL an instrument deep enough still asks for 10y"; FAIL=1; }

  # 打不通不等於「沒有更早的資料」：一次失敗不能讓那個代號再也沒補過。
  echo shallow > "$STUB_DIR/shallow"
  echo x > "$STUB_DIR/faildeep"
  execq "$DB" "INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency) VALUES ('SH2','補抓二號','US','SHTWO','USD');
    INSERT INTO transactions (portfolio_id, instrument_id, side, traded_on, quantity, price) VALUES
      ($HPID, (SELECT id FROM instruments WHERE yahoo_symbol='SHTWO'), 'BUY', '2025-01-05', 10, 30);"
  H2=$(q "SELECT id FROM instruments WHERE yahoo_symbol='SHTWO'")
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  [ "$(grep -c 'SHTWO 10y' "$HITS" 2>/dev/null || true)" = "1" ] \
    && echo "  ok   新代號照樣試補一次" \
    || { echo "  FAIL the deep attempt was not made: $(tr '\n' ' ' < "$HITS")"; FAIL=1; }
  [ -z "$(q "SELECT value FROM settings WHERE key = 'deep_oldest_$H2'")" ] \
    && echo "  ok   但那發失敗了，所以不會記成「歷史到底」" \
    || { echo "  FAIL a failed backfill was mistaken for exhausted history"; FAIL=1; }

  # 欠著十年歷史的投資物，不能因為「十幾秒前才抓過」就被暫停擋掉：正式區踩過這個坑——七檔新建的 ETF
  # 在這一輪裡十年深抓失敗了，之後每一輪都被暫停跳過，畫面上只顯示「資料不足」半個錯誤訊息都沒有。
  execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '10')
               ON CONFLICT(key) DO UPDATE SET value = '10'"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  [ "$(grep -c 'SHTWO 10y' "$HITS" 2>/dev/null || true)" = "1" ] \
    && echo "  ok   還欠十年歷史的那檔，暫停中照樣再問一次" \
    || { echo "  FAIL the pause blocked an instrument that still owes history: $(tr '\n' ' ' < "$HITS")"; FAIL=1; }
  [ "$(grep -c 'SHALONE 6mo' "$HITS" 2>/dev/null || true)" = "0" ] \
    && echo "  ok   歷史已經夠深的那檔，暫停還是照樣擋得住" \
    || { echo "  FAIL the exemption let a settled instrument be re-asked"; FAIL=1; }
  check "補抓失敗會講出是哪一檔" "的十年歷史沒補上" "$LAST"
  check "回報裡也給了根數與失敗檔數" "1 檔的十年歷史沒補上" "$LAST"
  quote_php bin/refresh-prices.php --data-dir="$DATADIR" --portfolio="$HPID" >"$WORK/rp-deepfail.txt" 2>&1
  DEEPCODE=$?
  [ "$DEEPCODE" = "1" ] && grep -q "十年歷史 +0 根、1 檔沒補上" "$WORK/rp-deepfail.txt" \
    && grep -q "quotes ok=1 failed=0 skipped=1" "$WORK/rp-deepfail.txt" \
    && echo "  ok   排程那支也因為欠的十年歷史而回報非零（報價本身有更新到、夠深的那檔仍被暫停擋住）" \
    || { echo "  FAIL the CLI gave $DEEPCODE: $(tr '\n' ' ' < "$WORK/rp-deepfail.txt")"; FAIL=1; }
  # 同樣的原因要收成一條：來源整個掛掉時，幾十行重複訊息會把閃訊淹死。
  # 這一發要在資料庫副本上跑：不帶 --portfolio 的全庫刷新會替每一檔寫進當日的 yahoo 日線，後面「人工標價
  # 那檔只有 manual 列」那一節的前提就壞了——實測過，它因此變成假失敗。副本裡帳號那列清掉，不讓它帶著雜碼留著。
  GRP="$WORK/deepgroup"; mkdir -p "$GRP"; cp "$DB" "$GRP/portfolio.sqlite"
  execq "$GRP/portfolio.sqlite" "DELETE FROM access; DELETE FROM login_throttle;"
  quote_php bin/refresh-prices.php --data-dir="$GRP" >"$WORK/rp-deepall.txt" 2>&1
  [ "$(grep -c '的十年歷史沒補上' "$WORK/rp-deepall.txt" 2>/dev/null || true)" = "1" ] \
    && grep -q '檔的十年歷史沒補上（來源回覆 HTTP 500）' "$WORK/rp-deepall.txt" \
    && echo "  ok   同一個原因只講一次，背後掛的是哪幾檔" \
    || { echo "  FAIL the backfill errors did not group: $(grep '的十年歷史沒補上' "$WORK/rp-deepall.txt" 2>/dev/null | head -3 | tr '\n' ' ')"; FAIL=1; }
  execq "$DB" "INSERT INTO settings (key, value) VALUES ('quote_min_refresh_minutes', '0')
               ON CONFLICT(key) DO UPDATE SET value = '0'"

  rm -f "$STUB_DIR/faildeep"
  : > "$HITS"
  T=$(csrf "/")
  post "/prices/refresh" "$T" -d "portfolio_id=$HPID" -d "back=/" >/dev/null
  [ "$(grep -c 'SHTWO 10y' "$HITS" 2>/dev/null || true)" = "1" ] \
    && echo "  ok   來源恢復之後的下一輪還會再試" \
    || { echo "  FAIL the failure silenced it for good: $(tr '\n' ' ' < "$HITS")"; FAIL=1; }
  [ -n "$(q "SELECT value FROM settings WHERE key = 'deep_oldest_$H2'")" ] \
    && echo "  ok   這次成功了，才把日期記下來" \
    || { echo "  FAIL a successful backfill wrote no mark"; FAIL=1; }

  # 投資物刪掉之後 id 會被下一個重用，所以它的記號必須跟著走。
  TID=$(q "SELECT id FROM transactions WHERE instrument_id=$H2")
  T=$(csrf "/transactions")
  post "/transactions/$TID/delete" "$T" -d "back=/transactions" >/dev/null
  T=$(csrf "/instruments")
  post "/instruments/$H2/delete" "$T" -d "back=/instruments" >/dev/null
  check "移除投資物會回報成功" "已移除投資物" "$LAST"
  [ -z "$(q "SELECT value FROM settings WHERE key = 'deep_oldest_$H2'")" ] \
    && echo "  ok   它那一份記號一起清掉，不會留給重用同一個 id 的新投資物" \
    || { echo "  FAIL the mark of a deleted instrument survived"; FAIL=1; }

  rm -f "$STUB_DIR/shallow" "$STUB_DIR/faildeep"
  execq "$DB" "DELETE FROM transactions WHERE portfolio_id = $HPID;
    DELETE FROM prices WHERE instrument_id IN ((SELECT id FROM instruments WHERE yahoo_symbol IN ('SHALONE','SHTWO')));
    DELETE FROM instruments WHERE yahoo_symbol IN ('SHALONE','SHTWO');
    DELETE FROM portfolios WHERE id = $HPID;
    DELETE FROM settings WHERE substr(key,1,12) = 'deep_oldest_';"

  T=$(csrf "/settings")
  post "/settings" "$T" -d "base_currency=$BC" -d "quote_source=yahoo" -d "quote_endpoint=" -d "quote_token=" -d "clear_quote_token=1" -d "quote_refresh_budget=240" >/dev/null
  [ -z "$(q "SELECT value FROM settings WHERE key = 'quote_endpoint'")" ] \
    && [ -z "$(q "SELECT value FROM settings WHERE key = 'quote_token'")" ] \
    && echo "  ok   clearing the address goes back to Yahoo itself" \
    || { echo "  FAIL the passthrough settings are still set"; FAIL=1; }
  get "/settings" "$LAST" >/dev/null
  check "the settings page offers the address again" 'name="quote_endpoint"' "$LAST"
  notcheck "with nothing left in it" "value=\"$STUB_ENDPOINT\"" "$LAST"

  # 還原進入本節前的值；直接用暫存 DB 寫回，避免 settings 表單順手對真實出口補匯率。
  "$PHPBIN" -r '$p = new PDO("sqlite:" . $argv[1]);
    $s = $p->prepare("INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    foreach (["quote_endpoint", "quote_token", "quote_source"] as $i => $key) { $s->execute([$key, $argv[$i + 2]]); }' \
    "$DB" "$ORIG_QUOTE_ENDPOINT" "$ORIG_QUOTE_TOKEN" "$ORIG_QUOTE_SOURCE"
  stop_quote_stub
  [ "$(q "SELECT value FROM settings WHERE key='quote_endpoint'")" = "$ORIG_QUOTE_ENDPOINT" ] \
    && echo "  ok   已還原原本的報價出口並停止假伺服器" \
    || { echo "  FAIL 原本的報價出口沒有還原"; FAIL=1; }

  execq "$DB" "DELETE FROM transactions WHERE portfolio_id = $SPID;
    DELETE FROM prices WHERE instrument_id IN ((SELECT id FROM instruments WHERE yahoo_symbol='STUBONE'),
                                               (SELECT id FROM instruments WHERE yahoo_symbol='STUBTWO'));
    DELETE FROM instruments WHERE yahoo_symbol IN ('STUBONE','STUBTWO');
    DELETE FROM portfolios WHERE id = $SPID;
    DELETE FROM settings WHERE key IN ('quote_min_refresh_minutes', 'quote_cooldown_until');"
fi

echo "正式策略（樂活五線譜／樂活通道）"
# Momentum、Turtle 與實驗室容器都已移除；Lehuo 不再依賴 lab_* 開關，直接由正式路由提供。
for OLD in /lab /lab/lehuo /lab/momentum /lab/turtle /lab/turtle/strategy; do
  CODE=$(get "$OLD" "$WORK/removed.html")
  [ "$CODE" = "404" ] \
    && echo "  ok   已移除的 $OLD 回 404" \
    || { echo "  FAIL $OLD still answered $CODE"; FAIL=1; }
done

echo "實驗室（保留空狀態頁）"
rm -f "$WORK/anon-laboratory.txt"
R=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$WORK/anon-laboratory.txt" -b "$WORK/anon-laboratory.txt" "$BASE/laboratory")
[ "$R" = "303 ${BASE}/login?back=%2Flaboratory" ] \
  && echo "  ok   /laboratory 跟其他正式頁面一樣要先登入" || { echo "  FAIL $R"; FAIL=1; }

LAB_BEFORE="$(q "SELECT (SELECT COUNT(*) FROM instruments)||'/'||(SELECT COUNT(*) FROM prices)||'/'||(SELECT COUNT(*) FROM transactions)||'/'||(SELECT COUNT(*) FROM cash_movements)||'/'||(SELECT COUNT(*) FROM settings)")"
CODE=$(get "/laboratory" "$WORK/laboratory.html")
E=$(grep -c -i -E "Warning:|Fatal error:|Deprecated:|Undefined" "$WORK/laboratory.html")
[ "$CODE" = "200" ] && [ "$E" = "0" ] && echo "  ok   GET /laboratory 200, no PHP notices" || { echo "  FAIL laboratory code=$CODE notices=$E"; FAIL=1; }
check "實驗室保留空狀態內容" "實驗室目前沒有項目" "$WORK/laboratory.html"
check "實驗室說明不影響現有系統" "確認不影響現有系統後再提升" "$WORK/laboratory.html"
check "實驗室可返回樂活五線譜" 'href="/index.php/strategy"' "$WORK/laboratory.html"
notcheck "已移除可調布林通道" "可調布林通道" "$WORK/laboratory.html"
notcheck "已移除 PHP 語法檢查" "PHP 語法健康檢查" "$WORK/laboratory.html"
notcheck "已移除源碼檢查端點連結" "/laboratory/source-check" "$WORK/laboratory.html"
notcheck "已移除實驗室前端腳本" "assets/laboratory.js" "$WORK/laboratory.html"
notcheck "實驗室頁面不暴露伺服器路徑" "$ROOT" "$WORK/laboratory.html"

SETTINGS_NAV=$(grep -n 'href="/index.php/settings"' "$WORK/laboratory.html" | head -1 | cut -d: -f1)
STRATEGY_NAV=$(grep -n 'href="/index.php/strategy"' "$WORK/laboratory.html" | head -1 | cut -d: -f1)
LAB_NAV=$(grep -n 'href="/index.php/laboratory"' "$WORK/laboratory.html" | head -1 | cut -d: -f1)
[ -n "$SETTINGS_NAV" ] && [ -n "$STRATEGY_NAV" ] && [ -n "$LAB_NAV" ] \
  && [ "$SETTINGS_NAV" -lt "$STRATEGY_NAV" ] && [ "$STRATEGY_NAV" -lt "$LAB_NAV" ] \
  && echo "  ok   實驗室連結緊接在策略之後" \
  || { echo "  FAIL laboratory nav order: settings=$SETTINGS_NAV strategy=$STRATEGY_NAV lab=$LAB_NAV"; FAIL=1; }

CODE=$(get "/laboratory/source-check" "$WORK/lab-source-removed.html")
[ "$CODE" = "404" ] \
  && echo "  ok   已移除的源碼檢查端點回 404" \
  || { echo "  FAIL removed source-check endpoint gave $CODE"; FAIL=1; }

T=$(csrf "/laboratory")
post "/laboratory" "$T" >/dev/null
LAB_AFTER="$(q "SELECT (SELECT COUNT(*) FROM instruments)||'/'||(SELECT COUNT(*) FROM prices)||'/'||(SELECT COUNT(*) FROM transactions)||'/'||(SELECT COUNT(*) FROM cash_movements)||'/'||(SELECT COUNT(*) FROM settings)")"
[ "$LAB_BEFORE" = "$LAB_AFTER" ] \
  && echo "  ok   實驗室空狀態頁與拒絕的 POST 都沒有改動正式表格" \
  || { echo "  FAIL laboratory wrote data: $LAB_BEFORE -> $LAB_AFTER"; FAIL=1; }
[ "$(q "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'lab_%'")" = "0" ] \
  && echo "  ok   實驗室沒有 lab_* 資料表" \
  || { echo "  FAIL a lab_ table exists"; FAIL=1; }
[ "$(q "SELECT COUNT(*) FROM settings WHERE substr(key,1,4) = 'lab_'")" = "0" ] \
  && echo "  ok   實驗室沒有 lab_* 設定" \
  || { echo "  FAIL a lab_ setting exists"; FAIL=1; }

rm -f "$WORK/anon-strategy.txt"
R=$(curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$WORK/anon-strategy.txt" -b "$WORK/anon-strategy.txt" "$BASE/strategy")
[ "$R" = "303 ${BASE}/login?back=%2Fstrategy" ] \
  && echo "  ok   /strategy 跟其他正式頁面一樣要先登入" || { echo "  FAIL $R"; FAIL=1; }

MATH=$("$PHPBIN" -d display_errors=stderr tests/lehuo-math.php 2>&1 | tr '\n' '~')
case "$MATH" in
  *"ALL PASS"*) echo "  ok   算式自己對得上（視窗・斜率按天・R² 三檔・五個區間・通道）" ;;
  *) echo "  FAIL 算式驗不過：$MATH"; FAIL=1 ;;
esac

execq "$DB" "DELETE FROM settings WHERE substr(key,1,4) = 'lab_';"
get "/settings" "$WORK/settings-strategy.html" >/dev/null
check "導覽列在設定下方提供交易策略下拉選單" 'href="/index.php/settings"' "$WORK/settings-strategy.html"
SETTINGS_NAV=$(grep -n 'href="/index.php/settings"' "$WORK/settings-strategy.html" | head -1 | cut -d: -f1)
STRATEGY_NAV=$(grep -n 'href="/index.php/strategy"' "$WORK/settings-strategy.html" | head -1 | cut -d: -f1)
[ -n "$SETTINGS_NAV" ] && [ -n "$STRATEGY_NAV" ] && [ "$SETTINGS_NAV" -lt "$STRATEGY_NAV" ] \
  && echo "  ok   樂活五線譜連結排在設定之後" \
  || { echo "  FAIL strategy nav order: settings=$SETTINGS_NAV strategy=$STRATEGY_NAV"; FAIL=1; }
notcheck "設定頁不再留實驗室入口" "進入實驗室" "$WORK/settings-strategy.html"
notcheck "導覽列不再顯示實驗室" 'href="/index.php/lab"' "$WORK/settings-strategy.html"

cat > "$WORK/lehuo-fixture.php" <<'PHP'
<?php
$pdo = new PDO('sqlite:' . $argv[1]);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("INSERT INTO instruments (symbol, name, market, yahoo_symbol, currency) VALUES ('LHFT', '樂活圖表測試', 'US', 'LHFT', 'USD')");
$id = (int) $pdo->lastInsertId();
$dates = [];
$day = new DateTimeImmutable($argv[2]);
while (count($dates) < 320) {
    if ((int) $day->format('N') < 6) {
        $dates[] = $day->format('Y-m-d');
    }
    $day = $day->modify('-1 day');
}
sort($dates);
$insert = $pdo->prepare('INSERT INTO prices (instrument_id, as_of, close, prev_close, adj_close, currency, source) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($dates as $i => $date) {
    $close = 100 + $i * 0.05 + sin($i / 9) * 2;
    $insert->execute([$id, $date, $close, $close, $close, 'USD', 'manual']);
}
PHP
"$PHPBIN" "$WORK/lehuo-fixture.php" "$DB" "$TODAY"
LHID=$(q "SELECT id FROM instruments WHERE yahoo_symbol='LHFT'")
NINST=$(q "SELECT COUNT(*) FROM instruments")

CODE=$(curl -s -D "$WORK/chart-ready-head.txt" -o "$WORK/chart-ready.json" -w '%{http_code}' \
  -b "$JAR" -c "$JAR" "$BASE/strategy/$LHID/chart")
CHART_READY=$("$PHPBIN" -r '$j = json_decode((string) file_get_contents($argv[1]), true); $s = $j["spectrumSeries"] ?? null; $c = $j["channelSeries"] ?? null; echo is_array($s) && is_array($c) && count($s["dates"] ?? []) >= 250 && count($s["prices"] ?? []) >= 250 && count($s["trend"] ?? []) >= 250 && count($s["u1"] ?? []) >= 250 && count($s["u2"] ?? []) >= 250 && count($s["d1"] ?? []) >= 250 && count($s["d2"] ?? []) >= 250 && count($c["weeks"] ?? []) >= 20 && count($c["mid"] ?? []) >= 20 && count($c["upper"] ?? []) >= 20 && count($c["lower"] ?? []) >= 20 ? "yes" : "no";' "$WORK/chart-ready.json" 2>/dev/null)
[ "$CODE" = "200" ] && [ "$CHART_READY" = "yes" ] \
  && grep -qi '^Content-Type: application/json' "$WORK/chart-ready-head.txt" \
  && echo "  ok   320 根日線的投資物回傳兩張非空圖表數列" \
  || { echo "  FAIL 充足資料圖表 code=$CODE ready=$CHART_READY"; head -c 500 "$WORK/chart-ready.json"; echo; FAIL=1; }
CODE=$(curl -s -D "$WORK/chart-bad-head.txt" -o "$WORK/chart-bad.json" -w '%{http_code}' \
  -b "$JAR" -c "$JAR" "$BASE/strategy/abc/chart")
[ "$CODE" = "400" ] && grep -qi '^Content-Type: application/json' "$WORK/chart-bad-head.txt" \
  && echo "  ok   圖表 id=abc 回 400 JSON" || { echo "  FAIL id=abc 給 $CODE"; FAIL=1; }
CODE=$(curl -s -o "$WORK/chart-missing.json" -w '%{http_code}' -b "$JAR" -c "$JAR" "$BASE/strategy/999999/chart")
[ "$CODE" = "404" ] && echo "  ok   圖表未知投資物回 404 JSON" || { echo "  FAIL 未知投資物給 $CODE"; FAIL=1; }

BEFORE="$(q "SELECT (SELECT COUNT(*) FROM instruments)||'/'||(SELECT COUNT(*) FROM prices)||'/'||(SELECT COUNT(*) FROM transactions)||'/'||(SELECT COUNT(*) FROM cash_movements)||'/'||(SELECT COUNT(*) FROM settings)")"
CODE=$(get "/strategy" "$WORK/strategy.html")
[ "$CODE" = "200" ] && echo "  ok   沒有任何實驗開關時 /strategy 也打得開" || { echo "  FAIL it answered $CODE"; FAIL=1; }
check "這一頁交代自己算的是什麼" "這頁在算什麼" "$WORK/strategy.html"
check "也交代哪些事它做不來" "做不來的部分" "$WORK/strategy.html"
check "並且明說這不是投資建議" "這不是投資建議" "$WORK/strategy.html"
check "頁首名稱是樂活五線譜" "<h2>樂活五線譜</h2>" "$WORK/strategy.html"
check "在樂活五線譜頁時交易策略選單自動展開" '<details class="nav-dropdown" open>' "$WORK/strategy.html"
notcheck "頁面不再自稱實驗功能" "實驗功能" "$WORK/strategy.html"
notcheck "頁面沒有回舊實驗室的失效連結" 'href="/index.php/lab"' "$WORK/strategy.html"
SPEC_ROWS=$(awk '/id="spectrum-table"/,/<\/table>/' "$WORK/strategy.html" | grep -c "<tr")
LINE_ROWS=$(awk '/id="line-table"/,/<\/table>/' "$WORK/strategy.html" | grep -c "<tr")
[ "$SPEC_ROWS" = "$((NINST + 1))" ] && [ "$LINE_ROWS" = "$((NINST + 1))" ] \
  && echo "  ok   兩張表各一列一檔（$NINST 檔＋表頭）" \
  || { echo "  FAIL 表格列數對不上：spectrum=$SPEC_ROWS lines=$LINE_ROWS want=$((NINST + 1))/$((NINST + 1))"; FAIL=1; }
checkf "判讀表有區間與動作兩欄" ">區間</th><th class=\"left\">動作</th>" "$WORK/strategy.html"
checkf "數字表有五條線與通道" ">＋2SD</th><th>＋1SD</th><th>趨勢線</th>" "$WORK/strategy.html"
checkf "通道那三欄寫明窗口是 20 週" ">20 週上軌</th><th>20 週中軸</th><th>20 週下軌</th>" "$WORK/strategy.html"
check "通道自報是布林，不是自己發明參數" "20 週布林通道" "$WORK/strategy.html"
checkf "通道那段說明寫明標準差除的是 20（母數）" "2 倍母數標準差" "$WORK/strategy.html"
checkf "偏向那一欄的欄名是週線，不是短線" '<th class="left">週線偏向</th>' "$WORK/strategy.html"
checkf "說明寫明偏向比的是 2 週與 19 週均線" "2 週與 19 週均線" "$WORK/strategy.html"
notcheckf "不再宣稱通道取全期歷史" "通道取自<strong>全期歷史" "$WORK/strategy.html"
notcheckf "算不了的格子都自帶對齊" "<td>--</td>" "$WORK/strategy.html"
checkf "數字欄與文字欄交界處補了欄距" '<th class="center col-gap">通道位置</th>' "$WORK/strategy.html"
checkf "投資物那欄維持靠左，只有徽章欄置中" '<th class="left">投資物</th>' "$WORK/strategy.html"
E=$(grep -c -i -E "PHP (Warning|Fatal error|Parse error|Deprecated):|Undefined (array key|property|variable)" "$WORK/strategy.html")
[ "$E" = "0" ] && echo "  ok   這一頁渲染時沒有任何 PHP notice" || { echo "  FAIL strategy page has $E notices"; grep -i -E "PHP (Warning|Fatal error|Parse error|Deprecated):|Undefined (array key|property|variable)" "$WORK/strategy.html" | head -3; FAIL=1; }

AFTER="$(q "SELECT (SELECT COUNT(*) FROM instruments)||'/'||(SELECT COUNT(*) FROM prices)||'/'||(SELECT COUNT(*) FROM transactions)||'/'||(SELECT COUNT(*) FROM cash_movements)||'/'||(SELECT COUNT(*) FROM settings)")"
[ "$BEFORE" = "$AFTER" ] \
  && echo "  ok   正式策略頁只讀，正式表格一筆都沒被變動" \
  || { echo "  FAIL the read-only page wrote data: $BEFORE -> $AFTER"; FAIL=1; }
[ "$(q "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'lab_%'")" = "0" ] \
  && echo "  ok   移除實驗功能後資料庫沒有 lab_* 資料表" \
  || { echo "  FAIL a lab_ table survived"; FAIL=1; }
[ "$(q "SELECT value FROM settings WHERE key='schema_version'")" = "9" ] \
  && echo "  ok   資料庫已遷移到 schema v9" \
  || { echo "  FAIL schema version is not 9"; FAIL=1; }

execq "$DB" "DELETE FROM prices WHERE instrument_id IN (SELECT id FROM instruments WHERE yahoo_symbol = 'LHFT');
  DELETE FROM instruments WHERE yahoo_symbol = 'LHFT';"
[ "$(q "SELECT COUNT(*) FROM instruments WHERE yahoo_symbol = 'LHFT'")" = "0" ] \
  && echo "  ok   這一節的合成投資物收乾淨了" \
  || { echo "  FAIL LHFT survived"; FAIL=1; }

echo
PERF=$("$PHPBIN" -d display_errors=stderr tests/performance-chain-math.php 2>&1 | tr '\n' '~')
case "$PERF" in
  *"ALL PASS"*) echo "  ok   績效鏈結算式驗算通過" ;;
  *) echo "  FAIL 績效鏈結算式驗算失敗：$PERF"; FAIL=1 ;;
esac
COST=$("$PHPBIN" -d display_errors=stderr tests/positions-cost-math.php 2>&1 | tr '\n' '~')
case "$COST" in
  *"ALL PASS"*) echo "  ok   持股成本池算式驗算通過" ;;
  *) echo "  FAIL 持股成本池算式驗算失敗：$COST"; FAIL=1 ;;
esac
QUOTE_CURRENCY=$("$PHPBIN" -d display_errors=stderr tests/quote-currency-math.php 2>&1 | tr '\n' '~')
case "$QUOTE_CURRENCY" in
  *"QUOTE CURRENCY ALL PASS"*) echo "  ok   報價幣別不一致時拒絕寫入" ;;
  *) echo "  FAIL 報價幣別防護驗算失敗：$QUOTE_CURRENCY"; FAIL=1 ;;
esac
BACKUP_SAFETY=$("$PHPBIN" -d display_errors=stderr tests/backup-restore-safety.php 2>&1 | tr '\n' '~')
case "$BACKUP_SAFETY" in
  *"BACKUP SAFETY ALL PASS"*) echo "  ok   惡意備份與本機報價政策防護通過" ;;
  *) echo "  FAIL 備份還原防護驗算失敗：$BACKUP_SAFETY"; FAIL=1 ;;
esac
AUTH_RACE=$("$PHPBIN" -d display_errors=stderr tests/auth-throttle-race.php 2>&1 | tr '\n' '~')
case "$AUTH_RACE" in
  *"AUTH THROTTLE RACE ALL PASS"*) echo "  ok   併發登入嘗試不會突破鎖定額度" ;;
  *) echo "  FAIL 登入節流併發驗算失敗：$AUTH_RACE"; FAIL=1 ;;
esac
MIGRATION=$("$PHPBIN" -d display_errors=stderr tests/database-migration.php 2>&1 | tr '\n' '~')
case "$MIGRATION" in
  *"MIGRATION ALL PASS"*) echo "  ok   schema v2 遷移會補欄並清除違規舊價格" ;;
  *) echo "  FAIL 舊資料庫遷移驗算失敗：$MIGRATION"; FAIL=1 ;;
esac
CLI_SAFETY=$("$PHPBIN" -d display_errors=stderr tests/cli-safety.php 2>&1 | tr '\n' '~')
case "$CLI_SAFETY" in
  *"CLI SAFETY ALL PASS"*) echo "  ok   seed 所有權、rollback 與 backfill dry-run 防護通過" ;;
  *) echo "  FAIL CLI 安全驗算失敗：$CLI_SAFETY"; FAIL=1 ;;
esac

echo
if [ "$FAIL" -ne 0 ]; then
  echo "SOME CHECKS FAILED（有檢查失敗）"
elif [ "$SKIP" -gt 0 ]; then
  echo "PASS WITH SKIPS（略過 $SKIP 節，不視為 ALL PASS）"
  for item in "${SKIPS[@]}"; do echo "  SKIP $item"; done
else
  echo "ALL PASS"
fi
exit "$FAIL"
