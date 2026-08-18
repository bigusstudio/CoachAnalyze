-- 012 — Klub jako tenant: templaty raportów, tagi ignorowane na stałe, `club_id` w historii.
--
-- Pierwsza migracja przebudowy pod wiele klubów (docs/PRZEBUDOWA_KLUB_SESJE.md, Sesja 1).
-- Stan sprzed przebudowy jest otagowany `baza-1.0`. ZAKAZ MIESZANIA: kod z tego taga
-- nie działa na bazie po tej migracji, bo `matches.club_id` jest NOT NULL. Powrót =
-- checkout taga ORAZ restore dumpa, nigdy samo jedno.
--
-- MIGRACJA JEST CZYSTO ADDYTYWNA: CREATE TABLE, ALTER ADD, UPDATE backfill.
-- Ani jednego DROP, DELETE ani zmiany typu kolumny, która istniała przed tym plikiem.
--
-- JEDEN WYJĄTEK, ŚWIADOMY: `matches.club_id` jest dokładany jako NULL, backfillowany,
-- i dopiero POTEM zaciskany do NOT NULL. To `MODIFY` na kolumnie założonej trzy
-- instrukcje wyżej, w tym samym pliku — nie na kolumnie zastanej. Inaczej się nie da:
-- wartości nie znamy w chwili DDL, a `NOT NULL` bez wartości domyślnej wpisałby zera,
-- czyli identyfikator klubu, który nie istnieje.
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction -u USER -p BAZA > backup_przed_012_$(date +%F).sql
--   mysql -u USER -p BAZA < app/migrations/012_kluby_templaty.sql
--
-- Zalecane: najpierw przebieg na kopii z dumpa, dopiero potem produkcja.


-- ===========================================================================
-- STAŁA DO PODMIANY
-- ===========================================================================
--
-- Klub, który przejmuje CAŁĄ dotychczasową historię (mecze, raporty, notatki).
-- NIE tworzymy tu nowego klubu: tabela `clubs` istnieje od migracji 001 i wiersz
-- klienta już w niej jest. Założenie drugiego zduplikowałoby podmiot i podpięło
-- historię pod niewłaściwy.
--
-- Wartość to `club_key` z tabeli `clubs` — dziesięcioznakowy klucz z adresów
-- publicznych. Podejrzysz go zapytaniem:
--
--   SELECT id, club_key, name, is_own_team FROM clubs ORDER BY is_own_team DESC, name;
--
SET @CA_CLUB_KEY = 'PODMIEN_MNIE';   -- PODMIEŃ PRZED URUCHOMIENIEM

SET @ca_club_id = (SELECT id FROM clubs WHERE club_key = @CA_CLUB_KEY);


-- ===========================================================================
-- BEZPIECZNIK — migracja ma paść TERAZ, a nie w połowie
-- ===========================================================================
--
-- Zła stała bez tego kroku oznaczałaby backfill, który nic nie dopasuje, i awarię
-- dopiero na zaciśnięciu NOT NULL — z komunikatem o NULL-ach, który nie mówi nic
-- o przyczynie. Tutaj przerwanie niesie instrukcję w treści błędu.
--
-- Konstrukcja przez PREPARE, bo MySQL rozwiązuje nazwy tabel przy parsowaniu:
-- odwołanie do nieistniejącej tabeli wprost wywaliłoby się ZAWSZE, także przy
-- poprawnej stałej. Zapytanie budowane warunkowo składa nazwę-komunikat tylko
-- wtedy, gdy klubu faktycznie nie ma.
SET @ca_kontrola = IF(
  @ca_club_id IS NULL,
  'SELECT * FROM `MIGRACJA_012_PRZERWANA__PODMIEN_STALA_CA_CLUB_KEY__NIE_MA_KLUBU_O_TYM_KLUCZU`',
  'SELECT 1'
);
PREPARE ca_kontrola_klubu FROM @ca_kontrola;
EXECUTE ca_kontrola_klubu;
DEALLOCATE PREPARE ca_kontrola_klubu;


