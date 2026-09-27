<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Reguła powrotu (golden layout, docs/GOLDEN_LAYOUT.md).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * KAŻDY EKRAN MA JEDNEGO RODZICA. „Wróć" prowadzi tam, skąd operator przyszedł
 * (`?powrot=`), a bez tego parametru — do rodzica ekranu. Nigdy do `/kluby`
 * (lista administracyjna, nie miejsce pracy analityka) i nigdy do `/zadania`
 * (strona techniczna, widoczna tylko dla administratora).
 *
 * `powrot` przychodzi z adresu, czyli od użytkownika. Przepuszczamy WYŁĄCZNIE
 * ścieżkę względną wewnątrz aplikacji: bez schematu, bez hosta, bez `//`
 * i bez ukośnika odwrotnego (przeglądarki traktują `/\evil.com` jak `//evil.com`).
 * Wszystko inne schodzi na rodzica — przekierowanie na obcą stronę z naszego
 * adresu to klasyczny wektor phishingu.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class Powrot
{
    /** Cel klipsa „CA" w raporcie spod linku publicznego. */
    public const STRONA_PUBLICZNA = 'https://coachanalyze.pl';

    /** Znacznik serwowania w raporcie v21 (engine/coachanalyze/render.py). */
    public const ZNACZNIK_RAPORTU = '__POWROT_URL__';

    /** Prefiksy, do których „Wróć" nie prowadzi nigdy. */
    private const ZAKAZANE = ['/kluby', '/zadania'];

    /** Adres „Wróć": `?powrot=` z żądania, jeśli bezpieczny, inaczej rodzic. */
    public static function url(string $domyslny): string
    {
        $kandydat = self::bezpieczna($_GET['powrot'] ?? null);
        return $kandydat ?? $domyslny;
    }

    /** Ścieżka względna wewnątrz aplikacji albo null. */
    public static function bezpieczna(mixed $wartosc): ?string
    {
        if (!is_string($wartosc) || $wartosc === '' || strlen($wartosc) > 300) {
            return null;
        }
        if ($wartosc[0] !== '/' || str_starts_with($wartosc, '//') || str_contains($wartosc, '\\')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $wartosc) === 1) {
            return null;
        }
        foreach (self::ZAKAZANE as $prefiks) {
            if ($wartosc === $prefiks || str_starts_with($wartosc, $prefiks . '/') || str_starts_with($wartosc, $prefiks . '?')) {
                return null;
            }
        }
        return $wartosc;
    }

    /** Odsyłacz z dopisanym `powrot` — dokąd wrócić z ekranu docelowego. */
    public static function z(string $href, string $powrot): string
    {
        $cel = self::bezpieczna($powrot);
        if ($cel === null) {
            return $href;
        }
        return $href . (str_contains($href, '?') ? '&' : '?') . 'powrot=' . rawurlencode($cel);
    }

    /** Bieżąca ścieżka z zapytaniem — do przekazania dalej jako `powrot`. */
    public static function tutaj(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '/pulpit');
    }

    /**
     * Raport z wypełnionymi ZNACZNIKAMI SERWOWANIA (docs/KONTRAKT_CLI.md):
     * cel klipsa „← CA", tryb odbiorcy i adres karty meczu (golden layout W3).
     * Raport sprzed zmiany znaczników ich nie ma — `str_replace` bez trafienia
     * zostawia go nietkniętego.
     *
     * @param string $tryb  op | analityk | trener | publiczny
     */
    public static function wypelnijRaport(string $html, string $cel, string $tryb = 'publiczny', string $karta = ''): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $tryb = in_array($tryb, ['op', 'analityk', 'trener', 'publiczny'], true) ? $tryb : 'publiczny';
        return str_replace(
            [self::ZNACZNIK_RAPORTU, '__TRYB__', '__KARTA_URL__'],
            [$e($cel), $tryb, $e($karta)],
            $html
        );
    }

    /** Tryb odbiorcy raportu dla zalogowanego — z roli (golden layout W3). */
    public static function trybDla(?array $user): string
    {
        return match ((string) ($user['role'] ?? '')) {
            'admin'    => 'op',
            'operator' => 'analityk',
            'viewer'   => 'trener',
            default    => 'publiczny',
        };
    }
}
