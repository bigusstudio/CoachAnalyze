<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Porządki w meczach i klubach — logika `app/repairs/usun_mecz.php`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PLAN, POTEM WYKONANIE. Każda operacja najpierw zwraca PLAN: listę zapytań
 * (z opisem i liczbą dotkniętych wierszy) i listę plików. Podgląd drukuje plan,
 * zapis wykonuje DOKŁADNIE ten plan — nie liczy niczego drugi raz. Dwie ścieżki
 * (jedna do pokazania, druga do zrobienia) rozjechałyby się przy pierwszej
 * poprawce i podgląd przestałby mówić prawdę o zapisie.
 *
 * KLUCZE OBCE PRODUKCJI SĄ SUROWSZE NIŻ SQLite W TESTACH. Bez `ON DELETE
 * CASCADE` są: `tag_catalog.first/last_seen_import_id` → imports,
 * `events.import_id` → imports, `match_players.match_id` → matches,
 * `club_report_templates` / `club_ignored_tags` → clubs. Plan czyści je jawnie
 * i w kolejności dzieci przed rodzicem — na MySQL-u pominięcie kończy się
 * błędem w połowie, na SQLite w testach przeszłoby po cichu. Dlatego plan
 * nie polega na kaskadach NIGDZIE, także tam, gdzie produkcja je ma.
 *
 * PLIKI USUWAMY PO COMMICIE i wyłącznie wewnątrz STORAGE_PATH
 * (`Storage::isInside`). Rekord bez pliku to stan, który panel już obsługuje
 * („raport niedostępny"); plik bez rekordu po wycofanej transakcji byłby
 * danymi taktycznymi klienta, o których nikt nie wie.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PorzadkiMeczow
{
    /** @return array{zapytania:list<array{opis:string,sql:string,p:array<string,mixed>,ile:int}>,pliki:list<string>,katalogi:list<string>,uwagi:list<string>,bledy:list<string>} */
    private static function pustyPlan(): array
    {
        return ['zapytania' => [], 'pliki' => [], 'katalogi' => [], 'uwagi' => [], 'bledy' => []];
    }

    /** Zapytanie do planu, z liczbą wierszy, których dotknie. */
    private static function dodaj(array &$plan, string $opis, string $tabela, string $gdzie, array $p, string $sql): void
    {
        try {
            $ile = (int) (Db::one("SELECT COUNT(*) AS c FROM {$tabela} WHERE {$gdzie}", $p)['c'] ?? 0);
        } catch (\PDOException $e) {
            // Brak tabeli (np. `events_canonical` z migracji 002 w bazie, która
            // jej nie ma) — mówimy o tym, zamiast wywracać cały plan.
            $plan['uwagi'][] = "tabela {$tabela}: niedostępna ({$opis}) — pominięta";
            return;
        }
        if ($ile > 0) {
            $plan['zapytania'][] = ['opis' => $opis, 'sql' => $sql, 'p' => $p, 'ile' => $ile];
        }
    }

    /** @param list<int> $ids @return array{0:string,1:array<string,int>} „:x0, :x1" i parametry */
    private static function lista(string $prefiks, array $ids): array
    {
        $p = [];
        foreach (array_values($ids) as $i => $id) {
            $p[$prefiks . $i] = (int) $id;
        }
        return [implode(', ', array_map(static fn(string $k): string => ':' . $k, array_keys($p))), $p];
    }

    /**
     * Zadania kolejki dotyczące meczu, importów albo raportów.
     * `payload_json` czytamy w PHP — funkcje JSON różnią się między MySQL a SQLite.
     *
     * @param list<int> $mecze @param list<int> $importy @param list<int> $raporty
     * @return list<int>
     */
    private static function zadania(array $mecze, array $importy, array $raporty): array
    {
        $out = [];
        foreach (Db::all('SELECT id, payload_json FROM jobs') as $j) {
            $p = json_decode((string) ($j['payload_json'] ?? ''), true);
            if (!is_array($p)) {
                continue;
            }
            if (in_array((int) ($p['match_id'] ?? 0), $mecze, true)
                || in_array((int) ($p['import_id'] ?? 0), $importy, true)
                || in_array((int) ($p['report_id'] ?? 0), $raporty, true)) {
                $out[] = (int) $j['id'];
            }
        }
        return $out;
    }

    /**
     * Raporty do usunięcia: rekordy, linki publiczne, powiadomienia, pliki.
     *
     * @param list<int> $raporty
     */
    private static function planRaportow(array &$plan, array $raporty): void
    {
        if ($raporty === []) {
            return;
        }
        [$in, $p] = self::lista('r', $raporty);
        foreach (Db::all("SELECT id, report_id FROM share_links WHERE report_id IN ({$in}) AND revoked_at IS NULL", $p) as $s) {
            $plan['uwagi'][] = "raport {$s['report_id']} ma AKTYWNY link publiczny (share_links.id {$s['id']}) — przestanie działać";
        }
        self::dodaj($plan, 'linki publiczne raportów', 'share_links', "report_id IN ({$in})", $p,
            "DELETE FROM share_links WHERE report_id IN ({$in})");
        self::dodaj($plan, 'powiadomienia o raportach', 'notifications', "entity = 'report' AND entity_id IN ({$in})", $p,
            "DELETE FROM notifications WHERE entity = 'report' AND entity_id IN ({$in})");
        self::dodaj($plan, 'raporty', 'reports', "id IN ({$in})", $p, "DELETE FROM reports WHERE id IN ({$in})");

        foreach (Db::all("SELECT html_path FROM reports WHERE id IN ({$in})", $p) as $r) {
            $html = (string) ($r['html_path'] ?? '');
            if ($html === '') {
                continue;
            }
            // Plik raportu i wszystko o tym samym rdzeniu nazwy obok niego
            // (dziś silnik zapisuje tylko HTML; slajdy PNG powstają w przeglądarce).
            $rdzen = preg_replace('/\.[^.\/]+$/', '', $html) ?? $html;
            foreach (array_unique(array_merge([$html], glob($rdzen . '.*') ?: [])) as $plik) {
                $plan['pliki'][] = $plik;
            }
        }
    }

    /**
     * Usunięcie meczów razem ze wszystkim, co od nich zależy.
     *
     * @param list<int> $mecze
     */
    public static function planUsunMecze(array $mecze): array
    {
        $plan = self::pustyPlan();
        $mecze = array_values(array_unique(array_map('intval', $mecze)));
        foreach ($mecze as $id) {
            if (Db::one('SELECT id FROM matches WHERE id = :id', ['id' => $id]) === null) {
                $plan['bledy'][] = "mecz {$id} nie istnieje";
            }
        }
        if ($plan['bledy'] !== [] || $mecze === []) {
            return $plan;
        }

        [$inM, $pM] = self::lista('m', $mecze);
        $importy = array_map('intval', array_column(Db::all("SELECT id FROM imports WHERE match_id IN ({$inM})", $pM), 'id'));
        $raporty = array_map('intval', array_column(Db::all("SELECT id FROM reports WHERE match_id IN ({$inM})", $pM), 'id'));
        $zadania = self::zadania($mecze, $importy, $raporty);

        self::planRaportow($plan, $raporty);

        if ($zadania !== []) {
            [$inJ, $pJ] = self::lista('j', $zadania);
            self::dodaj($plan, 'powiadomienia o zadaniach', 'notifications', "entity = 'job' AND entity_id IN ({$inJ})", $pJ,
                "DELETE FROM notifications WHERE entity = 'job' AND entity_id IN ({$inJ})");
            self::dodaj($plan, 'zadania kolejki', 'jobs', "id IN ({$inJ})", $pJ, "DELETE FROM jobs WHERE id IN ({$inJ})");
            foreach ($zadania as $j) {
                $plan['katalogi'][] = Storage::root() . '/jobs/' . $j;
            }
        }

        self::dodaj($plan, 'zdarzenia', 'events', "match_id IN ({$inM})", $pM, "DELETE FROM events WHERE match_id IN ({$inM})");
        self::dodaj($plan, 'zdarzenia kanoniczne (stara tabela)', 'events_canonical', "match_id IN ({$inM})", $pM,
            "DELETE FROM events_canonical WHERE match_id IN ({$inM})");
        self::dodaj($plan, 'skład meczu', 'match_players', "match_id IN ({$inM})", $pM,
            "DELETE FROM match_players WHERE match_id IN ({$inM})");
        self::dodaj($plan, 'notatki do meczu', 'notes', "match_id IN ({$inM})", $pM, "DELETE FROM notes WHERE match_id IN ({$inM})");
        self::dodaj($plan, 'strzały z kalkulatora xG: odpięcie od meczu', 'xg_manual_shots', "match_id IN ({$inM})", $pM,
            "UPDATE xg_manual_shots SET match_id = NULL WHERE match_id IN ({$inM})");

        if ($importy !== []) {
            [$inI, $pI] = self::lista('i', $importy);
            // Katalog tagów to HISTORIA tagowania klubu — wiersze zostają, znika
            // tylko wskazanie na import, którego już nie ma (FK bez kaskady).
            self::dodaj($plan, 'katalog tagów: odpięcie first_seen_import_id', 'tag_catalog', "first_seen_import_id IN ({$inI})", $pI,
                "UPDATE tag_catalog SET first_seen_import_id = NULL WHERE first_seen_import_id IN ({$inI})");
            self::dodaj($plan, 'katalog tagów: odpięcie last_seen_import_id', 'tag_catalog', "last_seen_import_id IN ({$inI})", $pI,
                "UPDATE tag_catalog SET last_seen_import_id = NULL WHERE last_seen_import_id IN ({$inI})");
            self::dodaj($plan, 'prośby o pojęcie: odpięcie importu', 'concept_requests', "import_id IN ({$inI})", $pI,
                "UPDATE concept_requests SET import_id = NULL WHERE import_id IN ({$inI})");
            foreach (Db::all("SELECT csv_path, json_path FROM imports WHERE id IN ({$inI})", $pI) as $imp) {
                foreach (['csv_path', 'json_path'] as $pole) {
                    if ((string) ($imp[$pole] ?? '') !== '') {
                        $plan['pliki'][] = (string) $imp[$pole];
                    }
                }
            }
            self::dodaj($plan, 'importy', 'imports', "id IN ({$inI})", $pI, "DELETE FROM imports WHERE id IN ({$inI})");
        }

        self::dodaj($plan, 'mecze', 'matches', "id IN ({$inM})", $pM, "DELETE FROM matches WHERE id IN ({$inM})");
        return $plan;
    }

    /**
     * Przepięcie meczu na inny klub-tenant. Raportów NIE ruszamy (zamówienie):
     * `reports.club_id`, linki publiczne i skład zostają przy starym klubie
     * i plan mówi o tym wprost.
     */
    public static function planPrzepnij(int $mecz, int $klub): array
    {
        $plan = self::pustyPlan();
        $m = Db::one('SELECT id, club_id FROM matches WHERE id = :id', ['id' => $mecz]);
        if ($m === null) {
            $plan['bledy'][] = "mecz {$mecz} nie istnieje";
        }
        if (Db::one('SELECT id FROM clubs WHERE id = :id', ['id' => $klub]) === null) {
            $plan['bledy'][] = "klub {$klub} nie istnieje";
        }
        if ($plan['bledy'] !== []) {
            return $plan;
        }
        $stary = (int) $m['club_id'];
        if ($stary === $klub) {
            return $plan;
        }

        $p = ['m' => $mecz, 'k' => $klub, 's' => $stary];
        self::dodaj($plan, "mecz {$mecz}: club_id {$stary} → {$klub}", 'matches', 'id = :m', ['m' => $mecz],
            'UPDATE matches SET club_id = :k WHERE id = :m');
        self::dodaj($plan, "mecz {$mecz}: club_home_id {$stary} → {$klub}", 'matches', 'id = :m AND club_home_id = :s',
            ['m' => $mecz, 's' => $stary], 'UPDATE matches SET club_home_id = :k WHERE id = :m AND club_home_id = :s');
        self::dodaj($plan, "mecz {$mecz}: club_away_id {$stary} → {$klub}", 'matches', 'id = :m AND club_away_id = :s',
            ['m' => $mecz, 's' => $stary], 'UPDATE matches SET club_away_id = :k WHERE id = :m AND club_away_id = :s');
        // Parametry per zapytanie: MySQL przy natywnym przygotowaniu nie przyjmuje
        // symbolu, którego zapytanie nie używa.
        foreach ($plan['zapytania'] as &$z) {
            $z['p'] = array_intersect_key($p, array_flip(self::symbole($z['sql'])));
        }
        unset($z);

        $raporty = (int) Db::one('SELECT COUNT(*) AS c FROM reports WHERE match_id = :m AND club_id = :s', ['m' => $mecz, 's' => $stary])['c'];
        $sklad = (int) Db::one('SELECT COUNT(*) AS c FROM match_players WHERE match_id = :m AND club_id = :s', ['m' => $mecz, 's' => $stary])['c'];
        if ($raporty > 0 || $sklad > 0) {
            $plan['uwagi'][] = "mecz {$mecz}: {$raporty} raport(ów) i {$sklad} wpis(ów) składu zostaje przy klubie {$stary} (bez zmian, zgodnie z zamówieniem)";
        }
        return $plan;
    }

    /** @return list<string> */
    private static function symbole(string $sql): array
    {
        preg_match_all('/:([a-z]\w*)/', $sql, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Usunięcie klubu — wyłącznie takiego, do którego nic nie prowadzi.
     *
     * Mecze usuwane i przepinane W TYM SAMYM PRZEBIEGU się nie liczą: rywal
     * testowy bywa podpięty właśnie pod mecz, który idzie do kosza. Przepięty
     * mecz przestaje wskazywać klub tylko wtedy, gdy klub był jego `club_id`
     * (tylko te odwołania przepięcie podmienia).
     *
     * @param list<int> $usuwaneMecze
     * @param list<int> $przepinaneMecze
     */
    public static function planUsunKlub(int $klub, array $usuwaneMecze = [], array $przepinaneMecze = []): array
    {
        $plan = self::pustyPlan();
        $k = Db::one('SELECT id, name, crest_path FROM clubs WHERE id = :id', ['id' => $klub]);
        if ($k === null) {
            $plan['bledy'][] = "klub {$klub} nie istnieje";
            return $plan;
        }

        $pomin = $usuwaneMecze;
        foreach ($przepinaneMecze as $m) {
            $w = Db::one('SELECT club_id FROM matches WHERE id = :m', ['m' => $m]);
            if ($w !== null && (int) $w['club_id'] === $klub) {
                $pomin[] = (int) $m;
            }
        }
        [$inX, $pX] = self::lista('x', $pomin !== [] ? $pomin : [0]);
        [$inU, $pU] = self::lista('u', $usuwaneMecze !== [] ? $usuwaneMecze : [0]);

        $mecze = (int) Db::one(
            "SELECT COUNT(*) AS c FROM matches WHERE (club_id = :a OR club_home_id = :b OR club_away_id = :c) AND id NOT IN ({$inX})",
            ['a' => $klub, 'b' => $klub, 'c' => $klub] + $pX
        )['c'];
        if ($mecze > 0) {
            $plan['bledy'][] = "klub {$klub} ({$k['name']}) ma mecze: {$mecze} — najpierw je usuń albo przepnij";
        }
        $zalezne = [
            'raporty'          => "SELECT COUNT(*) AS c FROM reports WHERE club_id = :k AND match_id NOT IN ({$inU})",
            'linki publiczne'  => "SELECT COUNT(*) AS c FROM share_links s WHERE s.club_id = :k
                                    AND s.report_id NOT IN (SELECT id FROM reports WHERE match_id IN ({$inU}))",
            'wpisy składu'     => "SELECT COUNT(*) AS c FROM match_players WHERE club_id = :k AND match_id NOT IN ({$inU})",
        ];
        foreach ($zalezne as $opis => $sql) {
            $ile = (int) Db::one($sql, ['k' => $klub] + $pU)['c'];
            if ($ile > 0) {
                $plan['bledy'][] = "klub {$klub} ma {$opis}: {$ile}";
            }
        }
        if ($plan['bledy'] !== []) {
            return $plan;
        }

        $p = ['k' => $klub];
        self::dodaj($plan, 'templaty klubu', 'club_report_templates', 'club_id = :k', $p, 'DELETE FROM club_report_templates WHERE club_id = :k');
        self::dodaj($plan, 'tagi ignorowane', 'club_ignored_tags', 'club_id = :k', $p, 'DELETE FROM club_ignored_tags WHERE club_id = :k');
        self::dodaj($plan, 'profile mapowań', 'mapping_profiles', 'club_id = :k', $p, 'DELETE FROM mapping_profiles WHERE club_id = :k');
        self::dodaj($plan, 'katalog tagów', 'tag_catalog', 'club_id = :k', $p, 'DELETE FROM tag_catalog WHERE club_id = :k');
        self::dodaj($plan, 'hasła indeksu', 'index_terms', 'club_id = :k', $p, 'DELETE FROM index_terms WHERE club_id = :k');
        self::dodaj($plan, 'notatki klubu', 'notes', 'club_id = :k', $p, 'DELETE FROM notes WHERE club_id = :k');
        self::dodaj($plan, 'prośby o pojęcie: odpięcie klubu', 'concept_requests', 'club_id = :k', $p,
            'UPDATE concept_requests SET club_id = NULL WHERE club_id = :k');
        self::dodaj($plan, 'powiadomienia o klubie', 'notifications', "entity = 'club' AND entity_id = :k", $p,
            "DELETE FROM notifications WHERE entity = 'club' AND entity_id = :k");
        self::dodaj($plan, "klub {$klub} ({$k['name']})", 'clubs', 'id = :k', $p, 'DELETE FROM clubs WHERE id = :k');
        if ((string) ($k['crest_path'] ?? '') !== '') {
            $plan['pliki'][] = (string) $k['crest_path'];
        }
        return $plan;
    }

    /** Raporty meczu poza najnowszym (po `generated_at`, przy remisie po `id`). */
    public static function planTylkoNajnowszy(int $mecz): array
    {
        $plan = self::pustyPlan();
        $raporty = Db::all(
            'SELECT id FROM reports WHERE match_id = :m ORDER BY generated_at DESC, id DESC',
            ['m' => $mecz]
        );
        if ($raporty === []) {
            $plan['bledy'][] = "mecz {$mecz} nie ma raportów";
            return $plan;
        }
        $plan['uwagi'][] = "mecz {$mecz}: zostaje raport {$raporty[0]['id']}";
        $stare = array_map('intval', array_column(array_slice($raporty, 1), 'id'));
        if ($stare !== []) {
            $zadania = self::zadania([], [], $stare);
            self::planRaportow($plan, $stare);
            if ($zadania !== []) {
                [$inJ, $pJ] = self::lista('j', $zadania);
                self::dodaj($plan, 'zadania kolejki usuwanych raportów', 'jobs', "id IN ({$inJ})", $pJ, "DELETE FROM jobs WHERE id IN ({$inJ})");
            }
        }
        return $plan;
    }

    /** Scalenie planów w kolejności podania. */
    public static function scal(array ...$plany): array
    {
        $out = self::pustyPlan();
        foreach ($plany as $p) {
            foreach ($out as $k => $_) {
                $out[$k] = array_merge($out[$k], $p[$k]);
            }
        }
        $out['pliki'] = array_values(array_unique($out['pliki']));
        $out['katalogi'] = array_values(array_unique($out['katalogi']));
        return $out;
    }

    /**
     * Wykonanie planu: zapytania w JEDNEJ transakcji, pliki po commicie.
     *
     * @return array{pliki:int,pominiete:list<string>}
     */
    public static function wykonaj(array $plan): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($plan['zapytania'] as $z) {
                Db::run($z['sql'], $z['p']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $usuniete = 0;
        $pominiete = [];
        foreach ($plan['pliki'] as $plik) {
            if (!is_file($plik)) {
                continue;
            }
            if (!Storage::isInside($plik)) {
                $pominiete[] = $plik . ' (poza STORAGE_PATH — nie ruszam)';
                continue;
            }
            if (@unlink($plik)) {
                $usuniete++;
            }
        }
        foreach ($plan['katalogi'] as $kat) {
            if (!is_dir($kat)) {
                continue;
            }
            if (!Storage::dirInside($kat . '/x')) {
                $pominiete[] = $kat . ' (poza STORAGE_PATH — nie ruszam)';
                continue;
            }
            foreach (glob($kat . '/*') ?: [] as $plik) {
                if (is_file($plik) && @unlink($plik)) {
                    $usuniete++;
                }
            }
            @rmdir($kat);
        }
        return ['pliki' => $usuniete, 'pominiete' => $pominiete];
    }
}
