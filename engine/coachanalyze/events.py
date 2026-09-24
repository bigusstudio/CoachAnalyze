"""Ramka -> wiersze tabeli `events`. Po SUROWYCH nazwach tagów, bez modelu kanonicznego.

═══════════════════════════════════════════════════════════════════════════════
DLACZEGO TO NIE JEST `canon.py` W INNYM OPAKOWANIU.

`canon.py` tłumaczy tagi klienta na pojęcia (`shot`, `entry_sbz`) i to jest jego
sens: archiwum i porównania sezonowe muszą mówić jednym językiem niezależnie od
tego, jak klub nazywa zdarzenia. Ta warstwa jest uśpiona (docs/STAN_PIVOTU.md §2.1).

Ten moduł robi coś innego i celowo prostszego: zapisuje to, co JEST W EKSPORCIE,
pod nazwą, którą wpisał analityk. Tak samo liczy szablon raportu w przeglądarce
(`e.tag==='STRZAŁ'`), więc tabela i raport odpowiadają na pytania tą samą miarą.
Mapowanie kanoniczne jest tu ZAKAZANE — dołożone, rozjechałoby tabelę z raportem
i nikt by tego nie zauważył, bo obie liczby wyglądałyby sensownie.

WEJŚCIEM JEST RAMKA RENDERU (D4), nie surowy CSV. Ten sam obiekt, z którego
powstaje HTML, daje wiersze do bazy — inaczej raport i tabela mogłyby pokazać
inne liczby dla tego samego meczu.
═══════════════════════════════════════════════════════════════════════════════
"""

import math

from .canon import build_team_lookup, _norm_team

# Tagi, na których stoi atrybucja gola. SUROWE NAZWY — patrz nagłówek modułu.
TAG_GOL = "Gol"
TAG_STRZAL = "STRZAŁ"

# Minuta doliczonego czasu pierwszej połowy. Zdarzenie z 47. minuty nagrania,
# które padło przed przerwą, jest zdarzeniem z 45. minuty meczu.
KONIEC_PIERWSZEJ_POLOWY = 45

# Ile sekund od gola wolno szukać strzału. Bufor taga bywa kilkusekundowy,
# ale dwie akcje w tej samej minucie to już osobne zdarzenia.
OKNO_GOLA_S = 30.0


def minuta(sekundy, half):
    """Minuta meczu. `ceil(t/60)`, pierwsza połowa przycięta do 45.

    PRZYCIĘCIE DOTYCZY WYŁĄCZNIE PIERWSZEJ POŁOWY i jest świadome: czas
    w eksporcie to czas WIDEO (pułapka 8), więc doliczony czas pierwszej połowy
    rośnie dalej, a przerwa nie zeruje licznika. Bez przycięcia zdarzenie sprzed
    przerwy trafiałoby do 47. minuty, a zdarzenie tuż po przerwie do 49. —
    i oś czasu pokazywałaby przerwę jako dwie minuty gry.

    Druga połowa zostaje BEZ przycięcia: to ta sama skala czasu wideo, a górnej
    granicy meczu nie znamy (doliczony czas bywa różny).

    MINUTA ZACZYNA SIĘ OD 1, NIE OD 0 — poprawka z odbioru sesji 2.
    `ceil(0/60)` daje zero, a zdarzeń w sekundzie 0 jest w eksportach sporo:
    pułapka 10 przycina ujemny `begin` (bufor taga) właśnie do zera, więc każdy
    tag wstawiony przed pierwszym gwizdkiem lądował w „0. minucie". Takiej
    minuty nie ma ani w meczu, ani na osi czasu raportu.
    """
    if sekundy is None:
        return None
    m = max(1, math.ceil(float(sekundy) / 60.0))
    if half == 1:
        return min(m, KONIEC_PIERWSZEJ_POLOWY)
    return m


