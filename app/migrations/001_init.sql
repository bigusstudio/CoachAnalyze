-- CoachAnalyze — migracja inicjalna
-- Uwaga: owner_id obecne od początku mimo jednego konta w wersji BAZA.
-- Koszt teraz zerowy, później różnica między migracją a przepisaniem.

SET NAMES utf8mb4;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  pass_hash     VARCHAR(255) NOT NULL,
  display_name  VARCHAR(120) NOT NULL,
  role          ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clubs (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id        INT UNSIGNED NOT NULL,
  club_key        CHAR(10) NOT NULL UNIQUE,   -- stały klucz w adresie /r/{club_key}/{token}
  name            VARCHAR(160) NOT NULL,
  short_name      VARCHAR(32) NULL,
  color_primary   CHAR(7) NOT NULL DEFAULT '#E8722C',
  color_secondary CHAR(7) NULL,
  crest_path      VARCHAR(255) NULL,
  is_own_team     TINYINT(1) NOT NULL DEFAULT 0,
  aliases_json    JSON NULL,                  -- nazwy, pod jakimi klub występuje w eksportach
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_clubs_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE seasons (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id   INT UNSIGNED NOT NULL,
  label      VARCHAR(32) NOT NULL,            -- np. 2026/2027
  date_from  DATE NOT NULL,
  date_to    DATE NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_seasons_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE matches (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id      INT UNSIGNED NOT NULL,
  season_id     INT UNSIGNED NULL,
  -- UWAGA NA NAZWY: to NIE są strony boiska. Eksport LiveTag nie niesie
  -- informacji o tym, kto był gospodarzem, a udawanie jej dałoby kolumnę
  -- kłamiącą przy każdym meczu wyjazdowym.
  --   club_home_id = strona `us`   („nasza drużyna" — klub oznaczony is_own_team)
  --   club_away_id = strona `them` („rywal")
  -- Odwzorowanie stron z docs/KONTRAKT_CLI.md. W interfejsie kolumny nazywają się
  -- „Nasza drużyna" i „Rywal". Nazwy kolumn zostają ze względu na zgodność wstecz.
  club_home_id  INT UNSIGNED NULL,
  club_away_id  INT UNSIGNED NULL,
  played_at     DATE NULL,
  competition   VARCHAR(120) NULL,
  half_split_ms INT UNSIGNED NULL,
  status        ENUM('draft','queued','running','done','failed') NOT NULL DEFAULT 'draft',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_matches_season (season_id, played_at),
  CONSTRAINT fk_matches_owner  FOREIGN KEY (owner_id)  REFERENCES users(id),
  CONSTRAINT fk_matches_season FOREIGN KEY (season_id) REFERENCES seasons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE imports (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id           INT UNSIGNED NOT NULL,
  csv_path           VARCHAR(255) NOT NULL,
  json_path          VARCHAR(255) NULL,
  checksum_csv       CHAR(64) NOT NULL,
  format_fingerprint CHAR(64) NULL,           -- skrót zestawu kolumn; zmiana = alert
  coverage_json      JSON NULL,
  warnings_json      JSON NULL,
  engine_version     VARCHAR(20) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_imports_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mapping_profiles (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  club_id    INT UNSIGNED NOT NULL,
  version    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rules_json JSON NOT NULL,
  source     ENUM('default','manual','ai') NOT NULL DEFAULT 'default',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profile (club_id, version),
  CONSTRAINT fk_profiles_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reports (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id       INT UNSIGNED NOT NULL,
  html_path      VARCHAR(255) NOT NULL,
  params_json    JSON NULL,                   -- wybrane sekcje, opcje renderu
  engine_version VARCHAR(20) NOT NULL,
  generated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reports_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE share_links (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id      INT UNSIGNED NOT NULL,
  club_id        INT UNSIGNED NOT NULL,       -- para (club_key, token) musi się zgadzać
  token          CHAR(32) NOT NULL UNIQUE,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     DATETIME NULL,
  revoked_at     DATETIME NULL,
  views          INT UNSIGNED NOT NULL DEFAULT 0,
  last_viewed_at DATETIME NULL,
  CONSTRAINT fk_share_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_share_club   FOREIGN KEY (club_id)   REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id      INT UNSIGNED NOT NULL,
  scope         ENUM('match','club','event') NOT NULL,
  match_id      INT UNSIGNED NULL,
  club_id       INT UNSIGNED NULL,
  event_ref     VARCHAR(64) NULL,             -- odniesienie do zdarzenia w raporcie
  title         VARCHAR(200) NULL,
  body          MEDIUMTEXT NOT NULL,
  tags_json     JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  INDEX idx_notes_scope (scope, match_id, club_id),
  FULLTEXT KEY ft_notes (title, body),
  CONSTRAINT fk_notes_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE jobs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         VARCHAR(40) NOT NULL,
  payload_json JSON NOT NULL,
  status       ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  exit_code    TINYINT NULL,
  error_text   TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at   DATETIME NULL,
  finished_at  DATETIME NULL,
  INDEX idx_jobs_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(40) NULL,
  entity_id  INT UNSIGNED NULL,
  meta_json  JSON NULL,
  ip         VARBINARY(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit (entity, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
