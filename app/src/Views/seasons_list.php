<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Sezony — lista i formularz dodawania w jednym miejscu. Rzecz jest na tyle
 * prosta, że osobny ekran tworzenia byłby kliknięciem bez treści.
 *
 * @var list<array<string,mixed>> $seasons
 * @var array{label:string,date_from:string,date_to:string} $propozycja
 * @var string|null $notice
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('season.list')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <?php if ($seasons === []): ?>
    <p class="empty"><?= View::e(View::t('season.empty')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= View::e(View::t('season.label')) ?></th>
          <th><?= View::e(View::t('season.from')) ?></th>
          <th><?= View::e(View::t('season.to')) ?></th>
          <th><?= View::e(View::t('season.matches')) ?></th>
          <th><?= View::e(View::t('match.action')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($seasons as $s): ?>
        <tr>
          <td>
            <?= View::e((string) $s['label']) ?>
            <?php if (!empty($s['is_current'])): ?>
              <span class="tag tag--done"><?= View::e(View::t('season.current')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= View::e(substr((string) $s['date_from'], 0, 10)) ?></td>
          <td><?= View::e(substr((string) $s['date_to'], 0, 10)) ?></td>
          <td><?= (int) ($s['matches_count'] ?? 0) ?></td>
          <td class="akcje">
            <?php if (empty($s['is_current'])): ?>
              <form method="post" action="/sezony/<?= (int) $s['id'] ?>/biezacy">
                <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <button class="link link--btn" type="submit"><?= View::e(View::t('season.make_current')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ((int) ($s['matches_count'] ?? 0) === 0): ?>
              <form method="post" action="/sezony/<?= (int) $s['id'] ?>/usun">
                <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <button class="link link--btn" type="submit"><?= View::e(View::t('season.delete')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('season.new')) ?></h2>
  <?php // Wartości podpowiedziane z dzisiejszej daty według reguły lipiec–czerwiec. ?>
  <p class="hint"><?= View::e(View::t('season.rule')) ?></p>

  <form method="post" action="/sezony">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('season.label')) ?></span>
        <input class="field__input" type="text" name="label" required maxlength="32"
               value="<?= View::e($propozycja['label']) ?>">
      </label>
      <label class="field field--check">
        <input type="checkbox" name="is_current" value="1">
        <span><?= View::e(View::t('season.set_current')) ?></span>
      </label>
    </div>
    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('season.from')) ?></span>
        <input class="field__input" type="date" name="date_from" required
               value="<?= View::e($propozycja['date_from']) ?>">
      </label>
      <label class="field">
        <span class="field__label"><?= View::e(View::t('season.to')) ?></span>
        <input class="field__input" type="date" name="date_to" required
               value="<?= View::e($propozycja['date_to']) ?>">
      </label>
    </div>
    <button class="btn" type="submit"><?= View::e(View::t('season.create')) ?></button>
  </form>
</section>
