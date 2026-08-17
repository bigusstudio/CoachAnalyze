<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Prośba o reset hasła.
 *
 * Komunikat po wysłaniu jest ten sam dla adresu istniejącego
 * i nieistniejącego — formularz nie może służyć do sondowania listy kont.
 *
 * @var string      $csrf
 * @var string|null $notice
 * @var string|null $error
 * @var string|null $warning przeszkoda znana przed wysłaniem formularza
 */
?>
<main class="auth">
  <form class="auth__box" method="post" action="/haslo/zapomniane" autocomplete="on">
    <h1 class="auth__title"><?= View::e(View::t('app.name')) ?></h1>
    <p class="auth__subtitle"><?= View::e(View::t('reset.title')) ?></p>

    <?php if (!empty($notice)): ?>
      <p class="notice" role="status"><?= View::e($notice) ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <p class="alert" role="alert"><?= View::e($error) ?></p>
    <?php endif; ?>

    <?php if (!empty($warning)): ?>
      <p class="alert" role="alert"><?= View::e($warning) ?></p>
    <?php endif; ?>

    <p class="hint"><?= View::e(View::t('reset.hint')) ?></p>

    <label class="field" for="pole-email">
      <span class="field__label"><?= View::e(View::t('login.email')) ?></span>
      <input class="field__input" id="pole-email" type="email" name="email" required autofocus
             autocomplete="username">
    </label>

    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <button class="btn" type="submit"><?= View::e(View::t('reset.submit')) ?></button>

    <p class="hint"><a class="link" href="/login"><?= View::e(View::t('reset.back_to_login')) ?></a></p>
  </form>
</main>
