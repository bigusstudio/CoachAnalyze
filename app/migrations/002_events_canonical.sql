-- Zdarzenia kanoniczne. Migracja opcjonalna w wersji BAZA — decyzja zapada przed startem.
-- Bez tej tabeli moduł M4 (porównanie do średniej sezonu) musi re-parsować całe archiwum
-- przy każdym raporcie: działa przy pięciu meczach, zatyka się przy trzydziestu.

CREATE TABLE events_canonical (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id        INT UNSIGNED NOT NULL,
  t_ms            INT UNSIGNED NOT NULL,
  half            TINYINT UNSIGNED NOT NULL,
  team_side       ENUM('us','them','none') NOT NULL,
  concept         VARCHAR(32) NOT NULL,
  qualifiers_json JSON NULL,
  x               DECIMAL(6,2) NULL,
  y               DECIMAL(6,2) NULL,
  x_end           DECIMAL(6,2) NULL,
  y_end           DECIMAL(6,2) NULL,
  xg              DECIMAL(5,4) NULL,
  xg_source       ENUM('analyst','model') NULL,
  player_id       INT UNSIGNED NULL,
  source_tag      VARCHAR(120) NULL,
  INDEX idx_ev_match_concept (match_id, concept),
  INDEX idx_ev_match_time    (match_id, team_side, t_ms),
  CONSTRAINT fk_ev_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
