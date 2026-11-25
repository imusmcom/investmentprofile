#!/usr/bin/env bash
# 把「投資組合追蹤系統」裝到寶塔（BT）VPS 的檢查與安裝腳本。
#
#   bash deploy-bt.sh                 只檢查，不寫入任何東西（預設）
#   bash deploy-bt.sh --apply         進行寫入：放資料庫、改 data/ 權限、跑一次真實更新
#   bash deploy-bt.sh --auto --domain=https://你的域名
#                                     一條指令裝完：上面那些 + 把片段放進寶塔的站台設定
#                                     （能用面板留的 extension 目錄就放那裡、主設定一個字不改；
#                                       既有同名片段先備份，不然先留原檔再插進去。nginx -t 通過才 reload，不通自動還原。）
#
# 腳本不簽憑證、也不碰面板收斂，那兩件事在活的 VPS 上不可逆，而且面板比較清楚。
# 檢查模式只把 nginx 片段印出來；--apply 寫成檔案；--auto（或 --write-nginx）才改你的設定。
#
# 可用參數：
#   --site-dir=PATH     專案在那台機器上的位置（預設 /www/wwwroot/portfolio）
#   --php-bin=PATH      要用哪個 PHP（預設自動找 /www/server/php/83|82|81/bin/php，否則 php）
#   --data-dir=PATH     資料目錄（正式路徑預設 /www/portfolio-data；其他部署預設 <site-dir>/data；相對路徑以 <site-dir> 為基準）
#   --user=NAME         資料目錄的擁有者／php-fpm 執行帳號（預設先找 www，再找 www-data）
#   --socket=NAME       php-fpm 的 socket（預設 unix:/tmp/php-cgi-<version>.sock）
#   --snapshot=FILE     要當成資料庫搬過去的那份 SQLite（預設找 <data-dir>/import.sqlite）
#   --domain=URL        已簽好憑證時拿來做線上自查，例如 https://portfolio.example.com
#   --conf=PATH         寶塔那份站台設定（預設依 --domain 的域名或 --site-dir 自動找）
#   --root-dir=PATH     網頁根應該是哪裡（預設 <site-dir>/public，不符就一起改）
#   --write-nginx       把片段插進站台設定（含備份、驗證、失敗還原）
#   --force-db          <data-dir>/portfolio.sqlite 已存在時也覆蓋（覆蓋前先留一份）
#   --skip-net          不跑會連網路的那次更新
#   --help

set -euo pipefail

SITE_DIR="/www/wwwroot/portfolio"
PHP_BIN="${PHP_BIN:-php}"
DATA_DIR=""
DATA_DIR_GIVEN=0
WWW_USER=""
SNAPSHOT=""
DOMAIN=""
DOMAIN_GIVEN=0
SOCK=""
CONF=""
ROOT_DIR=""
MODE="check"
WRITE_NGINX=0
FORCE_DB=0
SKIP_NET=0
FAILS=0
WARNS=0
PHP_VER_SHORT="83"
PATCHED_CONF=0
CONF_BACKUP=""
CONF_PATH=""
PATCH_MODE=""
PATCH_FILE=""
PATCH_FILE_BACKUP=""
PATCH_FILE_EXISTED=0
EXT_DIR=""
RELOADED=0
ROLLBACK_NOTE=""

for arg in "$@"; do
    case "$arg" in
        --apply) MODE="apply" ;;
        --check) MODE="check" ;;
        --auto) MODE="apply"; WRITE_NGINX=1 ;;
        --write-nginx) MODE="apply"; WRITE_NGINX=1 ;;
        --force-db) FORCE_DB=1 ;;
        --skip-net) SKIP_NET=1 ;;
        --site-dir=*) SITE_DIR="${arg#*=}" ;;
        --php-bin=*) PHP_BIN="${arg#*=}" ;;
        --data-dir=*) DATA_DIR="${arg#*=}"; DATA_DIR_GIVEN=1 ;;
        --user=*) WWW_USER="${arg#*=}" ;;
        --socket=*) SOCK="${arg#*=}" ;;
        --conf=*) CONF="${arg#*=}" ;;
        --root-dir=*) ROOT_DIR="${arg#*=}" ;;
        --snapshot=*) SNAPSHOT="${arg#*=}" ;;
        --domain=*) DOMAIN="${arg#*=}"; DOMAIN_GIVEN=1 ;;
        --help | -h)
            sed -n '2,27p' "$0"
            exit 0
            ;;
        *)
            echo "不懂這個參數：$arg（用 --help 看用法）" >&2
            exit 2
            ;;
    esac
done

SITE_DIR="${SITE_DIR%/}"
if [ -z "$SITE_DIR" ]; then
    echo "--site-dir 不能是空值" >&2
    exit 2
fi
if [ -z "$DATA_DIR" ]; then
    if [ "$DATA_DIR_GIVEN" = "1" ]; then
        echo "--data-dir 不能是空值" >&2
        exit 2
    fi
    if [ "$SITE_DIR" = "/www/wwwroot/portfolio" ] && [ -f /www/portfolio-data/portfolio.sqlite ]; then
        DATA_DIR="/www/portfolio-data"
    else
        DATA_DIR="$SITE_DIR/data"
    fi
