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

PERSPEKTYWA TENANTA (0.16.3). Tagi bez drużyny — odbiór, strata, 1x1 — taguje
analityk klubu-tenanta, więc należą do LEWEJ kolumny (HOME = tenant). Rywal
dostaje z nich tylko to, co wynika: nasza strata to jego odbiór, nasz odbiór to
jego strata, nasz przegrany pojedynek to jego wygrany. Do 0.16.2 szablon
przypinał je do prawej kolumny; to narzędzie liczy je niezależnie od szablonu,
żeby rozjazd był widoczny.
"""
import json
import re
import sys

# Zapas dla raportów sprzed wstrzykiwania `VARS` z templatu (silnik < 0.14).
ALIAS = {"SBZ PODAJĄCY": "ZDOBYCIE SBZ",
         "III STREFA PODAJĄCY/OTRZYMUJĄCY": "III STREFA"}


def aliasy_raportu(html):
    """{alias: nazwa zmiennej} — TE SAME, które zastosuje szablon (`ALIAS` w v21).

    Szablon przemianowuje zdarzenie na surową nazwę zmiennej, której alias
    pasuje. Narzędzie z własną, stałą listą nie widziało tego przemianowania
    i przy raporcie 28 (templat Pogoni v6: „STRZAŁ" jako alias „Strzał")
    liczyło strzały, których raport nie pokazywał. Czytamy więc literał
    wstrzyknięty przez `render.vars_slot`.
    """
    znacznik = "Object.entries("
    koniec = html.find("}).forEach(([k,v])=>{VARS[k]")
    start = html.rfind(znacznik, 0, koniec) if koniec >= 0 else -1
    if start < 0:
        return dict(ALIAS)
    obiekt, _ = json.JSONDecoder().raw_decode(html[start + len(znacznik):])
    # Jak `Object.assign` per klucz w szablonie: wpis z templatu z polem
    # `aliases` zastępuje aliasy TEJ zmiennej, reszta zostaje. Raporty sprzed
    # 0.14 mają tu pusty obiekt, a aliasy w samym szablonie — stąd punkt wyjścia.
    out = dict(ALIAS)
    for nazwa, wpis in obiekt.items():
        if "aliases" not in (wpis or {}):
            continue
        out = {a: n for a, n in out.items() if n != nazwa}
        for alias in wpis["aliases"] or []:
            out[alias] = nazwa
    return out


def wczytaj(html):
    """(zdarzenia, tenant, rywal) z wyrenderowanego raportu v21."""
    data = json.loads(re.search(r"const DATA = (.*?);\n", html, re.S).group(1))
    druzyny = re.search(r"const HUT='(.*?)', POG='(.*?)'", html)
    ev = data["events"]
    alias = aliasy_raportu(html)
    for e in ev:
        e["tag"] = alias.get(e["tag"], e["tag"])
    shots = [e for e in ev if e["tag"] == "STRZAŁ"]
    for g in [e for e in ev if e["tag"] == "Gol"]:
        # Bez ani jednego strzału gol nie ma drużyny — jak `atrybujGole` w v21 (W1).
        g["team"] = min(shots, key=lambda s: abs(s["b"] - g["b"]))["team"] if shots else None
    return ev, druzyny.group(1), druzyny.group(2)


def liczby(html):
    """[(etykieta, tenant, rywal)] — w kolejności wydruku.

    Etykiety dopasowane RÓWNOŚCIĄ na liście (`"WYGRANY" in e["labels"]`, gdzie
    `labels` to lista) — pułapka 7, `CELNY` wewnątrz `NIECELNY`.
    """
    ev, tenant, rywal = wczytaj(html)
    shots = [e for e in ev if e["tag"] == "STRZAŁ"]
    # Jak `ovStats` w szablonie: po tagu, bez patrzenia na `team` — te tagi
    # w eksportach referencyjnych drużyny nie mają, a gdy mają, v21 i tak
    # liczy je tenantowi. Narzędzie ma pokazać TĘ SAMĄ liczbę co raport.
    tag = lambda t: [e for e in ev if e["tag"] == t]
    wyg = lambda arr: sum(1 for e in arr if "WYGRANY" in e["labels"])
    odb, strata = tag("ODBIÓR"), tag("STRATA")
    duele = tag("1x1 OFF") + tag("1x1 DEF.")

    # Tag nieobecny W CAŁYM MECZU daje „–", nie 0 — jak `dostepnosc` w v21 (W1).
    jest = lambda t: any(e["tag"] == t for e in ev)
    strzaly_sa = jest("STRZAŁ")
    wiersze = []
    for etykieta, fn in [
        ("gole",          lambda t: sum(1 for e in ev if e["tag"] == "Gol" and e["team"] == t)),
        ("xG",            lambda t: round(sum(e.get("xg") or 0 for e in shots if e["team"] == t), 2)),
        ("strzały",       lambda t: sum(1 for e in shots if e["team"] == t)),
        ("celne",         lambda t: sum(1 for e in shots if e["team"] == t and "CELNY" in e["labels"])),
        ("zdobycie SBZ",  lambda t: sum(1 for e in ev if e["tag"] == "ZDOBYCIE SBZ" and e["team"] == t)),
        ("III strefa",    lambda t: sum(1 for e in ev if e["tag"] == "III STREFA" and e["team"] == t)),
    ]:
        wymaga = {"gole": strzaly_sa, "xG": strzaly_sa, "strzały": strzaly_sa, "celne": strzaly_sa,
                  "zdobycie SBZ": jest("ZDOBYCIE SBZ"), "III strefa": jest("III STREFA")}[etykieta]
        wiersze.append((etykieta, fn(tenant) if wymaga else "–", fn(rywal) if wymaga else "–"))
    # Tagi bez drużyny: tenant liczy wprost, rywal — to, co z nich wynika.
    wiersze.append(("odbiory",       len(odb), len(strata)))
    wiersze.append(("straty",        len(strata), len(odb)))
    wiersze.append(("1x1 wygrane",   wyg(duele), len(duele) - wyg(duele)))
    return tenant, rywal, wiersze


def main(argv):
    tenant, rywal, wiersze = liczby(open(argv[1], encoding="utf-8").read())
    # Od sesji 4a HOME to KLUB-TENANT, a nie „drużyna atakująca w lewo"
    # (docs/STAN_PIVOTU.md §7.7 a). Kierunek ataku wyprowadza silnik z danych
    # i zapisuje w `meta.direction` — nagłówek nie ma prawa go zgadywać.
    print(f"{'':22} {'HOME (tenant)':>16} {'AWAY (rywal)':>16}")
    print(f"{'drużyna':22} {tenant[:16]:>16} {rywal[:16]:>16}")
    for etykieta, a, b in wiersze:
        print(f"{etykieta:22} {a!s:>16} {b!s:>16}")


if __name__ == "__main__":
    main(sys.argv)
