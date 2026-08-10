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

## Nazewnictwo

Pojęcia kanoniczne po angielsku, wzorowane na SPADL (socceraction) — wyłącznie wewnątrz kodu.
W interfejsie i promptach AI wracają nazwy polskie: SBZ, III strefa, pressing, transformacja.
