"""canonical_events + metryki -> HTML raportu.

DWIE GENERACJE SZABLONU (`TEMPLATE_FILES`): `v17` — DOMYŚLNA, ta, której klient używa
dziś i którą odtwarza test złoty co do bajtu; `v21` — nowa, włączana świadomie.
Wyboru dokonuje `--html-template`, zmienna `CA_HTML_TEMPLATE` albo argument
`render(template_path=…)`; wszystkie trzy przyjmują nazwę generacji lub ścieżkę.

NIE MYLIĆ Z `--template`: tamten parametr to templat raportu KLUBU (`report_template.py`),
czyli zmienne i sekcje z konfiguratora. To zupełnie inna rzecz.

Szablon jest samowystarczalny i nie ma zależności zewnętrznych poza fontami. To cecha, nie brak.

Wstrzykiwanie danych działa jak w oryginalnym `build_dashboard.py`: placeholdery w komentarzu
JS, podmiana razem ze średnikiem, DATA serializowane kompaktowo, PAL domyślnie. Serializacja
jest częścią wyjścia — zmiana separatorów zmienia bajty pliku.

Szablon nie zna żadnego klubu. Nazwy, barwy i herby przychodzą z `config.teams` i wchodzą
w miejsce znaczników `__TEAM_*__` / `__LOGO_*__`. Poprzednia generacja szablonu (v17) miała
nazwy i herby Hutnika wpisane na sztywno — leży w `coachanalyze/templates/ARCHIWUM/v17.html`.

ASERCJA LICZBY WYSTĄPIEŃ, NIE SAMEJ OBECNOŚCI. Powód jest historyczny i kosztował
odtwarzanie szablonu z kopii: przy v13 skrypt podmiany trafił w komentarz `/* timeline */`
w CSS i zniszczył plik. Podmiana wzorca, który występuje zero razy, jest błędem — nigdy
„prawie dobrze". `/*__DATA__*/` i `/*__PAL__*/` muszą wystąpić DOKŁADNIE raz (to `const X = …;`,
drugie wystąpienie znaczy uszkodzony szablon); znaczniki drużyn co najmniej raz, bo z natury
powtarzają się w wielu miejscach.

Sprawdzamy dwa razy: przed podmianą — że wzorce są tam, gdzie mają być, i po niej — że żaden
nie został. Sam warunek `'/*__DATA__*/' in html` przepuściłby szablon bez średnika:
`str.replace` nie trafiłby w nic, nie zgłosiłby błędu, a przeglądarka dostałaby `const DATA = ;`.
Raport wyszedłby pusty, wdrożenie zielone.

Nie używamy `assert` — `python -O` wycina instrukcje `assert` z bajtkodu, a to jest
kontrola poprawności wyjścia, nie sprawdzenie założeń w testach.
"""

import base64
import html as html_mod
import json
import os
import re

from .errors import EngineError

TEMPLATE_FILENAME = "dashboard_template.html"

# ------------------------------------------------------------------ generacje szablonu
#
# DWIE GENERACJE ŻYJĄ OBOK SIEBIE. `v17` to szablon, którego klient używa dziś
# i którego wyjście odtwarza test złoty co do bajtu — **i on zostaje domyślny**.
# `v21` to nowa generacja (nagłówek transmisyjny, sekcja Przegląd, motyw jasny,
# druk i slajdy PNG), włączana świadomie.
#
# DLACZEGO DOMYŚLNY ZOSTAJE v17. Wzorzec złoty jest raportem v17. Przestawienie
# domyślnej generacji znaczyłoby, że bramka wdrożenia sprawdza inny plik, niż
# produkuje produkcja — albo że trzeba ruszyć manifest. Jedno i drugie zamienia
# test złoty z bramki w formalność. v21 dostanie swój wzorzec dopiero po
# porównaniu wizualnym i zgodzie klienta (docs/PRZEBUDOWA_KLUB_SESJE.md, S5b).
#
# NAZEWNICTWO — UWAGA NA ROZJAZD, KTÓRY JUŻ ISTNIEJE W REPOZYTORIUM.
# `dashboard_template.html` nazywany jest „v17" w CLAUDE.md i w rozmowie, a
# `golden/manifest.json` opisuje go jako generację `v23-noname`. To ten sam plik:
# v17 to numer generacji szablonu klienta, v23-noname to numer raportu, z którego
# szablon powstał. Nie zmieniamy tu żadnej z tych nazw — zmiana wartości
# `szablon_generacja` w manifeście zapala test i wymaga osobnej decyzji.
TEMPLATE_FILES = {
    "v21": "dashboard_template_v21.html",
    "v17": TEMPLATE_FILENAME,
}

DEFAULT_GENERATION = "v17"

# Zmienna środowiskowa, nie pole w `config.json`: silnik nie zna sesji ani bazy
# (CLAUDE.md §4), a warstwa PHP uruchamia go przez CLI, więc środowisko procesu
# jest naturalnym miejscem na tę decyzję. Nazwa z przedrostkiem `CA_`, bo
# `REPORT_TEMPLATE` myliłoby się z `--template`, czyli templatem raportu KLUBU
# (`report_template.py`) — to zupełnie inna rzecz, opisana w kontrakcie CLI.
TEMPLATE_ENV = "CA_HTML_TEMPLATE"

# Placeholdery danych. Podmieniamy RAZEM ZE ŚREDNIKIEM — inaczej `const DATA = ;`.
DATA_PLACEHOLDER = "/*__DATA__*/"
PAL_PLACEHOLDER = "/*__PAL__*/"

# Znaczniki drużyn. `us` to gospodarz raportu (klub, dla którego powstaje), `them` to rywal.
TEAM_SLOTS = (("us", "HOME"), ("them", "AWAY"))

