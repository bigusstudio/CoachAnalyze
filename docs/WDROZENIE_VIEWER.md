# Wdrożenie pivotu „viewer" na produkcję

**Dla człowieka, krok po kroku.** Każdy krok ma polecenie i kontrolę, po której
wiadomo, czy się udał. Nie ma tu niczego automatycznego poza `deploy.sh`.

Stan wyjściowy: produkcja stoi na gałęzi `main` (wersja `pro`), a cała praca
pivotu leży na gałęzi `viewer`. Silnik na `viewer` to **0.16.1**.

> **Migracje 014–017 są ADDYTYWNE** (`docs/STAN_PIVOTU.md` §4). Nałożone na bazę
> z działającą wersją `pro` niczego nie psują: `pro` tych tabel nie czyta i nie
> zapisuje. To jest cała podstawa, na której stoi procedura powrotu w kroku 8.

---

## 0. Zanim zaczniesz — czego potrzebujesz

| | |
|---|---|
| Dostęp | SSH do konta na lh.pl |
| Czas | ~20 minut na wdrożenie + tyle, ile cron zajmie regeneracja raportów |
| Okno | takie, w którym klient nie generuje raportów (regeneracja obciąża kolejkę) |
| Wyjście awaryjne | krok 8 — powrót zajmuje minutę, bo migracje są addytywne |

```bash
ssh <konto>@<serwer>
cd ~/CoachAnalyze/repo
git status          # ma być czysto; cokolwiek tu wisi, najpierw wyjaśnij
```

---

## 1. Zrzut bazy — PRZED czymkolwiek

`deploy.sh` robi własny zrzut, ale dopiero na końcu przygotowań. Ten jest
Twój i robisz go, zanim tkniesz cokolwiek.

```bash
DB=$(grep -E '^DB_NAME=' ~/CoachAnalyze/shared/.env | tail -1 | cut -d= -f2-)
echo "baza: $DB"          # SPRAWDŹ NAZWĘ, zanim przejdziesz dalej

mkdir -p ~/CoachAnalyze/shared/backups
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  "$DB" > ~/CoachAnalyze/shared/backups/przed-viewer-$(date +%F-%H%M).sql
```

**Kontrola:**

```bash
ls -la ~/CoachAnalyze/shared/backups/ | tail -3
```

Plik ma mieć **więcej niż kilka kilobajtów**. Zrzut o rozmiarze 0 B wygląda
w katalogu dokładnie jak kopia i zostaje odkryty dopiero przy próbie
odtworzenia — czyli w najgorszym możliwym momencie.

```bash
tail -1 ~/CoachAnalyze/shared/backups/przed-viewer-*.sql
```

Ostatnia linia poprawnego zrzutu to `-- Dump completed on …`.

---

## 2. Migracje 014–017 — NAJPIERW NA PRÓBNEJ

Baza próbna to `serwer400227_caproba`. Nakładamy tam, oglądamy, dopiero potem
produkcja.

```bash
cd ~/CoachAnalyze/repo
git fetch --all
git checkout viewer
git pull --ff-only

for M in 014_events_i_meta 015_katalog_tagow 016_alias_tagu 017_sklad_meczu; do
  echo "== $M"
  mysql --defaults-group-suffix=caproba serwer400227_caproba \
    < app/migrations/$M.sql || { echo "PADŁO NA $M"; break; }
done
```

**KOLEJNOŚĆ NIE JEST DOWOLNA.** `016` dokłada kolumnę do tabeli z `015`;
uruchomione odwrotnie wywali się na „unknown table".

**Kontrola po próbnej:**

```bash
mysql --defaults-group-suffix=caproba serwer400227_caproba -e "
  SHOW TABLES LIKE 'events';
  SHOW TABLES LIKE 'tag_catalog';
  SHOW TABLES LIKE 'match_players';
  SHOW COLUMNS FROM matches LIKE 'round';
  SHOW COLUMNS FROM tag_catalog LIKE 'alias_of';
"
```

Pięć niepustych odpowiedzi. Pusta odpowiedź znaczy, że któraś migracja przeszła
tylko częściowo — **nie idź dalej**, przeczytaj komunikat z pętli wyżej.

