<?php
declare(strict_types=1);

use CoachAnalyze\Auth;
use CoachAnalyze\Session;
use CoachAnalyze\Users;
use CoachAnalyze\View;
use CoachAnalyze\XgCalc;

/**
 * Kalkulator xG (M3) — interaktywne boisko BEZ JEDNEJ LINII SKRYPTU.
 *
 * Obrazek boiska jest przyciskiem formularza (`input type="image"`):
 * kliknięcie wysyła współrzędne w pikselach razem z wybranymi parametrami.
 * Wartość pochodzi z siatki policzonej przez silnik — patrz XgCalc.
 *
 * @var list<array<string,mixed>> $shots
 * @var float $sum
 * @var bool $gridReady
 * @var array<string,mixed>|null $edited  strzał w trybie edycji
 * @var array<string,mixed>|null $last    ostatnio dodany strzał
 * @var string|null $notice
 * @var string|null $error
 */
$user = Auth::currentUser();
$moze = Users::can($user, 'generate');
?>
<?php /*
  WSPÓLNY PRZODEK boiska i listy — potrzebny regułom parującym niżej.
*/ ?>
<div class="xg">

<?php if ($shots !== []): ?>
  <?php /*
    PODŚWIETLENIE W OBIE STRONY, BEZ JEDNEJ LINII SKRYPTU.

    Wymaganie brzmi: najechanie na kropkę podświetla wiersz listy i odwrotnie.
    CSS nie skojarzy dwóch elementów po wspólnej wartości atrybutu — trzeba
    wskazać konkretną parę, więc reguły powstają tu, po jednej na strzał.
    `:has()` na wspólnym przodku sprawia, że jedna reguła obsługuje OBA
    kierunki naraz: wskazanie któregokolwiek z pary wyróżnia oba.

    Dlaczego nie skrypt: §9 CLAUDE.md dopuszcza JavaScript w panelu wyłącznie
    do chmurek i wskaźnika pracy, a rozszerzenie tej listy wymaga osobnego
    uzgodnienia. Podświetlenie na hover da się zrobić bez niego, więc robimy
    je bez niego.

    W bloku NIE MA ANI JEDNEJ BARWY — te siedzą w `app.css` pod klasą
    `.is-wyrozniony`. Tutaj są wyłącznie selektory.
  */ ?>
  <style>
    <?php foreach ($shots as $s): $id = (int) $s['id']; ?>
    /* Wskazanie KTÓREGOKOLWIEK z pary wyróżnia oba. `:focus-within` dokłada
       obsługę klawiatury: wiersz ma odnośniki, więc da się go osiągnąć tabem. */
    .xg:has([data-strzal="<?= $id ?>"]:hover) .xg-wiersz[data-strzal="<?= $id ?>"] > td,
    .xg:has([data-strzal="<?= $id ?>"]:focus-within) .xg-wiersz[data-strzal="<?= $id ?>"] > td {
      background-color: var(--info-tlo);
    }
    .xg:has([data-strzal="<?= $id ?>"]:hover) .xg-znacznik[data-strzal="<?= $id ?>"],
    .xg:has([data-strzal="<?= $id ?>"]:focus-within) .xg-znacznik[data-strzal="<?= $id ?>"] {
      transform: scale(1.6);
      z-index: 2;
    }
    <?php endforeach; ?>
  </style>
<?php endif; ?>

