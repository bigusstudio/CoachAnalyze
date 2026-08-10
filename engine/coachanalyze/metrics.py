"""canonical_events[] -> pakiet metryk.

Jedyne miejsce, w którym liczą się metryki piłkarskie.
Pakiet z tego modułu jest kontekstem dla warstwy AI (D5: model opisuje, nie liczy).

Moduł czyta WYŁĄCZNIE pojęcia i kwalifikatory kanoniczne (`concept`, `qualifiers`),
nigdy `source_tag` ani `source_labels`. Nazwa tagu w eksporcie jest własnością klubu
i zmienia się między przejściami tagowania — liczenie po niej wróciłoby dokładnie
do problemu, dla którego istnieje model kanoniczny (D4).

Rozpoznawanie kwalifikatorów przez RÓWNOŚĆ na gotowej liście, nigdy przez fragment
tekstu (pułapka 7). W tym module nie ma i nie może się pojawić `in` na treści
pojedynczego kwalifikatora.

Brak danych zostaje brakiem: `_pct` zwraca `None` przy zerowym mianowniku, a nie 0.0.
„0% wygranych pojedynków" i „nie było pojedynków" to dwa różne zdania i raport nie ma
prawa ich mylić (CLAUDE.md §8).
"""

from . import __version__

SIDES = ("us", "them", "none")
HALVES = (1, 2)

# Kolejność ma znaczenie: pierwszy pasujący kwalifikator wygrywa. Odwzorowuje
# priorytet z szablonu raportu (celny > zablokowany > niecelny).
SHOT_OUTCOMES = ("on_target", "blocked", "off_target")

# Sposób zbudowania akcji. `set_piece` to SFG — stały fragment gry.
CONTEXTS = ("positional", "counter", "after_press", "set_piece")

# Następstwo wyprowadzenia po pressingu — kwalifikatory z etykiet tagów SKUTECZNY/NISKUTECZNY.
PRESS_OUTCOMES = ("entry_sbz", "shot_from_sbz", "possession", "loss", "other")


def _has(event, qualifier):
    """Kwalifikator obecny na liście. Równość całego napisu — patrz nagłówek modułu."""
    return qualifier in (event.get("qualifiers") or ())


def _pct(part, total):
    """Udział procentowy albo None przy pustym mianowniku.

    Zera nie podstawiamy: 0% to wynik, brak zdarzeń to brak danych. Warstwa AI
    dostaje `None` i ma o czym milczeć, zamiast opisywać „zerową skuteczność".
    """
    if not total:
        return None
    return round(part / total * 100.0, 1)


def _sum_xg(events):
    """Suma xG. Zaokrąglenie na końcu, nie na każdym składniku."""
    values = [e["xg"] for e in events if e.get("xg") is not None]
    return round(sum(values), 2) if values else 0.0


def _of_concept(events, concept):
    return [e for e in events if e.get("concept") == concept]


def _count(events, qualifier):
    return sum(1 for e in events if _has(e, qualifier))


def _outcome_of(event):
    """Wynik strzału albo `unknown`.

    UWAGA — świadoma różnica wobec szablonu raportu: szablon traktuje brak etykiety
    wyniku jako NIECELNY (`outcomeOf` kończy się domyślnym 'NIECELNY'). Silnik
    regułowy tego nie robi, bo domyślanie się wyniku strzału jest zmyślaniem danych.
    Strzał bez etykiety wyniku wpada do `unknown` i widać go w pakiecie.
    """
    for outcome in SHOT_OUTCOMES:
        if _has(event, outcome):
            return outcome
    return "unknown"


def _context_of(event):
    """Sposób zbudowania akcji albo `none`, gdy żadna etykieta go nie niesie."""
    for context in CONTEXTS:
        if _has(event, context):
            return context
    return "none"


def _group_by_context(events):
    """Rozbicie na sposoby zbudowania akcji. Klucze zawsze te same — także z zerami.

    Stały zestaw kluczy, bo pakiet jest porównywany między meczami (moduł M4):
    znikający klucz i zero to dwie różne rzeczy przy porównaniu sezonowym.
    """
    groups = {}
    for key in CONTEXTS + ("none",):
        group = [e for e in events if _context_of(e) == key]
        groups[key] = {
            "events": len(group),
            "xg_sum": _sum_xg(group),
        }
    return groups


