"""Pakiet metryk — silnik regułowy (D5: model językowy opisuje, nigdy nie liczy).

Testy na danych syntetycznych, żeby działały bez eksportów klienta.
Sprawdzają trzy rzeczy, na których stoi wiarygodność pakietu:

1. Metryki liczą się z pojęć kanonicznych, nie z nazw tagów klienta.
2. Brak danych zostaje brakiem (`None`), a nie zerem.
3. Podział na strony i połowy jest ROZŁĄCZNY I ZUPEŁNY — żadne zdarzenie nie ginie
   i żadne nie liczy się dwa razy.
"""

from coachanalyze import canon, metrics


def zdarzenie(tag, labels=(), team=None, b=10.0, half=1, xg=None, x=None, tx=None):
    return {"tag": tag, "b": b, "e": b + 5, "team": team, "labels": list(labels),
            "xg": xg, "x": x, "y": None, "tx": tx, "ty": None, "half": half}


def pakiet(zdarzenia, teams=None, mapping_profile=None):
    frame = {"events": list(zdarzenia), "half_split": 2700.0}
    return metrics.build(canon.build(frame, teams=teams, mapping_profile=mapping_profile))


TEAMS = {"us": {"name": "NASZA"}, "them": {"name": "RYWAL"}}


# ------------------------------------------------------------------ brak danych
def test_procent_przy_zerowym_mianowniku_to_none_a_nie_zero():
    """„0% wygranych" i „nie było pojedynków" to dwa różne zdania (CLAUDE.md §8)."""
    wynik = pakiet([])["sides"]["none"]

    assert wynik["duels"]["offensive"]["win_pct"] is None
    assert wynik["losses"]["reaction_pct"] is None
    assert wynik["shots"]["on_target_pct"] is None
    assert wynik["shots"]["xg_per_shot"] is None


def test_strzal_bez_etykiety_wyniku_nie_jest_zliczany_jako_niecelny():
    """Świadoma różnica wobec szablonu, który domyśla się NIECELNY.

    Domyślanie się wyniku strzału jest zmyślaniem danych. Strzał bez etykiety
    ma być widoczny jako `outcome_unknown`.
    """
    wynik = pakiet([
        zdarzenie("STRZAŁ", ["CELNY"]),
        zdarzenie("STRZAŁ"),
    ])["sides"]["none"]["shots"]

    assert wynik["total"] == 2
    assert wynik["on_target"] == 1
    assert wynik["off_target"] == 0
    assert wynik["outcome_unknown"] == 1


def test_xg_na_strzal_liczone_po_strzalach_z_xg():
    """Strzał bez xG nie ma prawa zaniżać średniej, jakby miał xG = 0."""
    wynik = pakiet([
        zdarzenie("STRZAŁ", ["CELNY"], xg=0.4),
        zdarzenie("STRZAŁ", ["NIECELNY"]),
    ])["sides"]["none"]["shots"]

    assert wynik["xg_sum"] == 0.4
    assert wynik["xg_parsed"] == 1
    assert wynik["xg_missing"] == 1
    assert wynik["xg_per_shot"] == 0.4


# ------------------------------------------------------------------ model, nie tagi
def test_metryki_licza_po_pojeciu_a_nie_po_nazwie_tagu():
    """Klub nazywa tag po swojemu; profil mapuje go na pojęcie — metryka ma to widzieć."""
    profil = {"version": 2, "rules": [
        {"match": {"tag": "STRZAŁ NASZA"}, "concept": "shot", "team_side": "us"},
    ]}
    wynik = pakiet([zdarzenie("STRZAŁ NASZA", ["CELNY"], xg=0.2)], mapping_profile=profil)

    assert wynik["sides"]["us"]["shots"]["total"] == 1
    assert wynik["profile_version"] == 2


def test_tag_bez_mapowania_nie_wchodzi_do_metryk_ale_jest_policzony():
    """`AKCJA DEFENSYWNA` z eksportów referencyjnych — zdarzenie zostaje, metryka nie."""
    wynik = pakiet([zdarzenie("AKCJA DEFENSYWNA"), zdarzenie("STRZAŁ", ["CELNY"])])

    assert wynik["totals"] == dict(
        wynik["totals"], events=2, mapped=1, unmapped=1,
        unmapped_tags=["AKCJA DEFENSYWNA"],
    )
    assert wynik["sides"]["none"]["shots"]["total"] == 1


# ------------------------------------------------------------------ rozłączność
def test_podzial_na_strony_jest_rozlaczny_i_zupelny():
    wynik = pakiet([
        zdarzenie("STRZAŁ", ["CELNY"], team="NASZA"),
        zdarzenie("STRZAŁ", ["CELNY"], team="RYWAL"),
        zdarzenie("STRATA", ["REAKCJA"]),
    ], teams=TEAMS)

    suma = sum(wynik["sides"][s]["events"] for s in metrics.SIDES)
    assert suma == wynik["totals"]["events"] == 3
    assert wynik["sides"]["us"]["events"] == 1
    assert wynik["sides"]["them"]["events"] == 1
    assert wynik["sides"]["none"]["events"] == 1


def test_podzial_na_polowy_jest_rozlaczny_i_zupelny():
    wynik = pakiet([
        zdarzenie("STRZAŁ", ["CELNY"], team="NASZA", half=1),
        zdarzenie("STRZAŁ", ["NIECELNY"], team="NASZA", half=2),
        zdarzenie("STRZAŁ", ["CELNY"], team="NASZA", half=2),
    ], teams=TEAMS)

    p1 = wynik["halves"]["1"]["us"]["shots"]
    p2 = wynik["halves"]["2"]["us"]["shots"]

    assert p1["total"] == 1 and p2["total"] == 2
    assert p1["total"] + p2["total"] == wynik["sides"]["us"]["shots"]["total"]


