<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * USTAWIENIA KLUBU (golden layout W2 — szkielet, W3 — pełny ekran).
 *
 * Poza hierarchią Pulpit → Sezon → Mecz. Tu trafia wszystko, co analityk
 * ustawia raz, a nie przy każdym meczu. [op] Zaawansowane — administrator.
 *
 * @var array<string,mixed> $club
 * @var bool $op
 */
$id = (int) $club['id'];
?>
<h1 class="h1"><?= View::e(View::t('nav.club_settings')) ?></h1>
<p class="hint"><?= View::e((string) $club['name']) ?></p>

<section class="panel">
  <ul class="ustawienia">
    <li><a class="link" href="/klub/<?= $id ?>/uklad"><?= View::e(View::t('settings.layout')) ?></a>
        <span class="hint"><?= View::e(View::t('settings.layout.hint')) ?></span></li>
    <li><a class="link" href="/klub/<?= $id ?>/konfigurator/slownik"><?= View::e(View::t('settings.dictionary')) ?></a>
        <span class="hint"><?= View::e(View::t('settings.dictionary.hint')) ?></span></li>
    <li><a class="link" href="/sezony"><?= View::e(View::t('settings.seasons')) ?></a>
        <span class="hint"><?= View::e(View::t('settings.seasons.hint')) ?></span></li>
  </ul>
</section>

<?php if ($op): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('settings.advanced')) ?></h2>
    <ul class="ustawienia">
      <li><a class="link" href="/kluby/<?= $id ?>/mapowania"><?= View::e(View::t('settings.mappings')) ?></a></li>
      <li><a class="link" href="/klub/<?= $id ?>/templaty"><?= View::e(View::t('settings.history')) ?></a></li>
      <li><a class="link" href="/kluby/<?= $id ?>"><?= View::e(View::t('settings.club_data')) ?></a></li>
    </ul>
  </section>
<?php endif; ?>
