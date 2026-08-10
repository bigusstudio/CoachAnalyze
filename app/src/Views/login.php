<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Ekran logowania.
 *
 * @var string|null $error   gotowy komunikat po polsku
 * @var string|null $notice
 * @var string      $csrf
 * @var string      $email   wpisany adres — nie każemy wpisywać go drugi raz
 */
?>
<main class="auth">
  <form class="auth__box" method="post" action="/login" autocomplete="on">
    <h1 class="auth__title"><?= View::e(View::t('app.name')) ?></h1>
    <p class="auth__subtitle"><?= View::e(View::t('app.tagline')) ?></p>

    <?php if (!empty($notice)): ?>
      <p class="notice" role="status"><?= View::e($notice) ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <p class="alert" role="alert"><?= View::e($error) ?></p>
    <?php endif; ?>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('login.email')) ?></span>
      <input class="field__input" type="email" name="email" required autofocus
             autocomplete="username" value="<?= View::e($email ?? '') ?>">
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('login.password')) ?></span>
      <input class="field__input" type="password" name="password" required
             autocomplete="current-password">
    </label>

    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <button class="btn" type="submit"><?= View::e(View::t('login.submit')) ?></button>
  </form>
</main>