def test_klucze_rozbicia_na_typ_akcji_sa_stale():
    """Znikający klucz i zero to dwie różne rzeczy przy porównaniu sezonowym (M4)."""
    wynik = pakiet([zdarzenie("STRZAŁ", ["CELNY", "KONTRATAK"], xg=0.3)])
    rozbicie = wynik["sides"]["none"]["shots"]["by_context"]

    assert list(rozbicie) == list(metrics.CONTEXTS) + ["none"]
    assert rozbicie["counter"] == {"events": 1, "xg_sum": 0.3}
    assert rozbicie["positional"] == {"events": 0, "xg_sum": 0.0}


# ------------------------------------------------------------------ pojęcia klienta
def test_skutecznosc_sbz():
    wynik = pakiet([
        zdarzenie("ZDOBYCIE SBZ", ["POZYCYJNIE", "STRZAŁ"], tx=95.0),
        zdarzenie("ZDOBYCIE SBZ", ["POZYCYJNIE", "BRAK STRZAŁU"]),
        zdarzenie("ZDOBYCIE SBZ", ["KONTRATAK", "STRZAŁ"]),
    ])["sides"]["none"]["entry_sbz"]

    assert wynik["total"] == 3
    assert wynik["with_shot"] == 2
    assert wynik["shot_pct"] == 66.7
    assert wynik["with_vector"] == 1
    assert wynik["by_context"]["positional"]["shot_pct"] == 50.0


def test_iii_strefa_rozroznia_brak_podania():
    """Pułapka 3 i terminologia klienta: ani udana, ani nieudana to „brak podania"."""
    wynik = pakiet([
        zdarzenie("III STREFA", ["UDANA"], x=70.0),
        zdarzenie("III STREFA", ["NIEUDANA"]),
        zdarzenie("III STREFA", []),
    ])["sides"]["none"]["entry_third"]

    assert (wynik["successful"], wynik["unsuccessful"], wynik["no_pass"]) == (1, 1, 1)
    assert wynik["success_pct"] == 33.3
    assert wynik["with_position"] == 1


def test_pierwszy_kontakt_ma_rozwiniecie_wygranych():
    wynik = pakiet([
        zdarzenie("PIERWSZY KONTAKT", ["WYGRANY", "DRUGI KONTAKT"]),
        zdarzenie("PIERWSZY KONTAKT", ["WYGRANY", "STRATA"]),
        zdarzenie("PIERWSZY KONTAKT", ["PRZEGRANY"]),
    ])["sides"]["none"]["duels"]["first_contact"]

    assert wynik["won"] == 2 and wynik["lost"] == 1
    assert wynik["win_pct"] == 66.7
    assert wynik["after_won"] == {"second_contact": 1, "possession": 0, "loss": 1}


def test_straty_dziela_sie_na_strony_boiska_i_reakcje():
    wynik = pakiet([
        zdarzenie("STRATA", ["ICH POŁOWA", "REAKCJA"]),
        zdarzenie("STRATA", ["NASZA POŁOWA", "BRAK REAKCJI"]),
        zdarzenie("STRATA", ["MASZA POŁOWA", "REAKCJA"]),
    ])["sides"]["none"]["losses"]

    assert wynik["total"] == 3
    # Pułapka 9: literówka zmapowana na NASZA POŁOWA jeszcze w modelu kanonicznym.
    assert wynik["own_half"] == 2 and wynik["opp_half"] == 1
    assert wynik["with_reaction"] == 2
    assert wynik["reaction_pct"] == 66.7


def test_pressing_ma_rozbicie_wyprowadzenia():
    wynik = pakiet([
        zdarzenie("SKUTECZNY", ["ZDOBYCIE SBZ"]),
        zdarzenie("SKUTECZNY", ["POSIADANIE"]),
        zdarzenie("NISKUTECZNY", ["STRATA"]),
    ])["sides"]["none"]["press"]

    assert wynik["total"] == 3
    assert wynik["effective"] == 2 and wynik["ineffective"] == 1
    assert wynik["effective_pct"] == 66.7
    assert wynik["outcomes"]["entry_sbz"] == 1
    assert wynik["outcomes"]["possession"] == 1
    assert wynik["outcomes"]["loss"] == 1


def test_pojedynki_dziela_sie_na_ofensywne_i_defensywne():
    wynik = pakiet([
        zdarzenie("1x1 OFF", ["WYGRANY"]),
        zdarzenie("1x1 OFF", ["PRZEGRANY"]),
        zdarzenie("1x1 DEF.", ["WYGRANY"]),
        zdarzenie("PIERWSZY KONTAKT", ["WYGRANY"]),
    ])["sides"]["none"]["duels"]

    assert wynik["total"] == 4
    assert wynik["offensive"]["total"] == 2 and wynik["offensive"]["win_pct"] == 50.0
    assert wynik["defensive"]["total"] == 1
    assert wynik["first_contact"]["total"] == 1


def test_pakiet_niesie_wersje_silnika():
    """Pytanie „dlaczego raport z marca pokazuje inną liczbę" ma mieć odpowiedź (§7)."""
    from coachanalyze import __version__

    assert pakiet([])["engine_version"] == __version__
