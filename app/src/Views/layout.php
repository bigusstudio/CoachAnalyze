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

// Licznik nieodczytanych liczony TUTAJ, a nie przekazywany z każdej trasy.
// Nagłówek jest na każdej stronie panelu; przekazywanie licznika z dwudziestu
// miejsc kończy się tym, że w którymś go zabraknie i licznik zniknie na jednym
// ekranie bez żadnego powodu. `unreadCount()` łyka własne błędy i zwraca 0.
$nieodczytane = $chrome && Session::userId() !== null
    ? \CoachAnalyze\Notifications::unreadCount((int) Session::userId())
    : 0;

// Chmurki renderowane PO STRONIE SERWERA. To jest wariant podstawowy, nie
// zapasowy: przy wyłączonym JavaScripcie powiadomienia i tak się pokazują,
// tylko przy przeładowaniu strony zamiast natychmiast. Skrypt je PRZYSPIESZA,
// nie warunkuje ich istnienia.
$chmurki = $chrome && Session::userId() !== null
    ? \CoachAnalyze\Notifications::unreadForToasts((int) Session::userId())
    : [];
?>
<!doctype html>
<html lang="pl"<?= $theme !== null ? ' data-theme="' . View::e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php if (!empty($refresh)): ?>
<?php // Odświeżanie statusu bez JavaScriptu — panel nie ma ani jednego skryptu
      // i nie ma powodu, żeby dokładać go dla licznika sekund. ?>
<meta http-equiv="refresh" content="<?= (int) $refresh ?>">
<?php endif; ?>
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

      <?php /*
        Licznik nieodczytanych. Zeruje sie po wejsciu na /powiadomienia.
        Gdy nie ma nic nowego, sama liczba znika, ale odnosnik zostaje —
        znikajaca pozycja nawigacji jest gorsza niz zero obok niej.
      */ ?>
      <a class="btn btn--ghost" href="/powiadomienia">
        <?= View::e(View::t('nav.notifications')) ?>
        <?php if ($nieodczytane > 0): ?>
          <span class="licznik" aria-label="<?= View::e(View::t('notif.unread', $nieodczytane)) ?>">
            <?= $nieodczytane > 99 ? '99+' : (int) $nieodczytane ?>
          </span>
        <?php endif; ?>
      </a>

      <a class="btn btn--ghost" href="/konto"><?= View::e(View::t('account.title')) ?></a>
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
    <a class="side__item<?= $active === 'reports' ? ' is-active' : '' ?>" href="/raporty">
      <?= View::e(View::t('nav.reports')) ?>
    </a>
    <a class="side__item<?= $active === 'import' ? ' is-active' : '' ?>" href="/import">
      <?= View::e(View::t('import.nav')) ?>
    </a>
    <a class="side__item<?= $active === 'seasons' ? ' is-active' : '' ?>" href="/sezony">
      <?= View::e(View::t('season.list')) ?>
    </a>
    <a class="side__item<?= $active === 'links' ? ' is-active' : '' ?>" href="/linki">
      <?= View::e(View::t('share.nav')) ?>
    </a>
    <a class="side__item<?= $active === 'clubs' ? ' is-active' : '' ?>" href="/kluby">
      <?= View::e(View::t('nav.clubs')) ?>
    </a>
    <a class="side__item<?= $active === 'index' ? ' is-active' : '' ?>" href="/indeks">
      <?= View::e(View::t('nav.index')) ?>
    </a>
    <a class="side__item<?= $active === 'xg' ? ' is-active' : '' ?>" href="/xg">
      <?= View::e(View::t('nav.xg')) ?>
    </a>
    <a class="side__item<?= $active === 'notes' ? ' is-active' : '' ?>" href="/notatki">
      <?= View::e(View::t('nav.notes')) ?>
    </a>
    <?php /*
      Pozycja widoczna WYŁĄCZNIE dla administratora. To wygoda, nie ochrona —
      o dostępie rozstrzyga `requireCan()` w trasie (app/public/index.php).
    */ ?>
    <?php if (\CoachAnalyze\Users::isAdmin(\CoachAnalyze\Auth::currentUser())): ?>
      <a class="side__item<?= $active === 'users' ? ' is-active' : '' ?>" href="/uzytkownicy">
        <?= View::e(View::t('nav.users')) ?>
      </a>
    <?php endif; ?>
  </nav>

  <main class="main">
    <?= $content ?>
  </main>

  <footer class="foot">
    <?= View::e(View::t('common.engine', Engine::version())) ?>
  </footer>

  <?php /*
    Obszar chmurek. `aria-live="polite"` sprawia, że czytnik ekranu przeczyta
    nowe powiadomienie, nie przerywając bieżącej wypowiedzi.

    `data-csrf` to DANE, nie kod: skrypt potrzebuje tokenu, żeby zamknięcie
    chmurki przeszło tę samą kontrolę CSRF co formularz. Serwer nie oddaje
    tu żadnego HTML-a do wstrzyknięcia.
  */ ?>
  <div class="chmurki"
       id="chmurki"
       role="status"
       aria-live="polite"
       data-csrf="<?= View::e(Session::csrfToken()) ?>"
       data-powrot="<?= View::e($_SERVER['REQUEST_URI'] ?? '/') ?>"
       <?php /* Teksty przekazujemy z pl.php — skrypt ich nie zawiera,
                bo wersja anglojęzyczna nie ma wymagać ruszania kodu. */ ?>
       data-tekst-otworz="<?= View::e(View::t('toast.open')) ?>"
       data-tekst-zamknij="<?= View::e(View::t('toast.close')) ?>"
       data-tekst-licznik="<?= View::e(View::t('notif.unread', 0)) ?>">
    <?php foreach ($chmurki as $ch): ?>
      <?php $rodzaj = \CoachAnalyze\Notifications::kind((string) $ch['type']); ?>
      <div class="chmurka chmurka--<?= View::e($rodzaj) ?>" data-id="<?= (int) $ch['id'] ?>">
        <div class="chmurka__tresc">
          <p class="chmurka__tytul"><?= View::e((string) $ch['title']) ?></p>
          <?php if (!empty($ch['url'])): ?>
            <a class="chmurka__link" href="<?= View::e((string) $ch['url']) ?>">
              <?= View::e(View::t('toast.open')) ?>
            </a>
          <?php endif; ?>
        </div>

        <?php /* Bez skryptu to zwykły formularz — pełnoprawne zamknięcie. */ ?>
        <form class="chmurka__zamknij" method="post"
              action="/powiadomienia/<?= (int) $ch['id'] ?>/odczytane">
          <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
          <input type="hidden" name="powrot" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '/') ?>">
          <button type="submit" aria-label="<?= View::e(View::t('toast.close')) ?>">×</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php /*
  ODSTĘPSTWO OD ZASADY „ZERO SKRYPTÓW" — zatwierdzone, dotyczy WYŁĄCZNIE
  powiadomień. Raport pozostaje samowystarczalnym HTML-em bez ani jednego
  skryptu; ta zasada nie została naruszona (CLAUDE.md §8).

  `defer` i miejsce na końcu dokumentu: skrypt nie blokuje renderowania,
  a gdy się nie wczyta, panel działa dalej — chmurki są już w HTML-u.
*/ ?>
<script src="/assets/powiadomienia.js" defer></script>
<?php endif; ?>
</body>
</html>
