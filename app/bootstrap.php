<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');

// A default trade date, the 更新報價 stamp, and a backup file name all come from date(), and an
// unsigned trade or a restored snapshot is read against the local market. PHP falls back to UTC
// when php.ini sets nothing, so the app supplies its own day boundary: HKT, UTC+8 all year with no
// daylight saving. An explicit php.ini setting still wins, and PORTFOLIO_TZ overrides everything.
//
// ini_get('date.timezone') cannot make that distinction: on PHP 8 it answers "UTC" whether the
// operator typed UTC or never mentioned the setting at all. get_cfg_var() reads the raw ini value,
// which is empty when nothing was configured.
$tz = trim((string) (getenv('PORTFOLIO_TZ') ?: ''));
if ($tz === '' && trim((string) get_cfg_var('date.timezone')) === '') {
    $tz = 'Asia/Hong_Kong';
}
if ($tz !== '' && @date_default_timezone_set($tz) === false) {
    error_log("PORTFOLIO_TZ「{$tz}」不是有效的時區，改採 PHP 原本的設定。");
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/helpers.php';

if (PHP_SAPI !== 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '伺服器設定錯誤：網站服務不得以 root 身分執行，請改用一般使用者（例如 www）後重新啟動。';
    exit;
}

if (PHP_SAPI === 'cli') {
    return;
}

// The php.ini a 宝塔 panel ships leaves display_errors on for every site on the machine, and one of
// this app's own failure modes writes the absolute database path into the message. The panel rewrites
// that file whenever PHP settings are saved from its UI, so the switch is made here instead: it travels
// with the app, covers start.sh and start-windows.bat too, and cannot be undone by a panel edit.
// Logging stays on, which is where the detail belongs.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.sid_length', '48');

$config = require __DIR__ . '/config.php';
// Terminating TLS in front of the app should not require an edit to config.php, but a proxy that
// forwards plain HTTP must not be able to claim otherwise unless the operator says so there.
$secure = (bool) $config['session_secure_cookie']
    || (($_SERVER['HTTPS'] ?? '') !== '' && strcasecmp((string) $_SERVER['HTTPS'], 'off') !== 0);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $secure,
]);
session_name('portfolio_session');
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Content-Type: text/html; charset=UTF-8');
