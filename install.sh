#!/usr/bin/env bash
# 寶塔 VPS 一鍵安裝腳本（Nginx + PHP-FPM + SQLite）。
#
# 前置條件：寶塔已安裝，Nginx 與 PHP 8.1+ 已裝好；如要自動設定網站，
# 請先在寶塔建立站台。SSL 憑證仍在寶塔申請，本腳本不代簽。
#
# 範例：
#   sudo bash install.sh --domain=portfolio.example.com
#   sudo bash install.sh --repo=owner/repo --ref=v1.0.0 --domain=portfolio.example.com
#   curl -fsSL https://raw.githubusercontent.com/owner/repo/v1.0.0/install.sh | \
#     sudo bash -s -- --repo=owner/repo --ref=v1.0.0 --domain=portfolio.example.com
#
# Private repo 可先下載 install.sh，再用 GITHUB_TOKEN 讓腳本下載源碼：
#   sudo env GITHUB_TOKEN=... bash install.sh --repo=owner/repo --ref=v1.0.0

set -euo pipefail

BT_PANEL_DIR="${BT_PANEL_DIR:-/www/server/panel}"
BT_NGINX_DIR="${BT_NGINX_DIR:-/www/server/panel/vhost/nginx}"
NGINX_BIN="${NGINX_BIN:-/www/server/nginx/sbin/nginx}"
SITE_DIR="/www/wwwroot/portfolio"
DATA_DIR=""
PHP_BIN=""
PHP_VERSION=""
WWW_USER="www"
USERNAME="admin"
DOMAIN=""
CONF=""
REPO=""
REF="main"
SOURCE=""
ARCHIVE_SHA256=""
NO_NGINX=0
MANAGE_FPM=0
SKIP_PHP_CONFIG=0
TMP_DIR=""
CREATED_SITE=0
CREATED_DATA=0
PHP_INI_BACKUP=""
PHP_INI_CHANGED=0
FPM_POOL_FILE=""
FPM_INCLUDE_FILE=""
FPM_POOL_EXISTED=0
FPM_INCLUDE_EXISTED=0
FPM_ROLLBACK_READY=0
NGINX_CONF_FILE=""
NGINX_EXT_FILE=""
NGINX_CONF_EXISTED=0
NGINX_EXT_EXISTED=0
NGINX_ROLLBACK_READY=0
NGINX_DEPLOY_STARTED=0
INSTALL_COMPLETE=0

usage() {
    cat <<'TXT'
用法：sudo bash install.sh [選項]

來源：
  --repo=OWNER/REPO      從 GitHub 下載源碼；private repo 可用 GITHUB_TOKEN
  --ref=REF              GitHub tag、branch 或 commit（預設 main）
  --archive-sha256=HASH  驗證下載的 GitHub archive；正式安裝建議提供
  --source=PATH          使用本機完整源碼目錄；未提供時使用 install.sh 所在目錄

安裝：
  --site-dir=PATH        程式目錄（預設 /www/wwwroot/portfolio）
  --data-dir=PATH        SQLite 目錄（預設 <site-dir>/data）
  --php-bin=PATH         PHP CLI（預設自動尋找寶塔 PHP 8.3／8.2／8.1）
  --user=NAME           資料目錄擁有者（預設 www）
  --username=NAME        預設登入帳號（預設 admin）
  --domain=DOMAIN        寶塔站台域名；自動設定 Nginx 時必填
  --conf=PATH            指定寶塔 Nginx 站台設定
  --no-nginx             只裝程式與資料庫，不修改 Nginx
  --skip-php-config      不修改 php.ini（自行設定 64M 上傳限制與時區）
  --help                 顯示說明

安全：
  root 才能執行；既有非空程式目錄或資料庫不會被覆蓋。
  未提供 PORTFOLIO_ADMIN_PASSWORD 時，會建立 admin 並顯示一次隨機臨時密碼。
  不要在命令列或 GitHub 內填寫固定密碼。

TXT
}

log()  { printf '%s\n' "$*"; }
warn() { printf '警告：%s\n' "$*" >&2; }
die()  { printf '錯誤：%s\n' "$*" >&2; exit 1; }