def _shots_block(events):
    shots = _of_concept(events, "shot")
    outcomes = {key: 0 for key in SHOT_OUTCOMES + ("unknown",)}
    for shot in shots:
        outcomes[_outcome_of(shot)] += 1

    with_xg = [e for e in shots if e.get("xg") is not None]
    xg_sum = _sum_xg(shots)

    return {
        "total": len(shots),
        "on_target": outcomes["on_target"],
        "off_target": outcomes["off_target"],
        "blocked": outcomes["blocked"],
        "outcome_unknown": outcomes["unknown"],
        "goals": _count(shots, "goal"),
        "on_target_pct": _pct(outcomes["on_target"], len(shots)),
        "xg_sum": xg_sum,
        "xg_parsed": len(with_xg),
        "xg_missing": len(shots) - len(with_xg),
        # Dzielimy przez strzały Z policzonym xG, nie przez wszystkie: strzał bez xG
        # zaniżyłby średnią tak, jakby miał xG = 0.
        "xg_per_shot": round(xg_sum / len(with_xg), 3) if with_xg else None,
        "by_context": _group_by_context(shots),
    }


def _sbz_block(events):
    """Zdobycia SBZ — strefa bezpośredniego zagrożenia (terminologia klienta, §6)."""
    entries = _of_concept(events, "entry_sbz")
    with_shot = _count(entries, "with_shot")
    without_shot = _count(entries, "without_shot")

    by_context = _group_by_context(entries)
    for key in by_context:
        group = [e for e in entries if _context_of(e) == key]
        shots_in_group = _count(group, "with_shot")
        by_context[key]["with_shot"] = shots_in_group
        by_context[key]["shot_pct"] = _pct(shots_in_group, len(group))

    return {
        "total": len(entries),
        "with_shot": with_shot,
        "without_shot": without_shot,
        # Wejście bez żadnej z dwóch etykiet — ani „ze strzałem", ani „bez strzału".
        "outcome_unknown": len(entries) - with_shot - without_shot,
        "shot_pct": _pct(with_shot, len(entries)),
        # Pułapka 2: wektor wejścia to punkt docelowy w metrach, bez lustrzenia.
        "with_vector": sum(1 for e in entries if e.get("x_end") is not None),
        "by_context": by_context,
    }


def _third_block(events):
    """Wejścia w III strefę."""
    entries = _of_concept(events, "entry_third")
    successful = _count(entries, "successful")
    unsuccessful = _count(entries, "unsuccessful")

    return {
        "total": len(entries),
        "successful": successful,
        "unsuccessful": unsuccessful,
        # Ani udana, ani nieudana — w raporcie klienta „brak podania".
        "no_pass": len(entries) - successful - unsuccessful,
        "success_pct": _pct(successful, len(entries)),
        # Pułapka 3: III STREFA bywa bez współrzędnych. Zero wyłącza sekcję mapy.
        "with_position": sum(1 for e in entries if e.get("x") is not None),
    }


def _won_lost(events):
    won = _count(events, "won")
    lost = _count(events, "lost")
    return {
        "total": len(events),
        "won": won,
        "lost": lost,
        "outcome_unknown": len(events) - won - lost,
        "win_pct": _pct(won, len(events)),
    }


def _duels_block(events):
    duels = _of_concept(events, "duel")
    offensive = [e for e in duels if _has(e, "offensive")]
    defensive = [e for e in duels if _has(e, "defensive")]
    first_contact = [e for e in duels if _has(e, "first_contact")]

    won_first = [e for e in first_contact if _has(e, "won")]
    block = {
        "total": len(duels),
        "offensive": _won_lost(offensive),
        "defensive": _won_lost(defensive),
        "first_contact": _won_lost(first_contact),
    }
    # Rozwinięcie wygranego pierwszego kontaktu: utrzymanie piłki kontra strata.
    block["first_contact"]["after_won"] = {
        "second_contact": _count(won_first, "second_contact"),
        "possession": _count(won_first, "possession"),
        "loss": _count(won_first, "loss"),
    }
    return block


