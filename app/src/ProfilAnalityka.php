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
 * MIARA: POKRYCIE MNIEJSZEGO ZBIORU (W7-b), |A∩B| / min(|A|,|B|) ≥ 0,8, przy
 * min(|A|,|B|) ≥ 5. Jaccard (W7) karał analityka za DOKŁADANIE tagów: stara
 * Pogoń (LiveTag 1.12.11, 11 tagów) ma z nową (16 tagów) Jaccarda UUID 0,69
 * i nazw 0,50, choć wszystkie 11 starych UUID siedzi w nowym profilu — to ten
 * sam analityk, który dopisał pięć tagów. Pokrycie daje tu 1,0 po UUID.
 * Próg minimalnej wielkości chroni przed „pokryciem" przez 2-tagowy plik.
 * Zapasowo ta sama miara po NAZWACH znormalizowanych.
 *
 * Znany profil nie wymaga niczego od panelu: przypisania ze Słownika stosuje
 * silnik (UUID tagu, zapasowo nazwa znormalizowana). Nowy profil daje baner
 * w raporcie — raport i tak powstaje, z warstwą 1 („Wszystkie tagi z pliku").
 */
final class ProfilAnalityka
{
    public const PROG = 0.8;

    /** Mniejszy zbiór musi mieć co najmniej tyle elementów, żeby pokrycie coś znaczyło. */
    public const MIN_ZBIOR = 5;

    /**
     * Pokrycie mniejszego zbioru: |A∩B| / min(|A|,|B|); 0, gdy mniejszy zbiór
     * ma mniej niż `MIN_ZBIOR` elementów (za mało, żeby orzec o profilu).
     */
    public static function pokrycie(array $a, array $b): float
    {
        $a = array_unique(array_map('strval', $a));
        $b = array_unique(array_map('strval', $b));
        $min = min(count($a), count($b));
        return $min < self::MIN_ZBIOR ? 0.0 : round(count(array_intersect($a, $b)) / $min, 3);
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
     * @return array{nowy:bool, import_id:?int, pokrycie_uuid:float, pokrycie_nazw:float}|null
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
        $najlepszy = ['nowy' => true, 'import_id' => null, 'pokrycie_uuid' => 0.0, 'pokrycie_nazw' => 0.0];
        foreach ($inne as $w) {
            $o = self::odcisk($w);
            if ($o === null) {
                continue;
            }
            $pu = self::pokrycie($moj['uuid'], $o['uuid']);
            $pn = self::pokrycie($moj['nazwy'], $o['nazwy']);
            if (max($pu, $pn) > max($najlepszy['pokrycie_uuid'], $najlepszy['pokrycie_nazw'])) {
                $najlepszy = [
                    'nowy'          => !self::dopasowane($pu, $pn),
                    'import_id'     => (int) $w['id'],
                    'pokrycie_uuid' => $pu,
                    'pokrycie_nazw' => $pn,
                ];
            }
        }
        if ($najlepszy['nowy']) {
            $najlepszy['import_id'] = null;
        }
        return $najlepszy;
    }

    /** Czy dwa odciski to ten sam profil: pokrycie UUID albo — zapasowo — nazw ≥ próg. */
    public static function dopasowane(float $pokrycieUuid, float $pokrycieNazw): bool
    {
        return $pokrycieUuid >= self::PROG || $pokrycieNazw >= self::PROG;
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
