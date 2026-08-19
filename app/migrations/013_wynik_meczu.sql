-- 013 — Wynik meczu i znacznik domknięcia diffu (Sesja 6 przebudowy).
--
-- Formularz meta meczu (docs/PRZEBUDOWA_KLUB_SESJE.md, Sesja 6 pkt 1) zbiera
-- przeciwnika, datę, dom/wyjazd, sezon i WYNIK. Cztery pierwsze mają już swoje
-- kolumny (`club_away_id`, `played_at`, `is_home` z migracji 012, `season_id`),
-- wyniku nie było gdzie zapisać.
--
-- MIGRACJA CZYSTO ADDYTYWNA: dwa ALTER ADD, zero DROP, zero DELETE, zero zmiany
-- typów kolumn istniejących. Ta sama zasada co w 012.
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction -u USER -p BAZA > backup_przed_013_$(date +%F).sql
--   mysql -u USER -p BAZA < app/migrations/013_wynik_meczu.sql


-- ===========================================================================
-- DWIE KOLUMNY, NIE JEDNO POLE TEKSTOWE
-- ===========================================================================
--
-- Kuszące byłoby `result VARCHAR(10)` i wpisywanie „3:1". Odpada z dwóch
-- powodów, oba wychodzą dopiero później:
--
--  1. Porównania sezonowe (moduł M4) muszą sumować bramki. Z napisu trzeba by
--     je za każdym razem parsować, a format „3:1" / „3-1" / „3 : 1" rozjeżdża
--     się natychmiast, bo wpisuje go człowiek.
--  2. Strona „nasza" i „ich" to w tym projekcie pojęcia z kontraktu silnika
--     (`us` / `them`), nie strony boiska. Napis „3:1" nie mówi, która liczba
--     jest czyja, dopóki nie zna się `is_home` — a `is_home` bywa NULL, bo
--     eksport LiveTag nie niesie gospodarza i nie wolno go zgadywać.
--
-- Nazwy kolumn idą więc za `club_home_id`/`club_away_id`, czyli za stronami
-- `us`/`them` z docs/KONTRAKT_CLI.md, a nie za gospodarzem i gościem.
--
-- NULL ZNACZY „NIE WIEMY" i jest wartością poprawną dla całej historii:
-- mecze zaimportowane przed tą migracją nie mają skąd wziąć wyniku, a zero
-- byłoby konkretnym rezultatem 0:0, nie do odróżnienia od braku danych
-- (CLAUDE.md §8 — brak danych ma być widoczny, nie zamaskowany).
--
-- TINYINT UNSIGNED (0–255) w zupełności wystarcza na wynik meczu piłkarskiego
-- i wyklucza wpisanie liczby, która nie jest bramkami.

ALTER TABLE matches
  ADD COLUMN score_us INT UNSIGNED NULL
    COMMENT 'bramki strony `us` (klub-tenant); NULL = nie wiemy',
  ADD COLUMN score_them INT UNSIGNED NULL
    COMMENT 'bramki strony `them` (rywal); NULL = nie wiemy';


-- ===========================================================================
-- ZNACZNIK DOMKNIĘCIA DIFFU
-- ===========================================================================
--
-- Ekran „Nowe tagi w tym imporcie" (Sesja 6) daje trzy akcje, a JEDNA Z NICH
-- NICZEGO NIE ZAPISUJE: „pomiń w tym imporcie" jest z definicji ulotna —
-- przy następnym eksporcie chcemy o ten tag zapytać ponownie.
--
-- Bez osobnego znacznika taka pozycja jest przy KAŻDYM wejściu na pokrycie
-- znowu „nowa", więc ekran pokrycia odsyła na diff w kółko i operator, który
-- cokolwiek pominął, NIGDY nie dojdzie do przycisku „Generuj". Złapał to
-- przelot HTTP (`test_import_n1_http.php`).
--
-- Znacznik mówi „człowiek widział ten diff i zdecydował", a nie „nie ma już
-- nic nowego". Pozycje pominięte zostają wyliczone w raporcie pokrycia i da
-- się do diffu wrócić odsyłaczem — decyzja jest odwracalna, tylko nie
-- wymuszana przy każdym odświeżeniu.
--
-- DATETIME, nie flaga: „kiedy" bywa potrzebne przy rozstrzyganiu, czy decyzja
-- zapadła przed czy po zmianie templatu, a flaga tej odpowiedzi nie niesie.

ALTER TABLE imports
  ADD COLUMN diff_done_at DATETIME NULL
    COMMENT 'kiedy operator domknal ekran nowych tagow; NULL = jeszcze nie widzial';


-- ===========================================================================
-- KONTROLA PO URUCHOMIENIU
-- ===========================================================================
--
-- Obie kolumny mają istnieć i być puste dla całej historii — migracja niczego
-- nie backfilluje, bo wyniku starych meczów nie ma skąd wziąć.

SELECT COUNT(*)                    AS meczow_ogolem,
       SUM(score_us IS NOT NULL)   AS z_wynikiem,
       SUM(score_us IS NULL)       AS bez_wyniku
  FROM matches;

SELECT COUNT(*)                       AS importow_ogolem,
       SUM(diff_done_at IS NOT NULL)  AS z_domknietym_diffem
  FROM imports;
