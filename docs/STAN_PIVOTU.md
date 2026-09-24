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

**Od sesji 1b dostępność sekcji i xG przestały zależeć od pojęć — GDY JEST TEMPLAT.**
To była realna blokada odbioru: templat ze zmiennymi `canon: null` dawał raport bez
map i bez osi SBZ, a `xg_sum` wynosiło 0 przy ostrzeżeniu `XG_POZA_STRZALEM` na
własnych strzałach klubu. Szablon liczył poprawnie, a silnik wycinał policzony DOM —
uśpiona warstwa egzekwowała regułę wycofaną w §2.3. Odtąd `coverage.build_sections`
liczy dostępność `mapy`, `tl_sbz` i `tl_iii` z SUROWYCH TAGÓW zmiennych templatu,
a `canon.py` czyta xG zmiennej bez pojęcia po kształcie liczby (ułamek w (0,1]) —
tą samą regułą, co szablon v21. **Bez templatu nic się nie zmienia.**

Obejmuje to także **`duels`**, dopisane osobnym commitem: sekcja pojedynków
liczyła się dalej z pojęcia `duel` i przy templacie bez pojęć znikała dokładnie
tak, jak znikały mapy. Ta sama usterka, ten sam powód, ta sama naprawa.

**Kryterium odbioru w jednym zdaniu:** templat bez ANI JEDNEGO pojęcia
kanonicznego daje na eksporcie referencyjnym **komplet siedmiu sekcji**.
Pilnuje tego `test_1b_sekcje_dostepne_mimo_braku_pojec`.

`noteam` zostaje wspólne dla obu ścieżek i to jest poprawne: liczy się z surowego
pola `team`, a nie z pojęcia, więc templat nie ma tam czego zmieniać.

**Od sesji 2 warstwa kanoniczna ma następcę dla warstwy widocznej.** `--out-events`
i tabela `events` (migracja 014) zapisują zdarzenia po **surowych nazwach tagów** —
tą samą miarą, którą liczy raport. `events_canonical` zostaje nietknięta i dalej
obsługuje archiwum; nowa tabela nie jest jej zamiennikiem, tylko warstwą, która
odpowiada na pytania raportu bez pośrednictwa pojęć.

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

### 2.3 Reguła „brak bindingu = tylko licznik" — WYCOFANA w sesji 1 (2026-09-24)

Zasada z Sesji 4 przebudowy (pkt 2) brzmiała: zmienna bez bindingu kanonicznego
dostaje wyłącznie widoki generyczne — licznik w bilansie i pas na osi czasu;
sekcje wymagające semantyki (mapy, xG) są dla niej zablokowane.

**Nie jest już uśpiona — została wycofana.** Pojęcie kanoniczne jest od sesji 1
**opcjonalne i nie ogranicza sekcji**: zmienna bez niego wchodzi do każdej sekcji
włączonej w templacie, a liczy się po **surowej nazwie z eksportu** — dokładnie
tak, jak liczy ją szablon raportu w JS (`e.tag==='STRZAŁ'`). Skoro raport nigdy
nie potrzebował pojęcia do narysowania czegokolwiek, blokada broniła dostępu do
sekcji, których nic już nie broniło; jej jedynym skutkiem było zmuszanie operatora
do wypełnienia pola, zanim zobaczył pierwszy raport — czyli dokładnie to, co pivot
usuwa. Pole zeszło pod „Zaawansowane" i jest zwinięte.

Co zniknęło: błąd `conf.err.canon_required`, blokada pól sekcji w konfiguratorze
i na ekranie diffu, odcinanie sekcji w `TemplateDiff::nowyConfig()`.
Co zostało: `Configurator::SEKCJE_GENERYCZNE` jako **domyślne sekcje nowej
zmiennej** (propozycja startowa, nie limit) oraz walidacja pojęcia spoza słownika
(`conf.err.unknown_canon`) i sekcji spoza templatu (`conf.err.section_disabled`).