## 2b. Migracje na produkcji

```bash
for M in 014_events_i_meta 015_katalog_tagow 016_alias_tagu 017_sklad_meczu; do
  echo "== $M"
  mysql "$DB" < app/migrations/$M.sql || { echo "PADŁO NA $M"; break; }
done
```

**Kontrola — to samo zapytanie, na produkcyjnej bazie:**

```bash
mysql "$DB" -e "
  SHOW TABLES LIKE 'events';
  SHOW TABLES LIKE 'tag_catalog';
  SHOW TABLES LIKE 'match_players';
  SHOW COLUMNS FROM matches LIKE 'round';
  SHOW COLUMNS FROM tag_catalog LIKE 'alias_of';
"
```

Aplikacja dalej chodzi na `pro` i nic się nie zmieniło — nowe tabele są puste
i nikt ich jeszcze nie czyta. To jest bezpieczny stan pośredni; można w nim
zostać dowolnie długo.

---

## 3. `shared/.env` — włączenie generacji v21

```bash
grep -n 'HTML_TEMPLATE' ~/CoachAnalyze/shared/.env || echo "(brak wpisu)"
```

Dopisz albo popraw:

```
HTML_TEMPLATE=v21
```

**DLACZEGO TO JEST OSOBNY KROK, A NIE CZĘŚĆ KODU.** Domyślną generacją szablonu
zostaje `v17` — ta, której wyjście odtwarza test złoty co do bajtu
(`docs/STAN_PIVOTU.md` §6). Przestawienie domyślnej w kodzie znaczyłoby, że
bramka wdrożenia sprawdza inny plik, niż produkuje produkcja. Wybór generacji
jest więc decyzją środowiska i stoi w `.env`.

**Kontrola:** po wdrożeniu (krok 5) pierwszy wygenerowany raport ma mieć
nagłówek transmisyjny z wynikiem i sekcję **Przegląd**. Raport bez nich to
raport v17 — wróć tutaj i sprawdź pisownię wpisu.

---

## 4. Merge `viewer` → `main`

```bash
cd ~/CoachAnalyze/repo
git checkout main
git pull --ff-only
git merge --ff-only viewer
```

**`--ff-only`, BEZ SQUASHA.** Historia pivotu to szesnaście commitów, z których
każdy niesie powód decyzji w treści. Squash zamienia to w jeden commit „pivot"
i pytanie „dlaczego kierunek ataku liczy się z mediany strzałów" przestaje mieć
odpowiedź w `git log`.

Gdy `--ff-only` odmówi, znaczy to, że na `main` jest commit, którego nie ma na
`viewer`. **Nie rób wtedy merge'a zwykłego** — najpierw sprawdź, co to:

```bash
git log --oneline viewer..main
```

---

## 5. Wdrożenie

```bash
bash deploy/deploy.sh main
```

Skrypt sam:

1. pobiera zmiany i instaluje silnik,
2. uruchamia **test złoty** — czerwony zatrzymuje wdrożenie,
3. robi własny zrzut bazy,
4. **sprawdza migracje 014–017** i przerywa, jeśli którejś brakuje,
5. synchronizuje katalog webowy,
6. przeprowadza kontrole: `.env`, `STORAGE_PATH`, `LOG_PATH`, 403 na plikach
   wewnętrznych, HTTPS, nagłówki, buforowanie, **`/api/metryki` bez sesji**.

**Kontrola:** ostatnia linia ma brzmieć `==> Gotowe: <skrót>`. Cokolwiek
z `!!! BŁĄD` w środku — czytaj komunikat, tam jest napisane, co poprawić.

---

## 6. Kontrola po wdrożeniu — ręcznie, w przeglądarce

| Co | Gdzie | Czego oczekujesz |
|---|---|---|
| Logowanie | `https://app.coachanalyze.pl/login` | wchodzi, bez komunikatu o szyfrowaniu |
| Pulpit | `/pulpit` | karta „Ostatni mecz", kafle KPI, pasek sezonu |
| Menu sezonowe | `/sezon` | lista kolejek; kliknięcie kolejki otwiera kartę meczu |
| Zawodnicy | `/zawodnicy` | lista albo zdanie „brak zawodników" — nie błąd 500 |
| Kalendarz | `/kalendarz` | siatka miesiąca, strzałki przewijają miesiące |
| Metryki bez sesji | `curl -o /dev/null -w '%{http_code}\n' https://app.coachanalyze.pl/api/metryki?club=1` | **401** |

