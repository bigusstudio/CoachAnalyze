<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Ustawienie nowego hasła z odnośnika mailowego.
 *
 * @var string      $csrf
 * @var string      $token
 * @var string      $email     adres konta — dla menedżera haseł
 * @var int         $minLength
 * @var string|null $error
 */
?>
<main class="auth">
  <form class="auth__box" method="post" action="/haslo/reset/<?= View::e($token) ?>" autocomplete="on">
    <h1 class="auth__title"><?= View::e(View::t('app.name')) ?></h1>
    <p class="auth__subtitle"><?= View::e(View::t('reset.new.title')) ?></p>

    <?php if (!empty($error)): ?>
      <p class="alert" role="alert"><?= View::e($error) ?></p>
    <?php endif; ?>

    <?php /*
      Adres konta obecny w drzewie dokumentu, ukryty klasą — bez niego
      menedżer haseł nie wie, do którego wpisu przypisać nowe hasło
      (ten sam zabieg co na ekranie konta, patrz account.php).
    */ ?>
    <input class="sr-only" type="email" name="konto" value="<?= View::e($email) ?>"
           autocomplete="username" readonly tabindex="-1" aria-hidden="true">

    <label class="field" for="pole-nowe">
      <span class="field__label"><?= View::e(View::t('reset.new.password')) ?></span>
      <input class="field__input" id="pole-nowe" type="password" name="nowe" required autofocus
             minlength="<?= (int) $minLength ?>" autocomplete="new-password">
    </label>
    <p class="hint"><?= View::e(View::t('reset.new.hint', (int) $minLength)) ?></p>

    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <button class="btn" type="submit"><?= View::e(View::t('reset.new.submit')) ?></button>
  </form>
</main>
