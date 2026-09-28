<?php
declare(strict_types=1);

use CoachAnalyze\Engine;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Szkielet panelu (v2, sesja 3,5): ciemna szyna boczna, górny pasek, treść.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * MOTYW USTAWIANY W TRZECH MIEJSCACH I TO NIE JEST NADMIAROWOŚĆ.
 *
 * 1. `data-theme` z CIASTECZKA, po stronie serwera — działa bez JavaScriptu
 *    i jest jedyną drogą, gdy skrypty są wyłączone.
 * 2. Skrypt INLINE w `<head>` czyta `localStorage` i ustawia atrybut, ZANIM
 *    przeglądarka cokolwiek narysuje. Bez tego wybór zapamiętany w przeglądarce
 *    objawia się mignięciem jasnego tła przy każdym wejściu — a mignięcie przy
 *    każdej podstronie jest gorsze niż brak zapamiętywania.
 * 3. `prefers-color-scheme` w CSS, gdy nie ma ani ciasteczka, ani zapisu.
 *
 * Skrypt z punktu 2 jest inline i ma być inline: plik zewnętrzny wczytuje się
 * PO pierwszym malowaniu, czyli dokładnie za późno na to, do czego służy.
 * To nie jest drugi plik `.js` panelu (CLAUDE.md §9) — nie ma pliku.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var string      $content
 * @var string|null $title
 * @var string|null $active   identyfikator pozycji nawigacji
 * @var bool|null   $chrome   false = strona bez panelu (logowanie)
 * @var array<string,mixed>|null $club   kontekst klubu — WŁĄCZA scope theming
 * @var string|null $crumb   etykieta bieżącej podstrony w okruszkach
 * @var list<array{0:string,1:string}>|null $okruszki  RODZICE ekranu, od Pulpitu:
 *      [[etykieta, adres], …]. Ostatni element okruszków (bez odsyłacza) to
 *      `$crumb` albo `$title`. Reguła (docs/GOLDEN_LAYOUT.md): ścieżka prowadzi
 *      do rodzica, nigdy do /kluby ani do /zadania.
 */
$theme  = View::theme();
$chrome = $chrome ?? true;
$active = $active ?? '';
$club   = $club ?? null;
$crumb  = $crumb ?? null;
$okruszki = $okruszki ?? null;

$next = $theme === 'dark' ? 'light' : 'dark';

// Licznik nieodczytanych liczony TUTAJ, a nie przekazywany z każdej trasy.
// Nagłówek jest na każdej stronie panelu; przekazywanie licznika z dwudziestu
// miejsc kończy się tym, że w którymś go zabraknie i licznik zniknie na jednym
// ekranie bez żadnego powodu. `unreadCount()` łyka własne błędy i zwraca 0.
$zalogowany   = $chrome && Session::userId() !== null;
$nieodczytane = $zalogowany
    ? \CoachAnalyze\Notifications::unreadCount((int) Session::userId())
    : 0;

// Informacyjne raz na sesję, ważne do zamknięcia (golden layout W4).
$chmurki = $zalogowany
    ? \CoachAnalyze\Notifications::naChmurkiSesji((int) Session::userId())
    : [];

// Liczniki przy pozycjach szyny — WYŁĄCZNIE z danych, które już mamy.
// Pozycja bez licznika po prostu go nie dostaje; zero obok nazwy wygląda jak
// awaria danych, a nie jak „nic nie czeka".
$uzytkownikSzyny = $zalogowany ? \CoachAnalyze\Auth::currentUser() : null;
$klubBiezacy = $zalogowany ? \CoachAnalyze\Zakres::biezacy($uzytkownikSzyny) : null;
$liczniki = $zalogowany
    ? \CoachAnalyze\Stats::railCounters($klubBiezacy !== null ? (int) $klubBiezacy['id'] : null)
    : [];
$kolejka  = $liczniki['queued'] ?? 0;

$uzytkownik = $zalogowany ? \CoachAnalyze\Auth::currentUser() : null;
$admin      = $uzytkownik !== null && \CoachAnalyze\Users::isAdmin($uzytkownik);

