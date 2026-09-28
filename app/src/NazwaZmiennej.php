<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Tożsamość nazwy zmiennej templatu — JEDNA funkcja dla importu, ekranu różnic
 * i konfiguratora (0.16.3).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * USTERKA, KTÓRA TO WYMUSIŁA (Pogoń, import meczu 26).
 *
 * Templat miał etykietę „Inne" (v_030), eksport niósł „INNE". `AutoImport`
 * porównywał po normalizacji i uznał ją za znaną — nie dodał. `TemplateDiff`
 * porównywał dosłownie i pokazał ją jako nową w „Poza templatem klubu".
 * Operator dodał ją ręcznie i templat ma dziś DWIE zmienne na jedną etykietę.
 *
 * Dwa miejsca, dwie definicje „ta sama nazwa" — i każde miało rację po swojemu.
 * Odtąd definicja jest tutaj i nigdzie indziej.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * RÓWNOŚĆ PO NORMALIZACJI, NIGDY FRAGMENT (pułapka 7). Normalizacja zdejmuje
 * wyłącznie to, czym analityk różni się od siebie przy klawiaturze: wielkość
 * liter, nadmiarowe spacje i łączniki. `SBZ PODAJĄCY` i `SBZ PODAJĄCY/OTRZYMUJĄCY`
 * zostają dwiema różnymi nazwami.
 *
 * ALIASY SILNIKA (`aliasy.json`) — kopia pliku silnika w `app/src/data/`. Silnik
 * leży poza `open_basedir` PHP-FPM na lh.pl (docs/OGRANICZENIA_HOSTINGU.md),
 * więc panel nie przeczyta oryginału. Kopia jest artefaktem jak `xg_grid.json`,
 * a `test_nazwy_zmiennych.php` porównuje ją bajt w bajt z plikiem silnika.
 */
final class NazwaZmiennej
{
    /** Klucz porównania: małe litery, łączniki jako spacje, jedna spacja, bez brzegów. */
    public static function klucz(string $nazwa): string
    {
        $bezLacznikow = preg_replace('/[\x{2010}-\x{2015}\-]+/u', ' ', $nazwa) ?? $nazwa;
        $jednaSpacja = preg_replace('/\s+/u', ' ', $bezLacznikow) ?? $bezLacznikow;
        return mb_strtolower(trim($jednaSpacja), 'UTF-8');
    }

    /**
     * Klucz ZNACZENIA (W7): jak `klucz`, a do tego kropki jako spacje.
     *
     * NIE SŁUŻY TOŻSAMOŚCI ZMIENNEJ. „1x1 DEF" i „1x1 DEF." to dwie zmienne
     * (templat, Słownik, wyświetlanie 1:1); ten klucz rozstrzyga wyłącznie ich
     * ZNACZENIE i zapasowe dopasowanie przypisań ze Słownika (UUID tagu po
     * nazwie). Bliźniak w silniku: `znaczenie.normalizuj`.
     */
    public static function kluczZnaczenia(string $nazwa): string
    {
        return self::klucz(preg_replace('/\.+/u', ' ', $nazwa) ?? $nazwa);
    }

    /** Czy dwie nazwy to ta sama zmienna. */
    public static function rowne(string $a, string $b): bool
    {
        return self::klucz($a) === self::klucz($b);
    }

    /** @var array<string,string>|null */
    private static ?array $odwrotne = null;

    /**
     * {klucz aliasu: nazwa główna} z `aliasy.json`. Klucze opisowe (`_…`) pomijamy.
     *
     * Nieczytelny plik daje pustą mapę, tak samo jak w silniku (`aliasy.domyslne`):
     * brak aliasu to raport uboższy, ale prawdziwy.
     *
     * @return array<string,string>
     */
    public static function aliasySilnika(): array
    {
        if (self::$odwrotne !== null) {
            return self::$odwrotne;
        }
        $dane = json_decode((string) @file_get_contents(self::plikAliasow()), true);
        $out = [];
        foreach (is_array($dane) ? $dane : [] as $glowna => $aliasy) {
            if (str_starts_with((string) $glowna, '_') || !is_array($aliasy)) {
                continue;
            }
            foreach ($aliasy as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $out[self::klucz($alias)] = (string) $glowna;
                }
            }
        }
        return self::$odwrotne = $out;
    }

    public static function plikAliasow(): string
    {
        return __DIR__ . '/data/aliasy.json';
    }
}
