"""Tabela zdarzeń — trzy reguły, na których stoi cała warstwa (sesja 2 pivotu).

Ten moduł NIE sprawdza, czy powstał plik. Sprawdza trzy rozstrzygnięcia, które
łatwo napisać prawie dobrze i nie zauważyć:

1. MINUTA. `ceil(t/60)` z przycięciem pierwszej połowy do 45 — czas w eksporcie
   to czas wideo (pułapka 8), więc doliczony czas rośnie dalej, a przerwa nie
   zeruje licznika.
2. GOL. Drużyna z najbliższego STRZAŁU, bo `team_uuid` przy golu bywa błędny.
   Ta sama reguła co w szablonie raportu — rozjazd tutaj to rozjazd na WYNIKU
   MECZU, czyli na jedynej liczbie, którą każdy sprawdza najpierw.
3. STRONA. Wiersz bez drużyny to `none`, nie zgadywanie (pułapka 5).
"""

import json

import pytest

from coachanalyze import events as events_mod
from coachanalyze.cli import main

TEAMS = {
    "us": {"name": "Klub A", "source_names": ["KLUB A"]},
    "them": {"name": "Klub B", "source_names": ["KLUB B"]},
}


def zdarzenie(tag, b, **kw):
    """Zdarzenie w kształcie ramki `prep_frame`."""
    baza = {"tag": tag, "b": b, "e": None, "team": None, "labels": [], "xg": None,
            "x": None, "y": None, "tx": None, "ty": None, "half": 1}
    baza.update(kw)
    return baza


def ramka(*zdarzenia, half_split=2700.0):
    return {"events": list(zdarzenia), "half_split": half_split}


# ------------------------------------------------------------------ 1. minuta
@pytest.mark.parametrize("sekundy, half, oczekiwana", [
    (0.0,    1, 1),    # minuta zaczyna się od 1 — poprawka z odbioru sesji 2
    (0.1,    1, 1),
    (59.9,   1, 1),
    (60.0,   1, 1),
    (60.1,   1, 2),
    (2640.0, 1, 44),
    (2700.0, 1, 45),   # równo 45. minuta
    (2701.0, 1, 45),   # doliczony czas pierwszej połowy — PRZYCIĘTY
    (3300.0, 1, 45),   # i dalej przycięty, choćby nagranie szło
])
def test_minuta_pierwszej_polowy_przycieta_do_45(sekundy, half, oczekiwana):
    assert events_mod.minuta(sekundy, half) == oczekiwana


@pytest.mark.parametrize("sekundy, oczekiwana", [
    (2701.0, 46),
    (5400.0, 90),
    (5700.0, 95),    # doliczony czas drugiej połowy ROŚNIE — górnej granicy nie znamy
])
def test_minuta_drugiej_polowy_nie_jest_przycinana(sekundy, oczekiwana):
    assert events_mod.minuta(sekundy, 2) == oczekiwana


def test_zdarzenie_w_sekundzie_zero_ma_minute_pierwsza():
    """Pułapka 10 przycina ujemny `begin` do zera, więc takich zdarzeń jest sporo.

    „0. minuta" nie istnieje ani w meczu, ani na osi czasu raportu.
    """
    assert events_mod.minuta(0.0, 1) == 1
    assert events_mod.minuta(0.0, 2) == 1

    frame = ramka(zdarzenie("STRZAŁ", 0.0, team="KLUB A"))
    assert events_mod.build(frame, config={"teams": TEAMS})["events"][0]["minute"] == 1


def test_granica_45_rozni_polowy():
    """Ta sama sekunda po dwóch stronach przerwy daje różne minuty.

    To jest sedno przycięcia: 2701 s przed przerwą to 45', a po przerwie 46'.
    """
    assert events_mod.minuta(2701.0, 1) == 45
    assert events_mod.minuta(2701.0, 2) == 46