/*
 * KONTEKST W SZYNIE JEST ZAWSZE, nie tylko na ekranach klubu.
 *
 * Dotąd blok pojawiał się wyłącznie tam, gdzie trasa podała `$club` — czyli
 * na pulpicie, liście meczów i w połowie panelu szyna nie mówiła, czyje to
 * w ogóle dane. Przy jednym kliencie to nie przeszkadza; przy drugim staje się
 * pytaniem „na czyim koncie jestem", zadawanym przy każdym wejściu.
 *
 * Bez kontekstu trasy bierzemy KLUB-TENANTA (`is_own_team = 1`). To ten sam
 * klub, którego dotyczą metryki pulpitu — więc szyna i liczby mówią o tym samym.
 */
$klubSzyny = $club ?? $klubBiezacy;

/** Inicjały do awatara. Nazwa bywa pusta — wtedy znak zastępczy, nie puste kółko. */
$inicjaly = static function (?array $u): string {
    $nazwa = trim((string) ($u['display_name'] ?? $u['email'] ?? ''));
    if ($nazwa === '') {
        return '?';
    }
    $czesci = preg_split('/[\s@._-]+/u', $nazwa, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $skrot = '';
    foreach (array_slice($czesci, 0, 2) as $c) {
        $skrot .= mb_strtoupper(mb_substr($c, 0, 1));
    }
    return $skrot !== '' ? $skrot : '?';
};

/** Pozycja szyny. `$licznik === null` znaczy „bez licznika", nie „zero". */
$pozycja = static function (
    string $id, string $href, string $etykieta, ?int $licznik = null, bool $pod = false
) use ($active): void {
    $klasy = 'it' . ($pod ? ' it--sub' : '') . ($active === $id ? ' is-active' : '');
    echo '<a class="' . $klasy . '" href="' . View::e($href) . '">'
       . '<span>' . View::e($etykieta) . '</span>';
    if ($licznik !== null && $licznik > 0) {
        echo '<span class="it__cnt">' . ($licznik > 99 ? '99+' : (int) $licznik) . '</span>';
    }
    echo "</a>\n";
};
?>
<!doctype html>
<html lang="pl"<?= $theme !== null ? ' data-theme="' . View::e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php /*
  BEZ MIGOTANIA — i BEZ DRUGIEGO PLIKU `.js`.

  Skrypt jest jednokierunkowy w obie strony, zależnie od tego, co już wiemy:

  - atrybut JEST (serwer odczytał ciasteczko) → zapisujemy go do `localStorage`,
    czyli synchronizujemy pamięć przeglądarki z decyzją serwera;
  - atrybutu NIE MA (brak ciasteczka: inna przeglądarka, wyczyszczone dane)
    → czytamy `localStorage` i ustawiamy atrybut PRZED pierwszym malowaniem.

  Dzięki temu zapis w przeglądarce nie wymaga własnego nasłuchu na przełączniku:
  powstaje przy najbliższym renderze po kliknięciu, a klikniecie i tak przeładowuje
  stronę (formularz POST). Ani jednej linii kodu więcej, niż to potrzebne.

  INLINE JEST TU KONIECZNE, nie wygodne: plik zewnętrzny wczytuje się PO pierwszym
  malowaniu, czyli dokładnie za późno na to, do czego ten skrypt służy. To nie jest
  drugi plik skryptu panelu (CLAUDE.md §9) — pliku nie ma.

  `try/catch`, bo w trybie prywatnym samo sięgnięcie do `localStorage` potrafi rzucić.
*/ ?>
<script>(function(){var r=document.documentElement,a=r.getAttribute('data-theme');try{if(a==='dark'||a==='light'){localStorage.setItem('ca-theme',a);}else{var t=localStorage.getItem('ca-theme');if(t==='dark'||t==='light'){r.setAttribute('data-theme',t);}}}catch(e){}})();</script>
<?php if (!empty($refresh)): ?>
<meta http-equiv="refresh" content="<?= (int) $refresh ?>">
<?php endif; ?>
<title><?= View::e($title ?? View::t('app.name')) ?> — <?= View::e(View::t('app.name')) ?></title>
<link rel="stylesheet" href="<?= View::e(View::asset('/assets/app.css')) ?>">
</head>
<body>
<?php if (!$chrome): ?>
<?= $content ?>
<?php else: ?>
<div class="shell">
  <?php /*
    SZYNA JEST ZAWSZE CIEMNA, także w motywie jasnym — to decyzja z podglądu
    zatwierdzonego przez właściciela. Ciemny pas po lewej trzyma wzrok na treści
    i sprawia, że przełączenie motywu nie przestawia całego ekranu.

    Poniżej 900 px zamienia się w wysuwany panel otwierany `:target` (adres
    `#szyna`). Bez skryptu, bo panel go nie używa poza chmurkami i wskaźnikiem.
  */ ?>
  <aside class="rail" id="szyna" aria-label="<?= View::e(View::t('nav.menu')) ?>">
    <a class="rail__close" href="#" aria-label="<?= View::e(View::t('nav.close')) ?>">×</a>

    <a class="brand" href="/pulpit">
      <span class="brand__logo" aria-hidden="true">CA</span>
      <span>
        <span class="brand__name"><?= View::e(View::t('app.name')) ?></span>
        <span class="brand__tag"><?= View::e(View::t('app.tagline')) ?></span>
      </span>
    </a>

    <?php
      /*
       * KONTEKST KLUBU I SEZONU W JEDNEJ LINII (golden layout W2, pkt 12).
       * Pełna nazwa w `title` — długa nazwa klubu ucina się wielokropkiem,
       * zamiast łamać szynę na dwie linie. Przełączanie klubu: administrator
       * i analityk z więcej niż jednym klubem (`/klub/wybierz`); trener ma
       * dokładnie jeden klub i nie przełącza niczego.
       */
      $opSzyny = \CoachAnalyze\Zakres::op($uzytkownikSzyny);
      $trenerSzyny = \CoachAnalyze\Zakres::trener($uzytkownikSzyny);
      $dozwoloneSzyny = $zalogowany ? \CoachAnalyze\Zakres::dozwoloneKluby($uzytkownikSzyny) : [];
      $przelacza = !$trenerSzyny && ($dozwoloneSzyny === null || count($dozwoloneSzyny) > 1);
      $sezonSzyny = (string) ($liczniki['season'] ?? View::t('dash.all_seasons'));
    ?>
    <?php if ($klubSzyny !== null): ?>
      <?php $opisCtx = (string) $klubSzyny['name'] . ' · ' . $sezonSzyny; ?>
      <<?= $przelacza ? 'a href="/klub/wybierz"' : 'div' ?> class="ctx ctx--linia" title="<?= View::e($opisCtx) ?>">
        <span class="ctx__crest">
          <?php if (!empty($klubSzyny['crest_path'])): ?>
            <img src="/herb/<?= (int) $klubSzyny['id'] ?>" alt="">
          <?php else: ?>
            <span aria-hidden="true"><?= View::e(mb_substr((string) $klubSzyny['name'], 0, 1)) ?></span>
          <?php endif; ?>
        </span>
        <?php /* Skrót klubu, gdy jest — długa pełna nazwa zjadała sezon za wielokropkiem. */ ?>
        <span class="ctx__linia"><b><?= View::e(trim((string) ($klubSzyny['short_name'] ?? '')) ?: (string) $klubSzyny['name']) ?></b> · <?= View::e($sezonSzyny) ?></span>
        <?php if ($przelacza): ?><span class="ctx__sw" aria-hidden="true">⇄</span><?php endif; ?>
      </<?= $przelacza ? 'a' : 'div' ?>>
    <?php endif; ?>

    <nav class="grp">
      <?php $pozycja('pulpit', '/pulpit', View::t('nav.dashboard')); ?>
    </nav>

    <?php /*
      SZYNA KLUBU (golden layout W2, pkt 11). „Mecze" zniknęły z szyny — dublują
      Sezon; trasa `/mecze` zostaje (wyszukiwarka u góry z niej korzysta).
      Trener nie widzi importu ani ustawień klubu — o dostępie rozstrzyga
      `straznikZakresu()` w routerze, szyna tylko nie kusi.
    */ ?>
    <nav class="grp">
      <span class="grp__lab"><?= View::e(View::t('nav.grp.club')) ?></span>
      <?php $pozycja('season', '/sezon', View::t('nav.season')); ?>
      <?php $pozycja('team', '/druzyna', View::t('nav.team')); ?>
      <?php $pozycja('players', '/zawodnicy', View::t('nav.players'), null, true); ?>
      <?php $pozycja('reports', '/raporty', View::t('nav.reports'), $liczniki['reports'] ?? null); ?>
      <?php if (!$trenerSzyny): ?>
        <?php $pozycja('import', '/import', View::t('import.nav')); ?>
      <?php endif; ?>
      <?php $pozycja('links', '/linki', View::t('share.nav'), $liczniki['links'] ?? null); ?>
      <?php if (!$trenerSzyny): ?>
        <?php $pozycja('settings', '/klub/ustawienia', View::t('nav.club_settings')); ?>
      <?php endif; ?>
    </nav>

    <nav class="grp">
      <span class="grp__lab"><?= View::e(View::t('nav.grp.tools')) ?></span>
      <?php $pozycja('calendar', '/kalendarz', View::t('nav.calendar')); ?>
      <?php $pozycja('index', '/indeks', View::t('nav.index')); ?>
      <?php $pozycja('notes', '/notatki', View::t('nav.notes')); ?>
      <?php $pozycja('xg', '/xg', View::t('nav.xg')); ?>
    </nav>

    <?php /*
      [op] ADMINISTRACJA i stopka techniczna — WYŁĄCZNIE administrator.
      Analityk i trener nie mają do czego użyć wersji silnika ani stanu kolejki,
      a pokazane budzą pytania, na które nie mają odpowiedzi.
    */ ?>
    <?php if ($admin): ?>
      <nav class="grp">
        <span class="grp__lab"><?= View::e(View::t('nav.grp.admin')) ?></span>
        <?php $pozycja('clubs', '/kluby', View::t('nav.clubs')); ?>
        <?php $pozycja('users', '/uzytkownicy', View::t('nav.users')); ?>
        <?php $pozycja('jobs', '/zadania', View::t('nav.jobs'), $kolejka > 0 ? $kolejka : null); ?>
      </nav>

      <div class="foot">
        <?= View::e(View::t('common.engine', Engine::version())) ?> ·
        <?= View::e(View::t('nav.template', 'v21')) ?><br>
        <?= View::e(View::t('nav.queue', $kolejka)) ?> ·
        <?= View::e(View::t('nav.disk', \CoachAnalyze\Storage::zajetoscOpis())) ?>
      </div>
    <?php endif; ?>
  </aside>

  <main class="main<?= $club !== null ? ' club-scope' : '' ?>"
    <?php if ($club !== null): ?>
      <?php /*
        Barwy klubu jako zmienne CSS, w scope'ie TEGO <main>, nie :root — poza
        widokiem klubu panel wygląda neutralnie.
        WYŁĄCZNIE surowe hexy z bazy — żadnej arytmetyki koloru po stronie PHP
        (`test_4b.php`: „PHP nie liczy luminancji ani nie miesza kanałów").
        Barwa klubu jest DRUGIM akcentem: pierwszy, zielony, jest stały.
      */ ?>
      style="--klub: <?= View::e(View::color($club['color_primary'] ?? null, '#2B5FD9')) ?>;
             --rywal: <?= View::e(View::color($club['color_secondary'] ?? null, '#A8780A')) ?>;
             --club-primary: <?= View::e(View::color($club['color_primary'] ?? null, '#888888')) ?>;
             --club-secondary: <?= View::e(View::color($club['color_secondary'] ?? null, '#5A6B7B')) ?>;"
    <?php endif; ?>
  >
    <header class="top">
      <a class="ico rail__toggle" href="#szyna" aria-label="<?= View::e(View::t('nav.menu')) ?>">≡</a>

      <?php
        /*
         * OKRUSZKI = ŚCIEŻKA DO RODZICA (golden layout W0). Korzeń to Pulpit,
         * nie lista klubów: `/kluby` jest ekranem administracyjnym, a nie
         * miejscem, z którego analityk przyszedł. Ekran bez jawnych rodziców
         * dostaje „Pulpit → tytuł" — nigdy pustą ścieżkę.
         */
        if ($okruszki === null) {
            $okruszki = $active === 'pulpit' ? [] : [[View::t('nav.dashboard'), '/pulpit']];
        }
        $biezacy = $crumb ?? ($title ?? View::t('app.name'));
      ?>
      <nav class="crumbs" aria-label="<?= View::e(View::t('nav.breadcrumb')) ?>">
        <?php foreach ($okruszki as [$etykieta, $adres]): ?>
          <a href="<?= View::e((string) $adres) ?>"><?= View::e((string) $etykieta) ?></a>
          <span aria-hidden="true">→</span>
        <?php endforeach; ?>
        <b><?= View::e((string) $biezacy) ?></b>
      </nav>

      <?php /*
        Wyszukiwarka prowadzi do ISTNIEJĄCEJ listy meczów z filtrem `q`.
        Nie zakładamy nowego wyszukiwania globalnego: pole, które nie wie,
        czego szuka, jest gorsze niż brak pola.
      */ ?>
      <form class="search" method="get" action="/mecze" role="search">
        <span aria-hidden="true">⌕</span>
        <input class="search__input" type="search" name="q"
               value="<?= View::e((string) ($_GET['q'] ?? '')) ?>"
               placeholder="<?= View::e(View::t('nav.search')) ?>"
               aria-label="<?= View::e(View::t('nav.search')) ?>">
        <button class="search__go" type="submit" aria-label="<?= View::e(View::t('nav.search.go')) ?>">↵</button>
      </form>

      <div class="top__actions">
        <a class="ico" href="/powiadomienia" title="<?= View::e(View::t('nav.notifications')) ?>">
          <span aria-hidden="true">✳</span>
          <?php if ($nieodczytane > 0): ?>
            <span class="dot" aria-hidden="true"></span>
            <span class="sr-only"><?= View::e(View::t('notif.unread', $nieodczytane)) ?></span>
          <?php endif; ?>
        </a>

        <?php /*
          Przełącznik motywu. Formularz działa BEZ skryptu (ciasteczko po stronie
          serwera); skrypt inline w `<head>` czyta dodatkowo `localStorage`, żeby
          wybór przeżył także tam, gdzie ciasteczko zostało wyczyszczone.
        */ ?>
        <form method="post" action="/motyw" class="inline">
          <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
          <input type="hidden" name="theme" value="<?= View::e($next) ?>">
          <input type="hidden" name="powrot" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '/') ?>">
          <button class="ico" type="submit" data-motyw="<?= View::e($next) ?>"
                  title="<?= View::e(View::t($next === 'dark' ? 'nav.theme.to_dark' : 'nav.theme.to_light')) ?>">
            <?= $next === 'dark' ? '◐' : '◑' ?>
          </button>
        </form>

        <a class="av" href="/konto" title="<?= View::e(View::t('account.title')) ?>">
          <?= View::e($inicjaly($uzytkownik)) ?>
        </a>
      </div>
    </header>

    <?= $content ?>
  </main>

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
  powiadomień i wskaźnika pracy kolejki. Raport pozostaje samowystarczalnym
  HTML-em bez ani jednego skryptu; ta zasada nie została naruszona (CLAUDE.md §9).

  KOLEJNOŚĆ ATRYBUTÓW NIE JEST DOWOLNA: `defer` stoi PRZED `src`, bo
  `app/tests/test_chmurki.php` wycina znacznik wyrażeniem `<script\b[^>]*>`,
  a to zatrzymuje się na pierwszym `>`.
*/ ?>
<script defer src="<?= View::e(View::asset('/assets/powiadomienia.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
