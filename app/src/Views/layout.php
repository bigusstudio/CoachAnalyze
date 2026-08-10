<?php
declare(strict_types=1);

use CoachAnalyze\Engine;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Szkielet panelu: nagłówek, nawigacja boczna, obszar treści, stopka.
 *
 * `data-theme` ustawiamy po stronie serwera z ciasteczka — przy pierwszym
 * renderze nie ma mignięcia jasnym tłem. Bez ciasteczka atrybutu nie ma wcale
 * i o motywie decyduje `prefers-color-scheme` w CSS.
 *
 * @var string      $content
 * @var string|null $title
 * @var string|null $active   identyfikator pozycji nawigacji
 * @var bool|null   $chrome   false = strona bez panelu (logowanie)
 */
$theme  = View::theme();
$chrome = $chrome ?? true;
$active = $active ?? '';

// Przełącznik prowadzi do motywu PRZECIWNEGO niż obecnie widoczny. Gdy wyboru
// jeszcze nie było, zakładamy jasny — taki jest domyślny w większości systemów,
// a jedno kliknięcie i tak zapisuje jawny wybór.
$next = $theme === 'dark' ? 'light' : 'dark';
?>
<!doctype html>
<html lang="pl"<?= $theme !== null ? ' data-theme="' . View::e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title ?? View::t('app.name')) ?> — <?= View::e(View::t('app.name')) ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?php if (!$chrome): ?>
<?= $content ?>
<?php else: ?>
<div class="shell">
  <header class="top">
    <a class="brand" href="/">
      <span class="brand__name"><?= View::e(View::t('app.name')) ?></span>
      <span class="brand__tag"><?= View::e(View::t('app.tagline')) ?></span>
    </a>

    <form class="top__actions" method="post" action="/motyw">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <input type="hidden" name="theme" value="<?= View::e($next) ?>">
      <input type="hidden" name="powrot" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '/') ?>">
      <button class="btn btn--ghost" type="submit"
              title="<?= View::e(View::t($next === 'dark' ? 'nav.theme.to_dark' : 'nav.theme.to_light')) ?>">
        <?= $next === 'dark' ? '◐' : '◑' ?>
        <span><?= View::e(View::t($next === 'dark' ? 'nav.theme.to_dark' : 'nav.theme.to_light')) ?></span>
      </button>

      <button class="btn btn--ghost" type="submit" formaction="/logout">
        <?= View::e(View::t('login.logout')) ?>
      </button>
    </form>
  </header>

  <nav class="side" aria-label="<?= View::e(View::t('nav.menu')) ?>">
    <a class="side__item<?= $active === 'dashboard' ? ' is-active' : '' ?>" href="/">
      <?= View::e(View::t('nav.dashboard')) ?>
    </a>
    <a class="side__item<?= $active === 'matches' ? ' is-active' : '' ?>" href="/mecze">
      <?= View::e(View::t('nav.matches')) ?>
    </a>
    <?php // Kluby i Notatki to jeszcze nie są trasy — pozycja nieaktywna mówi
          // wprost „będzie", zamiast prowadzić w stronę, której nie ma. ?>
    <span class="side__item is-disabled" aria-disabled="true">
      <?= View::e(View::t('nav.clubs')) ?>
      <em class="side__soon"><?= View::e(View::t('nav.soon_hint')) ?></em>
    </span>
    <span class="side__item is-disabled" aria-disabled="true">
      <?= View::e(View::t('nav.notes')) ?>
      <em class="side__soon"><?= View::e(View::t('nav.soon_hint')) ?></em>
    </span>
  </nav>

  <main class="main">
    <?= $content ?>
  </main>

  <footer class="foot">
    <?= View::e(View::t('common.engine', Engine::version())) ?>
  </footer>
</div>
<?php endif; ?>
</body>
</html>
