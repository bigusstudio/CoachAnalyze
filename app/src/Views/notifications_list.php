<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Powiadomienia użytkownika.
 *
 * Wejście na ten ekran zeruje licznik nieodczytanych, ale wiersze, które BYŁY
 * nieodczytane, zostają tu wyróżnione — inaczej otwarcie listy gubiłoby jedyną
 * informację o tym, co jest nowe.
 *
 * @var list<array<string,mixed>> $rows
 */
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('notif.title')) ?></h1>
  <a class="link" href="/konto"><?= View::e(View::t('notif.settings')) ?></a>
</div>

<?php if ($rows === []): ?>
  <p class="empty"><?= View::e(View::t('notif.empty')) ?></p>
<?php else: ?>
  <section class="panel">
    <table class="tbl">
      <caption class="sr-only"><?= View::e(View::t('notif.title')) ?></caption>
      <thead>
        <tr>
          <th scope="col"><?= View::e(View::t('notif.col.when')) ?></th>
          <th scope="col"><?= View::e(View::t('notif.col.what')) ?></th>
          <th scope="col"><?= View::e(View::t('notif.col.mail')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $n): ?>
          <tr class="<?= $n['read_at'] === null ? 'wiersz--nowy' : '' ?>">
            <td><?= View::e(substr((string) $n['created_at'], 0, 16)) ?></td>

            <td>
              <?php if (!empty($n['url'])): ?>
                <a class="link" href="<?= View::e((string) $n['url']) ?>">
                  <?= View::e((string) $n['title']) ?>
                </a>
              <?php else: ?>
                <?= View::e((string) $n['title']) ?>
              <?php endif; ?>

              <?php if ($n['read_at'] === null): ?>
                <span class="tag tag--new"><?= View::e(View::t('notif.new')) ?></span>
              <?php endif; ?>

              <?php if (!empty($n['body'])): ?>
                <?php /* Treść jest tekstem — do przeglądarki wyłącznie po ucieczce. */ ?>
                <p class="hint hint--block"><?= nl2br(View::e((string) $n['body'])) ?></p>
              <?php endif; ?>
            </td>

            <td>
              <?php /*
                Stan wysyłki mówimy wprost, także wtedy, gdy maila nie było.
                „—" bez wyjaśnienia kazałoby się domyślać, czy poczta nie działa,
                czy po prostu nie jest skonfigurowana.
              */ ?>
              <span class="tag tag--mail-<?= View::e((string) $n['mail_status']) ?>">
                <?= View::e(View::t('notif.mail.' . (string) $n['mail_status'])) ?>
              </span>

              <?php if ((string) $n['mail_status'] === 'failed' && !empty($n['mail_error'])): ?>
                <p class="hint hint--block"><?= View::e((string) $n['mail_error']) ?></p>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
