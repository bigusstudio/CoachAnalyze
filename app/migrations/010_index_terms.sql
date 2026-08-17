-- Indeks współczynników (moduł M1) — słownik metodyczny klubu.
--
-- HASŁA DOMYŚLNE NIE LEŻĄ W TEJ TABELI. Punkt wyjścia (definicje wskaźników,
-- które silnik już liczy) jest stałą w kodzie (IndexTerms::DOMYSLNE) — dzięki
-- temu nowa instalacja ma komplet haseł bez kroku wypełniania danych, a ich
-- treść podlega przeglądowi kodu jak każda inna zmiana metodyczna.
--
-- Tabela przechowuje WERSJE KLUBOWE: klub edytuje hasło pod własną metodykę,
-- a każda zmiana to NOWY WIERSZ z podbitą wersją — poprzednie zostają,
-- z tego samego powodu co w mapping_profiles: „czemu raport z marca opisywał
-- ten wskaźnik inaczej" musi mieć odpowiedź.
--
-- `slug` identyfikuje hasło trwale (np. 'xg', 'celnosc'); `concept` przypina
-- je do pojęcia kanonicznego, nie do nazwy tagu — słowniki tagów się zmieniają,
-- pojęcia nie (docs/MODEL_KANONICZNY.md).
--
-- `estimated_note` niepuste oznacza wskaźnik SZACOWANY (wartość z modelu, nie
-- wprost z eksportu) — treść to opis ograniczeń metody, pokazywany w indeksie
-- i przy wskaźniku w raporcie.

CREATE TABLE index_terms (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  club_id         INT UNSIGNED NOT NULL,
  slug            VARCHAR(60)  NOT NULL,
  concept         VARCHAR(40)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  definition      TEXT NOT NULL,
  formula         TEXT NULL,
  example         TEXT NULL,
  interpretation  TEXT NULL,           -- „co to znaczy dla trenera"
  source          TEXT NULL,           -- źródło danych wskaźnika
  estimated_note  TEXT NULL,           -- niepuste = wskaźnik szacowany + ograniczenia metody
  version         INT NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_index_terms_wersja (club_id, slug, version),
  KEY ix_index_terms_slug (slug),

  CONSTRAINT fk_index_terms_club
    FOREIGN KEY (club_id) REFERENCES clubs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
