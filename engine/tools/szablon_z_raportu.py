#!/usr/bin/env python3
"""Gotowy raport HTML -> szablon z placeholderami.

    python3 engine/tools/szablon_z_raportu.py RAPORT.html [SZABLON.html]

Recepta na następną generację szablonu. Raporty powstają dziś ręcznie w kolejnych
wersjach (v17, v21, v23, …); ten skrypt zamienia zatwierdzony plik w szablon, którego
używa `coachanalyze.render`. Uruchamiać na wersji JUŻ ZANONIMIZOWANEJ — nazwy i herby
konkretnego klubu w szablonie to wyciek do raportu innego klienta.

ZASADA: każda podmiana ma sprawdzaną LICZBĘ wystąpień i pierwsza niezgodność przerywa
konwersję. Powód jest historyczny — przy v13 skrypt podmiany trafił w komentarz
`/* timeline */` w CSS i zniszczył szablon; plik odtwarzano z kopii. Podmiana, która
trafiła w inną liczbę miejsc, niż zakładano, jest błędem, a nie „prawie dobrze".

Liczby wystąpień są parametrem, nie stałą: przy nowej generacji raportu najpierw
uruchom skrypt, zobacz, co zgłosi, sprawdź KAŻDĄ zmianę w pliku źródłowym i dopiero
wtedy popraw oczekiwania w `PODMIANY`. Nigdy odwrotnie.
"""

import base64
import pathlib
import re
import sys

# Nazwy drużyn w pliku zanonimizowanym. Szablon rozróżnia trzy role tej samej nazwy:
# klucz dopasowania (wielkimi literami, ta sama wartość co w `DATA[].team`), nazwę
# wyświetlaną i skrót na torze osi czasu.
GOSPODARZ, RYWAL = "DRUŻYNA A", "DRUŻYNA B"
GOSPODARZ_ETYKIETA, RYWAL_ETYKIETA = "Drużyna A", "Drużyna B"

# Barwy drużyn z pliku źródłowego, razem z wersją przygaszoną. Podmieniamy całą
# deklarację naraz, bo `--hut` jest fragmentem `--hut-dim` i osobne podmiany
# musiałyby pilnować kolejności.
BARWY = (
    ("--hut:#E6A23C; --hut-dim:rgba(230,162,60,.16);",
     "--team-home:__TEAM_HOME_COLOR__; --team-home-dim:__TEAM_HOME_DIM__;"),
    ("--pog:#5CA8E0; --pog-dim:rgba(92,168,224,.16);",
     "--team-away:__TEAM_AWAY_COLOR__; --team-away-dim:__TEAM_AWAY_DIM__;"),
)

# (wzorzec, zamiennik, oczekiwana liczba wystąpień). Kolejność ma znaczenie:
# najpierw etykiety torów, potem klucz dopasowania, na końcu nazwa wyświetlana —
# inaczej wcześniejsza podmiana zjadłaby fragment późniejszej.
PODMIANY = [
    ("${{team===HUT?'{}':'{}'}}".format(GOSPODARZ, RYWAL),
     "${team===HUT?'__TEAM_HOME_SHORT__':'__TEAM_AWAY_SHORT__'}", 2),
    (GOSPODARZ, "__TEAM_HOME__", 2),
    (RYWAL, "__TEAM_AWAY__", 2),
    (GOSPODARZ_ETYKIETA, "__TEAM_HOME_LABEL__", 14),
    (RYWAL_ETYKIETA, "__TEAM_AWAY_LABEL__", 14),
    BARWY[0] + (1,),
    BARWY[1] + (1,),
    ("--hut", "--team-home", 17),
    ("--pog", "--team-away", 14),
]

# Ślady, które nie mają prawa zostać w szablonie wspólnym dla wszystkich klientów.
ZAKAZANE = ("HUTNIK", "POGOŃ", "Hutnik", "Pogoń", "DRUŻYNA", "Drużyna", "base64,", "--hut", "--pog")


