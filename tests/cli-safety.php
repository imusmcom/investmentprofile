<?php
declare(strict_types=1);

/** CLI ownership and backfill safety; every database and server lives under /tmp. */
$root = dirname(__DIR__);
$work = '/tmp/portfolio-cli-safety-' . bin2hex(random_bytes(6));
if (!mkdir($work, 0700, true) && !is_dir($work)) {
    fwrite(STDERR, "無法建立 /tmp scratch：{$work}\n");
    exit(2);
}

$fail = 0;
$stub = null;
$ok = static function (string $name, bool $pass, string $got = '') use (&$fail): void {
    echo ($pass ? '  ok   ' : '  FAIL ') . $name . (!$pass && $got !== '' ? "（{$got}）" : '') . "\n";
    if (!$pass) {
        $fail++;
    }
};
$run = static function (array $command, array $env = []) use ($work, $root): array {
    $stdout = $work . '/stdout-' . bin2hex(random_bytes(3)) . '.txt';
    $stderr = $work . '/stderr-' . bin2hex(random_bytes(3)) . '.txt';
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $stdout, 'w'],
        2 => ['file', $stderr, 'w'],
    ], $pipes, $root, array_merge(getenv(), $env));
    if (!is_resource($process)) {
        throw new RuntimeException('無法啟動測試子程序');
    }
    fclose($pipes[0]);
    $code = proc_close($process);
    $result = [
        'code' => $code,
        'out' => (string) file_get_contents($stdout),
        'err' => (string) file_get_contents($stderr),
    ];
    unlink($stdout);
    unlink($stderr);
    return $result;
};
$pdoAt = static fn (string $dir): PDO => new PDO('sqlite:' . $dir . '/portfolio.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$removeTree = static function (string $dir) use (&$removeTree): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
};
$cleanup = static function () use (&$stub, &$fail, $work, $removeTree): void {
    if (is_resource($stub)) {
        proc_terminate($stub);
        proc_close($stub);
    }
    if ($fail > 0) {
        fwrite(STDERR, "CLI safety 除錯資料保留在：{$work}\n");
        return;
    }
    $removeTree($work);
};
register_shutdown_function($cleanup);