# Barwy zapasowe, gdy konfiguracja ich nie niesie. Prezentacja, nie dane — raport bez
# jakiejkolwiek barwy jest nieczytelny, a wykres bez danych zostaje pusty tak czy tak.
DEFAULT_COLORS = {"us": "#E6A23C", "them": "#5CA8E0"}

# Nazwa zapasowa, gdy nie ma jej ani w konfiguracji, ani w danych. Neutralna i widocznie
# zastępcza — nie da się jej pomylić z nazwą klubu. Render nie przerywa z tego powodu:
# jest ostatnim krokiem i wywrócenie się tutaj kasowałoby całe przetworzenie, a brak
# nazwy widać w raporcie od razu. Fakt podstawienia wraca w `teams_defaulted`.
FALLBACK_LABELS = {"us": "Drużyna A", "them": "Drużyna B"}

# Przygaszenie barwy drużyny. Zapis musi być identyczny jak w szablonie źródłowym.
DIM_ALPHA = ".16"

# Docelowa jasność barwy drużyny w MOTYWIE JASNYM (v21).
#
# Barwa dobrana pod ciemne tło bywa na papierze nieczytelna: żółć klubu na białym
# panelu znika. Przyciemniamy ją więc do ustalonej jasności względnej — skalujemy
# kanały, a nie przeliczamy odcień, żeby barwa klubu pozostała rozpoznawalna.
#
# TO JEST REGUŁA, NIE ODTWORZENIE RĘCZNYCH DOBORÓW z `tools/fill_v21.py`. Tamte
# wartości dobierał człowiek, osobno dla każdej drużyny, i nie da się ich wyprowadzić
# jednym wzorem. Klub, dla którego reguła wypadnie źle, podaje `color_light` wprost
# w konfiguracji (docs/KONTRAKT_CLI.md) — bez zmiany kodu.
LIGHT_TARGET_LUM = 0.45

# Typ MIME herbu idzie za rozszerzeniem pliku. Wpisany na sztywno w szablonie
# wyświetlałby PNG jako SVG i odwrotnie — przeglądarka pokazuje wtedy pusty kwadrat.
CREST_MIME = {
    ".svg": "image/svg+xml",
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".webp": "image/webp",
    ".gif": "image/gif",
}

# Paleta zastępcza, gdy wywołano bez `--json`. Puste słowniki, nie zmyślone kolory:
# szablon ma barwę zapasową (`||'#9DAFA6'`), a `meta.warnings` niesie NO_JSON.
EMPTY_PALETTE = {"tags": {}, "labels": {}}

LEFTOVER_RE = re.compile(r"__[A-Z][A-Z0-9_]*__")

# ------------------------------------------------------------------ grupy znaczników
#
# Znaczniki, których v17 NIE MA, a v21 ma. Podmieniamy je WYŁĄCZNIE tam, gdzie
# szablon je niesie — generacja bez nich nie jest uszkodzona, tylko inna.
#
# GRUPA NIEKOMPLETNA NIE PRZERYWA RENDERU. Raport ma powstać zawsze (docs/RUNBOOK.md:
# brak danych na sekcję to stan normalny, nie awaria) — a szablon, któremu przy edycji
# zniknął jeden znacznik, jest bliższy brakowi danych niż uszkodzonemu plikowi.
# Zamiast wyjątku idzie ostrzeżenie `BRAKUJACY_ZNACZNIK` w `meta.warnings`, z listą.
#
# Grupy istnieją właśnie po to, żeby to rozróżnić: brak CAŁEJ grupy to inna generacja
# i o tym nie ostrzegamy (ostrzeżenie o stanie normalnym uczy ignorować ostrzeżenia).
# Brak CZĘŚCI grupy to anomalia i o niej mówimy.
SLOT_GROUPS = {
    # Motyw jasny (v21). Barwa i jej przygaszenie dla `:root[data-theme="light"]`.
    "motyw_jasny": (
        "__TEAM_HOME_COLOR_L__", "__TEAM_HOME_DIM_L__",
        "__TEAM_AWAY_COLOR_L__", "__TEAM_AWAY_DIM_L__",
    ),
    # Meta meczu w nagłówku transmisyjnym i w stopce slajdów (v21).
    #
    # `__DATA_MECZU__`, nie `__DATA__`: to drugie jest podnapisem `/*__DATA__*/`,
    # czyli miejsca na zdarzenia meczu. Kolizja nazw wymuszała liczenie wystąpień
    # z korektą i podmianę w ustalonej kolejności — znacznik został przemianowany
    # w szablonie i cały ten mechanizm zniknął.
    "meta_meczu": ("__SEZON__", "__KOLEJKA__", "__DATA_MECZU__"),
}

# Odwrotność mapy powyżej: znacznik -> nazwa grupy.
SLOT_GROUP_OF = {p: nazwa for nazwa, grupa in SLOT_GROUPS.items() for p in grupa}

# Skąd bierzemy wartość meta meczu. Silnik NIE CHODZI DO BAZY (CLAUDE.md §4) —
# dostaje to w `config.json` albo nie dostaje wcale.
MATCH_SLOT_SOURCES = (
    ("__SEZON__", "season"),
    ("__KOLEJKA__", "round"),
    ("__DATA_MECZU__", "date"),
)

# Tagi, po których szablon liczy samodzielnie w JS. Służą wyłącznie kontroli
# rozjazdu — patrz `crosscheck`.
TEMPLATE_TAGS = (
    ("shot", "STRZAŁ"),
    ("entry_sbz", "ZDOBYCIE SBZ"),
    ("entry_third", "III STREFA"),
)


