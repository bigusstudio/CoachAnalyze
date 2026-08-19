"""canonical_events + metryki -> HTML raportu (szablon v23-noname).

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
import json
import os
import re

from .errors import EngineError

TEMPLATE_FILENAME = "dashboard_template.html"

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

# Tagi, po których szablon liczy samodzielnie w JS. Służą wyłącznie kontroli
# rozjazdu — patrz `crosscheck`.
TEMPLATE_TAGS = (
    ("shot", "STRZAŁ"),
    ("entry_sbz", "ZDOBYCIE SBZ"),
    ("entry_third", "III STREFA"),
)


def default_template_path():
    """Szablon jako DANE PAKIETU: `coachanalyze/templates/dashboard_template.html`.

    Ścieżka liczona względem katalogu pakietu, nie względem katalogu roboczego ani
    korzenia repozytorium — po `pip install` silnik startuje spoza repozytorium
    (deploy.sh uruchamia go z katalogu zadania) i szablon musi jechać razem z kodem.

    Zwykły `os.path`, nie `importlib.resources`: paczka nigdy nie jest zipem —
    wdrożenie to rsync źródeł plus instalacja edytowalna — a ścieżka jako napis
    wraca w raporcie renderu i wchodzi wprost do logu.
    """
    package_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(package_dir, "templates", TEMPLATE_FILENAME)


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


def hex_to_dim(color):
    """`#E6A23C` -> `rgba(230,162,60,.16)`. Zapis bez spacji, jak w szablonie."""
    value = (color or "").lstrip("#")
    if len(value) != 6:
        raise EngineError("Barwa drużyny musi być zapisem #RRGGBB, jest: {!r}".format(color))
    try:
        r, g, b = (int(value[i:i + 2], 16) for i in (0, 2, 4))
    except ValueError:
        raise EngineError("Barwa drużyny nie jest liczbą szesnastkową: {!r}".format(color))
    return "rgba({},{},{},{})".format(r, g, b, DIM_ALPHA)


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
        crest = cfg.get("crest")

        slots["__TEAM_{}__".format(slot)] = label.upper()
        slots["__TEAM_{}_LABEL__".format(slot)] = label
        slots["__TEAM_{}_SHORT__".format(slot)] = cfg.get("short") or label.upper()
        slots["__TEAM_{}_COLOR__".format(slot)] = color
        slots["__TEAM_{}_DIM__".format(slot)] = hex_to_dim(color)
        slots["__LOGO_{}__".format(slot)] = (
            crest_data_uri(crest) if crest else placeholder_crest(label, color)
        )

    return slots, podstawione


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

    nazwy = {}
    for side, _slot in TEAM_SLOTS:
        cfg = (teams or {}).get(side) or {}
        if cfg.get("name"):
            nazwy[side] = cfg["name"].upper()

    dane["events"] = [
        dict(raw, team=nazwy.get(canonical["team_side"], raw.get("team")))
        for raw, canonical in zip(events, canon_events)
    ]
    return dane


# ------------------------------------------------------------------ podmiana
def assert_placeholders(template, slots=None):
    """Kontrola szablonu PRZED podmianą. Podnosi `EngineError` przy każdym braku.

    `/*__DATA__*/` i `/*__PAL__*/` — dokładnie raz, razem ze średnikiem.
    Znaczniki drużyn — co najmniej raz; z natury powtarzają się w wielu miejscach,
    więc sztywna liczba psułaby się przy każdej edycji szablonu.
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
    assert_placeholders(template, slots)

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
    for placeholder in sorted(slots, key=len, reverse=True):
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
    """
    teams = (config or {}).get("teams")

    template = load_template(template_path)
    slots, teams_defaulted = team_slots(frame, teams)
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
        "template": template_path or default_template_path(),
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
        "tag_mismatch": crosscheck(data, metrics),
    }


def write(path, html):
    """Zapis raportu. Kodowanie jawne — szablon deklaruje UTF-8 w `<meta charset>`."""
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(html)
    return path
