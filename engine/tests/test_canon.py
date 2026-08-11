"""Model kanoniczny — docs/MODEL_KANONICZNY.md."""

import pytest

from coachanalyze import canon


def frame(*events):
    return {"events": list(events), "half_split": 0.0}


def event(tag="STRZAŁ", labels=None, team=None, b=0.0, **kwargs):
    base = {
        "tag": tag, "b": b, "e": b + 10, "team": team, "labels": labels or [],
        "xg": None, "x": None, "y": None, "tx": None, "ty": None, "half": 1,
    }
    base.update(kwargs)
    return base


def test_pojecia_pochodza_z_zamknietej_listy():
    """Nowa etykieta to kwalifikator, nie nowe pojęcie (MODEL_KANONICZNY.md)."""
    concepts = {rule["concept"] for rule in canon.DEFAULT_TAG_RULES.values()}
    assert concepts <= canon.CONCEPTS, (
        "Profil domyślny wprowadził pojęcie spoza listy bazowej — model zaczyna "
        "kopiować format LiveTag zamiast go tłumaczyć"
    )


def test_tag_bez_mapowania_zachowuje_zdarzenie():
    result = canon.build(frame(event(tag="AKCJA DEFENSYWNA")))
    zdarzenie = result["events"][0]

    assert len(result["events"]) == 1, "zdarzenie nie może zniknąć"
    assert zdarzenie["concept"] is None
    assert zdarzenie["confidence"] == 0.0
    assert zdarzenie["source_tag"] == "AKCJA DEFENSYWNA"
    assert result["report"]["unmapped_tags"] == ["AKCJA DEFENSYWNA"]


def test_etykieta_nie_analizuj_jest_znana():
    """Reguła `qualifier: null` to decyzja operatora „nie analizuj" — etykieta
    jest silnikowi ZNANA, jak tag z `concept: null`. Przy odczycie przez
    `get()` decyzja wracała w `unmapped_labels` przy każdym renderze."""
    profil = {"version": 1, "rules": [
        {"match": {"label": "PRESSING WYSOKI"}, "qualifier": None},
    ]}
    result = canon.build(
        frame(event(tag="STRZAŁ", labels=["PRESSING WYSOKI"])),
        mapping_profile=profil,
    )

    assert result["report"]["unmapped_labels"] == []
    assert result["events"][0]["source_labels"] == ["PRESSING WYSOKI"], (
        "decyzja wyłącza etykietę z metryk, ale nie wymazuje jej ze zdarzenia"
    )


def test_kwalifikatory_z_reguly_tagu_i_z_etykiet():
    result = canon.build(frame(event(tag="1x1 OFF", labels=["WYGRANY"])))
    zdarzenie = result["events"][0]

    assert zdarzenie["concept"] == "duel"
    assert zdarzenie["qualifiers"] == ["offensive", "won"], "kolejność ma być powtarzalna"


def test_kwalifikatory_bez_powtorzen():
    result = canon.build(frame(event(tag="STRATA", labels=["STRATA", "STRATA"])))
    assert result["events"][0]["qualifiers"].count("loss") == 1


@pytest.mark.parametrize("team,expected", [
    ("HUTNIK KRAKÓW", "us"),
    ("hutnik kraków", "us"),
    ("  HUTNIK   KRAKÓW ", "us"),
    ("HUT", "us"),
    ("POGOŃ", "them"),
    (None, "none"),
])
def test_strona_druzyny_z_konfiguracji(team, expected):
    """Silnik nie odgaduje nazw klubów — dostaje je w konfiguracji."""
    teams = {"us": {"name": "HUTNIK KRAKÓW", "short": "HUT"}, "them": {"name": "POGOŃ"}}
    result = canon.build(frame(event(team=team)), teams=teams)
    assert result["events"][0]["team_side"] == expected


def test_nazwa_druzyny_spoza_konfiguracji_jest_zglaszana():
    teams = {"us": {"name": "HUTNIK"}, "them": {"name": "POGOŃ"}}
    result = canon.build(frame(event(team="WISŁA")), teams=teams)

    assert result["events"][0]["team_side"] == "none"
    assert result["report"]["unknown_teams"] == ["WISŁA"]


def test_bez_konfiguracji_druzyn_nie_ma_falszywych_ostrzezen():
    """`inspect` nie dostaje konfiguracji — to nie jest błąd danych."""
    result = canon.build(frame(event(team="WISŁA")))

    assert result["report"]["unknown_teams"] == []
    assert result["report"]["teams_detected"] == ["WISŁA"]


def test_profil_klubu_nadpisuje_domyslny():
    profil = {
        "version": 4,
        "rules": [
            {"match": {"tag": "STRZAŁ NASZA"}, "concept": "shot", "team_side": "us"},
            {"match": {"label": "CELNY"}, "qualifier": "on_target"},
        ],
    }
    result = canon.build(frame(event(tag="STRZAŁ NASZA", labels=["CELNY"])), mapping_profile=profil)
    zdarzenie = result["events"][0]

    assert zdarzenie["concept"] == "shot"
    assert zdarzenie["team_side"] == "us", "drużyna zakodowana w nazwie tagu"
    assert zdarzenie["qualifiers"] == ["on_target"]
    assert result["report"]["profile_version"] == 4


def test_wspolrzedne_przechodza_bez_lustrzenia():
    """Pułapka 2: eksport ma je już znormalizowane kierunkowo."""
    result = canon.build(frame(event(x=88.4, y=31.2, tx=95.6, ty=48.3)))
    zdarzenie = result["events"][0]

    assert (zdarzenie["x"], zdarzenie["y"]) == (88.4, 31.2)
    assert (zdarzenie["x_end"], zdarzenie["y_end"]) == (95.6, 48.3)


def test_xg_bez_wartosci_nie_ma_zrodla():
    result = canon.build(frame(event(xg=None), event(xg=0.4)))
    assert result["events"][0]["xg_source"] is None
    assert result["events"][1]["xg_source"] == "analyst"


def test_czas_w_milisekundach():
    result = canon.build(frame(event(b=123.4)))
    assert result["events"][0]["t_ms"] == 123400
