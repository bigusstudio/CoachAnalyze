<?php
declare(strict_types=1);

use CoachAnalyze\Imports;
use CoachAnalyze\Session;
use CoachAnalyze\Upload;
use CoachAnalyze\View;

/**
 * Ponowne wgranie surowych plików do ISTNIEJĄCEGO meczu (Sesja 7).
 *
 * PO CO OSOBNY EKRAN, skoro `/import` już przyjmuje pliki: tamten zakłada NOWY
 * mecz. Raport, któremu zniknął eksport źródłowy, potrzebuje czegoś innego —
 * dopisania eksportu do meczu, który już ma raport i już ma rozesłany adres
 * publiczny. Import od nowa dałby drugi mecz, drugi raport i drugi adres,
 * a stary link zostałby sierotą.
 *
 * @var array<string,mixed>      $match
 * @var array<string,mixed>|null $previous  najnowszy dotychczasowy import
 * @var bool        $storageReady
 * @var string|null $error
 */
$opis = trim(trim((string) ($match['home_name'] ?? '')) . ' — ' . trim((string) ($match['away_name'] ?? '')), ' —');
$mb = (int) (Upload::maxBytes() / 1024 / 1024);
?>
<h1 class="h1"><?= View::e(View::t('reupload.title')) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if (!$storageReady): ?>
  <p class="alert" role="alert"><?= View::e(View::t('import.err.storage')) ?></p>
<?php endif; ?>

<p class="hint"><?= View::e(View::t('reupload.lead')) ?></p>

<section class="panel">
  <dl class="facts">
    <dt><?= View::e(View::t('reupload.match')) ?></dt>
    <dd><?= View::e($opis !== '' ? $opis : View::t('common.unknown')) ?></dd>

    <dt><?= View::e(View::t('reupload.previous')) ?></dt>
    <dd>
      <?php if ($previous === null): ?>
        <span class="muted"><?= View::e(View::t('reupload.previous.none')) ?></span>
      <?php else: ?>
        <?= View::e(substr((string) $previous['created_at'], 0, 16)) ?>
        <?php if (!Imports::rawUsable($previous)): ?>
          <?php /* Nazwy pliku NIE pokazujemy: jest losowa i nic nie mówi, a
                   ścieżka magazynu nie ma prawa wyjść do przeglądarki. Liczy
                   się fakt: pliku nie ma i dlatego ten ekran w ogóle istnieje. */ ?>
          <br><span class="tag tag--older"><?= View::e(View::t('reupload.previous.missing')) ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </dd>
  </dl>
</section>

<section class="panel">
  <form method="post" action="/mecze/<?= (int) $match['id'] ?>/wgraj" enctype="multipart/form-data">
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
      <?= View::e(View::t('reupload.submit')) ?>
    </button>
  </form>
</section>

<p>
  <a class="link" href="/mecze/<?= (int) $match['id'] ?>/historia"><?= View::e(View::t('history.title')) ?></a>
</p>
