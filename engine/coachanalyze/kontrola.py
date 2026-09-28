"""Anomalie importu i niezmienniki raportu (W7 G, H).

═══════════════════════════════════════════════════════════════════════════════
ANOMALIA to fakt o PLIKU, który człowiek powinien zobaczyć, zanim uwierzy
liczbom: gol przypisany w wierszu innej drużynie niż strzał, który go dał,
trzecia drużyna w kolumnie `team`, nazwa drużyny spoza meczu w nazwie tagu
(szablon tagów skopiowany z innego meczu), xG spoza 0..1. Anomalia NIE
zatrzymuje importu i niczego nie „naprawia" — raport liczy, jak liczy, a lista
trafia na kartę meczu (admin, analityk).

NIEZMIENNIK to obietnica RAPORTU wobec pliku: każdy wiersz CSV jest
zdarzeniem, każdy tag jest w warstwie 1, xG z komentarzy to xG raportu.
Naruszenie znaczy błąd w silniku albo w danych — baner i wpis dla admina.
═══════════════════════════════════════════════════════════════════════════════

Anomalia: {"typ", "opis", "count", …pola diagnostyczne}. Opis po polsku,
BEZ nazwisk i treści komentarzy (idzie na kartę meczu i do logu korpusu).
"""

import re

from .canon import _norm_team, build_team_lookup
from .events import OKNO_GOLA_S

# Słowa, które nie wyróżniają klubu: formy prawne, drużyny rezerw, przymiotniki.
_SLOWA_OGOLNE = frozenset({
    "klub", "sportowy", "miejski", "ludowy", "uczniowski", "akademia",
    "ks", "mks", "gks", "luks", "uks", "lks", "fc", "sc", "ii", "iii",
})
_MIN_DLUGOSC_SLOWA = 4


def _slowa(tekst):
    return {
        s for s in re.findall(r"[^\W\d_]+", str(tekst or "").casefold())
        if len(s) >= _MIN_DLUGOSC_SLOWA and s not in _SLOWA_OGOLNE
    }


def _anomalia(typ, opis, count=1, **pola):
    return dict({"typ": typ, "opis": opis, "count": count}, **pola)


def druzyny_meczu(frame, config=None):
    """Nazwy drużyn TEGO meczu: z konfiguracji, a bez niej — dwie najczęstsze z kolumny `team`."""
    nazwy = []
    for strona in ("us", "them"):
        cfg = ((config or {}).get("teams") or {}).get(strona) or {}
        nazwy += [cfg.get("name"), cfg.get("short")] + list(cfg.get("source_names") or ())
    nazwy = [n for n in nazwy if n]
    if nazwy:
        return nazwy
    licz = {}
    for e in frame.get("events") or []:
        if e.get("team") is not None:
            licz[e["team"]] = licz.get(e["team"], 0) + 1
    return [n for n, _ in sorted(licz.items(), key=lambda kv: -kv[1])[:2]]


def anomalie(frame, projekt=None, znaczenia=None, przypisanie_goli=None, config=None, markery=None):
    """Lista anomalii importu. Kolejność stała (typ, potem kolejność w pliku)."""
    events = frame.get("events") or []
    znaczenia = znaczenia or {}
    out = []

    # ---------------------------------------------------------------- gole (F)
    for g, s in sorted((przypisanie_goli or {}).items()):
        gol = events[g]
        wlasna = gol.get("team")
        if s is None:
            out.append(_anomalia(
                "gol_bez_strzalu",
                "Gol w {} bez strzału w oknie {} s — drużyna gola nieznana".format(
                    _minuta(gol), int(OKNO_GOLA_S)),
                minuta=_minuta(gol)))
            continue
        ze_strzalu = events[s].get("team")
        if wlasna is None:
            out.append(_anomalia(
                "gol_bez_druzyny",
                "Gol w {} bez drużyny w wierszu — drużyna ze strzału: {}".format(
                    _minuta(gol), ze_strzalu or "brak"),
                minuta=_minuta(gol), druzyna_ze_strzalu=ze_strzalu))
        elif ze_strzalu is not None and ze_strzalu != wlasna:
            out.append(_anomalia(
                "gol_inna_druzyna_niz_strzal",
                "Gol w {}: w wierszu {}, strzał {} — liczy się strzał".format(
                    _minuta(gol), wlasna, ze_strzalu),
                minuta=_minuta(gol), druzyna_wiersza=wlasna, druzyna_ze_strzalu=ze_strzalu))

    # ------------------------------------------------- trzecia drużyna (G)
    # Z konfiguracją drużyn: każda nazwa z kolumny `team`, której dopasowanie
    # drużyn (`canon.build_team_lookup`, to samo co w modelu) nie zna. Bez
    # konfiguracji (`inspect`, korpus): wszystko poza dwiema najczęstszymi.
    licz = {}
    for e in events:
        if e.get("team") is not None:
            licz[e["team"]] = licz.get(e["team"], 0) + 1
    po_liczbie = sorted(licz.items(), key=lambda kv: (-kv[1], kv[0]))
    lookup = build_team_lookup((config or {}).get("teams"), markery)
    if lookup:
        obce = [(n, c) for n, c in po_liczbie if _norm_team(n) not in lookup]
    else:
        obce = po_liczbie[2:]
    for n, c in obce:
        out.append(_anomalia(
            "trzecia_druzyna",
            "Kolumna team: {} ({} zdarzeń) — drużyna spoza meczu, nie łączona z żadną stroną".format(n, c),
            count=c, druzyna=n))
    meczu = druzyny_meczu(frame, config)
    klucze_meczu = {_norm_team(n) for n in meczu}

    # ------------------------------------ drużyna spoza meczu w nazwie tagu (G)
    licz_klucze = {_norm_team(n) for n in licz}
    slowa_meczu = set()
    for n in meczu + list(licz):
        slowa_meczu |= _slowa(n)
    znane = list((config or {}).get("znane_druzyny") or []) + list((projekt or {}).get("druzyny") or [])
    obce_kluby = {}
    for klub in znane:
        if _norm_team(klub) in klucze_meczu or _norm_team(klub) in licz_klucze:
            continue
        wyrozniajace = _slowa(klub) - slowa_meczu
        if wyrozniajace:
            obce_kluby[klub] = wyrozniajace
    for tag in dict.fromkeys(e.get("tag") for e in events if e.get("tag")):
        slowa_tagu = _slowa(tag)
        trafione = sorted(k for k, w in obce_kluby.items() if w & slowa_tagu)
        if trafione:
            out.append(_anomalia(
                "druzyna_w_nazwie_tagu",
                "Tag „{}” nazywa drużynę spoza meczu ({}) — nazwa tagu nie mówi, czyja to akcja".format(
                    tag, ", ".join(trafione)),
                tag=tag, druzyny=trafione))

    # ------------------------------------------------------------------ xG (A)
    poza = frame.get("xg_poza_zakresem") or []
    if poza:
        tagi = sorted({p["tag"] for p in poza if p.get("tag")})
        out.append(_anomalia(
            "xg_poza_zakresem",
            "{} komentarzy w kształcie xG z wartością spoza 0..1 — pominięte w xG ({})".format(
                len(poza), ", ".join(tagi)),
            count=len(poza), tags=tagi))

    for tag, z in sorted(znaczenia.items()):
        if z.get("zrodlo") == "xg" and z.get("nazwa_mowi") not in (None, "shot"):
            out.append(_anomalia(
                "xg_zmienia_znaczenie",
                "Tag „{}” ma xG w komentarzu — liczony jako strzał, choć nazwa znaczy co innego".format(tag),
                tag=tag))

    for u in (projekt or {}).get("nieznane_uuid") or []:
        out.append(_anomalia(
            "nieznany_uuid",
            "Plik projektu odwołuje się do tagu {}…, którego nie definiuje".format(str(u)[:8]),
            uuid=u))
    return out


