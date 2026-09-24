#!/usr/bin/env python3
"""Liczby z sekcji Przegląd, policzone POZA przeglądarką — tak, jak liczy je v21.

    python3 engine/tools/przeglad_liczby.py RAPORT.html

NARZĘDZIE ODBIORU, NIE CZĘŚĆ SILNIKA. Szablon v21 liczy wszystko sam, w JS,
ze zdarzeń w `DATA` — więc sprawdzenie „czy w Przeglądzie jest 2:1" wymagało
dotąd otwarcia raportu w przeglądarce i przepisania liczb ręcznie.

Ten skrypt odtwarza tamten rachunek z wstrzykniętego `DATA`: aliasy tagów z `VARS`,
atrybucję gola po najbliższym strzale, sumę xG po strzałach. Zgadza się z raportem
albo znaczy, że jedno z nich liczy inaczej — i to jest właśnie ta informacja.

CZEGO NIE ZASTĘPUJE: wyglądu. Układ, barwy i czytelność trzeba obejrzeć.

DUBLOWANIE RACHUNKU JEST TU CELOWE i nie jest tym samym, co zakazane dublowanie
w silniku (render nie powtarza atrybucji gola po szablonie). Narzędzie odbioru,
które liczy TĄ SAMĄ funkcją co sprawdzany kod, nie sprawdza niczego.
"""
import json, re, sys

html = open(sys.argv[1], encoding="utf-8").read()
DATA = json.loads(re.search(r"const DATA = (.*?);\n", html, re.S).group(1))
HOME = re.search(r"const HUT='(.*?)', POG='(.*?)'", html).group(1)
AWAY = re.search(r"const HUT='(.*?)', POG='(.*?)'", html).group(2)

ALIAS = {"SBZ PODAJĄCY": "ZDOBYCIE SBZ",
         "III STREFA PODAJĄCY/OTRZYMUJĄCY": "III STREFA"}
ev = DATA["events"]
for e in ev:
    e["tag"] = ALIAS.get(e["tag"], e["tag"])

shots = [e for e in ev if e["tag"] == "STRZAŁ"]
for g in [e for e in ev if e["tag"] == "Gol"]:
    if shots:
        g["team"] = min(shots, key=lambda s: abs(s["b"] - g["b"]))["team"]

# Od sesji 4a HOME to KLUB-TENANT, a nie „drużyna atakująca w lewo"
# (docs/STAN_PIVOTU.md §7.7 a). Kierunek ataku wyprowadza silnik z danych
# i zapisuje w `meta.direction` — nagłówek nie ma prawa go zgadywać.
print(f"{'':22} {'HOME (tenant)':>16} {'AWAY (rywal)':>16}")
print(f"{'drużyna':22} {HOME[:16]:>16} {AWAY[:16]:>16}")
for etykieta, fn in [
    ("gole",          lambda t: sum(1 for e in ev if e["tag"] == "Gol" and e["team"] == t)),
    ("xG",            lambda t: round(sum(e.get("xg") or 0 for e in shots if e["team"] == t), 2)),
    ("strzały",       lambda t: sum(1 for e in shots if e["team"] == t)),
    ("celne",         lambda t: sum(1 for e in shots if e["team"] == t and "CELNY" in e["labels"])),
    ("zdobycie SBZ",  lambda t: sum(1 for e in ev if e["tag"] == "ZDOBYCIE SBZ" and e["team"] == t)),
    ("III strefa",    lambda t: sum(1 for e in ev if e["tag"] == "III STREFA" and e["team"] == t)),
]:
    print(f"{etykieta:22} {fn(HOME)!s:>16} {fn(AWAY)!s:>16}")
