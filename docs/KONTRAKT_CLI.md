# Kontrakt CLI: PHP ↔ silnik Python

Jedyny punkt styku między warstwą webową a silnikiem. Zmiana czegokolwiek w tym dokumencie
wymaga zmiany po obu stronach w tym samym commicie.

**Zasada:** PHP przekazuje ścieżki i konfigurację, odbiera pliki i JSON. Nie interpretuje HTML-a
i nie liczy niczego samodzielnie.

---

## Wywołanie

```bash
$PYTHON_BIN -m coachanalyze build \
  --csv        /storage/uploads/2026/08/ab12cd.csv \
  --json       /storage/uploads/2026/08/ab12cd.json \
  --config     /tmp/job_881_config.json \
  --out-html   /storage/reports/881.html \
  --out-meta   /tmp/job_881_meta.json \
  --out-canon  /tmp/job_881_canon.json \
  --out-metrics /tmp/job_881_metrics.json
```

`--json` jest opcjonalny. Bez niego oś czasu traci paletę LiveTag i używa barw klubu jako zapasowych —
fakt odnotowany w `meta.warnings`.

`--out-canon` opcjonalny; używany, gdy zdarzenia kanoniczne mają trafić do bazy.

`--out-metrics` opcjonalny; pakiet metryk policzony przez silnik regułowy. Wejście warstwy AI (D5)
i modułu porównań sezonowych. **PHP go nie interpretuje** — przekazuje dalej albo pomija.

### Kolejność zapisu artefaktów

`--out-canon` i `--out-metrics` powstają **przed** renderem HTML: awaria szablonu nie może kasować
wyniku parsowania. `meta.json` powstaje **jako ostatni** i tylko przy powodzeniu — zapisany
wcześniej zostawiałby na dysku `ok: true` bez raportu.

### Wyjście `--out-metrics`

```json
{
  "engine_version": "0.8.1",
  "profile_version": 4,
  "totals": { "events": 294, "mapped": 287, "unmapped": 7,
              "unmapped_tags": ["AKCJA DEFENSYWNA"],
              "half_split_ms": 2733600, "duration_ms": 6458000 },
  "sides":  { "us": { "...": "komplet metryk" }, "them": { }, "none": { } },
  "halves": { "1": { "us": { }, "them": { }, "none": { } }, "2": { } }
}
```

Blok metryk jest ten sam dla każdej strony i każdej połowy: `shots`, `entry_sbz`, `entry_third`,
`duels`, `losses`, `recoveries`, `press`. Trzy własności, na których stoi pakiet:

- **Podział `us` / `them` / `none` jest rozłączny i zupełny.** `none` to zdarzenia bez przypisanej
  drużyny (pułapka 5) — w eksportach referencyjnych 196 z 294, czyli większość pojedynków,
  strat i odbiorów. To nie jest odpad.
- **Wskaźnik procentowy przy zerowym mianowniku to `null`, nigdy `0`.** „0% wygranych pojedynków"
  i „nie było pojedynków" to dwa różne zdania i raport nie ma prawa ich mylić.
- **Metryki liczą się z pojęć kanonicznych**, nigdy z `source_tag`. Zdarzenie z tagiem bez
  mapowania jest policzone w `totals.unmapped`, ale nie wchodzi do żadnej metryki.

Strzał bez etykiety wyniku trafia do `shots.outcome_unknown`, a nie do `off_target` — silnik nie
domyśla się wyniku. Szablon raportu robi w tym miejscu inaczej (patrz niżej).

### Render HTML

Silnik wstrzykuje dane w szablon `engine/coachanalyze/templates/dashboard_template.html`.
**Szablon nie zna żadnego klubu** — wszystko, co identyfikuje drużyny, przychodzi z `config.teams`.

| Znacznik | Wypełniany |
|---|---|
| `/*__DATA__*/` | Zdarzenia i `half_split`, serializacja kompaktowa. Dokładnie jedno wystąpienie |
| `/*__PAL__*/` | Paleta z pliku projektu LiveTag. Dokładnie jedno wystąpienie |
| `__TEAM_HOME__` · `__TEAM_AWAY__` | Klucz dopasowania — ta sama wartość co w `DATA[].team` |
| `__TEAM_*_LABEL__` | Nazwa wyświetlana (`name`) |
| `__TEAM_*_SHORT__` | Etykieta toru osi czasu (`short`) |
| `__TEAM_*_COLOR__` · `__TEAM_*_DIM__` | Barwa klubu i jej przygaszona wersja |
| `__LOGO_HOME__` · `__LOGO_AWAY__` | Pełny adres `data:` herbu — typ MIME idzie za rozszerzeniem pliku |

