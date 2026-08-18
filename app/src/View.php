<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Minimalny renderer szablonów. Bez frameworka i bez silnika szablonów —
 * skala projektu tego nie wymaga (CLAUDE.md §8).
 *
 * Reguła: do szablonu NIC nie trafia bez `e()`. Jedyny wyjątek to wartości
 * jawnie oznaczone jako gotowy HTML, których w tej warstwie nie ma.
 */
final class View
{
    /** @var array<string,string>|null */
    private static ?array $lang = null;

    /** Tekst interfejsu po polsku. Brakujący klucz zwraca sam klucz — widać go od razu. */
    public static function t(string $key, mixed ...$args): string
    {
        self::$lang ??= require __DIR__ . '/lang/pl.php';
        $text = self::$lang[$key] ?? $key;
        return $args === [] ? $text : sprintf($text, ...$args);
    }

    /** Ucieczka HTML. Krótka nazwa, bo w szablonach pojawia się przy każdej wartości. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $file = __DIR__ . '/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Brak szablonu: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public static function page(string $template, array $data = []): void
    {
        $data['content'] = self::render($template, $data);
        echo self::render('layout', $data);
    }

    /**
     * Motyw z ciasteczka, a nie z localStorage: musi być znany przy PIERWSZYM
     * renderze po stronie serwera, inaczej strona mignie jasnym tłem, zanim
     * JavaScript zdąży się wykonać. Brak ciasteczka = decyduje prefers-color-scheme.
     */
    public static function theme(): ?string
    {
        $theme = $_COOKIE['ca_theme'] ?? null;
        return in_array($theme, ['light', 'dark'], true) ? $theme : null;
    }

    /**
     * Znacznik stanu jako gotowy HTML. Nazwa stanu tłumaczona na polski, klasa
     * CSS bierze się z zamkniętej listy — wartość z bazy nigdy nie trafia do
     * atrybutu wprost, nawet gdyby ktoś rozszerzył ENUM w migracji.
     */
    public static function status(string $status): string
    {
        $known = ['draft', 'queued', 'running', 'done', 'failed'];
        $slug = in_array($status, $known, true) ? $status : 'draft';
        $label = in_array($status, $known, true) ? self::t('status.' . $status) : $status;

        return sprintf(
            '<span class="tag tag--%s">%s</span>',
            $slug,
            self::e($label)
        );
    }

    /**
     * Znacznik stanu linku publicznego.
     *
     * Osobno od `status()`, bo to inna dziedzina: zadanie bywa `failed`, link
     * bywa `revoked`, a wspólny zestaw nazw kusiłby, żeby je mieszać. Brak linku
     * jest tu pełnoprawnym stanem, nie pustką — „nieudostępniony" to informacja.
     */
    public static function linkStatus(string $stan): string
    {
        $znane = ['none', 'active', 'expired', 'revoked'];
        $slug  = in_array($stan, $znane, true) ? $stan : 'none';

        return sprintf(
            '<span class="tag tag--link-%s">%s</span>',
            $slug,
            self::e(self::t('link.status.' . $slug))
        );
    }