try {
    echo "-- seed --\n";
    $seedDir = $work . '/seed';
    mkdir($seedDir, 0700);
    $seed = static fn (array $extra = []): array => $run(
        array_merge([PHP_BINARY, $root . '/bin/seed-demo.php', '--data-dir=' . $seedDir], $extra),
        ['QUOTE_ENABLED' => 'false']
    );
    $first = $seed();
    $pdo = $pdoAt($seedDir);
    $ok('首次 seeding 成功', $first['code'] === 0, $first['out'] . $first['err']);
    $ok('建立兩份有所有權記錄的示範組合', (int) $pdo->query('SELECT COUNT(*) FROM portfolios')->fetchColumn() === 2
        && (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key='seed_demo.ownership.v1'")->fetchColumn() === 1);
    $pdo->exec("INSERT INTO portfolios (name, description) VALUES ('Demo 人為保留', '不是本工具建立的')");
    $humanId = (int) $pdo->lastInsertId();
    $replay = $seed(['--force']);
    $ok('--force 可重播未修改的本工具資料', $replay['code'] === 0, $replay['out'] . $replay['err']);
    $ok('同名 Demo 開頭的人為組合仍保留', (int) $pdo->query('SELECT COUNT(*) FROM portfolios WHERE id=' . $humanId)->fetchColumn() === 1);

    $pdo->exec("UPDATE transactions SET note='人為修改' WHERE portfolio_id=(SELECT MIN(id) FROM portfolios WHERE name='Demo 長期投資') LIMIT 1");
    $before = $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    $manifest = $pdo->query("SELECT value FROM settings WHERE key='seed_demo.ownership.v1'")->fetchColumn();
    $refused = $seed(['--force']);
    $ok('修改過指紋的示範組合拒絕 --force', $refused['code'] === 1 && str_contains($refused['err'], '指紋不符'), $refused['out'] . $refused['err']);
    $ok('拒絕後 seeding 整筆 rollback，人為修改與人為組合都在', (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn() === (int) $before
        && (int) $pdo->query('SELECT COUNT(*) FROM portfolios WHERE id=' . $humanId)->fetchColumn() === 1
        && $pdo->query("SELECT value FROM settings WHERE key='seed_demo.ownership.v1'")->fetchColumn() === $manifest);

    echo "-- backfill --\n";
    $backfillDir = $work . '/backfill';
    $stubDir = $work . '/stub';
    mkdir($backfillDir, 0700);
    mkdir($stubDir, 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('無法取得本機 stub 埠：' . $errstr);
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    fclose($socket);
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $stub = proc_open([
        PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/tests/quote-stub.php',
    ], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $stubDir . '/server.log', 'a'],
        2 => ['file', $stubDir . '/server.log', 'a'],
    ], $stubPipes, $root, array_merge(getenv(), [
        'QUOTE_STUB_DIR' => $stubDir,
        'QUOTE_STUB_TODAY' => $today,
    ]));
    if (!is_resource($stub)) {
        throw new RuntimeException('無法啟動本機報價 stub');
    }
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $health = @file_get_contents('http://127.0.0.1:' . $port . '/health', false, stream_context_create([
            'http' => ['timeout' => 1],
        ]));
        if ($health === "ok\n") {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    $ok('本機報價 stub 就緒', $ready);

    putenv('PORTFOLIO_DATA_DIR=' . $backfillDir);
    require $root . '/app/bootstrap.php';
    $pdo = App\Database::pdo();
    App\QuoteService::setEndpoint('https://example.com:' . $port);
    App\Settings::put('quote_source', 'yahoo');
    $dates = [
        (new DateTimeImmutable($today . ' -3 days'))->format('Y-m-d'),
        (new DateTimeImmutable($today . ' -2 days'))->format('Y-m-d'),
        $today,
    ];
    $pdo->exec("INSERT INTO instruments (symbol,name,market,yahoo_symbol,currency,asset_class) VALUES ('BFILL','BFILL','US','BFILL','USD','EQUITY')");
    $iid = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO prices (instrument_id,as_of,close,adj_close,currency,source) VALUES (?,?,?,?,?,?)');
    $insert->execute([$iid, $dates[0], 19.0, null, 'USD', 'manual']);
    $insert->execute([$iid, $dates[1], 20.0, 20.5, 'USD', 'manual']);
    $insert->execute([$iid, $dates[2], 42.5, null, 'USD', 'manual']);
    $beforeRows = $pdo->query('SELECT as_of, close, adj_close FROM prices ORDER BY as_of')->fetchAll(PDO::FETCH_ASSOC);
    $quoteTransport = $root . '/tests/quote-transport.php';
    $backfill = static function (string $flag) use ($backfillDir, $iid, $root, $quoteTransport, $run): array {
        $flags = $flag === '' ? [] : [$flag];
        return $run(array_merge([
            PHP_BINARY, '-d', 'auto_prepend_file=' . $quoteTransport,
            $root . '/bin/backfill-adj.php', '--data-dir=' . $backfillDir, '--instrument=' . $iid,
        ], $flags), [
            'QUOTE_ENABLED' => 'true',
            'QUOTE_REFRESH_BUDGET' => '1',
            'PORTFOLIO_QUOTE_STUB_HOST' => 'example.com',
        ]);
    };
    $dry = $backfill('--dry-run');
    $ok('dry-run 只列 NULL 列與來源日期的交集，不把四根來源全算成四列', $dry['code'] === 0
        && str_contains($dry['out'], '來源給了 4 根 adjclose，補進 會寫 2 列'), $dry['out'] . $dry['err']);
    $ok('dry-run 完全沒有改價格', $pdo->query('SELECT as_of, close, adj_close FROM prices ORDER BY as_of')->fetchAll(PDO::FETCH_ASSOC) === $beforeRows);
    $actual = $backfill('');
    $ok('實際回填只寫兩列 NULL，且 CLI 不受網頁一秒預算限制', $actual['code'] === 0
        && str_contains($actual['out'], '完成：寫入 2 列'), $actual['out'] . $actual['err']);
    $afterRows = $pdo->query('SELECT as_of, close, adj_close FROM prices ORDER BY as_of')->fetchAll(PDO::FETCH_ASSOC);
    $ok('既有非 NULL adj_close 與 close 都沒被覆蓋，也沒有新增價格列', count($afterRows) === 3
        && $afterRows[1]['close'] === $beforeRows[1]['close'] && (float) $afterRows[1]['adj_close'] === 20.5
        && array_column($afterRows, 'close') === array_column($beforeRows, 'close')
        && (float) $afterRows[0]['adj_close'] === 18.6 && (float) $afterRows[2]['adj_close'] === 41.44);

    echo "-- fx-only --\n";
    $pdo->exec("INSERT INTO fx_rates (currency,units_per_usd,as_of,source,manual,fetched_at)
                VALUES ('GBP',1.282051282051282,'2026-11-24','yahoo',0,'2000-01-01 00:00:00')");
    $fxOnly = $run([
        PHP_BINARY, '-d', 'auto_prepend_file=' . $quoteTransport,
        $root . '/bin/refresh-prices.php', '--data-dir=' . $backfillDir, '--fx-only',
    ], [
        'QUOTE_ENABLED' => 'true',
        'PORTFOLIO_QUOTE_STUB_HOST' => 'example.com',
    ]);
    $gbp = (float) $pdo->query("SELECT units_per_usd FROM fx_rates WHERE currency='GBP'")->fetchColumn();
    $ok('--fx-only 把 Yahoo GBP=X 原樣存成 GBP-per-USD', $fxOnly['code'] === 0
        && abs($gbp - 0.78) < 0.000001, $fxOnly['out'] . $fxOnly['err'] . ' GBP=' . $gbp);
    $ok('--fx-only 不碰任何投資物價格', $pdo->query('SELECT as_of, close, adj_close FROM prices ORDER BY as_of')->fetchAll(PDO::FETCH_ASSOC) === $afterRows);
    $conflict = $run([PHP_BINARY, $root . '/bin/refresh-prices.php', '--data-dir=' . $backfillDir, '--fx-only', '--portfolio=1']);
    $ok('--fx-only 與 --portfolio 並用時在開庫前拒絕', $conflict['code'] === 2 && str_contains($conflict['err'], '不能與 --portfolio'), $conflict['out'] . $conflict['err']);
} catch (Throwable $e) {
    $ok('測試沒有未捕捉例外', false, $e->getMessage());
}

echo $fail === 0 ? "CLI SAFETY ALL PASS\n" : "CLI SAFETY FAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
