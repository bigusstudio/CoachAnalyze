"""Kierunek ataku każdej drużyny — WYPROWADZONY Z DANYCH, nigdy z konfiguracji.

═══════════════════════════════════════════════════════════════════════════════
DLACZEGO TO NIE JEST POLE W `config.json`.

Kierunek ataku już stoi w eksporcie — w medianie współrzędnej `x` strzałów. Pole
w konfiguracji byłoby pytaniem o coś, co dane niosą, czyli kolejną wartością
do pomylenia przy imporcie; a pomyłka nie miałaby jak wyjść na jaw, bo raport
wyglądałby normalnie, tylko lustrzanie.

Wyprowadzenie ma też własność, której konfiguracja mieć nie może: przy eksporcie
bez pozycji po prostu NIE DAJE ODPOWIEDZI, zamiast dać błędną (docs/STAN_PIVOTU.md
§7.7 b).

RAZ NA MECZ, NIE PER POŁOWA. Zweryfikowane na eksporcie JDRZ 2026-09-24: druga
połowa wygląda w danych jak pierwsza (Pogoń 92,5 / 90,6; JDRZ 27,7 / 12,5).
Współrzędne w eksporcie LiveTag są już znormalizowane kierunkowo (pułapka 2
z CLAUDE.md), więc drużyny NIE zmieniają w nich stron po przerwie — i nie wolno
ich lustrzyć „bo połowa druga".
═══════════════════════════════════════════════════════════════════════════════

ODBICIA NIE ROBIMY PRZY ZMIANIE STRON. Gdy drużyny zmieniają połowy po przerwie,
mecz nie ma JEDNEGO kierunku, do którego dałoby się go sprowadzić — a odbicie
oparte na medianie mieszającej dwa przeciwne rozkłady przestawiłoby mapy bez
żadnego powodu. Zgłaszamy `KIERUNEK_ZMIANA_POLOWY` i zostawiamy plik, jaki jest.

Odbicie (`odbij_ramke`) to JEDYNE miejsce w silniku, w którym wolno tknąć
współrzędne. Pułapka 2 zakazuje lustrzenia „z góry"; tutaj odbicie jest
wyprowadzone z danych i odnotowane w `meta.mirrored`, więc da się je sprawdzić
i cofnąć.
"""

import statistics

from .events import strona_druzyny

# Wymiary boiska w metrach. Skala eksportu LiveTag: 1 m = 10 px w renderze.
DLUGOSC_BOISKA_M = 105.0
SRODEK_BOISKA_M = DLUGOSC_BOISKA_M / 2  # 52,5

# Ile zdarzeń musi mieć miara, żeby mediana coś znaczyła.
#
# Trzy, nie jedno: pojedynczy strzał z własnej połowy bywa i nie może przesądzać
# o kierunku całego meczu. Trzy to najmniejsza próbka, w której mediana przestaje
# być pojedynczą obserwacją — a mecz bez trzech strzałów drużyny i tak nie ma
# czego pokazać na mapie.
MIN_PROBKA = 3

# Pojęcia kanoniczne, z których czytamy kierunek. NIE NAZWY TAGÓW: klub, który
# taguje „ZDOBYCIE SBZ NASZA", ma to zmapowane w profilu i alias przychodzi stąd
# sam. Wpisana na sztywno lista nazw rozjechałaby się z profilem przy pierwszym
# kliencie z własnym słownikiem.
POJECIE_STRZAL = "shot"
POJECIE_SBZ = "entry_sbz"
POJECIE_III = "entry_third"

# Nazwy domyślne — te, którymi taguje klient. Wchodzą do zestawu ZAWSZE, obok
# nazw wyprowadzonych z profilu.
#
# POWÓD JEST TEN SAM, CO W SESJI 1b: templat klubu ma dziś zwykle `canon: null`
# przy większości zmiennych (docs/STAN_PIVOTU.md §2.3), więc profil nie zwróciłby
# ANI JEDNEJ nazwy dla `shot` i kierunek przestałby się liczyć — cicho, bo brak
# kierunku jest stanem dopuszczalnym. Kierunek to geometria, nie metryka: „czy
# to jest strzał w sensie kanonicznym" nie ma tu znaczenia, liczy się pozycja.
NAZWY_DOMYSLNE = {
    POJECIE_STRZAL: ("STRZAŁ",),
    POJECIE_SBZ: ("ZDOBYCIE SBZ",),
    POJECIE_III: ("III STREFA",),
}

PRZECIWNY = {"right": "left", "left": "right"}