def template_path_for(name):
    """Ścieżka pliku dla NAZWY generacji (`v17` / `v21`). Nieznana nazwa to błąd.

    Szablon jest DANYMI PAKIETU: ścieżka liczona względem katalogu pakietu, nie
    względem katalogu roboczego ani korzenia repozytorium — po `pip install` silnik
    startuje spoza repozytorium (deploy.sh uruchamia go z katalogu zadania) i szablon
    musi jechać razem z kodem.

    Zwykły `os.path`, nie `importlib.resources`: paczka nigdy nie jest zipem —
    wdrożenie to rsync źródeł plus instalacja edytowalna — a ścieżka jako napis
    wraca w raporcie renderu i wchodzi wprost do logu.
    """
    nazwa = str(name).strip()
    if nazwa not in TEMPLATE_FILES:
        raise EngineError(
            "Nieznana generacja szablonu raportu: {!r}. Dozwolone: {} albo ścieżka "
            "do pliku".format(nazwa, ", ".join(sorted(TEMPLATE_FILES)))
        )
    package_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(package_dir, "templates", TEMPLATE_FILES[nazwa])


def resolve_template(spec):
    """`v17` / `v21` / ścieżka do pliku -> ścieżka. `None` -> szablon domyślny.

    JEDEN PARAMETR PRZYJMUJE OBIE POSTACIE, bo do wyboru są dwa różne pytania:
    „którą z naszych generacji" (nazwa) i „ten konkretny plik" (ścieżka, np. przy
    porównywaniu wariantu szablonu przed jego zatwierdzeniem).

    ROZRÓŻNIAMY PO KSZTAŁCIE NAPISU, NIE PO ISTNIENIU PLIKU. Gdyby o tym decydowało
    `os.path.isfile`, literówka w nazwie generacji („v71") byłaby ścieżką, której nie
    ma — a render mówiłby „nie udało się wczytać szablonu: v71" zamiast wymienić
    dozwolone nazwy. Odwrotnie też: plik o nazwie `v21` w katalogu roboczym nie może
    przejąć znaczenia nazwy generacji.

    Ścieżką jest napis z separatorem albo z rozszerzeniem `.html`. Wszystko inne to
    nazwa generacji i musi być w `TEMPLATE_FILES`.
    """
    if spec is None:
        return default_template_path()
    tekst = str(spec).strip()
    if tekst in TEMPLATE_FILES:
        return template_path_for(tekst)
    if os.sep in tekst or "/" in tekst or tekst.lower().endswith(".html"):
        return tekst
    return template_path_for(tekst)  # nieznana nazwa -> EngineError z listą dozwolonych


def default_template_path():
    """Szablon domyślny: `CA_HTML_TEMPLATE`, a bez niej generacja `v17`.

    NIEZNANA WARTOŚĆ ZMIENNEJ PRZERYWA RENDER — literówka nie może schodzić po cichu
    na domyślną generację: raport wyszedłby w innym układzie, niż zamawiał ten, kto
    zmienną ustawiał, a jedynym śladem byłby jego własny błąd.

    Zmienna przyjmuje też ścieżkę, tak samo jak `--html-template`. Jedno miejsce
    decyzji, dwa sposoby jej podania.
    """
    ze_srodowiska = (os.environ.get(TEMPLATE_ENV) or "").strip()
    if ze_srodowiska:
        return resolve_template(ze_srodowiska)
    return template_path_for(DEFAULT_GENERATION)


def load_template(path=None):
    path = path or default_template_path()
    try:
        with open(path, encoding="utf-8") as fh:
            return fh.read()
    except OSError as exc:
        raise EngineError("Nie udało się wczytać szablonu raportu: {}".format(path)) from exc


# ------------------------------------------------------------------ drużyny
def _detected_teams(frame):
    """Nazwy drużyn wykryte w danych, w kolejności pierwszego wystąpienia.

    Wyjście awaryjne, gdy render wywołano bez konfiguracji (np. podgląd przed
    dopasowaniem klubów). Silnik nie odgaduje, KTÓRA drużyna jest gospodarzem —
    bierze kolejność z pliku i tyle. Przy `--config` decyduje konfiguracja.
    """
    kolejnosc = []
    for event in frame.get("events") or []:
        team = event.get("team")
        if team is not None and team not in kolejnosc:
            kolejnosc.append(team)
    return kolejnosc


def _kanaly(color):
    """`#E6A23C` -> (230, 162, 60). Jedno miejsce, w którym zapis barwy jest sprawdzany."""
    value = (color or "").lstrip("#")
    if len(value) != 6:
        raise EngineError("Barwa drużyny musi być zapisem #RRGGBB, jest: {!r}".format(color))
    try:
        return tuple(int(value[i:i + 2], 16) for i in (0, 2, 4))
    except ValueError:
        raise EngineError("Barwa drużyny nie jest liczbą szesnastkową: {!r}".format(color))


def hex_to_dim(color):
    """`#E6A23C` -> `rgba(230,162,60,.16)`. Zapis bez spacji, jak w szablonie."""
    r, g, b = _kanaly(color)
    return "rgba({},{},{},{})".format(r, g, b, DIM_ALPHA)


def hex_to_light(color, target=LIGHT_TARGET_LUM):
    """Barwa drużyny przyciemniona pod JASNE tło (v21, `data-theme="light"`).

    Skalujemy kanały wspólnym współczynnikiem, dobranym tak, żeby jasność względna
    spadła do `target`. Wspólny współczynnik znaczy, że stosunki kanałów zostają —
    żółć klubu robi się ciemniejszą żółcią, a nie brązem ani zielenią.

    Barwa dostatecznie ciemna WRACA BEZ ZMIANY. Rozjaśnianie jej „dla symetrii"
    byłoby wymyślaniem barwy, której klub nie ma, a na papierze i tak jest czytelna.

    Współczynniki jak w `sources/livetag/parse.py` (Rec. 709, bez korekty gamma) —
    ta sama arytmetyka co przy korekcie jasności palety, więc dwie części silnika
    nie mogą rozjechać się w ocenie „ta barwa jest za jasna".
    """
    r, g, b = _kanaly(color)
    lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
    if lum <= target:
        return "#{:02X}{:02X}{:02X}".format(r, g, b)
    wsp = target / lum
    return "#{:02X}{:02X}{:02X}".format(*(min(255, round(k * wsp)) for k in (r, g, b)))


