<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Karta meczu /mecze/{id} (golden layout W0, docs/GOLDEN_LAYOUT.md).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * JEDNO MIEJSCE MECZU. Zastępuje historię meczu, osobne strony mety i składu
 * oraz kartę kolejki z menu sezonowego. Rodzic karty to Sezon; rodzic raportu
 * to karta — klips „← CA" w raporcie prowadzi właśnie tutaj.
 *
 * ZAKŁADKI BEZ SKRYPTU: odsyłacze `?zakladka=`. Panel ma jeden zatwierdzony plik
 * JavaScript i jest nim `powiadomienia.js` (CLAUDE.md §9). Zapis danych meczu
 * zostaje na karcie — formularz niesie `powrot` wskazujący tę samą zakładkę.
 *
 * [op] Pokrycie, Wersje raportu i Zadania widzi wyłącznie administrator.
 * O dostępie do tras i tak rozstrzyga router; tu decydujemy, czego nie pokazać.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<string,mixed>       $mecz
 * @var array<string,mixed>|null  $club
 * @var string                    $zakladka
 * @var list<string>              $zakladki
 * @var bool                      $op
 * @var bool                      $mozeEdytowac
 * @var bool                      $mozeUdostepniac
 * @var array<string,mixed>|null  $raport
 * @var list<array<string,mixed>> $raporty
 * @var list<array<string,mixed>> $importy
 * @var array<string,mixed>|null  $import
 * @var array<string,mixed>|null  $wToku
 * @var array<string,mixed>|null  $fakty
 * @var array<string,mixed>|null  $aktywnyLink
 * @var string                    $appUrl
 * @var list<array<string,mixed>> $zadania
 * @var list<array<string,mixed>> $historia
 * @var list<array<string,mixed>> $rywale
 * @var list<array<string,mixed>> $seasons
 * @var int|null                  $sezonWybrany
 * @var list<array<string,mixed>> $sklad
 * @var list<array<string,mixed>> $propozycja
 */
$id = (int) $mecz['id'];
$karta = '/mecze/' . $id;
$kreska = View::t('common.dash');
$csrf = Session::csrfToken();

$nasz = (string) ($club['name'] ?? ($mecz['home_name'] ?? ''));
$rywal = (string) ($mecz['away_name'] ?? '');

// Wynik: wpis ręczny NADPISUJE tagi i jest oznaczony; z tagów pokazujemy zawsze,
// gdy są — rozjazd między nimi to informacja, nie szum.
$recznie = $mecz['score_us'] !== null && $mecz['score_them'] !== null;
$zTagow = $fakty !== null ? [(int) $fakty['us']['goals'], (int) $fakty['them']['goals']] : null;
$wynik = $recznie
    ? (int) $mecz['score_us'] . ':' . (int) $mecz['score_them']
    : ($zTagow !== null ? $zTagow[0] . ':' . $zTagow[1] : null);

// Stan raportu — cztery stany, każdy prawdziwy (bez procentów, CLAUDE.md §9).
$stan = match (true) {
    $wToku !== null                         => ['working', 'warn'],
    $raport !== null                        => ['ready', 'ok'],
    (string) $mecz['status'] === 'failed'   => ['failed', 'bad'],
    default                                 => ['none', ''],
};

