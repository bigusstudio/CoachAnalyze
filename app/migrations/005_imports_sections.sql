-- Sekcje raportu wyliczone przez `inspect`.
--
-- POWÓD: ekran raportu pokrycia pokazuje, które sekcje będą niedostępne i DLACZEGO.
-- Dotąd brało się to z wywołania silnika przy każdym otwarciu strony. PHP-FPM na
-- lh.pl nie może uruchomić procesu (disable_functions), więc wynik `inspect`
-- musi być ZAPISANY przez proces roboczy i odczytany z bazy.
--
-- Bez tej kolumny powód niedostępności sekcji przepadał i ekran pokrycia
-- pokazywałby same liczby, bez wyjaśnienia — czyli dokładnie to, czego
-- CLAUDE.md §8 zabrania.

ALTER TABLE imports
  ADD COLUMN sections_json JSON NULL
  COMMENT 'sections_available i sections_unavailable z meta.json';
