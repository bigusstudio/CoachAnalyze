# Stan pivotu „viewer" — co jest uśpione, co zarchiwizowane, jak wrócić

**Data:** 2026-09-24 · **Sesja:** 0 · **Gałąź:** `viewer` (od `main`)
**Punkt powrotu:** tag `pro-1.0` = `ae70a34`, gałąź `pro` na GitHubie

Ten dokument istnieje po to, żeby powrót do wersji `pro` był **decyzją**, a nie
archeologią. Opisuje trzy rzeczy: co przestaje być rozwijane, co leży w archiwum
i jak dokładnie wykonać powrót — krokami, które ktoś wykonał, a nie takimi,
które brzmią sensownie.

---

## 1. Dlaczego w ogóle jest pivot

Wersja `pro` celuje w klub, który ma analityka: buduje model kanoniczny, mapuje
własny słownik tagów przez konfigurator i liczy metryki, których nikt poza sztabem
nie zobaczy. To działa i jest zrobione — ale wymaga od klienta pracy **przed**
pierwszym raportem.

„Viewer" odwraca kolejność: raport najpierw, konfiguracja potem albo wcale.
Stąd nowa generacja szablonu (v21) i stąd ta sesja.

**To nie jest porzucenie `pro`.** Kod żyje pod tagiem, dane leżą w archiwum,
a schemat bazy od migracji `014` jest utrzymywany tak, żeby obie wersje mogły
stać na tym samym — patrz punkt 4.

---

## 2. Co zostaje UŚPIONE

Uśpione znaczy: **kod zostaje w repozytorium, testy dalej przechodzą, nikt tego
nie rozwija i nie ma tego w ścieżce nowego użytkownika.** Nie znaczy: usunięte.

### 2.1 Silnik kanoniczny jako warstwa widoczna dla użytkownika

`engine/coachanalyze/canon.py` + `metrics.py` + `--out-canon` / `--out-metrics`
zostają i dalej liczą — od nich zależy archiwum (`events_canonical`), porównania
sezonowe i `crosscheck` renderu.

**Usypiamy to, co ODWOŁUJE SIĘ DO NICH W INTERFEJSIE:** ekrany, które każą
operatorowi rozumieć pojęcia kanoniczne (`shot`, `entry_sbz`, `team_side`), zanim
zobaczy pierwszy raport.

> Czego NIE ruszać przy usypianiu: szablon v21 liczy w JS po **surowych nazwach
> tagów** (`e.tag==='STRZAŁ'`), tak samo jak v17. Model kanoniczny jest dla niego
> niewidoczny. Wyłączenie `--out-canon` „bo i tak nieużywane" zabrałoby archiwum,
> a raport wyglądałby identycznie — czyli nic by nie zapaliło się na czerwono.

### 2.2 Wizard AI mapowań

Kreator (`Mappings`, `HeuristicSuggester`, `mapping_profiles`) i konfigurator
(`Configurator`, `club_report_templates`) zostają wdrożone i działające dla klubów,
które już przez nie przeszły. **Nowy klub nie jest przez nie prowadzony.**

