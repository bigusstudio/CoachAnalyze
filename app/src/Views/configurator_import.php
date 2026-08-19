<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\Upload;
use CoachAnalyze\View;

/**
 * Import założycielski — pierwszy krok konfiguratora (Sesja 3).
 *
 * @var array<string,mixed>      $club
 * @var array<string,mixed>|null $reportTemplate  istniejący templat klubu.
 *      UWAGA: NIE `$template` — ta nazwa jest zajęta przez parametr
 *      `View::render()` (nazwa pliku szablonu), a `extract(…, EXTR_SKIP)`
 *      nie nadpisuje zmiennych już istniejących. Klucz `template` w danych
 *      ginie po cichu i widok dostaje napis zamiast wiersza z bazy.
 * @var bool        $storageReady
 * @var string|null $notice
 * @var string|null $error
 */
$mb = (int) (Upload::maxBytes() / 1024 / 1024);
?>
<h1 class="h1"><?= View::e(View::t('conf.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($reportTemplate !== null): ?>
  <?php /*
    Klub ma już templat. NIE blokujemy konfiguratora — kolejny przebieg zapisze
    nową wersję (append-only), a stara zostaje. Ostrzegamy jednak wprost, bo
    „skonfiguruj raporty" przy istniejącym templacie brzmi jak edycja, a jest
    budową od nowa z innego eksportu.
  */ ?>
  <p class="notice" role="status">
    <?= View::e(View::t('conf.has_template', (int) $reportTemplate['version'])) ?>
  </p>
<?php endif; ?>

<?php if (!$storageReady): ?>
  <p class="alert" role="alert"><?= View::e(View::t('import.err.storage')) ?></p>
<?php endif; ?>

<section class="panel">
  <p class="hint"><?= View::e(View::t('conf.lead')) ?></p>

  <form method="post" action="/klub/<?= (int) $club['id'] ?>/konfigurator"
        enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) Upload::maxBytes() ?>">

    <label class="field">
      <span class="field__label"><?= View::e(View::t('import.csv')) ?></span>
      <input class="field__input" type="file" name="csv" accept=".csv" required>
      <span class="hint"><?= View::e(View::t('import.csv.hint')) ?></span>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('import.json')) ?></span>
      <input class="field__input" type="file" name="json" accept=".json">
      <span class="hint"><?= View::e(View::t('conf.json.hint')) ?></span>
    </label>

    <p class="hint"><?= View::e(View::t('import.limit', $mb)) ?></p>

    <button class="btn" type="submit" <?= $storageReady ? '' : 'disabled' ?>>
      <?= View::e(View::t('conf.submit')) ?>
    </button>
  </form>
</section>

<p><a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a></p>
