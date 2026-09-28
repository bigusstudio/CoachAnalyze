"""Metoda importu v3 (W7) — jedno miejsce, które składa jej kroki w kolejności.

    projekt JSON  ->  znaczenia  ->  gole ze strzałów  ->  (model, render)
                                                     ->  warstwa 1, anomalie, niezmienniki

PO CO OSOBNY MODUŁ: `build` i `inspect` muszą dojść do TYCH SAMYCH znaczeń
i tych samych anomalii. Dwie kopie tej kolejności w `cli.py` rozjechałyby się
przy pierwszej zmianie — a ekran przygotowania importu (inspect) pokazałby
co innego niż raport (build).
"""

from . import kontrola, plik, znaczenie
from . import events as events_mod
from .sources.livetag import projekt as projekt_mod


def przesuniecia(projekt):
    """{tag: time_before} z pliku projektu — do chwili kliknięcia przy golach."""
    out = {}
    for t in (projekt or {}).get("tagi") or []:
        if t.get("time_before") is not None and t.get("nazwa") not in out:
            out[t["nazwa"]] = t["time_before"]
    return out


def przygotuj(frame, json_path=None, template=None):
    """(ramka z drużynami goli, stan) — pierwszy krok, przed modelem kanonicznym.

    Ramka wynikowa różni się od wejściowej WYŁĄCZNIE drużyną w wierszach goli
    (drużyna strzału albo brak). Warstwa 1 i anomalie liczą się z ramki
    ORYGINALNEJ — tam jest to, co w pliku.
    """
    projekt = projekt_mod.wczytaj(json_path) if json_path else None
    znaczenia = znaczenie.rozstrzygnij(frame, projekt, template)
    tagi_goli = znaczenie.tagi_pojecia(znaczenia, "goal")
    tagi_strzalow = znaczenie.tagi_pojecia(znaczenia, "shot")
    przes = przesuniecia(projekt)
    przypisanie = events_mod.przypisz_gole(
        frame.get("events") or [], tagi_goli, tagi_strzalow, przes or None)

    zdarzenia = list(frame.get("events") or [])
    for g, s in przypisanie.items():
        zdarzenia[g] = dict(zdarzenia[g], team=zdarzenia[s].get("team") if s is not None else None)
    ramka = dict(frame, events=zdarzenia)

    return ramka, {
        "projekt": projekt,
        "znaczenia": znaczenia,
        "tagi_goli": tagi_goli,
        "tagi_strzalow": tagi_strzalow,
        "przesuniecia": przes,
        "przypisanie_goli": przypisanie,
    }


def profil(frame, projekt):
    """Odcisk profilu analityka (W7 D): UUID tagów i nazwy znormalizowane."""
    tagi = list(dict.fromkeys(e.get("tag") for e in frame.get("events") or [] if e.get("tag")))
    nazwy = set(tagi) | {t["nazwa"] for t in (projekt or {}).get("tagi") or [] if t.get("nazwa")}
    return {
        "uuid": projekt_mod.uuid_tagow(projekt),
        "nazwy": sorted({znaczenie.normalizuj(n) for n in nazwy}),
        "nazwa_uuid": {
            t["nazwa"]: t["uuid"] for t in (projekt or {}).get("tagi") or [] if t.get("nazwa")
        },
    }


def wynik_strzalu(frame, znaczenia):
    """Czy eksport niesie wynik strzału w etykietach (CELNY / NIECELNY / ZABLOKOWANY)."""
    strzalowe = {t for t, z in (znaczenia or {}).items() if z.get("pojecie") == "shot"}
    wyniki = {"CELNY", "NIECELNY", "ZABLOKOWANY"}
    return any(
        wyniki.intersection(e.get("labels") or ())
        for e in frame.get("events") or [] if e.get("tag") in strzalowe
    )


def meta_w7(frame, stan, config=None, template=None, frame_widok=None, wiersze_zdarzen=None,
            direction=None):
    """Klucze `meta.json` dokładane przez W7 i dane warstwy 1 dla renderu.

    @return (klucze meta, dane warstwy 1)
    """
    projekt = stan["projekt"]
    znaczenia = stan["znaczenia"]
    w1 = plik.zbuduj(frame, frame_widok=frame_widok, projekt=projekt, znaczenia=znaczenia)
    from . import report_template as tpl
    anomalie = kontrola.anomalie(
        frame, projekt, znaczenia, stan["przypisanie_goli"], config=config,
        markery=tpl.team_markers(template))
    niezm = kontrola.niezmienniki(
        frame, w1, znaczenia, wiersze_zdarzen=wiersze_zdarzen, direction=direction)
    return {
        "sha256_zdarzen": frame.get("sha256_zdarzen"),
        "wersja_livetag": (projekt or {}).get("wersja_livetag"),
        "profil": profil(frame, projekt),
        "znaczenia": {
            t: {k: z[k] for k in ("pojecie", "kwalifikatory", "strona", "zrodlo", "klucz")}
            for t, z in znaczenia.items()
        },
        "nierozpoznane": sorted(
            t for t, z in znaczenia.items() if not z.get("pojecie") and not z.get("strona")),
        "wynik_strzalu": wynik_strzalu(frame, znaczenia),
        "gol_w_eksporcie": bool(stan["tagi_goli"]),
        "anomalie": anomalie,
        "niezmienniki": niezm,
        "niezmienniki_ok": kontrola.wszystkie_ok(niezm),
    }, w1
