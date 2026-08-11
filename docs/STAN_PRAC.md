# Stan prac — przekazanie sesji

**Zapisane:** 2026-08-11, 16:35 (Europe/Warsaw) · przed restartem maszyny.

Ten plik jest punktem wejścia dla następnej sesji. Kolejność czytania:
`CLAUDE.md` → `README.md` → ten plik → `git log --oneline -10`.

> **UWAGA — lista usterek jest AKTUALNA NA DZIŚ, nie z pamięci.**
> Cztery pozycje zgłoszone wcześniej zostały w tej sesji naprawione i sprawdzone;
> są opisane niżej w sekcji „Naprawione w tej sesji" wraz ze sposobem weryfikacji.
> Nie ścigaj ich ponownie. Za to doszła jedna usterka **poważniejsza niż wszystkie
> zgłoszone** — patrz „Usterki otwarte".

---

## 1. Zrobione i wdrożone

Etapy 0–8 zamknięte. Aplikacja działa na produkcji (`app.coachanalyze.pl`, lh.pl).

| Etap | Zakres | Stan |
|---|---|---|
| 0 | struktura, kontrakty, dokumentacja, CI | wdrożone |
| 1–2 | parser, model kanoniczny, pokrycie, render | wdrożone |
| 3 | logowanie (argon2id, sesja, limiter w Redis, audyt) | wdrożone |
| 4a–4c | panel, kluby, biblioteka meczów | wdrożone |
| 5 | builder raportów | wdrożone |
| 6 | notatnik | wdrożone |
| 7 | publikacja `/r/{club_key}/{token}` | wdrożone |
| 8 | odbiór, runbook | wdrożone |

**Migracje w repozytorium:** `001`, `002`, `004`, `005`, `006`, `007`, `008`.
(`003` została świadomie skasowana — jej treść wchłonęła `002`.)

**Do potwierdzenia na serwerze:** które migracje są faktycznie wykonane.
Z rozmowy wynika stan `001–007`, ale nie zostało to sprawdzone zapytaniem do bazy.
Pierwsza czynność po restarcie:

```sql
SHOW COLUMNS FROM users LIKE 'status';                 -- 008
SHOW COLUMNS FROM imports LIKE 'mapping_profile_id';   -- 007
SHOW TABLES LIKE 'notifications';                      -- 006
```

---

## 2. Napisane, NIEZACOMMITOWANE

Stan drzewa w chwili zapisu tego pliku. Wszystko poniżej zostaje zacommitowane
razem z tym dokumentem — lista służy do odtworzenia, czego dotyczy commit.

### Zarządzanie kontami (nowa funkcja, migracja 008)

| Plik | Czego dotyczy |
|---|---|
| `app/migrations/008_user_accounts.sql` | `users.status`, `users.must_change_password`, `users.created_by` |
| `app/src/Users.php` | role, zakładanie kont, dezaktywacja, reset hasła, uprawnienia |
| `app/src/Views/users_list.php` | ekran kont: lista, zakładanie, hasło pokazywane raz |
| `app/src/Auth.php` | odrzucanie kont wyłączonych, zdejmowanie flagi zmiany hasła |
| `app/public/index.php` | trasy `/uzytkownicy`, `requireCan()`, wymuszona zmiana hasła |
| `app/src/Views/layout.php` | pozycja „Użytkownicy" widoczna tylko dla admina |

### Naprawy usterek produkcyjnych

| Plik | Czego dotyczy |
|---|---|
| `app/src/Imports.php` | **zapis `unmapped_tags` w `coverage_json`** — przyczyna nieosiągalnego kreatora |
| `app/src/Reports.php`, `Matches.php`, `Notes.php`, `Clubs.php`, `Seasons.php` | powtórzone symbole nazwane w SQL (patrz §4) |
| `app/bin/run_job.php` | log konfiguracji tylko przy awarii, etykieta przycisku w mailu |
| `app/src/lang/pl.php` | teksty kont, przycisków maila, przyczyny ostrzeżeń |
| `app/public/assets/app.css` | style kont |
| `app/tests/test_sql_parametry.php` | **nowy skaner** — powtórzone symbole w zapytaniach |
| `.github/workflows/tests.yml` | skaner wpięty w CI |

---

## 3. Naprawione w tej sesji — NIE ŚCIGAĆ PONOWNIE

Cztery pozycje ze zgłoszenia zostały naprawione i sprawdzone przelotem przez
prawdziwy HTTP (nie tylko jednostkowo).

