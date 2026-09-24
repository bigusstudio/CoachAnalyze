"""Raport pokrycia i ostrzeżenia -> meta.coverage / meta.warnings.

Każda niedostępna sekcja MUSI nieść powód po polsku — trafia wprost do interfejsu.

Kształt `meta.json` jest kontraktem z warstwą PHP: docs/KONTRAKT_CLI.md.
Zmiana kluczy tutaj wymaga zmiany dokumentu w tym samym commicie.
"""

from . import __version__
from . import report_template as tpl

# Sekcje raportu. Kolejność jest kolejnością prezentacji.
#
# ═══════════════════════════════════════════════════════════════════════════
# DWIE LISTY, BO TO DWA RÓŻNE PYTANIA (sesja 4b).
#
# `ALL_SECTIONS`      — co silnik W OGÓLE zna. Sekcja spoza tej listy dostaje
#                       „Sekcja nieznana silnikowi" i nie da się jej włączyć.
# `DOMYSLNE_SEKCJE`   — co widać W RAPORCIE BEZ TEMPLATU KLUBU.
#
# Do sesji 4b były tym samym i to działało, dopóki każda znana sekcja miała być
# domyślnie widoczna. Kafle przeniesione z magazynu v2 (donuty, okazje, tabela
# zawodników, siatka ilości) mają być DOSTĘPNE, ale nie mają wskakiwać do raportu
# każdego klubu bez niczyjej decyzji — dokładają płótna do i tak długiego raportu.
# Kreator sekcji (sesja 5) włącza je świadomie.
#
# Sekcje v21 (`przeglad`, `makro`, …) nie istnieją w szablonie v17 i to NIE JEST
# brak: `drop_sections` pomija identyfikator, którego w HTML-u nie znalazł.
# ═══════════════════════════════════════════════════════════════════════════
ALL_SECTIONS = (
    "przeglad", "makro", "bilans", "mapy", "donuty", "okazje",
    "tl_sbz", "tl_iii", "tl_bilans", "duels", "zawodnicy", "siatka", "noteam",
)

DOMYSLNE_SEKCJE = (
    "przeglad", "makro", "bilans", "mapy",
    "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam",
)

# Sekcje dołożone do rejestru W SESJI 4a/4b, czyli PO tym, jak powstały
# istniejące templaty klubów (`schema_version: 1`).
#
# PO CO TA LISTA: templat zapisuje „sekcje włączone". Sekcji, której w chwili
# zapisu nie było, nie ma na tej liście — i bez tego rozróżnienia wyglądałaby
# jak WYŁĄCZONA świadomie. Klub z templatem straciłby Przegląd, którego dziś
# używa, a jedynym śladem byłby brak sekcji w raporcie.
#
# Reguła: sekcja z tej listy, której templat schematu 1 nie wymienia, wraca do
# stanu DOMYŚLNEGO, a nie do „wyłączona". Templat schematu 2 (kreator sekcji,
# sesja 5) wymienia wszystko, co zna, więc reguła go nie dotyczy.
SEKCJE_PO_4B = ("przeglad", "makro", "donuty", "okazje", "zawodnicy", "siatka")


def sekcje_z_templatu(wybrane, template=None):
    """Sekcje templatu uzupełnione o te, których templat nie mógł znać."""
    schemat = int((template or {}).get("schema_version") or 1)
    if schemat >= 2:
        return list(wybrane)
    braki = [s for s in DOMYSLNE_SEKCJE if s in SEKCJE_PO_4B and s not in wybrane]
    return list(wybrane) + braki

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
        #
        # `xg_parsed` liczy WSZYSTKIE zdarzenia z xG, nie tylko strzały — tak samo
        # jak `xg_sum` niżej. Przy templacie ze zmienną bez pojęcia kanonicznego
        # strzałów w sensie kanonicznym NIE MA, a xG jest (sesja 1b); dwie różne
        # podstawy dałyby „xg_parsed 0" obok „xg_sum 4,40" w jednym raporcie
        # pokrycia. Bez templatu obie liczby są takie jak dotąd: xG niosą tam
        # wyłącznie strzały.
        "xg_parsed": len(xg_values),
        # `xg_missing` zostaje przy strzałach: „ile STRZAŁÓW nie ma xG" to pytanie
        # o kompletność tagowania i ma sens wyłącznie dla nich.
        "xg_missing": sum(1 for e in shots if e["xg"] is None),
        "xg_sum": round(sum(xg_values), 2) if xg_values else 0.0,
        "negative_begin": report["negative_begin"],
        # Komentarze, ktore wygladaly na xG i nie dalo sie ich odczytac.
        # Brak xG ma byc WIDOCZNY, nie zamaskowany zerem: „nie bylo xG"
        # i „bylo, ale nieczytelne" to dwie rozne rzeczy dla analityka.
        "xg_unparsed": report.get("xg_unparsed", 0),
        "has_json": bool(has_json),
        # Pułapka 4: brak warstwy indywidualnej, dopóki dane nie istnieją.
        "players_filled": len(players),
    }


