-- Kreator mapowań tagów: autorstwo wersji profilu i zgłoszenia brakujących pojęć.
--
-- WERSJONOWANIE JUŻ JEST i wystarcza: `mapping_profiles` ma `version`
-- oraz `UNIQUE (club_id, version)` (migracja 001). Nowa wersja to nowy wiersz,
-- poprzednie zostają — niczego nie trzeba tu zmieniać.
--
-- Brakowało dwóch rzeczy.

-- 1. KTO zmienił mapowanie.
--
-- `created_at` było, autora nie było. Bez niego pytanie „czemu od marca liczby
-- się zmieniły" ma odpowiedź „bo ktoś zmienił mapowanie", i na tym koniec.
-- Mapowanie decyduje o tym, które zdarzenia wchodzą do metryk, więc jest
-- zmianą tej samej wagi co zmiana kodu silnika i ma mieć autora.
ALTER TABLE mapping_profiles
  ADD COLUMN created_by INT UNSIGNED NULL
    COMMENT 'autor wersji; NULL dla profilu domyślnego założonego przez system',
  ADD COLUMN note VARCHAR(500) NULL
    COMMENT 'powód zmiany, wpisywany przez operatora przy zatwierdzeniu',
  ADD CONSTRAINT fk_profiles_author FOREIGN KEY (created_by) REFERENCES users(id);

-- Historia wersji czytana jest zawsze w tej samej kolejności — od najnowszej.
CREATE INDEX idx_profiles_history ON mapping_profiles (club_id, version DESC);


-- 2. Zgłoszenia brakujących pojęć kanonicznych.
--
-- Lista pojęć jest ZAMKNIĘTA (docs/MODEL_KANONICZNY.md). Operator przypisuje tag
-- do istniejącego pojęcia, nie tworzy nowego — rozrost listy oznacza, że model
-- zaczyna kopiować format LiveTag zamiast go tłumaczyć.
--
-- Ale „nie ma pasującego pojęcia" bywa prawdą i musi mieć ujście inne niż cisza.
-- Bez tej tabeli operator ma do wyboru: wcisnąć tag w niepasujące pojęcie
-- (co po cichu skrzywi liczby) albo zostawić go nieanalizowanym i zapomnieć.
-- Zgłoszenie zapisuje sprawę do przejrzenia przez człowieka, a tag idzie
-- TYMCZASOWO jako nieanalizowany — widoczny w raporcie pokrycia, nie zgubiony.
CREATE TABLE concept_requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  club_id       INT UNSIGNED NULL,             -- NULL, gdy klub jeszcze nieprzypisany
  import_id     INT UNSIGNED NULL,             -- skąd przyszło zgłoszenie
  tag           VARCHAR(190) NOT NULL,         -- nazwa tagu z eksportu, bez zmian
  sample_labels_json JSON NULL,                -- etykiety towarzyszące, do oceny
  rationale     VARCHAR(1000) NULL,            -- czemu żadne pojęcie nie pasuje
  requested_by  INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  status        ENUM('new','accepted','rejected') NOT NULL DEFAULT 'new',
  resolution    VARCHAR(500) NULL,             -- decyzja i jej powód
  resolved_by   INT UNSIGNED NULL,
  resolved_at   DATETIME NULL,

  INDEX idx_requests_status (status, created_at),
  INDEX idx_requests_tag (tag),
  CONSTRAINT fk_request_club   FOREIGN KEY (club_id)      REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_request_author FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 3. Znacznik, że import przeszedł przez kreator.
--
-- Bez niego nie da się odróżnić „nie było nowych tagów, przeszliśmy dalej"
-- od „ekran mapowania nigdy się nie pokazał, bo coś go ominęło". Pierwsze jest
-- poprawne, drugie jest błędem — i mają wyglądać inaczej.
ALTER TABLE imports
  ADD COLUMN mapping_profile_id INT UNSIGNED NULL
    COMMENT 'wersja profilu użyta przy tym imporcie; NULL = kreator jeszcze nie przeszedł',
  ADD CONSTRAINT fk_import_profile FOREIGN KEY (mapping_profile_id)
    REFERENCES mapping_profiles(id);