# Kod ostrzeżenia o eksporcie, w którym drużyny ZMIENIAJĄ STRONY po przerwie.
KOD_ZMIANA_POLOWY = "KIERUNEK_ZMIANA_POLOWY"


def tagi_dla_pojec(tag_rules):
    """{pojęcie: {surowe nazwy tagów}} z rozwiązanego profilu mapowań.

    Wejściem jest `canon.resolve_profile(...)["tags"]`, czyli profil domyślny
    nadpisany regułami klubu. Aliasy („ZDOBYCIE SBZ NASZA") przychodzą więc
    z tego samego miejsca, z którego bierze je model kanoniczny — i nie ma
    drugiej listy, która mogłaby się z nim rozjechać.
    """
    out = {pojecie: set(nazwy) for pojecie, nazwy in NAZWY_DOMYSLNE.items()}
    for nazwa, regula in (tag_rules or {}).items():
        concept = (regula or {}).get("concept")
        if concept in out:
            out[concept].add(nazwa)
    return out


def _mediana(wartosci):
    """Mediana albo `None` przy próbce mniejszej niż `MIN_PROBKA`."""
    dane = [w for w in wartosci if w is not None]
    if len(dane) < MIN_PROBKA:
        return None
    return round(statistics.median(dane), 2)


def _strona_z_pozycji(mediana):
    """Mediana `x` -> `right` / `left`. Dokładny środek to brak odpowiedzi."""
    if mediana is None or mediana == SRODEK_BOISKA_M:
        return None
    return "right" if mediana > SRODEK_BOISKA_M else "left"


def _strona_ze_zwrotu(mediana_dx):
    """Mediana `Δx` podania -> `right` / `left`. Zero to brak odpowiedzi."""
    if mediana_dx is None or mediana_dx == 0:
        return None
    return "right" if mediana_dx > 0 else "left"


def _kierunek_polowy(events, tagi, lookup, side, half):
    """Kierunek jednej drużyny w jednej połowie — WYŁĄCZNIE ze strzałów.

    Bez miar kontrolnych i bez przeciwieństwa drugiej drużyny: to jest test
    na ZMIANĘ STRON, a nie kolejne źródło kierunku meczu. Odpowiedź ma być albo
    twarda, albo żadna — inaczej ostrzeżenie zapalałoby się od pojedynczego
    strzału z własnej połowy w słabiej otagowanej połowie meczu.
    """
    x = [
        e.get("x") for e in events
        if strona_druzyny(e.get("team"), lookup) == side
        and e.get("tag") in tagi[POJECIE_STRZAL]
        and int(e.get("half") or 1) == half
    ]
    return _strona_z_pozycji(_mediana(x))


def _zmiana_stron(events, tagi, lookup):
    """(czy zmiana stron, {strona: {połowa: kierunek}}).

    ═══════════════════════════════════════════════════════════════════════════
    ZGŁASZAMY, NIE NAPRAWIAMY.

    Współrzędne w eksporcie LiveTag są znormalizowane kierunkowo (pułapka 2)
    i tak wyglądały wszystkie eksporty, które widzieliśmy — kierunek liczy się
    raz na mecz. Gdyby jednak trafił się plik bez normalizacji, mapy II połowy
    byłyby odbite względem I, a liczby dalej poprawne (pozycja nie jest im do
    niczego potrzebna).

    Odbicia per połowa NIE ROBIMY automatycznie. Pułapka 2 zakazuje lustrzenia
    „bo połowa druga", a odbicie oparte na medianie kilku strzałów w jednej
    połowie jest dokładnie tym: regułą, która przy słabo otagowanej połowie
    odwróci mapę bez powodu. Mówimy więc, co widać, i zostawiamy decyzję.
    ═══════════════════════════════════════════════════════════════════════════
    """
    polowy = {}
    zmiana = False
    for side in ("us", "them"):
        wynik = {
            str(half): _kierunek_polowy(events, tagi, lookup, side, half)
            for half in (1, 2)
        }
        polowy[side] = wynik
        pierwsza, druga = wynik["1"], wynik["2"]
        if pierwsza is not None and druga is not None and pierwsza != druga:
            zmiana = True
    return zmiana, polowy


