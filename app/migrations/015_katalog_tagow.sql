-- 015 — Katalog tagów klubu (sesja 3 pivotu „viewer").
--
-- MIGRACJA CZYSTO ADDYTYWNA: jedno CREATE TABLE. Zero DROP, zero DELETE,
-- zero zmiany typów kolumn istniejących (app/migrations/README.md).
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
--     serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_015_$(date +%F).sql
--   mysql --defaults-group-suffix=caproba serwer400227_caproba < app/migrations/015_katalog_tagow.sql
--   mysql serwer400227_coachanalyze < app/migrations/015_katalog_tagow.sql


-- ===========================================================================
-- WYMAGANIA WOBEC SERWERA BAZY — SPRAWDŹ PRZED URUCHOMIENIEM
-- ===========================================================================
--
-- Metryki sesji 3 filtrują po etykietach zdarzenia zapisanych w `events.labels_json`.
-- Na MariaDB robi to `JSON_CONTAINS()`, które wymaga **MariaDB 10.2.3+**.
--
--   SELECT VERSION();                        -- ma być >= 10.2.3
--   SELECT JSON_CONTAINS('["A","B"]', '"A"');-- ma zwrócić 1
--
-- UWAGA NA PUŁAPKĘ MARIADB: `JSON` jest tam ALIASEM NA `LONGTEXT`, nie osobnym
-- typem jak w MySQL 5.7+. Kolumna przyjmie więc dowolny napis, także niepoprawny
-- JSON, a `JSON_CONTAINS()` zwróci wtedy NULL zamiast błędu — i metryka po cichu
-- policzy zero. Dlatego `events.labels_json` wypełnia WYŁĄCZNIE `Events::wartosc()`
-- przez `json_encode()`, nigdy sklejanie napisów.
--
-- Gdyby serwer okazał się starszy niż 10.2.3, `Metrics` ma wariant zapasowy
-- oparty na `LIKE` — patrz komentarz przy `Metrics::warunekEtykiety()`.
-- Jest ZGRUBNY i nie rozróżnia etykiety `PRESSING` od `PRESSING WYSOKI`,
-- więc to wyjście awaryjne, nie równoważne rozwiązanie.


-- ===========================================================================
-- KATALOG TAGÓW: CO KLUB W OGÓLE TAGUJE
-- ===========================================================================
--
-- PO CO TO ISTNIEJE. Metryka liczy po surowych nazwach tagów (`ZDOBYCIE SBZ`),
-- a każdy klub nazywa zdarzenia po swojemu. Dziś odpowiedź na pytanie „czy ten
-- klub w ogóle taguje SBZ" wymaga przejrzenia wszystkich jego zdarzeń.
--
-- Bez tej tabeli metryka bez danych wygląda DOKŁADNIE TAK SAMO jak metryka
-- o wartości zero: raport pokazuje „0 wejść w SBZ" i nikt się nie dowiaduje,
-- że klub po prostu nie ma takiego taga. Katalog pozwala odróżnić „policzono
-- zero" od „nie ma czego liczyć" — pierwsze jest wynikiem, drugie brakiem
-- pokrycia i trafia do „Wymaga uwagi" (CLAUDE.md §8).
--
-- ŹRÓDŁEM SĄ DANE, KTÓRE JUŻ MAMY: `meta.dictionary` (histogram tagów
-- i etykiet z liczbą wystąpień) oraz `meta.palette` (barwy z pliku projektu
-- LiveTag). Silnik liczy je od wersji 0.10.0 i 0.11.0 — ta migracja niczego
-- nie każe liczyć od nowa, tylko daje im miejsce do zapisania.

CREATE TABLE tag_catalog (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- KATALOG JEST PER KLUB, nie globalny. Ten sam napis znaczy u dwóch klubów
  -- co innego, a metryka liczy po nazwie — katalog wspólny sklejałby słowniki
  -- i pokazywał klubowi tagi, których nigdy nie użył.
  club_id  INT UNSIGNED NOT NULL,

  -- TAG czy ETYKIETA. Tag to RODZAJ zdarzenia (`STRZAŁ`), etykieta jego
  -- uszczegółowienie (`CELNY`). Rozdzielone, bo ta sama nazwa bywa jednym
  -- i drugim: `STRZAŁ` jest tagiem, ale też etykietą przy `SBZ PODAJĄCY`.
  kind     ENUM('tag','label') NOT NULL,
  name     VARCHAR(120) NOT NULL,

  -- Barwa z pliku projektu LiveTag (po korekcie jasności `to_hex`). NULL, gdy
  -- import szedł bez pliku projektu — to poprawny stan, nie brak do załatania.
  color    CHAR(7) NULL,

  -- KIEDY ZOBACZYLIŚMY TO PIERWSZY I OSTATNI RAZ. Tag, który zniknął z eksportów
  -- trzy miesiące temu, nadal jest w katalogu — i właśnie to ma być widoczne,
  -- bo znaczy, że klub zmienił sposób tagowania.
  first_seen_import_id INT UNSIGNED NULL,
  last_seen_import_id  INT UNSIGNED NULL,

  -- Liczniki narastające. `seen_matches` mówi, w ilu meczach tag wystąpił —
  -- tag z jednego meczu to prawdopodobnie eksperyment, a nie metodyka klubu.
  seen_matches INT UNSIGNED NOT NULL DEFAULT 0,
  seen_events  INT UNSIGNED NOT NULL DEFAULT 0,

  updated_at DATETIME NULL,

  -- Klucz naturalny. Na nim stoi UPSERT przy każdym imporcie: ten sam tag
  -- w kolejnym meczu ma podbić liczniki, a nie założyć drugi wiersz.
  UNIQUE KEY uq_tag_catalog (club_id, kind, name),

  CONSTRAINT fk_tag_catalog_club  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tag_catalog_first FOREIGN KEY (first_seen_import_id) REFERENCES imports(id),
  CONSTRAINT fk_tag_catalog_last  FOREIGN KEY (last_seen_import_id)  REFERENCES imports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===========================================================================
-- KONTROLA PO URUCHOMIENIU
-- ===========================================================================
--
-- Tabela ma istnieć i być pusta — migracja niczego nie backfilluje. Katalog
-- zapełni się przy pierwszym imporcie albo przeliczeniu raportu po wdrożeniu.
--
-- Backfill z istniejących `imports.coverage_json` BYŁBY MOŻLIWY (blok
-- `dictionary` tam leży), ale świadomie go tu nie ma: zapełniony katalog bez
-- ani jednego przebiegu nowego kodu wygląda jak działający mechanizm, którego
-- nikt nie uruchomił. Lepiej, żeby pierwszy wpis powstał tą samą drogą,
-- co wszystkie następne.

SELECT COUNT(*) AS pozycji_w_katalogu FROM tag_catalog;

-- Wersja serwera — do odnotowania w dzienniku wdrożenia.
SELECT VERSION() AS wersja_serwera;
