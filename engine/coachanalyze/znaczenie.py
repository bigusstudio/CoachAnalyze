"""Znaczenie tagu z pliku — bez zgadywania i bez AI (W7, metoda importu v3).

═══════════════════════════════════════════════════════════════════════════════
KOLEJNOŚĆ ROZSTRZYGANIA — PIERWSZA REGUŁA, KTÓRA COŚ MÓWI, WYGRYWA:

  1. SŁOWNIK KLUBU — decyzja człowieka: zmienna templatu z pojęciem (`canon`).
     Dopasowanie po UUID tagu (`variables[].uuids`), zapasowo po nazwie
     znormalizowanej (`normalizuj`) — nazwa główna i aliasy zmiennej.
  2. xG W KOMENTARZU -> `shot`. Parser czyta xG wyłącznie w ścisłym kształcie
     (`parse.parse_xg`), więc opis „3 zawodników w polu karnym" tu nie wejdzie.
  3. NAZWA PO NORMALIZACJI + ALIASY (`config/aliasy.json`): „Strzał" i „STRZAŁ"
     to ta sama nazwa (raport 32: Stal taguje „Strzał" i miała STRZAŁY –).
  4. NIC — tag zostaje bez interpretacji. Jego dane NIE ZNIKAJĄ: warstwa 1
     raportu („Wszystkie tagi z pliku") pokazuje go pod nazwą z pliku.

Model językowy nie bierze w tym udziału (D5, W7: warstwa AI odłożona do W8).
═══════════════════════════════════════════════════════════════════════════════

NAZWA TAGU NIE MÓWI, CZYJA TO AKCJA. „Posiadanie Górnik Strachocina" w meczu,
w którym Górnik nie gra, to szablon skopiowany przez analityka między meczami.
Drużyny NIGDY nie wywodzimy z nazwy tagu — tag drużynowy dostaje w Słowniku
STRONĘ (`variables[].side`: `us` / `them`), a nie nazwę klubu.

Dopasowanie po normalizacji to wciąż RÓWNOŚĆ CAŁEJ NAZWY (pułapka 7):
`SBZ PODAJĄCY` i `SBZ PODAJĄCY/OTRZYMUJĄCY` zostają dwiema nazwami.
"""

import re

from . import aliasy, canon

ZRODLO_SLOWNIK = "slownik"
ZRODLO_XG = "xg"
ZRODLO_NAZWA = "nazwa"

STRONY = ("us", "them")

# Pojęcia spoza `canon.CONCEPTS`, które Słownik może nadać tagowi. Nie wchodzą
# do metryk kanonicznych (tam ich nie ma), ale Przegląd raportu je pokazuje:
# gol (wynik meczu), posiadanie (czas), podanie, wprowadzenie, akcja defensywna.
POJECIA_PREZENTACJI = frozenset({"goal", "possession", "pass", "zone_entry", "defensive_action"})

# Tag wbudowany szablonu v21 dla pojęcia. Szablon liczy w JS po NAZWIE tagu
# (`e.tag==='STRZAŁ'`), więc znaczenie trafia do niego jako alias tej nazwy
# (`__VARS_TEMPLATU__`). Pojedynek ma trzy tagi — wybiera kwalifikator.
KLUCZ_POJECIA = {
    "shot": "STRZAŁ",
    "goal": "Gol",
    "entry_sbz": "ZDOBYCIE SBZ",
    "entry_third": "III STREFA",
    "recovery": "ODBIÓR",
    "loss": "STRATA",
}
KLUCZ_KWALIFIKATORA = {
    ("duel", "offensive"): "1x1 OFF",
    ("duel", "defensive"): "1x1 DEF.",
    ("duel", "first_contact"): "PIERWSZY KONTAKT",
    ("press", "effective"): "SKUTECZNY",
    ("press", "ineffective"): "NISKUTECZNY",
}

