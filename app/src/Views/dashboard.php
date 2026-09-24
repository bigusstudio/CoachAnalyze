<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Pulpit (v2, sesja 3,5): ostatni mecz, kafle liczbowe, pasek sezonu,
 * „wymaga uwagi", tabela ostatnich meczów.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * KAFEL BEZ DANYCH POKAZUJE KRESKĘ I MÓWI, SKĄD DANE PRZYJDĄ.
 *
 * Podgląd zatwierdzony przez właściciela ma sześć kafli; trzy z nich (SBZ na
 * mecz, pressing, reakcja na stratę) wymagają metryk, których warstwa żądań
 * dziś nie ma — liczy je silnik i nie zapisuje w postaci nadającej się do
 * sumowania po sezonie. Wpisanie tam czegokolwiek byłoby wymyśloną liczbą
 * pokazaną zarządowi klubu (CLAUDE.md §8, D5).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array{matches:int,matches_scope:string,reports:int,links:int,queued:int} $counters
 * @var list<array<string,mixed>> $matches
 * @var list<array<string,mixed>> $jobs
 * @var array<string,mixed>|null  $season
 * @var array<string,mixed>|null  $lastMatch
 * @var array<string,mixed>|null  $lastFacts   null = mecz bez zdarzeń
 * @var list<array<string,mixed>> $seasonRows
 * @var string|null $notice
 */
$season     ??= null;
$lastMatch  ??= null;
$lastFacts  ??= null;
$seasonRows ??= [];
$alerts     ??= [];
$metryki    ??= ['metrics' => [], 'coverage' => []];

/*
 * Metryki po `id`. Kafel bez wartości ZOSTAJE KRESKĄ — `null` znaczy albo brak
 * zdarzeń w zakresie, albo brak taga w katalogu klubu (migracja 015). Jedno
 * i drugie to „nie ma czego liczyć", nie „policzono zero" (CLAUDE.md §8).
 */
$mPoId = array_column($metryki['metrics'] ?? [], null, 'id');

/** Wartość metryki jako tekst albo kreska. Procent dla wskaźników. */
$metryka = static function (string $id, bool $procent = false) use ($mPoId): string {
    $w = $mPoId[$id]['value'] ?? null;
    if ($w === null) {
        return View::t('common.dash');
    }
    return $procent ? round((float) $w * 100) . '%' : (string) $w;
};

/** Liczba albo kreska. Zero jest wynikiem; brak danych nim nie jest. */
$lub = static fn($w, string $format = '%s'): string =>
    $w === null ? View::t('common.dash') : sprintf($format, $w);