Obecność znaczników jest sprawdzana **przed podmianą**, a po niej sprawdzamy, że żaden nie został;
brak dowolnego przerywa render z kodem `4`. `/*__DATA__*/` i `/*__PAL__*/` muszą wystąpić dokładnie
raz — drugie wystąpienie znaczy uszkodzony szablon. Znaczniki drużyn co najmniej raz, bo z natury
powtarzają się w wielu miejscach.

Do przeglądarki trafiają wyłącznie `events` i `half_split`. Nagłówki eksportu, `format_fingerprint`
i nazwa kolumny zawodnika zostają po stronie serwera — raport jest dostępny pod publicznym
adresem `/r/{club_key}/{token}`.

**Pole `team` w zdarzeniu dostaje nazwę z konfiguracji**, wybraną po `team_side` z modelu
kanonicznego, a nie surowy napis z eksportu. Szablon porównuje `e.team` z nazwą klubu przez
równość: klub, który w kolejnym eksporcie zapisze nazwę inaczej, dostałby raport z zerem zdarzeń
dla własnej drużyny i bez ostrzeżenia. Po tej podmianie dopasowaniem zajmuje się model kanoniczny.
Przy tej okazji surowy napis z eksportu przestaje wyciekać do raportu pod publicznym adresem.

Nazwa, której model nie rozpoznał (`team_side: none` przy niepustym `team`), **zostaje bez zmian** —
skasowanie jej przeniosłoby zdarzenie do sekcji „bez przypisania drużyny" i zmieniło liczby.
Mówi o tym ostrzeżenie `UNKNOWN_TEAM`.

**Szablon liczy metryki samodzielnie, w JS, po nazwach tagów klienta.** Pakiet z `--out-metrics`
nie jest do niego wstrzykiwany. Silnik porównuje jedno z drugim i przy rozjeździe wypisuje
ostrzeżenie **na stderr** (nie do `meta.json`): profil klubu mapujący własną nazwę tagu na
pojęcie kanoniczne rozjeżdża raport z archiwum, a to musi być widoczne.

### Wyjście `--out-canon`

Plik gotowy do wstawienia w `events_canonical` (app/migrations/002 + 003):

```json
{
  "match_id": 881,
  "engine_version": "0.8.1",
  "count": 294,
  "events": [
    {
      "match_id": 881, "t_ms": 0, "half": 1, "team_side": "them",
      "concept": "entry_sbz", "qualifiers_json": "[\"positional\",\"without_shot\"]",
      "x": 97.01, "y": 59.34, "x_end": 95.68, "y_end": 48.31,
      "xg": null, "xg_source": null, "player_id": null, "source_tag": "ZDOBYCIE SBZ"
    }
  ]
}
```

Trzy własności, na których stoi archiwum:

- **`count` zawsze równa się liczbie wierszy CSV.** Silnik sprawdza to przed zapisem
  i przerywa, gdy się nie zgadza. Cicha utrata zdarzenia ujawniłaby się dopiero
  przy porównaniu sezonowym, miesiące później.
- **`concept` bywa `null`** — tag bez mapowania na pojęcie kanoniczne. Zdarzenie jest
  faktem o meczu i trafia do archiwum; migracja 003 zdejmuje z kolumny `NOT NULL`.
- **`qualifiers_json` to gotowy napis JSON**, nie tablica — kolumna jest typu `JSON`,
  więc PHP wstawia wartość bez ponownego kodowania.

`t_ms` bywa `null`, gdy `begin` nie dał się odczytać. Zera nie podstawiamy: zero to
konkretna 0. minuta i nie da się jej odróżnić od braku danych. Takich wierszy nie przyjmie
kolumna `NOT NULL` — PHP musi je odrzucić i zgłosić, a nie „naprawić".

Pozostałe komendy:

```bash
$PYTHON_BIN -m coachanalyze inspect --csv PLIK      # sam raport pokrycia, bez renderu
$PYTHON_BIN -m coachanalyze inspect --csv PLIK --json PROJEKT --out-meta /tmp/meta.json
$PYTHON_BIN -m coachanalyze --version               # wersja silnika
```

`inspect` służy ekranowi raportu pokrycia — operator widzi ostrzeżenia **przed** wygenerowaniem raportu.