def tag_stats(frame):
    """{surowa nazwa taga: {'count': n, 'with_pos': n}} — z ramki, nie z pojęć.

    Podstawa liczenia dostępności sekcji przy templacie. Idziemy po SUROWYCH
    nazwach, bo tak liczy raport (`e.tag==='STRZAŁ'` w szablonie).
    """
    stats = {}
    for e in frame.get("events") or []:
        tag = e.get("tag")
        if not tag:
            continue
        wpis = stats.setdefault(tag, {"count": 0, "with_pos": 0})
        wpis["count"] += 1
        if e.get("x") is not None and e.get("y") is not None:
            wpis["with_pos"] += 1
    return stats


def _powody_z_templatu(template, stats):
    """Powody niedostępności sekcji liczone z SUROWYCH TAGÓW templatu.

    ═══════════════════════════════════════════════════════════════════════════
    SESJA 1b — ODBIÓR SESJI 1 TEGO NIE PRZESZEDŁ.

    Dostępność liczyła się z pojęć kanonicznych (`coverage["shots"]`,
    `coverage["sbz"]`). Templat, w którym `STRZAŁ` i `ZDOBYCIE SBZ` mają
    `canon: null` — stan NORMALNY od sesji 1 (docs/STAN_PIVOTU.md §2.3) —
    dawał więc raport bez map i bez osi SBZ, z powodami „Brak zdarzeń ze
    współrzędnymi" i „Eksport nie zawiera zdarzeń zdobycia SBZ". Dane były,
    szablon v21 liczył je poprawnie, a `drop_sections` wycinało gotowy DOM.
    Uśpiona warstwa egzekwowała wycofaną regułę.

    Sekcja jest dostępna, gdy MA SWOJE ZDARZENIA — niezależnie od tego, czy
    ktoś nazwał je pojęciem kanonicznym.
    ═══════════════════════════════════════════════════════════════════════════
    """
    po_sekcjach = tpl.tags_by_section(template)
    reasons = {}

    def zdarzen(sekcja, klucz="count"):
        return sum(stats.get(tag, {}).get(klucz, 0) for tag in po_sekcjach.get(sekcja, ()))

    # MAPY wymagają WSPÓŁRZĘDNYCH, nie samych zdarzeń: sekcja bez `pos_*`
    # narysowałaby puste boisko, a to wygląda jak zero zdarzeń (§7.8).
    if not zdarzen("mapy", "with_pos"):
        reasons["mapy"] = (
            "Brak zdarzeń ze współrzędnymi — mapy wymagają kolumn "
            "pos_x_meters i pos_y_meters"
        )

    if not zdarzen("tl_sbz"):
        reasons["tl_sbz"] = "Żadna zmienna tej sekcji nie ma zdarzeń w tym eksporcie"

    # POJEDYNKI TĄ SAMĄ MIARĄ. Dopisane po sesji 1b: `duels` liczyło się dalej
    # z pojęcia `duel`, więc przy templacie bez pojęć sekcja znikała dokładnie
    # tak, jak znikały mapy i oś SBZ. Ta sama usterka, ten sam powód, ta sama
    # naprawa — zostawiona wtedy poza zakresem i domknięta osobno.
    if not zdarzen("duels"):
        reasons["duels"] = "Żadna zmienna tej sekcji nie ma zdarzeń w tym eksporcie"

    # III strefa zachowuje ROZRÓŻNIENIE Z PUŁAPKI 3: brak zdarzeń to co innego
    # niż zdarzenia bez pozycji, i operator ma widzieć, które z dwojga.
    if not zdarzen("tl_iii"):
        reasons["tl_iii"] = "Żadna zmienna tej sekcji nie ma zdarzeń w tym eksporcie"
    elif not zdarzen("tl_iii", "with_pos"):
        reasons["tl_iii"] = "Eksport nie zawiera pozycji III STREFY (kolumny pos_* puste)"

    return reasons


def build_sections(coverage, requested=None, template=None, frame=None):
    """(dostępne, niedostępne). Każdy brak niesie powód po polsku.

    Powód trafia bez zmian do interfejsu — analityk ma zobaczyć, czego brakuje
    i dlaczego, a nie pustą sekcję.

    PRZY TEMPLACIE dostępność `mapy`, `tl_sbz` i `tl_iii` liczy się z SUROWYCH
    TAGÓW zmiennych przypisanych do tych sekcji, a nie z pojęć kanonicznych —
    patrz `_powody_z_templatu`. Bez templatu ścieżka zostaje NIETKNIĘTA: wyjście
    ma być bajt w bajt takie jak dotąd i na tym stoi test złoty.
    """
    reasons = {}
    z_templatu = template is not None and frame is not None

    if not coverage["events"]:
        reasons["bilans"] = "Eksport nie zawiera żadnych zdarzeń"
        reasons["tl_bilans"] = reasons["bilans"]
        # Kafle liczące z tych samych zdarzeń milkną z tego samego powodu.
        # Powtórzony powód jest lepszy niż sekcja, która wyszła pusta bez słowa.
        for sekcja in ("przeglad", "makro", "donuty", "okazje", "siatka"):
            reasons[sekcja] = reasons["bilans"]

    if z_templatu:
        reasons.update(_powody_z_templatu(template, tag_stats(frame)))
    else:
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

        # POJEDYNKI PO POJĘCIU — wyłącznie na ścieżce BEZ templatu. Z templatem
        # rozstrzyga `_powody_z_templatu`, po surowych tagach.
        if not coverage["duels"]:
            reasons["duels"] = "Eksport nie zawiera pojedynków (1x1, pierwszy kontakt)"

    # `noteam` zostaje wspólne dla obu ścieżek i to jest poprawne: liczy się
    # z SUROWEGO pola `team` (`coverage["no_team"]`), a nie z żadnego pojęcia,
    # więc templat nie ma tu czego zmieniać.
    if not coverage["no_team"]:
        reasons["noteam"] = (
            "Wszystkie zdarzenia mają przypisaną drużynę — sekcja bez przypisania byłaby pusta"
        )

    sections = list(requested) if requested else list(DOMYSLNE_SEKCJE)

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


