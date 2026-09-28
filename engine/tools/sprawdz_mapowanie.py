#!/usr/bin/env python3
"""Twarde reguły dla proponowanego mapowania tagów na pojęcia — sprawdzone na danych.

    python3 engine/tools/sprawdz_mapowanie.py EKSPORT.csv MAPOWANIE.json [--json EKSPORT.json]

NARZĘDZIE TESTU W7-T, NIE CZĘŚĆ SILNIKA. Propozycję mapowania daje model językowy
(ślepy mapper), a model językowy niczego nie liczy (D5). Ten skrypt jest bramką:
propozycja przechodzi sama wyłącznie wtedy, gdy model jest pewny I dane jej nie
przeczą. W każdym innym wypadku decyduje człowiek.

Wejście MAPOWANIE.json:
    {"mapowanie": [{"tag": "...", "pojecie": "shot", "pewnosc": 0.95, ...}, ...]}

Reguły (każda liczy się na surowych zdarzeniach eksportu, nie na karcie):

  R1 shot_pozycja        shot ma pos_x w >= 80% zdarzeń
  R2 shot_kierunek       w każdej parze (drużyna, połowa) z >= 3 strzałami co
                         najmniej 75% strzałów leży po jednej stronie środka (52,5 m)
  R3 goal_le_shot        liczba goli <= liczba strzałów, dla każdej drużyny i łącznie
  R4 possession_czas     mediana czasu trwania posiadania > 3 s
  R5 przedzial           press i possession to przedziały: cm=true w definicji
                         tagu ALBO zmienny czas trwania (co najmniej 20% zdarzeń
                         odbiega od najczęstszej długości o > 0,5 s). Stała długość
                         to okno time_before+time_after, czyli znacznik chwili.

Brak danych do reguły = reguła NIEZALICZONA („nd"), nie pominięta: auto-akceptacja
wymaga KOMPLETU zaliczonych reguł, a „nie dało się sprawdzić" kompletem nie jest.

Auto-akceptacja: pewność >= 0,9 ORAZ wszystkie reguły pojęcia zaliczone.
Pojęcie bez reguł przechodzi na samej pewności.

Dodatkowo (nie wpływa na status, idzie do raportu jako anomalie):
  - gol, którego drużyna z wiersza różni się od drużyny najbliższego strzału
    w oknie OKNO_GOLA_S (ta sama zasada co events.atrybucja_goli),
  - gol bez drużyny,
  - tag posiadania, którego nazwa nie wskazuje jednoznacznie drużyny meczu.

Tylko biblioteka standardowa (CLAUDE.md §3a).
"""
import argparse
import collections
import json
import pathlib
import re
import statistics
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from coachanalyze.events import OKNO_GOLA_S  # noqa: E402
from coachanalyze.sources.livetag.parse import (  # noqa: E402
    detect_half_split, is_na, read_rows, to_float,
)

PROG_PEWNOSCI = 0.9
PROG_POZYCJI = 0.8
PROG_KIERUNKU = 0.75
MIN_PROBKA_KIERUNKU = 3
SRODEK_M = 52.5
MIN_POSIADANIE_S = 3.0
TOLERANCJA_DLUGOSCI_S = 0.5
PROG_ZMIENNOSCI = 0.2

REGULY_POJEC = {
    "shot": ("R1_shot_pozycja", "R2_shot_kierunek"),
    "goal": ("R3_goal_le_shot",),
    "possession": ("R4_possession_czas", "R5_przedzial"),
    "press": ("R5_przedzial",),
}

AUTO = "auto"
ADMIN = "do akceptacji admina"


def _team(r):
    t = r.get("team")
    return None if is_na(t) or not t.strip() else t.strip()


def _czas(r):
    b, e = to_float(r.get("begin")), to_float(r.get("end"))
    if b is None or e is None:
        return None
    return e - max(0.0, b)


