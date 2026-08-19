<?php
declare(strict_types=1);

use CoachAnalyze\ReportTemplates;
use CoachAnalyze\View;

/**
 * Hub klubu (Sesja 2) — punkt wejścia do wszystkiego, co dotyczy jednego
 * tenanta: status templatu, ostatnie mecze i raporty, skróty do bibliotek
 * klubu i do importu.
 *
 * @var array<string,mixed>       $club
 * @var array<string,mixed>|null  $reportTemplate  UWAGA: NIE „$template" — ta
 *      nazwa jest zajęta przez `View::render()` (parametr = nazwa pliku
 *      szablonu), a `extract(…, EXTR_SKIP)` nie nadpisuje istniejących
 *      zmiennych. Klucz danych „template" ginie po cichu pod tym parametrem.
 * @var list<array<string,mixed>> $matches   już posortowane malejąco po dacie
 * @var int $matchesTotal
 * @var list<array<string,mixed>> $reports   już posortowane malejąco po dacie
 * @var int $reportsTotal
 * @var string|null $notice
 * @var string|null $error
 */
$szczegoly = \CoachAnalyze\Clubs::decodeDetails($club['details'] ?? null);
?>
<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<div class="hub__banner">
  <?php if (!empty($club['crest_path'])): ?>
    <img class="hub__crest" src="/herb/<?= (int) $club['id'] ?>" alt="">
  <?php else: ?>
    <span class="crest crest--empty hub__crest" aria-hidden="true"></span>
  <?php endif; ?>

  <div class="hub__meta">
    <h1 class="hub__name"><?= View::e((string) $club['name']) ?></h1>
    <span class="hub__sub">
      <?php if (!empty($szczegoly['miasto']) || !empty($szczegoly['liga'])): ?>
        <?= View::e(trim(implode(' · ', array_filter([
            $szczegoly['miasto'] ?? null,
            $szczegoly['liga'] ?? null,
        ])))) ?>
        ·
      <?php endif; ?>
      <?php if ($reportTemplate === null): ?>
        <?= View::e(View::t('club.hub.no_template')) ?>
      <?php else: ?>
        <?= View::e(View::t(
            'club.hub.template',
            (int) $reportTemplate['version'],
            substr((string) $reportTemplate['created_at'], 0, 10)
        )) ?>
      <?php endif; ?>
    </span>
  </div>

  <div class="hub__cta">
    <a class="btn" href="/klub/<?= (int) $club['id'] ?>/konfigurator">
      <?= View::e(View::t($reportTemplate === null ? 'club.hub.configure' : 'club.hub.reconfigure')) ?>
    </a>
    <a class="btn btn--ghost"
       href="/kluby/<?= (int) $club['id'] ?>?powrot=<?= rawurlencode('/klub/' . (int) $club['id']) ?>">
      <?= View::e(View::t('club.edit')) ?>
    </a>
  </div>
</div>

<div class="hub__grid">
  <a class="hub__tile" href="/klub/<?= (int) $club['id'] ?>/mecze">
    <strong><?= View::e(View::t('nav.matches')) ?></strong>
    <span><?= View::e(View::t('club.hub.count_matches', $matchesTotal)) ?></span>
  </a>
  <a class="hub__tile" href="/klub/<?= (int) $club['id'] ?>/raporty">
    <strong><?= View::e(View::t('nav.reports')) ?></strong>
    <span><?= View::e(View::t('club.hub.count_reports', $reportsTotal)) ?></span>
  </a>
  <a class="hub__tile" href="/klub/<?= (int) $club['id'] ?>/notatki">
    <strong><?= View::e(View::t('nav.notes')) ?></strong>
    <span><?= View::e(View::t('club.hub.open_notes')) ?></span>
  </a>
  <a class="hub__tile" href="/klub/<?= (int) $club['id'] ?>/import">
    <strong><?= View::e(View::t('import.nav')) ?></strong>
    <span><?= View::e(View::t('club.hub.new_import')) ?></span>
  </a>
</div>

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
            <th><?= View::e(View::t('match.them')) ?></th>
            <th><?= View::e(View::t('match.status')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($matches, 0, 5) as $m): ?>
          <tr>
            <td>
              <?php if (!empty($m['played_at'])): ?>
                <?= View::e(substr((string) $m['played_at'], 0, 10)) ?>
              <?php else: ?>
                <span class="muted"><?= View::e(View::t('match.no_date')) ?></span>
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
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint"><a class="link" href="/klub/<?= (int) $club['id'] ?>/mecze"><?= View::e(View::t('club.hub.all_matches')) ?></a></p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('reports.title')) ?></h2>

    <?php if ($reports === []): ?>
      <p class="empty"><?= View::e(View::t('reports.empty')) ?></p>
    <?php else: ?>
      <ul class="jobs">
        <?php foreach (array_slice($reports, 0, 5) as $r): ?>
          <li class="jobs__row">
            <a class="link" href="/raport/<?= (int) $r['id'] ?>">
              <?= View::e(substr((string) $r['generated_at'], 0, 16)) ?>
            </a>
            <span class="jobs__type">
              <?= View::e(trim((string) ($r['home_name'] ?? '')) ?: View::t('common.unknown')) ?>
              —
              <?= View::e(trim((string) ($r['away_name'] ?? '')) ?: View::t('common.unknown')) ?>
            </span>
            <?= View::linkStatus((string) $r['link_stan']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="hint"><a class="link" href="/klub/<?= (int) $club['id'] ?>/raporty"><?= View::e(View::t('club.hub.all_reports')) ?></a></p>
    <?php endif; ?>
  </section>
</div>

<?php if ($reportTemplate !== null): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('mapping.club.title')) ?></h2>
    <p class="hint"><?= View::e(View::t('club.hub.template_hint', ReportTemplates::currentVersion((int) $club['id']))) ?></p>
    <a class="link" href="/kluby/<?= (int) $club['id'] ?>/mapowania"><?= View::e(View::t('mapping.club.link')) ?></a>
    <a class="link" href="/klub/<?= (int) $club['id'] ?>/templaty"><?= View::e(View::t('tpl.history.link')) ?></a>
  </section>
<?php endif; ?>