-- ===========================================================================
-- 1. NOWE TABELE
-- ===========================================================================

-- Templat raportu klubu — WERSJONOWANY, dopisywany, nigdy nadpisywany.
--
-- Aktualny templat klubu to MAX(version). Nie ma flagi `is_active` i to jest
-- decyzja, nie przeoczenie: flaga pozwala mieć dwa aktywne wiersze albo zero,
-- a licznik nie pozwala na żadne z tych dwojga.
--
-- Stare wersje ZOSTAJĄ, ale NIE SĄ renderowalne (docs/PRZEBUDOWA_KLUB_SESJE.md,
-- Sesja 7): służą do wykrycia, że raport jest starszy niż templat, i do historii
-- zmian. Regeneracja idzie zawsze pod wersję najnowszą.
CREATE TABLE club_report_templates (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  club_id    INT UNSIGNED NOT NULL,
  version    INT UNSIGNED NOT NULL,           -- licznik od 1, ciągły w obrębie klubu
  config     JSON NOT NULL,                   -- struktura: Sesja 4, klucz `schema_version`
  created_by INT UNSIGNED NULL,               -- NULL dla templatu założonego przez system
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_template_version (club_id, version),
  -- Historia czytana jest zawsze od najnowszej — tak samo jak w mapping_profiles.
  KEY idx_template_history (club_id, version DESC),

  CONSTRAINT fk_template_club   FOREIGN KEY (club_id)    REFERENCES clubs(id),
  CONSTRAINT fk_template_author FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Tagi i etykiety zignorowane NA STAŁE dla klubu.
--
-- Osobna tabela, a nie pole w templacie, i to jest sedno: „nie pytaj mnie więcej
-- o ten tag" NIE JEST zmianą definicji raportu i nie ma prawa podbijać wersji
-- templatu. Gdyby siedziało w `config`, każde odhaczenie śmieciowego tagu przy
-- imporcie tworzyłoby nową wersję i unieważniało wszystkie dotychczasowe raporty
-- klubu jako „nieaktualne".
--
-- Zignorowane pozycje NIE ZNIKAJĄ po cichu — raport pokrycia wylicza je z nazwy
-- (Sesja 6). Zero cichego wyrzucania danych.
CREATE TABLE club_ignored_tags (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  club_id     INT UNSIGNED NOT NULL,
  source_type ENUM('tag','label') NOT NULL,
  raw_name    VARCHAR(190) NOT NULL,          -- nazwa z eksportu, bez normalizacji
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_ignored (club_id, source_type, raw_name),

  CONSTRAINT fk_ignored_club   FOREIGN KEY (club_id)    REFERENCES clubs(id),
  CONSTRAINT fk_ignored_author FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
-- 2. ROZSZERZENIA TABEL ISTNIEJĄCYCH
-- ===========================================================================

-- clubs — tabela istnieje od 001, dokładamy WYŁĄCZNIE dwie brakujące kolumny.
--
-- `crest_path` ZOSTAJE pod swoją nazwą. Specyfikacja przebudowy mówiła o
-- `logo_path`, ale zmiana nazwy to nie jest operacja addytywna i zerwałaby
-- `Clubs.php`, `Crest.php` oraz `serveCrest()` w kontrolerze. Jedna nazwa mniej
-- do pomylenia jest warta mniej niż działający herb.
ALTER TABLE clubs
  ADD COLUMN details JSON NULL
    COMMENT 'miasto, liga, sezon biezacy — pola opisowe, bez wplywu na metryki',
  ADD COLUMN updated_at DATETIME NULL
    COMMENT 'ostatnia edycja danych klubu';


-- matches — kolumna tenanta.
--
-- ŚWIADOMA REDUNDANCJA WZGLĘDEM `club_home_id`. Powód rozdziału:
--
--   club_id      = KTO JEST WŁAŚCICIELEM ANALIZY (tenant). Po tej kolumnie
--                  filtruje każde zapytanie w aplikacji.
--   club_home_id = KTO JEST „NAMI" w modelu meczu (strona `us` w kontrakcie
--                  silnika, docs/KONTRAKT_CLI.md).
--
-- Dziś obie wskazują ten sam klub i taki jest inwariant przejściowy dla całej
-- historii: club_id = club_home_id. Rozjadą się przy scoutingu — analizie meczu
-- dwóch obcych drużyn, gdzie tenant istnieje (to mój klub zamówił analizę),
-- a „nas" na boisku nie ma w ogóle.
--
-- REGUŁA DLA NOWEGO KODU: filtruj po `club_id`, nigdy po `club_home_id`.
--
-- `opponent_name` NIE POWSTAJE. Rywal zostaje wierszem w `clubs` wskazywanym
-- przez `club_away_id` — z herbem, barwami i `aliases_json`, które napędza
-- dopasowanie nazw z eksportu. Wolny tekst byłby drugim źródłem prawdy o tym
-- samym podmiocie i utratą aliasów.
--
-- `raw_csv_path` / `raw_json_path` NIE POWSTAJĄ. Ścieżki surowych eksportów są
-- w `imports` (`csv_path`, `json_path`) od migracji 001. Kopia w `matches`
-- rozjechałaby się z oryginałem przy pierwszym ponownym imporcie. Regeneracja
-- (Sesja 7) czyta ścieżki z NAJNOWSZEGO wiersza `imports` danego meczu.
ALTER TABLE matches
  ADD COLUMN club_id INT UNSIGNED NULL
    COMMENT 'tenant: wlasciciel analizy. Nowy kod filtruje po tej kolumnie',
  ADD COLUMN is_home TINYINT(1) NULL
    COMMENT 'mecz u siebie; NULL = nie wiemy. Wypelnia formularz meta, nigdy eksport';


-- reports — tenant i stempel wersji templatu.
--
-- `club_id` jest denormalizacją (dałoby się dojść przez `match_id`), i to jest
-- celowe: lista raportów filtrowana po klubie to najczęstsze zapytanie w tej
-- części panelu, a złączenie z `matches` przy każdym wyświetleniu listy jest
-- kosztem bez zysku.
--
-- `template_version` NULL znaczy „wygenerowany przed erą templatów" i tak ma
-- zostać — to nie brak danych do uzupełnienia, tylko fakt historyczny.
ALTER TABLE reports
  ADD COLUMN club_id INT UNSIGNED NULL
    COMMENT 'tenant, denormalizacja z matches — lista raportow filtruje po tym',
  ADD COLUMN template_version INT UNSIGNED NULL
    COMMENT 'wersja templatu uzyta przy generowaniu; NULL = raport sprzed ery templatow';


-- ===========================================================================
-- 3. BACKFILL
-- ===========================================================================

-- matches: tenant z „naszej" strony meczu.
--
-- COALESCE, bo `club_home_id` jest NULL-owalne: mecz zaimportowany, zanim klub
-- został dopasowany do nazwy z eksportu, nie ma jeszcze żadnej strony. Taki
-- wiersz dostaje klub ze stałej — historia należy do klienta niezależnie od
-- tego, czy dopasowanie nazwy zdążyło się wykonać.
UPDATE matches
   SET club_id = COALESCE(club_home_id, @ca_club_id)
 WHERE club_id IS NULL;

-- reports: tenant z meczu, do którego raport należy.
UPDATE reports r
  JOIN matches m ON m.id = r.match_id
   SET r.club_id = m.club_id
 WHERE r.club_id IS NULL;

-- notes: kolumna `club_id` ISTNIEJE od migracji 001, ale jest wypełniana
-- wyłącznie dla notatek o zasięgu klubowym (`scope = 'club'`). Notatka meczowa
-- i notatka przypięta do zdarzenia mają dziś `club_id` puste, mimo że klub jest
-- znany przez mecz. To właśnie „ujednolicenie" ze specyfikacji: wypełniamy to,
-- co da się wywieść, i od teraz każda notatka niesie klub wprost.
--
-- Notatki bez meczu i bez klubu (gdyby takie były) zostają z NULL — kolumna
-- pozostaje NULL-owalna i nie zaciskamy jej.
UPDATE notes n
  JOIN matches m ON m.id = n.match_id
   SET n.club_id = m.club_id
 WHERE n.club_id IS NULL
   AND n.match_id IS NOT NULL;


-- ===========================================================================
-- 4. ZACIŚNIĘCIE I KLUCZE OBCE (dopiero po backfillu)
-- ===========================================================================

-- Tenant meczu jest obowiązkowy. Ten krok jest DRUGIM bezpiecznikiem: gdyby
-- backfill czegoś nie objął, migracja pada tutaj, zamiast zostawić mecz bez
-- właściciela, którego nie zobaczy żadna lista filtrowana po klubie.
ALTER TABLE matches
  MODIFY COLUMN club_id INT UNSIGNED NOT NULL
    COMMENT 'tenant: wlasciciel analizy. Nowy kod filtruje po tej kolumnie',
  ADD CONSTRAINT fk_matches_club FOREIGN KEY (club_id) REFERENCES clubs(id);

ALTER TABLE reports
  ADD CONSTRAINT fk_reports_club FOREIGN KEY (club_id) REFERENCES clubs(id);

-- Indeksy pod zapytania, które od teraz ZAWSZE mają klub w warunku.
CREATE INDEX idx_matches_club ON matches (club_id, played_at);
CREATE INDEX idx_reports_club ON reports (club_id, generated_at);
CREATE INDEX idx_notes_club   ON notes (club_id);

-- Notatki wskazujące klub, którego nie ma, zablokują założenie klucza obcego.
-- Ten SELECT ma zwrócić PUSTO. Jeśli coś wypisze — to sierota po skasowanym
-- klubie i trzeba ją naprawić ręcznie, zanim klucz wejdzie.
SELECT n.id, n.scope, n.club_id
  FROM notes n
  LEFT JOIN clubs c ON c.id = n.club_id
 WHERE n.club_id IS NOT NULL
   AND c.id IS NULL;

ALTER TABLE notes
  ADD CONSTRAINT fk_notes_club FOREIGN KEY (club_id) REFERENCES clubs(id);


-- ===========================================================================
-- 5. KONTROLA PO MIGRACJI — czytać, nie przewijać
-- ===========================================================================

-- Kto przejął historię.
SELECT id AS tenant_id, club_key, name, is_own_team
  FROM clubs
 WHERE id = @ca_club_id;

-- Ile wierszy dostało tenanta i ile go NIE MA. Kolumna `bez_club_id` ma być
-- zerem w każdym wierszu; dla `matches` jest to gwarantowane przez NOT NULL,
-- dla pozostałych dwóch jest to sprawdzenie, nie gwarancja.
SELECT 'matches' AS tabela,
       COUNT(*)                                        AS wierszy,
       SUM(club_id IS NOT NULL)                        AS z_club_id,
       SUM(club_id IS NULL)                            AS bez_club_id,
       SUM(club_id <> club_home_id AND club_home_id IS NOT NULL) AS rozjazd_z_club_home_id
  FROM matches
UNION ALL
SELECT 'reports',  COUNT(*), SUM(club_id IS NOT NULL), SUM(club_id IS NULL), NULL FROM reports
UNION ALL
SELECT 'notes',    COUNT(*), SUM(club_id IS NOT NULL), SUM(club_id IS NULL), NULL FROM notes;

-- Notatki nadal bez klubu — rozbite na zasięgi, żeby było widać, czy to te bez
-- meczu (dopuszczalne), czy coś, czego backfill nie objął (do sprawdzenia).
SELECT scope, COUNT(*) AS bez_club_id
  FROM notes
 WHERE club_id IS NULL
 GROUP BY scope;

-- Nowe tabele są puste i mają być puste — templaty powstają w konfiguratorze
-- (Sesja 3/4), nie w migracji.
SELECT 'club_report_templates' AS tabela, COUNT(*) AS wierszy FROM club_report_templates
UNION ALL
SELECT 'club_ignored_tags', COUNT(*) FROM club_ignored_tags;