Pulpit i menu sezonowe pokażą **kreski zamiast liczb**, dopóki nie wykonasz
kroku 7 — i to jest poprawne zachowanie, nie usterka. Tabela zdarzeń jest pusta,
bo dopiero powstała.

---

## 7. Regeneracja raportów — wypełnienie `events` i `tag_catalog`

Migracje zakładają puste tabele. Wypełnia je silnik, przy generowaniu raportu.
Mecze zaimportowane przed wdrożeniem mają więc raporty na dysku i **zero wierszy
w tabeli zdarzeń**.

```bash
cd ~/CoachAnalyze/repo

# NAJPIERW LISTA, bez kolejkowania:
php app/repairs/regeneruj_raporty.php --all --dry-run
```

Przeczytaj wynik. Pozycje z `← BRAK SUROWYCH PLIKÓW` nie dadzą się przeliczyć:
ich CSV nie ma już na dysku. To nie jest awaria wdrożenia — te mecze zostaną
z raportem, ale bez liczb, dopóki ktoś nie wgra plików ponownie
(panel → mecz → „Wgraj ponownie").

```bash
# Kolejkowanie:
php app/repairs/regeneruj_raporty.php --all
```

**Kontrola postępu:**

```bash
mysql "$DB" -e "
  SELECT status, COUNT(*) FROM jobs
   WHERE type = 'rebuild_report' GROUP BY status;"
```

Zadania podnosi cron, pojedynczo, co minutę. Dwadzieścia meczów to około
dwudziestu minut.

**Kontrola końcowa:**

```bash
mysql "$DB" -e "
  SELECT COUNT(*) AS zdarzenia FROM events;
  SELECT COUNT(*) AS tagi FROM tag_catalog;"
```

Obie liczby większe od zera. Potem odśwież `/pulpit` — kreski zamieniają się
w liczby.

---

## 8. Powrót do `pro`, gdyby coś poszło nie tak

**ZWYKLE NIE TRZEBA RUSZAĆ BAZY.** Migracje 014–017 są addytywne: `pro` nowych
tabel nie czyta i nie zapisuje, więc ich obecność jest dla niej niewidoczna.
Powrót to wdrożenie starszego kodu.

```bash
cd ~/CoachAnalyze/repo
git checkout pro-1.0
bash deploy/deploy.sh pro-1.0
```

**Kontrola:** `/login`, `/pulpit`, jeden istniejący raport otwiera się z dysku.

### Kiedy zrzut jest jednak potrzebny

Wyłącznie wtedy, gdy zmieniły się dane, których `pro` używa — czyli gdy po
wdrożeniu ktoś zdążył:

- zapisać nową wersję templatu (kreator układu, kontynuacja zmiennej),
- zmienić meta meczu albo skład,
- wygenerować raport na generacji v21 (plik HTML zostaje podmieniony).

Wtedy:

```bash
mysql "$DB" < ~/CoachAnalyze/shared/backups/przed-viewer-<data>.sql
```

**To kasuje także wszystko, co powstało po wdrożeniu.** Zanim to zrobisz,
przeczytaj `docs/STAN_PIVOTU.md` §5 — jest tam pełna procedura razem z plikami
raportów, a `app/repairs/przywroc_pro.sh` pozwala przećwiczyć ją na bazie
próbnej, nigdy na produkcji.

---

## Czego ta procedura NIE obejmuje

- **Templatów klubów.** Istniejące templaty (schemat 1) działają bez zmian
  i wracają do stanu domyślnego dla kafli dołożonych w pivocie. Nikt nie musi
  ich przepisywać.
- **Składów.** Tabela `match_players` startuje pusta i to jest poprawne — skład
  wpisuje operator, mecz po meczu, gdy uzna za potrzebne.
- **Kolejek.** `matches.round` startuje puste. Nagłówek raportu i pasek sezonu
  poradzą sobie bez niej (człon po prostu znika).
