<?php
/** @var string $back */
/** @var bool $setup */
/** @var string $error */
/** @var array $flashes */
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登入｜投資組合追蹤系統</title>
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
</head>
<body class="login-body">
<main class="login-main">
  <h1 class="login-brand">投資組合追蹤系統 <span class="brand-sub">PHP + SQLite</span></h1>

  <?php foreach (($flashes ?? []) as $f): ?>
    <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>

  <?php if ($error !== ''): ?>
    <div class="flash warn"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="panel login-card">
    <h3>登入</h3>

    <?php if ($setup): ?>
      <p class="hint" style="margin-top:0">
        這套系統還沒有登入帳號，所以網頁全部關在門後。請用 SSH 到伺服器上，於專案目錄執行：
      </p>
      <p><code>php bin/create-admin.php --username=你的帳號</code></p>
      <p class="hint" style="margin-top:0">
        它會要求輸入密碼（輸入時不顯示），至少 <?= (int) \App\Auth::MIN_PASSWORD ?> 個字元，存進資料庫的是 hash 而非明文。
        建好之後重新整理這頁就能登入。
      </p>
    <?php else: ?>
      <form method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <label class="field">帳號
          <input type="text" name="username" autocomplete="username" autofocus required>
        </label>
        <label class="field" style="margin-top:12px">密碼
          <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <div style="margin-top:16px"><button type="submit">登入</button></div>
      </form>
      <p class="hint">
        整個系統共用這一組帳號密碼，沒有多使用者資料隔離：登得進來的人看到的是同一份組合。
        密碼忘記了就再跑一次 <code>php bin/create-admin.php --username=你的帳號 --force</code> 蓋掉它。
      </p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
