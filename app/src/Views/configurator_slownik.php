<?php
declare(strict_types=1);

use CoachAnalyze\Configurator;
use CoachAnalyze\Session;
use CoachAnalyze\Suggester;
use CoachAnalyze\View;

/**
 * Słownik importu + edytor zmiennych — Sesja 3 (listing, podpowiedzi)
 * i Sesja 4 (edycja, sekcje, zapis) na jednym ekranie.
 *
 * JEDEN EKRAN, NIE DWA, i to jest decyzja: podział na „przejrzyj słownik"
 * i „teraz edytuj" kazałby operatorowi przeklikać całą listę dwa razy, a przy
 * czterdziestu pozycjach to jest praca, nie krok kreatora.
 *
 * @var array<string,mixed>       $club
 * @var array<string,mixed>       $import
 * @var array<string,mixed>       $coverage
 * @var list<array<string,mixed>> $warnings
 * @var list<array<string,mixed>> $variables
 * @var list<string>              $sections
 * @var list<string>              $concepts
 * @var list<string>              $qualifiers
 */
$znaczniki = [
    Suggester::PEWNA         => 'tag--done',
    Suggester::PRAWDOPODOBNA => 'tag--queued',
    Suggester::ZGADYWANA     => 'tag--older',
    Suggester::BRAK          => '',
];
$akcja = '/klub/' . (int) $club['id'] . '/konfigurator';
?>
<h1 class="h1"><?= View::e(View::t('conf.dict.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php /* ---------------------------------------------- raport pokrycia (Sesja 3 pkt 3) */ ?>
<section class="cards" aria-label="<?= View::e(View::t('coverage.numbers')) ?>">
  <?php foreach ([
      'cov.events' => $coverage['events'] ?? null,
      'cov.shots'  => $coverage['shots'] ?? null,
      'cov.sbz'    => $coverage['sbz'] ?? null,
      'cov.third'  => $coverage['third'] ?? null,
  ] as $klucz => $wartosc): ?>
    <div class="card">
      <span class="card__value">
        <?= $wartosc === null ? View::e(View::t('common.dash')) : View::e((string) $wartosc) ?>
      </span>
      <span class="card__label"><?= View::e(View::t($klucz)) ?></span>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($warnings !== []): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('coverage.warnings')) ?></h2>
    <ul class="warns">
      <?php foreach ($warnings as $w): ?>
        <li class="warns__row">
          <?php // Treść przychodzi z silnika po polsku — nie tłumaczymy jej drugi raz. ?>
          <span class="warns__msg"><?= View::e((string) ($w['msg'] ?? '')) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<form method="post" action="<?= View::e($akcja) ?>/slownik">
  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

  <?php /* ------------------------------------------ sekcje raportu (Sesja 4 pkt 3) */ ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('conf.sections.title')) ?></h2>
    <p class="hint"><?= View::e(View::t('conf.sections.hint')) ?></p>
    <div class="grid2">
      <?php foreach (Configurator::SEKCJE as $sekcja): ?>
        <label class="field field--check">
          <input type="checkbox" name="sections[]" value="<?= View::e($sekcja) ?>"
                 <?= in_array($sekcja, $sections, true) ? 'checked' : '' ?>>
          <span><?= View::e(View::t('sekcja.' . $sekcja)) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <?php /* ------------------------------------------ zmienne (Sesja 3 pkt 4-5, Sesja 4 pkt 1-2) */ ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('conf.vars.title', count($variables))) ?></h2>
    <p class="hint"><?= View::e(View::t('conf.vars.hint')) ?></p>

    <?php if ($variables === []): ?>
      <p class="empty"><?= View::e(View::t('conf.vars.empty')) ?></p>
    <?php else: ?>
      <?php foreach ($variables as $z): ?>
        <?php
          $vid = (string) $z['id'];
          $typ = (string) $z['source']['type'];
          $raw = (string) $z['source']['raw'];
          $dozwolone = $typ === Suggester::ETYKIETA ? $qualifiers : $concepts;
          $pewnosc = (string) ($z['confidence'] ?? Suggester::BRAK);
          $bezBindingu = ($z['canon'] ?? null) === null;
        ?>
        <div class="zmienna<?= $bezBindingu ? ' zmienna--generyczna' : '' ?>">
          <div class="zmienna__glowa">
            <?php // Źródło jest READ-ONLY: nazwa z eksportu nie podlega edycji. ?>
            <code class="tag-nazwa"><?= View::e($raw) ?></code>
            <span class="tag"><?= View::e(View::t('conf.src.' . $typ)) ?></span>
            <span class="muted">
              <?= $z['count'] === null
                  ? View::e(View::t('common.dash'))
                  : View::e(View::t('conf.count', (int) $z['count'])) ?>
            </span>

            <?php if ($pewnosc !== Suggester::BRAK): ?>
              <?php /*
                POZIOM PEWNOŚCI PODPOWIEDZI. Bez niego ekran przedstawia
                zgadywankę tak samo jak trafienie pewne, a operator uczy się
                klikać „dalej" bez czytania.
              */ ?>
              <span class="tag <?= View::e($znaczniki[$pewnosc] ?? '') ?>"
                    title="<?= View::e((string) ($z['reason'] ?? '')) ?>">
                <?= View::e(View::t('conf.conf.' . $pewnosc)) ?>
              </span>
            <?php endif; ?>

            <label class="field--check zmienna__usun">
              <input type="checkbox" name="remove[]" value="<?= View::e($vid) ?>">
              <span><?= View::e(View::t('conf.var.remove')) ?></span>
            </label>
          </div>

          <?php if (!empty($z['samples'])): ?>
            <p class="hint zmienna__probka">
              <?= View::e(View::t('conf.samples')) ?>
              <?php foreach ($z['samples'] as $p): ?>
                <span class="probka">
                  <?= View::e((string) ($p['team'] ?? View::t('match.no_club'))) ?>
                  <?php if (!empty($p['labels'])): ?>
                    · <?= View::e(implode(', ', array_map('strval', (array) $p['labels']))) ?>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
            </p>
          <?php endif; ?>

          <div class="zmienna__pola">
            <label class="field">
              <span class="field__label"><?= View::e(View::t('conf.var.canon')) ?></span>
              <select class="field__input" name="canon[<?= View::e($vid) ?>]">
                <option value=""><?= View::e(View::t('conf.var.canon.none')) ?></option>
                <?php foreach ($dozwolone as $pojecie): ?>
                  <option value="<?= View::e($pojecie) ?>"
                          <?= ($z['canon'] ?? null) === $pojecie ? 'selected' : '' ?>>
                    <?= View::e($pojecie) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field">
              <span class="field__label"><?= View::e(View::t('conf.var.label')) ?></span>
              <input class="field__input" type="text" maxlength="60"
                     name="label[<?= View::e($vid) ?>]"
                     value="<?= View::e((string) $z['display_label']) ?>">
            </label>

            <label class="field">
              <span class="field__label"><?= View::e(View::t('conf.var.color')) ?></span>
              <input class="field__input field__color" type="color"
                     name="color[<?= View::e($vid) ?>]"
                     value="<?= View::e(View::color($z['color'] ?? null, '#8899AA')) ?>">
            </label>

            <label class="field field--check">
              <input type="checkbox" name="visible[<?= View::e($vid) ?>]" value="1"
                     <?= !empty($z['visible']) ? 'checked' : '' ?>>
              <span><?= View::e(View::t('conf.var.visible')) ?></span>
            </label>
          </div>

          <div class="zmienna__sekcje">
            <span class="field__label"><?= View::e(View::t('conf.var.sections')) ?></span>
            <?php foreach (Configurator::SEKCJE as $sekcja): ?>
              <?php
                // TWARDA ZASADA (Sesja 4 pkt 2): zmienna bez pojęcia kanonicznego
                // dostaje wyłącznie licznik w bilansie i pas na osi czasu.
                // Widok BLOKUJE pole, żeby nie kusiło; o tym, co wolno zapisać,
                // rozstrzyga `Configurator::bledyConfigu()` — pole wyboru da się
                // odblokować w przeglądarce, walidacji po stronie serwera nie.
                $zablokowana = $bezBindingu
                    && !in_array($sekcja, Configurator::SEKCJE_GENERYCZNE, true);
                $wlaczonaWTemplacie = in_array($sekcja, $sections, true);
              ?>
              <label class="field--check<?= $zablokowana ? ' is-disabled' : '' ?>"
                     <?= $zablokowana ? 'title="' . View::e(View::t('conf.var.canon_required')) . '"' : '' ?>>
                <input type="checkbox"
                       name="vsections[<?= View::e($vid) ?>][]"
                       value="<?= View::e($sekcja) ?>"
                       <?= in_array($sekcja, (array) $z['sections'], true) && !$zablokowana ? 'checked' : '' ?>
                       <?= $zablokowana || !$wlaczonaWTemplacie ? 'disabled' : '' ?>>
                <span><?= View::e(View::t('sekcja.' . $sekcja)) ?></span>
              </label>
            <?php endforeach; ?>

            <?php if ($bezBindingu): ?>
              <p class="hint"><?= View::e(View::t('conf.var.canon_required')) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <div class="actions">
    <button class="btn btn--ghost" type="submit"><?= View::e(View::t('conf.draft.save')) ?></button>
    <?php /*
      Zapis templatu wysyła TEN SAM formularz pod inny adres. Operator klika
      „Zapisz templat" bez osobnego zapisywania draftu i ma prawo oczekiwać,
      że zapisze się to, co widzi na ekranie.
    */ ?>
    <button class="btn" type="submit" formaction="<?= View::e($akcja) ?>/zapisz">
      <?= View::e(View::t('conf.save')) ?>
    </button>
  </div>
</form>

<div class="actions">
  <form method="post" action="<?= View::e($akcja) ?>/porzuc">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <button class="link link--danger" type="submit"><?= View::e(View::t('conf.draft.discard')) ?></button>
  </form>
  <a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
</div>
