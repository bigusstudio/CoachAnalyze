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
 * @var list<string>              $sectionsAvailable
 * @var array<string,mixed>|null  $report
 * @var string|null               $notice
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
