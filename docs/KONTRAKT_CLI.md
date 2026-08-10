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
  --out-canon  /tmp/job_881_canon.json
```

`--json` jest opcjonalny. Bez niego oś czasu traci paletę LiveTag i używa barw klubu jako zapasowych —
fakt odnotowany w `meta.warnings`.

`--out-canon` opcjonalny; używany, gdy zdarzenia kanoniczne mają trafić do bazy.

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
    "us":   { "name": "Klub A", "short": "KLA", "color": "#E8722C", "crest": "/storage/crests/3.png" },
    "them": { "name": "Klub B", "short": "KLB", "color": "#2C6FE8", "crest": "/storage/crests/9.png" }
  },
  "mapping_profile": { "version": 4, "rules": [] },
  "sections": ["bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam"],
  "options": { "contrast_fix": true, "engine_locale": "pl_PL" }
}
```

Silnik nie odgaduje nazw ani barw klubów — dostaje je w konfiguracji. Wykryte w danych nazwy
zwraca w `meta.coverage.teams`, żeby PHP mógł zaproponować dopasowanie przy pierwszym imporcie.

---

## Wyjście — `meta.json`

```json
{
  "ok": true,
  "engine_version": "0.3.0",
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

Każde ostrzeżenie ma zawsze trzy pola: `code`, `msg` (po polsku), `count`.

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
  "engine_version": "0.3.0", "missing_columns": ["end"] }
```

`missing_columns` występuje wyłącznie przy kodzie `3`.

**Traceback zawsze na stderr, nigdy na stdout.** Stdout jest zarezerwowany na strukturę JSON.
