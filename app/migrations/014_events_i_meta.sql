-- 014 — Tabela zdarzeń i meta meczu (sesja 2 pivotu „viewer").
--
-- MIGRACJA CZYSTO ADDYTYWNA: jedno CREATE TABLE i jeden ALTER ADD.
-- Zero DROP, zero DELETE, zero zmiany typów kolumn istniejących.
-- To pierwsza migracja objęta zasadą z app/migrations/README.md: od 014 wyłącznie
-- addytywne, żeby kod `pro` (tag pro-1.0) dalej działał na tym schemacie
-- (docs/STAN_PIVOTU.md §4).
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
--     serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_014_$(date +%F).sql
--   mysql --defaults-group-suffix=caproba serwer400227_caproba < app/migrations/014_events_i_meta.sql
--   mysql serwer400227_coachanalyze < app/migrations/014_events_i_meta.sql


-- ===========================================================================
-- ZDARZENIA MECZU PO SUROWYCH NAZWACH TAGÓW
-- ===========================================================================
--
-- DLACZEGO TO NIE JEST `events_canonical`.
--
-- `events_canonical` (migracja 002) trzyma zdarzenia przetłumaczone na POJĘCIA
-- (`shot`, `entry_sbz`) i zostaje nietknięta — warstwa kanoniczna jest uśpiona,
-- nie usunięta (docs/STAN_PIVOTU.md §2.1). Ta tabela trzyma to, co JEST
-- W EKSPORCIE, pod nazwą, którą wpisał analityk.
--
-- Powód jest ten sam, co przy wycofaniu reguły „brak bindingu = tylko licznik"
-- (§2.3): raport liczy po surowej nazwie tagu (`e.tag==='STRZAŁ'` w szablonie).
-- Tabela licząca po pojęciach odpowiadałaby na te same pytania INNĄ miarą,
-- a obie liczby wyglądałyby sensownie — czyli rozjazd nie do wykrycia okiem.
--
-- Dwie tabele obok siebie są tu tańsze niż jedna „uniwersalna": kolumna
-- `concept` wypełniana raz tak, raz inaczej, zależnie od tego, która warstwa
-- pisała wiersz, byłaby pułapką na każdego, kto to kiedyś przeczyta.

CREATE TABLE events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  match_id    INT UNSIGNED NOT NULL,
  -- Z KTÓREGO WGRANIA pochodzi wiersz. NULL dla danych dopisanych inaczej niż
  -- importem. Reimport tworzy nowy wiersz w `imports`, a zdarzenia meczu są
  -- kasowane i wstawiane od nowa — to pole mówi, które wgranie je wyprodukowało.
  import_id   INT UNSIGNED NULL,

  -- SUROWA NAZWA TAGA z eksportu, bez tłumaczenia. 120 znaków: najdłuższy
  -- zaobserwowany to `III STREFA PODAJĄCY/OTRZYMUJĄCY` (33), zapas jest celowy.
  tag_name    VARCHAR(120) NOT NULL,
  -- Etykiety jako tablica JSON, nie tekst z przecinkami. Pułapka 11: etykieta
  -- potrafi zawierać przecinek w środku (`POZYCYJNIE, CELNY` to DWIE etykiety,
  -- ale `NASZA POŁOWA` to jedna), więc sklejanie ich z powrotem w napis
  -- odtwarzałoby dokładnie ten problem, który parser już rozwiązał.
  labels_json JSON NULL,

  -- SUROWA nazwa drużyny z eksportu. NULL, gdy kolumna `team` była pusta —
  -- pułapka 5, w eksporcie referencyjnym dotyczy 196 z 294 zdarzeń.
  team        VARCHAR(160) NULL,
  -- Strona meczu, nie punkt widzenia. `none` to poprawna i CZĘSTA wartość.
  -- Perspektywę klubu-tenanta („kto jest nasz") interpretuje szablon raportu.
  team_side   ENUM('us','them','none') NOT NULL,
  -- Pułapka 4: kolumna zawodnika bywa pusta albo nie ma jej wcale.
  player      VARCHAR(160) NULL,

  -- Czas WIDEO w milisekundach (pułapka 8). NOT NULL, bo zdarzenie bez czasu
  -- nie da się umieścić na osi — silnik takie wiersze pomija i mówi o tym
  -- głośno (`skipped_no_time`). Zera nie podstawiamy: zero to konkretna
  -- 0. sekunda i nie da się jej odróżnić od braku danych (CLAUDE.md §8).
  t_ms        INT UNSIGNED NOT NULL,
  t_end_ms    INT UNSIGNED NULL,
  half        TINYINT NOT NULL,
  -- Minuta meczu: ceil(t/60), pierwsza połowa przycięta do 45. Liczona przez
  -- silnik i ZAPISANA, nie wyliczana w zapytaniach — reguła przycięcia jest
  -- nieoczywista i powielona w SQL-u rozjechałaby się przy pierwszej zmianie.
  minute      SMALLINT NOT NULL,

  -- xG odczytane z komentarza analityka (pułapka 1: `X 0,81` z przecinkiem).
  -- DECIMAL, nie FLOAT: sumy xG pokazujemy klientowi i mają się zgadzać
  -- co do setnej przy każdym odczycie.
  xg          DECIMAL(5,4) NULL,
  xg_source   ENUM('analyst','model') NULL,

  -- Metry, nie piksele (pułapka 2: współrzędne są już znormalizowane kierunkowo,
  -- NIE WOLNO ich lustrzyć przy zapisie). `t`-owe to punkt docelowy podania.
  x           DECIMAL(6,2) NULL,
  y           DECIMAL(6,2) NULL,
  tx          DECIMAL(6,2) NULL,
  ty          DECIMAL(6,2) NULL,

  -- Strzał zakończony golem. Wyliczone przez silnik przez przypisanie tagu
  -- `Gol` do NAJBLIŻSZEGO W CZASIE strzału — `team_uuid` przy golu w eksporcie
  -- bywa błędny. Wiersz `Gol` zostaje osobno, ze skorygowaną drużyną.
  is_goal     TINYINT(1) NOT NULL DEFAULT 0,

  -- Najczęstsze pytanie raportu: „ile razy padł tag X w tym meczu".
  INDEX idx_events_match_tag  (match_id, tag_name),
  -- Oś czasu i podział na strony — zapytanie z sortowaniem po czasie.
  INDEX idx_events_match_side (match_id, team_side, t_ms),

  -- ON DELETE CASCADE, bo zdarzenie bez meczu jest śmieciem, a nie danymi.
  -- Jedyne miejsce w schemacie, gdzie kasowanie jest kaskadowe i celowe:
  -- zdarzenia są ODTWARZALNE z surowego eksportu, więc ich utrata przy
  -- usunięciu meczu niczego nieodwracalnego nie kosztuje.
  CONSTRAINT fk_events_match  FOREIGN KEY (match_id)  REFERENCES matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_import FOREIGN KEY (import_id) REFERENCES imports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===========================================================================
-- KOLEJKA W META MECZU
-- ===========================================================================
--
-- Nagłówek raportu v21 pokazuje „sezon · kolejka · data". Sezon bierze się
-- z `seasons.label`, data z `matches.played_at`, a kolejki NIE BYŁO GDZIE ZAPISAĆ
-- (docs/STAN_PIVOTU.md §7.2). Do tej migracji nagłówek pokazywał to miejsce puste.
--
-- VARCHAR, nie INT: kolejka bywa zapisywana jako „3", ale też „1/8 finału"
-- albo „baraż". Liczba wymuszałaby wpisanie czegoś, co liczbą nie jest,
-- albo osobnej kolumny na przypadki nieliczbowe.
--
-- NULL ZNACZY „NIE WIEMY" i jest wartością poprawną dla całej historii: mecze
-- zaimportowane przed tą migracją nie mają skąd wziąć kolejki. Nagłówek pomija
-- wtedy CAŁY człon razem z separatorem, a nie zostawia „kolejka ·" bez treści.

ALTER TABLE matches
  ADD COLUMN round VARCHAR(16) NULL
    COMMENT 'kolejka/runda z formularza meta; NULL = nieznana';


-- ===========================================================================
-- KONTROLA PO URUCHOMIENIU
-- ===========================================================================
--
-- Tabela ma istnieć i być pusta — migracja niczego nie backfilluje. Zdarzenia
-- pojawią się przy pierwszym imporcie albo przeliczeniu raportu po wdrożeniu.

SELECT COUNT(*) AS zdarzen_ogolem FROM events;

SELECT COUNT(*)                  AS meczow_ogolem,
       SUM(round IS NOT NULL)    AS z_kolejka,
       SUM(round IS NULL)        AS bez_kolejki
  FROM matches;