### 3.1 Kreator mapowań nieosiągalny z interfejsu — NAPRAWIONE

**Przyczyna:** `coverage_json` przechowywał wyłącznie `$meta['coverage']`,
a `unmapped_tags` i `unmapped_labels` leżą w `meta.json` **piętro wyżej**.
Nigdy nie były zapisywane, więc `Imports::needsMapping()` zawsze widziało pustkę.

Błąd był niewidoczny, bo ostrzeżenie o niezmapowanych tagach idzie osobno,
w `warnings_json` — raport pokrycia je wypisywał, a kreator ich nie widział.

**Weryfikacja (przelot HTTP + prawdziwy silnik):** wgranie pliku z 7 nowymi
tagami → cron → `/import/{id}` przekierowuje na `/import/{id}/mapowanie` →
zapis profilu v1/v2 → `/import/{id}` przepuszcza → render dostaje profil
w `config.json` → `meta.json` z renderu ma `unmapped_tags: []`.
Próba pominięcia kreatora POST-em na `/import/{id}/generuj`: 0 zadań renderu,
mecz zostaje `draft`.

**Dodatkowo:** kontrola dodana także przy **akcji** generowania, nie tylko przy
ekranie — do renderu da się dojść przyciskiem „wygeneruj ponownie" z `/raporty`.

**Sprawdzone osobno:** pierwszy import klubu **bez profilu**. `Mappings` nie
zakłada istnienia profilu; przy braku dopasowanego klubu kreator pokazuje się
i mówi wprost, że nie ma gdzie zapisać profilu.

### 3.2 Przycisk „Otwórz raport" w mailu o imporcie — NAPRAWIONE

Etykieta zależy teraz od typu powiadomienia: `report.ready` → „Otwórz raport",
`import.pending` → „Sprawdź postęp", `report.failed` → „Zobacz szczegóły".

### 3.3 `run_job.php` zaśmieca log — NAPRAWIONE

Linia „konfiguracja z …" tylko wtedy, gdy `.env` **nie został wczytany**,
albo pod flagą `CA_DEBUG_CONFIG=1`.

### 3.4 `XG_POZA_STRZALEM` myli przy nierozpoznanym tagu — NAPRAWIONE

Tekst ostrzeżenia powstaje w silniku (`engine/coachanalyze/coverage.py`),
którego **nie ruszałem** — `engine/` należy do drugiej sesji. Ostrzeżenie niesie
jednak ustrukturyzowane `tags`, więc przyczynę nazywa warstwa prezentacji
(`Imports::explainWarnings()`): gdy tag jest niezmapowany, ekran dopisuje zdanie
o rzeczywistej przyczynie i odnośnik do mapowań klubu.

Sprawdzone na przypadku z produkcji: tag `Strzał` pisany małymi literami
(słownik silnika zna `STRZAŁ`), przy którym komunikat brzmiał
„tag, który nie jest strzałem: Strzał" — czyli przeczył sam sobie.

Oryginalny komunikat **zostaje**; jest uzupełniany, nie podmieniany, żeby
diagnostyka z logu zgadzała się z tym, co widzi operator.

### 3.5 Zwłoka maila „przetwarzanie w toku" — POTWIERDZONA, DZIAŁA

Sprawdzone na przebiegu workera, nie w teście:
`send_after` = `created_at + 120 s`; przejście crona **przed** terminem zostawia
zadanie nietknięte (0 prób); po terminie, gdy raport jest już gotowy, mail
zostaje odwołany jako `skipped`.

Mail, który dotarł na produkcję, poszedł więc **zasadnie** — przetwarzanie
faktycznie trwało dłużej niż dwie minuty.

---

## 4. Usterki OTWARTE

### 4.1 Powtórzony symbol nazwany w SQL — NAPRAWIONE W KODZIE, NIEWDROŻONE

**To jest ważniejsze niż wszystko zgłoszone wcześniej.** Zgłoszenie mówiło
o „`/raporty` może nadal wywalać Invalid parameter number". Sprawdzenie pokazało,
że usterka dotyczy **pięciu miejsc**, nie jednego:

| Plik | Zapytanie | Co było zepsute |
|---|---|---|
| `Reports.php` | `club_home_id = :club OR club_away_id = :club` | filtr klubu na `/raporty` |
| `Matches.php` | to samo | **filtr klubu w bibliotece meczów** |
| `Notes.php` | `title LIKE :q OR body LIKE :q OR tags_json LIKE :q` | **wyszukiwanie notatek** |
| `Clubs.php` | `club_home_id = :id OR club_away_id = :id` | usuwanie klubu |
| `Seasons.php` | `date_from <= :d AND date_to >= :d` | wykrywanie sezonu z daty |