**Świadoma cena.** Operator może wpuścić do mapy zmienną, której eksport nie niesie
pozycji — wtedy mapa jest pusta, a powód stoi w raporcie pokrycia
(`sections_unavailable`). To ten sam przypadek, co III STREFA bez `pos_*`
(pułapka 3) i ma tę samą odpowiedź: brak danych ma być widoczny, a nie uprzedzony
zakazem. Kafelek mapy bez współrzędnych dostanie własny komunikat — punkt 7.8.

Generyczny renderer, który miał obsłużyć takie zmienne po stronie szablonu
(S5b, pkt 5), **nie powstał i nie jest potrzebny**: szablon rysuje po nazwie tagu.

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

#### Jak zbudowane jest `app_public_html_*.tgz`

Pakowane **z katalogu NAD katalogiem domeny**, czyli razem z jego nazwą:

```bash
tar -czf app_public_html_$(date +%F).tgz -C ~/public_html app.coachanalyze.pl
```

Ścieżki w środku mają więc prefiks katalogu domeny:

```
app.coachanalyze.pl/app/src/bootstrap.php
app.coachanalyze.pl/storage/uploads/2026/09/ab12cd.csv
```

To ma znaczenie przy rozpakowywaniu: **sam `tar -xzf … -C <cel>` odtworzy katalog
`app.coachanalyze.pl/` wewnątrz celu**, a nie zawartość katalogu domeny. Żeby dostać
samo `storage/uploads/…`, trzeba zdjąć prefiks (`--strip-components=1`).

`przywroc_pro.sh` **nie ma tej liczby wpisanej na sztywno** — czyta pierwszą ścieżkę
pasującą do `*/storage/uploads/`, liczy składniki prefiksu i tyle zdejmuje. Zmiana
nazwy katalogu domeny albo spakowanie z `.` zamiast z nazwy katalogu nie wymaga
poprawki w skrypcie. Po rozpakowaniu skrypt **sprawdza układ**, nie samą liczbę
plików: w katalogu docelowym ma powstać `storage/uploads/…`, inaczej przerywa.

> Liczba składników prefiksu jest liczona **z wiodącym `./`, jeśli archiwum je ma** —
> `tar` traktuje `./` jak pełnoprawny składnik ścieżki. Odcięcie go „bo to nie katalog"
> powodowało, że `--strip-components` zabierało o jeden za mało i pliki lądowały
> w `<cel>/app.coachanalyze.pl/storage/uploads/…`. Kontrola liczby plików tego
> nie widziała — pliki były, tylko nie tam, gdzie ścieżki z bazy ich szukają.

**Mapowanie ścieżek z bazy na kopię jest mechaniczne:** wiersz `imports.csv_path`
postaci `<katalog domeny>/storage/uploads/X` odpowiada plikowi `<cel>/storage/uploads/X`.

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

#### Odbiór sesji 0 — WYKONANY 2026-09-24

Procedura nie jest już tylko opisana. **Została przeprowadzona na serwerze** i to
jest jedyny powód, dla którego wolno ją nazywać planem awaryjnym.

| Krok | Wynik |
|---|---|
| `przywroc_pro.sh` na `serwer400227_caproba` | 20 tabel usuniętych, zrzut wczytany, uploady w `~/tmp/pro-1.0-uploads` |
| **Test a** — raport `pro` z linii poleceń | mecz 1, Pogoń vs Hutnik; blok `DATA` z builda **0.12.0 na v17** ma **identyczny md5** z dwoma raportami produkcyjnymi |
| **Test b** — ten sam mecz na v21 | renderuje się; `przeglad_liczby.py`: xG **1,49 : 2,91**, strzały **12 : 17**, SBZ **15 : 19**, III strefa **20 : 15**; **brak** ostrzeżenia `BRAKUJACY_ZNACZNIK` |
| Stan końcowy serwera | wrócił na `main` |

