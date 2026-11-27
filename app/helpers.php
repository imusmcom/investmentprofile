<?php
declare(strict_types=1);

use App\Database;
use App\Settings;

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A JavaScript string literal, for the inline confirm() calls.
 *
 * `e()` on its own is not enough inside an attribute: the browser decodes entities there before it
 * compiles the script, so a name carrying an apostrophe ended the literal early. The handler then
 * threw a SyntaxError, `return false` never ran, and the delete went through without asking.
 */
function js(mixed $value): string
{
    return (string) json_encode(
        (string) $value,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

/** Absolute path helper that works under the built-in server, Apache and nginx. */function url(string $path = '', array $query = []): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $path = '/' . ltrim($path, '/');
    $target = rtrim($script, '/') . ($path === '/' ? '' : $path);

    if ($query !== []) {
        $target .= '?' . http_build_query($query);
    }

    return $target;
}

/** Static file URL: served straight from the docroot, so it must not go through index.php. */
function asset(string $path): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    // dirname('/index.php') yields '\' on Windows, which would make the href protocol-relative.
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $file = ltrim($path, '/');

    // 面板的 nginx 對 .css／.js 設了 `expires 12h`，所以樣式改完若不換網址，瀏覽器最長 12 小時
    // 還在拿舊檔——看的人只會以為「改了沒用」。掛上檔案的修改時間：動過樣式網址就跟著換，
    // 沒動過就還是同一個網址、照樣命中快取。
    $disk = dirname(__DIR__) . '/public/' . $file;
    $version = is_file($disk) ? (string) filemtime($disk) : '';

    return $base . '/' . $file . ($version === '' ? '' : '?v=' . $version);
}

function current_portfolio_id(): int
{
    $session = (int) ($_SESSION['portfolio_id'] ?? 0);

    if ($session > 0) {
        $exists = (int) Database::scalar('SELECT COUNT(*) FROM portfolios WHERE id = ?', [$session], 0);
        if ($exists > 0) {
            return $session;
        }
    }

    return (int) Database::scalar('SELECT MIN(id) FROM portfolios', [], 0);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('表單憑證已過期，請重新整理頁面後再試一次。');
    }
}

/** @return array<int, array{type:string, message:string}> */
function flash_take(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $items;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function fmt_num(float|int|null $v, int $dec = 2): string
{
    if ($v === null) {
        return '--';
    }

    return number_format((float) $v, $dec);
}

/** Currency symbols for the handful of currencies a personal portfolio usually touches. */
function currency_symbol(string $code): string
{
    return match (strtoupper($code)) {
        'TWD', 'NTD' => 'NT$',
        'USD'        => 'US$',
        'HKD'        => 'HK$',
        'CNY'        => '¥',
        'JPY'        => '¥',
        'GBP'        => '£',
        'EUR'        => '€',
        'CAD'        => 'C$',
        'SGD'        => 'S$',
        'MYR'        => 'RM',
        'AUD'        => 'A$',
        default      => strtoupper($code) . ' ',
    };
}

function fmt_money(float|int|null $v, string $currency = '', int $dec = 2): string
{
    if ($v === null) {
        return '--';
    }

    $sign = $v < 0 ? '-' : '';
    $body = number_format(abs((float) $v), $dec);

    return $sign . ($currency !== '' ? currency_symbol($currency) : '') . $body;
}

/** Unit prices of cheap coins must not collapse to two decimals: 0.05129 is not 0.05. */
function fmt_price(float|int|null $v, string $currency = ''): string
{
    if ($v === null) {
        return '--';
    }

    $abs = abs((float) $v);

    return fmt_money($v, $currency, $abs >= 1 ? 2 : ($abs >= 0.01 ? 4 : 6));
}

function fmt_signed(float|int|null $v, string $currency = '', int $dec = 2): string
{
    if ($v === null) {
        return '--';
    }

    return ($v > 0 ? '+' : '') . fmt_money($v, $currency, $dec);
}

function fmt_pct(float|int|null $v, int $dec = 2): string
{
    if ($v === null) {
        return '--';
    }

    return ($v > 0 ? '+' : '') . number_format((float) $v, $dec) . '%';
}

/** CSS class used to colour gains green and losses red. */
function tone(float|int|null $v): string
{
    if ($v === null) {
        return 'muted';
    }

    return $v > 0 ? 'gain' : ($v < 0 ? 'loss' : 'muted');
}

/**
 * The timestamp written into every `created_at` / `fetched_at` column.
 *
 * SQLite's own `datetime('now')` is UTC no matter what PHP is set to, so a database holding both
 * kinds of stamp is unreadable. Writing it here keeps the whole file on the one clock the app runs on.
 */
function now_stamp(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Units of $currency per 1 USD, preferring manual overrides stored in fx_rates.
 *
 * @param array<string,?float>|null $fxCache Optional request-local memo, including missing/invalid rates.
 */
function fx_to_usd(string $currency, ?array &$fxCache = null): ?float
{
    $code = strtoupper($currency);
    if ($code === 'USD') {
        return 1.0;
    }
    if ($fxCache !== null && array_key_exists($code, $fxCache)) {
        return $fxCache[$code];
    }

    $row = Database::one('SELECT units_per_usd FROM fx_rates WHERE currency = ?', [$code]);
    if ($row === null) {
        $rate = null;
    } else {
        $rate = (float) $row['units_per_usd'];
        if (!is_finite($rate) || $rate <= 0.0) {
            $rate = null;
        }
    }
    if ($fxCache !== null) {
        $fxCache[$code] = $rate;
    }

    return $rate;
}

/**
 * Convert an amount from one currency into the portfolio base currency.
 * Omitting the fourth argument retains the original uncached behavior.
 *
 * @param array<string,?float>|null $fxCache Optional request-local memo shared by conversions in one render.
 */
function convert(float $amount, string $from, string $to, ?array &$fxCache = null): array
{
    $from = strtoupper($from);
    $to = strtoupper($to);

    if ($from === $to) {
        return ['value' => is_finite($amount) ? $amount : null, 'converted' => is_finite($amount)];
    }

    $fromRate = fx_to_usd($from, $fxCache);
    $toRate = fx_to_usd($to, $fxCache);

    if ($fromRate === null || $toRate === null || $fromRate <= 0.0 || $toRate <= 0.0) {
        return ['value' => null, 'converted' => false];
    }
    $value = $amount / $fromRate * $toRate;

    return ['value' => is_finite($value) ? $value : null, 'converted' => is_finite($value)];
}

function base_currency(): string
{
    return Settings::baseCurrency();
}