def crest_data_uri(path):
    """Plik herbu -> adres `data:`. Typ MIME z rozszerzenia, nigdy zgadywany z treści."""
    rozszerzenie = os.path.splitext(path)[1].lower()
    mime = CREST_MIME.get(rozszerzenie)
    if mime is None:
        raise EngineError(
            "Nieobsługiwany format herbu ({}): {}. Dozwolone: {}".format(
                rozszerzenie or "brak rozszerzenia", path, ", ".join(sorted(CREST_MIME))
            )
        )
    try:
        with open(path, "rb") as fh:
            payload = base64.b64encode(fh.read()).decode("ascii")
    except OSError as exc:
        raise EngineError("Nie udało się wczytać herbu: {}".format(path)) from exc
    return "data:{};base64,{}".format(mime, payload)


def placeholder_crest(label, color):
    """Herb zastępczy: biały krążek, obwódka w barwie klubu, pierwsza litera nazwy.

    Świadomie wygląda na zastępnik i nikt go nie pomyli z herbem klubu. Alternatywą
    jest `<img src="">`, czyli ikona zepsutego obrazka w raporcie wysłanym klientowi.
    """
    litera = (label or "?").strip()[:1].upper() or "?"
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        '<circle cx="50" cy="50" r="46" fill="#FFFFFF"/>'
        '<circle cx="50" cy="50" r="40" fill="none" stroke="{c}" stroke-width="4"/>'
        '<text x="50" y="66" text-anchor="middle" font-family="Arial,sans-serif" '
        'font-size="46" font-weight="700" fill="{c}">{l}</text></svg>'
    ).format(c=color, l=litera)
    return "data:image/svg+xml;base64," + base64.b64encode(svg.encode("utf-8")).decode("ascii")


def team_slots(frame, teams=None):
    """{'__TEAM_HOME__': …, …} — wartości do wstawienia w miejsce znaczników drużyn.

    Trzy formy nazwy, bo szablon używa ich w trzech różnych rolach:

    - `__TEAM_*__`      klucz dopasowania, ta sama wartość co w `DATA[].team`.
      Wersja wielkimi literami, bo tak zapisuje nazwy LiveTag i tak wyglądały oba
      raporty referencyjne. Wielkość liter nie ma tu znaczenia funkcjonalnego —
      render wpisuje ten sam napis po obu stronach porównania.
    - `__TEAM_*_LABEL__` nazwa wyświetlana (nagłówek, legendy, karty).
    - `__TEAM_*_SHORT__` etykieta toru na osi czasu, gdzie miejsca jest mało.

    Warianty `_L` (barwa i przygaszenie dla motywu jasnego) wchodzą do słownika ZAWSZE.
    Szablon, który ich nie ma (v17), po prostu ich nie użyje — grupą znaczników
    zarządza `assert_placeholders`, a nie ten kod.

    KONWENCJA STRON JEST STAŁA I NIE WYNIKA Z DANYCH: `HOME` to drużyna atakująca
    w LEWO, `AWAY` w prawo (tak podpisuje je nagłówek v21). W modelu kanonicznym
    `us` to klub, dla którego powstaje raport, a `them` to rywal — eksport LiveTag
    nie niesie informacji o gospodarzu i nie wolno jej zgadywać (pułapka 2: dane
    są już znormalizowane kierunkowo).
    """
    wykryte = _detected_teams(frame)
    slots = {}
    podstawione = []

    for indeks, (side, slot) in enumerate(TEAM_SLOTS):
        cfg = (teams or {}).get(side) or {}
        label = cfg.get("name") or (wykryte[indeks] if indeks < len(wykryte) else "")
        if not label:
            label = FALLBACK_LABELS[side]
            podstawione.append(side)

        color = cfg.get("color") or DEFAULT_COLORS[side]
        # Barwa pod jasne tło: jawna z konfiguracji ma pierwszeństwo przed regułą.
        # Klub, któremu przeliczenie nie pasuje, podaje swoją i nikt nie rusza kodu.
        color_light = cfg.get("color_light") or hex_to_light(color)
        crest = cfg.get("crest")

        # ═══════════════════════════════════════════════════════════════════
        # DWA KONTEKSTY, DWIE UCIECZKI — i to nie jest niekonsekwencja.
        #
        # `__TEAM_*__` idzie do LITERAŁU JS porównywanego ze zdarzeniami
        # (`e.team === HUT`). Ucieczka HTML zamieniłaby „&" na „&amp;" i klub
        # o nazwie „Test & Spółka" dostałby raport z ZEREM własnych zdarzeń —
        # po obu stronach porównania stałyby wtedy różne napisy. Dlatego tutaj
        # ucieczka JS: neutralizujemy apostrof, cudzysłów, ukośnik i grawis,
        # zostawiając sam znak `&` nietknięty.
        #
        # `__TEAM_*_LABEL__` i `__TEAM_*_SHORT__` idą do TREŚCI HTML i do
        # literałów szablonowych — tam obowiązuje ucieczka HTML. Nazwa klubu
        # pochodzi z bazy, czyli od użytkownika, a raport wisi pod publicznym
        # adresem `/r/{club_key}/{token}` (CLAUDE.md §5).
        # ═══════════════════════════════════════════════════════════════════
        slots["__TEAM_{}__".format(slot)] = _js_literal(label.upper())
        slots["__TEAM_{}_LABEL__".format(slot)] = _tekst_do_szablonu(label)
        slots["__TEAM_{}_SHORT__".format(slot)] = _tekst_do_szablonu(
            cfg.get("short") or label.upper()
        )
        slots["__TEAM_{}_COLOR__".format(slot)] = color
        slots["__TEAM_{}_DIM__".format(slot)] = hex_to_dim(color)
        slots["__TEAM_{}_COLOR_L__".format(slot)] = color_light
        slots["__TEAM_{}_DIM_L__".format(slot)] = hex_to_dim(color_light)
        slots["__LOGO_{}__".format(slot)] = (
            crest_data_uri(crest) if crest else placeholder_crest(label, color)
        )

    return slots, podstawione