# install.sh owns the whole transaction, not just deploy-bt.sh's own validation rollback. Keep
# private copies before deployment so a later lint/admin failure can restore every managed config,
# including files created by this run and the main-config includes added to reach them.
snapshot_managed_file() {
    local source="$1" snapshot="$2"
    SNAPSHOT_EXISTED=0
    if [[ -L "$source" || ( -e "$source" && ! -f "$source" ) ]]; then
        die "受管理的設定不是一般檔案，拒絕安裝：$source"
    fi
    if [[ -f "$source" ]]; then
        cp -p -- "$source" "$snapshot" || die "無法備份受管理的設定：$source"
        cmp -s -- "$source" "$snapshot" || die "受管理的設定備份核對失敗：$source"
        SNAPSHOT_EXISTED=1
    fi
}

prepare_fpm_rollback() {
    (( MANAGE_FPM == 1 )) || return 0
    FPM_POOL_FILE="$PHP_DIR/etc/portfolio-fpm.conf"
    FPM_INCLUDE_FILE="$PHP_DIR/etc/php-fpm.conf"
    [[ -f "$FPM_INCLUDE_FILE" && ! -L "$FPM_INCLUDE_FILE" ]] \
        || die "PHP-FPM 主設定不存在或是符號連結，拒絕安裝：$FPM_INCLUDE_FILE"
    mkdir -p -- "$TMP_DIR/fpm-rollback"
    snapshot_managed_file "$FPM_POOL_FILE" "$TMP_DIR/fpm-rollback/pool.conf"
    FPM_POOL_EXISTED="$SNAPSHOT_EXISTED"
    snapshot_managed_file "$FPM_INCLUDE_FILE" "$TMP_DIR/fpm-rollback/php-fpm.conf"
    FPM_INCLUDE_EXISTED="$SNAPSHOT_EXISTED"
    FPM_ROLLBACK_READY=1
}

