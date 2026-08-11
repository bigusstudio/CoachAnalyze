<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Potwierdzenie usunięcia klubu. Osobny krok po stronie serwera — bez JavaScriptu.
 *
 * @var array<string,mixed> $club
 */
?>
<h1 class="h1"><?= View::e(View::t('club.delete')) ?></h1>

<section class="panel">
  <p><?= View::e(View::t('club.delete.confirm', (string) $club['name'])) ?></p>
  <p class="hint"><?= View::e(View::t('club.delete.hint')) ?></p>

  <div class="actions">
    <form method="post" action="/kluby/<?= (int) $club['id'] ?>/usun">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <input type="hidden" name="potwierdz" value="1">
      <button class="btn" type="submit"><?= View::e(View::t('club.delete.yes')) ?></button>
    </form>
    <a class="link" href="/kluby/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
  </div>
</section>
