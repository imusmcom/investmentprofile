<form method="post" action="<?= e(url('/prices/refresh')) ?>" style="display:inline">
  <?= csrf_field() ?>
  <input type="hidden" name="portfolio_id" value="<?= (int) ($pid ?? 0) ?>">
  <input type="hidden" name="back" value="<?= e(\App\App::path()) ?>">
  <button type="submit">更新報價與匯率</button>
</form>
