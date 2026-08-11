<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Wszystkie aktywne linki publiczne — przegląd i szybkie odwołanie.
 *
 * @var list<array<string,mixed>> $links
 * @var string|null $appUrl
 * @var string|null $notice
 */
?>
<h1 class="h1"><?= View::e(View::t('share.active')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<section class="panel">
  <?php if ($links === []): ?>
    <p class="empty"><?= View::e(View::t('share.empty_active')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= View::e(View::t('share.club')) ?></th>
          <th><?= View::e(View::t('share.address')) ?></th>
          <th><?= View::e(View::t('share.views')) ?></th>
          <th><?= View::e(View::t('share.last_view')) ?></th>
          <th><?= View::e(View::t('match.action')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($links as $l): ?>
        <tr>
          <td><?= View::e((string) $l['club_name']) ?></td>
          <td><code class="adres"><?= View::e(rtrim((string) $appUrl, '/') . $l['url']) ?></code></td>
          <td><?= (int) $l['views'] ?></td>
          <td><?= !empty($l['last_viewed_at'])
              ? View::e(substr((string) $l['last_viewed_at'], 0, 16))
              : '<span class="muted">' . View::e(View::t('share.never')) . '</span>' ?></td>
          <td class="akcje">
            <a class="link" href="/raport/<?= (int) $l['report_id'] ?>/udostepnij">
              <?= View::e(View::t('share.manage')) ?>
            </a>
            <form method="post" action="/link/<?= (int) $l['id'] ?>/odwolaj">
              <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
              <button class="link link--btn" type="submit"><?= View::e(View::t('share.revoke')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