<h1 class="h1"><?= View::e(View::t('xg.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<p class="hint hint--block"><?= View::e(View::t('xg.intro')) ?></p>
<p class="hint hint--block">
  <?= View::e(\CoachAnalyze\IndexTerms::XG_ZASTRZEZENIE) ?>
  <a class="link" href="/indeks/xg"><?= View::e(View::t('xg.index_link')) ?></a>
</p>

<?php if (!$gridReady): ?>
  <p class="alert" role="alert"><?= View::e(View::t('xg.err.no_grid')) ?></p>
<?php elseif ($moze): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('xg.pitch.title')) ?></h2>
    <p class="hint"><?= View::e(View::t('xg.pitch.hint')) ?></p>

    <form method="post" action="/xg">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

      <div class="actions">
        <label class="field field--check">
          <input type="radio" name="body_part" value="foot" checked>
          <span><?= View::e(View::t('xg.body.foot')) ?></span>
        </label>
        <label class="field field--check">
          <input type="radio" name="body_part" value="head">
          <span><?= View::e(View::t('xg.body.head')) ?></span>
        </label>
      </div>
      <div class="actions">
        <label class="field field--check">
          <input type="radio" name="situation" value="open" checked>
          <span><?= View::e(View::t('xg.sit.open')) ?></span>
        </label>
        <label class="field field--check">
          <input type="radio" name="situation" value="free_kick">
          <span><?= View::e(View::t('xg.sit.free_kick')) ?></span>
        </label>
        <label class="field field--check">
          <input type="radio" name="situation" value="penalty">
          <span><?= View::e(View::t('xg.sit.penalty')) ?></span>
        </label>
      </div>

      <?php /*
        Obrazek JEST przyciskiem: klik = wysłanie punkt_x/punkt_y.

        `width`/`height` to PRZESTRZEŃ WSPÓŁRZĘDNYCH, nie rozmiar wyświetlania.
        Boisko rozciąga do szerokości karty `transform: scale()` z app.css, a to
        zachowuje układ współrzędnych zgłaszany przez `input type="image"` —
        sprawdzone w przeglądarce. Gdyby zamiast tego użyć `width: 100%`, te same
        105×68 metrów opisywałaby inna liczba pikseli przy każdej szerokości okna
        i ten sam klik dawałby inną wartość xG. Atrybutów NIE WOLNO stąd usunąć
        ani zmienić bez poprawienia `XgCalc::PX_NA_METR`.

        Znaczniki dodanych strzałów leżą w tym samym kontenerze, pozycjonowane
        w procentach — dzięki temu trzymają swoje miejsce przy każdej szerokości.
      */ ?>
      <div class="xg-boisko">
        <input class="xg-boisko__pole" type="image" name="punkt"
               src="<?= View::e(View::asset('/assets/boisko.svg')) ?>"
               alt="<?= View::e(View::t('xg.pitch.alt')) ?>" width="525" height="340">

        <?php foreach ($shots as $s): ?>
          <?php
            // Metry -> procent boiska. 105 x 68 m to ten sam prostokąt, co
            // `aspect-ratio` kontenera, więc procent jest tu miarą dokładną.
            $lewo = max(0.0, min(100.0, ((float) $s['x'] / 105) * 100));
            $gora = max(0.0, min(100.0, ((float) $s['y'] / 68) * 100));
          ?>
          <span class="xg-znacznik xg-znacznik--<?= View::e((string) $s['body_part']) ?>"
                data-strzal="<?= (int) $s['id'] ?>"
                style="left: <?= number_format($lewo, 3, '.', '') ?>%;
                       top: <?= number_format($gora, 3, '.', '') ?>%"
                title="<?= View::e(View::t(
                    'xg.marker.title',
                    number_format((float) $s['xg'], 2, ',', ''),
                    View::t('xg.body.' . $s['body_part'])
                )) ?>"></span>
        <?php endforeach; ?>
      </div>
    </form>

    <?php if ($last !== null): ?>
      <p class="notice" role="status">
        <?= View::e(View::t(
            'xg.result',
            number_format((float) $last['xg'], 2, ',', ''),
            number_format((float) $last['x'], 1, ',', ''),
            number_format((float) $last['y'], 1, ',', '')
        )) ?>
        — <?= View::e(View::t(XgCalc::quality((float) $last['xg']))) ?>
      </p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($edited !== null && $moze): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('xg.edit.title', (int) $edited['id'])) ?></h2>
    <form method="post" action="/xg/<?= (int) $edited['id'] ?>">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <div class="actions">
        <label class="field field--slim">
          <span class="field__label">x (m)</span>
          <input class="field__input" type="number" name="x" min="0" max="105" step="0.1"
                 value="<?= View::e((string) $edited['x']) ?>">
        </label>
        <label class="field field--slim">
          <span class="field__label">y (m)</span>
          <input class="field__input" type="number" name="y" min="0" max="68" step="0.1"
                 value="<?= View::e((string) $edited['y']) ?>">
        </label>
        <label class="field field--slim">
          <span class="field__label"><?= View::e(View::t('xg.col.body')) ?></span>
          <select class="field__input" name="body_part">
            <option value="foot" <?= $edited['body_part'] === 'foot' ? 'selected' : '' ?>><?= View::e(View::t('xg.body.foot')) ?></option>
            <option value="head" <?= $edited['body_part'] === 'head' ? 'selected' : '' ?>><?= View::e(View::t('xg.body.head')) ?></option>
          </select>
        </label>
        <label class="field field--slim">
          <span class="field__label"><?= View::e(View::t('xg.col.situation')) ?></span>
          <select class="field__input" name="situation">
            <?php foreach (['open', 'free_kick', 'penalty'] as $sytuacja): ?>
              <option value="<?= $sytuacja ?>" <?= $edited['situation'] === $sytuacja ? 'selected' : '' ?>>
                <?= View::e(View::t('xg.sit.' . $sytuacja)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="actions">
        <button class="btn" type="submit"><?= View::e(View::t('xg.edit.submit')) ?></button>
        <a class="link" href="/xg"><?= View::e(View::t('common.back')) ?></a>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('xg.list.title')) ?></h2>

  <?php if ($shots === []): ?>
    <p class="empty"><?= View::e(View::t('xg.list.empty')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th scope="col">xG</th>
          <th scope="col"><?= View::e(View::t('xg.col.position')) ?></th>
          <th scope="col"><?= View::e(View::t('xg.col.body')) ?></th>
          <th scope="col"><?= View::e(View::t('xg.col.situation')) ?></th>
          <th scope="col"><?= View::e(View::t('xg.col.quality')) ?></th>
          <?php if ($moze): ?><th scope="col"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($shots as $s): ?>
          <?php /* `data-strzal` paruje wiersz z kropką na boisku — patrz
                   reguły wygenerowane na górze widoku. */ ?>
          <tr class="xg-wiersz" data-strzal="<?= (int) $s['id'] ?>">
            <td><strong><?= View::e(number_format((float) $s['xg'], 2, ',', '')) ?></strong></td>
            <td class="num"><?= View::e(number_format((float) $s['x'], 1, ',', '')) ?> ×
                <?= View::e(number_format((float) $s['y'], 1, ',', '')) ?> m</td>
            <td><?= View::e(View::t('xg.body.' . $s['body_part'])) ?></td>
            <td><?= View::e(View::t('xg.sit.' . $s['situation'])) ?></td>
            <td><?= View::e(View::t(XgCalc::quality((float) $s['xg']))) ?></td>
            <?php if ($moze): ?>
              <td class="num">
                <a class="link" href="/xg?edytuj=<?= (int) $s['id'] ?>"><?= View::e(View::t('xg.edit')) ?></a>
                <form method="post" action="/xg/<?= (int) $s['id'] ?>/usun" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                  <button class="btn btn--ghost" type="submit"><?= View::e(View::t('xg.delete')) ?></button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p class="hint"><strong><?= View::e(View::t('xg.sum', number_format($sum, 2, ',', ''))) ?></strong></p>
  <?php endif; ?>
</section>
</div>