**Test a jest tym, na czym stoi cała sesja.** Silnik 0.12.0 z przełącznikiem szablonu,
uruchomiony na v17, produkuje bajt w bajt te same dane, co raporty, które klient ma
dzisiaj na ekranie — i to na prawdziwym eksporcie, nie na wzorcu z repozytorium.
Test złoty mówi to samo, ale o pliku; tutaj zgadza się z produkcją.

> Liczby z testu b są podane w kolejności **HOME : AWAY** tak, jak wypisał je
> `przeglad_liczby.py`. W tym buildzie `us` = Pogoń, a render mapuje `us` → `HOME`,
> więc **Pogoń jest już lewą kolumną** — i po wdrożeniu punktu 7.7 kolejność kolumn
> dla tego meczu **się nie zmieni**. Ta para liczb zostaje, jak stoi.
>
> Zamiana stron po 7.7 dotyczy **wyłącznie raportów budowanych z configiem,
> w którym `us` był rywalem** — jak ręczny config JDRZ z rana. Dziś to `us`
> decyduje o lewym slocie, więc źle obsadzone `us` znaczy odwrócony raport;
> po 7.7 decyduje klub-tenant i ta pomyłka przestaje być możliwa.

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
#    Archiwum niesie prefiks katalogu domeny (patrz punkt 3.2), więc zdejmujemy
#    go `--strip-components=1`. NAJPIERW SPRAWDŹ PREFIKS WZROKIEM — jeśli ścieżka
#    zaczyna się od `./`, składników jest o jeden więcej.
ARCH=~/CoachAnalyze/archiwum/pro-1.0/app_public_html_2026-09-24.tgz
tar -tzf "$ARCH" | grep storage/uploads | head -1     # oczekiwane: app.coachanalyze.pl/storage/uploads/

tar -xzf "$ARCH" -C ~/public_html/app.coachanalyze.pl \
  --strip-components=1 'app.coachanalyze.pl/storage/uploads'

ls ~/public_html/app.coachanalyze.pl/storage/uploads/ | head   # kontrola układu

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

### Konwencja stron — OD SESJI 4a (2026-09-24)

**`HOME` = klub-tenant, `AWAY` = rywal.** Zawsze, niezależnie od kierunku ataku
i od tego, gdzie rozegrano mecz. Decyzja właściciela z punktu 7.7 a, wykonana
w sesji 4a (silnik 0.14.0).

Poprzednia konwencja — „`HOME` = drużyna atakująca w LEWO" — **już nie obowiązuje**
i nagłówek v21 nie ma jej już wpisanej na sztywno: kierunek podpisuje
`meta.direction`, a gdy go nie ma, podpis jest pusty.

Nazwy slotów zostają i **nie znaczą „gospodarz/gość"**: eksport LiveTag nie niesie
informacji o gospodarzu (pułapka 2 — współrzędne są już znormalizowane kierunkowo),
a `matches.is_home` bywa `NULL` i to jest poprawna wartość. Zmiana samych nazw
dotknęłaby obu szablonów i wzorca złotego, więc kosztowałaby więcej, niż daje.

Rozstrzyga `match.tenant_club_id` porównane z `teams.*.club_id`. Bez tych pól
obowiązuje `us` → `HOME` — czyli dokładnie to, co dotąd. Rozjazd zdarza się
wyłącznie przy scoutingu (`matches.club_id` ≠ `matches.club_home_id`).

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

> **Ten punkt urósł.** Decyzja 7.7 wymaga, żeby `config.match` niósł także **id
> klubu-tenanta** — bez niego render nie wie, kto zajmuje lewy slot. Z kosmetyki
> nagłówka robi się więc warunek wstępny obsadzenia stron. Jedna zmiana
> w `run_job.php`, dwie rzeczy zależne.

### 7.4 ~~Sekcja „Przegląd" nie jest w rejestrze sekcji~~ — ZAMKNIĘTE w sesji 4b

Przegląd jest w `coverage.ALL_SECTIONS` i w `render.SECTION_DOM_ID`, więc da się
go wyłączyć z templatu i pominąć przy braku danych. Razem z nim weszły kafle
z magazynu v2 (`makro`, `donuty`, `okazje`, `zawodnicy`, `siatka`).