def ostrzezenie_kierunku(direction):
    """`KIERUNEK_NIEPEWNY` albo `None`.

    Trzy powody, jeden kod: miary pokazały różne strony, obie drużyny wyszły po
    tej samej stronie, albo kierunek wziął się z miary kontrolnej, bo strzałów
    nie starczyło. We wszystkich trzech raport powstaje normalnie — ostrzeżenie
    mówi tylko, że mapy mogą być odbite.

    BRAK KIERUNKU (`confidence: none`) NIE JEST OSTRZEŻENIEM. Eksport bez pozycji
    to stan normalny (pułapka 3), mapy i tak nie powstaną, a ostrzeżenie o stanie
    normalnym uczy ignorować ostrzeżenia.
    """
    if not direction or direction.get("confidence") != "low":
        return None
    sprzecznosci = direction.get("conflicts") or []
    powod = ("miary wskazały różne strony ({})".format(", ".join(sprzecznosci))
             if sprzecznosci else "kierunek wyprowadzony z miary kontrolnej, nie ze strzałów")
    return {
        "code": "KIERUNEK_NIEPEWNY",
        "msg": (
            "Kierunek ataku ustalony z zastrzeżeniem — {}. Mapy mogą być odbite; "
            "kierunek i dowody są w `meta.direction`".format(powod)
        ),
        "count": 1,
    }


def build_warnings(frame, canon_result, has_json=False, palette=None, direction=None):
    """Ostrzeżenia z licznikiem wystąpień. Kolejność stała — wyjście ma być powtarzalne."""
    report = canon_result["report"]
    warnings = []

    kierunek = ostrzezenie_kierunku(direction)
    if kierunek is not None:
        warnings.append(kierunek)

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

    if report.get("xg_unparsed"):
        warnings.append({
            "code": "XG_NIECZYTELNE",
            "msg": (
                "Komentarz wygląda na xG, ale nie da się go odczytać jako liczby "
                "— xG pominięte dla tych zdarzeń"
            ),
            "count": report["xg_unparsed"],
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


def build_meta(frame, canon_result, config=None, has_json=False, palette=None, ok=True,
               report_template=None, direction=None, mirrored=False):
    """Pełny `meta.json` zgodny z docs/KONTRAKT_CLI.md.

    `report_template` jest OPCJONALNY i bez niego nic się nie zmienia. Z nim
    dostępność sekcji liczy się z surowych tagów templatu, a nie z pojęć
    kanonicznych (sesja 1b).
    """
    config = config or {}

    coverage = build_coverage(frame, canon_result, has_json=has_json)
    available, unavailable = build_sections(
        coverage, config.get("sections"), template=report_template, frame=frame
    )

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
        "warnings": build_warnings(
            frame, canon_result, has_json=has_json, palette=palette, direction=direction
        ),
        # KIERUNEK ATAKU ODCZYTANY Z DANYCH — sprzed ewentualnego odbicia.
        # Razem z `mirrored` odpowiada na pytanie „czy ta mapa jest odbita"
        # inaczej niż przez ponowne policzenie median (docs/STAN_PIVOTU.md §7.7 c).
        "direction": direction,
        "mirrored": bool(mirrored),
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
        # PALETA Z PLIKU PROJEKTU LiveTag — barwy tablicy kodowej, po korekcie
        # jasnosci (`to_hex`). Do tej pory byla wylacznie WEJSCIEM do ostrzezen
        # i nigdzie nie wychodzila, wiec konfigurator raportu nie mial jak
        # zaproponowac barw zmiennych i schodzil na barwy klubu.
        #
        # PHP NIE MOZE JEJ POLICZYC SAM: uruchomienie silnika z warstwy zadan
        # blokuje `disable_functions`, a przepisanie `to_hex` byloby przeniesieniem
        # arytmetyki koloru do PHP (pilnuje tego `app/tests/integracja/test_4b.php`).
        #
        # `None` przy imporcie bez pliku projektu — to poprawny stan, nie brak
        # danych do uzupelnienia. Konfigurator schodzi wtedy na barwy klubu.
        "palette": palette,
    }
