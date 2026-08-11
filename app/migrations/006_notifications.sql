-- Powiadomienia: w aplikacji zawsze, mailem opcjonalnie.
--
-- JEDNA TABELA NA OBA KANAŁY, nie dwie. Powiadomienie w aplikacji i mail o tym
-- samym zdarzeniu to ten sam fakt opisany dwa razy; rozbicie na osobne tabele
-- kończy się rozjazdem („mail poszedł, dzwonek nie zniknął") i podwójną historią
-- tego samego zdarzenia. Kanał mailowy jest tu STANEM WYSYŁKI, nie osobnym bytem.
--
-- Dzięki temu wyłączenie SMTP nie wymaga żadnej zmiany w warstwie aplikacji:
-- powiadomienia powstają tak samo, tylko `mail_status` zostaje na `none`.

CREATE TABLE notifications (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,           -- odbiorca
  type         VARCHAR(40) NOT NULL,            -- import.pending | report.ready | report.failed
  title        VARCHAR(200) NOT NULL,           -- treść gotowa do pokazania, po polsku
  body         TEXT NULL,                       -- rozwinięcie: powód błędu, podpowiedź
  entity       VARCHAR(40) NULL,                -- match | report | import | job
  entity_id    INT UNSIGNED NULL,
  url          VARCHAR(255) NULL,               -- dokąd prowadzi kliknięcie
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at      DATETIME NULL,                   -- NULL = nieodczytane (licznik w nagłówku)

  -- ---------------------------------------------------------------- kanał mailowy
  --
  -- `none` znaczy „mail nieprzewidziany" i jest wartością domyślną: albo SMTP nie
  -- jest skonfigurowany, albo użytkownik wyłączył ten typ w ustawieniach.
  -- Rozróżnienie `none` / `skipped` jest celowe — `skipped` to mail, który MIAŁ
  -- pójść i został świadomie odwołany, bo przestał być prawdziwy.
  mail_status  ENUM('none','pending','sent','failed','skipped') NOT NULL DEFAULT 'none',
  mail_to      VARCHAR(190) NULL,               -- adres z chwili powstania, nie z chwili wysyłki
  mail_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  mail_error   VARCHAR(500) NULL,               -- powód po polsku; pełny zapis rozmowy SMTP idzie do logu
  mail_sent_at DATETIME NULL,

  -- Mail „przetwarzanie w toku" ma sens WYŁĄCZNIE wtedy, gdy przetwarzanie
  -- faktycznie trwa. Przy pustej kolejce raport bywa gotowy w kilkanaście sekund
  -- i taki mail dotarłby po fakcie — czyli jako dezinformacja.
  -- Wysyłka rusza dopiero po tym czasie, a przed wysłaniem sprawdzamy, czy powód
  -- nadal obowiązuje (patrz Notifications::stillRelevant).
  send_after   DATETIME NULL,

  INDEX idx_notif_user (user_id, read_at, created_at),
  INDEX idx_notif_mail (mail_status, send_after),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Preferencje mailowe przy użytkowniku.
--
-- Osobne kolumny zamiast jednego pola JSON: to są trzy niezależne przełączniki
-- odpytywane przy każdym powstaniu powiadomienia, a nie zbiór rosnących ustawień.
-- Domyślnie WŁĄCZONE — użytkownik, który skonfigurował SMTP, chce te maile;
-- gdyby były domyślnie wyłączone, cała warstwa mailowa byłaby niewidoczna
-- i wyglądałaby na niedziałającą.
ALTER TABLE users
  ADD COLUMN notify_mail_pending TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'mail: import wgrany, przetwarzanie w toku (dopiero po zwłoce)',
  ADD COLUMN notify_mail_ready   TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'mail: raport gotowy',
  ADD COLUMN notify_mail_failed  TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'mail: przetwarzanie nie powiodło się';


-- Zadanie może czekać na swój termin.
--
-- Potrzebne dla maila „przetwarzanie w toku", który ma pójść dopiero po dwóch
-- minutach. Bez tego worker podjąłby zadanie przy najbliższym przejściu i cała
-- zwłoka byłaby fikcją.
--
-- Mechanizm jest ogólny, nie jednorazowy: przydaje się też na odczekanie przed
-- ponowieniem, żeby zadanie padające z powodu chwilowej awarii nie próbowało
-- w kółko co minutę.
ALTER TABLE jobs
  ADD COLUMN available_at DATETIME NULL
    COMMENT 'NULL = od razu; inaczej worker pomija zadanie do tego czasu';

-- Indeks obejmuje `available_at`, bo warunek wchodzi do zapytania wybierającego
-- zadania i bez niego doszłoby skanowanie przy każdym przejściu crona.
CREATE INDEX idx_jobs_ready ON jobs (status, available_at, created_at);
