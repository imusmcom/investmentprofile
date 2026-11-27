#!/usr/bin/env bash
# 用 /tmp 的獨立資料庫啟動伺服器並跑 smoke；不可指向正式資料。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2
PHPBIN="${PHP_BIN:-php}"
SCRATCH=""
LOG=""
SERVER=""

cleanup() {
    local rc=$?
    trap - EXIT INT TERM
    if [ -n "$SERVER" ]; then
        kill "$SERVER" 2>/dev/null || true
        wait "$SERVER" 2>/dev/null || true
    fi
    if [ "$FAILED" = "1" ] && [ -n "$LOG" ] && [ -f "$LOG" ]; then
        echo "---- 伺服器記錄（$LOG）----" >&2
        tail -n 120 "$LOG" >&2 || true
    fi
    if [ "${SMOKE_DEBUG_KEEP:-0}" = "1" ]; then
        echo "除錯保留伺服器記錄：$LOG" >&2
        echo "除錯保留測試資料：$SCRATCH" >&2
    else
        [ -z "$LOG" ] || rm -f "$LOG"
        [ -z "$SCRATCH" ] || rm -rf "$SCRATCH"
    fi
    exit "$rc"
}
FAILED=0
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

command -v "$PHPBIN" >/dev/null 2>&1 || { echo "找不到 PHP：$PHPBIN" >&2; FAILED=1; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "找不到 curl：smoke 就緒檢查需要它。" >&2; FAILED=1; exit 1; }
SCRATCH="$(mktemp -d /tmp/portfolio-smoke.XXXXXX)"
DATA_DIR="$SCRATCH/data"
DB="$DATA_DIR/portfolio.sqlite"
LOG="$(mktemp /tmp/portfolio-smoke-server.XXXXXX.log)"
mkdir -p "$DATA_DIR" "$SCRATCH/sessions"
chmod 700 "$SCRATCH/sessions"

if [ -n "${PORT:-}" ]; then
    [[ "$PORT" =~ ^[0-9]{1,5}$ ]] && [ "$((10#$PORT))" -ge 1 ] && [ "$((10#$PORT))" -le 65535 ] || {
        echo "PORT 必須是 1–65535，實際：$PORT" >&2; FAILED=1; exit 2;
    }
    PORT="$((10#$PORT))"
    "$PHPBIN" -r '$p = @stream_socket_server("tcp://127.0.0.1:" . $argv[1], $errno, $errstr); if (!$p) { fwrite(STDERR, "PORT 已被占用或無法綁定：$errstr\n"); exit(1); } fclose($p);' "$PORT" || {
        FAILED=1; exit 2;
    }
else
    PORT="$("$PHPBIN" -r '$p = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$p) { fwrite(STDERR, "無法取得臨時埠：$errstr\n"); exit(1); } $n = stream_socket_get_name($p, false); fclose($p); echo substr(strrchr($n, ":"), 1);')"
fi
BASE="http://127.0.0.1:$PORT/index.php"

TMPDIR="$SCRATCH" PORTFOLIO_DATA_DIR="$DATA_DIR" PORTFOLIO_QUOTE_STUB_HOST="example.com" \
    "$PHPBIN" -d session.save_path="$SCRATCH/sessions" -d upload_tmp_dir="$SCRATCH" \
    -d sys_temp_dir="$SCRATCH" -d auto_prepend_file="$ROOT/tests/quote-transport.php" \
    -S "127.0.0.1:$PORT" -t "$ROOT/public" >"$LOG" 2>&1 &
SERVER=$!

READY=0
BODY="$SCRATCH/login.html"
HEADERS="$SCRATCH/login.headers"
for _ in $(seq 1 80); do
    if ! kill -0 "$SERVER" 2>/dev/null; then
        echo "伺服器在就緒前結束（埠 $PORT）。" >&2
        FAILED=1
        exit 1
    fi
    CODE="$(curl -sS --max-time 2 -D "$HEADERS" -o "$BODY" -w '%{http_code}' "$BASE/login" 2>/dev/null || true)"
    if [ "$CODE" = "200" ] && grep -qF '投資組合追蹤系統' "$BODY" 2>/dev/null \
        && grep -qiE '^X-Content-Type-Options:[[:space:]]*nosniff' "$HEADERS" 2>/dev/null; then
        READY=1
        break
    fi
    sleep 0.1
done
if [ "$READY" != "1" ]; then
    echo "就緒檢查失敗：回應必須是 200，且包含本系統標題與 nosniff 標頭。" >&2
    FAILED=1
    exit 1
fi

echo "smoke 使用臨時資料庫：$DB"
echo "smoke 使用本機位址：$BASE"
echo "deploy-auto harness（合成快照，不讀正式資料）"
set +e
SNAPSHOT_SRC= TMPDIR=/tmp PHP_BIN="$PHPBIN" bash "$ROOT/tests/deploy-auto-harness.sh"
RC=$?
set -e
[ "$RC" -eq 0 ] || FAILED=1
[ "$RC" -eq 0 ] || exit "$RC"

set +e
DB="$DB" PORTFOLIO_DATA_DIR="$DATA_DIR" BASE="$BASE" PHP_BIN="$PHPBIN" \
    PORTFOLIO_QUOTE_STUB_HOST="example.com" PORTFOLIO_QUOTE_TEST_TRANSPORT="$ROOT/tests/quote-transport.php" \
    bash "$ROOT/tests/smoke.sh"
RC=$?
set -e
[ "$RC" -eq 0 ] || FAILED=1
exit "$RC"