def _js_literal(wartosc):
    """Napis bezpieczny w LITERALE JS, bez ucieczki HTML.

    Używany dla `__TEAM_*__`, czyli klucza dopasowania drużyn. Ucieczka HTML
    jest tu ZABRONIONA: `&amp;` po jednej stronie porównania i `&` po drugiej
    znaczą raport z zerem zdarzeń dla klubu o nazwie z ampersandem.

    Neutralizujemy to, co potrafi wyjść z literału: apostrof, cudzysłów,
    grawis, ukośnik odwrotny, `${` i domknięcie znacznika `</`. Znaki narodowe
    i `&` zostają nietknięte — szablon czyta je jako zwykły tekst.
    """
    tekst = "" if wartosc is None else str(wartosc)
    for znak in ("\\", "'", '"', "`"):
        tekst = tekst.replace(znak, "")
    return tekst.replace("${", "").replace("</", "")


def _tekst_do_szablonu(wartosc):
    """Tekst od użytkownika w miejsce znacznika. Pusty napis dla braku danych.

    Meta meczu (sezon, kolejka, data) wpisuje operator, a w v21 ląduje w DWÓCH
    kontekstach naraz: w treści HTML i wewnątrz literału szablonowego JS
    (`innerHTML = \\`… __DATA__ …\\``). Raport wisi pod publicznym adresem
    `/r/{club_key}/{token}`, więc wartość musi być bezpieczna w obu.

    `html.escape` domyka `& < > " '`, czyli treść HTML i literały JS w apostrofach
    albo cudzysłowach. Zostaje grawis i `${` — otwierają literał szablonowy, którego
    `html.escape` nie zna. Usuwamy je, bo w sezonie ani dacie nie mają czego szukać;
    zamiana na encje niczego by nie dała, skoro JS czyta ten napis przed HTML-em.

    NIE GENERUJEMY WARTOŚCI ZASTĘPCZEJ (CLAUDE.md §8). Brak sezonu ma być pustym
    miejscem w nagłówku, a nie wymyśloną datą.
    """
    if wartosc is None:
        return ""
    tekst = str(wartosc).strip()
    if not tekst:
        return ""
    return html_mod.escape(tekst).replace("`", "").replace("${", "")


def match_slots(config):
    """{'__SEZON__': …, '__KOLEJKA__': …, '__DATA__': …} z meta meczu w konfiguracji.

    Silnik nie chodzi do bazy (CLAUDE.md §4) — dostaje to, co PHP włoży do
    `config.json` w bloku `match`, albo nic. Nic znaczy pusty napis, nie brak
    znacznika: szablon v21 ma te miejsca w nagłówku i muszą zostać wypełnione.

    `season_label` z korzenia konfiguracji działa jako zapasowe źródło sezonu —
    to pole istnieje w kontrakcie od dawna i szkoda byłoby wymagać drugiego wpisu
    na tę samą wartość.
    """
    config = config or {}
    meta = config.get("match") or {}
    slots = {p: _tekst_do_szablonu(meta.get(klucz)) for p, klucz in MATCH_SLOT_SOURCES}
    if not slots["__SEZON__"]:
        slots["__SEZON__"] = _tekst_do_szablonu(config.get("season_label"))
    return slots


def view_data(frame, canon_result=None, teams=None):
    """Wycinek ramki, który trafia do przeglądarki.

    Wyłącznie `events` i `half_split`. Pozostałe klucze `prep_frame` (nagłówki
    eksportu, odcisk formatu, nazwa kolumny zawodnika) opisują plik wejściowy
    i nie mają czego szukać w raporcie pod publicznym adresem `/r/{club_key}/{token}`.

    Gdy konfiguracja niesie drużyny, `team` w zdarzeniu zastępujemy nazwą z konfiguracji,
    wybraną po `team_side` z modelu kanonicznego. Dwa powody:

    - Szablon porównuje `e.team` z nazwą klubu przez RÓWNOŚĆ. Klub, który w kolejnym
      eksporcie zapisze nazwę inaczej (inna wielkość liter, literówka, zmiana nazwy
      w LiveTag), dostałby raport z zerem zdarzeń dla własnej drużyny i bez ostrzeżenia.
      Po tej podmianie dopasowanie robi model kanoniczny, a nie napis w JS.
    - Surowy napis z eksportu przestaje wyciekać do raportu pod publicznym adresem.

    Nazwa, której model nie rozpoznał (`team_side: none` przy niepustym `team`),
    ZOSTAJE bez zmian. Skasowanie jej przeniosłoby zdarzenie do sekcji „bez przypisania
    drużyny" i zmieniło liczby; ostrzeżenie `UNKNOWN_TEAM` już o tym mówi.
    """
    events = frame.get("events") or []
    dane = {"events": events, "half_split": frame.get("half_split")}

    if not teams or canon_result is None:
        return dane

    canon_events = canon_result.get("events") or []
    if len(canon_events) != len(events):
        raise EngineError(
            "Model kanoniczny ma {} zdarzeń, ramka {} — nie da się przypisać drużyn".format(
                len(canon_events), len(events)
            )
        )

    # TA SAMA POSTAĆ NAZWY, CO W `__TEAM_*__`. Szablon porównuje `e.team`
    # z tym literałem przez równość, więc obie strony muszą przejść przez
    # dokładnie tę samą funkcję — inaczej klub z apostrofem w nazwie dostaje
    # raport z zerem własnych zdarzeń i bez żadnego ostrzeżenia.
    nazwy = {}
    for side, _slot in TEAM_SLOTS:
        cfg = (teams or {}).get(side) or {}
        if cfg.get("name"):
            nazwy[side] = _js_literal(cfg["name"].upper())

    dane["events"] = [
        dict(raw, team=nazwy.get(canonical["team_side"], raw.get("team")))
        for raw, canonical in zip(events, canon_events)
    ]
    return dane


