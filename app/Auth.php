<?php
declare(strict_types=1);

namespace App;

use Throwable;

/**
 * One shared credential for the whole installation, checked before any route runs.
 * It is a door, not a user directory: nothing here scopes the data itself.
 */
final class Auth
{
    public const MAX_FAILS = 5;
    public const LOCK_SECONDS = 900;          // 15 分鐘
    public const MIN_PASSWORD = 10;
    public const ABSOLUTE_SECONDS = 6 * 3600;
    private const IDLE_SECONDS = 8 * 3600;    // 8 小時沒動作就要重新登入
    private const FAIL_WINDOW = 3600;         // 一小時沒動靜就重新計數，呼應「連續」錯五次
    private const FAIL_PRUNE = 86400;         // 一天沒出現的來源 IP 不再留底
    private const FAIL_DELAY_US = 400_000;    // 讓腳本沒法快速輪詢，又不拖慢正常使用
    private const PASSWORD_BUCKET = 'password';
    private const GLOBAL_KEY = '*global';
    private const GLOBAL_WINDOW = 900;
    private const GLOBAL_THRESHOLD = 20;
    private const GLOBAL_DELAY_US = 3_000_000;
    private const HASH_ALGORITHM = PASSWORD_BCRYPT;
    private const HASH_OPTIONS = ['cost' => 12];

    public static function account(): ?array
    {
        return Database::one('SELECT * FROM access WHERE id = 1');
    }

    public static function configured(): bool
    {
        return self::account() !== null;
    }

    public static function user(): string
    {
        return (string) ($_SESSION['user'] ?? '');
    }

    /**
     * REMOTE_ADDR only. X-Forwarded-Proto/X-Forwarded-For are attacker-controlled unless a
     * reverse proxy sets them, so trusting them here would let anyone pick their own lockout key.
     */
    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function signedIn(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        if (time() - (int) ($_SESSION['login_at'] ?? 0) >= self::ABSOLUTE_SECONDS) {
            self::clear();
            flash('登入狀態已超過 6 小時，請重新登入。', 'warn');

            return false;
        }
        if (time() - (int) ($_SESSION['authed_at'] ?? 0) > self::IDLE_SECONDS) {
            self::clear();
            flash('登入狀態已超過 8 小時未使用，請重新登入。', 'warn');

            return false;
        }
        // Password changed (or the account re-created from the CLI) => every older session is void.
        if (!hash_equals((string) ($_SESSION['auth_stamp'] ?? ''), self::stamp())) {
            self::clear();

            return false;
        }
        $_SESSION['authed_at'] = time();

        return true;
    }

    /**
     * Everything except the login page itself is behind the door.
     *
     * @return bool true when the request may continue
     */
    public static function guard(string $path): bool
    {
        if ($path === '/login') {
            return true;
        }
        if (self::signedIn()) {
            return true;
        }
        if (!self::configured()) {
            // Without this the app would bounce in a loop onto a page that cannot be filled in yet.
            return false;
        }

        App::redirect('/login', ['back' => $path]);

        return false;
    }

    /** '' when the credentials are good, otherwise the one sentence to show (never says which half failed). */
    public static function attempt(string $username, string $password): string
    {
        $left = self::reserveAttempt();
        if ($left > 0) {
            return '嘗試次數過多，已暫時鎖定，請 ' . self::humanLock($left) . '後再試。';
        }

        // Only callers allowed to try credentials pay the distributed-failure delay or hash cost.
        // Distributed failures only add delay; they never arm the global row's lockout.
        self::slowGlobalAttempt();

        $row = self::account();
        if ($row === null) {
            self::recordFailure();
            usleep(self::FAIL_DELAY_US);

            return self::createHint();
        }

        $known = trim((string) $row['username']);
        $userOk = $username !== '' && strcasecmp($known, $username) === 0;
        $passOk = $password !== '' && password_verify($password, (string) $row['password_hash']);

        if (!$userOk || !$passOk) {
            self::recordFailure();
            usleep(self::FAIL_DELAY_US);

            return '帳號或密碼不正確。';
        }

        if (password_needs_rehash((string) $row['password_hash'], self::HASH_ALGORITHM, self::HASH_OPTIONS)) {
            Database::run('UPDATE access SET password_hash = ? WHERE id = 1', [
                password_hash($password, self::HASH_ALGORITHM, self::HASH_OPTIONS),
            ]);
        }

        self::clearThrottle();

        // A session id handed out on the login page must not survive the upgrade to logged-in.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['user'] = $known;
        $_SESSION['login_at'] = time();
        $_SESSION['authed_at'] = time();
        $_SESSION['auth_stamp'] = self::stamp();

        return '';
    }

