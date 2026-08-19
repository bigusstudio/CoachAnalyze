<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Raport pokrycia — ekran PRZED renderem.
 *
 * Operator ma tu zobaczyć, co silnik znalazł w pliku, i dopiero potem zdecydować
 * o wygenerowaniu raportu. Liczby pochodzą wprost z `meta.coverage`, ostrzeżenia
 * i powody niedostępnych sekcji — z silnika, po polsku. PHP niczego nie przelicza.
 *
 * @var array<string,mixed>       $import
 * @var array<string,mixed>       $coverage
 * @var list<array<string,mixed>> $warnings
 * @var list<array<string,mixed>> $sectionsUnavailable
 * @var array<string,mixed> $excluded
 * @var list<string>              $sectionsAvailable
 * @var array<string,mixed>|null  $report
 * @var string|null               $notice
 * @var array<string,mixed>|null  $pozaTemplatem  diff wobec templatu (Sesja 6)
 * @var string|null               $revWersja  wersja templatu podbita w rewizji
 * @var array<string,mixed>|null  $meczMeta
 */
$liczby = [
    'cov.events'          => $coverage['events']          ?? null,
    'cov.shots'           => $coverage['shots']           ?? null,
    'cov.xg'              => $coverage['xg_parsed']       ?? null,
    'cov.sbz'             => $coverage['sbz']             ?? null,
    'cov.sbz_vector'      => $coverage['sbz_with_vector'] ?? null,
    'cov.third'           => $coverage['third']           ?? null,
    'cov.third_pos'       => $coverage['third_pos']       ?? null,
    'cov.duels'           => $coverage['duels']           ?? null,
    'cov.no_team'         => $coverage['no_team']         ?? null,
    'cov.players'         => $coverage['players_filled']  ?? null,
];
?>
<h1 class="h1"><?= View::e(View::t('coverage.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<p class="hint"><?= View::e(View::t('coverage.lead')) ?></p>

<?php if (!empty($meczMeta)): ?>
  <?php /*
    DANE MECZU NA WIERZCHU, z drogą do poprawki.

    Data i sezon nie wpływają na liczby, ale opisują raport i stoją w jego
    nagłówku. Dotąd dało się je ustawić wyłącznie raz, w kroku przed diffem —
    mecz przepuszczony bez daty zostawał bez daty i bez sezonu na zawsze.
  */ ?>
  <section class="panel">
    <div class="actions actions--head">
      <h2 class="h2"><?= View::e(View::t('meta.edit.title')) ?></h2>
      <a class="link" href="/mecze/<?= (int) $meczMeta['id'] ?>/meta?powrot=<?= rawurlencode('/import/' . (int) $import['id']) ?>">
        <?= View::e(View::t('meta.edit.link')) ?>
      </a>
    </div>
    <dl class="facts">
      <dt><?= View::e(View::t('match.date')) ?></dt>
      <dd><?= !empty($meczMeta['played_at'])
              ? View::e(substr((string) $meczMeta['played_at'], 0, 10))
              : '<span class="muted">' . View::e(View::t('match.no_date')) . '</span>' ?></dd>

      <dt><?= View::e(View::t('matches.season')) ?></dt>
      <dd><?= !empty($meczMeta['season_label'])
              ? View::e((string) $meczMeta['season_label'])
              : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></dd>

      <dt><?= View::e(View::t('match.them')) ?></dt>
      <dd><?= !empty($meczMeta['away_name'])
              ? View::e((string) $meczMeta['away_name'])
              : '<span class="muted">' . View::e(View::t('match.no_club')) . '</span>' ?></dd>
    </dl>
  </section>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('coverage.numbers')) ?></h2>
  <dl class="facts facts--wide">
    <?php foreach ($liczby as $klucz => $wartosc): ?>
      <dt><?= View::e(View::t($klucz)) ?></dt>
      <dd>
        <?php if ($wartosc === null): ?>
          <span class="muted"><?= View::e(View::t('common.dash')) ?></span>
        <?php else: ?>
          <?= View::e((string) $wartosc) ?>
        <?php endif; ?>
      </dd>
    <?php endforeach; ?>

    <dt><?= View::e(View::t('cov.teams')) ?></dt>
    <dd>
      <?php $teams = (array) ($coverage['teams'] ?? []); ?>
      <?php if ($teams === []): ?>
        <span class="muted"><?= View::e(View::t('cov.teams.none')) ?></span>
      <?php else: ?>
        <?php // Nazwa z eksportu dopasowana do klubu przez aliasy. Bez dopasowania
              // proponujemy założenie klubu z tą nazwą — operator ją potwierdza
              // albo poprawia, a alias zapamiętuje się na kolejne mecze. ?>
        <ul class="teams">
          <?php foreach ($teams as $detected): ?>
            <?php $club = \CoachAnalyze\Clubs::matchByExportName((string) $detected); ?>
            <li class="teams__row">
              <span class="teams__name"><?= View::e((string) $detected) ?></span>
              <?php if ($club !== null): ?>
                <span class="tag tag--done"><?= View::e(View::t('cov.team.matched')) ?></span>
                <a class="link" href="/kluby/<?= (int) $club['id'] ?>"><?= View::e((string) $club['name']) ?></a>
              <?php else: ?>
                <a class="link" href="/kluby/nowy?nazwa=<?= rawurlencode((string) $detected)
                    ?>&amp;powrot=<?= rawurlencode('/import/' . (int) $import['id']) ?>">
                  <?= View::e(View::t('cov.team.create')) ?>
                </a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </dd>

    <dt><?= View::e(View::t('cov.json')) ?></dt>
    <dd><?= View::e(View::t(!empty($coverage['has_json']) ? 'common.yes' : 'common.no')) ?></dd>
  </dl>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('coverage.warnings')) ?></h2>
  <?php if ($warnings === []): ?>
    <p class="empty"><?= View::e(View::t('coverage.no_warnings')) ?></p>
  <?php else: ?>
    <ul class="warns">
      <?php foreach ($warnings as $w): ?>
        <li class="warns__row">
          <?php // Treść ostrzeżenia przychodzi z silnika po polsku — nie tłumaczymy
                // jej drugi raz w PHP, żeby nie rozjechać się ze źródłem. ?>
          <span class="warns__msg"><?= View::e((string) ($w['msg'] ?? '')) ?></span>
          <?php if (!empty($w['count']) && (int) $w['count'] > 1): ?>
            <span class="tag"><?= View::e(View::t('coverage.count', (int) $w['count'])) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('coverage.sections')) ?></h2>

  <?php if ($sectionsUnavailable === []): ?>
    <p class="empty"><?= View::e(View::t('coverage.all_sections')) ?></p>
  <?php else: ?>
    <ul class="warns">
      <?php foreach ($sectionsUnavailable as $s): ?>
        <li class="warns__row">
          <span class="tag tag--failed"><?= View::e((string) ($s['id'] ?? '')) ?></span>
          <?php // Powód niesie silnik i trafia tu bez zmian — analityk ma wiedzieć,
                // CZEGO brakuje i DLACZEGO, zamiast oglądać pustą sekcję. ?>
          <span class="warns__msg"><?= View::e((string) ($s['reason'] ?? '')) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($sectionsAvailable !== []): ?>
    <p class="hint"><?= View::e(View::t('coverage.available', implode(', ', $sectionsAvailable))) ?></p>
  <?php endif; ?>
