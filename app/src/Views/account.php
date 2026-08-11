<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Konto operatora: zmiana hasła i lista zapamiętanych urządzeń.
 *
 * @var array<string,mixed> $user
 * @var list<array<string,mixed>> $devices
 * @var bool $fullAuth
 * @var string|null $notice
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('account.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <dl class="facts">
    <dt><?= View::e(View::t('login.email')) ?></dt>
    <dd><?= View::e((string) $user['email']) ?></dd>
    <dt><?= View::e(View::t('account.level')) ?></dt>
    <dd>
      <?php if ($fullAuth): ?>
        <span class="tag tag--done"><?= View::e(View::t('account.level.password')) ?></span>
      <?php else: ?>
        <span class="tag tag--queued"><?= View::e(View::t('account.level.remembered')) ?></span>
      <?php endif; ?>
    </dd>
  </dl>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('account.change_password')) ?></h2>

  <?php if (!$fullAuth): ?>
    <?php // Sesja odtworzona z ciasteczka trwałego NIE wystarcza do zmiany hasła.
          // Gdyby wystarczała, skradzione ciasteczko pozwoliłoby odciąć właściciela. ?>
    <p class="alert"><?= View::e(View::t('account.err.reauth')) ?></p>
    <p><a class="link" href="/login"><?= View::e(View::t('account.relogin')) ?></a></p>
  <?php else: ?>
    <form method="post" action="/konto/haslo">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

      <?php /*
        Pole z adresem konta, ukryte wizualnie, ale OBECNE w drzewie dokumentu.
        Bez niego Keychain i menedżery haseł nie wiedzą, do którego konta
        przypisać nowe hasło, i albo nie proponują zapisu, albo zapisują je
        pod nieokreślonym wpisem.

        Ukrywamy klasą, NIE atrybutem `hidden` ani `display:none` — pola
        całkowicie wyjęte z układu bywają przez menedżery pomijane.
        `readonly` i `tabindex="-1"` trzymają je poza kolejnością wprowadzania.
      */ ?>
      <input class="sr-only" type="email" name="konto" id="pole-konto"
             value="<?= View::e((string) $user['email']) ?>"
             autocomplete="username" readonly tabindex="-1" aria-hidden="true">

      <label class="field" for="pole-obecne">
        <span class="field__label"><?= View::e(View::t('account.current')) ?></span>
        <input class="field__input" id="pole-obecne" type="password" name="obecne" required
               autocomplete="current-password">
      </label>
      <label class="field" for="pole-nowe">
        <span class="field__label"><?= View::e(View::t('account.new')) ?></span>
        <input class="field__input" id="pole-nowe" type="password" name="nowe" required
               autocomplete="new-password">
      </label>
      <p class="hint"><?= View::e(View::t('account.new.hint', \CoachAnalyze\Auth::minPasswordLength())) ?></p>
      <button class="btn" type="submit"><?= View::e(View::t('account.change')) ?></button>
    </form>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('notif.prefs.title')) ?></h2>

  <?php /*
    Przelaczniki dotycza WYLACZNIE poczty. Powiadomienia w aplikacji zostaja
    zawsze — to one niosa historie zdarzen i licznik, a ich wylaczenie
    zostawiloby operatora bez sladu po tym, co sie dzialo w tle.
  */ ?>
  <p class="hint"><?= View::e(View::t('notif.prefs.hint')) ?></p>

  <?php if (!$mailReady): ?>
    <?php /*
      SMTP nieskonfigurowany: mowimy o tym wprost, zamiast pokazywac przelaczniki,
      ktore niczego nie zmienia. Warstwa mailowa wylacza sie po cichu w kodzie,
      ale nie ma powodu, zeby byla cicha wobec uzytkownika.
    */ ?>
    <p class="empty"><?= View::e(View::t('notif.prefs.no_smtp')) ?></p>
  <?php endif; ?>

  <form method="post" action="/konto/powiadomienia">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

    <?php foreach ($mailPrefs as $typ => $wlaczone): ?>
      <label class="check">
        <input type="checkbox" name="typy[]" value="<?= View::e($typ) ?>"
               <?= $wlaczone ? 'checked' : '' ?> <?= $mailReady ? '' : 'disabled' ?>>
        <span><?= View::e(View::t('notif.prefs.' . $typ)) ?></span>
      </label>
    <?php endforeach; ?>

    <button class="btn" type="submit" <?= $mailReady ? '' : 'disabled' ?>>
      <?= View::e(View::t('notif.prefs.save')) ?>
    </button>
  </form>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('device.title')) ?></h2>
  <p class="hint"><?= View::e(View::t('device.hint')) ?></p>

  <?php if ($devices === []): ?>
    <p class="empty"><?= View::e(View::t('device.empty')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= View::e(View::t('device.device')) ?></th>
          <th><?= View::e(View::t('device.last_used')) ?></th>
          <th><?= View::e(View::t('device.expires')) ?></th>
          <th><?= View::e(View::t('match.action')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td>
            <?= View::e((string) $d['label']) ?>
            <?php if (!empty($d['is_current'])): ?>
              <span class="tag tag--done"><?= View::e(View::t('device.this')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= !empty($d['last_used_at'])
              ? View::e(substr((string) $d['last_used_at'], 0, 16))
              : '<span class="muted">' . View::e(View::t('share.never')) . '</span>' ?></td>
          <td><?= View::e(substr((string) $d['expires_at'], 0, 10)) ?></td>
          <td>
            <form method="post" action="/konto/urzadzenie/<?= (int) $d['id'] ?>/wyloguj">
              <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
              <button class="link link--btn" type="submit"><?= View::e(View::t('device.forget')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="actions">
      <form method="post" action="/konto/wyloguj-wszedzie">
        <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <button class="btn" type="submit"><?= View::e(View::t('device.forget_all_btn')) ?></button>
      </form>
      <span class="hint"><?= View::e(View::t('device.forget_all.hint')) ?></span>
    </div>
  <?php endif; ?>
</section>
