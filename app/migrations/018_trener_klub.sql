-- 018 — Trener przypisany do jednego klubu (golden layout W2).
--
-- MIGRACJA CZYSTO ADDYTYWNA: jedna kolumna NULL i jej klucz obcy. Zero DROP,
-- zero DELETE, zero zmiany typów kolumn istniejących (app/migrations/README.md).
--
-- ROLE PO W2 (bez nowej kolumny roli — `users.role` z migracji 001):
--   admin    = Administrator (operator systemu): widzi wszystko, w tym [op],
--   operator = Analityk: kluby, których `clubs.owner_id` = jego `users.id`,
--   viewer   = Trener: DOKŁADNIE jeden klub z `users.club_id`, tylko odczyt.
-- `club_id` jest WYMAGANY dla trenera (pilnuje `Users`), NULL dla pozostałych.
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
--     serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_018_$(date +%F).sql
--   mysql serwer400227_coachanalyze < app/migrations/018_trener_klub.sql

-- PODGLĄD PRZED — kto jakie kluby zobaczy po wdrożeniu kodu W2. Analityk bez
-- ani jednego klubu na liście zobaczy pusty panel: popraw `clubs.owner_id`
-- PRZED wdrożeniem, a nie po telefonie od klienta.
SELECT u.id, u.email, u.role,
       GROUP_CONCAT(c.name ORDER BY c.id SEPARATOR ', ') AS kluby_analityka
  FROM users u
  LEFT JOIN clubs c ON c.owner_id = u.id AND c.is_own_team = 1
 GROUP BY u.id, u.email, u.role;

ALTER TABLE users
  ADD COLUMN club_id INT UNSIGNED NULL AFTER role,
  ADD CONSTRAINT fk_users_club FOREIGN KEY (club_id) REFERENCES clubs(id);

-- KONTROLA PO — trener bez klubu nie zobaczy niczego; takie konto trzeba
-- przypisać na ekranie Użytkownicy.
SELECT id, email FROM users WHERE role = 'viewer' AND club_id IS NULL;