def atrybucja_goli(events):
    """(indeksy strzałów będących golem, {indeks gola: drużyna ze strzału}).

    ═══════════════════════════════════════════════════════════════════════════
    `team_uuid` PRZY GOLU W EKSPORCIE BYWA BŁĘDNY — to nie jest podejrzenie,
    tylko obserwacja z danych klienta. Dlatego drużyny gola NIE bierzemy z jego
    własnego wiersza, tylko ze STRZAŁU najbliższego w czasie.
    ═══════════════════════════════════════════════════════════════════════════

    Szablon raportu robi dokładnie to samo, w JS, przy wczytaniu danych. Ta
    funkcja jest jego odpowiednikiem po stronie silnika i musi dawać ten sam
    wynik — inaczej raport i tabela rozjadą się na wyniku meczu, czyli na
    jedynej liczbie, którą każdy sprawdza najpierw.

    Wynik: strzał dostaje `is_goal = 1`, a WIERSZ GOLA ZOSTAJE, ze skorygowaną
    drużyną. Nie sklejamy ich w jedno zdarzenie: gol jest osobnym tagiem
    analityka i skasowanie go zmieniłoby sumę zdarzeń w meczu.

    Gol bez żadnego strzału w oknie zostaje z drużyną z własnego wiersza —
    zgadywanie na podstawie strzału sprzed trzech minut byłoby gorsze niż
    wartość, którą i tak mamy.
    """
    strzaly = [i for i, e in enumerate(events) if e.get("tag") == TAG_STRZAL
               and e.get("b") is not None]
    gole_indeksy = [i for i, e in enumerate(events) if e.get("tag") == TAG_GOL]

    strzaly_z_golem = set()
    druzyna_gola = {}

    for idx in gole_indeksy:
        czas = events[idx].get("b")
        if czas is None or not strzaly:
            continue

        najblizszy = min(strzaly, key=lambda s: abs(events[s]["b"] - czas))
        if abs(events[najblizszy]["b"] - czas) > OKNO_GOLA_S:
            continue

        strzaly_z_golem.add(najblizszy)
        team = events[najblizszy].get("team")
        if team is not None:
            druzyna_gola[idx] = team

    return strzaly_z_golem, druzyna_gola


def strona_druzyny(raw_team, lookup):
    """`us` / `them` / `none` dla surowej nazwy drużyny z eksportu.

    WIERSZ BEZ DRUŻYNY TO `none`, NIE ZGADYWANIE. Pułapka 5: kolumna `team` jest
    wypełniona wybiórczo — w eksporcie referencyjnym 196 zdarzeń z 294 jej nie ma.
    To nie jest odpad, tylko większość pojedynków, strat i odbiorów.

    Nazwa, której konfiguracja nie zna, też daje `none`. Perspektywę klubu-tenanta
    („kto jest nasz") interpretuje SZABLON, nie silnik — tutaj zapisujemy stronę
    meczu, a nie punkt widzenia.
    """
    if raw_team is None or str(raw_team).strip() == "":
        return "none"
    return lookup.get(_norm_team(raw_team), "none")


def build(frame, config=None, players=None):
    """Wiersze do tabeli `events`. Kolejność jak w eksporcie.

    `xg_source` to `analyst` przy każdym xG, bo ramka renderu niesie wyłącznie
    wartości odczytane z komentarza analityka (pułapka 1). xG z modelu (M3) żyje
    w warstwie kanonicznej, która jest uśpiona — gdyby kiedyś wróciło, to pole
    ma już swoje miejsce i nie trzeba będzie migracji.

    ZDARZENIE BEZ CZASU JEST POMIJANE i policzone osobno. Kolumna `t_ms` jest
    `NOT NULL`, a zera nie podstawiamy: zero to konkretna 0. sekunda i nie da się
    jej odróżnić od braku danych (CLAUDE.md §8). Takich wierszy w eksportach
    referencyjnych nie ma — gdyby się pojawiły, licznik powie o tym głośno.
    """
    events = frame.get("events") or []
    teams = (config or {}).get("teams")
    markery = ((config or {}).get("team_us_rule") or {}).get("markers")
    lookup = build_team_lookup(teams, markery)

    strzaly_z_golem, druzyna_gola = atrybucja_goli(events)
    players = players or frame.get("players") or []

    wiersze = []
    bez_czasu = 0

    for i, e in enumerate(events):
        czas = e.get("b")
        if czas is None:
            bez_czasu += 1
            continue

        # Drużyna gola ze strzału — patrz `atrybucja_goli`.
        raw_team = druzyna_gola.get(i, e.get("team"))
        koniec = e.get("e")
        zawodnik = players[i] if i < len(players) else None
        xg = e.get("xg")

        wiersze.append({
            "tag_name": e.get("tag"),
            "labels": list(e.get("labels") or []),
            "team": raw_team,
            "team_side": strona_druzyny(raw_team, lookup),
            "player": zawodnik or None,
            "t_ms": int(round(float(czas) * 1000)),
            "t_end_ms": int(round(float(koniec) * 1000)) if koniec is not None else None,
            "half": int(e.get("half") or 1),
            "minute": minuta(czas, int(e.get("half") or 1)),
            "xg": xg,
            "xg_source": "analyst" if xg is not None else None,
            "x": e.get("x"),
            "y": e.get("y"),
            "tx": e.get("tx"),
            "ty": e.get("ty"),
            "is_goal": 1 if i in strzaly_z_golem else 0,
        })

    return {"events": wiersze, "skipped_no_time": bez_czasu}
