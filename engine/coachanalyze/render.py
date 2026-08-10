"""canonical_events + metryki -> HTML raportu (szablon v17+).

Szablon jest samowystarczalny i nie ma zależności zewnętrznych poza fontami. To cecha, nie brak.

Wstrzykiwanie danych działa jak w oryginalnym `build_dashboard.py`: dwa placeholdery
w komentarzu JS, podmiana razem ze średnikiem, DATA serializowane kompaktowo, PAL
domyślnie. Serializacja jest częścią wyjścia — zmiana separatorów zmienia bajty pliku
i unieważnia porównanie z v23.

ASERCJA LICZBY WYSTĄPIEŃ, NIE SAMEJ OBECNOŚCI. Powód jest historyczny i kosztował
odtwarzanie szablonu z kopii: przy v13 skrypt podmiany trafił w komentarz `/* timeline */`
w CSS i zniszczył plik. Podmiana wzorca, który występuje zero razy albo więcej niż raz,
jest błędem — nigdy „prawie dobrze".

Sprawdzamy dwa razy: PRZED podmianą, że każdy placeholder jest dokładnie raz, i PO niej,
że żaden nie został. Sam warunek `'/*__DATA__*/' in html` przepuściłby szablon bez
średnika — `str.replace` nie trafiłby w nic, nie zgłosiłby błędu, a przeglądarka
dostałaby `const DATA = ;`. Raport wyszedłby pusty, wdrożenie zielone.

Nie używamy `assert` — `python -O` wycina instrukcje `assert` z bajtkodu, a to jest
kontrola poprawności wyjścia, nie sprawdzenie założeń w testach.
"""

import json
import os
import re

from .errors import EngineError

TEMPLATE_FILENAME = "dashboard_template.html"

# Placeholdery szablonu. Podmieniamy RAZEM ZE ŚREDNIKIEM — inaczej `const DATA = ;`.
DATA_PLACEHOLDER = "/*__DATA__*/"
PAL_PLACEHOLDER = "/*__PAL__*/"

# Paleta zastępcza, gdy wywołano bez `--json`. Puste słowniki, nie zmyślone kolory:
# szablon ma zapasową barwę (`||'#9DAFA6'`), a `meta.warnings` niesie NO_JSON.
EMPTY_PALETTE = {"tags": {}, "labels": {}}

# Pozostałe znaczniki szablonu (`__LOGO_HUT__`, `__LOGO_POG__`). Nie podmieniamy ich
# tutaj — herby przychodzą z konfiguracji klubu i nie są częścią kontraktu z silnikiem.
# Raportujemy je, żeby nikt nie wysłał klientowi raportu z pustym herbem.
LEFTOVER_RE = re.compile(r"__[A-Z][A-Z0-9_]*__")

# Tagi, po których szablon v17 liczy samodzielnie w JS. Służą wyłącznie kontroli
# rozjazdu — patrz `crosscheck`.
TEMPLATE_TAGS = (
    ("shot", "STRZAŁ"),
    ("entry_sbz", "ZDOBYCIE SBZ"),
    ("entry_third", "III STREFA"),
)


def default_template_path():
    """`engine/templates/dashboard_template.html` — obok pakietu, nie w nim."""
    package_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(os.path.dirname(package_dir), "templates", TEMPLATE_FILENAME)


def load_template(path=None):
    path = path or default_template_path()
    try:
        with open(path, encoding="utf-8") as fh:
            return fh.read()
    except OSError as exc:
        raise EngineError("Nie udało się wczytać szablonu raportu: {}".format(path)) from exc


def view_data(frame):
    """Wycinek ramki, który trafia do przeglądarki.

    Wyłącznie `events` i `half_split`. Pozostałe klucze `prep_frame` (nagłówki
    eksportu, odcisk formatu, nazwa kolumny zawodnika) opisują plik wejściowy
    i nie mają czego szukać w raporcie pod publicznym adresem `/r/{club_key}/{token}`.

    Kolejność kluczy jest kolejnością z v23 — `json.dumps` zachowuje kolejność
    wstawiania, a plik wynikowy porównujemy bajtowo.
    """
    return {
        "events": frame.get("events") or [],
        "half_split": frame.get("half_split"),
    }