def _wynik(ok, opis):
    return {"wynik": "tak" if ok is True else ("nie" if ok is False else "nd"), "opis": opis}


def r1_pozycja(ev):
    if not ev:
        return _wynik(None, "brak zdarzeń")
    z = sum(1 for r in ev if to_float(r.get("pos_x_meters")) is not None)
    return _wynik(z / len(ev) >= PROG_POZYCJI, f"pos_x w {z}/{len(ev)} zdarzeń")


def r2_kierunek(ev, polowa):
    grupy = collections.defaultdict(list)
    for r in ev:
        x, b = to_float(r.get("pos_x_meters")), to_float(r.get("begin"))
        if x is None or b is None:
            continue
        grupy[(_team(r) or "brak", 1 if b < polowa else 2)].append(x)
    ocenione, zle = [], []
    for (team, h), xs in sorted(grupy.items()):
        if len(xs) < MIN_PROBKA_KIERUNKU:
            continue
        prawo = sum(1 for x in xs if x > SRODEK_M)
        udzial = max(prawo, len(xs) - prawo) / len(xs)
        opis = f"{team}/p{h}: {prawo}/{len(xs)} za środkiem"
        ocenione.append(opis)
        if udzial < PROG_KIERUNKU:
            zle.append(opis)
    if not ocenione:
        return _wynik(None, "za mało strzałów z pozycją w każdej parze drużyna/połowa")
    return _wynik(not zle, "; ".join(zle or ocenione))


def r3_gole(gole, strzaly):
    if not strzaly and not gole:
        return _wynik(None, "brak goli i strzałów")
    g, s = collections.Counter(_team(r) for r in gole), collections.Counter(_team(r) for r in strzaly)
    zle = [f"{t or 'brak'}: gole {n} > strzały {s.get(t, 0)}"
           for t, n in g.items() if t is not None and n > s.get(t, 0)]
    if len(gole) > len(strzaly):
        zle.append(f"łącznie: gole {len(gole)} > strzały {len(strzaly)}")
    return _wynik(not zle, "; ".join(zle) or f"gole {len(gole)} <= strzały {len(strzaly)}")


def r4_posiadanie(ev):
    czasy = [c for c in map(_czas, ev) if c is not None]
    if not czasy:
        return _wynik(None, "brak czasów")
    med = statistics.median(czasy)
    return _wynik(med > MIN_POSIADANIE_S, f"mediana {med:.1f} s")


def r5_przedzial(ev, definicja):
    if definicja and definicja.get("cm") is True:
        return _wynik(True, "cm=true w definicji tagu")
    czasy = [round(c, 1) for c in map(_czas, ev) if c is not None]
    if not czasy:
        return _wynik(None, "brak czasów i cm")
    moda = collections.Counter(czasy).most_common(1)[0][0]
    odb = sum(1 for c in czasy if abs(c - moda) > TOLERANCJA_DLUGOSCI_S)
    ok = odb / len(czasy) >= PROG_ZMIENNOSCI
    return _wynik(ok, f"cm={definicja.get('cm') if definicja else '?'}; "
                      f"{odb}/{len(czasy)} odbiega od długości {moda} s")


def _definicje(json_path):
    if not json_path:
        return {}
    with open(json_path, encoding="utf-8") as fh:
        dane = json.load(fh)
    return {
        (d.get("data") or {}).get("name"): (d["data"].get("params") or {})
        for d in dane.get("dependencies", []) if d.get("type") == "tag"
    }


def _tokeny(s):
    return {t for t in re.findall(r"[^\W\d_]+", s.lower()) if len(t) > 2}


