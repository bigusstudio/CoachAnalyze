"""Parsowanie eksportu LiveTag.Pro — port z `build_dashboard.py`, bez pandas.

POWÓD PORTU: katalog domowy na lh.pl jest zamontowany z `noexec`, więc numpy/pandas
nie dają się załadować (docs/OGRANICZENIA_HOSTINGU.md).

ZASADA NADRZĘDNA: ten moduł ma produkować DOKŁADNIE te same liczby, co oryginalny
skrypt. Każde odstępstwo jest błędem, dopóki nie zostanie świadomie zatwierdzone
i wpisane do CHANGELOG.md. Miejsca, w których pandas zachowywał się subtelnie,
są oznaczone komentarzem ZGODNOŚĆ.
"""

import csv
import hashlib
import json
import math
import re

from ...errors import MissingColumns, NotLiveTagExport

REQUIRED_COLUMNS = ("tag_name", "begin", "end")

OPTIONAL_COLUMNS = (
    "team", "labels", "comment",
    "pos_x_meters", "pos_y_meters",
    "pos_target_x_meters", "pos_target_y_meters",
)

# Pułapka 4: kolumna zawodnika bywa pusta ALBO nie ma jej wcale, a jej nazwa
# różni się między wersjami eksportu. Brak dopasowania = brak warstwy
# indywidualnej, nigdy dane zastępcze.
PLAYER_COLUMNS = ("player", "player_name", "zawodnik", "athlete")

# ZGODNOŚĆ: pandas.read_csv domyślnie zamienia te napisy na NaN. csv.DictReader
# zwróciłby je jako zwykły tekst, co dałoby inne wyniki niż dotychczasowy skrypt.
PANDAS_NA_VALUES = frozenset({
    "", "#N/A", "#N/A N/A", "#NA", "-1.#IND", "-1.#QNAN", "-NaN", "-nan",
    "1.#IND", "1.#QNAN", "<NA>", "N/A", "NA", "NULL", "NaN", "None",
    "n/a", "nan", "null",
})


def is_na(value):
    """Odpowiednik pandas.isna dla wartości z csv.DictReader."""
    return value is None or value in PANDAS_NA_VALUES


def to_float(value):
    """Napis -> float albo None. Odpowiednik konwersji typów przy read_csv."""
    if is_na(value):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def parse_xg(value):
    """xG bywa w komentarzu jako 'X 0,81' / 'xG 0,09' — polski przecinek.

    ZGODNOŚĆ: ten sam regex, pierwsze dopasowanie, przecinek na kropkę.
    """
    if is_na(value):
        return None
    m = re.search(r"([\d,\.]+)", str(value))
    return float(m.group(1).replace(",", ".")) if m else None


def split_labels(value):
    """Pole `labels` to lista rozdzielona przecinkami WEWNĄTRZ jednej komórki.

    csv.DictReader rozdziela kolumny z poszanowaniem cudzysłowów, więc tutaj
    dzielimy wyłącznie zawartość pojedynczego pola — nigdy całej linii.
    """
    if value is None:
        return []
    return [x.strip() for x in str(value).split(",") if x.strip()]


