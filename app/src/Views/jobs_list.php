<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * [op] ZADANIA KOLEJKI — ostatnie sto (golden layout W2, szyna ADMINISTRACJA).
 *
 * @var list<array<string,mixed>> $jobs
 */
?>
<h1 class="h1"><?= View::e(View::t('nav.jobs')) ?></h1>
<section class="panel">
  <?php if ($jobs === []): ?>
    <p class="empty"><?= View::e(View::t('card.zadania.empty')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead><tr>
          <th><?= View::e(View::t('card.zadania.col.id')) ?></th>
          <th><?= View::e(View::t('card.zadania.col.type')) ?></th>
          <th><?= View::e(View::t('card.zadania.col.status')) ?></th>
          <th><?= View::e(View::t('card.zadania.col.date')) ?></th>
          <th><?= View::e(View::t('jobs.col.match')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
          <?php $mecz = \CoachAnalyze\Jobs::meczZadania($j); ?>
          <tr>
            <td><a class="link" href="/zadania/<?= (int) $j['id'] ?>">#<?= (int) $j['id'] ?></a></td>
            <td><?= View::e((string) $j['type']) ?></td>
            <td><?= View::status((string) $j['status']) ?></td>
            <td><?= View::e(substr((string) ($j['created_at'] ?? ''), 0, 16)) ?></td>
            <td><?= $mecz !== null ? '<a class="link" href="/mecze/' . $mecz . '">' . View::e(View::t('card.title')) . ' #' . $mecz . '</a>' : View::e(View::t('common.dash')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