$czlony = array_values(array_filter([
    ($mecz['round'] ?? '') !== '' ? View::t('card.round', (string) $mecz['round']) : null,
    ($mecz['played_at'] ?? '') !== '' ? substr((string) $mecz['played_at'], 0, 10) : null,
    $mecz['is_home'] === null ? null : View::t((int) $mecz['is_home'] === 1 ? 'card.home' : 'card.away'),
]));
?>
<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<header class="karta-meczu">
  <p class="karta-meczu__meta"><?= View::e(implode(' · ', $czlony) ?: $kreska) ?></p>
  <h1 class="h1 karta-meczu__tytul">
    <?= View::e($nasz !== '' ? $nasz : View::t('common.unknown')) ?>
    <span class="karta-meczu__wynik"><?= View::e($wynik ?? $kreska) ?></span>
    <?= View::e($rywal !== '' ? $rywal : View::t('match.no_club')) ?>
  </h1>
  <p class="karta-meczu__pod">
    <span class="pill <?= $stan[1] !== '' ? 'pill--' . View::e($stan[1]) : '' ?>"><i></i><?= View::e(View::t('card.state.' . $stan[0])) ?></span>
    <?php if ($recznie): ?>
      <span class="hint"><?= View::e(View::t('card.score.manual')) ?></span>
    <?php endif; ?>
    <?php if ($zTagow !== null && ($recznie || $wynik !== null)): ?>
      <span class="hint"><?= View::e(View::t('card.score.tags', $zTagow[0], $zTagow[1])) ?></span>
    <?php endif; ?>
  </p>

  <p class="acts2 karta-meczu__akcje">
    <?php if ($raport !== null): ?>
      <a class="btn p" href="/raport/<?= (int) $raport['id'] ?>"><?= View::e(View::t('card.act.open')) ?></a>
      <a class="btn s" href="/raport/<?= (int) $raport['id'] ?>#slajdy"><?= View::e(View::t('card.act.slides')) ?></a>
    <?php endif; ?>
  </p>
</header>

<?php if ($wToku !== null): ?>
  <?php /* Mecz w przygotowaniu: ten sam wskaźnik pracy co wszędzie. Bez skryptu
           stronę odświeża `<meta refresh>` z layoutu; ze skryptem wskaźnik sam
           przechodzi do wyniku. Analityk nie trafia na techniczną stronę zadania. */ ?>
  <?= View::render('wskaznik', [
      'job'       => $wToku,
      'resultUrl' => $karta,
  ]) ?>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('card.link.title')) ?></h2>
  <?php if ($raport === null): ?>
    <p class="hint"><?= View::e(View::t('card.link.no_report')) ?></p>
  <?php elseif ($aktywnyLink !== null): ?>
    <label class="field">
      <span class="field__label"><?= View::e(View::t('card.link.copy')) ?></span>
      <input class="field__input" type="text" readonly
             value="<?= View::e(rtrim($appUrl, '/') . (string) $aktywnyLink['url']) ?>">
    </label>
    <?php if ($mozeUdostepniac): ?>
      <form class="inline" method="post" action="/link/<?= (int) $aktywnyLink['id'] ?>/odwolaj">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="powrot" value="<?= View::e($karta) ?>">
        <button class="btn s link--danger" type="submit"><?= View::e(View::t('card.link.off')) ?></button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="hint"><?= View::e(View::t('card.link.none')) ?></p>
    <?php if ($mozeUdostepniac): ?>
      <form class="inline" method="post" action="/raport/<?= (int) $raport['id'] ?>/udostepnij">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="powrot" value="<?= View::e($karta) ?>">
        <button class="btn s" type="submit"><?= View::e(View::t('card.link.create')) ?></button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<nav class="zakladki" aria-label="<?= View::e(View::t('card.tabs')) ?>">
  <?php foreach ($zakladki as $z): ?>
    <a class="zakladki__poz<?= $z === $zakladka ? ' zakladki__poz--on' : '' ?>"
       href="<?= View::e($karta . '?zakladka=' . $z) ?>"
       <?= $z === $zakladka ? 'aria-current="page"' : '' ?>><?= View::e(View::t('card.tab.' . $z)) ?></a>
  <?php endforeach; ?>
</nav>

