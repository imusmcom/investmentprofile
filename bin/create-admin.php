<?php
declare(strict_types=1);

/**
 * The only way to create (or overwrite) the shared login, on purpose: whoever can run this
 * on the machine already owns the database file, so a web-based signup would only add a race.
 *
 *   php bin/create-admin.php --username=alice
 *   php bin/create-admin.php --username=alice --password-from-stdin
 *   php bin/create-admin.php --username=alice --force
 */

require __DIR__ . '/../app/cli.php';

if (PHP_SAPI !== 'cli') {
    exit("這是命令列程式，請在終端機裡用 php bin/create-admin.php 執行。\n");
}

$usage = 'php bin/create-admin.php --username=帳號 [--force] [--password=... | --password-from-stdin] [--data-dir=目錄] [--tz=時區]';
$opts = cli_setup($argv, ['help', 'h', 'force', 'password-from-stdin'], ['username', 'password', 'data-dir', 'tz'], $usage);

if (isset($opts['help']) || isset($opts['h'])) {
    echo <<<TXT
用法：php bin/create-admin.php --username=帳號 [--force] [--password=... | --password-from-stdin] [--data-dir=目錄] [--tz=時區]

  --username   登入帳號（2～32 個半形字元）
  --force      已存在帳號時覆蓋它；覆蓋後所有瀏覽器的登入狀態立刻失效
  --password   直接給密碼（會留在 shell 的歷史紀錄裡，除非你是從別的程式餵進去的）
  --password-from-stdin  從標準輸入讀密碼，適用於 printf '%s' '密碼' | php bin/create-admin.php ...
  --data-dir   資料庫所在目錄，預設讀 PORTFOLIO_DATA_DIR，否則使用正式部署資料庫或專案下的 data/
  --tz         時間戳用的時區；未給時沿用 php.ini 的 date.timezone，它也沒設就用 Asia/Hong_Kong（UTC+8，不撥夏令時間）

寫錯的參數會直接中止（回 2），不會被忽略後照樣動到預設資料庫。

TXT;
    exit(0);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;

$username = trim((string) ($opts['username'] ?? ''));
if ($username === '') {
    fwrite(STDERR, "缺少 --username=帳號\n用法：{$usage}\n");
    exit(2);
}

$existing = Auth::account();
if ($existing !== null && !isset($opts['force'])) {
    fwrite(STDERR, sprintf(
        "帳號已存在：%s（建立於 %s）\n確定要連密碼一起蓋掉，請再加 --force。\n",
        (string) $existing['username'],
        (string) $existing['created_at']
    ));
    exit(1);
}

if (isset($opts['password-from-stdin'])) {
    $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
} elseif (array_key_exists('password', $opts)) {
    fwrite(STDERR, "⚠️  --password 在 ps 輸出中可見，建議改用互動式輸入。\n");
    $password = (string) $opts['password'];
} else {
    $password = prompt($username);
}

try {
    Auth::createAccount($username, $password);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

printf(
    "%s帳號「%s」%s\n",
    $existing === null ? '已建立' : '已更新',
    $username,
    $existing === null ? '' : '（密碼已覆蓋，其他瀏覽器的登入狀態在下一次請求就會失效）'
);
echo '資料庫裡只存 hash，不存明文。現在打開網站就能登入；忘記密碼時重跑這行指令並加 --force。' . "\n";
exit(0);

/** Hidden input where the terminal allows it, with a warning where it does not. */
function prompt(string $username): string
{
    $hidden = false;
    if (function_exists('exec')) {
        // stty also exists under git-bash on Windows, so ask for it wherever this is running rather
        // than assuming a platform cannot hide input — and saying nothing when it could not.
        exec('stty -echo 2>/dev/null', $ignored, $code);
        $hidden = (int) $code === 0;
    }

    echo "為「{$username}」輸入密碼（至少 " . Auth::MIN_PASSWORD . " 個字元）";
    if (!$hidden) {
        echo "\n（這個終端機無法隱藏輸入，密碼會顯示在螢幕上、也可能留在卷軸裡；改用 --password-from-stdin 可以避掉。）";
    }
    echo '：';

    $line = fgets(STDIN);
    if ($hidden) {
        exec('stty echo');
    }
    echo "\n";

    if ($line === false) {
        fwrite(STDERR, "讀不到輸入，請改用 --password 或 --password-from-stdin。\n");
        exit(1);
    }

    return rtrim($line, "\r\n");
}