# Nazwy znane silnikowi poza domyślnym profilem kanonicznym. `Gol` nie jest
# pojęciem kanonicznym (wynik to kwalifikator strzału), ale szablon liczy go
# jako gol — i tak samo ma go rozpoznać „Gol"/„GOL" w innym eksporcie.
NAZWY_DODATKOWE = {"Gol": ("goal", ())}


def normalizuj(nazwa):
    """Klucz porównania nazw: casefold, łączniki i kropki jako spacje, jedna spacja.

    Bliźniak w PHP: `NazwaZmiennej::klucz` — zmiana tutaj wymaga zmiany tam.
    """
    if nazwa is None:
        return ""
    tekst = re.sub(r"[‐-―\-.]+", " ", str(nazwa))
    return " ".join(tekst.split()).casefold()


def znane_nazwy():
    """{nazwa znormalizowana: (nazwa główna, pojęcie, kwalifikatory)}.

    Domyślny profil silnika, aliasy z `config/aliasy.json` (alias dostaje
    pojęcie nazwy głównej) i `NAZWY_DODATKOWE`.
    """
    out = {}
    for nazwa, regula in canon.DEFAULT_TAG_RULES.items():
        out.setdefault(normalizuj(nazwa), (nazwa, regula["concept"], tuple(regula["qualifiers"])))
    for nazwa, (pojecie, kw) in NAZWY_DODATKOWE.items():
        out.setdefault(normalizuj(nazwa), (nazwa, pojecie, kw))
    for alias, glowna in aliasy.odwrotne().items():
        regula = canon.DEFAULT_TAG_RULES.get(glowna)
        if regula is not None:
            out.setdefault(normalizuj(alias), (glowna, regula["concept"], tuple(regula["qualifiers"])))
    return out


def klucz_szablonu(pojecie, kwalifikatory=(), nazwa_glowna=None):
    """Nazwa tagu wbudowanego szablonu dla znaczenia albo None."""
    if nazwa_glowna and (nazwa_glowna in canon.DEFAULT_TAG_RULES or nazwa_glowna in NAZWY_DODATKOWE):
        return nazwa_glowna
    for kw in kwalifikatory or ():
        klucz = KLUCZ_KWALIFIKATORA.get((pojecie, kw))
        if klucz:
            return klucz
    return KLUCZ_POJECIA.get(pojecie)


def _slownik(template):
    """(po uuid, po nazwie znormalizowanej) — zmienne-tagi z POJĘCIEM albo STRONĄ.

    Zmienna bez pojęcia i bez strony to nie decyzja, tylko ślad importu
    (import dopisuje tagi do templatu sam, z `canon: null`) — nie blokuje
    reguł 2 i 3.
    """
    po_uuid, po_nazwie = {}, {}
    for z in (template or {}).get("variables") or []:
        if not isinstance(z, dict):
            continue
        zrodlo = z.get("source") or {}
        if zrodlo.get("type") not in (None, "tag"):
            continue
        strona = z.get("side") if z.get("side") in STRONY else None
        if z.get("canon") is None and strona is None:
            continue
        for u in z.get("uuids") or ():
            if u:
                po_uuid.setdefault(str(u), z)
        for nazwa in [zrodlo.get("raw")] + list(z.get("aliases") or ()):
            if nazwa and str(nazwa).strip():
                po_nazwie.setdefault(normalizuj(nazwa), z)
    return po_uuid, po_nazwie


