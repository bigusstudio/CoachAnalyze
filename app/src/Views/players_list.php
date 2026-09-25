<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * ZAWODNICY — skład scalony ze zdarzeniami (sesja 7 pivotu „viewer").
 *
 * DWA ŹRÓDŁA, BO OPISUJĄ CO INNEGO:
 *   `match_players` — kto był w protokole i ile zagrał,
 *   `events.player`  — kto co zrobił.
 * Zawodnik, który rozegrał 90 minut i nie dostał ani jednego taga, jest tylko
 * w pierwszym; nazwisko ze zdarzeń bez wpisu w składzie — tylko w drugim,
 * i to znaczy albo literówkę, albo skład, o którym zapomniano.
 *
 * KRESKA TO NIE ZERO. Klub, który nie wpisuje składów, ma zobaczyć kreski
 * w kolumnie minut i zdanie, skąd minuty się biorą — a nie „0 minut" przy
 * każdym zawodniku, co jest po prostu nieprawdą.
 *
 * @var array<string,mixed>       $club
 * @var list<array<string,mixed>> $zawodnicy
 * @var list<array<string,mixed>> $sezony
 * @var int|null                  $sezonId
 * @var string|null               $sezonLabel
 */
$kreska = View::t('common.dash');
$dziesietna = static fn($w): string => number_format((float) $w, 2, ',', ' ');
$bezMinut = true;
foreach ($zawodnicy as $z) {
    if ($z['minutes'] !== null) {
        $bezMinut = false;
    }
}
?>
<h1 class="h1"><?= View::e(View::t('nav.players')) ?></h1>
<p class="hint">
  <?= View::e((string) $club['name']) ?>
  <?php if ($sezonLabel !== null): ?> · <?= View::e($sezonLabel) ?><?php endif; ?>
</p>

<?php if (count($sezony) > 1): ?>
  <p class="hint">
    <?= View::e(View::t('sezon.przelacz')) ?>
    <?php foreach ($sezony as $s): ?>
      <a class="link" href="/zawodnicy?klub=<?= (int) $club['id'] ?>&sezon=<?= (int) $s['id'] ?>">
        <?= View::e((string) $s['label']) ?>
      </a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<section class="panel">
  <?php if ($zawodnicy === []): ?>
    <p class="empty"><?= View::e(View::t('players.empty')) ?></p>
  <?php else: ?>
    <?php if ($bezMinut): ?>
      <p class="hint"><?= View::e(View::t('players.no_roster')) ?></p>
    <?php endif; ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('roster.col.player')) ?></th>
            <th class="num"><?= View::e(View::t('players.col.matches')) ?></th>
            <th class="num"><?= View::e(View::t('roster.col.minutes')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.shots')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.xg')) ?></th>
            <th class="num"><?= View::e(View::t('players.col.goals')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($zawodnicy as $z): ?>
          <tr>
            <td>
              <?= View::e((string) $z['player']) ?>
              <?php if (empty($z['in_roster'])): ?>
                <span class="muted">· <?= View::e(View::t('players.not_in_roster')) ?></span>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int) $z['matches'] ?></td>
            <td class="num"><?= $z['minutes'] !== null ? (int) $z['minutes'] : View::e($kreska) ?></td>
            <td class="num"><?= (int) $z['shots'] ?: View::e($kreska) ?></td>
            <td class="num"><?= (float) $z['xg'] > 0 ? View::e($dziesietna($z['xg'])) : View::e($kreska) ?></td>
            <td class="num"><?= (int) $z['goals'] ?: View::e($kreska) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
