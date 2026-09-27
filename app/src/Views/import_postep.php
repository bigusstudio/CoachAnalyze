<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * POSTĘP — krok 2 (golden layout W3): „czytam plik → buduję raport".
 *
 * Nagłówek meczu, wskaźnik pracy (ten sam komponent co wszędzie — etapy, nie
 * procenty) i automatyczne przejście do raportu: skryptem, gdy wskaźnik zobaczy
 * „Gotowe", a bez skryptu — `<meta refresh>` + przekierowanie po stronie serwera.
 * Mecz jest od razu na liście sezonu jako „w przygotowaniu".
 *
 * @var array<string,mixed>      $mecz
 * @var array<string,mixed>|null $zadanie
 */
$czlony = array_filter([
    ($mecz['round'] ?? '') !== '' ? View::t('card.round', (string) $mecz['round']) : null,
    substr((string) ($mecz['played_at'] ?? ''), 0, 10) ?: null,
]);
?>
<ol class="kroki" aria-label="<?= View::e(View::t('import.steps')) ?>">
  <li class="kroki__k kroki__k--done"><span>1</span><?= View::e(View::t('import.step.upload')) ?></li>
  <li class="kroki__k kroki__k--on"><span>2</span><?= View::e(View::t('import.step.report')) ?></li>
</ol>

<p class="karta-meczu__meta"><?= View::e(implode(' · ', $czlony)) ?></p>
<h1 class="h1">
  <?= View::e((string) ($mecz['home_name'] ?? View::t('match.no_club'))) ?>
  – <?= View::e((string) ($mecz['away_name'] ?? View::t('match.no_club'))) ?>
</h1>

<ol class="postep">
  <li class="postep__k postep__k--done"><?= View::e(View::t('import.progress.read')) ?></li>
  <li class="postep__k postep__k--on"><?= View::e(View::t('import.progress.build')) ?></li>
</ol>

<?php if ($zadanie !== null): ?>
  <?= View::render('wskaznik', ['job' => $zadanie, 'resultUrl' => \CoachAnalyze\Jobs::resultUrl($zadanie)]) ?>
<?php endif; ?>
<p class="hint"><?= View::e(View::t('import.go.hint')) ?></p>

<p><a class="btn s" href="/pulpit"><?= View::e(View::t('import.progress.back')) ?></a></p>
