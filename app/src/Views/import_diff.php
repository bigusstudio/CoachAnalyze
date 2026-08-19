<?php
declare(strict_types=1);

use CoachAnalyze\Configurator;
use CoachAnalyze\Session;
use CoachAnalyze\Suggester;
use CoachAnalyze\TemplateDiff;
use CoachAnalyze\View;

/**
 * Nowe tagi w tym imporcie — trzy akcje per pozycja (Sesja 6 pkt 3).
 *
 * Ekran pokazuje WYŁĄCZNIE to, czego templat jeszcze nie zna. Pozycje znane
 * mapują się cicho i nie zajmują tu miejsca; gdy nic nowego nie ma, ten ekran
 * w ogóle się nie pokazuje (kontroler idzie prosto na pokrycie).
 *
 * @var array<string,mixed>       $import
 * @var array<string,mixed>       $diff
 * @var list<array<string,mixed>> $nowe   z podpowiedziami
 * @var list<string>              $concepts
 * @var list<string>              $qualifiers
 * @var bool|null                 $rewizja  tryb rewizji mapowania z pokrycia
 * @var string|null               $fokus    skrót pozycji do wyróżnienia
 * @var bool|null                 $slownikJest  czy eksport niesie słownik pozycji
 */
$rewizja = $rewizja ?? false;
$fokus   = $fokus ?? null;
$slownikJest = $slownikJest ?? true;

/*
 * TEN SAM EKRAN, DWA WEJŚCIA (żadnego drugiego ekranu mapowania).
 *
 *   diff     — import przyniósł pozycje, o które jeszcze nie pytaliśmy,
 *   rewizja  — operator sam przyszedł z pokrycia poprawić wcześniejszą decyzję.
 *
 * Różni je zbiór pozycji (rewizja pokazuje też zignorowane na stałe), zestaw
 * akcji przy pozycji już zignorowanej i teksty. Reszta jest wspólna.
 */
$znaczniki = [
    Suggester::PEWNA         => 'tag--done',
    Suggester::PRAWDOPODOBNA => 'tag--queued',
    Suggester::ZGADYWANA     => 'tag--older',
];
?>
<h1 class="h1"><?= View::e(View::t($rewizja ? 'rev.title' : 'diff.title')) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="cards" aria-label="<?= View::e(View::t('diff.title')) ?>">
  <div class="card">
    <span class="card__value"><?= count($nowe) ?></span>
    <span class="card__label"><?= View::e(View::t('diff.new')) ?></span>
  </div>
  <div class="card">
    <span class="card__value"><?= count($diff['znane']) ?></span>
    <span class="card__label"><?= View::e(View::t('diff.known')) ?></span>
    <span class="card__note"><?= View::e(View::t('diff.known.note')) ?></span>
  </div>
  <div class="card">
    <span class="card__value"><?= count($diff['ignorowane']) ?></span>
    <span class="card__label"><?= View::e(View::t('diff.ignored')) ?></span>
    <span class="card__note"><?= View::e(View::t('diff.ignored.note')) ?></span>
  </div>
</section>

<p class="hint"><?= View::e(View::t($rewizja ? 'rev.lead' : 'diff.lead')) ?></p>

<?php if ($rewizja && $nowe === []): ?>
  <?php /*
    PUSTA REWIZJA TEŻ JEST ODPOWIEDZIĄ — i musi paść na ekranie.

    Dotąd w tym miejscu było przekierowanie na pokrycie: operator klikał
    „Zmień mapowanie" i wracał tam, skąd przyszedł, bez słowa. Wyglądało to
    na zepsuty odsyłacz i tak zostało zgłoszone.

    DWA POWODY PUSTKI, ROZRÓŻNIONE. Jeden znaczy „nie ma nic do poprawienia",
    drugi „nie mam czego sprawdzić" — i tylko drugi wymaga działania.
  */ ?>
  <?php if (!$slownikJest): ?>
    <section class="panel">
      <p class="alert" role="alert"><?= View::e(View::t('rev.empty.no_dictionary')) ?></p>
      <p class="hint"><?= View::e(View::t('rev.empty.no_dictionary.hint')) ?></p>
      <form method="post" action="/import/<?= (int) $import['id'] ?>/inspekcja">
        <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <button class="btn" type="submit"><?= View::e(View::t('rev.inspect.submit')) ?></button>
      </form>
    </section>
  <?php else: ?>
    <p class="empty"><?= View::e(View::t('rev.empty.all_mapped')) ?></p>
  <?php endif; ?>

  <p><a class="link" href="/import/<?= (int) $import['id'] ?>"><?= View::e(View::t('common.back')) ?></a></p>
<?php else: ?>

