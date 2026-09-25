<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * SEZON › lista kolejek (sesja 7 pivotu „viewer").
 *
 * KOLEJNOŚĆ ROZGRYWKOWA, NIE ODWROTNA CHRONOLOGIA. Lista „ostatnie mecze" na
 * pulpicie zaczyna od najnowszego, bo odpowiada na pytanie „co się właśnie
 * wydarzyło". To jest lista KOLEJEK i czyta się ją od pierwszej, jak tabelę.
 *
 * @var array<string,mixed>       $club
 * @var list<array<string,mixed>> $kolejki
 * @var list<array<string,mixed>> $sezony
 * @var int|null                  $sezonId
 * @var string|null               $sezonLabel
 */
$przelacz = static fn(int $id): string => '/sezon?klub=' . (int) $club['id'] . '&sezon=' . $id;
?>
<h1 class="h1"><?= View::e(View::t('sezon.title')) ?></h1>
<p class="hint">
  <?= View::e((string) $club['name']) ?>
  <?php if ($sezonLabel !== null): ?> · <?= View::e($sezonLabel) ?><?php endif; ?>
</p>

<?php if (count($sezony) > 1): ?>
  <p class="hint">
    <?= View::e(View::t('sezon.przelacz')) ?>
    <?php foreach ($sezony as $s): ?>
      <a class="link<?= (int) $s['id'] === $sezonId ? ' is-active' : '' ?>"
         href="<?= View::e($przelacz((int) $s['id'])) ?>"><?= View::e((string) $s['label']) ?></a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<section class="panel">
  <?php if ($kolejki === []): ?>
    <p class="empty"><?= View::e(View::t('sezon.pusto')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('dash.col.round')) ?></th>
            <th><?= View::e(View::t('dash.col.match')) ?></th>
            <th><?= View::e(View::t('match.date')) ?></th>
            <th><?= View::e(View::t('dash.col.result')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.xg')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.shots')) ?></th>
            <th><?= View::e(View::t('dash.col.status')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kolejki as $nr => $k): ?>
          <?php
            $ma = (int) $k['events'] > 0;
            $liczba = static fn($w): string => number_format((float) $w, 2, ',', ' ');
          ?>
          <tr>
            <td>
              <?php /* Kolejka z prefiksem, numer porządkowy wyszarzony — ta sama
                       zasada co w pasku sezonu na pulpicie (sesja 6). */ ?>
              <?php if ($k['round'] !== null && $k['round'] !== ''): ?>
                <b><?= View::e(View::t('dash.round.prefix', (string) $k['round'])) ?></b>
              <?php else: ?>
                <span class="muted"><?= View::e((string) ($nr + 1)) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <a class="link" href="/sezon/mecz/<?= (int) $k['id'] ?>">
                <?= View::e((string) ($k['home_name'] ?? View::t('match.no_club'))) ?>
                – <?= View::e((string) ($k['away_name'] ?? View::t('match.no_club'))) ?>
              </a>
            </td>
            <td><?= View::e((string) ($k['played_at'] ?? View::t('common.dash'))) ?></td>
            <td><?= $ma ? View::e((int) $k['goals_us'] . ':' . (int) $k['goals_them'])
                        : View::e(View::t('common.dash')) ?></td>
            <td class="num"><?= $ma ? View::e($liczba($k['xg_us']) . ' : ' . $liczba($k['xg_them']))
                                    : View::e(View::t('common.dash')) ?></td>
            <td class="num"><?= $ma ? View::e((int) $k['shots_us'] . ' : ' . (int) $k['shots_them'])
                                    : View::e(View::t('common.dash')) ?></td>
            <td><?= View::e(View::t('status.' . (string) $k['status'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<p>
  <a class="btn p" href="/sezon/suma?klub=<?= (int) $club['id'] ?><?= $sezonId !== null ? '&sezon=' . $sezonId : '' ?>">
    <?= View::e(View::t('sezon.suma.link')) ?>
  </a>
</p>