def rozstrzygnij(frame, projekt=None, template=None):
    """{tag z pliku: znaczenie} dla KAŻDEGO tagu obecnego w zdarzeniach.

    Znaczenie:
        {"pojecie": str|None, "kwalifikatory": [..], "strona": "us"|"them"|None,
         "zrodlo": "slownik"|"xg"|"nazwa"|None, "klucz": tag wbudowany szablonu|None,
         "uuid": uuid tagu z pliku projektu|None,
         "nazwa_mowi": pojęcie wg reguły nazwy (do anomalii konfliktu)|None}
    """
    events = frame.get("events") or []
    tagi = list(dict.fromkeys(e.get("tag") for e in events if e.get("tag") is not None))
    z_xg = {e.get("tag") for e in events if e.get("xg") is not None}
    po_nazwie_projektu = (projekt or {}).get("po_nazwie") or {}
    slownik_uuid, slownik_nazwa = _slownik(template)
    znane = znane_nazwy()

    wynik = {}
    for tag in tagi:
        uuid = (po_nazwie_projektu.get(tag) or {}).get("uuid")
        z_nazwy = znane.get(normalizuj(tag))
        zmienna = (slownik_uuid.get(uuid) if uuid else None) or slownik_nazwa.get(normalizuj(tag))

        pojecie, kw, strona, zrodlo, glowna = None, (), None, None, None
        if zmienna is not None:
            strona = zmienna.get("side") if zmienna.get("side") in STRONY else None
        if zmienna is not None and zmienna.get("canon") is not None:
            pojecie, zrodlo = str(zmienna["canon"]), ZRODLO_SLOWNIK
            # Kwalifikator z nazwy tylko przy ZGODNYM pojęciu: Słownik mówi
            # „pojedynek", nazwa mówi „1x1 OFF" — to ten sam pojedynek ofensywny.
            if z_nazwy and z_nazwy[1] == pojecie:
                kw, glowna = z_nazwy[2], z_nazwy[0]
        elif tag in z_xg:
            pojecie, zrodlo = "shot", ZRODLO_XG
            if z_nazwy and z_nazwy[1] == "shot":
                glowna = z_nazwy[0]
        elif z_nazwy is not None:
            glowna, pojecie, kw = z_nazwy
            zrodlo = ZRODLO_NAZWA
        elif strona is not None:
            # Sama strona ze Słownika (np. posiadanie bez pojęcia) — decyzja
            # człowieka, choć bez pojęcia.
            zrodlo = ZRODLO_SLOWNIK

        wynik[tag] = {
            "pojecie": pojecie,
            "kwalifikatory": list(kw),
            "strona": strona,
            "zrodlo": zrodlo,
            "klucz": klucz_szablonu(pojecie, kw, glowna) if pojecie else None,
            "uuid": uuid,
            "nazwa_mowi": z_nazwy[1] if z_nazwy else None,
        }
    return wynik


def reguly_profilu(znaczenia):
    """Znaczenia -> reguły profilu mapowań (`canon.resolve_profile`).

    Tylko pojęcia kanoniczne (`canon.CONCEPTS`) — gol czy posiadanie nie są
    metrykami archiwum. Strona ze Słownika idzie jako `team_side` reguły:
    stosuje się do zdarzeń z pustą albo nierozpoznaną kolumną `team`.
    """
    reguly = []
    for tag, z in (znaczenia or {}).items():
        pojecie = z.get("pojecie") if z.get("pojecie") in canon.CONCEPTS else None
        if pojecie is None and z.get("strona") is None:
            continue
        reguly.append({
            "match": {"tag": tag},
            "concept": pojecie,
            "qualifiers": list(z.get("kwalifikatory") or ()),
            "team_side": z.get("strona"),
        })
    return reguly


def aliasy_szablonu(znaczenia):
    """{tag wbudowany szablonu: [tagi z pliku o tym znaczeniu]} — do `VARS`.

    Tag, który już JEST tagiem wbudowanym, aliasu nie potrzebuje.
    """
    out = {}
    for tag, z in (znaczenia or {}).items():
        klucz = z.get("klucz")
        if klucz and klucz != tag:
            out.setdefault(klucz, []).append(tag)
    return {k: sorted(v) for k, v in out.items()}


def tagi_pojecia(znaczenia, pojecie):
    """Tagi z pliku o danym pojęciu (kolejność stała)."""
    return sorted(t for t, z in (znaczenia or {}).items() if z.get("pojecie") == pojecie)
