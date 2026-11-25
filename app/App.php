<?php
declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class App
{
    /** What the browser gets for a failure the handlers did not anticipate. */
    private const UNEXPECTED = '這個動作沒有完成，而且不是表單內容的問題：程式遇到沒有預料到的狀況。'
        . '原因（可能含資料表名稱或伺服器路徑）只寫進 PHP 的錯誤日誌，不顯示在這裡。'
        . '請重試一次；一直如此的話，請到設定的「備份與還原」存一份快照再請管理員看日誌。';

    /** @var array<string, string> */
    private const ROUTES = [
        'GET /'                            => 'dashboard',
        'GET /login'                       => 'loginForm',
        'POST /login'                      => 'loginCreate',
        'POST /logout'                     => 'logoutCreate',
        'POST /password'                   => 'passwordSave',
        'GET /reports'                     => 'reports',
        'GET /transactions'                => 'transactions',
        'POST /transactions'               => 'transactionCreate',
        'POST /transactions/{id}/delete'   => 'transactionDelete',
        'GET /instruments'                 => 'instruments',
        'POST /instruments'                => 'instrumentCreate',
        'POST /instruments/{id}/price'     => 'instrumentPrice',
        'POST /instruments/{id}/delete'    => 'instrumentDelete',
        'GET /portfolios'                  => 'portfolios',
        'POST /portfolios'                 => 'portfolioCreate',
        'POST /portfolios/{id}/select'     => 'portfolioSelect',
        'POST /scope'                      => 'scopeSelect',
        'POST /portfolios/{id}/update'     => 'portfolioUpdate',
        'POST /portfolios/{id}/delete'     => 'portfolioDelete',
        'POST /cash'                       => 'cashCreate',
        'POST /cash/{id}/delete'           => 'cashDelete',
        'POST /prices/refresh'             => 'priceRefresh',
        'GET /settings'                    => 'settings',
        'POST /settings'                   => 'settingsSave',
        'GET /strategy'                    => 'strategy',
        'GET /strategy/{id}/chart'         => 'strategyChart',
        'GET /laboratory'                  => 'laboratory',
        'POST /fx'                         => 'fxSave',
        'POST /fx/delete'                  => 'fxDelete',
        'GET /export'                      => 'exportCsv',
        'POST /backup/create'              => 'backupCreate',
        'POST /backup/restore'             => 'backupRestore',
        'GET /backup/{name}/download'      => 'backupDownload',
        'POST /backup/{name}/delete'       => 'backupDelete',
        'POST /backup/{name}/restore'      => 'backupRestoreSnapshot',
    ];

    public static function run(): void
    {
        // One catch for everything the handlers did not plan for, so a request never ends on a blank
        // page. It sits outside dispatch() because the failures that need it most — a locked or unreadable
        // database — happen in guard() and in the first query of a view, not only inside a handler.
        try {
            self::dispatch();
        } catch (Throwable $e) {
            self::renderFailure($e);
        }
    }

    private static function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = self::path();

        // A body larger than post_max_size is dropped whole, and PHP says so at request startup — which
        // means session_start() has already failed by the time this runs. So this has to come before both
        // the auth guard and the CSRF check: otherwise a too-big backup file is reported as an expired
        // form token, or bounced to a login page the signed-in user was already past.
        if ($method === 'POST') {
            $tooBig = self::oversizedBody();
            if ($tooBig !== '') {
                http_response_code(400);
                Handlers::render('error', ['title' => '操作失敗', 'message' => $tooBig]);

                return;
            }
        }

        // Everything except the login page sits behind one shared credential. guard() answers the
        // signed-out case itself (redirect, or the "no account yet" page), so nothing below runs.
        if (!Auth::guard($path)) {
            Handlers::loginForm();

            return;
        }

        foreach (self::ROUTES as $pattern => $handler) {
            [$routeMethod, $routePath] = explode(' ', $pattern, 2);
            if ($routeMethod !== $method) {
                continue;
            }
            $params = self::match($routePath, $path);
            if ($params === null) {
                continue;
            }

            if ($method === 'POST') {
                verify_csrf();
            }

            try {
                Handlers::{$handler}($params);
            } catch (Throwable $e) {
                // UserError is the explicit opt-in for a sentence that is safe to show in the browser.
                // It must be checked first because it deliberately extends RuntimeException.
                if ($e instanceof UserError) {
                    http_response_code(400);
                    Handlers::render('error', ['title' => '操作失敗', 'message' => $e->getMessage()]);

                    return;
                }

                // Only UserError is safe to show. PDOException extends RuntimeException and can quote
                // SQL and absolute paths, so the exact legacy RuntimeException branch is not a trust boundary.
                self::renderFailure($e);

                return;
            }

            return;
        }

        http_response_code(404);
        Handlers::render('error', ['title' => '找不到頁面', 'message' => "沒有對應的網址：{$path}"]);
    }

    /**
     * Shows the page for a failure nobody expected, without repeating what it said.
     *
     * A PDOException names the table and the SQL; a TypeError names the file and the absolute path. Both
     * are exactly what an attacker probes for, and this installation is one shared credential away from
     * every holding in it, so the sentence stays in the log and the browser gets the shape of the problem.
     */
    private static function renderFailure(Throwable $e): void
    {
        error_log(sprintf(
            'portfolio %s %s -> %s: %s in %s:%d',
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '?')),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        http_response_code(500);

        try {
            Handlers::render('error', ['title' => '操作失敗', 'message' => self::UNEXPECTED]);
        } catch (Throwable) {
            // The error page reads the database for its own header, so the one failure that breaks
            // everything else also breaks the page meant to explain it. Say as much without one.
            echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
                . '<title>操作失敗</title></head><body><h1>操作失敗</h1><p>'
                . htmlspecialchars(self::UNEXPECTED, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p></body></html>';
        }
    }

    public static function path(): string
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');

        if (str_starts_with($uri, $script)) {
            $path = substr($uri, strlen($script));
        } else {
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
            $path = $base !== '' && str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
        }

        if ($path === false || $path === '') {
            $path = '/';
        }
        if (str_ends_with($path, '.php')) {
            $path = substr($path, 0, -4);
        }

        return '/' . trim($path, '/');
    }

    /** '' when the body got here intact, otherwise the message to show instead of a misleading 419. */
    private static function oversizedBody(): string
    {
        $limit = Backup::postLimitBytes();
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($limit === 0 || $length === 0 || $length <= $limit) {
            return '';
        }

        return '送出的內容超過伺服器能處理的上限（' . Backup::humanBytes($limit) . '），整份表單被丟掉了，資料庫沒有動到。'
            . '若是上傳備份檔，請改用 start-windows.bat／start.sh 啟動（它們把上限提高到 64 MB），'
            . '或調整 php.ini 的 post_max_size 與 upload_max_filesize。';
    }

    /** @return array<string,string>|null */
    private static function match(string $route, string $path): ?array    {
        if ($route === $path) {
            return [];
        }
        if (!str_contains($route, '{')) {
            return null;
        }

        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $route);

        if (preg_match('#^' . $regex . '$#', $path, $m) === 1) {
            return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    public static function input(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_array($value) ? $default : trim((string) $value);
    }

    public static function intInput(string $key, int $default = 0): int
    {
        $value = self::input($key, (string) $default);

        return $value === '' ? $default : (int) $value;
    }

    public static function numInput(string $key): ?float
    {
        $value = str_replace([',', ' '], '', self::input($key));
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new UserError("「{$key}」必須是數字。");
        }
        // is_numeric accepts 1e999, which becomes INF and silently poisons every total it joins.
        if (!is_finite((float) $value)) {
            throw new UserError("「{$key}」的數值超出可計算範圍。");
        }

        return (float) $value;
    }

    /**
     * Back-to destination supplied by a form, restricted to this app's own sections so a
     * crafted value cannot bounce the user somewhere else.
     */
    public static function safeBack(string $fallback = '/'): string
    {
        $back = self::input('back', $fallback);
        if (preg_match('#^/([A-Za-z0-9_-]*)#', $back, $m) !== 1) {
            return $fallback;
        }

        $allowed = ['', 'reports', 'transactions', 'instruments', 'portfolios', 'settings', 'strategy', 'laboratory'];
        if (!in_array($m[1], $allowed, true)) {
            return $fallback;
        }

        return $m[1] === '' ? '/' : '/' . $m[1];
    }

    public static function redirect(string $path, array $query = []): never
    {
        header('Location: ' . url($path, $query), true, 303);
        exit;
    }
}
