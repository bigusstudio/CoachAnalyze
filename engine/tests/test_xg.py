"""Model xG (M3) — regresja logistyczna, osobne modele, bramka odbioru.

Najważniejszy test tego modułu to NIE wartości modelu, tylko to, że jego
istnienie niczego nie zmienia bez jawnego włączenia — wprowadzenie modelu
nie może zmienić żadnej liczby w istniejących raportach.
"""

import math

from coachanalyze import canon, coverage, xg
from coachanalyze.sources.livetag import parse


def frame(*events):
    return {"events": list(events), "half_split": 2733.6}


def event(**kw):
    base = {"tag": "STRZAŁ", "b": 1.0, "e": 2.0, "team": None, "labels": [],
            "xg": None, "x": None, "y": None, "tx": None, "ty": None, "half": 1}
    base.update(kw)
    return base


# ------------------------------------------------------------ geometria i modele
def test_xg_maleje_z_odlegloscia():
    blisko = xg.estimate(99.0, 34.0, ["positional"])["xg"]
    srednio = xg.estimate(89.0, 34.0, ["positional"])["xg"]
    daleko = xg.estimate(75.0, 34.0, ["positional"])["xg"]
    assert blisko > srednio > daleko
    assert daleko < 0.05, "strzał z 30 m nie może wyglądać na sytuację bramkową"


def test_kat_widzenia_bramki_z_atan2():
    """Na wprost bramki kąt większy niż z boku; wartość zgodna z geometrią."""
    import pytest

    na_wprost = xg.goal_angle(94.0, 34.0)
    z_boku = xg.goal_angle(94.0, 14.0)
    assert na_wprost > z_boku
    assert na_wprost == pytest.approx(2 * math.atan(xg.GOAL_HALF_WIDTH / 11.0))


def test_glowka_nizej_niz_noga_z_tej_samej_pozycji():
    noga = xg.estimate(97.0, 34.0, ["positional"])["xg"]
    glowka = xg.estimate(97.0, 34.0, ["header"])["xg"]
    assert glowka < noga, "kara za główkę (−1,2946) musi być widoczna"


def test_karny_to_stala_niezalezna_od_pozycji():
    """Wszystkie cechy karnego są identyczne — liczenie z odległości dałoby bzdurę."""
    a = xg.estimate(94.0, 34.0, ["penalty"])
    b = xg.estimate(50.0, 10.0, ["penalty"])
    assert a["xg"] == b["xg"] == xg.PENALTY_XG


def test_wolny_ma_wlasne_wspolczynniki():
    wolny = xg.estimate(83.0, 34.0, ["set_piece"])
    otwarta = xg.estimate(83.0, 34.0, ["positional"])
    assert wolny["model"] == "free_kick"
    assert wolny["xg"] != otwarta["xg"]
    assert 0.02 <= wolny["xg"] <= 0.12, "bezpośredni wolny z ~22 m to okolice 0,06"


def test_nieznany_rodzaj_to_gra_otwarta_z_odnotowanym_zalozeniem():
    wynik = xg.estimate(90.0, 30.0, [])
    assert wynik["model"] == "open_foot"
    assert wynik["assumed"] is True
    rozpoznany = xg.estimate(90.0, 30.0, ["counter"])
    assert rozpoznany["assumed"] is False


def test_brak_wspolrzednych_daje_none_nie_zero():
    assert xg.estimate(None, 34.0, ["positional"]) is None, (
        "brak danych ma być widoczny, nie zamaskowany zmyśloną liczbą"
    )


# ------------------------------------------------------------ bramka odbioru
def test_domyslnie_wylaczony_niczego_nie_zmienia():
    """BRAMKA ODBIORU: bez opt-in wynik identyczny jak przed wprowadzeniem modelu."""
    ramka = frame(event(x=88.0, y=31.0), event(x=90.0, y=30.0, xg=0.5))
    bez = canon.build(ramka)

    assert bez["events"][0]["xg"] is None
    assert bez["events"][0]["xg_source"] is None
    assert bez["report"]["xg_model"] == {"filled": 0, "assumed": 0}

    meta = coverage.build_meta(ramka, bez)
    assert all(w["code"] != "XG_MODEL" for w in meta["warnings"])


def test_wartosc_analityka_ma_bezwzgledne_pierwszenstwo():
    ramka = frame(event(x=99.0, y=34.0, xg=0.07))
    wynik = canon.build(ramka, xg_model=True)
    assert wynik["events"][0]["xg"] == 0.07, "model NIGDY nie nadpisuje analityka"
    assert wynik["events"][0]["xg_source"] == "analyst"
    assert wynik["report"]["xg_model"]["filled"] == 0


def test_model_uzupelnia_brak_i_oznacza_zrodlo():
    ramka = frame(event(x=99.0, y=34.0), event(x=90.0, y=30.0, xg=0.5))
    wynik = canon.build(ramka, xg_model=True)

    uzupelniony = wynik["events"][0]
    assert uzupelniony["xg"] is not None and uzupelniony["xg"] > 0
    assert uzupelniony["xg_source"] == "model"
    assert wynik["report"]["xg_model"]["filled"] == 1

    meta = coverage.build_meta(ramka, wynik)
    ostrzezenia = {w["code"]: w for w in meta["warnings"]}
    assert "XG_MODEL" in ostrzezenia
    assert ostrzezenia["XG_MODEL"]["count"] == 1


def test_model_nie_dotyka_zdarzen_bez_wspolrzednych_ani_nie_strzalow():
    ramka = frame(event(x=None, y=None), event(tag="STRATA", x=50.0, y=30.0))
    wynik = canon.build(ramka, xg_model=True)
    assert wynik["events"][0]["xg"] is None
    assert wynik["events"][1]["xg"] is None
    assert wynik["report"]["xg_model"]["filled"] == 0


# ------------------------------------------------------------ siatka dla PHP
def test_siatka_zgadza_sie_z_modelem():
    siatka = xg.grid(step=1.0)
    assert siatka["penalty"] == xg.PENALTY_XG
    assert set(siatka["models"]) == {"free_kick", "open_foot", "open_head"}
    assert len(siatka["models"]["open_foot"]) == 68
    assert len(siatka["models"]["open_foot"][0]) == 105

    # Wartość komórki = xG jej środka, ten sam kod liczący.
    assert siatka["models"]["open_foot"][33][98] == xg.estimate(98.5, 33.5, ["open_play"])["xg"]
    assert siatka["models"]["open_head"][33][98] == xg.estimate(98.5, 33.5, ["header"])["xg"]
