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
/*
 * DO CZASU SKŁADU (golden layout W4): nazwiska ze zdarzeń w DWÓCH grupach.
 * Przypisani = choć jedno zdarzenie z drużyną tenanta. Bez przypisania = same
 * zdarzenia bez pola `team` (pułapka 5) — to mogą być zawodnicy rywala, więc
 * nie wolno ich pokazać jako naszych. Zdarzeń rywala nie ma tu wcale
 * (`Stats::players`).
 */
$przypisani = array_values(array_filter($zeZdarzen, static fn(array $z): bool => (int) ($z['events_team'] ?? 0) > 0));
$bezDruzyny = array_values(array_filter($zeZdarzen, static fn(array $z): bool => (int) ($z['events_team'] ?? 0) === 0));
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

<?php if ($przypisani !== []): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('team.from_events.nasi', count($przypisani))) ?></h2>
    <p class="hint"><?= View::e(View::t('team.from_events.nasi.hint')) ?></p>
    <ul class="tagi">
      <?php foreach ($przypisani as $z): ?>
        <li><?= View::e((string) $z['player']) ?>
          <span class="muted">· <?= View::e(View::t('team.zdarzen', (int) $z['events_team'])) ?><?php if ((int) $z['events_none'] > 0): ?>,
            <?= View::e(View::t('team.zdarzen.bez', (int) $z['events_none'])) ?><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if ($bezDruzyny !== []): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('team.from_events.bez', count($bezDruzyny))) ?></h2>
    <p class="hint"><?= View::e(View::t('team.from_events.bez.hint')) ?></p>
    <ul class="tagi">
      <?php foreach ($bezDruzyny as $z): ?>
        <li><?= View::e((string) $z['player']) ?> <span class="muted">· <?= View::e(View::t('team.zdarzen', (int) $z['events_none'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