fi
case "$DATA_DIR" in
    /*) ;;
    *) DATA_DIR="$SITE_DIR/$DATA_DIR" ;;
esac
NGINX_FRAGMENT="$SITE_DIR/deploy-portfolio.conf"
# 片段第一行帶著這個字串，重複跑時用它辨認「這段是腳本放的」，才不會插第二次。
FRAGMENT_TAG="deploy-bt.sh 放的部署片段"

validate_domain() {
    local port=""
    if [[ "$DOMAIN" =~ ^https://([A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])(:[0-9]{1,5})?(/[^[:space:]?#]*)?$ ]]; then
        port="${BASH_REMATCH[3]:-}"
        port="${port#:}"
    else
        echo "--domain 必須是完整 HTTPS 網址，例如 https://portfolio.example.com；不接受空值、其他協定、file:// 或開頭是 - 的值" >&2
        return 1
    fi
    if [ -n "$port" ] && { [ "$((10#$port))" -lt 1 ] || [ "$((10#$port))" -gt 65535 ]; }; then
        echo "--domain 的連接埠必須介於 1 到 65535" >&2
        return 1
    fi
    DOMAIN="${DOMAIN%/}"
}

if [ "$DOMAIN_GIVEN" = "1" ]; then
    validate_domain || exit 2
fi

ok()   { echo "  ok   $*"; }
bad()  { echo "  FAIL $*"; FAILS=$((FAILS + 1)); }
warn() { echo "  注意 $*"; WARNS=$((WARNS + 1)); }
skip() { echo "  跳過 $*"; }
do_it() { [ "$MODE" = "apply" ]; }

if do_it; then
    if [ "$WRITE_NGINX" = "1" ]; then
        echo "模式：auto（會寫入：搬資料庫、chown/chmod 資料目錄、把片段放進 nginx 的站台設定、nginx -t 通過才 reload、跑一次真實更新）"
    else
        echo "模式：apply（會寫入：搬資料庫、chown/chmod 資料目錄、產生 nginx 片段檔、跑一次真實更新；nginx 的設定不碰）"
    fi
else
    echo "模式：check（只讀不寫；要真的動手就加 --apply，想一條指令裝完就加 --auto）"
fi
echo "專案位置：$SITE_DIR"
echo "資料目錄：$DATA_DIR"

# ---------- 1. PHP ----------
echo "== 1. PHP =="
if [ -z "$PHP_BIN" ]; then
    for cand in /www/server/php/83/bin/php /www/server/php/82/bin/php /www/server/php/81/bin/php php; do
        if command -v "$cand" >/dev/null 2>&1; then PHP_BIN="$cand"; break; fi
    done
fi

PHP_OK=0
if [ -z "$PHP_BIN" ] || ! "$PHP_BIN" -v >/dev/null 2>&1; then
    bad "找不到可用的 PHP。裝好 PHP-8.3 之後用 --php-bin=/www/server/php/83/bin/php 再跑一次。"
else
    PHP_OK=1
    PHP_V="$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null)"
    PHP_VER_MAJOR="${PHP_V%%.*}"
    PHP_VER_MINOR="${PHP_V#*.}"
    PHP_VER_SHORT="${PHP_VER_MAJOR}${PHP_VER_MINOR%%.*}"
    ok "用 $PHP_BIN（PHP $PHP_V）"
    if "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.1.0", "<") ? 1 : 0);' >/dev/null 2>&1; then
        ok "版本符合 8.1 以上"
    else
        bad "PHP $PHP_V 太舊，這套系統要 8.1 以上（用到 str_starts_with 等 8.0+ 語法）"
    fi

    for ext in pdo_sqlite mbstring; do
        if "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1; then
            ok "必要擴展 $ext 已載入"
        else
            bad "缺少必要擴展 $ext — 頁面會直接當給你看，不是白版就是 500"
        fi
    done

    for ext in curl json session; do
        if "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1; then
            ok "附加擴展 $ext 已載入"
        else
            warn "沒有 $ext。缺 curl 時報價改走 allow_url_fopen；json 與 session 不該缺。"
        fi
    done

    if [ "$("$PHP_BIN" -r 'echo filter_var(ini_get("allow_url_fopen"), FILTER_VALIDATE_BOOLEAN) ? "off" : "on";' 2>/dev/null)" = "off" ] \
        && ! "$PHP_BIN" -r "exit(extension_loaded('curl') ? 0 : 1);" >/dev/null 2>&1; then
        bad "curl 沒有、allow_url_fopen 也關著：自動報價兩條路都走不通（要嘛裝 curl，要嘛打開 allow_url_fopen）"
    fi
fi

# ---------- 2. 目錄結構 ----------
echo "== 2. 目錄 =="
TREE_OK=0
if [ ! -d "$SITE_DIR" ]; then
    bad "目錄不存在：$SITE_DIR（先把專案上傳到那裡，或用 --site-dir= 指對）"
else
    TREE_OK=1
    for f in public/index.php app/App.php app/bootstrap.php app/schema.sql bin/refresh-prices.php bin/create-admin.php; do
        if [ -f "$SITE_DIR/$f" ]; then
            ok "$f 在"
        else
            bad "少了 $f — 上傳不完整？"
        fi
    done

    if [ -f "$SITE_DIR/index.php" ]; then
        warn "$SITE_DIR 底下直接有 index.php：專案根本來沒有這個檔，可能是把網頁根指到專案根、或上傳時多解了一層"
    fi

    if [ -f "$DATA_DIR/portfolio.sqlite" ]; then
        ok "資料庫已在定位：$DATA_DIR/portfolio.sqlite"
    elif [ -f "$DATA_DIR/import.sqlite" ]; then
        ok "待搬的資料庫找到：$DATA_DIR/import.sqlite"
    elif [ -n "$SNAPSHOT" ]; then
        if [ -f "$SNAPSHOT" ]; then
            ok "指定的快照存在：$SNAPSHOT"
        else
            bad "--snapshot 指到的檔案不存在：$SNAPSHOT"
        fi
    else
        warn "$DATA_DIR 裡還沒有 portfolio.sqlite，也沒有 import.sqlite：VPS 要直接有你那些交易，就得先把本機那份（或快照）搬過來"
    fi
fi

# ---------- 3. 搬資料庫與內容自證 ----------
echo "== 3. 資料庫 =="
TARGET_DB="$DATA_DIR/portfolio.sqlite"
BACKUP_DIR="$DATA_DIR/backups"
SRC_DB=""

# 不用 cp 搬 SQLite：主檔旁邊可能還有尚未 checkpoint 的 -wal，直接複製主檔會漏掉最後的交易。
# 新檔先寫同目錄暫存檔再原子改名；既有庫直接由 SQLite backup API 覆寫，絕不拔掉執行中連線的 WAL/SHM。
sqlite_backup() {
    local src="$1" dest="$2" tmp="${2}.backup-$$"
    local target="$tmp" direct=0
    if [ ! -f "$src" ]; then
        echo "SQLite 備份失敗：來源不存在或不是檔案：$src" >&2
        return 1
    fi
    if [ ! -d "$(dirname "$dest")" ]; then
        echo "SQLite 備份失敗：目的地目錄不存在：$(dirname "$dest")" >&2
        return 1
    fi
    if [ "$src" -ef "$dest" ]; then
        echo "SQLite 備份失敗：來源與目的地是同一個檔案：$src" >&2
        return 1
    fi
    if [ -e "$tmp" ] || [ -L "$tmp" ]; then
        echo "SQLite 備份失敗：暫存檔已存在：$tmp" >&2
        return 1
    fi
    if [ "$PHP_OK" != "1" ]; then
        echo "SQLite 備份失敗：需要可用的 PHP 與 PDO SQLite，不能用 cp 代替" >&2
        return 1
    fi

    if [ -e "$dest" ] || [ -L "$dest" ]; then
        if [ ! -f "$dest" ] || [ -L "$dest" ]; then
            echo "SQLite 備份失敗：目的地不是一般檔案，拒絕覆蓋：$dest" >&2
            return 1
        fi
        direct=1
        target="$dest"
    elif [ -e "$dest-wal" ] || [ -L "$dest-wal" ] || [ -e "$dest-shm" ] || [ -L "$dest-shm" ]; then
        echo "SQLite 備份失敗：主檔不存在卻殘留 WAL/SHM，拒絕猜測並刪除：$dest" >&2
        return 1
    fi

    if "$PHP_BIN" -r 'exit(extension_loaded("sqlite3") ? 0 : 1);' >/dev/null 2>&1; then
        if ! (umask 077; "$PHP_BIN" -r '
            try {
                $source = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
                $source->enableExceptions(true);
                $source->busyTimeout(10000);
                $target = new SQLite3($argv[2]);
                $target->enableExceptions(true);
                $target->busyTimeout(10000);
                if (!$source->backup($target)) {
                    throw new RuntimeException("SQLite3::backup 回傳失敗");
                }
                if ($target->querySingle("PRAGMA integrity_check") !== "ok") {
                    throw new RuntimeException("備份檔 integrity_check 不是 ok");
                }
                $target->close();
                $source->close();
            } catch (Throwable $e) {
                fwrite(STDERR, "SQLite 備份失敗：" . $e->getMessage() . "\n");
                exit(1);
            }
        ' "$src" "$target"); then
            [ "$direct" = "1" ] || rm -f -- "$tmp"
            return 1
        fi
    else
        if [ "$direct" = "1" ]; then
            echo "SQLite 備份失敗：這台沒有 sqlite3 擴展，拒絕用原子替換覆蓋既有資料庫；請安裝 sqlite3 或先停止網站" >&2
            return 1
        fi
        # 沒有 sqlite3 擴展時，PDO 的 VACUUM INTO 同樣從 SQLite 的一致讀取交易產生完整新檔。
        if ! (umask 077; "$PHP_BIN" -r '
            try {
                $source = new PDO("sqlite:" . $argv[1], null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 10,
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
                ]);
                if (file_exists($argv[2])) {
                    throw new RuntimeException("備份暫存檔已經存在");
                }
                $source->exec("VACUUM INTO " . $source->quote($argv[2]));
                $target = new PDO("sqlite:" . $argv[2], null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                if ((string) $target->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
                    throw new RuntimeException("備份檔 integrity_check 不是 ok");
                }
                $target = null;
                $source = null;
            } catch (Throwable $e) {
                fwrite(STDERR, "SQLite 備份失敗：" . $e->getMessage() . "\n");
                exit(1);
            }
        ' "$src" "$target"); then
            rm -f -- "$tmp"
            return 1
        fi
    fi

    if [ "$direct" = "1" ]; then
        return 0
    fi
    if mv -- "$tmp" "$dest"; then
        return 0
    fi
    rm -f -- "$tmp"
    echo "SQLite 備份失敗：無法把完整快照放到 $dest" >&2
    return 1
}

if [ "$TREE_OK" = "1" ]; then
    if [ -n "$SNAPSHOT" ]; then
        SRC_DB="$SNAPSHOT"
    elif [ -f "$DATA_DIR/import.sqlite" ]; then
        SRC_DB="$DATA_DIR/import.sqlite"
    fi

    if [ -f "$TARGET_DB" ] && [ -n "$SRC_DB" ] && [ "$FORCE_DB" = "0" ]; then
        warn "$TARGET_DB 已存在，所以不搬 $SRC_DB（要覆蓋就加 --force-db；覆蓋前會先留一份）"
        SRC_DB=""
    fi

    if [ -n "$SRC_DB" ]; then
        if [ ! -f "$SRC_DB" ]; then
            bad "資料庫來源不存在或不是檔案：$SRC_DB"
            SRC_DB=""
        fi
    fi

    if [ -n "$SRC_DB" ]; then
        if do_it; then
            if [ -f "$TARGET_DB" ]; then
                if [ -L "$BACKUP_DIR" ]; then
                    bad "備份目錄是符號連結，拒絕把舊檔備份到別處：$BACKUP_DIR"
                    SRC_DB=""
                elif ! mkdir -p -- "$BACKUP_DIR"; then
                    bad "建不了備份目錄，因此不覆蓋 $TARGET_DB"
                    SRC_DB=""
                else
                    KEEP="$BACKUP_DIR/replaced-$(date +%Y%m%d-%H%M%S).sqlite"
                    if sqlite_backup "$TARGET_DB" "$KEEP"; then
                        if chmod 600 -- "$KEEP"; then
                            ok "舊檔先留成 $KEEP（含 WAL 的一致快照，權限 600）"
                        else
                            bad "舊檔備份權限設不成 600，因此不覆蓋 $TARGET_DB"
                            SRC_DB=""
                        fi
                    else
                        bad "舊檔留不下，因此不覆蓋 $TARGET_DB"
                        SRC_DB=""
                    fi
                fi
            fi
            if [ -n "$SRC_DB" ] && sqlite_backup "$SRC_DB" "$TARGET_DB"; then
                ok "已把 $SRC_DB 放成 $TARGET_DB（含 WAL 的一致快照）"
            elif [ -n "$SRC_DB" ]; then
                bad "資料庫搬過去失敗"
            fi
        else
            skip "搬資料庫（檢查模式不寫入；--apply 才會把 $SRC_DB 放成 $TARGET_DB）"
        fi
    fi

    CHECK_DB="$TARGET_DB"
    if [ ! -f "$CHECK_DB" ] && [ -n "$SRC_DB" ] && [ -f "$SRC_DB" ]; then
        CHECK_DB="$SRC_DB"
    fi

    if [ -f "$CHECK_DB" ] && [ "$PHP_OK" = "1" ]; then
        "$PHP_BIN" -r '
            $f = realpath($argv[1]) ?: $argv[1];
            try {
                // A normal PDO open is read/write and can create -wal/-shm even in check mode.
                // Use immutable mode when no WAL is present; with a live WAL, require its existing
                // SHM and use the real SQLite read-only open flag rather than creating missing files.
                $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
                $dsn = "sqlite:" . $f;
                $wal = $f . "-wal";
                if (!file_exists($wal) || (int) filesize($wal) === 0) {
                    $uriPath = str_replace(["%", "?", "#"], ["%25", "%3F", "%23"], $f);
                    $dsn = "sqlite:file:" . $uriPath . "?mode=ro&immutable=1";
                } else {
                    if (!file_exists($f . "-shm")) {
                        throw new RuntimeException("WAL 存在但 SHM 不存在；唯讀檢查拒絕建立 SHM，請先由應用程式正常 checkpoint");
                    }
                    $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
                }
                $p = new PDO($dsn, null, null, $options);
                $p->exec("PRAGMA query_only = ON");
                $ic = (string) $p->query("PRAGMA integrity_check")->fetchColumn();
                printf("  %-4s SQLite 自查 %s（%d bytes）：%s\n", $ic === "ok" ? "ok" : "FAIL", $ic, filesize($f), $f);
                $parts = [];
                foreach (["portfolios", "instruments", "transactions", "cash_movements", "prices", "fx_rates", "access"] as $t) {
                    $parts[] = $t . "=" . (int) $p->query("SELECT count(*) FROM " . $t)->fetchColumn();
                }
                echo "       " . implode("  ", $parts) . "\n";
                if ((int) $p->query("SELECT count(*) FROM access")->fetchColumn() > 0) {
                    echo "  ok   帳號已在這份檔案裡：VPS 上沿用同一組帳密即可，不需要跑 create-admin.php\n";
                } else {
                    echo "  注意 這份檔案沒有帳號那一列：VPS 上自己要跑一次 bin/create-admin.php（密碼由你輸入）\n";
                }
            } catch (Throwable $e) {
                printf("  FAIL 讀不到這份 SQLite：%s\n", $e->getMessage());
            }
        ' "$CHECK_DB" 2>&1 || bad "PHP 讀 $CHECK_DB 時出錯"
    else
        skip "資料庫內容檢查（還沒有可讀的檔案）"
    fi
fi

# ---------- 4. 權限 ----------
echo "== 4. 權限 =="
if [ -z "$WWW_USER" ]; then
    for u in www www-data; do
        if id "$u" >/dev/null 2>&1; then WWW_USER="$u"; break; fi
    done
fi
if [ -n "$WWW_USER" ] && id -- "$WWW_USER" >/dev/null 2>&1; then
    ok "網頁／資料目錄使用 $WWW_USER 帳號"
else
    warn "找不到可用的執行帳號 ${WWW_USER:-www 或 www-data}；下面那條 chown 請自己換成你伺服器的執行帳號"
    WWW_USER=""
fi

if [ "$TREE_OK" = "1" ] && [ -d "$DATA_DIR" ]; then
    # 符號連結不追進去的 chmod 迴圈；任何連結都先提醒，避免資料目錄被接到別處。
    while IFS= read -r -d '' link_path; do
        warn "資料目錄下有符號連結，chmod 迴圈不會處理：$link_path"
    done < <(find "$DATA_DIR" -type l -print0 2>/dev/null || true)

    FORMAL_DB_FILES=()
    BACKUP_FILES=()
    shopt -s nullglob
    ROOT_SQLITE_FILES=("$DATA_DIR/"*.sqlite)
    BACKUP_SQLITE_FILES=("$BACKUP_DIR/"*.sqlite)
    shopt -u nullglob
    for dbf in "${ROOT_SQLITE_FILES[@]}"; do
        [ -L "$dbf" ] && continue
        [ -f "$dbf" ] || continue
        if [ "$dbf" = "$TARGET_DB" ]; then
            FORMAL_DB_FILES+=("$dbf")
        else
            # import.sqlite and replaced-*.sqlite are snapshots, not the live database.
            BACKUP_FILES+=("$dbf")
        fi
    done
    for dbf in "${BACKUP_SQLITE_FILES[@]}"; do
        [ -L "$dbf" ] && continue
        [ -f "$dbf" ] && BACKUP_FILES+=("$dbf")
    done

    if do_it && [ -n "$WWW_USER" ]; then
        if chown -R -- "$WWW_USER:$WWW_USER" "$DATA_DIR"; then
            ok "chown -R $WWW_USER:$WWW_USER $DATA_DIR"
        else
            bad "chown 失敗（這一步通常要用 root 跑整個腳本）"
        fi
        chmod 750 -- "$DATA_DIR" || bad "chmod 750 失敗：$DATA_DIR"
        if [ -d "$BACKUP_DIR" ] && [ ! -L "$BACKUP_DIR" ]; then
            chmod 700 -- "$BACKUP_DIR" || bad "備份目錄 chmod 700 失敗：$BACKUP_DIR"
        fi
        PERM_FAIL_BEFORE="$FAILS"
        for dbf in "${FORMAL_DB_FILES[@]}"; do
            chmod 640 -- "$dbf" || bad "正式資料庫 chmod 640 失敗：$dbf"
        done
        for dbf in "${BACKUP_FILES[@]}"; do
            chmod 600 -- "$dbf" || bad "備份 chmod 600 失敗：$dbf"
        done
        if [ "$FAILS" = "$PERM_FAIL_BEFORE" ]; then
            ok "$DATA_DIR 750、正式資料庫 640；備份目錄 700、SQLite 備份／快照 600（符號連結已跳過）"
        fi
    elif do_it; then
        skip "chown／chmod：找不到執行帳號，請手動設定 $DATA_DIR 為 750、正式資料庫為 640、備份目錄為 700、備份檔為 600"
    else
        skip "chown／chmod（檢查模式不寫入；目標是 $DATA_DIR 750、正式資料庫 640、備份目錄 700、備份／快照 600）"
    fi

    inspect_mode() {
        local path="$1" expected="$2" label="$3" actual=""
        if actual="$(stat -c '%a' -- "$path" 2>/dev/null)" && [ "$actual" = "$expected" ]; then
            ok "$label 權限 $actual（目標 $expected）：$path"
        else
            bad "$label 權限 ${actual:-讀不到}，目標是 $expected：$path"
        fi
        return 0
    }
    inspect_mode "$DATA_DIR" 750 "資料目錄"
    if [ -d "$BACKUP_DIR" ] && [ ! -L "$BACKUP_DIR" ]; then
        inspect_mode "$BACKUP_DIR" 700 "備份目錄"
    fi
    for dbf in "${FORMAL_DB_FILES[@]}"; do
        inspect_mode "$dbf" 640 "正式資料庫"
    done
    for dbf in "${BACKUP_FILES[@]}"; do
        inspect_mode "$dbf" 600 "SQLite 備份／快照"
    done
    ls -ld -- "$DATA_DIR"
else
    skip "權限（資料目錄還沒就位）"
fi

# ---------- 5. nginx ----------
echo "== 5. nginx =="
if [ "$TREE_OK" = "1" ]; then
    [ -n "$SOCK" ] || SOCK="unix:/tmp/php-cgi-$PHP_VER_SHORT.sock"
    printf '       fastcgi socket：%s\n' "$SOCK"
    [ -n "$ROOT_DIR" ] || ROOT_DIR="$SITE_DIR/public"

    # 寶塔那份站台設定在哪：--conf 給的 > 跟域名同名的那一份 > 內容裡提到 SITE_DIR 的那一份。
    find_conf() {
        if [ -n "$CONF" ]; then
            printf '%s' "$CONF"
            return
        fi
        local host cand
        host="${DOMAIN#*://}"
        host="${host%%/*}"
        if [ -n "$host" ] && [ -f "/www/server/panel/vhost/nginx/$host.conf" ]; then
            printf '%s' "/www/server/panel/vhost/nginx/$host.conf"
            return
        fi
        cand="$(grep -l -F -- "$SITE_DIR" /www/server/panel/vhost/nginx/*.conf 2>/dev/null | head -1 || true)"
        printf '%s' "$cand"
    }

    # nginx comments are not configuration. Strip them before looking for root/include/tag directives;
    # otherwise a commented extension include sends --auto down the wrong path.
    nginx_uncommented() {
        [ -n "$1" ] && [ -f "$1" ] && sed -E 's/[[:space:]]*#.*$//' "$1" 2>/dev/null || true
    }

    conf_root() {
        [ -n "$1" ] && [ -f "$1" ] || return 0
        nginx_uncommented "$1" \
            | grep -m1 -E '^[[:space:]]*root[[:space:]]' \
            | sed -E 's/^[[:space:]]*root[[:space:]]+//; s/[[:space:]]*;.*$//' || true
    }

    # 寶塔在 server{ } 尾端留了一個 extension/<域名>/*.conf 的 include。能用它，主設定檔一個字都不用改，
    # 面板日後重新生成那份設定也不會把這段洗掉——比插進主設定檔安全，所以優先走它。
    conf_extension_dir() {
        [ -n "$1" ] && [ -f "$1" ] || return 0
        nginx_uncommented "$1" \
            | grep -m1 -oE 'include[[:space:]]+[^;]*/extension/[^;/]+/\*\.conf' \
            | sed -E 's/^include[[:space:]]+//; s#/\*\.conf$##' || true
    }

    ROOT_FIX=""
    CONF_PATH="$(find_conf)"
    LIVE_ROOT="$(conf_root "$CONF_PATH")"
    if [ -n "$LIVE_ROOT" ] && [ "${LIVE_ROOT%/}" != "${ROOT_DIR%/}" ]; then
        ROOT_FIX="# 網頁根要是 public，data/ 才不會被瀏覽器當成檔案下載。
