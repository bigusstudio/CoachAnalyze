<?php
declare(strict_types=1);

use CoachAnalyze\Configurator;
use CoachAnalyze\UstawieniaKlubu;
use CoachAnalyze\View;

/**
 * USTAWIENIA KLUBU (golden layout W3) — Układ raportu · Słownik klubu · [op] Zaawansowane.
 *
 * Poza hierarchią Pulpit → Sezon → Mecz. Tu trafia wszystko, co analityk
 * ustawia raz, a nie przy każdym meczu. BEZ SKRYPTU: strzałki i „ukryj" to
 * przyciski `submit` z własną wartością `akcja` (stan roboczy w sesji), zapis
 * tworzy nową wersję templatu i przelicza raporty klubu w tle.
 *
 * @var array<string,mixed>       $club
 * @var bool                      $op
 * @var list<string>              $zakladki
 * @var string                    $zakladka
 * @var array<string,mixed>|null  $templat
 * @var list<array<string,mixed>> $sekcje
 * @var list<string>              $ukryte
 * @var bool                      $roboczy
 * @var list<array<string,mixed>> $wliczane
 * @var list<array<string,mixed>> $nierozpoznane
 * @var list<array<string,mixed>> $historia
 * @var list<string>|null         $martwe
 * @var string|null               $partia
 * @var array<string,mixed>|null  $postep
 * @var string                    $csrf
 */
$id = (int) $club['id'];
$wersja = $templat !== null ? (int) $templat['version'] : 0;
$ostatni = count($sekcje) - 1;
?>
<h1 class="h1"><?= View::e(View::t('nav.club_settings')) ?></h1>
<p class="hint"><?= View::e((string) $club['name']) ?>
  <?php if ($op && $wersja > 0): ?><span class="tag"><?= View::e(View::t('ust.wersja', $wersja)) ?></span><?php endif; ?>
</p>

<nav class="zakladki" aria-label="<?= View::e(View::t('nav.club_settings')) ?>">
  <?php foreach ($zakladki as $z): ?>
    <a class="zakladki__z<?= $z === $zakladka ? ' zakladki__z--on' : '' ?>"
       href="/klub/ustawienia?zakladka=<?= View::e($z) ?>"
       <?= $z === $zakladka ? 'aria-current="page"' : '' ?>><?= View::e(View::t('ust.tab.' . $z)) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!empty($notice)): ?>
  <p class="notice notice--ok" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($partia !== null && $postep !== null): ?>
  <?php /* Ten sam wskaźnik partii co ekran przeliczenia: skrypt podmienia liczby,
           po domknięciu przeładowuje stronę; bez skryptu — <meta refresh>. */ ?>
  <section class="panel"
           data-partia="<?= View::e($partia) ?>"
           data-partia-punkt="/partia/<?= View::e($partia) ?>/stan"
           data-partia-trwa="<?= (int) $postep['working'] > 0 ? '1' : '0' ?>"
           data-partia-bledy="<?= (int) $postep['failed'] ?>">
    <p role="status">
      <strong>
        <?= View::e(View::t('recalc.progress.done_label')) ?>
        <b data-rola="gotowe"><?= (int) $postep['done'] ?></b>
        <?= View::e(View::t('recalc.progress.of_total', (int) $postep['total'])) ?>
      </strong>
      <?php if ((int) $postep['working'] > 0): ?>
        · <?= View::e(View::t('ust.przeliczanie')) ?>
      <?php endif; ?>
    </p>
  </section>
<?php endif; ?>

<?php if ($templat === null): ?>
  <section class="panel">
    <p class="empty"><?= View::e(View::t('ust.brak_templatu')) ?></p>
  </section>

