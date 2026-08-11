<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Pulpit: cztery liczniki, ostatnie mecze, zadania wymagające uwagi.
 *
 * @var array{matches:int,matches_scope:string,reports:int,links:int,queued:int} $counters
 * @var list<array<string,mixed>> $matches
 * @var list<array<string,mixed>> $jobs
 * @var string|null $notice
 */
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('dash.title')) ?></h1>
  <a class="btn btn--ghost" href="/import"><?= View::e(View::t('import.nav')) ?></a>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<section class="cards" aria-label="<?= View::e(View::t('dash.title')) ?>">
  <div class="card">
    <span class="card__value"><?= View::e((string) $counters['matches']) ?></span>
    <span class="card__label"><?= View::e(View::t('dash.c.matches')) ?></span>
    <span class="card__note"><?= View::e($counters['matches_scope']) ?></span>
  </div>
  <div class="card">
    <span class="card__value"><?= View::e((string) $counters['reports']) ?></span>
    <span class="card__label"><?= View::e(View::t('dash.c.reports')) ?></span>
  </div>
  <div class="card">
    <span class="card__value"><?= View::e((string) $counters['links']) ?></span>
    <span class="card__label"><?= View::e(View::t('dash.c.links')) ?></span>
  </div>
  <div class="card">
    <span class="card__value"><?= View::e((string) $counters['queued']) ?></span>
    <span class="card__label"><?= View::e(View::t('dash.c.queued')) ?></span>
  </div>
</section>

<div class="split">
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('dash.recent')) ?></h2>

    <?php if ($matches === []): ?>
      <p class="empty"><?= View::e(View::t('dash.empty.matches')) ?></p>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('match.date')) ?></th>
            <?php // Nie „Gospodarz"/„Gość": eksport LiveTag nie niesie stron boiska,
                  // a przy meczu wyjazdowym taka kolumna po prostu kłamie. ?>
            <th><?= View::e(View::t('match.us')) ?></th>
            <th><?= View::e(View::t('match.them')) ?></th>
            <th><?= View::e(View::t('match.status')) ?></th>
            <th><?= View::e(View::t('match.action')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($matches as $m): ?>
          <tr>
            <td>
              <?php if (!empty($m['played_at'])): ?>
                <?= View::e(substr((string) $m['played_at'], 0, 10)) ?>
              <?php else: ?>
                <span class="muted"><?= View::e(View::t('match.no_date')) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($m['home_name'])): ?>
                <?= View::e((string) $m['home_name']) ?>
              <?php else: ?>
                <span class="muted"><?= View::e(View::t('match.no_club')) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($m['away_name'])): ?>
                <?= View::e((string) $m['away_name']) ?>
              <?php else: ?>
                <span class="muted"><?= View::e(View::t('match.no_club')) ?></span>
              <?php endif; ?>
            </td>
            <td><?= View::status((string) $m['status']) ?></td>
            <td><a class="link" href="/mecze"><?= View::e(View::t('job.preview')) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('dash.jobs')) ?></h2>
    <p class="hint"><?= View::e(View::t('dash.jobs.hint')) ?></p>

    <?php if ($jobs === []): ?>
      <p class="empty"><?= View::e(View::t('dash.empty.jobs')) ?></p>
    <?php else: ?>
      <ul class="jobs">
      <?php foreach ($jobs as $j): ?>
        <li class="jobs__row">
          <a class="link" href="/zadania/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a>
          <span class="jobs__type"><?= View::e((string) $j['type']) ?></span>
          <?= View::status((string) $j['status']) ?>
          <span class="muted"><?= View::e(substr((string) $j['created_at'], 0, 16)) ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
