-- Zarządzanie kontami z panelu: stan konta, wymuszona zmiana hasła, autorstwo.
--
-- Rola już jest w schemacie (`users.role` ENUM admin/operator/viewer, migracja 001)
-- i wystarcza. Brakowało trzech rzeczy.

-- 1. STAN KONTA zamiast usuwania.
--
-- Konta NIE KASUJEMY. `users.id` stoi w `audit_log`, `mapping_profiles.created_by`
-- i `notifications.user_id` — usunięcie wiersza albo zerwałoby te powiązania,
-- albo wymusiło kaskadę, która skasowałaby historię. A historia jest tu warta
-- więcej niż wiersz: pytanie „kto zmienił mapowanie w marcu" musi mieć odpowiedź
-- także wtedy, gdy ta osoba dawno nie pracuje.
--
-- `disabled` to konto istniejące, ale bez wstępu: sesje i tokeny „zapamiętaj mnie"
-- są unieważniane w chwili dezaktywacji (Users::setStatus).
ALTER TABLE users
  ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'
    COMMENT 'disabled = konto zostaje w bazie, ale nie da się zalogować';

-- 2. WYMUSZONA ZMIANA HASŁA PRZY PIERWSZYM LOGOWANIU.
--
-- Hasło zakłada administrator i pokazuje je raz na ekranie. Do momentu zmiany
-- zna je DWOJE ludzi, a to o jedną osobę za dużo. Flaga zdejmuje się sama przy
-- pierwszej udanej zmianie hasła przez właściciela konta.
ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = do czasu zmiany hasła panel wpuszcza wyłącznie na ekran zmiany';

-- 3. KTO ZAŁOŻYŁ KONTO.
--
-- `audit_log` też to zapisze, ale tam wpis da się przewinąć i zgubić wśród tysięcy
-- innych. Przy koncie jest to jedno pole, widoczne od razu na liście użytkowników.
ALTER TABLE users
  ADD COLUMN created_by INT UNSIGNED NULL
    COMMENT 'autor konta; NULL dla konta założonego przy instalacji',
  ADD CONSTRAINT fk_users_author FOREIGN KEY (created_by) REFERENCES users(id);

-- Lista użytkowników sortuje po stanie i dacie utworzenia.
CREATE INDEX idx_users_status ON users (status, created_at);