# ------------------------------------------------------------------ podmiana
def missing_slots(template, slots=None):
    """Znaczniki z grupy CZĘŚCIOWO obecnej w szablonie — czyli te, których brakuje.

    Grupa nieobecna w całości nie jest brakiem, tylko inną generacją szablonu:
    v17 nie ma ani jednego znacznika motywu jasnego i to jest poprawne. Grupa
    obecna w połowie to ślad po edycji, która zjadła znacznik — i o tym mówimy.

    Zwraca listę posortowaną, żeby ostrzeżenie było powtarzalne między przebiegami.
    """
    braki = []
    for grupa in SLOT_GROUPS.values():
        nalezace = [p for p in grupa if p in (slots or {})]
        if not nalezace:
            continue
        nieobecne = [p for p in nalezace if template.count(p) == 0]
        if nieobecne and len(nieobecne) != len(nalezace):
            braki.extend(nieobecne)
    return sorted(braki)


def assert_placeholders(template, slots=None):
    """Kontrola szablonu PRZED podmianą. Podnosi `EngineError` przy każdym braku.

    `/*__DATA__*/` i `/*__PAL__*/` — dokładnie raz, razem ze średnikiem.
    Znaczniki drużyn — co najmniej raz; z natury powtarzają się w wielu miejscach,
    więc sztywna liczba psułaby się przy każdej edycji szablonu.

    Znaczniki z `SLOT_GROUPS` (motyw jasny, meta meczu) SĄ OPCJONALNE i ich brak
    NIGDY nie przerywa renderu — raport ma powstać zawsze. Nieobecne po prostu nie
    wchodzą do podmiany; o grupie niekompletnej mówi `missing_slots` i ostrzeżenie
    `BRAKUJACY_ZNACZNIK` w `meta.warnings`.

    Zwraca liczby wystąpień dla znaczników AKTYWNYCH, czyli tych, które faktycznie
    trzeba podmienić.
    """
    liczby = {}
    problemy = []

    for placeholder in (DATA_PLACEHOLDER, PAL_PLACEHOLDER):
        wzorzec = placeholder + ";"
        ile = template.count(wzorzec)
        liczby[placeholder] = ile
        if ile == 0:
            golo = template.count(placeholder)
            problemy.append("{} — brak wzorca do podmiany{}".format(
                wzorzec, " (placeholder jest, ale bez średnika)" if golo else ""))
        elif ile > 1:
            problemy.append("{} — {} wystąpienia, oczekiwano jednego".format(wzorzec, ile))

    for placeholder in sorted(slots or {}):
        ile = template.count(placeholder)
        if SLOT_GROUP_OF.get(placeholder) is not None:
            if ile:
                liczby[placeholder] = ile
            continue
        liczby[placeholder] = ile
        if ile == 0:
            problemy.append("{} — znacznik zniknął z szablonu".format(placeholder))

    if problemy:
        raise EngineError("Szablon raportu jest niezgodny: " + "; ".join(problemy))
    return liczby


def inject(template, data, palette, slots=None):
    """Podmiana znaczników na dane. Serializacja jak w `build_dashboard.py`.

    DATA kompaktowo (`separators=(',', ':')`) — 294 zdarzenia w jednej linii.
    PAL domyślnie (ze spacjami) — kilkadziesiąt kolorów, czytelne przy podglądzie.
    Ta asymetria jest w oryginale i zostaje: zmiana separatorów zmienia bajty pliku.
    """
    slots = slots or {}
    aktywne = assert_placeholders(template, slots)

    html = template.replace(
        DATA_PLACEHOLDER + ";",
        json.dumps(data, ensure_ascii=False, separators=(",", ":")) + ";",
    )
    html = html.replace(
        PAL_PLACEHOLDER + ";",
        json.dumps(palette, ensure_ascii=False) + ";",
    )
    # Malejąco po długości. Domykające `__` sprawia, że `__TEAM_HOME__` nie jest
    # fragmentem `__TEAM_HOME_LABEL__` i kolejność nie ma dziś znaczenia — ale
    # znacznik dodany kiedyś bez domknięcia rozbiłby podmianę po cichu.
    #
    # Podmieniamy WYŁĄCZNIE znaczniki aktywne: grupa nieobecna w tej generacji
    # szablonu nie ma czego podmieniać, a `str.replace` bez trafienia nie zgłasza
    # błędu i schowałby literówkę.
    do_podmiany = [p for p in slots if p in aktywne]
    for placeholder in sorted(do_podmiany, key=len, reverse=True):
        html = html.replace(placeholder, slots[placeholder])

    zostalo = [p for p in (DATA_PLACEHOLDER, PAL_PLACEHOLDER) if p in html]
    if zostalo:
        raise EngineError(
            "Podmiana danych w szablonie nie zadziałała — placeholdery zostały: "
            + ", ".join(zostalo)
        )
    return html


