<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\Upload;
use CoachAnalyze\View;

/**
 * WGRAJ EKSPORT — krok 1 ścieżki „Wgraj → Postęp → Raport" (golden layout W3).
 *
 * JEDEN EKRAN, bez ekranu różnic i bez kreatora na drodze analityka. Po wgraniu
 * plik czyta silnik (inspekcja w tle), a ten sam krok kończy się formularzem
 * „rywal, data, wynik" (`import_przygotuj`) i jednym przyciskiem.
 *
 * Walidacja typu pliku w skrypcie (`powiadomienia.js`, jedyny plik JS panelu)
 * PRZYSPIESZA, nie warunkuje: `accept` i serwer (`Upload::accept`) sprawdzają
 * to samo. Komunikaty idą z `pl.php` przez `data-*` — w skrypcie nie ma zdań.
 *
 * @var array<string,mixed>|null $club
 * @var bool        $storageReady
 * @var string|null $error
 */
$club ??= null;
$action = $club !== null ? '/klub/' . (int) $club['id'] . '/import' : '/import';
$mb = (int) (Upload::maxBytes() / 1024 / 1024);
?>
<ol class="kroki" aria-label="<?= View::e(View::t('import.steps')) ?>">
  <li class="kroki__k kroki__k--on"><span>1</span><?= View::e(View::t('import.step.upload')) ?></li>
  <li class="kroki__k"><span>2</span><?= View::e(View::t('import.step.report')) ?></li>
</ol>

<h1 class="h1"><?= View::e(View::t('import.title')) ?></h1>
<p class="hint"><?= View::e(View::t('import.where')) ?></p>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if (!$storageReady): ?>
  <p class="alert" role="alert"><?= View::e(View::t('import.err.storage')) ?></p>
<?php endif; ?>

<section class="panel">
  <form method="post" action="<?= View::e($action) ?>" enctype="multipart/form-data" data-wgraj
        data-blad-typu-csv="<?= View::e(View::t('import.err.type_csv')) ?>"
        data-blad-typu-json="<?= View::e(View::t('import.err.type_json')) ?>"
        data-blad-rozmiar="<?= View::e(View::t('import.err.size', $mb)) ?>"
        data-limit="<?= (int) Upload::maxBytes() ?>">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <?php // Podpowiedź dla przeglądarki; twardy limit i tak sprawdza serwer. ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) Upload::maxBytes() ?>">

    <label class="upuszczenie">
      <span class="upuszczenie__tytul"><?= View::e(View::t('import.csv')) ?></span>
      <input type="file" name="csv" accept=".csv,text/csv" required data-rozszerzenie="csv">
      <span class="hint"><?= View::e(View::t('import.csv.hint')) ?></span>
    </label>

    <label class="upuszczenie upuszczenie--drugie">
      <span class="upuszczenie__tytul"><?= View::e(View::t('import.json')) ?></span>
      <input type="file" name="json" accept=".json,application/json" data-rozszerzenie="json">
      <span class="hint"><?= View::e(View::t('import.json.hint')) ?></span>
    </label>

    <p class="alert" role="alert" data-komunikat hidden></p>
    <p class="hint"><?= View::e(View::t('import.limit', $mb)) ?></p>

    <button class="btn p" type="submit" <?= $storageReady ? '' : 'disabled' ?>>
      <?= View::e(View::t('import.submit')) ?>
    </button>
  </form>
</section>

<p><a class="link" href="<?= View::e(\CoachAnalyze\Powrot::url('/pulpit')) ?>"><?= View::e(View::t('import.back')) ?></a></p>