Wypisuje `meta.json` na stdout; `--out-meta` dodatkowo zapisuje go do pliku. `--json` jest
opcjonalny i włącza wykrywanie literówek w palecie tablicy kodowej. **`inspect` nie przyjmuje
`--config`** — na tym etapie kluby nie są jeszcze dopasowane, więc `coverage.teams` zwraca nazwy
wykryte w danych, a wszystkie zdarzenia mają `team_side: "none"`.

---

## Wejście — `config.json`

```json
{
  "match_id": 881,
  "season_label": "2026/2027",
  "teams": {
    "us":   { "name": "Klub A", "short": "KLA", "color": "#E8722C", "crest": "/storage/crests/3.png",
              "source_names": ["KLUB A", "KLUB A II"] },
    "them": { "name": "Klub B", "short": "KLB", "color": "#2C6FE8", "crest": "/storage/crests/9.png" }
  },
  "mapping_profile": { "version": 4, "rules": [] },
  "sections": ["bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam"],
  "options": { "contrast_fix": true, "engine_locale": "pl_PL" }
}
```

Silnik nie odgaduje nazw ani barw klubów — dostaje je w konfiguracji. Wykryte w danych nazwy
zwraca w `meta.coverage.teams`, żeby PHP mógł zaproponować dopasowanie przy pierwszym imporcie.

### Pola drużyny

| Pole | Do czego służy | Gdy go brak |
|---|---|---|
| `name` | Nazwa wyświetlana w raporcie. Dopasowuje też zdarzenia, gdy zgadza się z zapisem w eksporcie | Nazwa wykryta w danych, a gdy i tej nie ma — `Drużyna A` / `Drużyna B` + ostrzeżenie na stderr |
| `short` | Etykieta toru na osi czasu, gdzie miejsca jest mało | `name` wielkimi literami |
| `color` | Barwa drużyny w wykresach i na mapach | `#E6A23C` (gospodarz), `#5CA8E0` (rywal) |
| `crest` | Ścieżka do herbu. Formaty: svg, png, jpg, webp, gif | Herb zastępczy: biały krążek z pierwszą literą nazwy + ostrzeżenie na stderr |
| `source_names` | Nazwy tak, jak zapisał je LiveTag — dokładają się do dopasowania obok `name` i `short` | Dopasowanie tylko po `name` i `short` |

**`source_names` istnieje po to, żeby nazwa w raporcie nie zależała od zapisu w eksporcie.**
Klub bywa otagowany skrótem, pod starą nazwą albo z literówką; bez tego pola każda taka zmiana
wymuszałaby zmianę nazwy klubu w aplikacji. Nazwa spoza tej listy i spoza `name`/`short` nie
przepada po cichu — wraca jako ostrzeżenie `UNKNOWN_TEAM`.

---

## Wyjście — `meta.json`

```json
{
  "ok": true,
  "engine_version": "0.8.1",
  "format_fingerprint": "sha256:9f3c…",
  "half_split_ms": 2730000,
  "duration_ms": 5820000,
  "coverage": {
    "events": 294,
    "shots": 21,
    "duels": 62,
    "sbz": 38,
    "sbz_with_vector": 31,
    "third": 35,
    "third_pos": 0,
    "teams": ["NASZA", "RYWAL"],
    "no_team": 196,
    "xg_parsed": 17,
    "xg_missing": 4,
    "xg_sum": 4.4,
    "negative_begin": 3,
    "has_json": true,
    "players_filled": 0
  },
  "sections_available": ["bilans", "mapy", "tl_sbz", "tl_bilans", "duels", "noteam"],
  "sections_unavailable": [
    { "id": "tl_iii", "reason": "Eksport nie zawiera pozycji III STREFY (kolumny pos_* puste)" }
  ],
  "warnings": [
    { "code": "TYPO_MASZA", "msg": "Wykryto 'MASZA POŁOWA' — zmapowano na NASZA", "count": 12 },
    { "code": "NEGATIVE_BEGIN", "msg": "Ujemny czas startu taga — przycięto do 0", "count": 3 }
  ],
  "unmapped_tags": ["PRESS WYSOKI 2"],
  "unmapped_labels": []
}
```

### Klucze `coverage`