# ------------------------------------------------------------------ 2. gol
def test_gol_bierze_druzyne_z_najblizszego_strzalu():
    """`team_uuid` przy golu bywa błędny — drużyna idzie ze strzału."""
    frame = ramka(
        zdarzenie("STRZAŁ", 100.0, team="KLUB A"),
        zdarzenie("Gol", 102.0, team="KLUB B"),      # BŁĘDNA drużyna w eksporcie
        zdarzenie("STRZAŁ", 500.0, team="KLUB B"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    gol = next(w for w in wiersze if w["tag_name"] == "Gol")
    assert gol["team"] == "KLUB A", "drużyna gola nie została skorygowana"
    assert gol["team_side"] == "us"


def test_strzal_dostaje_znacznik_gola_a_wiersz_gola_zostaje():
    """Nie sklejamy gola ze strzałem: gol jest osobnym tagiem analityka."""
    frame = ramka(
        zdarzenie("STRZAŁ", 100.0, team="KLUB A"),
        zdarzenie("Gol", 102.0, team="KLUB A"),
        zdarzenie("STRZAŁ", 500.0, team="KLUB A"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    assert len(wiersze) == 3, "skasowanie wiersza gola zmieniłoby sumę zdarzeń meczu"
    strzaly = [w for w in wiersze if w["tag_name"] == "STRZAŁ"]
    assert [w["is_goal"] for w in strzaly] == [1, 0], "gol trafił w niewłaściwy strzał"
    assert next(w for w in wiersze if w["tag_name"] == "Gol")["is_goal"] == 0


def test_gol_wybiera_strzal_najblizszy_w_czasie_takze_wstecz():
    """Najbliższy, nie poprzedzający — tag gola bywa wstawiony przed strzałem."""
    frame = ramka(
        zdarzenie("STRZAŁ", 80.0, team="KLUB A"),
        zdarzenie("Gol", 99.0, team="KLUB A"),
        zdarzenie("STRZAŁ", 100.0, team="KLUB B"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    assert next(w for w in wiersze if w["tag_name"] == "Gol")["team"] == "KLUB B"
    assert [w["is_goal"] for w in wiersze if w["tag_name"] == "STRZAŁ"] == [0, 1]


def test_gol_bez_strzalu_w_oknie_zostaje_przy_swojej_druzynie():
    """Strzał sprzed trzech minut to inna akcja — lepsza wartość, którą mamy."""
    frame = ramka(
        zdarzenie("STRZAŁ", 100.0, team="KLUB A"),
        zdarzenie("Gol", 900.0, team="KLUB B"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    assert next(w for w in wiersze if w["tag_name"] == "Gol")["team"] == "KLUB B"
    assert all(w["is_goal"] == 0 for w in wiersze if w["tag_name"] == "STRZAŁ")


def test_mecz_bez_goli_nie_oznacza_niczego():
    frame = ramka(zdarzenie("STRZAŁ", 100.0, team="KLUB A"))
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]
    assert all(w["is_goal"] == 0 for w in wiersze)


# ------------------------------------------------------------------ 3. strona
def test_odbior_bez_druzyny_ma_strone_none():
    """Pułapka 5: kolumna `team` bywa pusta — to nie jest odpad, tylko brak danych."""
    frame = ramka(
        zdarzenie("ODBIÓR", 10.0),                    # bez drużyny
        zdarzenie("ODBIÓR", 20.0, team=""),           # pusty napis, nie None
        zdarzenie("ODBIÓR", 30.0, team="KLUB A"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    assert [w["team_side"] for w in wiersze] == ["none", "none", "us"]
    assert wiersze[0]["team"] is None


def test_nazwa_spoza_konfiguracji_to_none_a_nie_zgadywanie():
    frame = ramka(zdarzenie("STRATA", 10.0, team="DRUŻYNA SPOZA BAZY"))
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    assert wiersze[0]["team_side"] == "none"
    assert wiersze[0]["team"] == "DRUŻYNA SPOZA BAZY", "surowa nazwa ma zostać"


def test_dopasowanie_ignoruje_wielkosc_liter_i_nadmiarowe_spacje():
    frame = ramka(zdarzenie("STRZAŁ", 10.0, team="  klub   a "))
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]
    assert wiersze[0]["team_side"] == "us"


def test_marker_z_templatu_wskazuje_nasza_druzyne():
    """Korekta MASZA/NASZA jest DANĄ TEMPLATU, nie regułą wpisaną w silnik."""
    frame = ramka(zdarzenie("STRZAŁ", 10.0, team="MASZA"))
    config = {"teams": TEAMS, "team_us_rule": {"markers": ["NASZA", "MASZA"]}}
    wiersze = events_mod.build(frame, config=config)["events"]
    assert wiersze[0]["team_side"] == "us"


# ------------------------------------------------------------------ kształt wiersza
def test_wiersz_niesie_surowa_nazwe_taga_bez_mapowania():
    """Zakaz z nagłówka modułu: żadnego pojęcia kanonicznego w tej warstwie."""
    frame = ramka(zdarzenie("WYJŚCIE Z PRESSINGU", 10.0, team="KLUB A"))
    wiersz = events_mod.build(frame, config={"teams": TEAMS})["events"][0]

    assert wiersz["tag_name"] == "WYJŚCIE Z PRESSINGU"
    assert "concept" not in wiersz and "canon" not in wiersz


def test_czas_w_milisekundach_i_polowa_z_ramki():
    frame = ramka(zdarzenie("STRZAŁ", 12.3, e=20.5, half=2, team="KLUB A"))
    wiersz = events_mod.build(frame, config={"teams": TEAMS})["events"][0]

    assert wiersz["t_ms"] == 12300
    assert wiersz["t_end_ms"] == 20500
    assert wiersz["half"] == 2


def test_xg_z_komentarza_jest_oznaczone_jako_analityka():
    frame = ramka(
        zdarzenie("STRZAŁ", 10.0, team="KLUB A", xg=0.81),
        zdarzenie("STRZAŁ", 20.0, team="KLUB A"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]

    assert (wiersze[0]["xg"], wiersze[0]["xg_source"]) == (0.81, "analyst")
    assert (wiersze[1]["xg"], wiersze[1]["xg_source"]) == (None, None)


def test_zawodnik_bierze_sie_z_kolumny_wyrownanej_indeksowo():
    frame = ramka(
        zdarzenie("STRZAŁ", 10.0, team="KLUB A"),
        zdarzenie("STRZAŁ", 20.0, team="KLUB A"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS}, players=["Kowalski", ""])["events"]

    assert wiersze[0]["player"] == "Kowalski"
    assert wiersze[1]["player"] is None, "pusta wartość to brak zawodnika, nie pusty napis"


def test_zdarzenie_bez_czasu_jest_pomijane_i_policzone():
    """`t_ms` jest NOT NULL, a zera nie podstawiamy (CLAUDE.md §8)."""
    frame = ramka(
        zdarzenie("STRZAŁ", 10.0, team="KLUB A"),
        zdarzenie("STRZAŁ", None, team="KLUB A"),
    )
    wynik = events_mod.build(frame, config={"teams": TEAMS})

    assert len(wynik["events"]) == 1
    assert wynik["skipped_no_time"] == 1


def test_kolejnosc_wierszy_jak_w_eksporcie():
    frame = ramka(
        zdarzenie("STRATA", 300.0, team="KLUB A"),
        zdarzenie("STRZAŁ", 10.0, team="KLUB A"),
    )
    wiersze = events_mod.build(frame, config={"teams": TEAMS})["events"]
    assert [w["tag_name"] for w in wiersze] == ["STRATA", "STRZAŁ"]


def test_bez_konfiguracji_druzyn_wszystko_jest_none():
    """Podgląd przed dopasowaniem klubów nie może zgadywać stron."""
    frame = ramka(zdarzenie("STRZAŁ", 10.0, team="KLUB A"))
    wiersze = events_mod.build(frame)["events"]
    assert wiersze[0]["team_side"] == "none"


# ------------------------------------------------------------------ spięcie z CLI
def _config(tmp_path):
    path = tmp_path / "config.json"
    path.write_text(json.dumps({
        "match_id": 77,
        "teams": {"us": {"name": "A", "source_names": ["A"]}},
    }), encoding="utf-8")
    return str(path)


def test_out_events_zapisuje_plik(write_csv, row, tmp_path, capsys):
    out_events = tmp_path / "events.json"
    kod = main([
        "build", "--csv", write_csv([
            row("STRZAŁ", begin=100.0, end=110.0, team="A", labels="CELNY", comment="X 0,81"),
            row("Gol", begin=102.0, end=104.0, team="A"),
        ]),
        "--config", _config(tmp_path),
        "--out-html", str(tmp_path / "raport.html"),
        "--out-meta", str(tmp_path / "meta.json"),
        "--out-events", str(out_events),
    ])
    capsys.readouterr()

    assert kod == 0
    payload = json.loads(out_events.read_text(encoding="utf-8"))
    assert payload["match_id"] == 77
    assert payload["count"] == 2 == len(payload["events"])
    assert payload["skipped_no_time"] == 0

    strzal = next(e for e in payload["events"] if e["tag_name"] == "STRZAŁ")
    assert strzal["is_goal"] == 1
    assert strzal["labels"] == ["CELNY"]
    assert strzal["xg"] == 0.81


def test_bez_out_events_plik_nie_powstaje(write_csv, row, tmp_path, capsys):
    """Parametr jest OPCJONALNY — bez niego pipeline zachowuje się jak dotąd."""
    out_events = tmp_path / "events.json"
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]),
        "--config", _config(tmp_path),
        "--out-html", str(tmp_path / "raport.html"),
        "--out-meta", str(tmp_path / "meta.json"),
    ])
    capsys.readouterr()

    assert kod == 0
    assert not out_events.exists()


def test_artefakt_events_powstaje_przed_renderem(write_csv, row, tmp_path, capsys, monkeypatch):
    """Awaria szablonu nie może kasować wyniku parsowania — jak przy `--out-canon`."""
    from coachanalyze import render

    monkeypatch.setattr(render, "load_template", lambda path=None: "<script>const DATA = 1;</script>")

    out_events = tmp_path / "events.json"
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]),
        "--config", _config(tmp_path),
        "--out-html", str(tmp_path / "raport.html"),
        "--out-meta", str(tmp_path / "meta.json"),
        "--out-events", str(out_events),
    ])
    capsys.readouterr()

    assert kod == 4
    assert json.loads(out_events.read_text(encoding="utf-8"))["count"] == 1
