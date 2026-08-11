<?php
declare(strict_types=1);

use CoachAnalyze\Jobs;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Podgląd zadania z kolejki: stan, czasy, kod wyjścia, treść błędu.
 *
 * @var array<string,mixed>      $job
 * @var array<string,mixed>|null $report
 * @var string|null              $notice
 * @var string|null              $error
 */
$canRetry = Jobs::canRetry((string) $job['status']);
?>
<h1 class="h1"><?= View::e(View::t('job.title', (int) $job['id'])) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <dl class="facts">
    <dt><?= View::e(View::t('job.type')) ?></dt>
    <dd><?= View::e((string) $job['type']) ?></dd>

    <dt><?= View::e(View::t('job.status')) ?></dt>
    <dd><?= View::status((string) $job['status']) ?></dd>

    <dt><?= View::e(View::t('job.attempts')) ?></dt>
    <dd><?= (int) $job['attempts'] ?></dd>

    <dt><?= View::e(View::t('job.started')) ?></dt>
    <dd><?= $job['started_at'] !== null
            ? View::e((string) $job['started_at'])
            : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></dd>

    <dt><?= View::e(View::t('job.finished')) ?></dt>
    <dd><?= $job['finished_at'] !== null
            ? View::e((string) $job['finished_at'])
            : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></dd>

    <dt><?= View::e(View::t('job.exit_code')) ?></dt>
    <dd><?= $job['exit_code'] !== null
            ? View::e((string) $job['exit_code'])
            : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></dd>
  </dl>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('job.error')) ?></h2>
  <?php if (!empty($job['error_text'])): ?>
    <?php // Traceback silnika jest tekstem, nie HTML-em — do przeglądarki trafia
          // wyłącznie po ucieczce (CLAUDE.md §5). ?>
    <pre class="pre"><?= View::e((string) $job['error_text']) ?></pre>
  <?php else: ?>
    <p class="empty"><?= View::e(View::t('job.no_error')) ?></p>
  <?php endif; ?>
</section>

<div class="actions">
  <?php if ($canRetry): ?>
    <form method="post" action="/zadania/<?= (int) $job['id'] ?>/ponow">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <button class="btn" type="submit"><?= View::e(View::t('job.retry')) ?></button>
    </form>
  <?php endif; ?>

  <?php if ($report !== null): ?>
    <a class="btn btn--ghost" href="/raport/<?= (int) $report['id'] ?>">
      <?= View::e(View::t('job.report')) ?>
    </a>
    <a class="btn btn--ghost" href="/raport/<?= (int) $report['id'] ?>/udostepnij">
      <?= View::e(View::t('share.create')) ?>
    </a>
    <span class="hint"><?= View::e(View::t(
        'job.report.meta',
        substr((string) $report['generated_at'], 0, 16),
        (string) $report['engine_version']
    )) ?></span>
  <?php endif; ?>

  <a class="link" href="/"><?= View::e(View::t('common.back')) ?></a>
</div>