<section class="panel">
<?php if ($zakladka === 'dane'): ?>
  <?php if ($mozeEdytowac && $raport !== null): ?>
    <?php /* Uczciwa nota: meta nie wchodzi do liczb, ale nagłówek gotowego
             raportu jest już wyrenderowany i zostanie stary do przeliczenia. */ ?>
    <p class="notice" role="status"><?= View::e(View::t('meta.edit.report_note')) ?></p>
  <?php endif; ?>
  <?php if ($mozeEdytowac): ?>
    <form method="post" action="<?= View::e($karta . '/meta') ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <input type="hidden" name="powrot" value="<?= View::e($karta . '?zakladka=dane') ?>">
      <?= View::render('_meta_pola', [
          'club' => $club, 'mecz' => $mecz, 'rywale' => $rywale,
          'seasons' => $seasons, 'sezonWybrany' => $sezonWybrany,
      ]) ?>
      <?php if ($zTagow !== null): ?>
        <p class="hint"><?= View::e(View::t('card.score.tags', $zTagow[0], $zTagow[1])) ?></p>
      <?php endif; ?>
      <button class="btn" type="submit" name="akcja" value="zapisz"><?= View::e(View::t('card.dane.save')) ?></button>
    </form>
  <?php else: ?>
    <p class="hint"><?= View::e(View::t('card.readonly')) ?></p>
    <dl class="facts">
      <dt><?= View::e(View::t('meta.rival')) ?></dt><dd><?= View::e($rywal !== '' ? $rywal : $kreska) ?></dd>
      <dt><?= View::e(View::t('match.date')) ?></dt><dd><?= View::e((string) ($mecz['played_at'] ?? '') ?: $kreska) ?></dd>
      <dt><?= View::e(View::t('meta.round')) ?></dt><dd><?= View::e((string) ($mecz['round'] ?? '') ?: $kreska) ?></dd>
      <dt><?= View::e(View::t('matches.season')) ?></dt><dd><?= View::e((string) ($mecz['season_label'] ?? '') ?: $kreska) ?></dd>
      <dt><?= View::e(View::t('meta.competition')) ?></dt><dd><?= View::e((string) ($mecz['competition'] ?? '') ?: $kreska) ?></dd>
    </dl>
  <?php endif; ?>

