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
 * KLONOWANIE ZAMIAST COFANIA (Sesja 5 pivotu). Wersje są dopisywane, nigdy
 * nadpisywane, więc „wróć do układu sprzed trzech zmian" nie może być
 * cofnięciem: cofnięcie kasowałoby historię, a raporty klienta stoją na
 * konkretnych numerach wersji. Klon robi z dawnego configu NAJNOWSZY,
 * zostawiając wszystko, co było, na swoim miejscu.
 *
 * @var array<string,mixed> $club
 * @var list<array<string,mixed>> $wersje
 * @var int $biezaca
 * @var string $csrf
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
          <th></th>
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
          <td>
            <?php if ((int) $w['version'] !== $biezaca): ?>
              <form method="post"
                    action="/klub/<?= (int) $club['id'] ?>/templaty/<?= (int) $w['version'] ?>/klonuj">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <button class="btn s" type="submit"><?= View::e(View::t('tpl.clone.submit')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<p>
  <a class="link" href="/klub/<?= (int) $club['id'] ?>/uklad"><?= View::e(View::t('uklad.title')) ?></a>
  &middot;
  <a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
</p>
