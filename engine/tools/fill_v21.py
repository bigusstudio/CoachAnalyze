#!/usr/bin/env python3
"""Wypełnia szablon v21 danymi z pary CSV+JSON LiveTag — ręczny podgląd. stdlib only.

    python3 engine/tools/fill_v21.py SZABLON.html MECZ.csv PROJEKT.json WYNIK.html

NARZĘDZIE WARSZTATOWE, NIE ŚCIEŻKA PRODUKCYJNA. Raporty robi `coachanalyze.render`
przez kontrakt CLI (docs/KONTRAKT_CLI.md); ten skrypt służy do obejrzenia nowego
szablonu na konkretnym meczu bez stawiania aplikacji. Nazwy klubów, barwy i meta
meczu są tu WPISANE NA SZTYWNO — w renderze przychodzą z `config.json`.

Leży w `tools/`, a nie obok szablonu: `templates/` to dane pakietu (`package-data`
w pyproject) i plik `.py` w tamtym katalogu wygląda na część silnika, którą nie jest.
"""
import csv, json, re, sys, math
TPL, CSV, JSN, OUT = sys.argv[1:5]
HOME_NAME = "NAPRZÓD JĘDRZEJÓW"      # v17: HOME atakuje bramkę po lewej (w tych danych: JDRZ)
AWAY_NAME = "POGOŃ-SOKÓŁ LUBACZÓW"

def num(s): return float(s) if s not in ("", None) else None
def xg(c):
    m = re.search(r'([\d]+[,.]\d+)', c or ""); return float(m.group(1).replace(",", ".")) if m else None

ev = []
with open(CSV, encoding="utf-8-sig") as f:
    for r in csv.DictReader(f):
        ev.append(dict(tag=r["tag_name"], b=float(r["begin"]), e=float(r["end"]),
                       labels=[l.strip() for l in r["labels"].split(",") if l.strip()],
                       team=r["team"].strip(), player=r["players"].strip(), xg=xg(r["comment"]),
                       x=num(r["pos_x_meters"]), y=num(r["pos_y_meters"]),
                       tx=num(r["pos_target_x_meters"]), ty=num(r["pos_target_y_meters"])))
ALIAS = {}   # v19: aliasy zmiennych żyją w szablonie (VARS), nie w wypełniaczu
ev.sort(key=lambda e: e["b"])
# gol: drużyna po najbliższym strzale (team_uuid gola w eksporcie jest niewiarygodny)
shots = [e for e in ev if e["tag"] == "STRZAŁ"]
for g in [e for e in ev if e["tag"] == "Gol"]:
    g["team"] = min(shots, key=lambda s: abs(s["b"] - g["b"]))["team"]
# przerwa: największa luka w środkowej 1/3
tmax = max(e["e"] for e in ev)
gaps = [(ev[i+1]["b"] - ev[i]["e"], ev[i+1]["b"]) for i in range(len(ev)-1) if tmax/3 < ev[i]["e"] < 2*tmax/3]
half_split = max(gaps)[1]

J = json.load(open(JSN))
def hexc(s):
    p = [float(v) for v in s.split()]; return "#%02x%02x%02x" % tuple(int(round(v*255)) for v in p[:3])
def lum(h): r, g, b = (int(h[i:i+2], 16)/255 for i in (1, 3, 5)); return 0.2126*r+0.7152*g+0.0722*b
pal = {"tags": {}, "labels": {}}
logo_away = ""
for d in J["dependencies"]:
    t = d.get("type"); data = d.get("data", {})
    if t in ("tag", "label"):
        c = hexc(data["color"])
        if lum(c) > 0.85: c = "#9DAFA6"       # białe tagi z LiveTag nie czytają się na ciemnym tle → neutral
        pal[t + "s"][ALIAS.get(data["name"], data["name"])] = c
for a in J.get("avatars", []):
    logo_away = "data:image/png;base64," + a["data_b64"]   # jedyny awatar w eksporcie = Pogoń
import base64
_svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect width='40' height='40' rx='6' fill='#1a1a1a'/><text x='20' y='26' font-size='13' font-family='sans-serif' font-weight='700' fill='#E8C558' text-anchor='middle'>JDRZ</text></svg>"
logo_home = "data:image/svg+xml;base64," + base64.b64encode(_svg.encode()).decode()

DATA = dict(events=ev, half_split=half_split)
html = open(TPL, encoding="utf-8").read()
rep = {"/*__DATA__*/": json.dumps(DATA, ensure_ascii=False), "/*__PAL__*/": json.dumps(pal, ensure_ascii=False),
       "__TEAM_HOME_LABEL__": "Naprzód Jędrzejów", "__TEAM_AWAY_LABEL__": "Pogoń-Sokół Lubaczów",
       "__TEAM_HOME_SHORT__": "JDRZ", "__TEAM_AWAY_SHORT__": "Pogoń",
       "__TEAM_HOME_COLOR__": "#E8C558", "__TEAM_HOME_DIM__": "#5a4d24", "__TEAM_AWAY_COLOR__": "#5B8DEF", "__TEAM_AWAY_DIM__": "#25355c",
       "__TEAM_HOME_COLOR_L__": "#A8780A", "__TEAM_HOME_DIM_L__": "#E9DDB8", "__TEAM_AWAY_COLOR_L__": "#2B5FD9", "__TEAM_AWAY_DIM_L__": "#C9D6F5",
       "__SEZON__": "2026/27", "__KOLEJKA__": "3", "__DATA_MECZU__": "2026-08-01",
       "__LOGO_HOME__": logo_home, "__LOGO_AWAY__": logo_away,
       "__TEAM_HOME__": HOME_NAME, "__TEAM_AWAY__": AWAY_NAME}
for k, v in rep.items(): html = html.replace(k, v)
assert "__" not in re.sub(r"data:[^\"']+", "", html) or not re.search(r"__[A-Z_]+__", html), re.findall(r"__[A-Z_]+__", html)
open(OUT, "w", encoding="utf-8").write(html)
print(OUT, len(html), "bytes; events", len(ev), "half_split", round(half_split), "goals", [(g['team'][:5], round(g['b'])) for g in ev if g['tag']=='Gol'])