root $ROOT_DIR;"
        warn "站台目前的網頁根是 $LIVE_ROOT，這套系統要的是 $ROOT_DIR；--auto 會在片段裡補一行 root 壓回來"
    else
        printf '       網頁根：%s\n' "${LIVE_ROOT:-從站台設定讀不到（站台還沒建？用 --conf= 指給我看）}"
    fi

    fragment() {
        cat <<NGINX
# $FRAGMENT_TAG
# 手動放的話，就是貼進面板「網站 → 設定 → 配置文件」那份 server { } 裡面。
client_max_body_size 64M;
add_header X-Frame-Options DENY always;
add_header Strict-Transport-Security "max-age=31536000" always;
$ROOT_FIX

# 寶塔的 enable-php-83.conf 裡那條 PHP location（正則 [^/]\.php(/|\$)）已經寫過一遍了；同一個 server
# 再放一條字面相同的，nginx -t 直接 [emerg] duplicate location 不過。下面這條把括號改寫成 (/.*)? ——
# 匹配的是同一批 URL，但字面不同才過得了。面板留的 extension include 在 enable-php 之前，正則 location
# 依檔案順序取第一個吻合的，所以這條贏：它那條 try_files \$uri =404 就不會把 /index.php/transactions 打成 404。
location ~ [^/]\.php(/.*)?\$ {
    fastcgi_split_path_info ^(.+\.php)(/.*)\$;
    fastcgi_index index.php;
    try_files \$fastcgi_script_name =404;
    fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    fastcgi_param PATH_INFO \$fastcgi_path_info;
    fastcgi_pass  $SOCK;
    include fastcgi_params;
    # nginx sends the server-scope frame policy; suppress PHP's duplicate header.
    fastcgi_hide_header X-Frame-Options;
}
NGINX
    }

    # 把擴充片段收回原樣。既有同名檔必須從先前備份逐字節還原，不能當成新檔 rm；
    # 還原後再用 cmp 核對，任何無法識別或驗證失敗都保留現場並回報。
    restore_extension_file() {
        if [ "$PATCH_FILE_EXISTED" = "1" ]; then
            if [ -z "$PATCH_FILE_BACKUP" ] || [ ! -f "$PATCH_FILE_BACKUP" ] || [ -L "$PATCH_FILE_BACKUP" ]; then
                bad "找不到可驗證的既有片段備份，拒絕猜測還原：$PATCH_FILE_BACKUP"
                return 1
            fi
            if [ -L "$PATCH_FILE" ] || { [ -e "$PATCH_FILE" ] && [ ! -f "$PATCH_FILE" ]; }; then
                bad "片段位置被換成符號連結或非一般檔案，拒絕覆蓋：$PATCH_FILE"
                return 1
            fi
            if ! cp -p -- "$PATCH_FILE_BACKUP" "$PATCH_FILE" || ! cmp -s -- "$PATCH_FILE_BACKUP" "$PATCH_FILE"; then
                bad "既有片段還原失敗，請手動跑：cp -p $PATCH_FILE_BACKUP $PATCH_FILE"
                return 1
            fi
            ok "既有同名片段已從備份精確還原：$PATCH_FILE"
        else
            if [ -e "$PATCH_FILE" ] || [ -L "$PATCH_FILE" ]; then
                if ! rm -f -- "$PATCH_FILE"; then
                    bad "本輪新建的片段刪不掉，請手動移除：$PATCH_FILE"
                    return 1
                fi
                ok "本輪新建的片段檔已刪除：$PATCH_FILE"
            fi
        fi
        return 0
    }

    # 把改動收回原樣。還沒 reload 過的沒什麼要重載；已經生效過的，還完要再 reload 一次才真的回落。
    rollback() {
        if [ "$PATCH_MODE" = "extension" ]; then
            restore_extension_file || return 1
        elif [ -n "$CONF_BACKUP" ]; then
            if cp -- "$CONF_BACKUP" "$CONF_PATH"; then
                ok "站台設定已還原成 $CONF_BACKUP"
            else
                bad "還原失敗，請手動跑：cp $CONF_BACKUP $CONF_PATH"
                return 1
            fi
        fi
        if nginx -t >/dev/null 2>&1; then
            ok "還原之後 nginx -t 又通過了"
        elif [ -n "$CONF_BACKUP" ]; then
            bad "還原後 nginx -t 仍不通：從面板重新生成該站設定，或把 $CONF_BACKUP 貼回去"
            return 1
        else
            bad "還原後 nginx -t 仍不通：主設定檔這輪一個字都沒動，問題不在我放的那段，從面板重新生成該站設定來看"
            return 1
        fi
        if [ "$RELOADED" = "1" ]; then
            if nginx -s reload >/dev/null 2>&1; then
                ok "nginx 已重載回改之前的設定"
            else
                warn "reload 叫不起來，手動跑一次 nginx -s reload（或 systemctl reload nginx）"
            fi
        fi
        PATCHED_CONF=0
        if [ -n "$CONF_BACKUP" ]; then
            ROLLBACK_NOTE="這一次對站台設定的改動已自動還原，原檔備份留在 $CONF_BACKUP。"
        elif [ "$PATCH_FILE_EXISTED" = "1" ]; then
            ROLLBACK_NOTE="這一次只動過那個片段檔，已從 $PATCH_FILE_BACKUP 精確還原；寶塔的主設定檔這輪一個字都沒改。"
        else
            ROLLBACK_NOTE="這一次只動過那個片段檔，已刪除；寶塔的主設定檔這輪一個字都沒改。"
        fi
    }

    patch_conf() {
        if [ -z "$CONF_PATH" ] || [ ! -f "$CONF_PATH" ]; then
            bad "--auto 找不到該改哪一份站台設定（/www/server/panel/vhost/nginx/ 裡沒看到指著 $SITE_DIR 的）。用 --domain= 或直接 --conf= 告訴我它是哪一份。"
            return
        fi
        printf '       站台設定：%s\n' "$CONF_PATH"
        if ! command -v nginx >/dev/null 2>&1; then
            bad "叫不到 nginx 指令：沒有 nginx -t 我不動任何設定。這個驗證不能跳——設定檔打錯，整台機器的 web 會一起停。"
            return
        fi

        EXT_DIR="$(conf_extension_dir "$CONF_PATH")"
        if [ -n "$EXT_DIR" ]; then
            PATCH_MODE="extension"
            PATCH_FILE="$EXT_DIR/portfolio-deploy.conf"
            if [ -L "$EXT_DIR" ]; then
                bad "擴展目錄是符號連結，無法識別實際寫入位置，拒絕修改：$EXT_DIR"
                return
            fi
            if ! mkdir -p "$EXT_DIR"; then
                bad "建不了 $EXT_DIR（權限？）"
                return
            fi
            if [ -L "$PATCH_FILE" ] || { [ -e "$PATCH_FILE" ] && [ ! -f "$PATCH_FILE" ]; }; then
                bad "同名片段不是一般檔案（可能是符號連結或目錄），拒絕覆蓋：$PATCH_FILE"
                return
            fi

            PATCH_FILE_EXISTED=0
            PATCH_FILE_BACKUP=""
            if [ -f "$PATCH_FILE" ]; then
                PATCH_FILE_EXISTED=1
                local stamp backup_tmp
                stamp="$(date +%Y%m%d-%H%M%S)"
                backup_tmp="$PATCH_FILE.bak-$stamp"
                while [ -e "$backup_tmp" ] || [ -L "$backup_tmp" ]; do
                    backup_tmp="$backup_tmp-$$"
                done
                PATCH_FILE_BACKUP="$backup_tmp"
                if ! cp -p -- "$PATCH_FILE" "$PATCH_FILE_BACKUP" \
                    || ! cmp -s -- "$PATCH_FILE" "$PATCH_FILE_BACKUP"; then
                    bad "既有同名片段無法先留下可驗證備份，因此拒絕覆蓋：$PATCH_FILE"
                    return
                fi
                ok "既有同名片段先備份並核對完成：$PATCH_FILE_BACKUP"
            fi

            local patch_tmp="$PATCH_FILE.new.$$" patch_ready=1
            if [ -e "$patch_tmp" ] || [ -L "$patch_tmp" ]; then
                bad "片段暫存檔已存在，拒絕猜測覆蓋：$patch_tmp"
                return
            fi
            if ! fragment > "$patch_tmp"; then
                bad "片段寫不下去：$PATCH_FILE（既有檔沒有被覆蓋）"
                rm -f -- "$patch_tmp" || true
                return
            fi
            if [ "$PATCH_FILE_EXISTED" = "1" ]; then
                chmod --reference="$PATCH_FILE_BACKUP" "$patch_tmp" \
                    || { bad "無法沿用既有片段權限：$PATCH_FILE"; patch_ready=0; }
            else
                chmod 644 "$patch_tmp" || { bad "新片段權限設不成 644：$patch_tmp"; patch_ready=0; }
            fi
            if [ "$patch_ready" != "1" ] || ! mv -fT -- "$patch_tmp" "$PATCH_FILE"; then
                bad "新片段換不下去，既有檔維持原樣"
                rm -f -- "$patch_tmp" || true
                return
            fi
            PATCHED_CONF=1
            ok "片段放進寶塔留好的擴展目錄，主設定檔一個字都沒改：$PATCH_FILE"
            if [ "$PATCH_FILE_EXISTED" = "1" ]; then
                ROLLBACK_NOTE="要恢復原樣：cp -p $PATCH_FILE_BACKUP $PATCH_FILE && nginx -t && nginx -s reload；主設定檔沒動過。"
            else
                ROLLBACK_NOTE="要恢復原樣：rm $PATCH_FILE 然後 nginx -t && nginx -s reload；主設定檔沒動過。"
            fi
        elif grep -qFx -- "# $FRAGMENT_TAG" "$CONF_PATH"; then
            ok "這份設定裡已經有上次插進去的那段，所以不重複插入（要重放請先把它從設定裡移除）"
        else
            PATCH_MODE="inline"
            local close
            close="$(nginx_uncommented "$CONF_PATH" | grep -n '^}' | tail -1 | cut -d: -f1 || true)"
            if [ -z "$close" ] || [ "$close" -lt 2 ]; then
                bad "看不懂這份設定的 server { }（找不到頂層那個 }），所以不動它。把 $NGINX_FRAGMENT 那段自己貼在 server { } 最後一個 } 之前。"
                return
            fi
            CONF_BACKUP="$CONF_PATH.bak-$(date +%Y%m%d-%H%M%S)"
            if ! cp "$CONF_PATH" "$CONF_BACKUP"; then
                bad "原設定檔留不下，因此不動它（這一步要用 root 跑整個腳本）"
                return
            fi
            if ! { head -n "$((close - 1))" "$CONF_PATH"; fragment; tail -n "+$close" "$CONF_PATH"; } > "$CONF_PATH.new"; then
                bad "組合新設定時出錯，因此不動它（那份備份 $CONF_BACKUP 跟原檔一模一樣，可以刪）"
                rm -f -- "$CONF_PATH.new" || true
                return
            fi
            if ! mv "$CONF_PATH.new" "$CONF_PATH"; then
                bad "新設定檔換不下去，先還原"
                rollback || true
                return
            fi
            PATCHED_CONF=1
            ok "片段插進 server { } 結尾之前，原檔留成 $CONF_BACKUP"
            ROLLBACK_NOTE="要恢復原樣：cp $CONF_BACKUP $CONF_PATH && nginx -t && nginx -s reload（面板上重新生成該站設定也行）。"
        fi

        local nginx_t
        if nginx_t="$(nginx -t 2>&1)"; then
            ok "nginx -t 通過"
        else
            bad "nginx -t 不通，正在收回這輪改動（沒通過就絕不 reload，正在跑的站台不受影響）"
            # nginx 把原因寫在 stderr 上，只報「不通」等於要人在線上猜；這裡直接把它的前幾行貼出來。
            printf '%s\n' "$nginx_t" | sed -n '1,6p' | sed 's/^/  | /'
            rollback || true
            return
        fi
        if nginx -s reload >/dev/null 2>&1; then
            RELOADED=1
            ok "nginx 已重載，片段生效（第 7 步再從外面驗一次）"
        else
            warn "設定驗證過了但 reload 叫不起：手動跑 nginx -s reload 或 systemctl reload nginx，面板上也有重載的按鈕"
        fi
    }

    if ! do_it; then
        echo "  跳過 寫入（檢查模式只印內容，不落檔、不碰站台設定、不 reload）——內容如下，可以先複製："
        fragment | sed 's/^/  | /'
        echo "       --apply 只把這段寫成 $NGINX_FRAGMENT 給你手動貼；--auto 才會動 nginx 的設定。"
    else
        if fragment > "$NGINX_FRAGMENT"; then
            ok "nginx 片段寫好了：$NGINX_FRAGMENT"
        else
            bad "nginx 片段寫不下去：$NGINX_FRAGMENT"
        fi
        if [ "$WRITE_NGINX" = "1" ]; then
            patch_conf
        else
            skip "動站台設定（這次只 --apply，片段檔已寫好；要一條指令裝完就用 --auto）"
        fi
    fi
    warn "寶塔預設那段 PHP 不給 PATH_INFO，而這個系統的連結是 /index.php/transactions 這種形式；少了 fastcgi_split_path_info 就 404"
