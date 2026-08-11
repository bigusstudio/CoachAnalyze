<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Notatki w kontekście meczu i jego zdarzeń.
 *
 * PHP NIE DOTYKA HTML-a raportu (CLAUDE.md §4) — nie wstrzykujemy notatek do
 * wygenerowanego pliku. Zamiast tego pokazujemy je obok, pogrupowane po
 * `event_ref`, z odnośnikiem do raportu. Analityk ma jedno i drugie na ekranie,
 * a plik raportu zostaje bajtowo tym, co policzył silnik.
 *
 * @var array<string,mixed> $match
 * @var list<array<string,mixed>> $ogolne
 * @var array<string,list<array<string,mixed>>> $poZdarzeniu
 * @var array<string,mixed>|null $report
 */
?>
<h1 class="h1"><?= View::e(View::t('note.match_title')) ?></h1>

<section class="panel">
  <dl class="facts">
    <dt><?= View::e(View::t('match.date')) ?></dt>
    <dd><?= !empty($match['played_at'])
        ? View::e(substr((string) $match['played_at'], 0, 10))
        : '<span class="muted">' . View::e(View::t('match.no_date')) . '</span>' ?></dd>
    <dt><?= View::e(View::t('match.us')) ?></dt>
    <dd><?= View::e((string) ($match['home_name'] ?? View::t('match.no_club'))) ?></dd>
    <dt><?= View::e(View::t('match.them')) ?></dt>
    <dd><?= View::e((string) ($match['away_name'] ?? View::t('match.no_club'))) ?></dd>
  </dl>

  <?php if ($report !== null): ?>
    <div class="actions">
      <a class="btn btn--ghost" href="/raport/<?= (int) $report['id'] ?>">
        <?= View::e(View::t('job.report')) ?>
      </a>
      <a class="btn btn--ghost" href="/raport/<?= (int) $report['id'] ?>/udostepnij">
        <?= View::e(View::t('share.create')) ?>
      </a>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('note.general')) ?></h2>
  <?php if ($ogolne === []): ?>
    <p class="empty"><?= View::e(View::t('note.empty_match')) ?></p>
  <?php else: ?>
    <ul class="notatki">
      <?php foreach ($ogolne as $n): ?>
        <li class="notatka">
          <?php if (!empty($n['title'])): ?><strong><?= View::e((string) $n['title']) ?></strong><?php endif; ?>
          <div class="notatka__tresc"><?= nl2br(View::e((string) $n['body'])) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($poZdarzeniu !== []): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('note.in_report')) ?></h2>
    <p class="hint"><?= View::e(View::t('note.in_report.hint')) ?></p>

    <?php foreach ($poZdarzeniu as $ref => $lista): ?>
      <div class="zdarzenie">
        <code class="ref"><?= View::e((string) $ref) ?></code>
        <ul class="notatki">
          <?php foreach ($lista as $n): ?>
            <li class="notatka">
              <div class="notatka__tresc"><?= nl2br(View::e((string) $n['body'])) ?></div>
              <?php if ($n['tags'] !== []): ?>
                <div class="notatka__tagi">
                  <?php foreach ($n['tags'] as $tag): ?>
                    <span class="tag"><?= View::e((string) $tag) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<p><a class="link" href="/notatki"><?= View::e(View::t('note.title')) ?></a></p>
