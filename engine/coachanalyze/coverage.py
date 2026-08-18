"""Raport pokrycia i ostrzeżenia -> meta.coverage / meta.warnings.

Każda niedostępna sekcja MUSI nieść powód po polsku — trafia wprost do interfejsu.

Kształt `meta.json` jest kontraktem z warstwą PHP: docs/KONTRAKT_CLI.md.
Zmiana kluczy tutaj wymaga zmiany dokumentu w tym samym commicie.
"""

from . import __version__

# Sekcje raportu. Kolejność jest kolejnością prezentacji.
ALL_SECTIONS = ("bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam")

# Pułapka 9: literówka bywa też w palecie z pliku projektu, nie tylko w zdarzeniach.
TYPO_PALETTE_KEYS = ("MASZA POŁOWA",)


def _count(events, concept):
    return sum(1 for e in events if e["concept"] == concept)


def build_coverage(frame, canon_result, has_json=False):
    """Liczby pokrycia. Liczone na zdarzeniach kanonicznych, nie na surowym CSV (D4).

    Wyjątek: `no_team` i `teams` czytamy z surowego pola `team`, bo `team_side`
    zależy od konfiguracji klubów, której `inspect` nie dostaje. Inaczej raport
    pokrycia pokazywałby „brak drużyny" dla całego meczu przy każdym imporcie.
    """
    events = canon_result["events"]
    report = canon_result["report"]
    raw_events = frame.get("events") or []

    shots = [e for e in events if e["concept"] == "shot"]
    sbz = [e for e in events if e["concept"] == "entry_sbz"]
    third = [e for e in events if e["concept"] == "entry_third"]

    xg_values = [e["xg"] for e in events if e["xg"] is not None]
    players = [p for p in (frame.get("players") or []) if p]

    return {
        "events": len(events),
        # Zdarzenia POZA analizą (concept null): nierozpoznany tag albo świadome
        # „nie analizuj" w profilu. Raport pokrycia mówi dzięki temu
        # „7 z 120 zdarzeń nie wchodzi do metryk" zamiast samej listy tagów.
        "unanalysed": sum(1 for e in events if e["concept"] is None),
        "shots": len(shots),
        "duels": _count(events, "duel"),
        "sbz": len(sbz),
        "sbz_with_vector": sum(1 for e in sbz if e["x_end"] is not None),
        "third": len(third),
        # Pułapka 3: III STREFA bywa bez współrzędnych — to steruje sekcją tl_iii.
        "third_pos": sum(1 for e in third if e["x"] is not None),
        "teams": report["teams_detected"],
        "no_team": sum(1 for e in raw_events if e.get("team") is None),
        # Pułapka 1: xG bywa zapisane w komentarzu, nie w osobnej kolumnie.
        "xg_parsed": sum(1 for e in shots if e["xg"] is not None),
        "xg_missing": sum(1 for e in shots if e["xg"] is None),
        "xg_sum": round(sum(xg_values), 2) if xg_values else 0.0,
        "negative_begin": report["negative_begin"],
        "has_json": bool(has_json),
        # Pułapka 4: brak warstwy indywidualnej, dopóki dane nie istnieją.
        "players_filled": len(players),
    }


def build_sections(coverage, requested=None):
    """(dostępne, niedostępne). Każdy brak niesie powód po polsku.

    Powód trafia bez zmian do interfejsu — analityk ma zobaczyć, czego brakuje
    i dlaczego, a nie pustą sekcję.
    """
    reasons = {}

    if not coverage["events"]:
        reasons["bilans"] = "Eksport nie zawiera żadnych zdarzeń"
        reasons["tl_bilans"] = reasons["bilans"]

    if not coverage["shots"] and not coverage["sbz"]:
        reasons["mapy"] = (
            "Brak zdarzeń ze współrzędnymi — mapy wymagają kolumn "
            "pos_x_meters i pos_y_meters"
        )

    if not coverage["sbz"]:
        reasons["tl_sbz"] = "Eksport nie zawiera zdarzeń zdobycia SBZ"

    if not coverage["third"]:
        reasons["tl_iii"] = "Eksport nie zawiera zdarzeń III STREFY"
    elif not coverage["third_pos"]:
        reasons["tl_iii"] = "Eksport nie zawiera pozycji III STREFY (kolumny pos_* puste)"

    if not coverage["duels"]:
        reasons["duels"] = "Eksport nie zawiera pojedynków (1x1, pierwszy kontakt)"

    if not coverage["no_team"]:
        reasons["noteam"] = (
            "Wszystkie zdarzenia mają przypisaną drużynę — sekcja bez przypisania byłaby pusta"
        )

    sections = list(requested) if requested else list(ALL_SECTIONS)

    available, unavailable = [], []
    for section in sections:
        if section not in ALL_SECTIONS:
            unavailable.append({
                "id": section,
                "reason": "Sekcja nieznana silnikowi — sprawdź konfigurację raportu",
            })
        elif section in reasons:
            unavailable.append({"id": section, "reason": reasons[section]})
        else:
            available.append(section)

    return available, unavailable