def unresolved_placeholders(html):
    """Znaczniki `__COŚ__`, które przetrwały render. Po poprawnym renderze pusto."""
    return sorted(set(LEFTOVER_RE.findall(html)))


def crosscheck(data, metrics):
    """Czy raport w przeglądarce pokaże to samo, co pójdzie do archiwum.

    Szablon liczy w JS po SUROWEJ nazwie tagu (`e.tag==='STRZAŁ'`), a model
    kanoniczny i archiwum liczą po pojęciu (`concept == 'shot'`). Przy domyślnym
    profilu to te same liczby. Profil klubu, który mapuje np. `STRZAŁ NASZA` na
    `shot`, rozjeżdża je natychmiast: coach widzi w raporcie zero strzałów, a
    porównanie sezonowe pokazuje komplet.

    Zwraca listę rozjazdów — nie przerywa renderu. Raport ma powstać; rozjazd
    ma trafić do logu, żeby ktoś podjął decyzję świadomie.
    """
    if not metrics:
        return []

    events = data.get("events") or []
    sides = metrics.get("sides") or {}

    rozjazdy = []
    for concept, tag in TEMPLATE_TAGS:
        w_szablonie = sum(1 for e in events if e.get("tag") == tag)
        klucz = "shots" if concept == "shot" else concept
        w_modelu = sum((sides.get(side) or {}).get(klucz, {}).get("total", 0) for side in sides)
        if w_szablonie != w_modelu:
            rozjazdy.append({
                "concept": concept,
                "template_tag": tag,
                "template_count": w_szablonie,
                "metrics_count": w_modelu,
            })
    return rozjazdy


def index_block(index_base, links):
    """Blok odsyłaczy do indeksu współczynników (M1), doklejany przed </body>.

    Render NIE zna słownika ani bazy — dostaje gotową listę z `config.options`
    (docs/KONTRAKT_CLI.md): adres bazowy i pozycje {slug, label, estimated}.
    Adres bazowy jest publiczny (/r/{club_key}/i/…), więc odsyłacze działają
    i w panelu, i w raporcie udostępnionym bez logowania.

    Wskaźniki SZACOWANE dostają znacznik i wspólną adnotację — szczegóły
    i ograniczenia metody są w haśle, nie w raporcie.

    Styl wpisany w atrybuty, nie w arkusz: szablon jest samowystarczalnym
    plikiem HTML i doklejka nie może zależeć od jego klas ani go modyfikować.
    """
    import html as html_mod

    pozycje = []
    for link in links:
        slug = str(link.get("slug") or "")
        label = str(link.get("label") or "")
        if not slug or not label or not slug.replace("-", "").isalnum():
            continue
        znacznik = " *" if link.get("estimated") else ""
        pozycje.append(
            '<a href="{}{}" style="color:#9dc3e6;text-decoration:underline;">{}</a>{}'.format(
                html_mod.escape(index_base, quote=True), html_mod.escape(slug, quote=True),
                html_mod.escape(label), znacznik,
            )
        )

    if not pozycje:
        return ""

    return (
        '<section id="ca-indeks" style="margin:24px auto;max-width:1200px;'
        'padding:16px 24px;font-family:inherit;font-size:13px;color:#c9d4de;">'
        '<strong>Indeks współczynników:</strong> '
        + " &middot; ".join(pozycje)
        + '<br><span style="color:#8a97a3;">* wskaźnik szacowany — wartość może '
        'pochodzić z modelu, nie wprost z danych meczu; ograniczenia metody '
        'opisuje hasło indeksu.</span>'
        "</section>"
    )


# Sekcje raportu -> identyfikatory `<section id="...">` w szablonie.
#
# Dwie listy nazw tej samej rzeczy to zawsze ryzyko rozjazdu, wiec mapowanie
# stoi w JEDNYM miejscu, a test pilnuje, ze pokrywa cale `ALL_SECTIONS`.
SECTION_DOM_ID = {
    "bilans": "sec-bilans",
    "mapy": "sec-mapy",
    "tl_sbz": "sec-tlsbz",
    "tl_iii": "sec-tl3",
    "tl_bilans": "sec-tlm",
    "duels": "sec-duels",
    "noteam": "sec-noteam",
}


def drop_sections(html, section_ids):
    """Usuniecie sekcji z GOTOWEGO HTML-a.

    ┌──────────────────────────────────────────────────────────────────────┐
    │ PRZEJSCIOWE do S5b — MOSTEK, NIE DOCELOWE ROZWIAZANIE.               │
    │                                                                      │
    │ Szablon raportu ma nazwy tagow i etykiety wpisane na sztywno w JS,   │
    │ wiec nie da sie nim sterowac konfiguracja. Do czasu przepisania go   │
    │ na wariant sterowany templatem (S5b, wymaga przebazowania wzorca     │
    │ zlotego i decyzji klienta) sekcje wylaczone w templacie wycinamy     │
    │ z WYJSCIA, a nie z szablonu.                                         │
    │                                                                      │
    │ Ograniczenie jest realne i trzeba je znac: wycinamy sam blok         │
    │ `<section>`, a JS szablonu nadal liczy dla niego dane i probuje      │
    │ pisac do nieistniejacych wezlow. Szablon jest na to odporny          │
    │ (`getElementById` zwraca null i konczy sie cicho), ale to zaleznosc  │
    │ od cudzej odpornosci, a nie projekt.                                 │
    └──────────────────────────────────────────────────────────────────────┘

    Zwraca (html, usuniete[]). Pusta lista sekcji zostawia HTML nietkniety.
    """
    usuniete = []
    for sid in section_ids or ():
        dom_id = SECTION_DOM_ID.get(sid)
        if not dom_id:
            continue
        poczatek = html.find('<section id="{}"'.format(dom_id))
        if poczatek == -1:
            continue
        koniec = html.find("</section>", poczatek)
        if koniec == -1:
            continue
        html = html[:poczatek] + html[koniec + len("</section>"):]
        usuniete.append(sid)
    return html, usuniete


