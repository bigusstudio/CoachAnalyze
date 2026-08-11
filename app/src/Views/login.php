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

    <?php // Etykieta wiązana przez `for`/`id`, nie samym zawinięciem. Zawinięcie
          // jest poprawne w HTML, ale menedżery haseł rozpoznają pola po jawnym
          // powiązaniu — bez niego Keychain na macOS nie podpowiada danych. ?>
    <label class="field" for="pole-email">
      <span class="field__label"><?= View::e(View::t('login.email')) ?></span>
      <input class="field__input" id="pole-email" type="email" name="email" required autofocus
             autocomplete="username" value="<?= View::e($email ?? '') ?>">
    </label>

    <label class="field" for="pole-haslo">
      <span class="field__label"><?= View::e(View::t('login.password')) ?></span>
      <input class="field__input" id="pole-haslo" type="password" name="password" required
             autocomplete="current-password">
    </label>

    <label class="field field--check">
      <input type="checkbox" name="zapamietaj" value="1">
      <span><?= View::e(View::t('login.remember')) ?></span>
    </label>
    <?php // Token trwały to osobne poświadczenie o słabszych uprawnieniach —
          // do zmiany hasła i tak trzeba będzie podać je ponownie. ?>
    <p class="hint"><?= View::e(View::t('login.remember.hint', \CoachAnalyze\Remember::DAYS)) ?></p>

    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <button class="btn" type="submit"><?= View::e(View::t('login.submit')) ?></button>
  </form>
</main>