</section>

<div class="actions">
<section class="panel">
  <h2 class="h2"><?= View::e(View::t('coverage.excluded')) ?></h2>

  <?php /*
    RAPORT NIE OBEJMUJE WSZYSTKIEGO — i to musi być widoczne.

    Dwa źródła zdarzeń poza analizą, i drugie jest groźniejsze:
      1. tagi nierozpoznane — nikt jeszcze nie zdecydował, co z nimi zrobić,
      2. tagi świadomie pominięte — decyzja jest poprawna, ale wygląda na
         „obsłużone" i po pół roku nikt nie pamięta, że część zdarzeń
         nie wchodzi do liczb.

    Bez tej sekcji raport pokrycia sugeruje kompletność, której nie ma.
  */ ?>
  <?php
    /*
     * Adres rewizji mapowania dla pojedynczej pozycji. Chip w tej sekcji ma
     * prowadzić WPROST do tego taga, a nie do listy, na której trzeba go szukać.
     *
     * Rewizja ma sens wyłącznie dla klubu z templatem — bez niego decyzje
     * o tagach zapadają w kreatorze mapowań i tam kieruje istniejący odsyłacz.
     */
    $rewizjaMozliwa = !empty($pozaTemplatem);
    $adresRewizji = function (string $typ, string $nazwa) use ($import): string {
        $k = \CoachAnalyze\TemplateDiff::kluczHtml($typ, $nazwa);
        return '/import/' . (int) $import['id'] . '/diff?rewizja=1&tag=' . $k . '#poz-' . $k;
    };
  ?>
  <?php if (($excluded['unrecognised'] ?? []) === [] && ($excluded['ignored'] ?? []) === []): ?>
    <p class="empty"><?= View::e(View::t('coverage.excluded.none')) ?></p>
  <?php else: ?>
    <?php if ($excluded['count'] !== null): ?>
      <p class="alert" role="alert">
        <?= View::e($excluded['total'] !== null
            ? View::t('coverage.excluded.count_of', (int) $excluded['count'], (int) $excluded['total'])
            : View::t('coverage.excluded.count', (int) $excluded['count'])) ?>
      </p>
    <?php else: ?>
      <?php /*
        Liczby zdarzeń poza analizą nie ma dziś w wyniku `inspect`
        (docs/KONTRAKT_CLI.md). Mówimy to wprost zamiast pokazywać zero —
        zero znaczyłoby „wszystko policzone", a to nieprawda.
      */ ?>
      <p class="notice" role="status"><?= View::e(View::t('coverage.excluded.count_unknown')) ?></p>
    <?php endif; ?>

    <?php if (($excluded['unrecognised'] ?? []) !== []): ?>
      <h3 class="h3"><?= View::e(View::t('coverage.excluded.unrecognised')) ?></h3>
      <p class="tagi">
        <?php foreach ($excluded['unrecognised'] as $tag): ?>
          <code class="tag-nazwa"><?= View::e((string) $tag) ?></code>
        <?php endforeach; ?>
      </p>
      <p class="hint">
        <a class="link" href="/import/<?= (int) $import['id'] ?>/mapowanie">
          <?= View::e(View::t('coverage.excluded.map_now')) ?>
        </a>
      </p>
    <?php endif; ?>

    <?php if (($excluded['ignored'] ?? []) !== []): ?>
      <h3 class="h3"><?= View::e(View::t('coverage.excluded.ignored')) ?></h3>
      <p class="tagi">
        <?php foreach ($excluded['ignored'] as $tag): ?>
          <?php if ($rewizjaMozliwa): ?>
            <?php /* Chip klikalny — prowadzi do rewizji z fokusem na tym tagu. */ ?>
            <a class="tag-nazwa tag-nazwa--pominiety"
               href="<?= View::e($adresRewizji('tag', (string) $tag)) ?>"
               title="<?= View::e(View::t('rev.chip.hint')) ?>"><?= View::e((string) $tag) ?></a>
          <?php else: ?>
            <code class="tag-nazwa tag-nazwa--pominiety"><?= View::e((string) $tag) ?></code>
          <?php endif; ?>
        <?php endforeach; ?>
      </p>
      <p class="hint"><?= View::e(View::t('coverage.excluded.ignored.hint')) ?></p>
      <?php if ($rewizjaMozliwa): ?>
        <?php /* DROGA WYJŚCIA PRZY LIŚCIE, nie do wyszukania w panelu.
                 Sekcja mówiła dotąd „te zdarzenia nie wchodzą do liczb"
                 i na tym kończyła — operator musiał sam znaleźć, gdzie to
                 zmienić. */ ?>
        <p>
          <a class="btn btn--ghost" href="/import/<?= (int) $import['id'] ?>/diff?rewizja=1">
            <?= View::e(View::t('rev.act.open')) ?>
          </a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php /*
  POZA TEMPLATEM KLUBU. Pozycje, o które operator został zapytany na ekranie
  diffu i których nie dopisał, oraz te zignorowane na stałe. Ich zdarzenia
  NIE wchodzą do metryk — i to musi być widoczne przed kliknięciem „Generuj",
  a nie odkryte pół roku później.
*/ ?>
<?php if (!empty($pozaTemplatem) && (($pozaTemplatem['nowe'] ?? []) !== [] || ($pozaTemplatem['ignorowane'] ?? []) !== [])): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('cov.excluded.template')) ?></h2>
    <p class="hint"><?= View::e(View::t('cov.excluded.template.hint')) ?></p>

    <?php if (($pozaTemplatem['nowe'] ?? []) !== []): ?>
      <h3 class="h3"><?= View::e(View::t('diff.new')) ?></h3>
      <p class="tagi">
        <?php foreach ($pozaTemplatem['nowe'] as $poz): ?>
          <a class="tag-nazwa"
             href="<?= View::e($adresRewizji((string) $poz['type'], (string) $poz['name'])) ?>"
             title="<?= View::e(View::t('rev.chip.hint')) ?>"><?= View::e((string) $poz['name']) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if (($pozaTemplatem['ignorowane'] ?? []) !== []): ?>
      <h3 class="h3"><?= View::e(View::t('diff.ignored')) ?></h3>
      <p class="tagi">
        <?php foreach ($pozaTemplatem['ignorowane'] as $poz): ?>
          <a class="tag-nazwa tag-nazwa--pominiety"
             href="<?= View::e($adresRewizji((string) $poz['type'], (string) $poz['name'])) ?>"
             title="<?= View::e(View::t('rev.chip.hint')) ?>"><?= View::e((string) $poz['name']) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <p>
      <a class="btn btn--ghost" href="/import/<?= (int) $import['id'] ?>/diff?rewizja=1">
        <?= View::e(View::t('rev.act.open')) ?>
      </a>
      <span class="hint"><?= View::e(View::t('rev.act.open.hint')) ?></span>
    </p>
  </section>
