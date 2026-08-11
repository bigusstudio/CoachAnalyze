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
}
