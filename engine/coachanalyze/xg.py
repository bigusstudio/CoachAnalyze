"""Model xG — regresja logistyczna ze współczynnikami z literatury (moduł M3).

WYŁĄCZNIE biblioteka standardowa (`math`) — hosting z noexec nie załaduje
żadnego rozszerzenia w C (docs/OGRANICZENIA_HOSTINGU.md).

ZASTRZEŻENIE O KALIBRACJI — obowiązkowe przy każdej prezentacji wartości:
współczynniki pochodzą z modeli trenowanych na ligach zachodnich (StatsBomb,
Big 5, ~43 tys. strzałów, skuteczność ~10,8%), a nie na poziomie rozgrywkowym
klienta. Wartości czytać PORÓWNAWCZO (mecz do meczu), nie bezwzględnie.
To samo zastrzeżenie niesie hasło indeksu współczynników (M1), adnotacja
w raporcie i docs/MODEL_KANONICZNY.md.

OSOBNE MODELE DLA RODZAJÓW STRZAŁÓW — to jest istotne, nie ozdobnik:
  - gra otwarta nogą,
  - gra otwarta główką (kara za główkę z literatury: −1,2946),
  - rzut wolny bezpośredni (własne współczynniki odległości i kąta),
  - rzut karny: WARTOŚĆ STAŁA — wszystkie cechy każdego karnego są identyczne,
    więc „liczenie" z odległości dawałoby pozór precyzji bez treści.

Geometria: współrzędne eksportu w metrach, znormalizowane kierunkowo
(pułapka 2 — atak zawsze w stronę wysokich x). Boisko 105 × 68 m, bramka
na linii x = 105, środek y = 34, słupki w y = 34 ± 3,66.

PRZYSZŁA KALIBRACJA: wynik każdego strzału (gol / celny / niecelny /
zablokowany) jest już zapisywany w `events_canonical` jako kwalifikatory
(`goal`, `on_target`, `off_target`, `blocked`) — żadna migracja nie jest
potrzebna, żeby za sezon przejść na współczynniki z własnych danych.
"""

import math

PITCH_LENGTH = 105.0
PITCH_WIDTH = 68.0
GOAL_X = PITCH_LENGTH
GOAL_CENTER_Y = PITCH_WIDTH / 2
GOAL_HALF_WIDTH = 7.32 / 2

# Skuteczność rzutów karnych — stała z literatury, nie funkcja pozycji.
PENALTY_XG = 0.76

# Zaokrąglenie jak u analityka (dwa miejsca, np. „X 0,81") — wartość modelu
# nie może wyglądać na dokładniejszą niż wartości wpisywane ręcznie.
ROUND_DIGITS = 2

"""
Współczynniki referencyjne z literatury — PUNKT WYJŚCIA, nie prawda objawiona.

Cechy:
  distance — odległość do środka bramki, w metrach,
  angle    — kąt widzenia bramki: kąt między wektorami do prawego i lewego
             słupka (atan2), w radianach,
  start_x  — odległość od linii bramkowej wzdłuż osi ataku, w DZIESIĄTKACH
             metrów: (105 − x) / 10. Jednostka wybrana tak, by współczynnik
             −0,1290 z literatury zachował skalę.

Wyrazy wolne (intercept) są DOKALIBROWANE do skuteczności referencyjnej
~10,8% dla typowego strzału — literatura podaje wagi cech bez wyrazów
wolnych porównywalnych między implementacjami. Przy przejściu na własne
dane (patrz nagłówek) wszystkie liczby w tej tabeli podlegają wymianie.
"""
MODELS = {
    "open_foot": {
        "intercept": 3.07,
        "distance": -0.3135,
        "angle": 0.0910,
        "start_x": -0.1290,
    },
    # Gra otwarta główką: wagi jak nogą + kara za część ciała z literatury
    # (bodypart_head = −1,2946) wliczona w wyraz wolny osobnego modelu.
    "open_head": {
        "intercept": 3.07 - 1.2946,
        "distance": -0.3135,
        "angle": 0.0910,
        "start_x": -0.1290,
    },
    # Rzut wolny bezpośredni: odległość i kąt zmienne, własne współczynniki.
    # Mur i ustawiona obrona spłaszczają zależność od odległości.
    "free_kick": {
        "intercept": -1.10,
        "distance": -0.0800,
        "angle": 0.5000,
        "start_x": 0.0,
    },
}