def build_warnings(frame, canon_result, has_json=False, palette=None):
    """Ostrzeżenia z licznikiem wystąpień. Kolejność stała — wyjście ma być powtarzalne."""
    report = canon_result["report"]
    warnings = []

    typo_hits = report["typo_hits"]
    typo_in_palette = sum(
        1 for key in TYPO_PALETTE_KEYS
        if key in ((palette or {}).get("labels") or {}) or key in ((palette or {}).get("tags") or {})
    )
    if typo_hits or typo_in_palette:
        where = []
        if typo_hits:
            where.append("w zdarzeniach")
        if typo_in_palette:
            where.append("w palecie tablicy kodowej")
        warnings.append({
            "code": "TYPO_MASZA",
            "msg": "Wykryto 'MASZA POŁOWA' ({}) — zmapowano na 'NASZA POŁOWA'".format(
                " i ".join(where)
            ),
            "count": typo_hits + typo_in_palette,
        })

    if report["negative_begin"]:
        warnings.append({
            "code": "NEGATIVE_BEGIN",
            "msg": "Ujemny czas startu taga — przycięto do 0",
            "count": report["negative_begin"],
        })

    if not has_json:
        warnings.append({
            "code": "NO_JSON",
            "msg": (
                "Brak pliku projektu LiveTag — oś czasu użyje barw klubu "
                "zamiast palety tablicy kodowej"
            ),
            "count": 1,
        })

    if frame.get("player_column") is None:
        warnings.append({
            "code": "NO_PLAYER_COLUMN",
            "msg": "Eksport nie zawiera kolumny zawodnika — warstwa indywidualna niedostępna",
            "count": 1,
        })
    elif not [p for p in (frame.get("players") or []) if p]:
        warnings.append({
            "code": "EMPTY_PLAYER_COLUMN",
            "msg": "Kolumna zawodnika jest pusta — warstwa indywidualna niedostępna",
            "count": 1,
        })

    poza_strzalem = report.get("xg_outside_shot") or {"count": 0, "tags": []}
    if poza_strzalem["count"]:
        warnings.append({
            "code": "XG_POZA_STRZALEM",
            "msg": (
                "Liczba w komentarzu przy tagu, który nie jest strzałem — pominięta "
                "przy xG: " + ", ".join(poza_strzalem["tags"])
            ),
            "count": poza_strzalem["count"],
            "tags": poza_strzalem["tags"],
        })

    model = report.get("xg_model") or {"filled": 0, "assumed": 0}
    if model["filled"]:
        # Wartość z modelu ma być WIDOCZNA, nie wtopiona w liczby analityka —
        # zastrzeżenie o kalibracji: engine/coachanalyze/xg.py (nagłówek).
        msg = ("xG uzupełnione modelem dla {} strzałów bez wartości od analityka — "
               "wartości szacowane, czytać porównawczo").format(model["filled"])
        if model["assumed"]:
            msg += "; przy {} przyjęto założenie gry otwartej nogą".format(model["assumed"])
        warnings.append({
            "code": "XG_MODEL",
            "msg": msg,
            "count": model["filled"],
            "assumed": model["assumed"],
        })

    if report["unknown_teams"]:
        warnings.append({
            "code": "UNKNOWN_TEAM",
            "msg": "Nazwa drużyny spoza konfiguracji: " + ", ".join(report["unknown_teams"]),
            "count": len(report["unknown_teams"]),
        })

    if report["unmapped_tags"]:
        warnings.append({
            "code": "UNMAPPED_TAGS",
            "msg": (
                "Tagi bez mapowania na pojęcie kanoniczne — zdarzenia zachowane, "
                "ale nieuwzględnione w metrykach: " + ", ".join(report["unmapped_tags"])
            ),
            "count": len(report["unmapped_tags"]),
        })

    return warnings


def _probka_zdarzenia(e):
    """Jedno zdarzenie w postaci skróconej — tyle, żeby człowiek rozpoznał tag.

    Czas, drużyna i etykiety towarzyszące. Bez współrzędnych, komentarza i xG:
    próbka ma pomóc odpowiedzieć „co to za tag", a nie odtwarzać przebiegu meczu.

    UWAGA NA PRZYSZŁOŚĆ: gdyby podpowiedzi bindingów kiedyś liczył model
    językowy (backlog po Sesji 7), do modelu wolno wysłać NAZWY tagów
    i policzone metryki — nigdy tych próbek. To są surowe zdarzenia meczowe,
    czyli dokładnie to, czego CLAUDE.md §5 zabrania wypuszczać na zewnątrz.
    """
    return {
        "b": e.get("b"),
        "team": e.get("team"),
        "labels": list(e.get("labels") or []),
    }


