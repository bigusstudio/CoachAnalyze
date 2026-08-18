<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\Upload;
use CoachAnalyze\View;

/**
 * Formularz wgrania eksportu.
 *
 * BEZ KONTEKSTU KLUBU (`$club === null`): globalny `/import`, klub domyślny
 * (`Clubs::tenantDefault()`). Z KONTEKSTEM (Sesja 2, `/klub/{id}/import`):
 * `clubId` wprost z adresu — patrz `handleClubImport()` w index.php.
 *
 * @var string|null $error
 * @var bool        $storageReady
 * @var array<string,mixed>|null $club
 */
$club ??= null;
$action = $club !== null ? '/klub/' . (int) $club['id'] . '/import' : '/import';
$mb = (int) (Upload::maxBytes() / 1024 / 1024);
?>
<h1 class="h1"><?= View::e(View::t('import.title')) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if (!$storageReady): ?>
  <p class="alert" role="alert"><?= View::e(View::t('import.err.storage')) ?></p>
<?php endif; ?>

<section class="panel">
  <form method="post" action="<?= View::e($action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <?php // Podpowiedź dla przeglądarki; twardy limit i tak sprawdza serwer. ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) Upload::maxBytes() ?>">

    <label class="field">
      <span class="field__label"><?= View::e(View::t('import.csv')) ?></span>
      <input class="field__input" type="file" name="csv" accept=".csv" required>
      <span class="hint"><?= View::e(View::t('import.csv.hint')) ?></span>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('import.json')) ?></span>
      <input class="field__input" type="file" name="json" accept=".json">
      <span class="hint"><?= View::e(View::t('import.json.hint')) ?></span>
    </label>

    <p class="hint"><?= View::e(View::t('import.limit', $mb)) ?></p>

    <button class="btn" type="submit" <?= $storageReady ? '' : 'disabled' ?>>
      <?= View::e(View::t('import.submit')) ?>
    </button>
  </form>
</section>

<p>
  <a class="link" href="<?= $club !== null ? '/klub/' . (int) $club['id'] : '/' ?>">
    <?= View::e(View::t('common.back')) ?>
  </a>
</p>