else
    skip "nginx 片段（目錄還沒就位）"
fi

# ---------- 6. 排程與時間戳 ----------
echo "== 6. 排程與時間戳 =="
if [ "$TREE_OK" = "1" ] && [ "$PHP_OK" = "1" ]; then
    if [ "$SKIP_NET" = "1" ]; then
        skip "真跑一次報價更新"
    elif do_it; then
        RC=0
        ( cd "$SITE_DIR" && "$PHP_BIN" bin/refresh-prices.php --data-dir="$DATA_DIR" ) || RC=$?
        if [ "$RC" = "0" ]; then
            ok "refresh-prices.php 回 0：這台抓得到 Yahoo，排程放下去就有報價"
        else
            warn "refresh-prices.php 回 $RC（1 = 有報價或匯率沒抓到，2 = 參數或投資組合問題）。機房 IP 被 Yahoo 限流就長這樣，先別排程。"
        fi
    else
        skip "真跑一次報價更新（檢查模式不連網路；--apply 才會跑）"
    fi

    if [ -f "$TARGET_DB" ]; then
        "$PHP_BIN" -r '
            require $argv[1] . "/app/bootstrap.php";
            $f = realpath($argv[2]) ?: $argv[2];
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
            $dsn = "sqlite:" . $f;
            $wal = $f . "-wal";
            if (!file_exists($wal) || (int) filesize($wal) === 0) {
                $uriPath = str_replace(["%", "?", "#"], ["%25", "%3F", "%23"], $f);
                $dsn = "sqlite:file:" . $uriPath . "?mode=ro&immutable=1";
            } else {
                if (!file_exists($f . "-shm")) {
                    throw new RuntimeException("WAL 存在但 SHM 不存在；唯讀檢查拒絕建立 SHM");
                }
                $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            }
            $p = new PDO($dsn, null, null, $options);
            $p->exec("PRAGMA query_only = ON");
            $now = date("Y-m-d H:i:s");
            $st = $p->query("SELECT max(fetched_at) FROM prices")->fetchColumn();
            $lr = $p->query("SELECT value FROM settings WHERE key = \"last_refresh\"")->fetchColumn();
            printf("       app 時鐘 %s｜最新 fetched_at %s｜上次更新 %s｜時區 %s\n", $now, var_export($st, true), var_export($lr, true), date_default_timezone_get());
            if ($st === null || $st === false) {
                echo "  跳過 時間戳比對（這份資料庫還沒有任何報價）\n";
                exit(0);
            }
            $gap = abs(strtotime($now) - strtotime((string) $st));
            if ($gap < 172800) {
                printf("  ok   兩個鐘在同一條線上（差 %d 秒，且都在 +8 這一側）\n", $gap);
            } else {
                printf("  FAIL fetched_at 跟現在差了 %d 秒 — 時間戳還沒搬正，或 PHP 時區不對\n", $gap);
            }
        ' "$SITE_DIR" "$TARGET_DB" 2>&1 | sed 's/^       /  /'
    else
        skip "時間戳比對（$TARGET_DB 還沒就位）"
    fi

    echo "       排程那一行（面板上的排程工作，類型選 Shell 腳本）："
    echo "       cd $SITE_DIR && $PHP_BIN bin/refresh-prices.php --data-dir=$DATA_DIR >> $DATA_DIR/refresh.log 2>&1"
