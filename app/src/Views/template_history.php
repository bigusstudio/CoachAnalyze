<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Historia wersji templatu — lista z datą, bez diffa (spec Sesji 4 pkt 5).
 *
 * Stare wersje ZOSTAJĄ w bazie, ale NIE SĄ renderowalne: regeneracja idzie
 * zawsze pod wersję najnowszą (Sesja 7). Numer służy do wykrycia, że raport
 * jest starszy niż templat — i temu właśnie służy ta lista.
 *
 * @var array<string,mixed> $club
 * @var list<array<string,mixed>> $wersje
 * @var int $biezaca
 */
?>
<h1 class="h1"><?= View::e(View::t('tpl.history.title')) ?></h1>

<?php if ($wersje === []): ?>
  <p class="empty"><?= View::e(View::t('tpl.history.empty')) ?></p>
<?php else: ?>
  <section class="panel">
    <p class="hint"><?= View::e(View::t('tpl.history.hint')) ?></p>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= View::e(View::t('tpl.history.version')) ?></th>
          <th><?= View::e(View::t('tpl.history.when')) ?></th>
          <th><?= View::e(View::t('tpl.history.who')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($wersje as $w): ?>
        <tr>
          <td>
            v<?= (int) $w['version'] ?>
            <?php if ((int) $w['version'] === $biezaca): ?>
              <span class="tag tag--done"><?= View::e(View::t('tpl.history.current')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= View::e(substr((string) $w['created_at'], 0, 16)) ?></td>
          <td>
            <?= $w['author'] !== null
                ? View::e((string) $w['author'])
                : '<span class="muted">' . View::e(View::t('tpl.history.system')) . '</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<p><a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a></p>
