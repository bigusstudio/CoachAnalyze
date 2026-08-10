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
$PYTHON_BIN -m coachanalyze --version               # wersja silnika
```

`inspect` służy ekranowi raportu pokrycia — operator widzi ostrzeżenia **przed** wygenerowaniem raportu.

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
  "engine_version": "0.1.0",
  "format_fingerprint": "sha256:9f3c…",
  "half_split_ms": 2730000,
  "duration_ms": 5820000,
  "coverage": {
    "events": 294,
    "shots": 21,
    "sbz": 38,
    "sbz_with_vector": 31,
    "third_pos": 0,
    "teams": ["NASZA", "RYWAL"],
    "xg_parsed": 17,
    "xg_missing": 4,
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
  "unmapped_tags": ["PRESS WYSOKI 2"]
}
```

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

**Traceback zawsze na stderr, nigdy na stdout.** Stdout jest zarezerwowany na strukturę JSON.
