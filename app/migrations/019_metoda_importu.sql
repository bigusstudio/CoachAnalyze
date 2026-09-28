-- 019 — Metoda importu v3 (W7): deduplikacja meczów, profil analityka, niezmienniki.
--
-- MIGRACJA CZYSTO ADDYTYWNA: pięć kolumn NULL i jeden indeks na `imports`.
-- Zero DROP, zero DELETE, zero zmiany typów kolumn istniejących
-- (app/migrations/README.md). Kod sprzed W7 tych kolumn nie zna i nie musi.
--
--   sha256_zdarzen    skrót zbioru wierszy CSV NIEZALEŻNY OD KOLEJNOŚCI
--                     (silnik: parse.sha256_zdarzen, panel: Upload::sha256Zdarzen).
--                     Ten sam mecz wgrany drugi raz (także z inną kolejnością
--                     wierszy) nie tworzy drugiego meczu.
--   profil_json       odcisk profilu analityka z `meta.profil`:
--                     {uuid:[..], nazwy:[znormalizowane], nazwa_uuid:{tag:uuid}}
--   profil_nowy       1 = układ tagów, którego klub jeszcze nie widział
--                     (Jaccard UUID i nazw < 0,8 do każdego wcześniejszego importu)
--   profil_import_id  import, do którego profil się dopasował (NULL przy nowym)
--   niezmienniki_ok   0 = raport niezgodny z plikiem (alert dla admina), 1 = zgodny,
--                     NULL = import sprzed W7 albo jeszcze nie policzony
--
-- Stare importy zostają z NULL: skrót i profil uzupełni pierwsza inspekcja
-- albo regeneracja raportu (app/repairs/regeneruj_raporty.php).
--
-- URUCHOMIENIE (ręczne, po zrzucie bazy):
--   mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
--     serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_019_$(date +%F).sql
--   mysql serwer400227_coachanalyze < app/migrations/019_metoda_importu.sql

ALTER TABLE imports
  ADD COLUMN sha256_zdarzen CHAR(64) NULL AFTER checksum_csv,
  ADD COLUMN profil_json JSON NULL AFTER sections_json,
  ADD COLUMN profil_nowy TINYINT(1) NULL AFTER profil_json,
  ADD COLUMN profil_import_id INT UNSIGNED NULL AFTER profil_nowy,
  ADD COLUMN niezmienniki_ok TINYINT(1) NULL AFTER profil_import_id,
  ADD INDEX idx_imports_sha256_zdarzen (sha256_zdarzen);

-- KONTROLA PO — kolumny są, wszystkie puste (nic jeszcze ich nie wypełniło).
SELECT COUNT(*) AS importow,
       SUM(sha256_zdarzen IS NOT NULL) AS ze_skrotem,
       SUM(profil_json IS NOT NULL) AS z_profilem,
       SUM(niezmienniki_ok = 0) AS niezgodnych
  FROM imports;
