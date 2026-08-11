<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Linki publiczne do raportu: tworzenie, lista, odwoływanie.
 *
 * @var array<string,mixed> $report
 * @var list<array<string,mixed>> $links
 * @var string|null $appUrl
 * @var string|null $notice
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('share.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <p class="hint"><?= View::e(View::t(
      'share.report_meta',
      substr((string) $report['generated_at'], 0, 16),
      (string) $report['engine_version']
  )) ?></p>

  <form method="post" action="/raport/<?= (int) $report['id'] ?>/udostepnij">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <label class="field">
      <span class="field__label"><?= View::e(View::t('share.expires')) ?></span>
      <input class="field__input" type="date" name="expires_at">
      <span class="hint"><?= View::e(View::t('share.expires.hint')) ?></span>
    </label>
    <button class="btn" type="submit"><?= View::e(View::t('share.create')) ?></button>
  </form>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('share.links')) ?></h2>

  <?php if ($links === []): ?>
    <p class="empty"><?= View::e(View::t('share.empty')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= View::e(View::t('share.address')) ?></th>
          <th><?= View::e(View::t('share.state')) ?></th>
          <th><?= View::e(View::t('share.views')) ?></th>
          <th><?= View::e(View::t('share.last_view')) ?></th>
          <th><?= View::e(View::t('match.action')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($links as $l): ?>
        <tr>
          <td>
            <?php // Pełny adres do skopiowania — klient wysyła go prezesowi. ?>
            <code class="adres"><?= View::e(rtrim((string) $appUrl, '/') . $l['url']) ?></code>
          </td>
          <td>
            <?php $klasa = ['active' => 'done', 'expired' => 'queued', 'revoked' => 'failed'][$l['stan']]; ?>
            <span class="tag tag--<?= View::e($klasa) ?>">
              <?= View::e(View::t('share.state.' . $l['stan'])) ?>
            </span>
            <?php if (!empty($l['expires_at'])): ?>
              <span class="hint"><?= View::e(View::t('share.until', substr((string) $l['expires_at'], 0, 10))) ?></span>
            <?php endif; ?>
          </td>
          <td><?= (int) $l['views'] ?></td>
          <td><?= !empty($l['last_viewed_at'])
              ? View::e(substr((string) $l['last_viewed_at'], 0, 16))
              : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></td>
          <td>
            <?php if ($l['stan'] === 'active'): ?>
              <form method="post" action="/link/<?= (int) $l['id'] ?>/odwolaj">
                <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <button class="link link--btn" type="submit"><?= View::e(View::t('share.revoke')) ?></button>
              </form>
            <?php else: ?>
              <span class="muted"><?= View::e(View::t('common.dash')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<p><a class="link" href="/mecze"><?= View::e(View::t('common.back')) ?></a></p>