prepare_nginx_rollback() {
    (( NO_NGINX == 0 )) || return 0
    NGINX_CONF_FILE="$CONF"
    [[ -f "$NGINX_CONF_FILE" && ! -L "$NGINX_CONF_FILE" ]] \
        || die "Nginx 站台設定不存在或是符號連結，拒絕安裝：$NGINX_CONF_FILE"
    mkdir -p -- "$TMP_DIR/nginx-rollback"
    snapshot_managed_file "$NGINX_CONF_FILE" "$TMP_DIR/nginx-rollback/site.conf"
    NGINX_CONF_EXISTED="$SNAPSHOT_EXISTED"

    local extension_dir
    extension_dir="$(sed -E 's/[[:space:]]*#.*$//' "$NGINX_CONF_FILE" 2>/dev/null \
        | grep -m1 -oE 'include[[:space:]]+[^;]*/extension/[^;/]+/\*\.conf' \
        | sed -E 's/^include[[:space:]]+//; s#/\*\.conf$##' || true)"
    if [[ -n "$extension_dir" ]]; then
        [[ "$extension_dir" = /* ]] || extension_dir="$PWD/$extension_dir"
        extension_dir="${extension_dir%/}"
        [[ ! -L "$extension_dir" ]] || die "Nginx extension 目錄是符號連結，拒絕安裝：$extension_dir"
        if [[ -e "$extension_dir" && ! -d "$extension_dir" ]]; then
            die "Nginx extension 路徑不是目錄，拒絕安裝：$extension_dir"
        fi
        NGINX_EXT_FILE="$extension_dir/portfolio-deploy.conf"
        snapshot_managed_file "$NGINX_EXT_FILE" "$TMP_DIR/nginx-rollback/extension.conf"
        NGINX_EXT_EXISTED="$SNAPSHOT_EXISTED"
    fi
    NGINX_ROLLBACK_READY=1
}

restore_managed_file() {
    local file="$1" snapshot="$2" existed="$3" label="$4"
    if (( existed == 1 )); then
        if [[ ! -f "$snapshot" || -L "$snapshot" ]]; then
            warn "找不到 $label 的安裝前備份，請手動檢查：$file"
            return 1
        fi
        if [[ -L "$file" || ( -e "$file" && ! -f "$file" ) ]]; then
            warn "$label 被換成符號連結或非一般檔案，拒絕覆蓋：$file"
            return 1
        fi
        if ! cp -p -- "$snapshot" "$file" || ! cmp -s -- "$snapshot" "$file"; then
            warn "$label 無法精確還原，請手動檢查：$file"
            return 1
        fi
    elif [[ -e "$file" || -L "$file" ]]; then
        if ! rm -f -- "$file"; then
            warn "本輪新建的 $label 刪除失敗：$file"
            return 1
        fi
    fi
    return 0
}

rollback_fpm() {
    (( FPM_ROLLBACK_READY == 1 )) || return 0
    local failed=0
    # Restore the include before removing a newly created pool, so no managed include is left stale.
    restore_managed_file "$FPM_INCLUDE_FILE" "$TMP_DIR/fpm-rollback/php-fpm.conf" \
        "$FPM_INCLUDE_EXISTED" "PHP-FPM 主設定" || failed=1
    restore_managed_file "$FPM_POOL_FILE" "$TMP_DIR/fpm-rollback/pool.conf" \
        "$FPM_POOL_EXISTED" "portfolio pool" || failed=1
    return "$failed"
}

rollback_nginx() {
    (( NGINX_ROLLBACK_READY == 1 )) || return 0
    local failed=0
    if [[ -n "$NGINX_EXT_FILE" ]]; then
        restore_managed_file "$NGINX_EXT_FILE" "$TMP_DIR/nginx-rollback/extension.conf" \
            "$NGINX_EXT_EXISTED" "Nginx extension 片段" || failed=1
    fi
    restore_managed_file "$NGINX_CONF_FILE" "$TMP_DIR/nginx-rollback/site.conf" \
        "$NGINX_CONF_EXISTED" "Nginx 站台設定" || failed=1
    return "$failed"
}

cleanup() {
    local rc=$? fpm_failed=0 nginx_failed=0 ini_failed=0 rollback_failed=0
    trap - EXIT
    if (( rc != 0 && INSTALL_COMPLETE == 0 )); then
        rollback_fpm || fpm_failed=1
        if (( PHP_INI_CHANGED == 1 )) && [[ -n "$PHP_INI_BACKUP" ]]; then
            if ! cp -p -- "$PHP_INI_BACKUP" "$PHP_INI" \
                || ! cmp -s -- "$PHP_INI_BACKUP" "$PHP_INI"; then
                warn "php.ini 無法精確還原，請手動檢查：$PHP_INI"
                ini_failed=1
            fi
        fi
        rollback_nginx || nginx_failed=1

        # Put restored configuration back into each service before deleting directories that only
        # this failed install may have created. A failed validation/reload keeps the directories.
        if (( FPM_ROLLBACK_READY == 1 && fpm_failed == 0 && ini_failed == 0 )) \
            && [[ -x "$PHP_DIR/sbin/php-fpm" ]]; then
            if ! "$PHP_DIR/sbin/php-fpm" -tt >/dev/null 2>&1; then
                warn "PHP-FPM 已還原但設定檢查失敗，請手動檢查"
                fpm_failed=1
            elif systemctl is-active --quiet "php-fpm-${PHP_VERSION/./}" 2>/dev/null; then
                if ! systemctl reload "php-fpm-${PHP_VERSION/./}"; then
                    warn "PHP-FPM 已還原但重載失敗，請手動重新啟動 PHP"
                    fpm_failed=1
                fi
            fi
        fi
        if (( NGINX_ROLLBACK_READY == 1 && NGINX_DEPLOY_STARTED == 1 && nginx_failed == 0 )); then
            if ! "$NGINX_BIN" -t >/dev/null 2>&1; then
                warn "Nginx 已還原但設定檢查失敗，請手動檢查"
                nginx_failed=1
            elif ! "$NGINX_BIN" -s reload >/dev/null 2>&1; then
                warn "Nginx 已還原但重載失敗，請手動重新啟動 Nginx"
                nginx_failed=1
            fi
        fi

        (( fpm_failed == 0 && nginx_failed == 0 && ini_failed == 0 )) || rollback_failed=1
        if (( rollback_failed != 0 )); then
            warn "設定未完全回復，保留本次建立的程式與資料目錄以避免服務中斷：$SITE_DIR、$DATA_DIR"
        else
            if (( CREATED_SITE == 1 )); then
                rm -rf -- "$SITE_DIR"
            fi
            if (( CREATED_DATA == 1 )) && [[ "$DATA_DIR" != "$SITE_DIR"/* ]]; then
                rm -rf -- "$DATA_DIR"
            fi
        fi
    fi
    if [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]]; then
        rm -rf -- "$TMP_DIR"
    fi
    exit "$rc"
}
trap cleanup EXIT

for arg in "$@"; do
    case "$arg" in
        --repo=*) REPO="${arg#*=}" ;;
        --ref=*) REF="${arg#*=}" ;;
        --archive-sha256=*) ARCHIVE_SHA256="${arg#*=}" ;;
        --source=*) SOURCE="${arg#*=}" ;;
        --site-dir=*) SITE_DIR="${arg#*=}" ;;
        --data-dir=*) DATA_DIR="${arg#*=}" ;;
        --php-bin=*) PHP_BIN="${arg#*=}" ;;
        --user=*) WWW_USER="${arg#*=}" ;;
        --username=*) USERNAME="${arg#*=}" ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --conf=*) CONF="${arg#*=}" ;;
        --no-nginx) NO_NGINX=1 ;;
        --skip-php-config) SKIP_PHP_CONFIG=1 ;;
        --help|-h) usage; exit 0 ;;
        *) die "不明的參數：$arg（用 --help 看用法）" ;;
    esac
done

[[ "$EUID" -eq 0 ]] || die "請用 root 或 sudo 執行"
[[ "$SITE_DIR" = /* ]] || die "--site-dir 必須是絕對路徑"
[[ "$SITE_DIR" != "/" ]] || die "--site-dir 不可以是 /"
[[ -n "$USERNAME" ]] || die "--username 不可以是空值"

if [[ -n "$DATA_DIR" ]]; then
    [[ "$DATA_DIR" = /* ]] || die "--data-dir 必須是絕對路徑"
else
    DATA_DIR="$SITE_DIR/data"
fi
[[ "$DATA_DIR" != "$SITE_DIR" ]] || die "--data-dir 不可以等於程式目錄"
[[ "$DATA_DIR" != "$SITE_DIR/public" && "$DATA_DIR" != "$SITE_DIR/public/"* ]] \
    || die "--data-dir 不可以放在 public/ 裡面"

if [[ -n "$DOMAIN" ]]; then
    [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] \
        || die "--domain 請填域名或 IP，不要加 http://、https:// 或路徑"
fi
if (( NO_NGINX == 0 )) && [[ -z "$DOMAIN" && -z "$CONF" ]]; then
    die "自動設定寶塔網站需要 --domain=你的域名；只裝程式請加 --no-nginx"
fi
if [[ -n "$CONF" ]]; then
    [[ "$CONF" = /* ]] || die "--conf 必須是絕對路徑"
fi

if (( NO_NGINX == 0 )) && [[ "$WWW_USER" == "root" ]]; then
    die "寶塔網站資料目錄不可以由 root 擁有；請使用 www 或其他非 root 帳號"
fi

for cmd in curl tar sha256sum openssl rsync runuser; do
    command -v "$cmd" >/dev/null 2>&1 || die "找不到必要指令：$cmd"
done
[[ -d "$BT_PANEL_DIR" ]] || die "找不到寶塔面板：$BT_PANEL_DIR"
id "$WWW_USER" >/dev/null 2>&1 || die "找不到寶塔執行帳號：$WWW_USER"

if [[ -z "$PHP_BIN" ]]; then
    for ver in 83 82 81; do
        candidate="/www/server/php/$ver/bin/php"
        if [[ -x "$candidate" ]]; then
            PHP_BIN="$candidate"
            break
        fi
    done
fi
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || die "找不到 PHP CLI；請用 --php-bin=/www/server/php/83/bin/php"
PHP_DIR="$(cd -- "$(dirname -- "$PHP_BIN")/.." && pwd)"
PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.1.0", "<") ? 1 : 0);' \
    || die "PHP 版本太低：$PHP_VERSION（需要 8.1+）"
"$PHP_BIN" -r '
foreach (["pdo_sqlite", "curl", "mbstring", "openssl"] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "缺少 PHP 擴充：{$ext}\n");
        exit(1);
    }
}
' || die "請先在寶塔 PHP 設定開啟 pdo_sqlite、curl、mbstring、openssl"

if (( NO_NGINX == 0 )); then
    [[ -x "$NGINX_BIN" ]] || die "找不到寶塔 Nginx：$NGINX_BIN"
    if [[ -z "$CONF" ]]; then
        CONF="$BT_NGINX_DIR/$DOMAIN.conf"
    fi
    [[ -f "$CONF" ]] || die \
        "找不到站台設定：$CONF。請先在寶塔建立站台（運行目錄 $SITE_DIR/public），再重跑；或用 --no-nginx 只裝程式"
fi

if [[ -e "$SITE_DIR" || -L "$SITE_DIR" ]]; then
    [[ ! -L "$SITE_DIR" ]] || die "程式目錄不可以是符號連結：$SITE_DIR"
    [[ -d "$SITE_DIR" ]] || die "程式目錄不是資料夾：$SITE_DIR"
    if [[ -n "$(find "$SITE_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
        die "程式目錄已有內容，拒絕覆蓋：$SITE_DIR"
    fi
fi
if [[ -e "$DATA_DIR" || -L "$DATA_DIR" ]]; then
    [[ ! -L "$DATA_DIR" ]] || die "資料目錄不可以是符號連結：$DATA_DIR"
    [[ -d "$DATA_DIR" ]] || die "資料目錄不是資料夾：$DATA_DIR"
    if [[ -n "$(find "$DATA_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
        die "資料目錄已有內容，拒絕覆蓋：$DATA_DIR"
    fi
fi

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/portfolio-install.XXXXXX")"

if [[ -n "$SOURCE" ]]; then
    [[ -d "$SOURCE" ]] || die "源碼目錄不存在：$SOURCE"
    SOURCE="$(cd -- "$SOURCE" && pwd)"
elif [[ -n "$REPO" ]]; then
    [[ "$REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "--repo 格式應為 OWNER/REPO"
    [[ "$REF" =~ ^[A-Za-z0-9._/-]+$ && "$REF" != -* ]] || die "--ref 含不支援的字元"
    if [[ -n "$ARCHIVE_SHA256" ]]; then
        [[ "$ARCHIVE_SHA256" =~ ^[A-Fa-f0-9]{64}$ ]] || die "--archive-sha256 必須是 64 位十六進位"
    fi

    archive="$TMP_DIR/source.tar.gz"
    url="https://codeload.github.com/$REPO/tar.gz/$REF"
    curl_args=(--fail --silent --show-error --location --proto '=https' --tlsv1.2 --output "$archive")
    if [[ -n "${GITHUB_TOKEN:-}" ]]; then
        [[ "$GITHUB_TOKEN" =~ ^[A-Za-z0-9_]+$ ]] || die "GITHUB_TOKEN 含不支援的字元"
        curl_config="$TMP_DIR/curl.conf"
        chmod 600 "$curl_config"
        printf 'header = "Authorization: Bearer %s"\n' "$GITHUB_TOKEN" > "$curl_config"
        curl_args+=(--config "$curl_config")
    fi
    log "下載源碼：$url"
    curl "${curl_args[@]}" "$url" || die "GitHub 源碼下載失敗；private repo 請設定 GITHUB_TOKEN"
    unset GITHUB_TOKEN
    if [[ -n "$ARCHIVE_SHA256" ]]; then
        actual="$(sha256sum "$archive" | awk '{print $1}')"
        [[ "${actual,,}" == "${ARCHIVE_SHA256,,}" ]] || die "源碼 archive SHA-256 不符"
    fi
    if tar -tzf "$archive" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
        die "源碼 archive 含有不安全路徑"
    fi
    mkdir "$TMP_DIR/extracted"
    tar -xzf "$archive" -C "$TMP_DIR/extracted"
    mapfile -t top < <(find "$TMP_DIR/extracted" -mindepth 1 -maxdepth 1 -print)
    [[ "${#top[@]}" -eq 1 && -d "${top[0]}" ]] || die "無法識別 GitHub archive 的源碼目錄"
    SOURCE="${top[0]}"
else
    script_path="${BASH_SOURCE[0]:-}"
    [[ -n "$script_path" && -f "$script_path" ]] || die \
        "install.sh 是從 stdin 執行；請加 --repo=OWNER/REPO 或 --source=/path/to/source"
    SOURCE="$(cd -- "$(dirname -- "$script_path")" && pwd)"
fi

for required in public/index.php app/bootstrap.php app/schema.sql bin/create-admin.php deploy-bt.sh; do
    [[ -f "$SOURCE/$required" ]] || die "源碼不完整，缺少：$required"
done
if [[ -n "$(find "$SOURCE" -path "$SOURCE/data" -prune -o -type l -print -quit)" ]]; then
    die "源碼含有符號連結，拒絕安裝"
fi

mkdir -p -- "$(dirname -- "$SITE_DIR")"
if [[ ! -d "$SITE_DIR" ]]; then
    mkdir -- "$SITE_DIR"
    CREATED_SITE=1
fi
if [[ ! -d "$DATA_DIR" ]]; then
    mkdir -p -- "$DATA_DIR"
    if [[ "$DATA_DIR" != "$SITE_DIR"/* ]]; then
        CREATED_DATA=1
    fi
fi

# 永遠排除執行期資料，避免把持股、交易或舊備份一起安裝到新機。
rsync -rltp \
    --exclude='/data/' \
    --exclude='/.git/' \
    --exclude='/deploy-portfolio.conf' \
    --exclude='/*.log' \
    "$SOURCE/" "$SITE_DIR/"

mkdir -p -- "$DATA_DIR/backups" "$DATA_DIR/sessions"
chown -R root:root "$SITE_DIR"
find "$SITE_DIR" -type d -exec chmod 755 {} +
find "$SITE_DIR" -type f -exec chmod 644 {} +
[[ ! -f "$SITE_DIR/start.sh" ]] || chmod 755 "$SITE_DIR/start.sh"
[[ ! -f "$SITE_DIR/install.sh" ]] || chmod 755 "$SITE_DIR/install.sh"
chown -R "$WWW_USER:$WWW_USER" "$DATA_DIR"
chmod 750 "$DATA_DIR"
chmod 700 "$DATA_DIR/backups" "$DATA_DIR/sessions"

if (( SKIP_PHP_CONFIG == 0 )); then
    PHP_INI="$PHP_DIR/etc/php.ini"
    [[ -f "$PHP_INI" ]] || die "找不到 php.ini：$PHP_INI"
    PHP_INI_BACKUP="$PHP_INI.before-portfolio-install-$(date +%Y%m%d-%H%M%S)"
    cp -p -- "$PHP_INI" "$PHP_INI_BACKUP"
    set_ini_value() {
        local key="$1" value="$2" escaped
        escaped="${key//./\\.}"
        if grep -Eq "^[;[:space:]]*${escaped}[[:space:]]*=" "$PHP_INI"; then
            sed -i -E "s|^[;[:space:]]*${escaped}[[:space:]]*=.*|${key} = ${value}|" "$PHP_INI"
        else
            printf '\n%s = %s\n' "$key" "$value" >> "$PHP_INI"
        fi
    }
    set_ini_value upload_max_filesize 64M
    set_ini_value post_max_size 64M
    set_ini_value date.timezone Asia/Hong_Kong
    PHP_INI_CHANGED=1

    if [[ -x "$PHP_DIR/sbin/php-fpm" ]]; then
        "$PHP_DIR/sbin/php-fpm" -tt >/dev/null 2>&1 \
            || die "PHP-FPM 設定檢查失敗，已還原 php.ini"
        if systemctl is-active --quiet "php-fpm-${PHP_VERSION/./}" 2>/dev/null; then
            systemctl reload "php-fpm-${PHP_VERSION/./}" \
                || warn "PHP-FPM reload 失敗，請在寶塔重新啟動 PHP"
        else
            warn "請在寶塔重新啟動 PHP $PHP_VERSION，讓上傳限制與時區生效"
        fi
    fi
fi

DEPLOY_ARGS=(
    --apply
    --site-dir="$SITE_DIR"
    --data-dir="$DATA_DIR"
    --php-bin="$PHP_BIN"
    --user="$WWW_USER"
    --skip-net
)
if (( NO_NGINX == 0 )); then
    MANAGE_FPM=1
    prepare_fpm_rollback
    prepare_nginx_rollback
    log "套用寶塔 Nginx 與 PHP-FPM 設定：$CONF"
    DEPLOY_ARGS+=(--php-etc-dir="$PHP_DIR/etc" --manage-fpm --write-nginx --conf="$CONF")
else
    # --php-etc-dir itself enables deploy-bt.sh's FPM management, so omit both FPM switches for
    # root/CLI installs. DATA_DIR still reaches create-admin and the printed cron command below.
    log "套用程式設定（--no-nginx：不修改 Nginx／PHP-FPM）"
fi
NGINX_DEPLOY_STARTED=1
bash "$SITE_DIR/deploy-bt.sh" "${DEPLOY_ARGS[@]}"

# 在建立帳號前先檢查所有 PHP 語法，避免留下半安裝狀態。
while IFS= read -r -d '' file; do
    "$PHP_BIN" -l "$file" >/dev/null
done < <(find "$SITE_DIR/app" "$SITE_DIR/bin" "$SITE_DIR/public" -type f -name '*.php' -print0)

if [[ -f "$DATA_DIR/portfolio.sqlite" ]]; then
    die "資料庫在安裝途中出現，拒絕建立帳號：$DATA_DIR/portfolio.sqlite"
fi

generated=0
if [[ -n "${PORTFOLIO_ADMIN_PASSWORD:-}" ]]; then
    password="$PORTFOLIO_ADMIN_PASSWORD"
    unset PORTFOLIO_ADMIN_PASSWORD
else
    password="$(openssl rand -hex 18)"
    generated=1
fi
if [[ "$WWW_USER" == "root" ]]; then
    warn "--user=root 只用於 CLI／測試；不要在 Nginx 網站使用 root"
    printf '%s' "$password" | \
        PORTFOLIO_DATA_DIR="$DATA_DIR" "$PHP_BIN" "$SITE_DIR/bin/create-admin.php" \
        --username="$USERNAME" \
        --password-from-stdin \
        --data-dir="$DATA_DIR"
else
    printf '%s' "$password" | runuser -u "$WWW_USER" -- \
        env PORTFOLIO_DATA_DIR="$DATA_DIR" "$PHP_BIN" "$SITE_DIR/bin/create-admin.php" \
        --username="$USERNAME" \
        --password-from-stdin \
        --data-dir="$DATA_DIR"
fi
initial_password="$password"
unset password

INSTALL_COMPLETE=1
log ""
log "安裝完成。"
log "程式目錄：$SITE_DIR"
log "資料目錄：$DATA_DIR"
log "登入帳號：$USERNAME"
if (( generated == 1 )); then
    log "臨時密碼（只顯示這一次，請立即保存並登入後修改）："
    log "$initial_password"
fi
unset initial_password
if (( NO_NGINX == 0 )); then
    log "網站：https://$DOMAIN （請確認寶塔已申請 SSL）"
    log "SSL 生效後可執行：cd $SITE_DIR && bash deploy-bt.sh --domain=https://$DOMAIN"
else
    log "尚未設定 Nginx。請在寶塔建立站台，運行目錄設為 $SITE_DIR/public，再執行："
    log "cd $SITE_DIR && bash deploy-bt.sh --auto --conf=/www/server/panel/vhost/nginx/你的域名.conf --skip-net"
fi
printf -v cron_site '%q' "$SITE_DIR"
printf -v cron_php '%q' "$PHP_BIN"
printf -v cron_data '%q' "$DATA_DIR"
printf -v cron_log '%q' "$DATA_DIR/refresh.log"
log "排程（Shell 腳本，環境與 CLI 使用同一資料庫）："
log "  cd $cron_site && PORTFOLIO_DATA_DIR=$cron_data $cron_php bin/refresh-prices.php --data-dir=$cron_data >> $cron_log 2>&1"
log "日後更新帳號：cd $cron_site && PORTFOLIO_DATA_DIR=$cron_data $cron_php bin/create-admin.php --username=$USERNAME --data-dir=$cron_data"
log "下一步：登入 → 設定頁修改密碼 → 上傳並還原舊備份。"