def anomalie(rows, mapowanie):
    wynik = []
    druzyny = sorted({t for t in map(_team, rows) if t})
    po_pojeciu = collections.defaultdict(set)
    for m in mapowanie:
        po_pojeciu[m["pojecie"]].add(m["tag"])
    strzaly = [r for r in rows if r["tag_name"] in po_pojeciu["shot"] and to_float(r.get("begin")) is not None]
    for r in rows:
        if r["tag_name"] not in po_pojeciu["goal"]:
            continue
        b = to_float(r.get("begin"))
        wlasna = _team(r)
        blisko = min(strzaly, key=lambda s: abs(to_float(s["begin"]) - b), default=None) if b is not None else None
        ze_strzalu = _team(blisko) if blisko and abs(to_float(blisko["begin"]) - b) <= OKNO_GOLA_S else None
        if wlasna is None:
            wynik.append({"typ": "gol_bez_druzyny", "tag": r["tag_name"],
                          "druzyna_ze_strzalu": ze_strzalu})
        elif ze_strzalu is not None and ze_strzalu != wlasna:
            wynik.append({"typ": "gol_inna_druzyna_niz_strzal", "tag": r["tag_name"],
                          "druzyna_wiersza": wlasna, "druzyna_ze_strzalu": ze_strzalu})
    for tag in sorted(po_pojeciu["possession"]):
        reszta = _tokeny(tag) - {"posiadanie"}
        trafione = [d for d in druzyny if reszta & _tokeny(d)]
        if len(trafione) != 1:
            wynik.append({"typ": "druzyna_w_nazwie_tagu", "tag": tag, "druzyny_meczu": druzyny,
                          "pasuje_do": trafione})
    return wynik


def sprawdz(csv_path, mapowanie, json_path=None):
    rows, _ = read_rows(csv_path)
    defs = _definicje(json_path)
    polowa = detect_half_split([b for b in (to_float(r.get("begin")) for r in rows) if b is not None])
    po_tagu = collections.defaultdict(list)
    for r in rows:
        po_tagu[r["tag_name"]].append(r)
    tagi_pojecia = collections.defaultdict(list)
    for m in mapowanie:
        tagi_pojecia[m["pojecie"]].append(m["tag"])
    gole = [r for t in tagi_pojecia["goal"] for r in po_tagu.get(t, [])]
    strzaly = [r for t in tagi_pojecia["shot"] for r in po_tagu.get(t, [])]

    wyniki = []
    for m in mapowanie:
        ev = po_tagu.get(m["tag"], [])
        reguly = {}
        for nazwa in REGULY_POJEC.get(m["pojecie"], ()):
            if nazwa == "R1_shot_pozycja":
                reguly[nazwa] = r1_pozycja(ev)
            elif nazwa == "R2_shot_kierunek":
                reguly[nazwa] = r2_kierunek(ev, polowa)
            elif nazwa == "R3_goal_le_shot":
                reguly[nazwa] = r3_gole(gole, strzaly)
            elif nazwa == "R4_possession_czas":
                reguly[nazwa] = r4_posiadanie(ev)
            elif nazwa == "R5_przedzial":
                reguly[nazwa] = r5_przedzial(ev, defs.get(m["tag"]))
        komplet = all(r["wynik"] == "tak" for r in reguly.values())
        pewnosc = float(m.get("pewnosc") or 0)
        wyniki.append({
            "tag": m["tag"],
            "pojecie": m["pojecie"],
            "pewnosc": pewnosc,
            "reguly": reguly,
            "reguly_komplet": komplet,
            "status": AUTO if pewnosc >= PROG_PEWNOSCI and komplet else ADMIN,
        })
    return {"polowa_s": round(polowa, 1), "tagi": wyniki, "anomalie": anomalie(rows, mapowanie)}


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("csv")
    ap.add_argument("mapowanie")
    ap.add_argument("--json", help="eksport JSON (definicje tagów: cm)")
    a = ap.parse_args(argv)
    with open(a.mapowanie, encoding="utf-8") as fh:
        mapowanie = json.load(fh)["mapowanie"]
    json.dump(sprawdz(a.csv, mapowanie, a.json), sys.stdout, ensure_ascii=False, indent=1)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
