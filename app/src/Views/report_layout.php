<?php
declare(strict_types=1);

use CoachAnalyze\ReportLayout;
use CoachAnalyze\View;

/**
 * Układ raportu klubu — kolejność, szerokość i tytuły kafli (Sesja 5 pivotu).
 *
 * BEZ SKRYPTU. Kolejność zmieniają przyciski góra/dół, a nie przeciąganie:
 * panel ma jeden zatwierdzony plik JavaScript i jest to wyjątek na chmurki
 * powiadomień (CLAUDE.md §9). Każdy przycisk jest `submit` z własną wartością
 * pola `akcja`, więc ekran działa dokładnie tak samo z wyłączonym JS.
 *
 * JEDEN FORMULARZ NA CAŁY EKRAN, nie jeden na wiersz: operator zmienia
 * szerokość kafelka i przesuwa go w tym samym kroku, a formularze per wiersz
 * gubiłyby przy tym wszystko, czego nie dotyczył kliknięty przycisk.
 *
 * @var array<string,mixed>       $club
 * @var list<array<string,mixed>> $sekcje
 * @var array<string,array>       $widgety
 * @var list<string>              $rozmiary
 * @var list<string>              $dostepne
 * @var int                       $wersja
 * @var bool                      $roboczy
 * @var array<string,mixed>       $progi
 * @var list<string>              $klucze_progow
 */
$akcja = '/klub/' . (int) $club['id'] . '/uklad';
$ostatni = count($sekcje) - 1;
?>
<h1 class="h1"><?= View::e(View::t('uklad.title')) ?></h1>
<p class="hint"><?= View::e(View::t('uklad.intro')) ?></p>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($roboczy): ?>
  <p class="notice" role="status"><?= View::e(View::t('uklad.roboczy')) ?></p>
<?php endif; ?>

