-- Trwałe logowanie („zapamiętaj mnie na tym urządzeniu").
--
-- To NIE jest przedłużona sesja. Sesja żyje do zamknięcia przeglądarki; ten token
-- jest osobnym, długoterminowym poświadczeniem o słabszych uprawnieniach.
--
-- W bazie trzymamy WYŁĄCZNIE SKRÓT tokenu. Wyciek zawartości tabeli nie może
-- pozwolić nikomu zalogować się cudzym ciasteczkiem. To ten sam powód, dla
-- którego nie trzymamy haseł jawnie.
--
-- Skrót jest SHA-256, nie argon2id — i to jest świadome. Argon2 broni przed
-- zgadywaniem wartości o niskiej entropii (hasło ludzkie). Token ma 256 bitów
-- z CSPRNG, więc nie ma czego zgadywać, a szybki skrót pozwala go odnaleźć
-- indeksem zamiast porównywać po kolei z każdym wierszem w tabeli.

CREATE TABLE remember_tokens (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  token_hash      CHAR(64) NOT NULL UNIQUE,     -- sha256(token) w zapisie szesnastkowym
  expires_at      DATETIME NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at    DATETIME NULL,

  -- Skróty, nie wartości jawne. Pozwalają ROZPOZNAĆ, że to nadal to samo
  -- urządzenie i ta sama sieć, ale nie odtworzyć przeglądarki ani adresu.
  user_agent_hash CHAR(64) NULL,
  ip_hash         CHAR(64) NULL,

  -- ODSTĘPSTWO OD PIERWOTNEGO ZESTAWU KOLUMN, świadome i konieczne:
  -- ze skrótu nie da się odtworzyć nazwy urządzenia, bo funkcja skrótu jest
  -- jednokierunkowa. Lista „aktywnych urządzeń" pokazywałaby wtedy same daty
  -- i ciąg szesnastkowy, czyli nic, po czym właściciel rozpozna swój telefon.
  -- Dlatego przy tworzeniu zapisujemy zgrubną etykietę („Chrome · macOS"),
  -- wyliczoną z User-Agent i celowo pozbawioną numerów wersji.
  device_label    VARCHAR(120) NULL,

  INDEX idx_remember_user (user_id, expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