<?php elseif ($zakladka === 'uklad'): ?>
  <?php /* ------------------------------------------------ Układ raportu */ ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('ust.tab.uklad')) ?></h2>
    <p class="hint"><?= View::e(View::t('ust.uklad.hint')) ?></p>

    <?php if ($roboczy): ?>
      <p class="notice" role="status"><?= View::e(View::t('uklad.roboczy')) ?></p>
    <?php endif; ?>

    <form method="post" action="/klub/ustawienia/uklad">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <ol class="uklad-lista">
        <?php foreach ($sekcje as $i => $wpis): ?>
          <?php $widget = (string) ($wpis['widgets'][0] ?? ''); $ruch = UstawieniaKlubu::ruchoma($wpis); ?>
          <li class="uklad-lista__w">
            <span class="uklad-lista__nr"><?= View::e(sprintf('%02d', $i + 1)) ?></span>
            <span class="uklad-lista__nazwa"><?= View::e(View::t('kafel.' . $widget)) ?></span>
            <?php if ($ruch): ?>
              <button class="btn s" type="submit" name="akcja" value="gora:<?= (int) $i ?>"
                      aria-label="<?= View::e(View::t('uklad.gora')) ?>" <?= $i <= 1 ? 'disabled' : '' ?>>&uarr;</button>
              <button class="btn s" type="submit" name="akcja" value="dol:<?= (int) $i ?>"
                      aria-label="<?= View::e(View::t('uklad.dol')) ?>" <?= $i === $ostatni ? 'disabled' : '' ?>>&darr;</button>
              <button class="btn s" type="submit" name="akcja" value="ukryj:<?= (int) $i ?>"><?= View::e(View::t('ust.ukryj')) ?></button>
            <?php else: ?>
              <span class="hint"><?= View::e(View::t('ust.przeglad_staly')) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>

      <div class="actions">
        <button class="btn p" type="submit" name="akcja" value="zapisz"><?= View::e(View::t('ust.zapisz')) ?></button>
        <?php if ($roboczy): ?>
          <button class="btn s" type="submit" name="akcja" value="porzuc"><?= View::e(View::t('uklad.porzuc')) ?></button>
        <?php endif; ?>
      </div>
      <p class="hint hint--block"><?= View::e(View::t('ust.zapisz.hint')) ?></p>
    </form>
  </section>

  <?php if ($ukryte !== []): ?>
    <section class="panel">
      <h2 class="h2"><?= View::e(View::t('ust.ukryte')) ?></h2>
      <ul class="uklad-lista">
        <?php foreach ($ukryte as $widget): ?>
          <li class="uklad-lista__w">
            <span class="uklad-lista__nazwa"><?= View::e(View::t('kafel.' . $widget)) ?></span>
            <form method="post" action="/klub/ustawienia/uklad">
              <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
              <input type="hidden" name="widget" value="<?= View::e($widget) ?>">
              <button class="btn s" type="submit" name="akcja" value="pokaz"><?= View::e(View::t('ust.pokaz')) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

<?php elseif ($zakladka === 'slownik'): ?>
  <?php /* ------------------------------------------------ Słownik klubu */ ?>
  <form method="post" action="/klub/ustawienia/slownik">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

    <section class="panel">
      <h2 class="h2"><?= View::e(View::t('ust.nierozpoznane', count($nierozpoznane))) ?></h2>
      <?php if ($nierozpoznane === []): ?>
        <p class="empty"><?= View::e(View::t('ust.nierozpoznane.brak')) ?></p>
      <?php else: ?>
        <p class="hint"><?= View::e(View::t('ust.nierozpoznane.hint')) ?></p>
        <table class="table">
          <thead><tr>
            <th><?= View::e(View::t('ust.kol.tag')) ?></th>
            <th><?= View::e(View::t('ust.kol.ile')) ?></th>
            <th><?= View::e(View::t('ust.kol.wlicz')) ?></th>
            <th><?= View::e(View::t('ust.kol.etykieta')) ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($nierozpoznane as $k => $n): ?>
              <?php $pominiety = $n['powod'] === UstawieniaKlubu::POMINIETY; ?>
              <tr>
                <td><code><?= View::e($n['name']) ?></code>
                  <input type="hidden" name="wlicz_nazwa[<?= (int) $k ?>]" value="<?= View::e($n['name']) ?>">
                  <br><span class="hint"><?= View::e($pominiety
                      ? View::t('ust.powod.pominiety')
                      : View::t('ust.powod.bez_znaczenia', implode(', ', array_map(
                          static fn(string $s): string => View::t('sekcja.' . $s), $n['sekcje'])))) ?></span></td>
                <td><?= View::e(View::t('ust.ile', $n['events'], $n['matches'])) ?></td>
                <td>
                  <select class="field__input" name="wlicz[<?= (int) $k ?>]">
                    <option value=""><?= View::e(View::t($pominiety ? 'ust.wlicz.nie' : 'ust.wlicz.zostaw')) ?></option>
                    <?php if ($pominiety): ?>
                      <option value="nowa"><?= View::e(View::t('ust.wlicz.nowa')) ?></option>
                    <?php else: ?>
                      <option value="bilans"><?= View::e(View::t('ust.wlicz.bilans')) ?></option>
                    <?php endif; ?>
                    <?php foreach ($wliczane as $w): ?>
                      <?php if ($w['typ'] === 'tag' && $w['i'] !== $n['i']): ?>
                        <option value="z:<?= (int) $w['i'] ?>"><?= View::e(View::t('ust.wlicz.kontynuacja', $w['label'] !== '' ? $w['label'] : $w['raw'])) ?></option>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><?php if ($pominiety): ?><input class="field__input" type="text" maxlength="80" name="wlicz_etykieta[<?= (int) $k ?>]"
                           placeholder="<?= View::e($n['name']) ?>"><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2 class="h2"><?= View::e(View::t('ust.wliczane', count($wliczane))) ?></h2>
      <p class="hint"><?= View::e(View::t('ust.wliczane.hint')) ?></p>
      <table class="table">
        <thead><tr>
          <th><?= View::e(View::t('ust.kol.tag')) ?></th>
          <th><?= View::e(View::t('ust.kol.etykieta')) ?></th>
          <th><?= View::e(View::t('ust.kol.sekcje')) ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($wliczane as $w): ?>
            <tr>
              <td><code><?= View::e($w['raw']) ?></code>
                <?php if ($w['typ'] !== 'tag'): ?><span class="tag"><?= View::e(View::t('ust.etykieta_eksportu')) ?></span><?php endif; ?>
                <?php foreach ($w['aliases'] as $a): ?>
                  <br><span class="hint"><?= View::e(View::t('ust.kontynuacja', $a)) ?></span>
                <?php endforeach; ?>
              </td>
              <td><input class="field__input" type="text" maxlength="80" name="etykieta[<?= (int) $w['i'] ?>]"
                         value="<?= View::e($w['label']) ?>"></td>
              <td class="sekcje-wybor">
                <?php foreach (Configurator::SEKCJE as $s): ?>
                  <label><input type="checkbox" name="sekcje[<?= (int) $w['i'] ?>][]" value="<?= View::e($s) ?>"
                         <?= in_array($s, $w['sections'], true) ? 'checked' : '' ?>> <?= View::e(View::t('sekcja.' . $s)) ?></label>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <div class="actions">
      <button class="btn p" type="submit"><?= View::e(View::t('ust.zapisz')) ?></button>
      <span class="hint"><?= View::e(View::t('ust.zapisz.hint')) ?></span>
    </div>
  </form>

