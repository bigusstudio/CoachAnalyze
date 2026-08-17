-- Kalkulator xG (moduł M3) — strzały dodane ręcznie na interaktywnym boisku.
--
-- Narzędzie robocze analityka: może dodać strzał, którego nie otagował
-- w LiveTag, albo zweryfikować własną ocenę sytuacji. Te strzały NIE wchodzą
-- do żadnego raportu ani metryki — to notatnik liczbowy, nie źródło danych.
--
-- `xg` przechowuje wartość ODCZYTANĄ z siatki policzonej przez silnik
-- (app/src/data/xg_grid.json, komenda `python -m coachanalyze xg-grid`).
-- PHP niczego nie liczy (CLAUDE.md §4) — zapisujemy wynik odczytu z chwili
-- dodania, żeby lista nie zmieniała się wstecznie po rekalibracji modelu.

CREATE TABLE xg_manual_shots (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  match_id    INT UNSIGNED NULL,                 -- opcjonalne przypięcie do meczu
  x           DECIMAL(5,1) NOT NULL,             -- metry, oś ataku (0–105)
  y           DECIMAL(5,1) NOT NULL,             -- metry (0–68)
  body_part   ENUM('foot','head') NOT NULL DEFAULT 'foot',
  situation   ENUM('open','free_kick','penalty') NOT NULL DEFAULT 'open',
  xg          DECIMAL(4,2) NOT NULL,             -- wartość z siatki silnika
  note        VARCHAR(200) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY ix_xg_manual_user (user_id),

  CONSTRAINT fk_xg_manual_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
