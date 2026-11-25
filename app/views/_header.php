<?php
/** @var string $title */
$here = \App\App::path();
if (str_ends_with($here, '.php')) {
    $here = substr($here, 0, -4);
}

$links = [
    '/'            => '總覽',
    '/reports'     => '回報',
    '/transactions' => '交易紀錄',
    '/instruments' => '投資物',
    '/portfolios'  => '組合',
    '/settings'    => '設定',
    '/laboratory'  => '實驗室',
];
$strategyActive = str_starts_with($here, '/strategy');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '投資組合') ?>｜投資組合追蹤系統</title>
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
</head>
<body>
<header class="topbar">
  <h1>投資組合追蹤系統 <span class="brand-sub">PHP + SQLite</span></h1>
  <nav class="main">
    <?php foreach ($links as $href => $label): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $href === '/' ? ($here === '/' ? 'active' : '') : (str_starts_with($here, $href) ? 'active' : '') ?>">
        <?= e($label) ?>
      </a>
      <?php if ($href === '/settings'): ?>
        <details class="nav-dropdown"<?= $strategyActive ? ' open' : '' ?>>
          <summary>交易策略</summary>
          <a href="<?= e(url('/strategy')) ?>" class="nav-submenu<?= $strategyActive ? ' active' : '' ?>">樂活五線譜</a>
        </details>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="session">
    <span>登入者 <?= e(\App\Auth::user()) ?></span>
    <form method="post" action="<?= e(url('/logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit">登出</button>
    </form>
  </div>
</header>
<main>
<?php foreach (($flashes ?? []) as $f): ?>
  <div class="flash <?= e($f['type']) ?>">
    <?= e($f['message']) ?>
  </div>
<?php endforeach; ?>
<?php if (!empty($flash_detail)): ?>
  <div class="flash warn">
    <strong>部分項目未更新</strong>
    <ul><?php foreach ($flash_detail as $d) { echo '<li>' . e($d) . '</li>'; } ?></ul>
  </div>
<?php endif; ?>
