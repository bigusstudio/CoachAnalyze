"""raw_frame + profil mapowań -> canonical_events[].

Opis modelu: docs/MODEL_KANONICZNY.md

UWAGA: dopasowanie etykiet wyłącznie przez równość na rozdzielonej liście.
Dopasowanie przez fragment tekstu łapie CELNY wewnątrz NIECELNY i psuje liczby bez ostrzeżenia.

Dlatego etykiety i tagi rozpoznajemy WYŁĄCZNIE przez `dict.get(...)` na gotowej
liście. W tym module nie ma i nie może się pojawić `in`, `startswith` ani `find`
na treści etykiety — to jedyne realne źródło cichych błędów w liczbach.
"""

import json

# Pojęcia bazowe z docs/MODEL_KANONICZNY.md. Nowa etykieta w eksporcie to
# KWALIFIKATOR, nie nowe pojęcie — rozrost tej listy oznaczałby, że model
# zaczyna kopiować format LiveTag zamiast go tłumaczyć.
CONCEPTS = frozenset({
    "shot", "entry_sbz", "entry_third", "duel", "loss", "recovery", "press",
    "transition", "set_piece", "foul", "card", "keeper_action",
})

TEAM_SIDES = ("us", "them", "none")

# Pułapka 9: literówka w eksportach klienta. Mapowanie przez RÓWNOŚĆ całego
# napisu, nigdy przez podmianę fragmentu — `str.replace("MASZA", "NASZA")`
# zadziałałby też wewnątrz nazw, których nikt nie sprawdził.
TYPO_MAP = {
    "MASZA POŁOWA": "NASZA POŁOWA",
}


def _tag(concept, *qualifiers):
    return {"concept": concept, "qualifiers": tuple(qualifiers), "team_side": None}


# Domyślny profil — słownik odczytany z eksportów referencyjnych klienta.
# Profil klubu z `config.mapping_profile` nadpisuje te reguły klucz po kluczu.
DEFAULT_TAG_RULES = {
    "STRZAŁ": _tag("shot"),
    "ZDOBYCIE SBZ": _tag("entry_sbz"),
    "III STREFA": _tag("entry_third"),
    "STRATA": _tag("loss"),
    "ODBIÓR": _tag("recovery"),
    "PIERWSZY KONTAKT": _tag("duel", "first_contact"),
    "1x1 OFF": _tag("duel", "offensive"),
    "1x1 DEF.": _tag("duel", "defensive"),
    "SKUTECZNY": _tag("press", "effective"),
    "NISKUTECZNY": _tag("press", "ineffective"),
    # AKCJA DEFENSYWNA celowo bez mapowania — patrz komentarz przy `build`.
}

DEFAULT_LABEL_RULES = {
    # wynik strzału — kształt znacznika w renderze (pułapka 6)
    "CELNY": "on_target",
    "NIECELNY": "off_target",
    "ZABLOKOWANY": "blocked",
    "GOL": "goal",
    # sposób zbudowania akcji
    "POZYCYJNIE": "positional",
    "KONTRATAK": "counter",
    "PRESSING": "after_press",
    "SFG": "set_piece",          # stały fragment gry
    "POSIADANIE": "possession",
    # następstwo zdobycia SBZ
    "STRZAŁ": "with_shot",
    "BRAK STRZAŁU": "without_shot",
    "STRZAŁ Z SBZ": "shot_from_sbz",
    "ZDOBYCIE SBZ": "entry_sbz",
    # rozstrzygnięcie
    "UDANA": "successful",
    "NIEUDANA": "unsuccessful",
    "WYGRANY": "won",
    "PRZEGRANY": "lost",
    "REAKCJA": "reaction",
    "BRAK REAKCJI": "no_reaction",
    # strefa boiska
    "NASZA POŁOWA": "own_half",
    "ICH POŁOWA": "opp_half",
    # pozostałe
    "DRUGI KONTAKT": "second_contact",
    "STRATA": "loss",
    "ODBIÓR": "recovery",
    "INNE": "other",
}


def normalize_name(value):
    """Nazwa tagu/etykiety po korekcie znanych literówek + licznik trafień.

    Zwraca (nazwa, czy_poprawiono). Dopasowanie przez równość całego napisu.
    """
    if value is None:
        return None, False
    fixed = TYPO_MAP.get(value)
    return (fixed, True) if fixed is not None else (value, False)


def _norm_team(value):
    """Nazwa drużyny do porównania: bez nadmiarowych spacji, bez wielkości liter."""
    return " ".join(str(value).split()).casefold()


