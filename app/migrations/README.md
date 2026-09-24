# Migracje schematu

Numerowane, wyłącznie „w przód", **nigdy edytowane po wdrożeniu** (CLAUDE.md §7).
Zmiany DANYCH mają własny katalog i własne zasady — `app/repairs/README.md`.

## Stan faktyczny

| | |
|---|---|
| Pliki | `001`…`013`, **bez `003`** |
| Tabela śledząca | **nie ma** |
| Kto uruchamia | człowiek, ręcznie, po zrzucie bazy |
| Baza produkcyjna | `serwer400227_coachanalyze` |
| Baza próbna | `serwer400227_caproba` — schemat = produkcja po `013` |

**Braku `003` nie łatamy.** Numer został zużyty i porzucony przed wdrożeniem;
wstawienie tam czegokolwiek dziś znaczyłoby, że dwie instalacje o tej samej
„wersji schematu" mają różny schemat. Luka jest tańsza niż ta niejednoznaczność.

**Braku tabeli śledzącej też nie nadrabiamy w tej sesji.** Migracje uruchamia
człowiek i wie, co uruchomił; tabela wprowadzona teraz musiałaby zostać
zabackfillowana „na wiarę" dla trzynastu pozycji. To zadanie na osobną decyzję,
nie efekt uboczny innej pracy.

---

## ZASADA OD `014`: WYŁĄCZNIE MIGRACJE ADDYTYWNE

Od migracji `014` obowiązuje twardo:

| Wolno | Nie wolno |
|---|---|
| `CREATE TABLE` | `DROP TABLE`, `DROP COLUMN` |
| `ALTER TABLE … ADD COLUMN … NULL` | `ADD COLUMN … NOT NULL` bez wartości domyślnej |
| `CREATE INDEX` | `MODIFY COLUMN` na kolumnie zastanej |
| `UPDATE` backfillujący nową kolumnę | `DELETE FROM`, zmiana typu, zwężenie `ENUM` |

### Dlaczego — to nie jest higiena, tylko warunek odwrotu

Po pivocie „viewer" **`pro` i `viewer` mają stać na JEDNYM schemacie**. Gałąź `pro`
(tag `pro-1.0`) jest drogą powrotu, jeśli kierunek „viewer" okaże się ślepy —
a droga powrotu, która wymaga odtworzenia zrzutu bazy, kosztuje wszystkie dane
wprowadzone po pivocie.

Migracja addytywna sprawia, że kod `pro` na nowym schemacie po prostu **nie widzi**
kolumn, których nie zna. Migracja z `DROP` albo `MODIFY` sprawia, że **wywala się
na `INSERT`** — i powrót przestaje być decyzją produktową, a staje się operacją
na danych. Różnica jest dokładnie taka, jak przy migracji `012`, gdzie
`club_id NOT NULL` uczyniło kod sprzed przebudowy niekompatybilnym z bazą po niej
(docs/PRZEBUDOWA_KLUB_SESJE.md, PRE-FLIGHT pkt 4).

Pełny opis, co jest uśpione i jak wygląda powrót: **`docs/STAN_PIVOTU.md`**.

### Kolumna, której „trzeba" się pozbyć

Nie pozbywamy się jej. Przestaje być zapisywana, zostaje z komentarzem
`-- nieużywana od 0XX, zostaje dla zgodności z pro-1.0` i czeka. Usunięcie
kolumny jest operacją jednorazową i nieodwracalną; jej nieużywanie kosztuje
kilkanaście bajtów na wiersz.

`DROP` wraca do gry dopiero wtedy, gdy powrót do `pro` przestanie być opcją —
a to jest decyzja, którą ktoś podejmuje i zapisuje, nie stan, do którego się
dryfuje.

---

## Rytuał uruchomienia

**Zrzut przed KAŻDĄ migracją. Bez wyjątku, także przy „drobnej".**

```bash
# 1. Zrzut — nazwa mówi, przed czym
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  serwer400227_coachanalyze > ~/CoachAnalyze/shared/backups/przed_014_$(date +%F).sql
ls -lh ~/CoachAnalyze/shared/backups/przed_014_*.sql   # rozmiar > 0, data dzisiejsza

# 2. Próba na bazie próbnej — NAJPIERW, nie „jak będzie czas"
mysql --defaults-group-suffix=caproba serwer400227_caproba < app/migrations/014_....sql

# 3. Dopiero potem produkcja
mysql serwer400227_coachanalyze < app/migrations/014_....sql
```

Migracja kończy się blokiem `SELECT`, który potwierdza wynik — tak jak `012` i `013`.
Migracja bez kontroli po uruchomieniu jest migracją, o której nie wiadomo, czy przeszła.

### Baza próbna to nie kopia produkcji

`serwer400227_caproba` istnieje od sierpnia 2026 jako środowisko próbne migracji.
Ma schemat produkcji po `013`, ale **dane z 2026-08-19** (8 meczów wobec 23 na
produkcji). Nadaje się do sprawdzenia, czy migracja przechodzi; nie nadaje się do
sprawdzenia, ile potrwa na produkcji ani czy backfill trafi we wszystkie wiersze.

Dostęp: `mysql --defaults-group-suffix=caproba` (sekcja `[clientcaproba]` w `~/.my.cnf`).
Sufiks jest **jedyną** rzeczą odróżniającą to połączenie od produkcyjnego — polecenie
bez niego idzie na produkcję. Stąd twarda blokada nazwy bazy w `app/repairs/przywroc_pro.sh`.
