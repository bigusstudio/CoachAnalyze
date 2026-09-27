<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * WGRAJ — dokończenie kroku 1 (golden layout W3): plik przeczytany, zostały
 * rywal, data i wynik. Jeden przycisk „Wgraj i przygotuj raport".
 *
 * Rywal PODPOWIEDZIANY z kolumny `team` eksportu (dopasował go już AutoImport,
 * a nierozpoznanego założył). Gdy założony automatycznie przypomina istniejący
 * klub — ostrzeżenie „podobny klub już istnieje" i wybór z listy (normalizacja
 * + zawieranie nazwy: Hetman / HETMAN ZAMOŚĆ). Skład jest poza tą ścieżką
 * (karta meczu, zakładka Skład); ekran różnic też ([op]).
 *
 * @var array<string,mixed>       $import
 * @var array<string,mixed>       $mecz
 * @var array<string,mixed>|null  $club
 * @var array<string,mixed>|null  $inspekcja   zadanie inspect, gdy jeszcze trwa/padło
 * @var bool                      $gotowe      czy plik jest już przeczytany
 * @var list<array<string,mixed>> $rywale
 * @var list<array<string,mixed>> $podobne
 * @var string|null               $error
 */
$importId = (int) $import['id'];
?>
<ol class="kroki" aria-label="<?= View::e(View::t('import.steps')) ?>">
  <li class="kroki__k kroki__k--on"><span>1</span><?= View::e(View::t('import.step.upload')) ?></li>
  <li class="kroki__k"><span>2</span><?= View::e(View::t('import.step.report')) ?></li>
</ol>

<h1 class="h1"><?= View::e(View::t('import.title')) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if (!$gotowe): ?>
  <?php /* Plik czyta silnik w tle (cron). Ten sam wskaźnik pracy co wszędzie;
           bez skryptu odświeża stronę `<meta refresh>`. */ ?>
  <p class="hint"><?= View::e(View::t('import.reading')) ?></p>
  <?php if ($inspekcja !== null): ?>
    <?= View::render('wskaznik', ['job' => $inspekcja, 'resultUrl' => '/import/' . $importId . '/przygotuj']) ?>
  <?php endif; ?>
<?php else: ?>
  <section class="panel">
    <form method="post" action="/import/<?= $importId ?>/przygotuj" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

      <label class="field">
        <span class="field__label"><?= View::e(View::t('meta.rival')) ?></span>
        <select class="field__input" name="club_away_id">
          <option value=""><?= View::e(View::t('meta.rival.none')) ?></option>
          <?php foreach ($rywale as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= (int) ($mecz['club_away_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
              <?= View::e((string) $r['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint"><?= View::e(View::t('import.rival.hint')) ?></span>
      </label>

      <?php if ($podobne !== []): ?>
        <p class="notice notice--warn" role="status">
          <?= View::e(View::t('import.rival.similar', implode(', ', array_map(
              static fn(array $k): string => (string) $k['name'], $podobne)))) ?>
        </p>
      <?php endif; ?>

      <label class="field">
        <span class="field__label"><?= View::e(View::t('import.rival.new')) ?></span>
        <input class="field__input" type="text" name="nowy_rywal" maxlength="120">
      </label>

      <div class="grid2">
        <label class="field">
          <span class="field__label"><?= View::e(View::t('match.date')) ?> *</span>
          <input class="field__input" type="date" name="played_at" required
                 value="<?= View::e(substr((string) ($mecz['played_at'] ?? ''), 0, 10)) ?>">
        </label>
        <label class="field">
          <span class="field__label"><?= View::e(View::t('meta.round')) ?></span>
          <input class="field__input" type="text" name="round" maxlength="16"
                 value="<?= View::e((string) ($mecz['round'] ?? '')) ?>">
        </label>
      </div>

      <div class="grid2">
        <label class="field">
          <span class="field__label"><?= View::e(View::t('meta.score_us')) ?></span>
          <input class="field__input" type="number" min="0" max="99" name="score_us">
        </label>
        <label class="field">
          <span class="field__label"><?= View::e(View::t('meta.score_them')) ?></span>
          <input class="field__input" type="number" min="0" max="99" name="score_them">
        </label>
      </div>
      <p class="hint"><?= View::e(View::t('import.score.tags')) ?></p>

      <button class="btn p" type="submit"><?= View::e(View::t('import.go')) ?></button>
      <p class="hint hint--block"><?= View::e(View::t('import.go.hint')) ?></p>
    </form>
  </section>
<?php endif; ?>

<p><a class="link" href="/import"><?= View::e(View::t('import.back')) ?></a></p>