fi

# ---------- 7. 線上自查 ----------
echo "== 7. 線上自查 =="
if [ -z "$DOMAIN" ]; then
    skip "線上自查（沒給 --domain=；憑證簽好之後再跑一次並帶上它）"
elif ! command -v curl >/dev/null 2>&1; then
    skip "線上自查（這台機器沒有 curl？）"
else
    probe() {
        curl -s -o /dev/null -w '%{http_code}' --max-time 12 \
            --proto '=https' --proto-redir '=https' -- "$1" 2>/dev/null || true
    }
    CODE_LOGIN="$(probe "$DOMAIN/login")"
    CODE_PATH="$(probe "$DOMAIN/index.php/login")"
    CODE_DB="$(probe "$DOMAIN/data/portfolio.sqlite")"
    CODE_CSS="$(probe "$DOMAIN/assets/style.css")"

    if [ "$CODE_LOGIN" = "200" ]; then
        ok "$DOMAIN/login → 200"
    else
        warn "$DOMAIN/login → ${CODE_LOGIN:-連不到}（憑證還沒簽或站還沒起來，這一步正常）"
    fi

    if [ "$CODE_PATH" = "200" ]; then
        ok "$DOMAIN/index.php/login → 200，PATH_INFO 通了"
    else
        bad "$DOMAIN/index.php/login → ${CODE_PATH:-連不到}：這是這套系統實際生成的連結格式，不是 200 就代表第 5 步那段沒生效"
    fi

    if [ "$CODE_DB" = "200" ] || [ "$CODE_DB" = "304" ]; then
        bad "$DOMAIN/data/portfolio.sqlite 回 $CODE_DB：你的持股與現金可以被當成檔案下載！把執行目錄改成 /public 再查一次"
    else
        ok "data/portfolio.sqlite 從外面拿不到（HTTP ${CODE_DB:-404}）"
    fi

    if [ "$CODE_CSS" = "200" ]; then
        ok "樣式抓得到（網頁根確實是 public/）"
    else
        warn "assets/style.css → ${CODE_CSS:-連不到}：網頁根可能不是 public/"
    fi

    # 只有一種情況我自己收回：片段放下去了、也 reload 了，而整個站從外面完全叫不通——那代表改動把站弄停了。
    # 單純 PATH_INFO 沒通（上面那條 FAIL）不收，那段本來就是為它加的，收了只會變回更壞的樣子。
    if [ "$PATCHED_CONF" = "1" ] && [ "$RELOADED" = "1" ] \
        && [ "$CODE_LOGIN" != "200" ] && [ "$CODE_PATH" != "200" ]; then
        bad "放了片段也重載了，登入頁從外面還是完全叫不到：這輪改動先收回，不要讓站停在那裡"
        rollback || true
    fi