def distance_to_goal(x, y):
    return math.hypot(GOAL_X - x, GOAL_CENTER_Y - y)


def goal_angle(x, y):
    """Kąt widzenia bramki: między wektorami do obu słupków (atan2), radiany.

    Na linii bramkowej i za nią kąt liczymy z minimalnego cofnięcia — strzał
    z x >= 105 to artefakt danych, nie pozycja, ale wartość ma być skończona.
    """
    dx = max(GOAL_X - x, 0.1)
    a1 = math.atan2(GOAL_CENTER_Y + GOAL_HALF_WIDTH - y, dx)
    a2 = math.atan2(GOAL_CENTER_Y - GOAL_HALF_WIDTH - y, dx)
    return abs(a1 - a2)


def _logistic(z):
    if z < -30:
        return 0.0
    if z > 30:
        return 1.0
    return 1.0 / (1.0 + math.exp(-z))


def classify(qualifiers):
    """(rodzaj strzału, założenie_przyjęte) z kwalifikatorów zdarzenia.

    Rozpoznajemy po kwalifikatorach kanonicznych, nie po nazwach tagów —
    słowniki tagów są klubowe, kwalifikatory wspólne. `penalty`, `free_kick`
    i `header` nie występują dziś w słowniku domyślnym, ale profil klubu może
    je nadać (kreator mapowań) — rozpoznajemy je z wyprzedzeniem.

    `set_piece` bez doprecyzowania traktujemy jak rzut wolny bezpośredni.
    Gdy nic nie pasuje: model gry otwartej nogą, z ODNOTOWANIEM ZAŁOŻENIA
    (drugi element krotki) — założenie ma być widoczne, nie domyślne.
    """
    q = set(qualifiers or [])
    if "penalty" in q:
        return "penalty", False
    if "free_kick" in q or "set_piece" in q:
        return "free_kick", False
    if "header" in q or "head" in q:
        return "open_head", False
    if "open_play" in q or "positional" in q or "counter" in q or "after_press" in q:
        return "open_foot", False
    return "open_foot", True


def estimate(x, y, qualifiers=None):
    """Wartość xG dla strzału z pozycji (x, y) w metrach.

    Zwraca {"xg", "model", "assumed"} albo None, gdy brak współrzędnych —
    braku danych nie zastępujemy zmyśloną liczbą (CLAUDE.md §8).
    """
    if x is None or y is None:
        return None

    shot_type, assumed = classify(qualifiers)

    if shot_type == "penalty":
        return {"xg": PENALTY_XG, "model": "penalty", "assumed": assumed}

    coeffs = MODELS[shot_type]
    z = (
        coeffs["intercept"]
        + coeffs["distance"] * distance_to_goal(x, y)
        + coeffs["angle"] * goal_angle(x, y)
        + coeffs["start_x"] * ((GOAL_X - x) / 10.0)
    )
    return {
        "xg": round(_logistic(z), ROUND_DIGITS),
        "model": shot_type,
        "assumed": assumed,
    }


def grid(step=1.0):
    """Siatka wartości xG dla całego boiska — artefakt dla warstwy PHP.

    Interaktywne boisko w panelu działa BEZ skryptu i BEZ liczenia w PHP
    (CLAUDE.md §4, §9): kliknięcie wysyła współrzędne zwykłym formularzem,
    a PHP ODCZYTUJE wartość z tej siatki — tak samo, jak czyta coverage_json.
    Liczy silnik, raz, tutaj.

    Wartość komórki = xG dla jej ŚRODKA. Kolejność wierszy: y od 0, w wierszu
    x od 0 — deterministyczna, bo plik podlega porównaniu w testach.
    """
    cols = int(PITCH_LENGTH / step)
    rows = int(PITCH_WIDTH / step)

    models = {}
    for name in sorted(MODELS):
        rows_out = []
        for iy in range(rows):
            y = (iy + 0.5) * step
            rows_out.append([
                estimate((ix + 0.5) * step, y, [_QUALIFIER_FOR[name]])["xg"]
                for ix in range(cols)
            ])
        models[name] = rows_out

    return {
        "length": PITCH_LENGTH,
        "width": PITCH_WIDTH,
        "step": step,
        "penalty": PENALTY_XG,
        "models": models,
    }


# Kwalifikator wymuszający dany model przy generowaniu siatki.
_QUALIFIER_FOR = {
    "open_foot": "open_play",
    "open_head": "header",
    "free_kick": "free_kick",
}
