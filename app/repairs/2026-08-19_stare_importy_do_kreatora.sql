-- Stare importy: ponowna inspekcja, żeby kreator mapowań przestał je omijać.
--
-- Podstawa: docs/STAN_PRAC.md §4.2a. Objaw z produkcji: puste `mapping_profiles`,
-- `mapping_profile_id` NULL, mecze 4 i 5 klubu 7 przechodzą do generowania
-- z pominięciem kreatora — mimo że kod z repozytorium zatrzymuje na nim poprawnie
-- (przelot HTTP `app/tests/integracja/test_mapowania_http.php`, scenariusz 4).
--
-- ============================================================================
-- PO CO TO ISTNIEJE — CZEGO SAMO WDROŻENIE NIE NAPRAWIA
-- ============================================================================
--
-- Usterka miała dwie warstwy i tylko PIERWSZĄ leczy wgranie nowego kodu:
--
--  1. Stary `Imports::saveInspection()` zapisywał do `coverage_json` wyłącznie
--     obiekt `coverage`, bez `unmapped_tags` i `unmapped_labels`. Te pola leżą
--     w `meta.json` NA NAJWYŻSZYM POZIOMIE, obok `coverage`, a nie w środku.
--     `needsMapping()` zawsze widziało więc pustkę. Naprawione w kodzie.
--
--  2. ISTNIEJĄCE WIERSZE NIE NAPRAWIĄ SIĘ SAME. Ich `coverage_json` zapisał
--     stary kod i nic go nie odświeża. „Wygeneruj ponownie" przechodzi bez
--     zatrzymania (render bez profilu mapowań), a wpis uzupełnia się dopiero
--     PO tym renderze — czyli za późno, bo raport powstał już niepełny.
--
-- Ten skrypt robi to samo, co przycisk „Ponów" na zadaniu `inspect`, tylko
-- zbiorczo: wstawia zadanie inspekcji dla każdego importu ze starym zapisem.
-- Inspekcję podnosi cron w ciągu minuty i nadpisuje `coverage_json` w nowym
-- formacie. Od tego momentu kreator zatrzymuje na tych importach normalnie.
--
-- ============================================================================
-- ZGODNOŚĆ ZE SCHEMATEM PO MIGRACJI 012 — SPRAWDZONE
-- ============================================================================
--
-- Migracja 012 (klub jako tenant) NIE DOTYKA ani `jobs`, ani `imports`.
-- Dokłada `club_id`/`is_home` do `matches` oraz `club_id`/`template_version`
-- do `reports`. Ten skrypt pisze wyłącznie do `jobs`, a czyta z `imports` —
-- obie tabele w kształcie sprzed 012.
--
--  - `jobs.type` to VARCHAR(40), nie ENUM — wartość 'inspect' przechodzi.
--  - `jobs.payload_json` jest JSON NOT NULL; `CONCAT` niżej składa dokładnie
--    ten sam kształt, który zapisuje `Imports::queueInspect()`:
--    {"import_id":N,"match_id":M} — bez spacji, klucze w tej kolejności.
--  - `jobs.available_at` (migracja 006) zostawiamy NULL. Proces roboczy wybiera
--    zadania warunkiem `status = 'queued' AND (available_at IS NULL OR
--    available_at <= :teraz)` (app/bin/run_job.php), więc NULL znaczy
--    „gotowe od zaraz".
--  - `matches.club_id` NOT NULL nie jest zagrożone: ponowna inspekcja NIE
--    wstawia wierszy do `matches`, tylko aktualizuje istniejące.
--
-- JEDNA RZECZ WYMAGA SPRAWDZENIA PO URUCHOMIENIU I DLATEGO JEST KROK 3.
-- `saveInspection()` woła `assignClubs()`, które przelicza `club_home_id`
-- i `club_away_id` z nazw drużyn wykrytych w eksporcie. Migracja 012 przyjęła
-- inwariant przejściowy `matches.club_id = matches.club_home_id` dla całej
-- historii. Gdyby dopasowanie nazw dało dziś INNY klub niż przy pierwotnym
-- imporcie — bo w międzyczasie przybył drugi klub własny albo zmieniły się
-- aliasy — `club_home_id` zmieni się, a `club_id` zostanie na wartości
-- z backfillu i inwariant cicho pęknie. Krok 3 to wykrywa.
--
-- ============================================================================
-- URUCHOMIENIE
-- ============================================================================
--
--   mysqldump --single-transaction -u USER -p BAZA > backup_przed_naprawa_$(date +%F).sql
--   mysql -u USER -p BAZA < app/repairs/2026-08-19_stare_importy_do_kreatora.sql
--
-- WYMAGA WDROŻONEGO NOWEGO KODU. Uruchomiony na starym kodzie zakolejkuje
-- inspekcje, które zapiszą `coverage_json` w tym samym starym formacie —
-- czyli nie naprawi niczego i trzeba będzie powtórzyć.
--
-- POWTÓRZENIE JEST BEZPIECZNE. Po udanej inspekcji `coverage_json` zawiera już
-- `unmapped_tags`, więc warunek z KROKU 2 przestaje ten wiersz obejmować.
-- Dodatkowo pomijamy importy, dla których zadanie inspekcji już czeka albo
-- właśnie się wykonuje — inaczej dwa uruchomienia w tej samej minucie
-- (przed przejściem crona) zakolejkowałyby tę samą pracę dwa razy.