<?php endif; ?>

  <?php if (!empty($revWersja)): ?>
    <?php /*
      TEMPLAT WŁAŚNIE URÓSŁ W REWIZJI, a raport dla tego meczu stoi na
      poprzedniej wersji. Bez tego zdania operator widzi odświeżony podział
      i uznaje sprawę za załatwioną — a liczby w raporcie zostają stare.
    */ ?>
    <p class="notice" role="status">
      <?= View::e(View::t('rev.regenerate.hint', (int) $revWersja)) ?>
    </p>
  <?php endif; ?>

  <form method="post" action="/import/<?= (int) $import['id'] ?>/generuj">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <button class="btn" type="submit">
      <?= View::e(View::t($report === null ? 'coverage.generate' : 'coverage.regenerate')) ?>
    </button>
  </form>

  <?php if ($report !== null): ?>
    <a class="btn btn--ghost" href="/raport/<?= (int) $report['id'] ?>">
      <?= View::e(View::t('job.report')) ?>
    </a>
    <span class="hint"><?= View::e(View::t(
        'job.report.meta',
        substr((string) $report['generated_at'], 0, 16),
        (string) $report['engine_version']
    )) ?></span>
  <?php endif; ?>

  <a class="link" href="/"><?= View::e(View::t('common.back')) ?></a>
</div>