/** Klasa wyniku z perspektywy klubu: wygrana / remis / porażka. */
$klasaWyniku = static function (?int $nas, ?int $ich): string {
    if ($nas === null || $ich === null) {
        return '';
    }
    return $nas > $ich ? ' res--w' : ($nas < $ich ? ' res--l' : ' res--d');
};
?>
<div class="head">
  <div>
    <h1><?= View::e(View::t('dash.title')) ?></h1>
    <div class="sub2"><?= View::e($counters['matches_scope']) ?></div>
  </div>
  <?php /* JEDEN przycisk podstawowy na ekran — reszta akcji jest drugorzędna. */ ?>
  <div class="acts">
    <a class="btn p" href="/import"><?= View::e(View::t('import.nav')) ?></a>
  </div>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<div class="grid">

  <?php /* ─────────────────────────────────────────── ostatni mecz */ ?>
  <section class="card c8">
    <h2>
      <?= View::e(View::t('dash.last_match')) ?>
      <?php if ($lastMatch !== null): ?>
        <a class="more" href="/mecze/<?= (int) $lastMatch['id'] ?>/historia"><?= View::e(View::t('dash.more')) ?></a>
      <?php endif; ?>
    </h2>

    <?php if ($lastMatch === null): ?>
      <p class="empty"><?= View::e(View::t('dash.no_match')) ?></p>
    <?php else: ?>
      <?php
        $golNas = $lastFacts !== null ? (int) $lastFacts['us']['goals'] : null;
        $golIch = $lastFacts !== null ? (int) $lastFacts['them']['goals'] : null;
        $xgNas  = $lastFacts !== null ? (float) $lastFacts['us']['xg'] : null;
        $xgIch  = $lastFacts !== null ? (float) $lastFacts['them']['xg'] : null;
        $suma   = ($xgNas ?? 0) + ($xgIch ?? 0);
      ?>
      <div class="hero">
        <div class="hero__t">
          <span class="hero__crest" aria-hidden="true">
            <?php if (!empty($lastMatch['home_crest'])): ?>
              <img src="/herb/<?= (int) $lastMatch['club_home_id'] ?>" alt="">
            <?php else: ?>
              <?= View::e(mb_substr((string) ($lastMatch['home_name'] ?? '?'), 0, 1)) ?>
            <?php endif; ?>
          </span>
          <span>
            <b><?= View::e((string) ($lastMatch['home_name'] ?? View::t('match.no_club'))) ?></b>
            <small><?= View::e(View::t('match.us')) ?></small>
          </span>
        </div>

        <div class="hero__sc<?= $klasaWyniku($golNas, $golIch) ?>">
          <span><?= View::e($lub($golNas)) ?></span>
          <small>:</small>
          <span><?= View::e($lub($golIch)) ?></span>
        </div>

        <div class="hero__t hero__t--r">
          <span>
            <b><?= View::e((string) ($lastMatch['away_name'] ?? View::t('match.no_club'))) ?></b>
            <small><?= View::e(View::t('match.them')) ?></small>
          </span>
          <span class="hero__crest" aria-hidden="true">
            <?php if (!empty($lastMatch['away_crest'])): ?>
              <img src="/herb/<?= (int) $lastMatch['club_away_id'] ?>" alt="">
            <?php else: ?>
              <?= View::e(mb_substr((string) ($lastMatch['away_name'] ?? '?'), 0, 1)) ?>
            <?php endif; ?>
          </span>
        </div>

        <?php /* Pasek xG. Bez zdarzeń nie rysujemy proporcji — rysowalibyśmy zero. */ ?>
        <div class="hero__xg">
          <span><?= View::e($lub($xgNas, '%.2f')) ?></span>
          <span class="hero__t2" aria-hidden="true">
            <?php if ($lastFacts !== null && $suma > 0): ?>
              <i class="a" style="width: <?= round($xgNas / $suma * 100, 1) ?>%"></i>
              <i class="b" style="width: <?= round($xgIch / $suma * 100, 1) ?>%"></i>
            <?php endif; ?>
          </span>
          <span style="text-align:right"><?= View::e($lub($xgIch, '%.2f')) ?></span>
        </div>

        <div class="hero__meta">
          <?php if (!empty($lastMatch['played_at'])): ?>
            <span><?= View::e((string) $lastMatch['played_at']) ?></span>
          <?php endif; ?>
          <?php if (!empty($lastMatch['round'])): ?>
            <span><?= View::e(View::t('dash.col.round')) ?> <?= View::e((string) $lastMatch['round']) ?></span>
          <?php endif; ?>
          <span class="pill pill--ok"><i></i><?= View::e(View::t('dash.pill.import')) ?></span>
          <span class="pill pill--ok"><i></i><?= View::e(View::t('dash.pill.report')) ?></span>
          <a class="pill pill--club" href="/linki"><i></i><?= View::e(View::t('dash.pill.link')) ?></a>
        </div>
      </div>

      <?php if ($lastFacts === null): ?>
        <p class="hint hint--block"><?= View::e(View::t('dash.no_events')) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php /* ─────────────────────────────────────────── wymaga uwagi */ ?>
  <section class="card c4">
    <h2><?= View::e(View::t('dash.attention')) ?></h2>
    <div class="todo">
      <?php $cos = false; ?>

      <?php foreach ($alerts as $a): ?>
        <?php $cos = true; ?>
        <div class="todo__row">
          <span class="todo__ic todo__ic--<?= $a['level'] === 'error' ? 'b' : 'w' ?>" aria-hidden="true">!</span>
          <span>
            <b><?= View::e($a['msg']) ?></b>
            <small><?= View::e($a['hint']) ?></small>
          </span>
        </div>
      <?php endforeach; ?>

      <?php foreach ($jobs as $j): ?>
        <?php $cos = true; ?>
        <div class="todo__row">
          <span class="todo__ic todo__ic--<?= $j['status'] === 'failed' ? 'b' : 'w' ?>" aria-hidden="true">
            <?= $j['status'] === 'failed' ? '×' : '⋯' ?>
          </span>
          <span>
            <b><?= View::e(View::t('job.type')) ?></b>
            <small><?= View::e(View::t('status.' . $j['status'])) ?> · #<?= (int) $j['id'] ?></small>
          </span>
          <a class="btn s" href="/zadania/<?= (int) $j['id'] ?>"><?= View::e(View::t('dash.open')) ?></a>
        </div>
      <?php endforeach; ?>

      <?php if (!$cos): ?>
        <p class="empty"><?= View::e(View::t('dash.attention.none')) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <?php /* ─────────────────────────────────────────── kafle liczbowe */ ?>
  <?php
    // Trzy pierwsze mają dane DZIŚ. Trzy kolejne czekają na metryki sezonowe
    // i pokazują kreskę z podpisem — patrz nagłówek pliku.
    $kafle = [
        ['l' => View::t('dash.kpi.matches'), 'v' => (string) $counters['matches'],
         'd' => $counters['matches_scope'], 'pusty' => false],
        ['l' => View::t('dash.kpi.reports'), 'v' => (string) $counters['reports'],
         'd' => View::t('nav.reports'), 'pusty' => false],
        ['l' => View::t('dash.kpi.queue'), 'v' => (string) $counters['queued'],
         'd' => View::t('nav.queue', $counters['queued']), 'pusty' => false],
        // Sesja 3: trzy kafle liczone z tabeli `events`. Kreska zostaje
        // WYŁĄCZNIE wtedy, gdy metryka nie ma wartości.
        ['l' => View::t('dash.kpi.sbz'), 'v' => $metryka('sbz_na_mecz'),
         'd' => (string) ($mPoId['sbz_na_mecz']['label'] ?? ''),
         'pusty' => ($mPoId['sbz_na_mecz']['value'] ?? null) === null],
        ['l' => View::t('dash.kpi.press'), 'v' => $metryka('pressing', true),
         'd' => ($mPoId['pressing']['d'] ?? null) !== null
             ? View::t('dash.of_total', (int) $mPoId['pressing']['d']) : '',
         'pusty' => ($mPoId['pressing']['value'] ?? null) === null],
        ['l' => View::t('dash.kpi.reaction'), 'v' => $metryka('reakcja_na_strate', true),
         'd' => ($mPoId['reakcja_na_strate']['d'] ?? null) !== null
             ? View::t('dash.of_total', (int) $mPoId['reakcja_na_strate']['d']) : '',
         'pusty' => ($mPoId['reakcja_na_strate']['value'] ?? null) === null],
    ];
  ?>
  <?php foreach ($kafle as $k): ?>
    <section class="card c4 kpi<?= $k['pusty'] ? ' kpi--pusty' : '' ?>">
      <span class="l"><?= View::e($k['l']) ?></span>
      <span class="v"><?= View::e($k['v']) ?></span>
      <span class="d"><?= View::e($k['d']) ?></span>
    </section>
  <?php endforeach; ?>

  <?php /* ─────────────────────────────────────────── pasek sezonu */ ?>
  <section class="card c12">
    <h2>
      <?= View::e(View::t('dash.season_strip')) ?>
      <span class="hint"><?= View::e($counters['matches_scope']) ?></span>
      <a class="more" href="/mecze"><?= View::e(View::t('dash.more')) ?></a>
    </h2>

    <?php if ($seasonRows === []): ?>
      <p class="empty"><?= View::e(View::t('dash.no_match')) ?></p>
    <?php else: ?>
      <div class="season">
        <?php foreach (array_reverse($seasonRows) as $r): ?>
          <?php
            $ma  = (int) $r['events'] > 0;
            $nas = $ma ? (int) $r['goals_us'] : null;
            $ich = $ma ? (int) $r['goals_them'] : null;
            $kl  = 'q';
            if ($r['status'] !== 'done') {
                $kl .= ' q--next';
            } elseif ($ma) {
                $kl .= $nas > $ich ? ' q--w' : ($nas < $ich ? ' q--l' : ' q--d');
            }
          ?>
          <a class="<?= $kl ?>" href="/mecze/<?= (int) $r['id'] ?>/historia"
             title="<?= View::e(trim(((string) ($r['home_name'] ?? '?')) . ' – ' . ((string) ($r['away_name'] ?? '?')))) ?>">
            <?= View::e($r['round'] !== null && $r['round'] !== '' ? (string) $r['round'] : '·') ?>
            <small><?= $ma ? View::e($nas . ':' . $ich) : View::e(View::t('common.dash')) ?></small>
          </a>
        <?php endforeach; ?>
        <?php /* SUMA prowadzi do zapowiedzi: zestawienia sezonowego jeszcze nie ma. */ ?>
        <a class="q q--next" href="/kalendarz"><?= View::e(View::t('dash.season_sum')) ?></a>
      </div>
      <p class="legend">
        <span><i style="background: var(--ok-mikkie)"></i><?= View::e(View::t('dash.legend.win')) ?></span>
        <span><i style="background: var(--tlo-drugi)"></i><?= View::e(View::t('dash.legend.draw')) ?></span>
        <span><i style="background: var(--blad-mikkie)"></i><?= View::e(View::t('dash.legend.loss')) ?></span>
        <span><i style="background: transparent; border: 1.5px dashed var(--obramowanie)"></i><?= View::e(View::t('dash.legend.next')) ?></span>
      </p>
    <?php endif; ?>
  </section>

  <?php /* ─────────────────────────────────────────── ostatnie mecze */ ?>
  <section class="card c12">
    <h2>
      <?= View::e(View::t('dash.recent')) ?>
      <a class="more" href="/mecze"><?= View::e(View::t('dash.more')) ?></a>
    </h2>

    <?php if ($seasonRows === []): ?>
      <p class="empty"><?= View::e(View::t('dash.no_match')) ?></p>
    <?php else: ?>
      <div class="tbl-scroll">
        <table>
          <thead>
            <tr>
              <th><?= View::e(View::t('dash.col.match')) ?></th>
              <th><?= View::e(View::t('dash.col.result')) ?></th>
              <th class="num"><?= View::e(View::t('dash.col.xg')) ?></th>
              <th class="num"><?= View::e(View::t('dash.col.shots')) ?></th>
              <th class="num"><?= View::e(View::t('dash.col.sbz')) ?></th>
              <th><?= View::e(View::t('dash.col.status')) ?></th>
              <th class="num"><?= View::e(View::t('dash.col.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($seasonRows, 0, 8) as $r): ?>
              <?php
                $ma  = (int) $r['events'] > 0;
                $nas = $ma ? (int) $r['goals_us'] : null;
                $ich = $ma ? (int) $r['goals_them'] : null;
              ?>
              <tr>
                <td>
                  <span class="match">
                    <span class="k"><?= View::e($r['round'] !== null && $r['round'] !== '' ? (string) $r['round'] : '·') ?></span>
                    <span>
                      <b><?= View::e((string) ($r['home_name'] ?? View::t('match.no_club'))) ?>
                         – <?= View::e((string) ($r['away_name'] ?? View::t('match.no_club'))) ?></b>
                      <small><?= View::e((string) ($r['played_at'] ?? View::t('common.dash'))) ?></small>
                    </span>
                  </span>
                </td>
                <td>
                  <span class="res<?= $klasaWyniku($nas, $ich) ?>">
                    <?= $ma ? View::e($nas . ':' . $ich) : View::e(View::t('common.dash')) ?>
                  </span>
                </td>
                <td class="num">
                  <?= $ma
                      ? View::e(sprintf('%.2f : %.2f', (float) $r['xg_us'], (float) $r['xg_them']))
                      : View::e(View::t('common.dash')) ?>
                </td>
                <td class="num">
                  <?= $ma
                      ? View::e($r['shots_us'] . ' : ' . $r['shots_them'])
                      : View::e(View::t('common.dash')) ?>
                </td>
                <?php
                  // Sesja 3: SBZ per mecz z tej samej definicji, co kafel.
                  // Jedno zapytanie na wiersz — tabela ma osiem wierszy.
                  $sbzMeczu = ($tenantId ?? null) !== null
                      ? \CoachAnalyze\Metrics::compute(
                            \CoachAnalyze\Metrics::definicje()['sbz'],
                            ['club_id' => (int) $tenantId, 'match_id' => (int) $r['id']]
                        )['value']
                      : null;
                ?>
                <td class="num"><?= $sbzMeczu === null
                    ? View::e(View::t('common.dash')) : (int) $sbzMeczu ?></td>
                <td>
                  <span class="pill pill--<?= $r['status'] === 'done' ? 'ok' : ($r['status'] === 'failed' ? 'bad' : 'warn') ?>">
                    <i></i><?= View::e(View::t('status.' . $r['status'])) ?>
                  </span>
                </td>
                <td>
                  <span class="acts2">
                    <a class="btn s" href="/mecze/<?= (int) $r['id'] ?>/historia"><?= View::e(View::t('dash.report')) ?></a>
                    <a class="btn s" href="/kalendarz"><?= View::e(View::t('dash.slides')) ?></a>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