-- ===========================================================================
-- KROK 1 — PODGLĄD: czego dotknie naprawa
-- ===========================================================================
--
-- Wynik przeczytać PRZED przejściem dalej. Pusty wynik znaczy, że nie ma czego
-- naprawiać — i to jest poprawny stan po udanym uruchomieniu.
--
-- `csv_path` jest tu nie bez powodu: jeśli plik zniknął z dysku, inspekcja
-- padnie, a `run_job.php` ustawi wtedy `matches.status = 'failed'` — także dla
-- meczu, który dziś ma gotowy raport i status `done`. Sprawdź, czy wypisane
-- ścieżki istnieją, zanim uruchomisz KROK 2.

SELECT i.id                                        AS import_id,
       i.match_id,
       m.club_id                                   AS tenant,
       m.club_home_id,
       m.status                                    AS status_meczu,
       i.created_at                                AS import_z_dnia,
       i.csv_path,
       CASE WHEN i.mapping_profile_id IS NULL
            THEN 'brak profilu' ELSE CONCAT('profil ', i.mapping_profile_id)
       END                                         AS profil_mapowan
  FROM imports i
  JOIN matches m ON m.id = i.match_id
 WHERE i.coverage_json IS NOT NULL
   AND i.coverage_json NOT LIKE '%unmapped_tags%'
 ORDER BY i.match_id, i.id;


-- ===========================================================================
-- KROK 2 — NAPRAWA: zadanie inspekcji dla każdego starego importu
-- ===========================================================================
--
-- Zakres wyznacza SYGNATURA USTERKI (`coverage_json` bez `unmapped_tags`),
-- a nie lista identyfikatorów. Wpisanie „mecze 4 i 5" na sztywno przeoczyłoby
-- każdy inny import z tamtego okresu, a takich może być więcej niż te, które
-- ktoś zauważył. Gdyby jednak zależało na operacji chirurgicznej, dopisz
-- w warunku:  AND i.match_id IN (4, 5)
--
-- PODZAPYTANIE O `jobs` SIEDZI W TABELI POCHODNEJ (`AS biezace`) ZAPOBIEGAWCZO.
-- Samo czytanie tabeli docelowej w `INSERT ... SELECT` jest dozwolone i
-- udokumentowane: serwer zbiera wtedy wynik `SELECT` do tabeli tymczasowej,
-- zanim cokolwiek wstawi. Ograniczenie „You can't specify target table … for
-- update in FROM clause" (błąd 1093) dotyczy `UPDATE` i `DELETE`, nie tego
-- przypadku. Tabela pochodna wypisuje tę materializację wprost, zamiast
-- polegać na tym, że każda wersja serwera rozstrzygnie ją tak samo — kosztuje
-- jedną linijkę i zdejmuje pytanie „a czy na pewno".
--
-- Dopasowanie po `payload_json LIKE` zamiast funkcji JSON: składnia funkcji
-- JSON różni się między wersjami MariaDB, a wzorzec z przecinkiem na końcu
-- (`"import_id":4,`) NIE złapie `"import_id":40,` — sprawdzone na czterech
-- kombinacjach, razem z odwrotną. Wzorzec bez przecinka byłby cichym błędem:
-- pomijałby import 4 za każdym razem, gdy w kolejce stoi import 4x.