def build_team_lookup(teams):
    """{'us': {'name': ..., 'short': ..., 'source_names': [...]}, ...} -> {nazwa: strona}.

    Silnik nie odgaduje nazw klubów — dostaje je w konfiguracji (KONTRAKT_CLI.md).

    `source_names` to nazwy tak, jak zapisał je LiveTag. Są osobnym polem, bo nazwa
    wyświetlana w raporcie nie musi być tą z eksportu: klub bywa otagowany skrótem,
    z literówką albo pod starą nazwą, a raport ma pokazywać nazwę bieżącą. Bez tego
    pola każda zmiana zapisu w LiveTag wymagałaby zmiany nazwy klubu w aplikacji.
    """
    lookup = {}
    for side in ("us", "them"):
        cfg = (teams or {}).get(side) or {}
        for key in ("name", "short"):
            value = cfg.get(key)
            if value:
                lookup[_norm_team(value)] = side
        for value in cfg.get("source_names") or ():
            if value:
                lookup[_norm_team(value)] = side
    return lookup


def resolve_profile(mapping_profile=None):
    """Profil domyślny + reguły klubu. Reguła klubu nadpisuje domyślną po kluczu."""
    tags = dict(DEFAULT_TAG_RULES)
    labels = dict(DEFAULT_LABEL_RULES)

    for rule in (mapping_profile or {}).get("rules") or []:
        match = rule.get("match") or {}
        if "tag" in match:
            tags[match["tag"]] = {
                "concept": rule.get("concept"),
                "qualifiers": tuple(rule.get("qualifiers") or ()),
                "team_side": rule.get("team_side"),
            }
        elif "label" in match:
            labels[match["label"]] = rule.get("qualifier")

    return {"tags": tags, "labels": labels}


def to_records(events, match_id=None):
    """canonical_events[] -> rekordy w kształcie tabeli `events_canonical`.

    Kolumny wg app/migrations/002_events_canonical.sql. Silnik nie chodzi do bazy
    (D4) — produkuje plik, który PHP wstawia.

    Liczba rekordów zawsze równa się liczbie zdarzeń, także dla `concept: null`.
    Zdarzenie bez rozpoznanego pojęcia jest faktem o meczu i ma trafić do archiwum;
    gubienie go tutaj skrzywiłoby porównania sezonowe (moduł M4).

    `t_ms` bywa `null`, gdy `begin` nie dał się odczytać. NIE podstawiamy zera —
    zero to konkretna 0. minuta i nie da się jej odróżnić od braku danych (CLAUDE.md §8).
    Wiersze z `t_ms: null` nie wejdą do kolumny NOT NULL i musi je obsłużyć PHP.
    """
    records = []
    for event in events:
        records.append({
            "match_id": match_id,
            "t_ms": event["t_ms"],
            "half": event["half"],
            "team_side": event["team_side"],
            "concept": event["concept"],
            # Kolumna jest typu JSON — podajemy gotowy napis, żeby PHP wstawiało
            # wartość bez ponownego kodowania.
            "qualifiers_json": json.dumps(event["qualifiers"], ensure_ascii=False),
            "x": event["x"],
            "y": event["y"],
            "x_end": event["x_end"],
            "y_end": event["y_end"],
            "xg": event["xg"],
            "xg_source": event["xg_source"],
            "player_id": event["player_id"],
            "source_tag": event["source_tag"],
        })
    return records


