<?php require __DIR__ . '/_header.php'; ?>

<div class="panel">
  <h3><?= e($title ?? '發生錯誤') ?></h3>
  <p><?= e($message ?? '') ?></p>
  <p class="hint"><a href="<?= e(url('/')) ?>">回到總覽</a></p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