def assert_placeholders(template):
    """Każdy placeholder dokładnie raz — razem ze średnikiem.

    Zwraca liczby wystąpień; podnosi `EngineError` przy zerze i przy powtórzeniu.
    Powód asercji na LICZBIE, a nie na obecności: patrz nagłówek modułu (incydent v13).
    """
    counts = {}
    problems = []
    for placeholder in (DATA_PLACEHOLDER, PAL_PLACEHOLDER):
        target = placeholder + ";"
        count = template.count(target)
        counts[placeholder] = count
        if count == 0:
            bare = template.count(placeholder)
            problems.append(
                "{} — brak wzorca do podmiany{}".format(
                    target,
                    " (placeholder jest, ale bez średnika)" if bare else "",
                )
            )
        elif count > 1:
            problems.append("{} — {} wystąpienia, oczekiwano jednego".format(target, count))

    if problems:
        raise EngineError("Szablon raportu jest niezgodny: " + "; ".join(problems))
    return counts


def inject(template, data, palette):
    """Podmiana placeholderów na dane. Serializacja jak w `build_dashboard.py`.

    DATA kompaktowo (`separators=(',', ':')`) — 294 zdarzenia w jednej linii.
    PAL domyślnie (ze spacjami) — kilkadziesiąt kolorów, czytelne przy podglądzie.
    Ta asymetria jest w oryginale i zostaje: zmiana separatorów zmienia bajty pliku.
    """
    assert_placeholders(template)

    html = template.replace(
        DATA_PLACEHOLDER + ";",
        json.dumps(data, ensure_ascii=False, separators=(",", ":")) + ";",
    )
    html = html.replace(
        PAL_PLACEHOLDER + ";",
        json.dumps(palette, ensure_ascii=False) + ";",
    )

    remaining = [p for p in (DATA_PLACEHOLDER, PAL_PLACEHOLDER) if p in html]
    if remaining:
        raise EngineError(
            "Podmiana danych w szablonie nie zadziałała — placeholdery zostały: "
            + ", ".join(remaining)
        )
    return html


def unresolved_placeholders(html):
    """Znaczniki `__COŚ__`, które przetrwały render (herby klubów w szablonie v17)."""
    return sorted(set(LEFTOVER_RE.findall(html)))


def crosscheck(data, metrics):
    """Czy raport w przeglądarce pokaże to samo, co pójdzie do archiwum.

    Szablon v17 liczy w JS po SUROWEJ nazwie tagu (`e.tag==='STRZAŁ'`), a model
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

    mismatches = []
    for concept, tag in TEMPLATE_TAGS:
        in_template = sum(1 for e in events if e.get("tag") == tag)
        key = "shots" if concept == "shot" else concept
        in_metrics = sum((sides.get(side) or {}).get(key, {}).get("total", 0) for side in sides)
        if in_template != in_metrics:
            mismatches.append({
                "concept": concept,
                "template_tag": tag,
                "template_count": in_template,
                "metrics_count": in_metrics,
            })
    return mismatches


def render(frame, palette=None, metrics=None, template_path=None):
    """(html, raport). Raport idzie do logu wykonawcy, nigdy do przeglądarki.

    `metrics` nie jest wstrzykiwane do szablonu: szablon v17 liczy wszystko sam,
    w przeglądarce, ze zdarzeń w `DATA`. Pakiet metryk służy warstwie AI (D5)
    i archiwum — a tutaj wyłącznie kontroli rozjazdu (`crosscheck`).
    Wstrzyknięcie go zmieniłoby bajty pliku i zerwało porównanie z v23.
    """
    template = load_template(template_path)
    data = view_data(frame)
    html = inject(template, data, palette if palette is not None else EMPTY_PALETTE)

    return html, {
        "template": template_path or default_template_path(),
        "events": len(data["events"]),
        "bytes": len(html.encode("utf-8")),
        "has_palette": palette is not None,
        "unresolved_placeholders": unresolved_placeholders(html),
        "tag_mismatch": crosscheck(data, metrics),
    }


def write(path, html):
    """Zapis raportu. Kodowanie jawne — szablon deklaruje UTF-8 w `<meta charset>`."""
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(html)
    return path