Przy okazji rejestr rozpadł się na **dwie listy**, bo to dwa różne pytania:
`ALL_SECTIONS` mówi, co silnik ZNA, a `DOMYSLNE_SEKCJE` — co widać BEZ TEMPLATU.
Kafle z magazynu są dostępne, ale nie domyślne; dokłada je kreator sekcji.

Zostaje jako zapis decyzji: **templat, który sekcji nie znał, jej nie wyłącza**.
Sekcja dołożona po zapisie templatu wraca do stanu domyślnego, bo brak na liście
zapisanej rok wcześniej nie jest niczyją decyzją.

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

### 7.7 Strony raportu i kierunek ataku — DECYZJA WŁAŚCICIELA, 2026-09-24

> To nie jest pytanie otwarte, tylko **rozstrzygnięcie zapisane wśród nich**, bo
> zmienia konwencję opisaną w punkcie 6 i ma zależność, której dziś nie ma.
>
> **WYKONANE W SESJI 4a (silnik 0.14.0).** Punkty (a), (b), (c) i (d) są
> zaimplementowane; moduł `engine/coachanalyze/direction.py`, testy
> `engine/tests/test_direction.py`, kontrakt w `docs/KONTRAKT_CLI.md`.
> Odstępstwo od zapisu poniżej jest jedno i dotyczy PODPISU w nagłówku —
> opisane w (c).

#### a) Lewa strona należy do klubu-tenanta, zawsze

**Klub-tenant (`clubs.is_own_team = 1`) jest ZAWSZE po lewej stronie raportu,
rywal po prawej — niezależnie od tego, gdzie rozegrano mecz.**

Zastępuje to konwencję v21 „`HOME` = drużyna atakująca w lewo" (punkt 6,
„Konwencja stron"). Po zmianie: **lewy slot = tenant, prawy = rywal**, a to,
w którą stronę ktokolwiek atakował, przestaje o stronach decydować.

Powód jest po stronie odbiorcy, nie danych: sztab ogląda serię raportów przez
sezon i ma widzieć swoją drużynę zawsze w tym samym miejscu. Strona zależna od
meczu każe przy każdym raporcie najpierw sprawdzić, gdzie się jest.

#### b) Kierunku ataku NIE konfiguruje się — wyprowadza go silnik z danych

Żadnego pola w konfiguracji, żadnego pytania do operatora. Reguła:

| Krok | Miara | Próg |
|---|---|---|
| podstawowa | mediana `pos_x_meters` strzałów drużyny | `> 52,5` → atak w prawo |
| kontrolna | mediana `pos_target_x_meters` wejść w SBZ | ta sama strona |
| kontrolna | znak `delta x` podań w III strefę | ten sam zwrot |

**Zweryfikowane na eksporcie JDRZ** (2026-09-24): Pogoń **92,5 / 90,6**,
JDRZ **27,7 / 12,5** — w obu połowach. Rozdzielenie jest jednoznaczne, nie graniczne.

Dwie obserwacje z tego samego sprawdzenia, obie istotne:

- **Drużyny nie zmieniają stron po przerwie** — w danych druga połowa wygląda
  jak pierwsza. Współrzędne w eksporcie są już znormalizowane kierunkowo
  (pułapka 2 z CLAUDE.md), więc **nie wolno ich lustrzyć „bo połowa druga"**.
- Skoro tak, kierunek liczy się **raz na mecz i na drużynę**, a nie per połowa.

Konfigurowanie kierunku byłoby pytaniem o coś, co w danych już stoi — czyli
kolejnym polem do pomylenia. Wyprowadzenie ma też tę własność, że przy eksporcie
bez pozycji po prostu nie da odpowiedzi, zamiast dać błędną.

#### c) Gdy tenant atakuje w lewo, render odbija współrzędne

**Na mapach tenant atakuje zawsze w prawo.** Gdy dane mówią inaczej, render
odbija współrzędne **obu drużyn**:

```
x' = 105 - x        (także pos_target_x_meters)
```

i zapisuje w `meta` flagę **`mirrored = true`**. Flaga nie jest ozdobnikiem:
bez niej pytanie „czy ta mapa jest odbita" nie ma odpowiedzi inaczej niż przez
ponowne policzenie median.

Odbijamy **obie drużyny razem** — lustrzenie jednej rozjechałoby mecz na dwa
układy współrzędnych. `y` zostaje nietknięte: zamieniamy strony boiska, nie skrzydła.

**Podpis w nagłówku idzie za MAPĄ, nie za surowym eksportem.** `meta.direction`
zapisuje kierunek sprzed odbicia (bo po nim da się odbicie sprawdzić i cofnąć),
ale znaczniki `__KIERUNEK_*__` niosą kierunek **po** odbiciu — czytelnik patrzy
na mapę, a nie na plik. Praktyczny skutek: przy znanym kierunku podpis mówi
zawsze „tenant atakuje w prawo", a różnicę widać wtedy, gdy kierunku NIE DA SIĘ
ustalić — wtedy podpis milczy, zamiast twierdzić cokolwiek.

**Tabela `events` też dostaje współrzędne po odbiciu**, a archiwum kanoniczne
i pakiet metryk — oryginalne. Porównanie sezonowe nie może sumować map z dwóch
przeciwnych stron boiska, a archiwum ma zapisywać to, co było w pliku.

To jedyne miejsce, w którym wolno tknąć współrzędne. Pułapka 2 zakazuje lustrzenia
„z góry"; tutaj odbicie jest **wyprowadzone z danych i odnotowane w `meta`**, więc
da się je cofnąć i sprawdzić.

#### d) Zależność: `config.match` musi nieść id klubu-tenanta

Render nie chodzi do bazy (CLAUDE.md §4), więc **nie wie, który klub jest tenantem**
— a bez tego nie ma jak obsadzić lewego slotu.

`config.match` z punktu 7.3 musi więc nieść także **id klubu-tenanta**. To ta sama
zmiana w `run_job.php`, co meta meczu, i ma iść razem z nią. Dopóki jej nie ma,
punktu (a) nie da się zaimplementować — punkt 7.3 przestaje być kosmetyką nagłówka
i staje się warunkiem wstępnym.

**ZAMKNIĘTE w sesji 4a.** `run_job.php` przekazywał `tenant_club_id` już od sesji 3,
brakowało drugiej połowy porównania: `Clubs::engineConfig()` dokłada teraz
`club_id` do każdej drużyny. Bez obu pól render zostaje przy `us` → `HOME`.

### 7.8 Mapa bez pozycji pokazuje puste boisko zamiast powodu

Eksport Hutnika ma **35 tagów `III STREFA` bez `pos_*`**. Bilans je liczy
(**20 : 15**), bo do policzenia zdarzenia pozycja nie jest potrzebna — ale mapa
wychodzi pusta.

**To nie jest błąd silnika.** `meta.sections_unavailable` niesie `tl_iii` z powodem,
pipeline zachowuje się dokładnie tak, jak ma (pułapka 3: III STREFA bywa bez
współrzędnych; sekcja warunkowa, brak danych = wyszarzenie z wyjaśnieniem).
Brakuje wyłącznie tego wyjaśnienia **w kafelku mapy**.

**ZROBIONE w sesji 4b.** Kafelek mapy bez współrzędnych pokazuje komunikat
„Brak pozycji w eksporcie" wraz z licznikiem zdarzeń fragmentu, zamiast pustego
boiska. Puste boisko jest gorsze niż brak kafelka — wygląda jak zero zdarzeń,
a zdarzeń było trzydzieści pięć.

Dotyczy trzech map sekcji Mapy (strzały wg wyniku, strzały wg typu akcji,
wejścia w SBZ). Sekcja `tl_iii` nadal ZNIKA w całości z powodem w raporcie
pokrycia — i tak jest poprawnie: tam nie ma pustego kafelka, tylko brak sekcji.