def _pozycje_slownika(zliczone, klucz):
    """Histogram na listę: najczęstsze na górze, remisy alfabetycznie.

    Porządek jest DETERMINISTYCZNY, bo `meta.json` bywa porównywany w testach,
    a lista zmieniająca kolejność między przebiegami dawałaby fałszywe różnice.
    """
    return [
        {klucz: nazwa, "count": dane["count"], "samples": dane["samples"]}
        for nazwa, dane in sorted(zliczone.items(), key=lambda p: (-p[1]["count"], p[0]))
    ]


def build_dictionary(frame, probka=3):
    """Pełny słownik eksportu: KAŻDY tag i KAŻDA etykieta, z liczbą wystąpień.

    PO CO TO ISTNIEJE: konfigurator raportu (Sesja 3 przebudowy) buduje templat
    klubu z pierwszego importu i musi pokazać operatorowi kompletną listę tego,
    co w pliku jest. `unmapped_tags` do tego nie wystarcza — niesie wyłącznie
    tagi, których silnik NIE rozpoznał. Przy imporcie założycielskim `inspect`
    nie dostaje profilu klubu, więc tagi z domyślnego słownika silnika
    (STRZAŁ, ZDOBYCIE SBZ, III STREFA, STRATA, ODBIÓR…) są rozpoznawane
    i znikają z tamtej listy — czyli w konfiguratorze byłoby ich nie widać
    dokładnie wtedy, gdy są najbardziej potrzebne.

    LICZYMY PO ZDARZENIACH JUŻ SPARSOWANYCH, nie po surowym CSV. Dzięki temu
    etykiety przychodzą z `split_labels`, czyli z parsera respektującego
    cudzysłowy (pułapka 11), a nie z ponownego dzielenia linii po przecinku.
    Ta funkcja niczego nie parsuje i niczego nie liczy poza zliczeniem —
    żadna metryka piłkarska od niej nie zależy.

    Blok jest CZYSTO ADDYTYWNY: nie zmienia ani jednej wartości w `coverage`,
    w metrykach ani w renderze. Test złoty porównuje DATA i PAL, nie `meta`.
    """
    events = frame.get("events") or []

    tagi = {}
    etykiety = {}

    for e in events:
        tag = e.get("tag")
        if tag:
            poz = tagi.setdefault(tag, {"count": 0, "samples": []})
            poz["count"] += 1
            if len(poz["samples"]) < probka:
                poz["samples"].append(_probka_zdarzenia(e))

        for etykieta in (e.get("labels") or []):
            if not etykieta:
                continue
            poz = etykiety.setdefault(etykieta, {"count": 0, "samples": []})
            poz["count"] += 1
            if len(poz["samples"]) < probka:
                poz["samples"].append(_probka_zdarzenia(e))

    return {
        "tags": _pozycje_slownika(tagi, "tag"),
        "labels": _pozycje_slownika(etykiety, "label"),
    }


def build_meta(frame, canon_result, config=None, has_json=False, palette=None, ok=True):
    """Pełny `meta.json` zgodny z docs/KONTRAKT_CLI.md."""
    config = config or {}

    coverage = build_coverage(frame, canon_result, has_json=has_json)
    available, unavailable = build_sections(coverage, config.get("sections"))

    half_split = frame.get("half_split") or 0.0
    ends = [e.get("e") for e in (frame.get("events") or []) if e.get("e") is not None]
    begins = [e.get("b") for e in (frame.get("events") or []) if e.get("b") is not None]
    duration = max(ends) if ends else (max(begins) if begins else 0.0)

    return {
        "ok": ok,
        "engine_version": __version__,
        "format_fingerprint": frame.get("format_fingerprint"),
        "half_split_ms": int(round(half_split * 1000)),
        "duration_ms": int(round(duration * 1000)),
        "coverage": coverage,
        "sections_available": available,
        "sections_unavailable": unavailable,
        "warnings": build_warnings(frame, canon_result, has_json=has_json, palette=palette),
        # Kształt WZBOGACONY: liczba wystąpień i etykiety towarzyszące.
        # Bez nich operator kreatora decyduje w ciemno — „tag wystąpił 2 razy"
        # i „tag wystąpił 140 razy" to zupełnie inne decyzje. Warstwa PHP czyta
        # oba kształty (Mappings::unknown), więc zmiana jest bezpieczna.
        "unmapped_tags": canon_result["report"]["unmapped_tags_detail"],
        "unmapped_labels": canon_result["report"]["unmapped_labels_detail"],
        # PEŁNY słownik eksportu — wszystko, co w pliku jest, nie tylko to,
        # czego silnik nie rozpoznał. Zasila konfigurator raportu klubu
        # (Sesja 3 przebudowy). Patrz `build_dictionary`.
        "dictionary": build_dictionary(frame),
    }