def bez_zmian(powod):
    sys.exit("PRZERWANO: " + powod)


def podmien(tekst, wzorzec, zamiennik, oczekiwane, raport):
    trafienia = tekst.count(wzorzec)
    if trafienia != oczekiwane:
        bez_zmian(
            "{!r} — {} wystąpień, oczekiwano {}. Sprawdź, co zmieniło się w raporcie "
            "źródłowym, i dopiero wtedy popraw PODMIANY.".format(
                wzorzec[:60], trafienia, oczekiwane
            )
        )
    raport.append("  {:<44} {:>3}x".format(wzorzec[:44].replace("\n", " "), oczekiwane))
    return tekst.replace(wzorzec, zamiennik)


def konwertuj(html, katalog_herbow):
    raport = []

    # ---------------------------------------------------------- dane inline
    for nazwa in ("DATA", "PAL"):
        wzor = re.compile(r"const {} = .*?;\n".format(nazwa), re.S)
        if len(wzor.findall(html)) != 1:
            bez_zmian("const {} — oczekiwano dokładnie jednego wystąpienia".format(nazwa))
        html = wzor.sub("const {} = /*__{}__*/;\n".format(nazwa, nazwa), html)
        raport.append("  {:<44} {:>3}x".format("const " + nazwa, 1))

    # ---------------------------------------------------------- herby
    # Placeholderem jest CAŁY adres `data:`, nie sam ładunek. Typ MIME musi iść
    # za plikiem herbu z konfiguracji; wpisany na sztywno wyświetlałby PNG jako SVG.
    adresy = []
    for adres in re.findall(r"data:image/[\w.+-]+;base64,[A-Za-z0-9+/=]+", html):
        if adres not in adresy:
            adresy.append(adres)
    if len(adresy) != 2:
        bez_zmian("oczekiwano dwóch różnych herbów, znaleziono {}".format(len(adresy)))

    for adres, slot, plik in zip(adresy, ("HOME", "AWAY"), ("crest_home", "crest_away")):
        rozszerzenie = re.search(r"data:image/([\w.+-]+);", adres).group(1)
        rozszerzenie = {"svg+xml": "svg", "jpeg": "jpg"}.get(rozszerzenie, rozszerzenie)
        ladunek = adres.split("base64,", 1)[1]
        katalog_herbow.joinpath("{}.{}".format(plik, rozszerzenie)).write_bytes(
            base64.b64decode(ladunek)
        )
        html = podmien(html, adres, "__LOGO_{}__".format(slot), 2, raport)

    # ---------------------------------------------------------- nazwy i barwy
    for wzorzec, zamiennik, ile in PODMIANY:
        html = podmien(html, wzorzec, zamiennik, ile, raport)

    return html, raport


def main(argv):
    if not 2 <= len(argv) <= 3:
        sys.exit(__doc__)

    zrodlo = pathlib.Path(argv[1])
    # Domyślny cel liczony przez `render`, a nie sklejany tutaj — szablon jest danymi
    # pakietu i jego położenie ma być w jednym miejscu, nie w dwóch.
    sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))
    from coachanalyze import render

    cel = pathlib.Path(argv[2]) if len(argv) == 3 else pathlib.Path(render.default_template_path())

    html, raport = konwertuj(zrodlo.read_text(encoding="utf-8"), zrodlo.parent)

    zostalo = [s for s in ZAKAZANE if s in html]
    if zostalo:
        bez_zmian("w szablonie zostały ślady konkretnego klubu: " + ", ".join(zostalo))

    cel.parent.mkdir(parents=True, exist_ok=True)
    cel.write_text(html, encoding="utf-8")

    print("\n".join(raport))
    print("\nzapisano {} ({} bajtów)".format(cel, len(html.encode("utf-8"))))
    print("znaczniki:", ", ".join(sorted(set(re.findall(r"__[A-Z][A-Z0-9_]*__", html)))))
    print("herby wypakowane obok raportu źródłowego:", zrodlo.parent)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