    /**
     * Barwa klubu do wstawienia w atrybut `style`.
     *
     * Barwy klubowe to DANE, nie tokeny motywu — dlatego trafiają do zmiennej
     * CSS, a arkusz nadal nie zawiera żadnej wartości szesnastkowej poza
     * definicją motywu.
     *
     * Sprawdzamy wzorzec, a nie tylko uciekamy znaki: wartość z bazy wpadająca
     * do atrybutu `style` to miejsce, w którym `expression(...)` albo domknięcie
     * cudzysłowu robią realną szkodę. Cokolwiek innego niż `#RRGGBB` zamieniamy
     * na barwę zastępczą.
     */
    public static function color(mixed $value, string $fallback = '#888888'): string
    {
        $candidate = is_string($value) ? trim($value) : '';
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $candidate) === 1 ? $candidate : $fallback;
    }

    /** Czas w sekundach na czytelny opis po polsku. */
    public static function humanSeconds(int $seconds): string
    {
        if ($seconds >= 60) {
            return self::t('time.minutes', (int) ceil($seconds / 60));
        }
        return self::t('time.seconds', max(1, $seconds));
    }

    /** @var array<string,string> Wyliczone adresy zasobów — jeden `stat` na plik na żądanie. */
    private static array $assetCache = [];

    /**
     * Adres zasobu statycznego z parametrem wersji: `/assets/app.css?v=lkq3n1`.
     *
     * PO CO: bez tego przeglądarka po wdrożeniu podaje stary arkusz i stary
     * skrypt. Nagłówki Apache dla plików statycznych nie niosą `Cache-Control`,
     * więc obowiązuje BUFOROWANIE HEURYSTYCZNE — przeglądarce wolno wtedy
     * trzymać kopię przez ułamek wieku pliku i podawać ją BEZ pytania serwera.
     * Skutkiem jest panel po deployu wyglądający jak przed nim, do czasu
     * twardego odświeżenia, o które nie ma jak poprosić operatora. Zmiana
     * adresu rozwiązuje to u źródła: dla nowego adresu żadna kopia nie istnieje.
     *
     * WERSJA TO `filemtime`, nie hash treści. Powód praktyczny: `rsync -a`
     * w `deploy.sh` przenosi czasy modyfikacji, więc plik niezmieniony
     * zachowuje swój czas i swój adres (bufor działa dalej), a plik podmieniony
     * dostaje nowy. Liczenie skrótu treści przy każdym żądaniu byłoby czytaniem
     * całego arkusza po to, żeby wypisać sześć znaków.
     *
     * Czas jest zapisany w bazie 36 — krócej i bez udawania, że to sekret.
     * Apache i tak wysyła dokładny `Last-Modified` w nagłówku odpowiedzi,
     * więc skracanie tego do skrótu byłoby zabezpieczeniem pozorowanym.
     *
     * ─────────────────────────────────────────────────────────────────────
     * DLACZEGO DWIE ŚCIEŻKI, A NIE JEDNA — TO JEST TA SAMA PUŁAPKA,
     * KTÓRA W TYM REPOZYTORIUM WRACAŁA JUŻ DWA RAZY (patrz `test_layout.php`).
     *
     * Układ w repozytorium NIE jest układem produkcyjnym. `deploy.sh` robi dwa
     * przejścia rsync i zawartość `app/public/` ląduje piętro wyżej:
     *
     *   repozytorium                          produkcja
     *   app/public/assets/app.css      ->     {domena}/assets/app.css
     *   app/src/View.php               ->     {domena}/app/src/View.php
     *
     * `CA_ROOT` wskazuje katalog zawierający `app/`, więc ten sam adres
     * publiczny `/assets/app.css` leży pod DWOMA różnymi ścieżkami na dysku,
     * zależnie od układu. Sprawdzenie tylko jednej z nich działałoby w testach
     * i cicho przestawało działać na produkcji — albo odwrotnie.
     * ─────────────────────────────────────────────────────────────────────
     *
     * Gdy pliku nie da się odnaleźć, zwracamy adres BEZ parametru zamiast
     * zmyślać wersję. Wersja wzięta z sufitu byłaby gorsza niż jej brak:
     * przypięłaby przypadkową wartość na długo. Adres bez parametru trafia
     * natomiast na regułę `must-revalidate` z `app/public/.htaccess`, czyli
     * degraduje się do zachowania poprawnego, tylko mniej oszczędnego.
     * Powód zapisujemy do logu — cicha degradacja jest tu nie do wykrycia.
     */
    public static function asset(string $sciezka): string
    {
        if (isset(self::$assetCache[$sciezka])) {
            return self::$assetCache[$sciezka];
        }

        $wzgledna = ltrim($sciezka, '/');
        $korzen   = defined('CA_ROOT') ? CA_ROOT : dirname(__DIR__, 2);

        $kandydaci = [
            $korzen . '/' . $wzgledna,                 // produkcja: {domena}/assets/…
            $korzen . '/app/public/' . $wzgledna,      // repozytorium: app/public/assets/…
        ];

        $wynik = $sciezka;
        foreach ($kandydaci as $plik) {
            $mtime = @filemtime($plik);
            if ($mtime !== false) {
                $wynik = $sciezka . '?v=' . base_convert((string) $mtime, 10, 36);
                break;
            }
        }

        if ($wynik === $sciezka) {
            error_log(
                'View::asset: nie odnaleziono pliku dla ' . $sciezka
                . ' (sprawdzone: ' . implode(', ', $kandydaci) . ') — adres bez wersji'
            );
        }

        return self::$assetCache[$sciezka] = $wynik;
    }
}
