<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Wybór klubu bieżącego (golden layout W2) — administrator i analityk z więcej
 * niż jednym klubem. Formularz POST z CSRF: wybór zmienia stan sesji.
 *
 * @var list<array<string,mixed>> $kluby
 * @var array<string,mixed>|null  $biezacy
 */
?>
<h1 class="h1"><?= View::e(View::t('pick.title')) ?></h1>
<section class="panel">
  <?php foreach ($kluby as $k): ?>
    <form class="inline" method="post" action="/klub/<?= (int) $k['id'] ?>/wybierz">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <button class="btn <?= (int) ($biezacy['id'] ?? 0) === (int) $k['id'] ? 'p' : 's' ?>" type="submit">
        <?= View::e((string) $k['name']) ?>
      </button>
    </form>
  <?php endforeach; ?>
</section>