def stamp_block(template_version, generated_at):
    """Dyskretna stopka „templat vN · wygenerowano DATA", doklejana przed </body>.

    Odpowiada na pytanie „dlaczego raport z marca pokazuje inna liczbe"
    (CLAUDE.md §7) bez wchodzenia w tresc raportu. Styl w atrybutach, nie
    w arkuszu — tak samo jak `index_block`: doklejka nie moze zalezec od klas
    szablonu ani go modyfikowac.
    """
    if template_version is None:
        return ""
    import html as html_mod
    opis = "templat v{}".format(int(template_version))
    if generated_at:
        opis += " · wygenerowano {}".format(html_mod.escape(str(generated_at)))
    return (
        '<div style="margin:24px 0 8px;text-align:center;font:11px/1.4 system-ui,sans-serif;'
        'opacity:.45">{}</div>'.format(opis)
    )


def render(frame, palette=None, metrics=None, canon_result=None, config=None, template_path=None):
    """(html, raport). Raport idzie do logu wykonawcy, nigdy do przeglądarki.

    `metrics` nie jest wstrzykiwane do szablonu: szablon liczy wszystko sam,
    w przeglądarce, ze zdarzeń w `DATA`. Pakiet metryk służy warstwie AI (D5)
    i archiwum — a tutaj wyłącznie kontroli rozjazdu (`crosscheck`).

    `template_path` przyjmuje nazwę generacji (`v17`, `v21`) albo ścieżkę do pliku
    i wygrywa ze wszystkim. Bez niego decyduje `CA_HTML_TEMPLATE`, a bez niej — v17,
    czyli szablon, którego wyjścia pilnuje test złoty.
    """
    teams = (config or {}).get("teams")

    sciezka = resolve_template(template_path)
    template = load_template(sciezka)
    slots, teams_defaulted = team_slots(frame, teams)
    slots.update(match_slots(config))
    braki_znacznikow = missing_slots(template, slots)
    data = view_data(frame, canon_result=canon_result, teams=teams)
    html = inject(template, data, palette if palette is not None else EMPTY_PALETTE, slots)

    # Odsyłacze do indeksu współczynników — WYŁĄCZNIE gdy konfiguracja niesie
    # adres bazowy. Bez niego wyjście jest bajt w bajt takie jak dotąd; na tym
    # stoi złoty test odtworzenia raportu produkcyjnego.
    options = (config or {}).get("options") or {}
    if options.get("index_base") and options.get("index_links"):
        blok = index_block(str(options["index_base"]), list(options["index_links"]))
        if blok and "</body>" in html:
            html = html.replace("</body>", blok + "\n</body>", 1)

    # Sekcje wylaczone w templacie ALBO niedostepne dla tego eksportu.
    # Lista przychodzi gotowa z `config.drop_sections` — decyzje „czego brakuje
    # i dlaczego" podejmuje `coverage.build_sections`, a nie render.
    #
    # GENEROWANIE NIGDY NIE PADA Z TEGO POWODU: brak danych na sekcje to stan
    # normalny (pulapka 3 — III STREFA bywa bez wspolrzednych), a nie awaria.
    html, sekcje_usuniete = drop_sections(html, (config or {}).get("drop_sections"))

    # Stempel wersji templatu. Bez wersji — bez stopki i bajt w bajt jak dotad.
    stempel = stamp_block(
        (config or {}).get("template_version"),
        (config or {}).get("generated_at"),
    )
    if stempel and "</body>" in html:
        html = html.replace("</body>", stempel + "\n</body>", 1)

    return html, {
        "sections_dropped": sekcje_usuniete,
        "template": sciezka,
        # Nazwa generacji idzie do logu obok ścieżki: pytanie „dlaczego raport
        # z marca wygląda inaczej" (CLAUDE.md §7) ma mieć odpowiedź bez zgadywania,
        # którą wartość miał wtedy `REPORT_TEMPLATE`.
        "template_generation": next(
            (nazwa for nazwa, plik in TEMPLATE_FILES.items()
             if os.path.basename(sciezka) == plik),
            None,
        ),
        "events": len(data["events"]),
        "bytes": len(html.encode("utf-8")),
        "has_palette": palette is not None,
        "teams": {
            side: slots["__TEAM_{}_LABEL__".format(slot)] for side, slot in TEAM_SLOTS
        },
        # Nazwa podstawiona zapasowo i herb wygenerowany zamiast wczytanego z pliku.
        # Jedno i drugie widać w raporcie, ale operator ma się dowiedzieć wcześniej.
        "teams_defaulted": teams_defaulted,
        "crests_generated": [
            side for side, _slot in TEAM_SLOTS
            if not ((teams or {}).get(side) or {}).get("crest")
        ],
        "unresolved_placeholders": unresolved_placeholders(html),
        # Grupa znaczników obecna w szablonie tylko częściowo. Nie przerywa renderu
        # (raport ma powstać zawsze) — idzie jako ostrzeżenie do `meta.warnings`.
        "missing_slots": braki_znacznikow,
        "tag_mismatch": crosscheck(data, metrics),
    }


def write(path, html):
    """Zapis raportu. Kodowanie jawne — szablon deklaruje UTF-8 w `<meta charset>`."""
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(html)
    return path
