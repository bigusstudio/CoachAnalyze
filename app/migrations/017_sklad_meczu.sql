-- 017 — Skład meczu (sesja 6 pivotu „viewer").
--
-- MIGRACJA CZYSTO ADDYTYWNA: jedno CREATE TABLE. Zero DROP, zero DELETE,
-- zero zmiany typów kolumn istniejących (app/migrations/README.md).
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
--     serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_017_$(date +%F).sql
--   mysql --defaults-group-suffix=caproba serwer400227_caproba < app/migrations/017_sklad_meczu.sql
--   mysql serwer400227_coachanalyze < app/migrations/017_sklad_meczu.sql


-- ===========================================================================
-- ODSTĘPSTWO OD SPECYFIKACJI SESJI 6 — `matches.venue` NIE POWSTAJE
-- ===========================================================================
--
-- Specyfikacja prosiła o `matches.venue ENUM('dom','wyjazd') NULL`. Ta kolumna
-- JUŻ ISTNIEJE, pod inną nazwą i w innym typie:
--
--   `matches.is_home TINYINT(1) NULL`   (migracja 012)
--     1    = u siebie
--     0    = wyjazd
--     NULL = nie wiemy
--
-- Trzy stany, ten sam fakt, to samo źródło (formularz mety — eksport LiveTag
-- nie niesie informacji o gospodarzu i nie wolno jej zgadywać). Dołożenie
-- drugiej kolumny znaczyłoby DWA MIEJSCA, w których zapisuje się jedna rzecz,
-- i pytanie „która jest prawdziwa" przy pierwszym rozjeździe — a rozjazd jest
-- pewny, bo stary kod pisze do `is_home`, a nowy pisałby do `venue`.
--
-- Kontrakt z silnikiem dostaje pole `match.venue` (`dom` / `wyjazd` / `null`)
-- tak, jak przewidywała specyfikacja — wypełnia je `run_job.php`, czytając
-- `is_home`. Nazwa w kontrakcie jest więc taka, jak zamówiono; nie ma tylko
-- drugiej kolumny w bazie.


-- ===========================================================================
-- SKŁAD MECZU
-- ===========================================================================
--
-- PO CO OSOBNA TABELA, SKORO EKSPORT MA KOLUMNĘ ZAWODNIKA.
--
-- Bo kolumna zawodnika w eksporcie bywa pusta (pułapka 4 z CLAUDE.md) i bo
-- niesie WYŁĄCZNIE tych, którzy dostali taga. Zawodnik, który rozegrał 90 minut
-- i nie zrobił nic, co analityk tagował, w eksporcie nie istnieje — a w składzie
-- owszem. Minuty i numery nie mają w eksporcie żadnego odpowiednika.
--
-- SKŁAD JEST PER MECZ I PER KLUB, nie per klub: ten sam zawodnik ma różne
-- minuty w różnych meczach, a scoutingowy raport opisuje dwa cudze składy.
--
-- DOPASOWANIE DO ZDARZEŃ IDZIE PO PEŁNEJ NAZWIE, PRZEZ RÓWNOŚĆ (pułapka 7).
-- Stąd `UNIQUE (match_id, club_id, player)`: dwa wiersze o tej samej nazwie
-- w jednym składzie dałyby zdarzeniu dwóch właścicieli.

CREATE TABLE match_players (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id   INT UNSIGNED NOT NULL,
  club_id    INT UNSIGNED NOT NULL,

  -- Nazwa tak, jak zapisał ją analityk w LiveTag. 160 znaków jak `clubs.name`:
  -- nazwisko z imieniem i drugim członem mieści się z zapasem.
  player     VARCHAR(160) NOT NULL,

  -- Numer na koszulce. SMALLINT, nie TINYINT: numery powyżej 99 zdarzają się
  -- w młodzieżowych rozgrywkach, a NULL znaczy „nie podano".
  number     SMALLINT UNSIGNED NULL,

  -- Pozycja jako KRÓTKI NAPIS, nie ENUM. Nazewnictwo pozycji jest sprawą
  -- metodyki klubu („ŚP", „DP", „LO"), a ENUM wymagałby migracji przy pierwszym
  -- kliencie, który nazywa je inaczej.
  position   VARCHAR(16) NULL,

  -- Rozegrane minuty. NULL = nie podano; 0 = wszedł i zszedł bez minuty gry
  -- albo nie wszedł wcale. To są dwie różne rzeczy i zero ich nie myli.
  minutes    SMALLINT UNSIGNED NULL,

  is_starter TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'wyjsciowa jedenastka; 0 = rezerwowy albo wszedl z lawki',

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_match_players (match_id, club_id, player),
  INDEX idx_match_players_match (match_id, club_id),

  CONSTRAINT fk_match_players_match FOREIGN KEY (match_id) REFERENCES matches(id),
  CONSTRAINT fk_match_players_club  FOREIGN KEY (club_id)  REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===========================================================================
-- SPRAWDZENIE PO NAŁOŻENIU
-- ===========================================================================
--
--   SHOW CREATE TABLE match_players\G
--   SELECT COUNT(*) FROM match_players;        -- 0, tabela startuje pusta
--   SELECT is_home, COUNT(*) FROM matches GROUP BY is_home;
--     -- kontrola, że gospodarz/gość dalej siedzi tam, gdzie siedział