**Mechanizm:** `Db` ustawia `PDO::ATTR_EMULATE_PREPARES => false`, czyli zapytanie
przygotowuje serwer bazy. MySQL/MariaDB **nie pozwala** wtedy użyć tego samego
symbolu dwa razy i odrzuca zapytanie: `SQLSTATE[HY093]: Invalid parameter number`.

**SQLite powtórzenie przyjmuje.** Testy chodzą na SQLite (`APP_ENV=test`), więc
cały zestaw był zielony, a ekrany wywalały się wyłącznie na produkcji.
**Żaden przebieg testów tego nie złapie** — dlatego powstał skaner kodu:
`app/tests/test_sql_parametry.php` (wpięty w CI).

Poprawione we wszystkich pięciu miejscach. **Wymaga wdrożenia i sprawdzenia
na produkcji** — na MySQL, nie na SQLite:

- `/raporty` z filtrem po klubie,
- `/mecze` z filtrem po klubie,
- `/notatki` z wpisanym wyrażeniem,
- usunięcie klubu,
- wgranie eksportu z datą meczu (wykrywanie sezonu).

### 4.2 Kreator: liczby wystąpień i etykiety towarzyszące — ZROBIONE (silnik 0.9.0)

`meta.json` niesie teraz kształt wzbogacony (`{tag, count, sample_labels}`,
`{label, count}`) oraz `coverage.unanalysed`. Warstwa PHP czytała oba kształty
już wcześniej, więc ekran sam zaczął pokazywać liczby. Szczegóły: `CHANGELOG.md`
[0.9.0] i `docs/KONTRAKT_CLI.md`, sekcja „Dane dla kreatora".

