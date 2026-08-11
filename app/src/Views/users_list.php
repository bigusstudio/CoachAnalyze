<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Konta użytkowników: lista, zakładanie, role, stan, reset hasła.
 *
 * @var list<array<string,mixed>> $users
 * @var list<string> $roles
 * @var int $me        identyfikator zalogowanego administratora
 * @var int $admins    ilu jest czynnych administratorów
 * @var string|null $freshPassword
 * @var string|null $freshFor
 * @var string|null $notice
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('users.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if (!empty($freshPassword)): ?>
  <?php /*
    HASŁO WIDOCZNE RAZ. Nie ma go w bazie (jest hash), nie ma w audycie, nie ma
    w adresie. Po odświeżeniu strony zniknie — i komunikat mówi to wprost,
    zamiast pozwolić odkryć to metodą prób.
  */ ?>
  <section class="panel panel--uwaga">
    <h2 class="h2"><?= View::e(View::t('users.password.heading')) ?></h2>
    <p><?= View::e(View::t('users.password.for', (string) $freshFor)) ?></p>
    <p class="haslo"><code><?= View::e((string) $freshPassword) ?></code></p>
    <p class="alert" role="alert"><?= View::e(View::t('users.password.once')) ?></p>
    <p class="hint"><?= View::e(View::t('users.password.must_change')) ?></p>
  </section>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('users.new')) ?></h2>

  <?php /*
    OSTRZEŻENIE O ZAKRESIE DOSTĘPU. To nie jest drobiazg do ukrycia w pomocy:
    zakładając konto trenerowi z innego klubu, administrator daje mu wgląd
    we WSZYSTKIE kluby. Musi o tym wiedzieć w chwili zakładania konta,
    nie po fakcie.
  */ ?>
  <p class="alert" role="alert"><?= View::e(View::t('users.scope_warning')) ?></p>

  <form method="post" action="/uzytkownicy">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

    <label class="field" for="email">
      <span class="field__label"><?= View::e(View::t('users.email')) ?></span>
      <input class="field__input" type="email" id="email" name="email"
             autocomplete="off" required>
    </label>

    <label class="field" for="display_name">
      <span class="field__label"><?= View::e(View::t('users.name')) ?></span>
      <input class="field__input" type="text" id="display_name" name="display_name"
             autocomplete="off" maxlength="120" required>
    </label>

    <label class="field" for="role">
      <span class="field__label"><?= View::e(View::t('users.role')) ?></span>
      <select class="field__input" id="role" name="role">
        <?php foreach ($roles as $r): ?>
          <option value="<?= View::e($r) ?>" <?= $r === 'operator' ? 'selected' : '' ?>>
            <?= View::e(View::t('users.role.' . $r)) ?> — <?= View::e(View::t('users.role.' . $r . '.hint')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php /* Hasła NIE WPISUJE administrator — generuje je system, z CSPRNG. */ ?>
    <p class="hint"><?= View::e(View::t('users.password.generated')) ?></p>

    <button class="btn" type="submit"><?= View::e(View::t('users.create')) ?></button>
  </form>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('users.list')) ?></h2>

  <table class="tbl">
    <thead>
      <tr>
        <th scope="col"><?= View::e(View::t('users.email')) ?></th>
        <th scope="col"><?= View::e(View::t('users.name')) ?></th>
        <th scope="col"><?= View::e(View::t('users.role')) ?></th>
        <th scope="col"><?= View::e(View::t('users.status')) ?></th>
        <th scope="col"><?= View::e(View::t('users.created')) ?></th>
        <th scope="col"><?= View::e(View::t('users.last_login')) ?></th>
        <th scope="col"><?= View::e(View::t('users.actions')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <?php
        $jaSam = (int) $u['id'] === $me;
        // Ostatni czynny administrator: nie da się go zdegradować ani wyłączyć.
        $ostatniAdmin = (string) $u['role'] === 'admin'
            && (string) $u['status'] === 'active'
            && $admins <= 1;
        ?>
        <tr class="<?= (string) $u['status'] === 'disabled' ? 'wiersz--wylaczony' : '' ?>">
          <td><?= View::e((string) $u['email']) ?></td>
          <td>
            <?= View::e((string) $u['display_name']) ?>
            <?php if ($jaSam): ?>
              <span class="tag"><?= View::e(View::t('users.you')) ?></span>
            <?php endif; ?>
            <?php if (!empty($u['must_change_password'])): ?>
              <span class="tag tag--req-new"><?= View::e(View::t('users.pending_change')) ?></span>
            <?php endif; ?>
          </td>

          <td>
            <?php /*
              Własnej roli nie da się zmienić — jedno nieuważne kliknięcie
              zostawiłoby system bez administratora, a odzyskanie dostępu
              wymagałoby wejścia do bazy przez SSH.
            */ ?>
            <?php if ($jaSam || $ostatniAdmin): ?>
              <?= View::e(View::t('users.role.' . (string) $u['role'])) ?>
              <span class="hint"><?= View::e(View::t(
                  $jaSam ? 'users.role.self_locked' : 'users.role.last_admin_locked'
              )) ?></span>
            <?php else: ?>
              <form class="inline" method="post" action="/uzytkownicy/<?= (int) $u['id'] ?>/rola">
                <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <select class="field__input field__input--slim" name="role">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= View::e($r) ?>" <?= $r === (string) $u['role'] ? 'selected' : '' ?>>
                      <?= View::e(View::t('users.role.' . $r)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="link" type="submit"><?= View::e(View::t('users.role.save')) ?></button>
              </form>
            <?php endif; ?>
          </td>

          <td>
            <span class="tag tag--status-<?= View::e((string) $u['status']) ?>">
              <?= View::e(View::t('users.status.' . (string) $u['status'])) ?>
            </span>
          </td>

          <td>
            <?= View::e(substr((string) $u['created_at'], 0, 10)) ?>
            <?php if (!empty($u['autor'])): ?>
              <span class="hint"><?= View::e(View::t('users.by', (string) $u['autor'])) ?></span>
            <?php endif; ?>
          </td>

          <td>
            <?= $u['last_login_at'] !== null
                ? View::e(substr((string) $u['last_login_at'], 0, 16))
                : '<span class="muted">' . View::e(View::t('users.never')) . '</span>' ?>
          </td>

          <td class="akcje">
            <?php /*
              Konta NIE KASUJEMY — jego identyfikator stoi w audycie i przy
              wersjach profili mapowań. Wyłączenie zabiera dostęp i zostawia
              historię.
            */ ?>
            <?php if ((string) $u['status'] === 'active'): ?>
              <?php if (!$jaSam && !$ostatniAdmin): ?>
                <form class="inline" method="post" action="/uzytkownicy/<?= (int) $u['id'] ?>/status">
                  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                  <input type="hidden" name="status" value="disabled">
                  <button class="link link--danger" type="submit">
                    <?= View::e(View::t('users.disable')) ?>
                  </button>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <form class="inline" method="post" action="/uzytkownicy/<?= (int) $u['id'] ?>/status">
                <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <input type="hidden" name="status" value="active">
                <button class="link" type="submit"><?= View::e(View::t('users.enable')) ?></button>
              </form>
            <?php endif; ?>

            <form class="inline" method="post" action="/uzytkownicy/<?= (int) $u['id'] ?>/haslo">
              <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
              <button class="link" type="submit"><?= View::e(View::t('users.reset')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
