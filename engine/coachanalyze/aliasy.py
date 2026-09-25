"""Aliasy zmiennych — JEDEN słownik dla silnika i dla szablonu.

═══════════════════════════════════════════════════════════════════════════════
PO CO TEN MODUŁ ISTNIEJE — USTERKA Z ODBIORU NA SERWERZE.

Eksport JDRZ taguje wejścia w SBZ jako `SBZ PODAJĄCY`. Szablon v21 znał ten alias
w swoim słowniku `VARS`, więc sekcja Przegląd pokazywała **11:20**. Silnik go NIE
znał, więc `coverage` liczył zero wejść w SBZ i wycinał całą oś SBZ z powodem
„Eksport nie zawiera zdarzeń zdobycia SBZ".

Jeden raport, dwie odpowiedzi na to samo pytanie — i obie wyglądały sensownie.
═══════════════════════════════════════════════════════════════════════════════

Odtąd lista jest w JEDNYM miejscu (`config/aliasy.json`) i czytają ją wszyscy:

- `canon.resolve_profile` — alias dostaje POJĘCIE nazwy głównej, więc liczniki
  pokrycia i metryki widzą go tak samo jak ją,
- `coverage` — przez profil, bez własnej ścieżki,
- `render.vars_slot` — wstrzyknięcie do `VARS` szablonu jako wartości DOMYŚLNE.

TEMPLAT KLUBU NADPISUJE (sesja 5, `variables[].aliases`). Domyślne są punktem
wyjścia dla klubu, który nic nie ustawił — a nie regułą narzuconą wszystkim.
"""

import json
import os

PLIK = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config", "aliasy.json")

# Wynik czytamy raz: plik jest danymi pakietu i nie zmienia się w trakcie biegu.
_PAMIEC = None


def domyslne():
    """{nazwa główna: [aliasy]} z `config/aliasy.json`. Klucze opisowe pomijamy.

    Plik jest DANYMI PAKIETU, jak szablon i progi: ścieżka liczona względem
    katalogu pakietu, bo po instalacji silnik startuje spoza repozytorium.

    Nieczytelny plik NIE PRZERYWA pracy i to jest różnica wobec progów faktów.
    Próg wpisany do raportu decyduje o kolorze liczby — brak pliku znaczyłby
    liczby ocenione nie wiadomo czym. Alias, którego zabrakło, daje raport
    uboższy, ale prawdziwy: zdarzenia zostają pod swoją nazwą z eksportu.
    """
    global _PAMIEC
    if _PAMIEC is None:
        try:
            with open(PLIK, encoding="utf-8") as fh:
                dane = json.load(fh)
        except (OSError, ValueError):
            dane = {}
        _PAMIEC = {
            str(nazwa): [str(a) for a in wartosc if str(a).strip()]
            for nazwa, wartosc in dane.items()
            if not str(nazwa).startswith("_") and isinstance(wartosc, list)
        }
    return _PAMIEC


def odwrotne():
    """{alias: nazwa główna}. Dopasowanie WYŁĄCZNIE przez równość całej nazwy.

    Pułapka 7 z CLAUDE.md: dopasowanie przez fragment łapie `CELNY` wewnątrz
    `NIECELNY`. Tutaj to samo ryzyko nosi `SBZ PODAJĄCY` wewnątrz
    `SBZ PODAJĄCY/OTRZYMUJĄCY` — dwa różne zdarzenia.
    """
    return {alias: nazwa for nazwa, aliasy in domyslne().items() for alias in aliasy}


def scal_z_templatem(nadpisania=None):
    """Aliasy domyślne scalone z nadpisaniami templatu klubu.

    SCALAMY PER KLUCZ, a nie podmieniamy całość: klub, który nazwał JEDNĄ
    zmienną, nie ma tracić aliasów wszystkich pozostałych. Templat wygrywa
    tam, gdzie się wypowiedział.

    `nadpisania` w kształcie `report_template.variable_overrides`, czyli
    `{nazwa: {'display': …, 'aliases': [...]}}`.

    @return dict w tym samym kształcie, gotowy do wstrzyknięcia w `VARS`
    """
    out = {nazwa: {"aliases": list(aliasy)} for nazwa, aliasy in domyslne().items()}

    for nazwa, wpis in (nadpisania or {}).items():
        if not isinstance(wpis, dict):
            continue
        biezacy = dict(out.get(nazwa) or {})
        biezacy.update(wpis)
        out[nazwa] = biezacy

    return out