def _dowody_strony(events, tagi, lookup, side):
    """Trzy miary dla jednej drużyny: strzały, wejścia w SBZ, zwrot podań w III strefę.

    Każda miara niesie LICZBĘ ZDARZEŃ obok mediany. „Mediana 92,5" i „mediana 92,5
    z dwóch strzałów" to dwa różne zdania, a raport pokrycia ma pokazywać drugie.

    Przypisanie drużyny robi `events.strona_druzyny`, czyli DOKŁADNIE ta sama
    funkcja, co przy zapisie tabeli `events`. Druga implementacja tego samego
    dopasowania rozjechałaby kierunek z danymi, na których go policzono.
    """
    nasze = [e for e in events if strona_druzyny(e.get("team"), lookup) == side]

    strzaly = [e.get("x") for e in nasze if e.get("tag") in tagi[POJECIE_STRZAL]]
    sbz = [e.get("tx") for e in nasze if e.get("tag") in tagi[POJECIE_SBZ]]
    # ZWROT WEJŚCIA W III STREFĘ TO `x - tx`, NIE `tx - x`. Sprawdzone na eksporcie
    # referencyjnym: pozycja taga jest miejscem OTRZYMUJĄCEGO — w zdobytej strefie —
    # a `pos_target_*` pozycją PODAJĄCEGO, czyli dalej od bramki rywala. Stąd alias
    # „III STREFA PODAJĄCY/OTRZYMUJĄCY" w słowniku szablonu.
    #
    # Liczby z mecz2.csv: drużyna strzelająca po lewej (mediana x strzałów 13,3)
    # ma wejścia 33,9 -> 64,4, a druga 70,1 -> 34,3. Wektor `tx - x` wskazywałby
    # więc obu drużynom stronę PRZECIWNĄ do tej, w którą strzelają.
    trzecia = [
        e["x"] - e["tx"]
        for e in nasze
        if e.get("tag") in tagi[POJECIE_III]
        and e.get("tx") is not None and e.get("x") is not None
    ]

    return {
        "shots": {"n": len([w for w in strzaly if w is not None]), "median_x": _mediana(strzaly)},
        "entry_sbz": {"n": len([w for w in sbz if w is not None]), "median_tx": _mediana(sbz)},
        "third_dx": {"n": len(trzecia), "median_dx": _mediana(trzecia)},
    }


def _z_dowodow(dowody):
    """(kierunek, źródło, sprzeczne[]) dla jednej drużyny.

    ROZSTRZYGAJĄ STRZAŁY. Pozostałe dwie miary są kontrolne: potwierdzają albo
    zgłaszają sprzeczność, ale nie przegłosowują strzałów. Powód jest w danych —
    strzał ma pozycję oddania, czyli miejsce przy bramce rywala; wejście w SBZ
    bywa tagowane z pozycją podającego, a podanie w III strefę ma zwrot, który
    przy cofnięciu piłki potrafi wyjść przeciwny.

    Bez strzałów schodzimy na kontrolne — w kolejności, w jakiej im ufamy —
    i mówimy o tym wprost przez `źródło`, żeby `confidence` nie udawało pewności.
    """
    rozstrzygajace = (
        ("shots", _strona_z_pozycji(dowody["shots"]["median_x"])),
        ("entry_sbz", _strona_z_pozycji(dowody["entry_sbz"]["median_tx"])),
    )
    odpowiedzi = [(zrodlo, kier) for zrodlo, kier in rozstrzygajace if kier is not None]
    if not odpowiedzi:
        return None, None, []

    zrodlo, kierunek = odpowiedzi[0]
    sprzeczne = [z for z, k in odpowiedzi[1:] if k != kierunek]

    # ZWROT PODAŃ W III STREFĘ TYLKO POTWIERDZA — nigdy nie rozstrzyga, nawet gdy
    # jest jedyną miarą, jaką mamy. Dwie pozostałe czytają POZYCJĘ (gdzie padł
    # strzał, gdzie skończyło się wejście), a ta czyta ZWROT WEKTORA, którego
    # konwencja zależy od tego, czy analityk stawia tag na podającym, czy na
    # otrzymującym. Pomyłka w pozycji przesuwa medianę; pomyłka w konwencji
    # wektora odwraca odpowiedź — i to bez żadnego śladu.
    z_wektora = _strona_ze_zwrotu(dowody["third_dx"]["median_dx"])
    if z_wektora is not None and z_wektora != kierunek:
        sprzeczne.append("third_dx")
    return kierunek, zrodlo, sprzeczne


