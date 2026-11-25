<form method="post" action="<?= e(url('/scope')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($scopeAction ?? '/') ?>">
    <label class="small muted" for="scope">範圍</label>
    <select name="scope" id="scope" onchange="this.form.submit()">
      <option value="all" <?= $aggregate ? 'selected' : '' ?>>所有組合</option>
      <?php foreach ($portfolios as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= !$aggregate && (int) $p['id'] === (int) $pid ? 'selected' : '' ?>>
          <?= e($p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="tiny" type="submit">套用</button></noscript>
  </form>