fi

# ---------- 報告 ----------
echo
echo "== 總結：FAIL $FAILS、注意 $WARNS =="
if [ "$MODE" = "check" ]; then
    echo "這一次只檢查，沒有寫入任何東西。"
fi
echo "還要在面板上做、腳本故意不碰的："
echo "  1. 網站目錄 → 執行目錄 設成 /public（第 7 步就是用來驗它有生效；--auto 補的那行 root 是暫時代替，面板上改才不會在重新生成設定時被洗掉）"
echo "  2. Let's Encrypt 簽憑證 + 強制 HTTPS（有 TLS 之後 session cookie 自動帶 Secure，不用改 app/config.php）"
echo "  3. 專用 PHP pool（後續受控步驟）：用 --socket、--user、--data-dir 對應實際 pool；上傳上限 64M 與 date.timezone = Asia/Hong_Kong 設在該 pool，不改共用 php.ini"
echo "  4. 面板端口 + 安全入口 + IP 白名單；防火牆只留 80/443/SSH/面板端口"
echo "  5. 把第 6 步印出的那行排程加進面板的排程工作"
if [ -n "$ROLLBACK_NOTE" ]; then
    echo
    echo "nginx 設定這一輪：$ROLLBACK_NOTE"
    [ -n "$CONF_BACKUP" ] && echo "       原檔備份：$CONF_BACKUP"
    [ -n "$PATCH_FILE_BACKUP" ] && echo "       既有片段備份：$PATCH_FILE_BACKUP"
fi
echo "資料庫已含你的登入帳號，VPS 上沿用同一組帳密；只有想換密碼才跑 bin/create-admin.php，而且密碼自己輸入。"
if [ "$FAILS" != "0" ]; then
    exit 1
fi