def wykryj(frame, tag_rules=None, lookup=None):
    """Kierunek ataku obu drużyn z ramki renderu.

    Zwraca kształt idący wprost do `meta.direction`:

        {"us": "right"|"left"|None, "them": …, "confidence": "high"|"low"|"none",
         "evidence": {...}, "conflicts": [...], "halves": {...}, "warnings": [...]}

    `confidence`:
      - `high`  — kierunek ze strzałów, kontrole milczą albo potwierdzają,
      - `low`   — kierunek z miary kontrolnej, sprzeczność między miarami
                  albo strony wyszły takie same dla obu drużyn,
      - `none`  — danych nie ma (eksport bez pozycji) i nie zgadujemy.

    STRONA NIEZNANEJ DRUŻYNY WYCHODZI Z PRZECIWIEŃSTWA, nie z pustego miejsca:
    drużyny atakują przeciwne bramki i to jest fakt o meczu, a nie założenie.
    Gdy obie miary dadzą tę samą stronę, wygrywa ta z twardszym źródłem, a druga
    dostaje przeciwną — z odnotowaną sprzecznością.
    """
    events = frame.get("events") or []
    tagi = tagi_dla_pojec(tag_rules)
    lookup = lookup or {}

    dowody = {side: _dowody_strony(events, tagi, lookup, side) for side in ("us", "them")}
    wynik = {side: _z_dowodow(dowody[side]) for side in ("us", "them")}

    kier = {side: wynik[side][0] for side in ("us", "them")}
    zrodla = {side: wynik[side][1] for side in ("us", "them")}
    sprzecznosci = [
        "{}:{}".format(side, ",".join(wynik[side][2]))
        for side in ("us", "them") if wynik[side][2]
    ]

    # Obie drużyny po tej samej stronie — jedna z miar kłamie. Zostaje ta, która
    # oparła się o strzały; druga dostaje przeciwną stronę i idzie ostrzeżenie.
    if kier["us"] is not None and kier["us"] == kier["them"]:
        przegrany = "them" if zrodla["us"] == "shots" and zrodla["them"] != "shots" else "us"
        if zrodla["us"] == "shots" and zrodla["them"] == "shots":
            # Oba ze strzałów: mecz, w którym jedna drużyna strzelała wyłącznie
            # z dystansu, potrafi tak wyjść. Nie rozstrzygamy po cichu — mówimy.
            przegrany = "them"
        kier[przegrany] = PRZECIWNY[kier[przegrany]]
        sprzecznosci.append("obie_strony_te_same")

    # Drużyna bez własnych danych bierze przeciwieństwo drugiej.
    for side, druga in (("us", "them"), ("them", "us")):
        if kier[side] is None and kier[druga] is not None:
            kier[side] = PRZECIWNY[kier[druga]]

    zmiana, polowy = _zmiana_stron(events, tagi, lookup)

    if kier["us"] is None:
        pewnosc = "none"
    elif zmiana or sprzecznosci or zrodla["us"] != "shots":
        # ZMIANA STRON ODBIERA WIARĘ CAŁEMU MECZOWI, nie tylko drugiej połowie:
        # mediana liczona przez obie połowy miesza dwa przeciwne rozkłady
        # i ląduje koło środka boiska, więc odpowiedź jest wtedy rzutem monetą.
        pewnosc = "low"
    else:
        pewnosc = "high"

    return {
        "us": kier["us"],
        "them": kier["them"],
        "confidence": pewnosc,
        "evidence": dowody,
        "conflicts": sprzecznosci,
        # Kierunek KAŻDEJ DRUŻYNY W KAŻDEJ POŁOWIE, liczony osobno ze strzałów.
        # Służy jednej rzeczy: wykryciu eksportu bez normalizacji stron.
        "halves": polowy,
        # Kody ostrzeżeń, które z tego wynikają. Lista, a nie flaga: kolejne
        # obserwacje o kierunku będą miały gdzie dojść bez zmiany kształtu.
        "warnings": [KOD_ZMIANA_POLOWY] if zmiana else [],
    }


def _odbij(wartosc):
    """`x` -> `105 - x`. `None` zostaje `None` — brak pozycji nie jest zerem."""
    if wartosc is None:
        return None
    return round(DLUGOSC_BOISKA_M - float(wartosc), 2)


def odbij_ramke(frame):
    """Ramka z odbitymi `x` i `tx` OBU DRUŻYN. `y` nietknięte.

    ODBIJAMY OBIE DRUŻYNY RAZEM. Lustrzenie jednej rozjechałoby mecz na dwa
    układy współrzędnych, w których to samo podanie ma dwa różne zwroty.

    `y` zostaje: zamieniamy strony boiska, nie skrzydła. Odbicie `y` przeniosłoby
    akcje z prawego skrzydła na lewe i odwrotnie — a to już nie jest ta sama gra.

    Zwraca NOWĄ ramkę; wejściowa zostaje nietknięta, bo model kanoniczny i metryki
    liczą się z oryginalnych współrzędnych.
    """
    events = [
        dict(e, x=_odbij(e.get("x")), tx=_odbij(e.get("tx")))
        for e in (frame.get("events") or [])
    ]
    return dict(frame, events=events)
