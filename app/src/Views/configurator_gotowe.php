<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Podsumowanie po zapisie templatu + CTA przykładowego raportu (Sesja 4 pkt 6).
 *
 * @var array<string,mixed> $club
 * @var int $wersja
 * @var array{variables:int,canon:int,custom:int,sections:int,hidden:int} $podsumowanie
 * @var array<string,mixed>|null $import
 */
?>
<h1 class="h1"><?= View::e(View::t('conf.done.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('conf.done.saved', $wersja)) ?></h2>
  <dl class="facts facts--wide">
    <dt><?= View::e(View::t('conf.done.variables')) ?></dt>
    <dd><?= (int) $podsumowanie['variables'] ?></dd>
    <dt><?= View::e(View::t('conf.done.canon')) ?></dt>
    <dd><?= (int) $podsumowanie['canon'] ?></dd>
    <dt><?= View::e(View::t('conf.done.custom')) ?></dt>
    <dd><?= (int) $podsumowanie['custom'] ?></dd>
    <dt><?= View::e(View::t('conf.done.sections')) ?></dt>
    <dd><?= (int) $podsumowanie['sections'] ?></dd>
  </dl>
</section>

<?php /*
  UCZCIWA NOTA — bez niej operator ustawia etykiety i barwy, po czym nie widzi
  ich w raporcie i ma prawo uznać to za usterkę. Szablon raportu ma nazwy tagów
  i etykiety wpisane na sztywno w JS; sterowanie nim z templatu wymaga jego
  przepisania (S5b w docs/PRZEBUDOWA_KLUB_SESJE.md) i decyzji o przebazowaniu
  wzorca. Mówimy o tym wprost, zamiast pozwolić na cichy rozjazd.
*/ ?>
<section class="panel panel--uwaga">
  <h2 class="h2"><?= View::e(View::t('conf.done.partial.title')) ?></h2>
  <p><?= View::e(View::t('conf.done.partial.body')) ?></p>
  <p class="hint"><?= View::e(View::t('conf.done.partial.works')) ?></p>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('conf.sample.title')) ?></h2>
  <?php if ($import === null): ?>
    <p class="empty"><?= View::e(View::t('conf.sample.no_import')) ?></p>
  <?php else: ?>
    <p class="hint"><?= View::e(View::t('conf.sample.hint')) ?></p>
    <form method="post" action="/klub/<?= (int) $club['id'] ?>/konfigurator/przyklad">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <button class="btn" type="submit"><?= View::e(View::t('conf.sample.submit')) ?></button>
    </form>
  <?php endif; ?>
</section>

<div class="actions">
  <a class="link" href="/klub/<?= (int) $club['id'] ?>/templaty"><?= View::e(View::t('tpl.history.link')) ?></a>
  <a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
</div>