<?php elseif ($zakladka === 'zaawansowane' && $op): ?>
  <?php /* ------------------------------------------------ [op] Zaawansowane */ ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('ust.zaaw.zmienne')) ?></h2>
    <p class="hint"><?= View::e(View::t('ust.zaaw.zmienne.hint')) ?></p>
    <ul class="ustawienia">
      <li><a class="link" href="/klub/<?= $id ?>/konfigurator"><?= View::e(View::t('ust.zaaw.konfigurator')) ?></a></li>
      <li><a class="link" href="/kluby/<?= $id ?>/mapowania"><?= View::e(View::t('settings.mappings')) ?></a></li>
      <li><a class="link" href="/klub/<?= $id ?>/uklad"><?= View::e(View::t('ust.zaaw.uklad')) ?></a></li>
      <li><a class="link" href="/kluby/<?= $id ?>"><?= View::e(View::t('settings.club_data')) ?></a></li>
    </ul>
  </section>

  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('ust.zaaw.martwe')) ?></h2>
    <?php if ($martwe === null): ?>
      <p class="empty"><?= View::e(View::t('ust.zaaw.martwe.brak_katalogu')) ?></p>
    <?php elseif ($martwe === []): ?>
      <p class="empty"><?= View::e(View::t('ust.zaaw.martwe.zero')) ?></p>
    <?php else: ?>
      <p class="hint"><?= View::e(View::t('ust.zaaw.martwe.hint')) ?></p>
      <ul><?php foreach ($martwe as $m): ?><li><code><?= View::e($m) ?></code></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('ust.zaaw.historia')) ?></h2>
    <p class="hint"><?= View::e(View::t('ust.zaaw.historia.hint')) ?></p>
    <table class="table">
      <tbody>
        <?php foreach ($historia as $h): ?>
          <tr>
            <td><?= View::e(View::t('ust.wersja', (int) $h['version'])) ?></td>
            <td><?= View::e((string) $h['created_at']) ?></td>
            <td><?= View::e((string) ($h['author'] ?? View::t('common.dash'))) ?></td>
            <td>
              <?php if ((int) $h['version'] !== $wersja): ?>
                <form method="post" action="/klub/<?= $id ?>/templaty/<?= (int) $h['version'] ?>/klonuj">
                  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                  <input type="hidden" name="powrot" value="ustawienia">
                  <button class="btn s" type="submit"><?= View::e(View::t('ust.zaaw.przywroc')) ?></button>
                </form>
              <?php else: ?>
                <span class="tag tag--done"><?= View::e(View::t('ust.zaaw.biezaca')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
