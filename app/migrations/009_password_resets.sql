-- Reset hasła przez e-mail.
--
-- W BAZIE LEŻY WYŁĄCZNIE SKRÓT tokenu (sha256). Surowy token powstaje
-- w procesie roboczym (app/bin/run_job.php) tuż przed wysyłką maila i nie
-- jest zapisywany NIGDZIE — ani tutaj, ani w jobs.payload_json. Warstwa
-- żądań kolejkuje samą prośbę („adres X poprosił o reset"), a dopiero worker
-- rozstrzyga, czy konto istnieje, i generuje token. Dzięki temu odpowiedź
-- HTTP jest identyczna dla adresu istniejącego i nieistniejącego, a zrzut
-- bazy nie wystarcza do przejęcia konta.
--
-- Jednorazowość: `used_at` ustawiane przy udanym użyciu; wiersz zostaje jako
-- ślad. Nowa prośba kasuje wcześniejsze NIEUŻYTE tokeny konta — ważny jest
-- zawsze najwyżej jeden odnośnik.

CREATE TABLE password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,                 -- sha256 surowego tokenu (256 bitów z CSPRNG)
  expires_at  DATETIME NOT NULL,                 -- created_at + 1 h; po terminie odnośnik martwy
  used_at     DATETIME NULL,                     -- NULL = jeszcze nieużyty
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY ix_password_resets_user (user_id),

  CONSTRAINT fk_password_resets_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