def _minuta(e):
    b = e.get("b")
    return "{}'".format(max(1, int(b // 60) + 1)) if b is not None else "?"


def _niezmiennik(kod, ok, opis):
    return {"kod": kod, "ok": bool(ok), "opis": opis}


def niezmienniki(frame, warstwa1, znaczenia=None, wiersze_zdarzen=None, direction=None,
                 liczba_wierszy=None):
    """Lista niezmienników z wynikiem. Wszystkie `ok` = raport wierny plikowi.

    `wiersze_zdarzen` — wiersze tabeli `events` (`events.build`); bez nich
    niezmienniki tabeli są pomijane (`inspect`).
    """
    events = frame.get("events") or []
    n_csv = liczba_wierszy if liczba_wierszy is not None else len(events)
    suma_w1 = sum(t["n"] for t in warstwa1.get("tagi") or [])
    tagi_csv = {e.get("tag") for e in events}
    tagi_w1 = {t["nazwa"] for t in warstwa1.get("tagi") or []}
    out = [
        _niezmiennik("wiersze_warstwa1", n_csv == suma_w1 == len(events),
                     "wiersze CSV {} = zdarzenia {} = suma warstwy 1 {}".format(n_csv, len(events), suma_w1)),
        _niezmiennik("tagi_warstwa1", tagi_csv <= tagi_w1,
                     "każdy tag z CSV w warstwie 1 ({} z {})".format(len(tagi_csv & tagi_w1), len(tagi_csv))),
    ]
    if wiersze_zdarzen is not None:
        pominiete = n_csv - len(wiersze_zdarzen)
        out.append(_niezmiennik(
            "wiersze_zdarzenia", pominiete == 0,
            "wiersze CSV {} = zdarzenia do zapisu {}".format(n_csv, len(wiersze_zdarzen))))
        gracze_csv = {p for p in frame.get("players") or [] if p}
        gracze_ev = {w.get("player") for w in wiersze_zdarzen if w.get("player")}
        out.append(_niezmiennik(
            "zawodnicy", gracze_csv <= gracze_ev,
            "zawodnicy z CSV w zdarzeniach: {} z {}".format(len(gracze_csv & gracze_ev), len(gracze_csv))))

    # xG: suma z komentarzy = xG raportu (tagi, które szablon liczy jako strzał).
    znaczenia = znaczenia or {}
    xg_kom = round(sum(e["xg"] for e in events if e.get("xg") is not None), 2)
    strzalowe = {t for t, z in znaczenia.items() if z.get("klucz") == "STRZAŁ"} | {"STRZAŁ"}
    xg_rap = round(sum(e["xg"] for e in events if e.get("xg") is not None and e.get("tag") in strzalowe), 2)
    out.append(_niezmiennik(
        "xg", xg_kom == xg_rap,
        "xG z komentarzy {:.2f} = xG raportu {:.2f}".format(xg_kom, xg_rap)))

    kier = direction or {}
    wartosc = kier.get("us") if kier.get("us") in ("left", "right") and kier.get("confidence") == "high" else "niepewny"
    out.append(_niezmiennik(
        "kierunek", wartosc in ("left", "right", "niepewny"),
        "kierunek ataku: {}".format({"left": "w lewo", "right": "w prawo"}.get(wartosc, "niepewny"))))
    return out


def wszystkie_ok(lista):
    return all(n.get("ok") for n in lista or [])