def build(frame, mapping_profile=None, teams=None):
    """raw_frame -> {'events': canonical_events[], 'report': {...}}.

    Zdarzenia z nierozpoznanym tagiem NIE ZNIKAJĄ: trafiają do wyniku z
    `concept: null` i `confidence: 0.0`, a nazwa tagu ląduje w `unmapped_tags`.
    Dzięki temu suma zdarzeń kanonicznych zawsze zgadza się z liczbą wierszy
    eksportu, a brak mapowania jest widoczny, a nie zamaskowany.

    Przykład z eksportów referencyjnych: `AKCJA DEFENSYWNA` (7 zdarzeń) nie ma
    odpowiednika wśród pojęć bazowych i celowo pozostaje nierozpoznany do czasu
    decyzji człowieka — zgadywanie pojęcia zmieniłoby liczby w raporcie.
    """
    profile = resolve_profile(mapping_profile)
    tag_rules, label_rules = profile["tags"], profile["labels"]
    team_lookup = build_team_lookup(teams)

    events = []
    unmapped_tags, unmapped_labels, teams_detected, unknown_teams = {}, {}, {}, {}
    xg_outside = {}
    typo_hits = 0

    # Parser przycina ujemny `begin` do zera i podaje licznik osobno. Ramka zbudowana
    # ze wzorca (testy) licznika nie ma — wtedy liczymy z wartości, co po przycięciu
    # daje zero i jest prawdą o tej ramce.
    negative_begin = frame.get("negative_begin")
    if negative_begin is None:
        negative_begin = sum(
            1 for raw in frame.get("events") or []
            if raw.get("b") is not None and raw["b"] < 0
        )

    for raw in frame.get("events") or []:
        tag, tag_fixed = normalize_name(raw.get("tag"))
        typo_hits += 1 if tag_fixed else 0

        rule = tag_rules.get(tag)
        if rule is None and tag is not None:
            unmapped_tags[tag] = unmapped_tags.get(tag, 0) + 1

        qualifiers = list(rule["qualifiers"]) if rule else []
        source_labels = []
        for label_raw in raw.get("labels") or []:
            label, label_fixed = normalize_name(label_raw)
            typo_hits += 1 if label_fixed else 0
            source_labels.append(label)
            # RÓWNOŚĆ, nie fragment — patrz nagłówek modułu.
            qualifier = label_rules.get(label)
            if qualifier is None:
                unmapped_labels[label] = unmapped_labels.get(label, 0) + 1
            elif qualifier not in qualifiers:
                qualifiers.append(qualifier)

        raw_team = raw.get("team")
        if raw_team is None:
            # `none` jest wartością poprawną i częstą — część tagów nie ma
            # przypisanej drużyny i trafia do osobnej sekcji raportu (pułapka 5).
            team_side = (rule or {}).get("team_side") or "none"
        else:
            teams_detected[raw_team] = teams_detected.get(raw_team, 0) + 1
            team_side = team_lookup.get(_norm_team(raw_team))
            if team_side is None:
                # Reguła profilu może kodować drużynę w samej nazwie tagu
                # (np. „STRZAŁ NASZA"), inaczej zostaje brak przypisania.
                team_side = (rule or {}).get("team_side") or "none"
                # Nazwa spoza konfiguracji to sygnał dla operatora, ale tylko
                # wtedy, gdy konfiguracja drużyn w ogóle była (`inspect` jej nie ma).
                if team_lookup and team_side == "none":
                    unknown_teams[raw_team] = unknown_teams.get(raw_team, 0) + 1

        begin = raw.get("b")

        # xG czytamy WYŁĄCZNIE ze zdarzeń mapujących się na `shot`. Parser wyciąga
        # z komentarza pierwszą liczbę, jaką znajdzie — przy innym tagu komentarz
        # „3 zawodników w polu karnym" dałby xG = 3.0 i zawyżył sumę bez śladu.
        # Wartość nie przepada po cichu: tag trafia do ostrzeżenia XG_POZA_STRZALEM.
        xg = raw.get("xg")
        if xg is not None and (rule or {}).get("concept") != "shot":
            xg_outside[tag] = xg_outside.get(tag, 0) + 1
            xg = None

        events.append({
            # `max(0, ...)` zostaje jako zabezpieczenie: parser już przycina (pułapka 10),
            # ale model kanoniczny bywa budowany też z ramek spoza parsera.
            "t_ms": int(round(max(0.0, begin) * 1000)) if begin is not None else None,
            "half": raw.get("half"),
            "team_side": team_side,
            "concept": rule["concept"] if rule else None,
            "qualifiers": qualifiers,
            # Pułapka 2: współrzędne są w metrach i już znormalizowane
            # kierunkowo w eksporcie. Nie lustrzyć.
            "x": raw.get("x"),
            "y": raw.get("y"),
            "x_end": raw.get("tx"),
            "y_end": raw.get("ty"),
            "xg": xg,
            "xg_source": "analyst" if xg is not None else None,
            # Silnik nie zna identyfikatorów z bazy (D4 — kontrakt to pliki, nie baza).
            # Warstwa indywidualna powstanie, gdy dane zawodnika w ogóle istnieją.
            "player_id": None,
            "source_tag": tag,
            "source_labels": source_labels,
            "confidence": 1.0 if rule else 0.0,
        })

    return {
        "events": events,
        "report": {
            "unmapped_tags": sorted(unmapped_tags),
            "unmapped_labels": sorted(unmapped_labels),
            "teams_detected": sorted(teams_detected),
            "unknown_teams": sorted(unknown_teams),
            "xg_outside_shot": {"count": sum(xg_outside.values()), "tags": sorted(xg_outside)},
            "typo_hits": typo_hits,
            "negative_begin": negative_begin,
            "profile_version": (mapping_profile or {}).get("version"),
        },
    }
