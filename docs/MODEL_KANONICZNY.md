# Model kanoniczny

Warstwa tłumaczenia między eksportem LiveTag a wszystkim, co liczy i renderuje.
Powód istnienia: format LiveTag.Pro nie ma opublikowanej specyfikacji i zmienia się między wersjami.
Bez tej warstwy każda zmiana formatu dotyka całego kodu raportu.

```
eksport (CSV + JSON)
   │  parse — respektuje cytowanie, kodowanie, przecinek dziesiętny
   ▼
raw_frame
   │  canon + profil mapowań klubu
   ▼
canonical_events[]  ←── na tym poziomie liczy się WSZYSTKO
   ├─▶ metrics  → pakiet metryk
   ├─▶ render   → HTML raportu
   └─▶ MySQL    → events_canonical (archiwum, porównania sezonowe)
```

## Zdarzenie kanoniczne

```json
{
  "t_ms": 1234500,
  "half": 1,
  "team_side": "us",
  "concept": "shot",
  "qualifiers": ["on_target", "open_play", "counter"],
  "x": 88.4, "y": 31.2,
  "x_end": null, "y_end": null,
  "xg": 0.14,
  "xg_source": "analyst",
  "player_id": null,
  "source_tag": "STRZAŁ NASZA",
  "source_labels": ["CELNY", "KONTRATAK"],
  "confidence": 1.0
}
```

`team_side` przyjmuje `us`, `them`, `none`. Wartość `none` jest poprawna i częsta — część tagów
w eksportach nie ma przypisanej drużyny i trafia do osobnej sekcji raportu.

`xg_source`: `analyst` (wpisane ręcznie w komentarzu), `model` (policzone ze współrzędnych, moduł M3),
`null` (brak). Wartość szacowana musi być oznaczona w raporcie jako szacowana.

**xG czytamy wyłącznie ze zdarzeń o pojęciu `shot`.** Parser wyciąga z komentarza pierwszą
liczbę, jaką napotka, bo na jego poziomie nie wiadomo, czym jest zdarzenie. Komentarz
„3 zawodników w polu karnym" przy `ZDOBYCIE SBZ` dałby xG = 3.0 i zawyżył sumę meczu bez
żadnego śladu. Odrzucona wartość nie znika po cichu — tag trafia do ostrzeżenia
`XG_POZA_STRZALEM` w raporcie pokrycia.

`concept` bywa `null`: tag, dla którego profil nie ma reguły. Takie zdarzenie **zostaje**
w modelu, z `confidence: 0.0` i zachowanym `source_tag`. Liczba zdarzeń kanonicznych
zawsze równa się liczbie wierszy eksportu — inaczej „294 zdarzenia" w raporcie pokrycia
i zawartość archiwum przestają znaczyć to samo.

## Pojęcia bazowe

`shot` · `entry_sbz` · `entry_third` · `duel` · `loss` · `recovery` · `press` · `transition`
`set_piece` · `foul` · `card` · `keeper_action`

Nowa etykieta w eksporcie to **kwalifikator**, nie nowe pojęcie. Rozrost listy pojęć oznacza,
że model zaczyna kopiować format LiveTag zamiast go tłumaczyć.

## Profil mapowań

```json
{
  "club_id": 3,
  "version": 4,
  "rules": [
    { "match": { "tag": "STRZAŁ NASZA" }, "concept": "shot", "team_side": "us" },
    { "match": { "tag": "MASZA POŁOWA" }, "concept": "entry_third", "team_side": "us",
      "note": "literówka w eksportach klienta — mapowana świadomie" },
    { "match": { "label": "CELNY" }, "qualifier": "on_target" }
  ],
  "unmapped": ["PRESS WYSOKI 2"]
}
```

**Dopasowanie etykiet wyłącznie przez równość na już rozdzielonej liście.** Dopasowanie przez
fragment tekstu łapie `CELNY` wewnątrz `NIECELNY` i psuje liczby po cichu — bez błędu, bez ostrzeżenia.

Profil jest wersjonowany i przypisany do klubu. Tagi nierozpoznane trafiają do `unmapped`
i są widoczne w raporcie pokrycia — nigdy nie znikają milcząco.

## Zapis do archiwum

`canon.to_records()` tłumaczy zdarzenia kanoniczne na kształt tabeli `events_canonical`
(app/migrations/002, po zdjęciu `NOT NULL` z `concept` w 003). Silnik nie chodzi do bazy —
produkuje plik przez `build --out-canon`, a wstawia go PHP. Szczegóły: docs/KONTRAKT_CLI.md.

Dwie różnice wobec kształtu w JSON-ie:

| Model kanoniczny | `events_canonical` |
|---|---|
| `qualifiers` — tablica | `qualifiers_json` — napis JSON, gotowy do wstawienia w kolumnę `JSON` |
| — | `match_id` — silnik dostaje go w konfiguracji, sam go nie zna |

## Nazewnictwo

Pojęcia kanoniczne po angielsku, wzorowane na SPADL (socceraction) — wyłącznie wewnątrz kodu.
W interfejsie i promptach AI wracają nazwy polskie: SBZ, III strefa, pressing, transformacja.