    public static function logout(): void
    {
        self::clear();
        // The login form is rendered without a signed-in session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Used by bin/create-admin.php; refusing to quietly overwrite is that script's job, not this one's. */
    public static function createAccount(string $username, string $password): void
    {
        self::assertUsable($username, $password);
        Database::run(
            'INSERT INTO access (id, username, password_hash, created_at) VALUES (1, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET username = excluded.username,
                                           password_hash = excluded.password_hash',
            [$username, password_hash($password, self::HASH_ALGORITHM, self::HASH_OPTIONS), now_stamp()]
        );
    }

    public static function changePassword(string $old, string $new, string $confirm): void
    {
        $left = self::reserveAttempt(self::PASSWORD_BUCKET);
        if ($left > 0) {
            throw new UserError('密碼變更嘗試次數過多，已暫時鎖定，請 ' . self::humanLock($left) . '後再試。');
        }

        $row = self::account();
        if ($row === null) {
            throw new UserError(self::createHint());
        }
        if ($old === '' || !password_verify($old, (string) $row['password_hash'])) {
            self::recordFailure(self::PASSWORD_BUCKET);
            usleep(self::FAIL_DELAY_US);

            throw new UserError('目前密碼不正確。');
        }
        self::assertUsable((string) $row['username'], $new);
        if ($new !== $confirm) {
            throw new UserError('兩次輸入的新密碼不一樣。');
        }
        if ($new === $old) {
            throw new UserError('新密碼不能跟現在用的相同。');
        }

        $hash = password_hash($new, self::HASH_ALGORITHM, self::HASH_OPTIONS);
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('無法更新登入 session，請重新登入後再試。');
        }

        Database::run(
            'UPDATE access SET password_hash = ? WHERE id = 1',
            [$hash]
        );
        self::clearThrottle(self::PASSWORD_BUCKET);

        // Stay signed in here; every other browser is pushed back to the login page by the stamp change.
        // login_at intentionally stays fixed so changing a password cannot extend the absolute lifetime.
        $_SESSION['auth_stamp'] = self::stamp();
        $_SESSION['authed_at'] = time();
    }

    private static function assertUsable(string $username, string $password): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{1,31}$/', $username) !== 1) {
            throw new UserError('帳號只能是 2～32 個半形字母、數字或 . _ @ - 符號。');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new UserError('密碼至少要 ' . self::MIN_PASSWORD . ' 個字元。');
        }
    }

    private static function key(string $bucket): string
    {
        if ($bucket === 'login') {
            // Preserve the existing rows: login throttles have always been keyed by the bare IP.
            return self::ip();
        }
        if ($bucket === self::GLOBAL_KEY) {
            return self::GLOBAL_KEY;
        }

        // A prefixed bucket cannot equal REMOTE_ADDR, so password failures cannot clear or arm login locks.
        return $bucket . ':' . self::ip();
    }

    private static function reserveAttempt(string $bucket = 'login'): int
    {
        $pdo = Database::pdo();
        $key = self::key($bucket);
        $now = time();
        $pdo->beginTransaction();
        try {
            Database::run('INSERT OR IGNORE INTO login_throttle (ip, fail_count, locked_until, seen_at) VALUES (?, 0, 0, ?)', [$key, $now]);
            $row = Database::one('SELECT fail_count, locked_until, seen_at FROM login_throttle WHERE ip = ?', [$key]);
            $until = (int) ($row['locked_until'] ?? 0);
            if ($until > $now) {
                $pdo->commit();

                return $until - $now;
            }

            $count = $now - (int) ($row['seen_at'] ?? 0) > self::FAIL_WINDOW
                ? 1
                : (int) ($row['fail_count'] ?? 0) + 1;
            if ($count > self::MAX_FAILS) {
                Database::run('UPDATE login_throttle SET fail_count = 0, locked_until = ? WHERE ip = ?', [$now + self::LOCK_SECONDS, $key]);
                $pdo->commit();

                return self::LOCK_SECONDS;
            }

            Database::run('UPDATE login_throttle SET fail_count = ?, seen_at = ? WHERE ip = ?', [$count, $now, $key]);
            $pdo->commit();

            return 0;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function recordFailure(string $bucket = 'login'): void
    {
        $now = time();
        if ($bucket === 'login') {
            // The reserved global key aggregates distributed login failures for delay only. Its
            // locked_until stays zero, so a botnet can never lock the legitimate owner out.
            Database::run(
                'INSERT INTO login_throttle (ip, fail_count, locked_until, seen_at) VALUES (?, 1, 0, ?)
                 ON CONFLICT(ip) DO UPDATE SET
                   fail_count = CASE WHEN ? - login_throttle.seen_at > ' . self::GLOBAL_WINDOW . '
                                     THEN 1 ELSE login_throttle.fail_count + 1 END,
                   seen_at = excluded.seen_at',
                [self::GLOBAL_KEY, $now, $now]
            );
        }

        // A row per visiting address is otherwise permanent, so anything untouched for a day is dropped.
        Database::run('DELETE FROM login_throttle WHERE seen_at < ?', [$now - self::FAIL_PRUNE]);
    }

    private static function slowGlobalAttempt(): void
    {
        $failures = (int) Database::scalar(
            'SELECT fail_count FROM login_throttle WHERE ip = ? AND seen_at >= ?',
            [self::GLOBAL_KEY, time() - self::GLOBAL_WINDOW],
            0
        );
        if ($failures >= self::GLOBAL_THRESHOLD) {
            usleep(self::GLOBAL_DELAY_US);
        }
    }

    private static function clearThrottle(string $bucket = 'login'): void
    {
        Database::run('DELETE FROM login_throttle WHERE ip = ?', [self::key($bucket)]);
    }

    /**
     * Derived from the stored hash, never from the password, so rotating a hash with
     * password_needs_rehash() alone keeps live sessions alive.
     */
    private static function stamp(): string
    {
        $hash = (string) (self::account()['password_hash'] ?? '');

        return $hash === '' ? "\0no-account" : substr(hash('sha256', $hash), 0, 32);
    }

    private static function clear(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['login_at'], $_SESSION['authed_at'], $_SESSION['auth_stamp']);
    }

    private static function humanLock(int $left): string
    {
        $m = intdiv($left, 60);
        $s = $left % 60;

        return ($m > 0 ? $m . ' 分 ' : '') . $s . ' 秒';
    }

    public static function createHint(): string
    {
        return '這套系統還沒有登入帳號，請在伺服器上於專案目錄執行：'
            . 'php bin/create-admin.php --username=你的帳號（會要求輸入密碼，不會顯示在螢幕上）。';
    }
}
