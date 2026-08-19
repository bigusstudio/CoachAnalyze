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

<?php /*
  WSKAŹNIK PRACY — ten sam komponent, co przy konfiguratorze i przy
  przeliczaniu. Pokazuje etapy, czas od zgłoszenia i — po trzech minutach
  w kolejce — uczciwe „trwa dłużej niż zwykle".

  Renderuje go SERWER. Bez skryptu odświeża się przez `<meta refresh>`
  z layoutu; ze skryptem sam się aktualizuje i po „Gotowe" przechodzi do
  wyniku (CLAUDE.md §9 — skrypt przyspiesza, nie warunkuje).
*/ ?>
<?= View::render('wskaznik', [
    'job'       => $job,
    'resultUrl' => Jobs::resultUrl($job),
]) ?>

<?php if (in_array($job['status'], ['queued', 'running'], true)): ?>
  <?php /* Zdanie, którego wskaźnik nie powie: silnik chodzi z crona, więc
           raport powstanie niezależnie od tego, czy ktoś tu patrzy. */ ?>
  <p class="notice" role="status">
    <?= View::e(View::t($job['status'] === 'queued' ? 'job.waiting' : 'job.working')) ?>
    <span class="hint"><?= View::e(View::t('job.background')) ?></span>
  </p>
<?php elseif ($job['status'] === 'done'): ?>
  <?php /*
    Zakończone — i widać to od razu, bez szukania w tabeli faktów.
    Zgłoszenie z produkcji: zadanie było `done`, a strona nadal pokazywała
    generowanie. Właściwą przyczyną było buforowanie odpowiedzi przez
    przeglądarkę (naprawione nagłówkiem `Cache-Control: no-store`
    w app/src/bootstrap.php), ale ekran i tak nie mówił wprost, że jest gotowe:
    o powodzeniu trzeba było wnioskować z braku komunikatu o błędzie.
  */ ?>
  <p class="notice notice--ok" role="status">
    <?= View::e(View::t($report !== null ? 'job.done.report' : 'job.done')) ?>
    <?php if ($report !== null): ?>
      <a class="link" href="/raport/<?= (int) $report['id'] ?>"><?= View::e(View::t('job.done.open')) ?></a>
    <?php endif; ?>
  </p>
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
