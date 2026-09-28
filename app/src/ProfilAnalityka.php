<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Profil analityka (W7 D) — „czy klub już widział ten układ tagów".
 *
 * Odcisk profilu liczy silnik (`meta.profil`): zbiór UUID tagów z pliku projektu
 * i zbiór nazw znormalizowanych. Import zapisuje go w `imports.profil_json`
 * (migracja 019). Tu porównujemy go z wcześniejszymi importami klubu.
 *
 * DWA PROGI, BO UUID NIE WYSTARCZA. Stara Pogoń z LiveTag 1.12.11 ma z nową
 * tylko 0,69 po UUID (analityk odtworzył część tagów od nowa), a to ten sam
 * analityk i te same nazwy. Zapasowo liczymy więc Jaccarda NAZW; dopasowanie
 * po którymkolwiek z nich ≥ 0,8 znaczy „znany profil".
 *
 * Znany profil nie wymaga niczego od panelu: przypisania ze Słownika stosuje
 * silnik (UUID tagu, zapasowo nazwa znormalizowana). Nowy profil daje baner
 * w raporcie — raport i tak powstaje, z warstwą 1 („Wszystkie tagi z pliku").
 */
final class ProfilAnalityka
{
    public const PROG = 0.8;

    /** Jaccard dwóch zbiorów napisów; dwa puste zbiory = 0 (nic nie wiadomo). */
    public static function jaccard(array $a, array $b): float
    {
        $a = array_unique(array_map('strval', $a));
        $b = array_unique(array_map('strval', $b));
        $suma = count(array_unique(array_merge($a, $b)));
        return $suma === 0 ? 0.0 : round(count(array_intersect($a, $b)) / $suma, 3);
    }

    /** @return array{uuid:list<string>, nazwy:list<string>, nazwa_uuid:array<string,string>}|null */
    public static function odcisk(?array $import): ?array
    {
        $p = json_decode((string) ($import['profil_json'] ?? ''), true);
        if (!is_array($p)) {
            return null;
        }
        return [
            'uuid'       => array_values(array_map('strval', (array) ($p['uuid'] ?? []))),
            'nazwy'      => array_values(array_map('strval', (array) ($p['nazwy'] ?? []))),
            'nazwa_uuid' => array_map('strval', (array) ($p['nazwa_uuid'] ?? [])),
        ];
    }

    /**
     * Ocena profilu importu wobec wcześniejszych importów klubu.
     *
     * @return array{nowy:bool, import_id:?int, jaccard_uuid:float, jaccard_nazwy:float}|null
     *         null, gdy import nie ma odcisku (silnik < 0.17 albo brak inspekcji)
     */
    public static function ocen(int $importId): ?array
    {
        $import = Db::one(
            'SELECT i.id, i.match_id, i.profil_json, m.club_id
               FROM imports i JOIN matches m ON m.id = i.match_id
              WHERE i.id = :id',
            ['id' => $importId]
        );
        $moj = self::odcisk($import);
        if ($import === null || $moj === null) {
            return null;
        }
        $inne = Db::all(
            'SELECT i.id, i.profil_json
               FROM imports i JOIN matches m ON m.id = i.match_id
              WHERE m.club_id = :club AND i.match_id <> :mecz AND i.profil_json IS NOT NULL
              ORDER BY i.id DESC',
            ['club' => (int) $import['club_id'], 'mecz' => (int) $import['match_id']]
        );
        $najlepszy = ['nowy' => true, 'import_id' => null, 'jaccard_uuid' => 0.0, 'jaccard_nazwy' => 0.0];
        foreach ($inne as $w) {
            $o = self::odcisk($w);
            if ($o === null) {
                continue;
            }
            $ju = self::jaccard($moj['uuid'], $o['uuid']);
            $jn = self::jaccard($moj['nazwy'], $o['nazwy']);
            if (max($ju, $jn) > max($najlepszy['jaccard_uuid'], $najlepszy['jaccard_nazwy'])) {
                $najlepszy = [
                    'nowy'          => !($ju >= self::PROG || $jn >= self::PROG),
                    'import_id'     => (int) $w['id'],
                    'jaccard_uuid'  => $ju,
                    'jaccard_nazwy' => $jn,
                ];
            }
        }
        if ($najlepszy['nowy']) {
            $najlepszy['import_id'] = null;
        }
        return $najlepszy;
    }

    /** Zapis oceny w `imports` (migracja 019). */
    public static function zapisz(int $importId, ?array $ocena): void
    {
        if ($ocena === null) {
            return;
        }
        Db::run(
            'UPDATE imports SET profil_nowy = :nowy, profil_import_id = :imp WHERE id = :id',
            ['nowy' => $ocena['nowy'] ? 1 : 0, 'imp' => $ocena['import_id'], 'id' => $importId]
        );
    }

    /**
     * UUID tagów klubu o danych nazwach — do `variables[].uuids` przy zapisie Słownika.
     *
     * Z odcisków wszystkich importów klubu; nazwa porównywana po normalizacji
     * (`NazwaZmiennej::klucz`, bliźniak `znaczenie.normalizuj`). Dzięki temu
     * przypisanie przeżywa zmianę nazwy tagu przez analityka (ten sam UUID).
     *
     * @param list<string> $nazwy
     * @return list<string>
     */
    public static function uuidDlaNazw(int $clubId, array $nazwy): array
    {
        $klucze = array_flip(array_map([NazwaZmiennej::class, 'klucz'], $nazwy));
        $out = [];
        $wiersze = Db::all(
            'SELECT i.profil_json FROM imports i JOIN matches m ON m.id = i.match_id
              WHERE m.club_id = :club AND i.profil_json IS NOT NULL',
            ['club' => $clubId]
        );
        foreach ($wiersze as $w) {
            foreach ((self::odcisk($w)['nazwa_uuid'] ?? []) as $nazwa => $uuid) {
                if (isset($klucze[NazwaZmiennej::klucz((string) $nazwa)]) && $uuid !== '') {
                    $out[$uuid] = true;
                }
            }
        }
        $lista = array_keys($out);
        sort($lista);
        return $lista;
    }
}