Powód jest ten sam, co przy pivocie: to praca przed pierwszym raportem.
Backlog przebudowy przewidywał podpięcie modelu językowego pod podpowiedzi
bindingów (docs/PRZEBUDOWA_KLUB_SESJE.md, „PO SESJACH 1–7") — **to zadanie
wypada z planu**, dopóki wizard jest uśpiony.

Ostrzeżenie z tamtego backlogu zostaje w mocy i dotyczy każdej przyszłej próby:
do modelu wolno wysłać **nazwy tagów i policzone metryki, nigdy próbek zdarzeń**
z `meta.dictionary[].samples` (CLAUDE.md §5).

### 2.3 Reguła „brak bindingu = tylko licznik"

Zasada z Sesji 4 przebudowy (pkt 2): zmienna bez bindingu kanonicznego dostaje
wyłącznie widoki generyczne — licznik w bilansie i pas na osi czasu; sekcje
wymagające semantyki (mapy, xG) są dla niej zablokowane.

**Reguła zostaje zapisana i przestaje być rozwijana.** Generyczny renderer, który
miał ją obsłużyć po stronie szablonu (S5b, pkt 5), **nie powstał i w pivocie nie
powstanie**. W praktyce znaczy to, że zmienna `canon: null` dziś nigdzie się nie
rysuje — jest policzona w pokryciu i tyle.

To jest dług, nie funkcja. Zapisany tutaj, żeby nikt nie odkrywał go przez
zdziwienie, że tag „jest w templacie, a nie ma go w raporcie".

### 2.4 Czego NIE usypiamy

| Zostaje w pełni | Dlaczego |
|---|---|
| Parser LiveTag i wszystkie 11 pułapek | To jest produkt, nie warstwa |
| Test złoty i manifest | Bramka wdrożenia dla obu wersji |
| Kolejka cron, wskaźnik pracy, chmurki | Nie zależą od modelu kanonicznego |
| Publiczne raporty `/r/{club_key}/{token}` | Adresy rozesłane sztabom muszą żyć |
| Kalkulator xG, indeks współczynników | Samodzielne, bez związku z pivotem |

---

## 3. Archiwum wersji `pro`

### 3.1 Kod

| Gdzie | Co |
|---|---|
| Tag `pro-1.0` | = `ae70a34`, wypchnięty na GitHub |
| Gałąź `pro` | wskazuje ten sam commit, na GitHubie |

### 3.2 Dane i pliki — `~/CoachAnalyze/archiwum/pro-1.0/`

| Plik | Co niesie |
|---|---|
| `prod_2026-09-24.sql.gz` | pełny zrzut bazy produkcyjnej `serwer400227_coachanalyze` |
| `app_public_html_2026-09-24.tgz` | **cała aplikacja z katalogu domeny, razem ze `storage/uploads`** |
| `shared_2026-09-24.tgz` | `shared/` — konfiguracja (`.env`) i katalog zrzutów |
| `repo_storage_2026-09-24.tgz` | `storage/` z repozytorium |
| `repo_HEAD_2026-09-24.txt` | commit, na którym stała produkcja w chwili archiwizacji |
| `SUMY_2026-09-24.txt` | skróty SHA-256 wszystkich powyższych |

**Kopia poza serwerem: u Tomasa.** To jest istotne i nie jest formalnością —
archiwum leżące wyłącznie na serwerze, który ma być odtwarzany, nie jest archiwum.

**Stan produkcji w chwili archiwizacji:** 10 klubów, 23 mecze, 24 raporty.
Skrypt powrotu sprawdza te liczby po odtworzeniu — rozjazd znaczy, że zrzut
pochodzi z innego dnia niż ten dokument.

### 3.3 Czego w archiwum NIE MA i dlaczego

- **Surowych eksportów CSV/JSON osobno** — leżą w `storage/uploads`, czyli wewnątrz
  `app_public_html_*.tgz`. Jeden plik zamiast dwóch, bo rozdzielenie ich znaczyłoby
  dwa momenty w czasie i ryzyko, że baza wskazuje na plik, którego w kopii nie ma.
- **Danych meczowych w repozytorium** — to dane taktyczne klienta (CLAUDE.md §7).
  W `engine/tests/golden/` są wyłącznie skróty oczekiwanych wyjść.

---

## 4. Zasada migracji: od `014` wyłącznie addytywne

**`pro` i `viewer` stoją na JEDNYM schemacie.** Pełny opis i ściąga w
`app/migrations/README.md`; tutaj powód, bo należy do tego dokumentu:

Powrót do `pro`, który wymaga odtworzenia zrzutu bazy, kosztuje **wszystkie dane
wprowadzone po pivocie**. Migracja addytywna sprawia, że kod `pro` na nowym
schemacie po prostu nie widzi kolumn, których nie zna. Migracja z `DROP` albo
`MODIFY` sprawia, że **wywala się na `INSERT`** — i powrót przestaje być decyzją
produktową, a staje się operacją na danych.

Tak było przy migracji `012`: `matches.club_id NOT NULL` uczyniło kod sprzed
przebudowy niekompatybilnym z bazą po niej (docs/PRZEBUDOWA_KLUB_SESJE.md,
PRE-FLIGHT pkt 4). Ta cena została zapłacona raz, świadomie. Drugi raz jej nie płacimy.

W skrócie:

| Wolno | Nie wolno |
|---|---|
| `CREATE TABLE`, `ADD COLUMN … NULL`, `CREATE INDEX` | `DROP TABLE`, `DROP COLUMN`, `MODIFY` na kolumnie zastanej |
| `UPDATE` backfillujący nową kolumnę | `DELETE FROM`, zmiana typu, zwężenie `ENUM` |

Kolumny, która przestała być potrzebna, **nie usuwamy** — przestaje być zapisywana
i zostaje z komentarzem `-- nieużywana od 0XX, zostaje dla zgodności z pro-1.0`.

Migracje nakłada **człowiek, ręcznie, po zrzucie bazy**. Tabeli śledzącej nie ma
i ta sesja jej nie dokłada.

---

## 5. Procedura powrotu do `pro`

> **Ta procedura została napisana pod STAN FAKTYCZNY serwera, nie pod RUNBOOK.**
> Katalogów `current/` i `releases/` **nie ma** — `releases/` jest pusty, a aplikacja
> mieszka wprost w `~/public_html/app.coachanalyze.pl/`. Sekcja „Wycofanie wdrożenia"
> w `docs/RUNBOOK.md` została w tej sesji poprawiona; jeśli gdziekolwiek natkniesz
> się na `ln -sfn releases/… current`, to jest ślad po układzie, który nigdy nie
> wystartował.

### 5.1 Najpierw próba, potem produkcja

Powrót ćwiczy się na bazie próbnej. Służy do tego:

```bash
bash app/repairs/przywroc_pro.sh ~/tmp/pro-1.0-uploads --sprawdz-tylko   # same asercje
bash app/repairs/przywroc_pro.sh ~/tmp/pro-1.0-uploads                   # z importem
```

Skrypt odtwarza **dane** wersji `pro` na `serwer400227_caproba` i rozpakowuje
uploady do wskazanego katalogu. **Produkcji nie dotyka** — nazwa bazy docelowej
jest stałą w kodzie, a skrypt odmawia startu, jeśli ta nazwa zgadza się z `DB_NAME`
z `shared/.env`.

Czego skrypt świadomie **nie** robi: nie przestawia repozytorium, nie wdraża kodu
i nie generuje raportu. To są kroki na produkcji i należą do człowieka.

### 5.2 Powrót właściwy, na produkcji

```bash
# 1. Kod na tag pro-1.0
git -C ~/CoachAnalyze/repo fetch --all --tags
git -C ~/CoachAnalyze/repo checkout pro-1.0

# 2. Wdrożenie tą samą drogą co zawsze.
#    deploy.sh zrobi zrzut bazy PRZED synchronizacją, uruchomi test złoty
#    i po wdrożeniu sprawdzi .htaccess, HTTPS i nagłówki.
bash ~/CoachAnalyze/repo/deploy/deploy.sh

# 3. Import zrzutu produkcyjnego — TYLKO jeśli schemat zdążył się rozjechać
#    tak, że kod pro na nim nie działa. Przy zasadzie z punktu 4 ten krok
#    JEST ZBĘDNY i pominięcie go zachowuje dane z okresu „viewer".
gunzip -c ~/CoachAnalyze/archiwum/pro-1.0/prod_2026-09-24.sql.gz \
  | mysql serwer400227_coachanalyze

# 4. Uploady — tylko razem z krokiem 3. Zrzut bazy wskazuje na pliki
#    z tamtego dnia; bez nich raporty odwołują się w próżnię.
#
#    Rozpakowujemy do katalogu tymczasowego i dopiero stamtąd kopiujemy.
#    Poziom, z którego spakowano tarball, nie jest z góry znany, a pomyłka
#    w `--strip-components` rozsypałaby pliki po katalogu domeny zamiast
#    zatrzymać polecenie. `przywroc_pro.sh` robi dokładnie to samo.
TMP=$(mktemp -d)
tar -xzf ~/CoachAnalyze/archiwum/pro-1.0/app_public_html_2026-09-24.tgz -C "$TMP"
ZRODLO=$(find "$TMP" -type d -path '*/storage/uploads' | head -1)
echo "$ZRODLO"          # sprawdź wzrokiem, ZANIM skopiujesz
cp -a "$ZRODLO"/. ~/public_html/app.coachanalyze.pl/storage/uploads/
rm -rf "$TMP"

# 5. Kontrola: jeden raport wygenerowany z palca musi odpowiadać
#    istniejącemu plikowi HTML. Procedura w docs/RUNBOOK.md.
```

**Kroki 3 i 4 idą razem albo wcale.** Baza bez plików daje raporty, których nie da
się przeliczyć; pliki bez bazy to katalog niepodpiętych plików. Jeśli wykonujesz
krok 3, zrób najpierw zrzut stanu bieżącego — `deploy.sh` z kroku 2 już go zrobił
i leży w `~/CoachAnalyze/shared/backups/`.

### 5.3 Co przeżywa powrót, a co nie

| Przeżywa | Nie przeżywa |
|---|---|
| Publiczne adresy `/r/{club_key}/{token}` — tokeny są w bazie i nie były ruszane | Raporty wygenerowane w okresie „viewer", jeśli wykonasz krok 3 |
| Pliki raportów HTML już zapisane na dysku | Ustawienie `HTML_TEMPLATE=v21`, jeśli je kiedyś włączysz — `pro` nie zna tej zmiennej i użyje v17 |
| Konta, hasła (argon2id), sesje w Redisie | — |

---

## 6. Szablon raportu: dwie generacje obok siebie

Od tej sesji `engine/coachanalyze/templates/` niesie dwa pliki:

| Generacja | Plik | Status |
|---|---|---|
| `v17` | `dashboard_template.html` | **DOMYŚLNA.** Wyjścia pilnuje test złoty co do bajtu |
| `v21` | `dashboard_template_v21.html` | Nowa. Włączana świadomie |

Wybór: `--html-template v17|v21|ŚCIEŻKA`, zmienna `CA_HTML_TEMPLATE` albo
`HTML_TEMPLATE` w `.env` (domyślnie `v17`). Szczegóły: `docs/KONTRAKT_CLI.md`.

**Domyślny został v17 świadomie.** Wzorzec złoty jest raportem v17; przestawienie
domyślnej generacji znaczyłoby, że bramka wdrożenia sprawdza inny plik, niż produkuje
produkcja — albo że trzeba ruszyć manifest. Jedno i drugie zamienia test złoty
z bramki w formalność.

**v21 nie ma wzorca złotego** i dopóki go nie ma, nie powinien być domyślny.
Ścieżka do wzorca jest opisana w `docs/PRZEBUDOWA_KLUB_SESJE.md` (S5b, „Plan
przebazowania wzorca złotego") i ma pięć kroków, z których **trzeci to porównanie
wizualne przez człowieka, a czwarty to akceptacja klienta**. Skrócenie tej kolejności
znaczy podmianę wzorca pod zmienioną logikę, czyli dokładnie to, czego zakazuje
CLAUDE.md §2.

### Co v21 niesie, czego v17 nie ma

- nagłówek transmisyjny z wynikiem, xG i paskiem zdarzeń (`id="hdr2"`),
- sekcję **Przegląd** (`id="sec-przeglad"`) — kluczowe liczby i wnioski,
- motyw jasny (`data-theme="light"`) z własnymi barwami drużyn,
- druk (PDF A4 i slajdy 16:9) oraz eksport slajdów PNG,
- słownik zmiennych `VARS` z aliasami tagów **w szablonie, nie w silniku** —
  render ich nie dubluje,
- atrybucję gola po najbliższym strzale **w szablonie** (`team_uuid` gola
  w eksporcie bywa błędny) — render jej też nie dubluje.

### Konwencja stron

**`HOME` = drużyna atakująca w LEWO**, `AWAY` w prawo. Tak podpisuje je nagłówek v21
i tak wypełnia je render (`us` → `HOME`, `them` → `AWAY`).

To jest konwencja PREZENTACJI, nie fakt z danych: eksport LiveTag nie niesie
informacji o gospodarzu (pułapka 2 — współrzędne są już znormalizowane kierunkowo),
a `matches.is_home` bywa `NULL` i to jest poprawna wartość.

---

## 7. Pytania otwarte

Rzeczy, które ta sesja zostawia świadomie, z nazwiskiem problemu zamiast ciszy.

### 7.1 ~~`__DATA__` znaczy dwie rzeczy~~ — ZAMKNIĘTE

`__DATA__` było podnapisem `/*__DATA__*/`, więc liczenie wystąpień wymagało korekty,
a podmiana — ustalonej kolejności. **Znacznik został przemianowany w szablonie v21 na
`__DATA_MECZU__`** (nagłówek `hdr2` i stopka slajdów), a obejścia zniknęły z `render.py`.
Podstawienie jest znowu zwykłe, `/*__DATA__*/` bez zmian.

Zostaje jako zapis decyzji: dwa znaczniki nie mogą być swoimi podnapisami. Następna
generacja szablonu ma to wiedzieć, zanim wymyśli `__PAL_KLUBU__`.

### 7.2 `__KOLEJKA__` nie ma skąd wziąć wartości

`matches` niesie `season_id`, `played_at`, `competition` i wynik — **kolejki nie ma**.
`__KOLEJKA__` wypełni się dopiero, gdy PHP zacznie podawać `config.match.round`,
a to wymaga kolumny, czyli migracji `014` (addytywnej: `ADD COLUMN round … NULL`).

**Wygląd jest już rozwiązany:** nagłówek v21 składa się z członów NIEPUSTYCH, więc
brak kolejki zabiera cały człon razem z separatorem. Nie ma „sezon  · kolejka  · ".

Zostaje samo pytanie o dane: czy kolejka ma być kolumną w `matches` (wtedy migracja
`014`), czy zostaje poza produktem. **Do decyzji Tomasa** — nic nie jest zepsute,
dopóki jej nie ma.

### 7.3 `config.match` nie jest jeszcze wypełniane przez PHP

`app/bin/run_job.php` wpisuje `'season_label' => null` na sztywno i nie tworzy bloku
`match`. Silnik to rozumie (puste miejsca), ale **dopóki PHP nie zacznie podawać
mety, nagłówek v21 będzie pusty także tam, gdzie dane w bazie są** (`played_at`,
`season_id`).

Ta sesja świadomie nie ruszała `run_job.php` — zakres zamknięty na silniku i jednym
przekazaniu parametru z `EngineRunner`. **Do zrobienia w sesji, która włącza v21.**

### 7.4 Sekcja „Przegląd" nie jest w rejestrze sekcji

v21 ma `id="sec-przeglad"`, a `coverage.ALL_SECTIONS` i `render.SECTION_DOM_ID`
o niej nie wiedzą. Skutek: **sekcji Przegląd nie da się wyłączyć z templatu klubu**
ani pominąć przy braku danych.

Dodanie jej do rejestru jest zmianą kontraktu (`sections_enabled`) i dotyka
`build_sections`, więc nie weszło tutaj. Dopóki Przegląd ma być zawsze widoczny,
nie boli — ale to jest założenie, nie projekt.

### 7.5 Nazwy klubów nie przechodzą przez ucieczkę

**Usterka zastana, nie wprowadzona w tej sesji.** `render.py` wstawia
`__TEAM_*_LABEL__` bez ucieczki, a nazwa klubu pochodzi z bazy, czyli od użytkownika.
Raport wisi pod publicznym adresem `/r/{club_key}/{token}`.

Nie naprawiono tutaj, bo **poprawna naprawa wymaga rozróżnienia kontekstów**,
a nie jednego `html.escape`:

| Znacznik | Kontekst w szablonie | Czego potrzebuje |
|---|---|---|
| `__TEAM_*_LABEL__` | treść HTML i literał szablonowy JS | ucieczki HTML |
| `__TEAM_*__` | **literał JS porównywany ze zdarzeniami** (`e.team===HUT`) | ucieczki JS, **nigdy HTML** — `&amp;` rozjechałoby dopasowanie drużyn i raport pokazałby zera |

Nowe znaczniki meta meczu (`__SEZON__`, `__KOLEJKA__`, `__DATA__`) ucieczkę
**mają** — są dodane w tej sesji, więc nie ma tu czego regresować.

**Do zrobienia osobno**, z testem na nazwę klubu zawierającą `<`, `&` i apostrof.

### 7.6 `venv` na Macu ma Pythona starszego niż 3.11

`test_pakowanie.py` pomija się lokalnie (`tomllib` jest od 3.11), czyli siedem
testów naraz. Bramka wersji stoi osobno (`test_wersja.py`) właśnie z tego powodu
i ona działa — ale reszta modułu pakowania sprawdza się wyłącznie w CI i na serwerze.

To nie jest nowe i nie blokuje niczego w tej sesji. Odnotowane, bo „1 skipped"
w podsumowaniu już raz przepuściło rozjazd wersji aż do wdrożenia.