| Klucz | Znaczenie |
|---|---|
| `events` | Liczba zdarzeń kanonicznych — zawsze równa liczbie wierszy eksportu |
| `shots` · `duels` · `sbz` · `third` | Liczności pojęć: `shot`, `duel`, `entry_sbz`, `entry_third` |
| `sbz_with_vector` | Zdobycia SBZ z punktem docelowym (`x_end`) — bez nich nie ma strzałek na mapie |
| `third_pos` | Zdarzenia III strefy ze współrzędnymi. `0` przy niepustym `third` wyłącza `tl_iii` |
| `teams` | Nazwy drużyn **wykryte w danych**, do zaproponowania dopasowania przy pierwszym imporcie |
| `no_team` | Zdarzenia bez przypisanej drużyny — zasilają sekcję `noteam` |
| `xg_parsed` · `xg_missing` | Strzały z policzonym xG i bez. Suma równa `shots` |
| `xg_sum` | Suma xG wszystkich zdarzeń, zaokrąglona do dwóch miejsc |
| `negative_begin` | Liczba tagów z ujemnym czasem startu (przyciętych do 0) |
| `players_filled` | Niepuste wartości kolumny zawodnika. `0` = brak warstwy indywidualnej |

`unmapped_labels` działa jak `unmapped_tags`, ale dla etykiet: nazwa bez reguły w profilu
nie znika po cichu, tylko trafia do raportu pokrycia.

### Ostrzeżenia — kody

| Kod | Kiedy |
|---|---|
| `TYPO_MASZA` | Wykryto `MASZA POŁOWA` w zdarzeniach lub w palecie tablicy kodowej |
| `NEGATIVE_BEGIN` | Ujemny czas startu taga — przycięty do 0 |
| `NO_JSON` | Wywołanie bez `--json`; oś czasu użyje barw klubu zamiast palety LiveTag |
| `NO_PLAYER_COLUMN` | Eksport nie ma kolumny zawodnika |
| `EMPTY_PLAYER_COLUMN` | Kolumna zawodnika istnieje, ale jest pusta |
| `UNKNOWN_TEAM` | Nazwa drużyny w danych nie pasuje do żadnej z konfiguracji |
| `UNMAPPED_TAGS` | Tagi bez mapowania na pojęcie kanoniczne — zdarzenia zachowane, poza metrykami |
| `XG_POZA_STRZALEM` | Liczba w komentarzu przy tagu, który nie jest strzałem — pominięta przy xG |

Każde ostrzeżenie ma zawsze trzy pola: `code`, `msg` (po polsku), `count`.
Część niesie dodatkowe pola diagnostyczne — `XG_POZA_STRZALEM` dokłada `tags`
z listą tagów, których to dotyczyło. PHP ma czytać po nazwach, nie po zestawie kluczy.

**`sections_unavailable` zawsze niesie powód po polsku.** Trafia bezpośrednio do interfejsu —
analityk ma zobaczyć, czego brakuje i dlaczego, a nie pustą sekcję.

**`format_fingerprint`** to skrót zestawu kolumn eksportu. Gdy LiveTag zmieni format, PHP porównuje
odcisk z poprzednimi importami i sygnalizuje rozjazd, zamiast po cichu zgubić część zdarzeń.

---

## Kody wyjścia

| Kod | Znaczenie | Reakcja PHP |
|---|---|---|
| `0` | Sukces | Zapis raportu, status `done` |
| `2` | Plik nie jest eksportem LiveTag | „Ten plik nie wygląda na eksport z LiveTag.Pro" |
| `3` | Brak wymaganych kolumn | Komunikat z listą brakujących kolumn z `meta.missing_columns` |
| `4` | Błąd wewnętrzny silnika | „Nie udało się wygenerować raportu", status `failed`, traceback do logu |
| `5` | Przekroczony limit czasu | Status `failed`, informacja o możliwości ponowienia |

Przy kodach `2` i `3` silnik **mimo wszystko zapisuje `meta.json`** z `ok: false` i opisem problemu.
Przy `4` i `5` `meta.json` może nie powstać — PHP musi to przewidzieć.

`meta.json` błędu ma inny kształt niż `meta.json` sukcesu — PHP rozstrzyga po polu `ok`:

```json
{ "ok": false, "code": "MISSING_COLUMNS", "msg": "Brak wymaganych kolumn: end",
  "engine_version": "0.8.1", "missing_columns": ["end"] }
```

`missing_columns` występuje wyłącznie przy kodzie `3`.

**Traceback zawsze na stderr, nigdy na stdout.** Stdout jest zarezerwowany na strukturę JSON.