def _halves_of_pitch(events):
    """Rozkład na strony boiska. `unknown` — etykieta strony nie została nadana."""
    own = _count(events, "own_half")
    opp = _count(events, "opp_half")
    return {
        "own_half": own,
        "opp_half": opp,
        "half_unknown": len(events) - own - opp,
    }


def _losses_block(events):
    losses = _of_concept(events, "loss")
    reaction = _count(losses, "reaction")
    no_reaction = _count(losses, "no_reaction")

    block = {
        "total": len(losses),
        "with_reaction": reaction,
        "without_reaction": no_reaction,
        "reaction_unknown": len(losses) - reaction - no_reaction,
        "reaction_pct": _pct(reaction, len(losses)),
    }
    block.update(_halves_of_pitch(losses))
    return block


def _recoveries_block(events):
    """Odbiory — terminologia klienta (§6)."""
    recoveries = _of_concept(events, "recovery")
    block = {"total": len(recoveries)}
    block.update(_halves_of_pitch(recoveries))
    block["opp_half_pct"] = _pct(block["opp_half"], len(recoveries))
    return block


def _press_block(events):
    """Wysoki pressing: skuteczność wyprowadzenia po odbiorze."""
    press = _of_concept(events, "press")
    effective = [e for e in press if _has(e, "effective")]
    ineffective = [e for e in press if _has(e, "ineffective")]

    return {
        "total": len(press),
        "effective": len(effective),
        "ineffective": len(ineffective),
        "outcome_unknown": len(press) - len(effective) - len(ineffective),
        "effective_pct": _pct(len(effective), len(press)),
        "outcomes": {key: _count(press, key) for key in PRESS_OUTCOMES},
    }


def side_block(events):
    """Komplet metryk dla jednego zbioru zdarzeń (strona, połowa albo cały mecz)."""
    return {
        "events": len(events),
        "shots": _shots_block(events),
        "entry_sbz": _sbz_block(events),
        "entry_third": _third_block(events),
        "duels": _duels_block(events),
        "losses": _losses_block(events),
        "recoveries": _recoveries_block(events),
        "press": _press_block(events),
    }


def build(canon_result, meta=None):
    """canonical_events[] -> pakiet metryk.

    `meta` jest opcjonalne i służy wyłącznie przepisaniu czasu meczu do pakietu —
    metryki liczą się ze zdarzeń kanonicznych, nigdy z raportu pokrycia.

    Podział na `us` / `them` / `none` jest kompletny: `none` to zdarzenia bez
    przypisanej drużyny (pułapka 5) i jest to grupa liczna, nie odpad. W eksportach
    referencyjnych trafia do niej większość pojedynków, strat i odbiorów.
    """
    events = canon_result["events"]
    report = canon_result.get("report") or {}
    meta = meta or {}

    by_side = {}
    for side in SIDES:
        by_side[side] = side_block([e for e in events if e.get("team_side") == side])

    by_half = {}
    for half in HALVES:
        half_events = [e for e in events if e.get("half") == half]
        by_half[str(half)] = {
            side: side_block([e for e in half_events if e.get("team_side") == side])
            for side in SIDES
        }

    mapped = sum(1 for e in events if e.get("concept") is not None)

    return {
        "engine_version": __version__,
        "profile_version": report.get("profile_version"),
        "totals": {
            "events": len(events),
            # Zdarzenia z tagiem bez reguły w profilu. NIE znikają z modelu,
            # ale nie wchodzą do żadnej metryki — i to musi być widoczne.
            "mapped": mapped,
            "unmapped": len(events) - mapped,
            "unmapped_tags": report.get("unmapped_tags") or [],
            "half_split_ms": meta.get("half_split_ms"),
            "duration_ms": meta.get("duration_ms"),
        },
        "sides": by_side,
        "halves": by_half,
    }