def detect_half_split(begins):
    """Przerwa = największa luka w kodowaniu w środkowej części zapisu.

    ZGODNOŚĆ: oryginał budował krotki (luka, t_i, t_i+1) i brał max(), co porównuje
    najpierw po rozmiarze luki, przy remisie po czasie. Odtworzone.
    """
    t = sorted(begins)
    if not t:
        return 0.0
    t_max = t[-1]
    mid = [
        (t[i + 1] - t[i], t[i], t[i + 1])
        for i in range(len(t) - 1)
        if 0.3 * t_max < t[i] < 0.7 * t_max
    ]
    gap = max(mid) if mid else (0, t[len(t) // 2], t[len(t) // 2])
    return (gap[1] + gap[2]) / 2


def read_rows(csv_path):
    """Wczytanie CSV z walidacją formatu. Zwraca (wiersze, nagłówki)."""
    with open(csv_path, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        if reader.fieldnames is None:
            raise NotLiveTagExport("Plik jest pusty lub nie zawiera nagłówka.")
        headers = list(reader.fieldnames)
        missing = [c for c in REQUIRED_COLUMNS if c not in headers]
        if missing:
            if len(missing) == len(REQUIRED_COLUMNS):
                raise NotLiveTagExport(
                    "Plik nie wygląda na eksport z LiveTag.Pro — brak kolumn "
                    + ", ".join(REQUIRED_COLUMNS)
                )
            raise MissingColumns(missing)
        rows = list(reader)
    if not rows:
        raise NotLiveTagExport("Eksport nie zawiera żadnych zdarzeń.")
    return rows, headers


def _round2(value):
    v = to_float(value)
    return round(v, 2) if v is not None else None


def prep_events(csv_path):
    """CSV -> {'events': [...], 'half_split': float}, zgodnie z wyjściem v23."""
    rows, _ = read_rows(csv_path)
    return _build_events(rows)


def _build_events(rows):
    """Właściwa konwersja wierszy. Wydzielona, żeby `prep_frame` czytał plik raz.

    ZGODNOŚĆ: wyjście musi zostać identyczne z v23_expected_DATA.json — ta funkcja
    jest wyłącznie przeniesieniem ciała `prep_events`, bez zmiany logiki.
    """
    begins = [b for b in (to_float(r.get("begin")) for r in rows) if b is not None]
    half_split = detect_half_split(begins)

    events = []
    for r in rows:
        begin = to_float(r.get("begin"))
        end = to_float(r.get("end"))
        xg = parse_xg(r.get("comment"))
        team = r.get("team")
        labels_raw = r.get("labels")

        events.append({
            "tag": r.get("tag_name"),
            # ZGODNOŚĆ: round() Pythona jest bankierskie — nie zastępować
            # konstrukcją int(x*10+0.5)/10, bo da inne wartości.
            "b": round(begin, 1) if begin is not None else None,
            "e": round(end, 1) if end is not None else None,
            "team": None if is_na(team) else team,
            "labels": [] if is_na(labels_raw) else split_labels(labels_raw),
            "xg": None if xg is None or (isinstance(xg, float) and math.isnan(xg)) else xg,
            "x": _round2(r.get("pos_x_meters")),
            "y": _round2(r.get("pos_y_meters")),
            "tx": _round2(r.get("pos_target_x_meters")),
            "ty": _round2(r.get("pos_target_y_meters")),
            # ZGODNOŚĆ: porównanie na surowym `begin`, nie na zaokrąglonym `b`.
            "half": 1 if (begin is not None and begin < half_split) else 2,
        })

    return {"events": events, "half_split": round(half_split, 1)}


def format_fingerprint(headers):
    """Skrót ZESTAWU kolumn — sygnalizuje zmianę formatu eksportu (KONTRAKT_CLI.md).

    Kolumny sortujemy, bo sama zmiana ich kolejności niczego nie psuje:
    czytamy przez DictReader, po nazwach. Rozjazd odcisku ma znaczyć
    „inny zestaw kolumn", a nie „inna kolejność".
    """
    joined = "\n".join(sorted(headers))
    return "sha256:" + hashlib.sha256(joined.encode("utf-8")).hexdigest()


def read_players(rows, headers):
    """(nazwa kolumny zawodnika, wartości) — obie None/puste, gdy kolumny brak.

    Pułapka 4: pusta kolumna zawodnika blokuje warstwę indywidualną. Zwracamy
    fakt jej braku wprost, żeby raport pokrycia mógł to pokazać zamiast udawać.
    """
    column = next((c for c in PLAYER_COLUMNS if c in headers), None)
    if column is None:
        return None, []
    return column, [None if is_na(r.get(column)) else str(r.get(column)).strip() for r in rows]


def prep_frame(csv_path):
    """prep_events + metadane wejścia, których wymaga warstwa pokrycia.

    Klucze `events` i `half_split` są identyczne co do wartości z `prep_events`
    — to ta sama funkcja na tych samych wierszach. Reszta to opis samego pliku:
    nagłówki, odcisk formatu i kolumna zawodnika.

    Lista `players` jest wyrównana indeksowo z `events` (ten sam porządek wierszy).
    """
    rows, headers = read_rows(csv_path)
    frame = _build_events(rows)
    player_column, players = read_players(rows, headers)
    frame["headers"] = headers
    frame["format_fingerprint"] = format_fingerprint(headers)
    frame["player_column"] = player_column
    frame["players"] = players
    return frame


def to_hex(color_str, floor=0.22):
    """RGB 0-1 z pliku projektu -> hex, z podbiciem jasności pod ciemne tło.

    ZGODNOŚĆ: round() bankierskie w ostatnim kroku. Zamiana na int(x+0.5)
    zmieniłaby kolory o jeden stopień — subtelnie i niezauważalnie.
    """
    r, g, b = [float(x) for x in color_str.split()]
    lum = 0.2126 * r + 0.7152 * g + 0.0722 * b
    if lum < floor:
        t = (floor - lum) / (1 - lum) if lum < 1 else 0
        if lum < 0.05:
            r, g, b = [x + (0.75 - x) * max(t, 0.35) for x in (r, g, b)]
        else:
            r, g, b = [min(1, x + (1 - x) * t * 1.4) for x in (r, g, b)]
    return "#%02X%02X%02X" % tuple(round(min(1, x) * 255) for x in (r, g, b))


def prep_palette(json_path):
    """Paleta tagów i etykiet z pliku projektu LiveTag.

    ZGODNOŚĆ: setdefault — przy powtórzonej nazwie wygrywa PIERWSZE wystąpienie.
    """
    with open(json_path, encoding="utf-8") as fh:
        d = json.load(fh)

    pal = {"tags": {}, "labels": {}}
    for dep in d.get("dependencies", []):
        data = dep.get("data", {})
        if dep.get("type") in ("tag", "label") and data.get("color") is not None:
            key = "tags" if dep["type"] == "tag" else "labels"
            pal[key].setdefault(data["name"], to_hex(data["color"]))
    return pal
