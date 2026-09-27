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

<?php
  $kreska = View::t('common.dash');
  $liczba = static fn($w): string => number_format((float) $w, 2, ',', ' ');
  $procent = static fn(array $m): string => $m['value'] === null
      ? View::t('common.dash')
      : round(100 * (float) $m['value']) . '% (' . (int) $m['n'] . '/' . (int) $m['d'] . ')';
  // SUMA z tych samych wierszy, które widać — tylko mecze ze strzałami (W1).
  $suma = ['gu' => 0, 'gt' => 0, 'xu' => 0.0, 'xt' => 0.0, 'su' => 0, 'st' => 0, 'n' => 0];
  foreach ($kolejki as $k) {
      if ((int) $k['events'] > 0 && (int) ($k['shots_all'] ?? 0) > 0) {
          $suma['gu'] += (int) $k['goals_us']; $suma['gt'] += (int) $k['goals_them'];
          $suma['xu'] += (float) $k['xg_us']; $suma['xt'] += (float) $k['xg_them'];
          $suma['su'] += (int) $k['shots_us']; $suma['st'] += (int) $k['shots_them'];
          $suma['n']++;
      }
  }
?>
<section class="panel">
  <?php if ($kolejki === []): ?>
    <p class="empty"><?= View::e(View::t('sezon.pusto')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl tbl--sezon">
        <thead>
          <tr>
            <th><?= View::e(View::t('dash.col.round')) ?></th>
            <th><?= View::e(View::t('meta.rival')) ?></th>
            <th><?= View::e(View::t('match.date')) ?></th>
            <th><?= View::e(View::t('meta.where')) ?></th>
            <th><?= View::e(View::t('dash.col.result')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.xg')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.shots')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.sbz')) ?></th>
            <th class="num"><?= View::e(View::t('sezon.col.pressing')) ?></th>
            <th><?= View::e(View::t('sezon.col.report')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kolejki as $nr => $k): ?>
          <?php $ma = (int) $k['events'] > 0 && (int) ($k['shots_all'] ?? 0) > 0; ?>
          <tr>
            <td>
              <?php /* „k. N" przy kolejce, numer porządkowy wyszarzony bez prefiksu —
                       ta sama zasada co w pasku sezonu na pulpicie. */ ?>
              <?php if ($k['round'] !== null && $k['round'] !== ''): ?>
                <b><?= View::e(View::t('dash.round.prefix', (string) $k['round'])) ?></b>
              <?php else: ?>
                <span class="muted"><?= View::e((string) ($nr + 1)) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php /* Klik w wiersz prowadzi na KARTĘ MECZU (rodzica raportu). */ ?>
              <a class="link" href="/mecze/<?= (int) $k['id'] ?>">
                <?= View::e((string) ($k['away_name'] ?? View::t('match.no_club'))) ?>
              </a>
            </td>
            <td><?= View::e((string) ($k['played_at'] ?? '') ?: $kreska) ?></td>
            <td><?= View::e($k['is_home'] === null ? $kreska
                    : View::t((int) $k['is_home'] === 1 ? 'card.home' : 'card.away')) ?></td>
            <td><?= View::e($k['score_us'] !== null && $k['score_them'] !== null
                    ? (int) $k['score_us'] . ':' . (int) $k['score_them']
                    : ($ma ? (int) $k['goals_us'] . ':' . (int) $k['goals_them'] : $kreska)) ?></td>
            <td class="num"><?= View::e($ma ? $liczba($k['xg_us']) . ' : ' . $liczba($k['xg_them']) : $kreska) ?></td>
            <td class="num"><?= View::e($ma ? (int) $k['shots_us'] . ' : ' . (int) $k['shots_them'] : $kreska) ?></td>
            <td class="num"><?= View::e($k['sbz'] === null ? $kreska : (string) (int) $k['sbz']) ?></td>
            <td class="num"><?= View::e($procent($k['pressing'])) ?></td>
            <td>
              <?php if ($k['w_toku']): ?>
                <span class="pill pill--warn"><i></i><?= View::e(View::t('card.state.working')) ?></span>
              <?php elseif ($k['raport'] !== null): ?>
                <a class="pill pill--ok" href="/raport/<?= (int) $k['raport']['id'] ?>"><i></i><?= View::e(View::t('card.state.ready')) ?></a>
              <?php elseif ((string) $k['status'] === 'failed'): ?>
                <span class="pill pill--bad"><i></i><?= View::e(View::t('card.state.failed')) ?></span>
              <?php else: ?>
                <span class="pill"><i></i><?= View::e(View::t('card.state.none')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="tbl__suma">
            <th colspan="4"><?= View::e(View::t('sezon.suma.row', $suma['n'])) ?></th>
            <td><?= View::e($suma['n'] > 0 ? $suma['gu'] . ':' . $suma['gt'] : $kreska) ?></td>
            <td class="num"><?= View::e($suma['n'] > 0 ? $liczba($suma['xu']) . ' : ' . $liczba($suma['xt']) : $kreska) ?></td>
            <td class="num"><?= View::e($suma['n'] > 0 ? $suma['su'] . ' : ' . $suma['st'] : $kreska) ?></td>
            <td class="num"><?= View::e($sumaSbz === null ? $kreska : (string) (int) $sumaSbz) ?></td>
            <td class="num"><?= View::e($procent($sumaPressing)) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</section>

<p>
  <a class="btn p" href="/sezon/suma?klub=<?= (int) $club['id'] ?><?= $sezonId !== null ? '&sezon=' . $sezonId : '' ?>">
    <?= View::e(View::t('sezon.suma.link')) ?>
  </a>
</p>
