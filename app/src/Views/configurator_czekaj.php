<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Import przyjęty, pokrycie jeszcze nie policzone.
 *
 * Warstwa żądań nie uruchamia silnika (`disable_functions` na PHP-FPM), więc
 * inspekcję podnosi cron — do minuty. Odświeżanie robi `<meta http-equiv>`
 * z layoutu, nie skrypt: panel nie ma ani jednego skryptu poza chmurkami
 * i licznik sekund nie jest powodem, żeby to zmienić.
 *
 * @var array<string,mixed>      $club
 * @var array<string,mixed>|null $job
 */
$status = $job !== null ? (string) $job['status'] : 'queued';
?>
<h1 class="h1"><?= View::e(View::t('conf.title')) ?></h1>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('conf.wait.title')) ?></h2>
  <p><?= View::e(View::t('conf.wait.body')) ?></p>

  <?php if ($job !== null): ?>
    <?php /*
      TEN SAM WSKAŹNIK, co na ekranie zadania i przy przeliczaniu — jeden
      komponent na wszystkie oczekiwania. `autoGo` wyłączone: po policzeniu
      pokrycia operator ma zostać w konfiguratorze i przejść do słownika,
      a nie zostać przerzucony na ekran importu.
    */ ?>
    <?= View::render('wskaznik', [
        'job'       => $job,
        'resultUrl' => null,
        'autoGo'    => false,
    ]) ?>
  <?php endif; ?>

  <dl class="facts">
    <dt><?= View::e(View::t('match.status')) ?></dt>
    <dd><?= View::status($status) ?></dd>
    <?php if ($job !== null): ?>
      <dt><?= View::e(View::t('conf.wait.job')) ?></dt>
      <dd><a class="link" href="/zadania/<?= (int) $job['id'] ?>">#<?= (int) $job['id'] ?></a></dd>
    <?php endif; ?>
  </dl>

  <?php if ($status === 'failed'): ?>
    <?php /*
      Inspekcja padła. Zostawiamy operatora z DECYZJĄ, a nie z pętlą
      odświeżania: powód stoi w podglądzie zadania, a stąd wolno porzucić
      stan roboczy i wgrać plik jeszcze raz.
    */ ?>
    <p class="alert" role="alert"><?= View::e(View::t('conf.wait.failed')) ?></p>
  <?php endif; ?>
</section>

<div class="actions">
  <form method="post" action="/klub/<?= (int) $club['id'] ?>/konfigurator/porzuc">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <button class="btn btn--ghost" type="submit"><?= View::e(View::t('conf.draft.discard')) ?></button>
  </form>
  <a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
</div>