<?php elseif ($zakladka === 'sklad'): ?>
  <?php if ($mozeEdytowac): ?>
    <form method="post" action="<?= View::e($karta . '/sklad') ?>">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <?= View::render('_sklad_tabela', ['sklad' => $sklad, 'propozycja' => $propozycja]) ?>
      <button class="btn" type="submit" name="akcja" value="zapisz"><?= View::e(View::t('card.sklad.save')) ?></button>
    </form>
  <?php elseif ($sklad === []): ?>
    <p class="empty"><?= View::e(View::t('sezon.mecz.bez_skladu')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead><tr>
          <th><?= View::e(View::t('roster.col.player')) ?></th>
          <th class="num"><?= View::e(View::t('roster.col.number')) ?></th>
          <th><?= View::e(View::t('roster.col.position')) ?></th>
          <th class="num"><?= View::e(View::t('roster.col.minutes')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sklad as $z): ?>
          <tr>
            <td><?= View::e((string) $z['player']) ?></td>
            <td class="num"><?= $z['number'] !== null ? (int) $z['number'] : View::e($kreska) ?></td>
            <td><?= View::e((string) ($z['position'] ?? '') ?: $kreska) ?></td>
            <td class="num"><?= $z['minutes'] !== null ? (int) $z['minutes'] : View::e($kreska) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php elseif ($zakladka === 'pliki'): ?>
  <?php if ($importy === []): ?>
    <p class="empty"><?= View::e(View::t('card.pliki.empty')) ?></p>
  <?php else: ?>
    <?php foreach ($importy as $imp): ?>
      <dl class="facts">
        <dt><?= View::e(View::t('card.pliki.date')) ?></dt>
        <dd><?= View::e(substr((string) $imp['created_at'], 0, 16)) ?></dd>
        <dt><?= View::e(View::t('card.pliki.csv')) ?></dt>
        <dd><?= View::e(View::t(is_file((string) $imp['csv_path']) ? 'card.pliki.ok' : 'card.pliki.missing')) ?></dd>
        <dt><?= View::e(View::t('card.pliki.json')) ?></dt>
        <dd><?= ($imp['json_path'] ?? '') === '' || $imp['json_path'] === null
                ? View::e(View::t('card.pliki.none'))
                : View::e(View::t(is_file((string) $imp['json_path']) ? 'card.pliki.ok' : 'card.pliki.missing')) ?></dd>
      </dl>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($mozeEdytowac): ?>
    <p><a class="btn s" href="<?= View::e($karta . '/wgraj') ?>"><?= View::e(View::t('card.pliki.reupload')) ?></a></p>
  <?php endif; ?>

<?php elseif ($zakladka === 'pokrycie' && $op): ?>
  <?php if ($import !== null): ?>
    <p><a class="btn s" href="/import/<?= (int) $import['id'] ?>"><?= View::e(View::t('card.pokrycie.open')) ?></a></p>
  <?php else: ?>
    <p class="empty"><?= View::e(View::t('card.pokrycie.none')) ?></p>
  <?php endif; ?>

<?php elseif ($zakladka === 'wersje' && $op): ?>
  <?php if ($raporty === []): ?>
    <p class="empty"><?= View::e(View::t('card.wersje.empty')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead><tr>
          <th><?= View::e(View::t('card.wersje.col.date')) ?></th>
          <th><?= View::e(View::t('card.wersje.col.tpl')) ?></th>
          <th><?= View::e(View::t('card.wersje.col.engine')) ?></th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($raporty as $i => $r): ?>
          <tr>
            <td><a class="link" href="/raport/<?= (int) $r['id'] ?>"><?= View::e(substr((string) $r['generated_at'], 0, 16)) ?></a></td>
            <td><?= $r['template_version'] !== null ? 'v' . (int) $r['template_version'] : View::e($kreska) ?></td>
            <td><?= View::e((string) ($r['engine_version'] ?? '') ?: $kreska) ?></td>
            <td class="akcje">
              <?php if ($i === 0): ?>
                <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/przelicz">
                  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                  <input type="hidden" name="powrot" value="<?= View::e($karta . '?zakladka=wersje') ?>">
                  <button class="link" type="submit"><?= View::e(View::t('card.wersje.recalc')) ?></button>
                </form>
                <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/ponow">
                  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                  <button class="link" type="submit"><?= View::e(View::t('card.wersje.regen')) ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php elseif ($zakladka === 'zadania' && $op): ?>
  <?php if ($zadania === []): ?>
    <p class="empty"><?= View::e(View::t('card.zadania.empty')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead><tr>
          <th><?= View::e(View::t('card.zadania.col.id')) ?></th>
          <th><?= View::e(View::t('card.zadania.col.type')) ?></th>
          <th><?= View::e(View::t('card.zadania.col.status')) ?></th>
          <th><?= View::e(View::t('card.zadania.col.date')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($zadania as $j): ?>
          <tr>
            <td><a class="link" href="/zadania/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td>
            <td><?= View::e((string) $j['type']) ?></td>
            <td><?= View::status((string) $j['status']) ?></td>
            <td><?= View::e(substr((string) ($j['created_at'] ?? ''), 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h3 class="h3"><?= View::e(View::t('card.zadania.history')) ?></h3>
  <?php if ($historia === []): ?>
    <p class="empty"><?= View::e(View::t('history.empty')) ?></p>
  <?php else: ?>
    <ol class="os">
      <?php foreach ($historia as $h): ?>
        <li class="os__poz os__poz--<?= View::e((string) $h['kind']) ?>">
          <span class="os__czas"><?= View::e(substr((string) $h['at'], 0, 16)) ?></span>
          <span class="os__opis">
            <?php if (!empty($h['url'])): ?>
              <a class="link" href="<?= View::e((string) $h['url']) ?>"><?= View::e(View::t('history.kind.' . (string) $h['kind'])) ?></a>
            <?php else: ?>
              <?= View::e(View::t('history.kind.' . (string) $h['kind'])) ?>
            <?php endif; ?>
            <?php if (!empty($h['detail'])): ?><span class="hint"><?= View::e((string) $h['detail']) ?></span><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
<?php endif; ?>
</section>