Przy okazji naprawiona usterka silnika wykryta przelotem HTTP: etykieta z regułą
`qualifier: null` („nie analizuj") wracała w `unmapped_labels` przy każdym
renderze — `get()` nie odróżniał jawnego `null` od braku reguły.

### 4.2a Kreator na produkcji: STARE WIERSZE `coverage_json` nie naprawią się same

Ustalone przelotem HTTP (`app/tests/integracja/test_mapowania_http.php`, 37 asercji):
kod z repozytorium zatrzymuje na kreatorze poprawnie — produkcyjny objaw
(puste `mapping_profiles`, `mapping_profile_id` NULL, klub 7, mecze 4 i 5) ma
DWIE warstwy:

1. Produkcja działa na kodzie sprzed naprawy `saveInspection()` (commit
   `32e2f60` niewdrożony) — `coverage_json` bez `unmapped_tags`, więc
   `needsMapping()` zawsze widziało pustkę.
2. **Po wdrożeniu istniejące importy nadal ominą kreator**: ich `coverage_json`
   zapisał stary kod i nic go nie odświeża. „Wygeneruj ponownie" przejdzie bez
   zatrzymania (render bez profilu); dopiero zapis pokrycia PO tym renderze
   uzupełnia wpis. Symulacja w scenariuszu 4 testu HTTP.

**Droga naprawy po wdrożeniu** (sprawdzona w teście): „Ponów" na zadaniu
`inspect` danego importu — działa też dla zadań `done` — albo zbiorczo z SQL:

```sql
INSERT INTO jobs (type, payload_json, status, attempts, created_at)
SELECT 'inspect',
       CONCAT('{"import_id":', i.id, ',"match_id":', i.match_id, '}'),
       'queued', 0, NOW()
  FROM imports i
 WHERE i.coverage_json IS NOT NULL
   AND i.coverage_json NOT LIKE '%unmapped_tags%';
```

Wykonać PO wdrożeniu nowego kodu (inspekcję podnosi cron w ciągu minuty).

### 4.3 Migracje 006, 007, 008 do wdrożenia

W tej kolejności. **008 jest bezpieczna przy odwrotnej kolejności** (kod przed
migracją): `Auth` czyta `SELECT *` z wartościami domyślnymi i logowanie działa
także na bazie bez tych kolumn — sprawdzone osobno. Przy 006 i 007 tej odporności
**nie ma**.

---

## 5. Otwarte pytania do analityka

Znaczenie skrótów i tagów z eksportów klienta. Bez odpowiedzi nie da się ich
przypisać do pojęć kanonicznych — a przypisanie „na wyczucie" zmieniłoby liczby
w raporcie, czyli dokładnie to, przed czym stoi test złoty.

| Oznaczenie | Pytanie |
|---|---|
| **WP** | wyjście z pressingu? wznowienie gry przez bramkarza? coś innego? |
| **PP** | przejęcie piłki? podanie prostopadłe? pressing pozycyjny? |
| **P2**, **P3** | strefy boiska (druga, trzecia)? fazy gry? podania w kategoriach? |
| **PK** | rzut karny? podanie kluczowe? |
| **SFG** | potwierdzić: stały fragment gry (tak zakłada dziś słownik silnika) |
| **PIERWSZY / DRUGI KONTAKT** | czy DRUGI KONTAKT to osobne zdarzenie, czy kwalifikator PIERWSZEGO? |
| **AKCJA DEFENSYWNA** | zbiorcza kategoria? Jeśli tak, to czego dokładnie — odbioru, pressingu, pojedynku? Dziś celowo nierozpoznana. |

Do każdego potrzebne: **czy to zdarzenie, czy uszczegółowienie zdarzenia**.
Zdarzenie → pojęcie kanoniczne (lista zamknięta, `docs/MODEL_KANONICZNY.md`).
Uszczegółowienie → kwalifikator przy istniejącym pojęciu.

Zgłoszenia brakujących pojęć zbiera tabela `concept_requests` (migracja 007) —
warto ją przejrzeć przed rozmową.

---

## 6. Następne zadanie w kolejce

**Zarządzanie kontami z panelu — NAPISANE w tej sesji, do wdrożenia i odbioru.**

Zakres zrealizowany: lista kont, zakładanie z hasłem generowanym CSPRNG
(16 znaków, pokazywane raz), wymuszona zmiana hasła przy pierwszym logowaniu,
zmiana roli, dezaktywacja zamiast usuwania, reset hasła, wszystko w `audit_log`.

Zabezpieczenia sprawdzone przez HTTP: operator dostaje **404** na `/uzytkownicy`
(nie 403 — rola bez uprawnienia nie ma powodu wiedzieć, że ta część istnieje),
admin nie zmieni własnej roli ani się nie wyłączy, ostatniego administratora nie
da się zdegradować, dezaktywacja unieważnia sesje i tokeny, wyłączone konto przy
logowaniu dostaje **ten sam komunikat** co przy złym haśle.

Uprawnienia ról: `admin` wszystko, `operator` bez zarządzania kontami,
`viewer` tylko podgląd raportów i notatek (bez uploadu i bez udostępniania).

Ekran zakładania konta niesie ostrzeżenie: *„W tej wersji każde konto widzi dane
wszystkich klubów. Separacja danych między klientami wymaga osobnego wdrożenia."*

### Co dalej, po wdrożeniu kont

1. Wdrożyć migracje 006, 007, 008 i potwierdzić naprawy z §4.1 na MySQL.
2. Rozmowa z analitykiem (§5) i uzupełnienie słownika mapowań.
3. Po wdrożeniu: przeinspekcjonować stare importy (§4.2a) — inaczej kreator
   nadal ominie mecze 4 i 5 klubu 7.

---

## 7. Testy — stan wyjściowy

Uruchamiane przed każdym commitem. Stan przy zapisie tego pliku:

```
silnik (pytest):            107 passed, 1 skipped
PHP, łącznie:               724 asercje, 0 błędów
```

Zestawy w repozytorium (CI): `test_layout`, `test_forms`,
`test_disabled_functions`, `test_php_wersje`, `test_chmurki`, `test_sql_parametry`.

Zestawy integracyjne zostały **przeniesione do repozytorium**
(`app/tests/integracja/`) wraz z atrapami i syntetycznymi danymi — wcześniej
leżały w katalogu roboczym sesji i restart by je skasował.

**Nie są wpięte w CI**, bo wymagają środowiska (gniazdo Redis, venv Pythona,
wolny port). Uruchamianie opisuje `app/tests/integracja/README.md`:

```
test_etap3  test_4a  test_4b  test_4c  test_7  test_remember
test_kolejka  test_powiadomienia  test_smtp  test_mapowania  test_konta
test_chmurki.js
```

Nazwy klubów w danych testowych są neutralne (`KLUB A`, `KLUB B`), pliki `*.csv`
syntetyczne. **Eksporty klienta do tego katalogu nie trafiają** (CLAUDE.md §7).
