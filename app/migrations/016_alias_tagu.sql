-- 016 — Alias tagu w katalogu klubu (sesja 5 pivotu „viewer").
--
-- MIGRACJA CZYSTO ADDYTYWNA: jedna nowa kolumna i jeden indeks. Zero DROP,
-- zero DELETE, zero zmiany typów kolumn istniejących (app/migrations/README.md).
-- `pro` i `viewer` dzielą jeden schemat, więc powrót do `pro` zostaje decyzją
-- produktową, a nie operacją na danych.
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
--     serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_016_$(date +%F).sql
--   mysql --defaults-group-suffix=caproba serwer400227_caproba < app/migrations/016_alias_tagu.sql
--   mysql serwer400227_coachanalyze < app/migrations/016_alias_tagu.sql


-- ===========================================================================
-- PO CO TA KOLUMNA
-- ===========================================================================
--
-- Klub zmienia nazwę taga między sezonami: `SBZ PODAJĄCY` staje się
-- `ZDOBYCIE SBZ`. Do tej pory katalog widział dwa różne tagi, a porównanie
-- sezonowe pokazywało dwie serie zamiast jednej — i nic nie mówiło, że to
-- ta sama rzecz pod inną nazwą.
--
-- `alias_of` zapisuje decyzję człowieka z ekranu różnic importu: „ten nowy tag
-- JEST KONTYNUACJĄ tamtej zmiennej". Trzy rzeczy, które warto wiedzieć:
--
-- 1. TO NIE JEST MAPOWANIE KANONICZNE. Pojęcie (`shot`, `entry_sbz`) mówi, CZYM
--    zdarzenie jest w modelu; alias mówi, ŻE TO TA SAMA ZMIENNA KLUBU pod inną
--    nazwą. Klub bez ani jednego pojęcia kanonicznego (stan normalny od sesji 1,
--    docs/STAN_PIVOTU.md §2.3) i tak potrzebuje aliasów.
--
-- 2. KATALOG NICZEGO NIE SCALA. Wiersz starego tagu ZOSTAJE ze swoimi licznikami
--    — katalog jest historią tagowania, nie zdjęciem stanu bieżącego. Scalanie
--    przy liczeniu robi szablon raportu, na podstawie `variables[].aliases`
--    w templacie; ta kolumna jest zapisem decyzji i źródłem dla ekranów.
--
-- 3. TRZYMAMY NAZWĘ, NIE `id`. Wiersz docelowy może jeszcze nie istnieć
--    w katalogu tego klubu (tag pojawił się dopiero w nowym eksporcie), a klucz
--    obcy wymagałby wtedy kolejności wstawiania, której import nie kontroluje.
--    Nazwa jest tym, po czym i tak liczą raport i templat.

ALTER TABLE tag_catalog
  ADD COLUMN alias_of VARCHAR(160) NULL
      COMMENT 'Nazwa zmiennej, której ten tag jest kontynuacją (sesja 5). NULL = tag samodzielny';

-- Indeks po (klub, alias_of): ekrany pytają „co jest aliasem czego W TYM KLUBIE",
-- nigdy „gdzie w całej bazie występuje ta nazwa".
CREATE INDEX idx_tag_catalog_alias ON tag_catalog (club_id, alias_of);