<form method="post" action="/import/<?= (int) $import['id'] ?>/diff<?= $rewizja ? '?rewizja=1' : '' ?>">
  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

  <section class="panel">
    <?php foreach ($nowe as $poz): ?>
      <?php
        $k = TemplateDiff::kluczHtml((string) $poz['type'], (string) $poz['name']);
        $typ = (string) $poz['type'];
        $dozwolone = $typ === 'label' ? $qualifiers : $concepts;
        $podp = $poz['suggestion'] ?? ['canon' => null, 'confidence' => Suggester::BRAK, 'reason' => null];
      ?>
      <?php $stan = (string) ($poz['stan'] ?? ''); ?>
      <?php /* Kotwica pozwala wejść wprost na tag klikniętego chipa
               z ekranu pokrycia; `is-fokus` wyróżnia go po wejściu. */ ?>
      <div class="zmienna<?= $fokus === $k ? ' is-fokus' : '' ?>" id="poz-<?= View::e($k) ?>">
        <div class="zmienna__glowa">
          <code class="tag-nazwa"><?= View::e((string) $poz['name']) ?></code>
          <span class="tag"><?= View::e(View::t('conf.src.' . $typ)) ?></span>
          <?php if ($rewizja && $stan !== ''): ?>
            <?php /* OBECNY STAN POZYCJI. W rewizji operator musi wiedzieć,
                     co jest dziś, zanim zdecyduje, co ma być. */ ?>
            <span class="tag <?= $stan === TemplateDiff::STAN_NA_STALE ? 'tag--older' : '' ?>">
              <?= View::e(View::t('rev.stan.' . $stan)) ?>
            </span>
          <?php endif; ?>
          <span class="muted">
            <?= $poz['count'] === null
                ? View::e(View::t('common.dash'))
                : View::e(View::t('conf.count', (int) $poz['count'])) ?>
          </span>
          <?php if ($podp['confidence'] !== Suggester::BRAK): ?>
            <span class="tag <?= View::e($znaczniki[$podp['confidence']] ?? '') ?>"
                  title="<?= View::e((string) ($podp['reason'] ?? '')) ?>">
              <?= View::e(View::t('conf.conf.' . $podp['confidence'])) ?>
            </span>
          <?php endif; ?>
        </div>

        <?php if (!empty($poz['samples'])): ?>
          <p class="hint zmienna__probka">
            <?= View::e(View::t('conf.samples')) ?>
            <?php foreach ($poz['samples'] as $p): ?>
              <span class="probka">
                <?= View::e((string) ($p['team'] ?? View::t('match.no_club'))) ?>
                <?php if (!empty($p['labels'])): ?>
                  · <?= View::e(implode(', ', array_map('strval', (array) $p['labels']))) ?>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>

        <?php /* Domyślnie „zostaw jak jest" — decyzja o dopisaniu do templatu
                 ma być świadoma, nie domyślna. W rewizji pozycja zignorowana
                 na stałe dostaje „cofnij" zamiast „zignoruj na stałe":
                 proponowanie jej tego, co już jest, byłoby akcją bez skutku. */ ?>
        <div class="zmienna__sekcje">
          <label class="field--check">
            <input type="radio" name="decyzja[<?= View::e($k) ?>]"
                   value="<?= View::e(TemplateDiff::DODAJ) ?>">
            <span><?= View::e(View::t('diff.act.add')) ?></span>
          </label>
          <label class="field--check">
            <input type="radio" name="decyzja[<?= View::e($k) ?>]"
                   value="<?= View::e(TemplateDiff::POMIN) ?>" checked>
            <span><?= View::e(View::t($rewizja ? 'rev.act.keep' : 'diff.act.skip')) ?></span>
          </label>
          <?php if ($rewizja && $stan === TemplateDiff::STAN_NA_STALE): ?>
            <label class="field--check">
              <input type="radio" name="decyzja[<?= View::e($k) ?>]"
                     value="<?= View::e(TemplateDiff::COFNIJ) ?>">
              <span><?= View::e(View::t('rev.act.undo')) ?></span>
            </label>
          <?php else: ?>
            <label class="field--check">
              <input type="radio" name="decyzja[<?= View::e($k) ?>]"
                     value="<?= View::e(TemplateDiff::NA_STALE) ?>">
              <span><?= View::e(View::t('diff.act.never')) ?></span>
            </label>
          <?php endif; ?>
        </div>

        <?php /* MINI-EDYTOR — pola działają tylko przy „dodaj do templatu".
                 Zostawiamy je widoczne: ukrywanie wymagałoby skryptu, a panel
                 go nie ma poza chmurkami (CLAUDE.md §9). */ ?>
        <div class="zmienna__pola">
          <label class="field">
            <span class="field__label"><?= View::e(View::t('conf.var.canon')) ?></span>
            <select class="field__input" name="canon[<?= View::e($k) ?>]">
              <option value=""><?= View::e(View::t('conf.var.canon.none')) ?></option>
              <?php foreach ($dozwolone as $pojecie): ?>
                <option value="<?= View::e($pojecie) ?>"
                        <?= ($podp['canon'] ?? null) === $pojecie ? 'selected' : '' ?>>
                  <?= View::e($pojecie) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="field">
            <span class="field__label"><?= View::e(View::t('conf.var.label')) ?></span>
            <input class="field__input" type="text" maxlength="60"
                   name="label[<?= View::e($k) ?>]"
                   value="<?= View::e(Configurator::etykietaZNazwy((string) $poz['name'])) ?>">
          </label>

          <label class="field">
            <span class="field__label"><?= View::e(View::t('conf.var.color')) ?></span>
            <input class="field__input field__color" type="color"
                   name="color[<?= View::e($k) ?>]" value="#8899AA">
          </label>
        </div>

        <div class="zmienna__sekcje">
          <span class="field__label"><?= View::e(View::t('conf.var.sections')) ?></span>
          <?php foreach (Configurator::SEKCJE as $sekcja): ?>
            <label class="field--check">
              <input type="checkbox" name="vsections[<?= View::e($k) ?>][]"
                     value="<?= View::e($sekcja) ?>"
                     <?= in_array($sekcja, Configurator::SEKCJE_GENERYCZNE, true) ? 'checked' : '' ?>>
              <span><?= View::e(View::t('sekcja.' . $sekcja)) ?></span>
            </label>
          <?php endforeach; ?>
          <p class="hint"><?= View::e(View::t('conf.var.canon_required')) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="actions">
    <button class="btn" type="submit"><?= View::e(View::t($rewizja ? 'rev.submit' : 'diff.submit')) ?></button>
    <span class="hint"><?= View::e(View::t('diff.submit.hint')) ?></span>
    <?php if ($rewizja): ?>
      <a class="link" href="/import/<?= (int) $import['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>