<form method="post" action="<?= View::e($akcja) ?>">
  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

  <section class="zmienne" aria-label="<?= View::e(View::t('uklad.sekcje')) ?>">
    <?php foreach ($sekcje as $i => $wpis): ?>
      <?php $widget = (string) (($wpis['widgets'][0] ?? '')); ?>
      <div class="zmienna">
        <div class="zmienna__naglowek">
          <strong class="zmienna__nazwa"><?= View::e(View::t('kafel.' . $widget)) ?></strong>
          <span class="tag"><?= View::e((string) ($i + 1)) ?></span>
          <?php if (!empty($widgety[$widget]['wymaga_zawodnikow'])): ?>
            <span class="hint"><?= View::e(View::t('uklad.wymaga_zawodnikow')) ?></span>
          <?php endif; ?>
        </div>

        <div class="zmienna__pola">
          <label class="field">
            <span class="field__label"><?= View::e(View::t('uklad.rozmiar')) ?></span>
            <select class="field__input" name="rozmiar[<?= (int) $i ?>]">
              <?php foreach ($rozmiary as $r): ?>
                <option value="<?= View::e($r) ?>"
                        <?= (string) ($wpis['size'] ?? '') === $r ? 'selected' : '' ?>>
                  <?= View::e(View::t('uklad.rozmiar.' . str_replace('/', '_', $r))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="field">
            <span class="field__label"><?= View::e(View::t('uklad.tytul')) ?></span>
            <input class="field__input" type="text" maxlength="80"
                   name="tytul[<?= (int) $i ?>]"
                   placeholder="<?= View::e(View::t('uklad.tytul.domyslny')) ?>"
                   value="<?= View::e((string) ($wpis['title'] ?? '')) ?>">
          </label>
        </div>

        <div class="zmienna__sekcje">
          <button class="btn s" type="submit" name="akcja" value="gora:<?= (int) $i ?>"
                  <?= $i === 0 ? 'disabled' : '' ?>>&uarr; <?= View::e(View::t('uklad.gora')) ?></button>
          <button class="btn s" type="submit" name="akcja" value="dol:<?= (int) $i ?>"
                  <?= $i === $ostatni ? 'disabled' : '' ?>>&darr; <?= View::e(View::t('uklad.dol')) ?></button>
          <button class="btn s" type="submit" name="akcja" value="usun:<?= (int) $i ?>">
            <?= View::e(View::t('uklad.usun')) ?>
          </button>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($sekcje === []): ?>
      <p class="hint"><?= View::e(View::t('uklad.pusto')) ?></p>
    <?php endif; ?>
  </section>

  <?php /* ---------------------------------------------------- dodanie kafelka */ ?>
  <?php if ($dostepne !== []): ?>
    <section class="zmienna" aria-label="<?= View::e(View::t('uklad.dodaj')) ?>">
      <div class="zmienna__naglowek">
        <strong class="zmienna__nazwa"><?= View::e(View::t('uklad.dodaj')) ?></strong>
      </div>
      <div class="zmienna__pola">
        <label class="field">
          <span class="field__label"><?= View::e(View::t('uklad.kafel')) ?></span>
          <select class="field__input" name="nowy_kafel">
            <?php foreach ($dostepne as $widget): ?>
              <option value="<?= View::e($widget) ?>"><?= View::e(View::t('kafel.' . $widget)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label"><?= View::e(View::t('uklad.rozmiar')) ?></span>
          <select class="field__input" name="nowy_rozmiar">
            <?php foreach ($rozmiary as $r): ?>
              <option value="<?= View::e($r) ?>">
                <?= View::e(View::t('uklad.rozmiar.' . str_replace('/', '_', $r))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="zmienna__sekcje">
        <button class="btn s" type="submit" name="akcja" value="dodaj">
          <?= View::e(View::t('uklad.dodaj.submit')) ?>
        </button>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ---------------------------------------------------- progi faktów */ ?>
  <section class="zmienna" aria-label="<?= View::e(View::t('uklad.progi')) ?>">
    <div class="zmienna__naglowek">
      <strong class="zmienna__nazwa"><?= View::e(View::t('uklad.progi')) ?></strong>
    </div>
    <p class="hint"><?= View::e(View::t('uklad.progi.hint')) ?></p>
    <div class="zmienna__pola">
      <?php foreach ($klucze_progow as $klucz): ?>
        <label class="field">
          <span class="field__label"><?= View::e(View::t('prog.' . $klucz)) ?></span>
          <input class="field__input" type="number" min="0" max="100" step="1"
                 name="prog[<?= View::e($klucz) ?>]"
                 placeholder="<?= View::e(View::t('uklad.progi.globalny')) ?>"
                 value="<?= View::e((string) ($progi[$klucz] ?? '')) ?>">
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="actions">
    <button class="btn" type="submit" name="akcja" value="zapisz">
      <?= View::e(View::t('uklad.zapisz')) ?>
    </button>
    <span class="hint"><?= View::e(View::t('uklad.zapisz.hint', $wersja + 1)) ?></span>
    <?php if ($roboczy): ?>
      <button class="btn s" type="submit" name="akcja" value="porzuc">
        <?= View::e(View::t('uklad.porzuc')) ?>
      </button>
    <?php endif; ?>
    <a class="link" href="/klub/<?= (int) $club['id'] ?>/templaty">
      <?= View::e(View::t('tpl.history.title')) ?>
    </a>
  </div>
</form>

<?php /*
  PODGLĄD TO ZWYKŁY BUILD RAPORTU — nie ma drugiej ścieżki renderu i nie ma jej
  mieć. Odsyłamy do przeliczenia raportów klubu pod aktualny templat: to ta sama
  komenda, którą uruchamia produkcja, więc podgląd nie może pokazać czegoś,
  czego raport nie pokaże.
*/ ?>
<p class="hint">
  <?= View::e(View::t('uklad.podglad')) ?>
  <a class="link" href="/klub/<?= (int) $club['id'] ?>/przelicz">
    <?= View::e(View::t('uklad.podglad.link')) ?>
  </a>
</p>
