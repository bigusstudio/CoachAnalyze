<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * DRUŻYNA (golden layout W2, pkt 16) — do czasu modułu M7.
 *
 * Kadra z `match_players` (numer, pozycja, mecze, minuty, pierwszy skład)
 * i statystyki sezonu per zawodnik. NIGDY PUSTY EKRAN: bez składów pokazujemy
 * nazwiska ze zdarzeń i zdanie, skąd biorą się minuty; bez niczego — co zrobić.
 *
 * @var array<string,mixed>       $club
 * @var list<array<string,mixed>> $kadra
 * @var list<array<string,mixed>> $zawodnicy
 * @var list<array<string,mixed>> $sezony
 * @var int|null                  $sezonId
 * @var string|null               $sezonLabel
 */
$kreska = View::t('common.dash');
$dziesietna = static fn($w): string => number_format((float) $w, 2, ',', ' ');
$zeZdarzen = array_values(array_filter($zawodnicy, static fn(array $z): bool => empty($z['in_roster'])));
?>
<h1 class="h1"><?= View::e(View::t('nav.team')) ?></h1>
<p class="hint">
  <?= View::e((string) $club['name']) ?>
  <?php if ($sezonLabel !== null): ?> · <?= View::e($sezonLabel) ?><?php endif; ?>
</p>

<?php if (count($sezony) > 1): ?>
  <p class="hint">
    <?= View::e(View::t('sezon.przelacz')) ?>
    <?php foreach ($sezony as $s): ?>
      <a class="link" href="/druzyna?sezon=<?= (int) $s['id'] ?>"><?= View::e((string) $s['label']) ?></a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('team.kadra')) ?></h2>
  <?php if ($kadra === []): ?>
    <p class="empty"><?= View::e(View::t($zeZdarzen === [] ? 'team.empty' : 'team.no_roster')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead><tr>
          <th class="num"><?= View::e(View::t('roster.col.number')) ?></th>
          <th><?= View::e(View::t('roster.col.player')) ?></th>
          <th><?= View::e(View::t('roster.col.position')) ?></th>
          <th class="num"><?= View::e(View::t('team.col.matches')) ?></th>
          <th class="num"><?= View::e(View::t('team.col.starts')) ?></th>
          <th class="num"><?= View::e(View::t('roster.col.minutes')) ?></th>
          <th class="num"><?= View::e(View::t('dash.col.shots')) ?></th>
          <th class="num"><?= View::e(View::t('team.col.goals')) ?></th>
          <th class="num"><?= View::e(View::t('dash.col.xg')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($kadra as $z): ?>
          <tr>
            <td class="num"><?= $z['number'] !== null ? (int) $z['number'] : View::e($kreska) ?></td>
            <td><?= View::e((string) $z['player']) ?></td>
            <td><?= View::e((string) ($z['position'] ?? '') ?: $kreska) ?></td>
            <td class="num"><?= (int) $z['matches'] ?></td>
            <td class="num"><?= (int) $z['starts'] ?></td>
            <td class="num"><?= $z['minutes'] !== null ? (int) $z['minutes'] : View::e($kreska) ?></td>
            <td class="num"><?= (int) $z['shots'] ?></td>
            <td class="num"><?= (int) $z['goals'] ?></td>
            <td class="num"><?= View::e($dziesietna($z['xg'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($zeZdarzen !== []): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('team.from_events')) ?></h2>
    <p class="hint"><?= View::e(View::t('team.from_events.hint')) ?></p>
    <ul class="tagi">
      <?php foreach ($zeZdarzen as $z): ?>
        <li><?= View::e((string) $z['player']) ?> <span class="muted">· <?= (int) $z['events'] ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