INSERT INTO jobs (type, payload_json, status, attempts, created_at)
SELECT 'inspect',
       CONCAT('{"import_id":', i.id, ',"match_id":', i.match_id, '}'),
       'queued',
       0,
       NOW()
  FROM imports i
 WHERE i.coverage_json IS NOT NULL
   AND i.coverage_json NOT LIKE '%unmapped_tags%'
   AND NOT EXISTS (
         SELECT 1
           FROM (SELECT type, status, payload_json FROM jobs) AS biezace
          WHERE biezace.type = 'inspect'
            AND biezace.status IN ('queued', 'running')
            AND biezace.payload_json LIKE CONCAT('%"import_id":', i.id, ',%')
       );


-- ===========================================================================
-- KROK 3 — KONTROLA (uruchomić PO przejściu crona, czyli po ~2 minutach)
-- ===========================================================================

-- 3a. Ile zadań czeka i ile się nie powiodło. `failed` z tej partii oznacza
--     najczęściej brak pliku CSV na dysku — powód stoi w `error_text`.
SELECT status, COUNT(*) AS ile
  FROM jobs
 WHERE type = 'inspect'
   AND created_at >= NOW() - INTERVAL 1 HOUR
 GROUP BY status;

-- 3b. Czy zostały jeszcze importy w starym formacie. Docelowo: pusty wynik.
SELECT i.id AS import_id, i.match_id, i.engine_version
  FROM imports i
 WHERE i.coverage_json IS NOT NULL
   AND i.coverage_json NOT LIKE '%unmapped_tags%';

-- 3c. INWARIANT MIGRACJI 012: `club_id = club_home_id` wszędzie, gdzie strona
--     „nasza" jest znana. Docelowo pusty wynik.
--
--     Niepusty NIE znaczy „naprawa się nie udała" — znaczy, że ponowne
--     dopasowanie nazw drużyn wskazało inny klub niż ten, który przejął mecz
--     przy backfillu 012. Wtedy trzeba rozstrzygnąć RĘCZNIE, który jest
--     właściwy, bo `club_id` (właściciel analizy) i `club_home_id` (strona
--     „nasza" w kontrakcie silnika) to dwa różne pojęcia i automat nie ma
--     podstaw, żeby wybrać za człowieka.
SELECT m.id            AS match_id,
       m.club_id       AS tenant,
       m.club_home_id  AS strona_nasza,
       ch.name         AS nazwa_tenanta,
       chh.name        AS nazwa_strony_naszej
  FROM matches m
  LEFT JOIN clubs ch  ON ch.id  = m.club_id
  LEFT JOIN clubs chh ON chh.id = m.club_home_id
 WHERE m.club_home_id IS NOT NULL
   AND m.club_id <> m.club_home_id;

-- 3d. Mecze, które przy okazji zmieniły status na `failed` — czyli takie,
--     dla których inspekcja padła. Docelowo pusty wynik.
SELECT m.id AS match_id, m.status, j.error_text
  FROM matches m
  JOIN imports i ON i.match_id = m.id
  JOIN jobs j    ON j.payload_json LIKE CONCAT('%"import_id":', i.id, ',%')
 WHERE m.status = 'failed'
   AND j.type = 'inspect'
   AND j.status = 'failed'
   AND j.created_at >= NOW() - INTERVAL 1 HOUR;
