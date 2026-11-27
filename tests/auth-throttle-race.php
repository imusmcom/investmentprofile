<?php
declare(strict_types=1);

namespace App {
    // A locked caller must return before either delay path or any password hashing work.
    function usleep(int $microseconds): void
    {
        throw new \RuntimeException('locked caller attempted to sleep');
    }

    function password_verify(string $password, string $hash): bool
    {
        if (!empty($GLOBALS['auth_test_rotate_fail'])) {
            return \password_verify($password, $hash);
        }
        throw new \RuntimeException('locked caller attempted password verification');
    }

    function session_regenerate_id(bool $delete_old_session = false): bool
    {
        return !empty($GLOBALS['auth_test_rotate_fail'])
            ? false
            : \session_regenerate_id($delete_old_session);
    }

    function password_needs_rehash(string $hash, string|int $algorithm, array $options = []): bool
    {
        throw new \RuntimeException('locked caller attempted password rehash work');
    }
}

namespace {
    if (($argv[1] ?? '') === 'reserve') {
        putenv('PORTFOLIO_DATA_DIR=' . $argv[2]);
        require dirname(__DIR__) . '/app/bootstrap.php';
        $method = new ReflectionMethod(App\Auth::class, 'reserveAttempt');
        $left = (int) $method->invoke(null);
        echo $left > 0 ? '1' : '0';
        exit(0);
    }

    $dir = '/tmp/portfolio-auth-race-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    });

    putenv('PORTFOLIO_DATA_DIR=' . $dir);
    require dirname(__DIR__) . '/app/bootstrap.php';
    App\Database::pdo();

    $processes = [];
    $pipes = [];
    for ($i = 0; $i < App\Auth::MAX_FAILS + 3; $i++) {
        $spec = [1 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, __FILE__, 'reserve', $dir], $spec, $pipe);
        if (!is_resource($process)) {
            fwrite(STDERR, "cannot start reservation worker\n");
            exit(1);
        }
        $processes[] = $process;
        $pipes[] = $pipe[1];
    }
    $allowed = 0;
    foreach ($pipes as $pipe) {
        $result = stream_get_contents($pipe);
        fclose($pipe);
        if ($result === '0') {
            $allowed++;
        } elseif ($result !== '1') {
            fwrite(STDERR, "invalid reservation worker output: {$result}\n");
            exit(1);
        }
    }
    foreach ($processes as $process) {
        if (proc_close($process) !== 0) {
            fwrite(STDERR, "reservation worker failed\n");
            exit(1);
        }
    }
    $until = (int) App\Database::scalar('SELECT locked_until FROM login_throttle WHERE ip = ?', [App\Auth::ip()], 0);
    if ($allowed !== App\Auth::MAX_FAILS || $until <= time()) {
        fwrite(STDERR, "allowed={$allowed} locked_until={$until}\n");
        exit(1);
    }
    echo "  ok   concurrent reservations enforce the per-IP limit\n";

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $now = time();
    App\Database::run(
        'INSERT INTO login_throttle (ip, fail_count, locked_until, seen_at) VALUES (?, ?, 0, ?)
         ON CONFLICT(ip) DO UPDATE SET fail_count = excluded.fail_count, locked_until = 0, seen_at = excluded.seen_at',
        ['*global', 20, $now]
    );
    $reserve = new ReflectionMethod(App\Auth::class, 'reserveAttempt');
    for ($i = 0; $i <= App\Auth::MAX_FAILS; $i++) {
        $reserve->invoke(null);
    }
    $ipBefore = App\Database::one('SELECT * FROM login_throttle WHERE ip = ?', [App\Auth::ip()]);
    $globalBefore = App\Database::one('SELECT * FROM login_throttle WHERE ip = ?', ['*global']);
    $until = (int) App\Database::scalar('SELECT locked_until FROM login_throttle WHERE ip = ?', [App\Auth::ip()], 0);
    $left = max(0, $until - time());
    $minutes = intdiv($left, 60);
    $seconds = $left % 60;
    $expected = '嘗試次數過多，已暫時鎖定，請 ' . ($minutes > 0 ? $minutes . ' 分 ' : '') . $seconds . ' 秒後再試。';
    $_SESSION = ['marker' => 'preserved'];
    $message = '';
    $lockedFailure = null;
    try {
        $message = App\Auth::attempt('admin', 'must-not-be-hashed');
    } catch (Throwable $e) {
        $lockedFailure = $e->getMessage();
    }
    $ipAfter = App\Database::one('SELECT * FROM login_throttle WHERE ip = ?', [App\Auth::ip()]);
    $globalAfter = App\Database::one('SELECT * FROM login_throttle WHERE ip = ?', ['*global']);
    if ($lockedFailure !== null || $message !== $expected
        || $ipAfter !== $ipBefore || $globalAfter !== $globalBefore
        || ($_SESSION['marker'] ?? '') !== 'preserved') {
        fwrite(STDERR, "locked attempt failed: error={$lockedFailure} message={$message} expected={$expected}\n");
        exit(1);
    }
    echo "  ok   locked callers skip global sleep, hashing, and throttle/session changes\n";

    $GLOBALS['auth_test_rotate_fail'] = true;
    App\Auth::createAccount('admin', 'original-pass-1');
    $beforeHash = (string) App\Database::scalar('SELECT password_hash FROM access WHERE id = 1');
    $_SESSION['auth_stamp'] = 'existing-stamp';
    $rotationError = null;
    try {
        App\Auth::changePassword('original-pass-1', 'replacement-pass-2', 'replacement-pass-2');
    } catch (RuntimeException $e) {
        $rotationError = $e->getMessage();
    }
    $afterHash = (string) App\Database::scalar('SELECT password_hash FROM access WHERE id = 1');
    if ($rotationError !== '無法更新登入 session，請重新登入後再試。'
        || $afterHash !== $beforeHash
        || ($_SESSION['auth_stamp'] ?? '') !== 'existing-stamp') {
        fwrite(STDERR, "failed session rotation did not fail closed\n");
        exit(1);
    }
    echo "  ok   failed session rotation leaves the password and auth stamp unchanged\n";

    echo "AUTH THROTTLE RACE ALL PASS\n";
}
