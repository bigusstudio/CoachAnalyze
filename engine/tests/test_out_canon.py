"""Eksport zdarzeń kanonicznych do `events_canonical` — build --out-canon.

Kształt rekordu: app/migrations/002_events_canonical.sql (+ 003 dla `concept NULL`).
"""

import json
import pathlib

import pytest

from coachanalyze import canon
from coachanalyze.cli import main
from coachanalyze.sources.livetag import parse

GOLDEN = pathlib.Path(__file__).parent / "golden"

# Kolumny tabeli. Rozjazd tej listy z migracją to błąd po jednej ze stron.
KOLUMNY = [
    "match_id", "t_ms", "half", "team_side", "concept", "qualifiers_json",
    "x", "y", "x_end", "y_end", "xg", "xg_source", "player_id", "source_tag",
]


def _config(tmp_path, **extra):
    cfg = {"match_id": 881, "teams": {"us": {"name": "A"}, "them": {"name": "B"}}}
    cfg.update(extra)
    path = tmp_path / "config.json"
    path.write_text(json.dumps(cfg), encoding="utf-8")
    return str(path)


def _build(tmp_path, csv_path, capsys, **extra):
    """Uruchamia `build`. Zwraca (kod, zawartość out-canon).

    `build` kończy się dziś kodem 4, bo render.py nie istnieje — ale artefakty
    danych powstają przed renderem i to one są tu sprawdzane.
    """
    out_canon = tmp_path / "canon.json"
    kod = main([
        "build", "--csv", csv_path, "--config", _config(tmp_path, **extra),
        "--out-html", str(tmp_path / "raport.html"),
        "--out-meta", str(tmp_path / "meta.json"),
        "--out-canon", str(out_canon),
    ])
    capsys.readouterr()
    return kod, json.loads(out_canon.read_text(encoding="utf-8"))


def test_rekord_ma_kolumny_z_migracji(write_csv, row, tmp_path, capsys):
    csv_path = write_csv([row("STRZAŁ", team="A", comment="X 0,81", x="88.4", y="31.2")])
    _, payload = _build(tmp_path, csv_path, capsys)

    assert payload["count"] == 1
    rekord = payload["events"][0]
    assert list(rekord) == KOLUMNY, "kolejność i zestaw pól wg 002_events_canonical.sql"
    assert rekord["match_id"] == 881
    assert rekord["t_ms"] == 0
    assert rekord["concept"] == "shot"
    assert rekord["team_side"] == "us"
    assert rekord["xg"] == 0.81
    assert rekord["xg_source"] == "analyst"


def test_qualifiers_json_jest_napisem_gotowym_do_wstawienia(write_csv, row, tmp_path, capsys):
    csv_path = write_csv([row("STRZAŁ", team="A", labels="POZYCYJNIE, CELNY")])
    _, payload = _build(tmp_path, csv_path, capsys)

    surowy = payload["events"][0]["qualifiers_json"]
    assert isinstance(surowy, str), "kolumna jest typu JSON — PHP nie ma kodować drugi raz"
    assert json.loads(surowy) == ["positional", "on_target"]


def test_zdarzenie_bez_pojecia_tez_daje_rekord(write_csv, row, tmp_path, capsys):
    """Niezmiennik: liczba rekordów == liczba wierszy CSV, także dla concept null."""
    csv_path = write_csv([
        row("STRZAŁ", team="A"),
        row("AKCJA DEFENSYWNA", team="A"),
        row("TAG SPOZA SŁOWNIKA", team="A"),
    ])
    _, payload = _build(tmp_path, csv_path, capsys)

    assert payload["count"] == 3
    assert [e["concept"] for e in payload["events"]] == ["shot", None, None]
    assert all(e["source_tag"] for e in payload["events"]), (
        "nazwa taga musi zostać, inaczej nie da się dodać mapowania później"
    )


def test_czas_bez_wartosci_nie_jest_zerowany(write_csv, row, tmp_path, capsys):
    """Zero to konkretna 0. minuta — nie wolno go użyć jako 'brak danych'."""
    csv_path = write_csv([row("STRZAŁ", begin="", team="A")])
    _, payload = _build(tmp_path, csv_path, capsys)

    assert payload["events"][0]["t_ms"] is None


def test_to_records_bez_match_id():
    """Silnik nie zna identyfikatora meczu, dopóki nie dostanie go w konfiguracji."""
    frame = {"events": [{"tag": "STRZAŁ", "b": 1.0, "e": 2.0, "team": None, "labels": [],
                         "xg": None, "x": None, "y": None, "tx": None, "ty": None, "half": 1}],
             "half_split": 0.0}
    rekordy = canon.to_records(canon.build(frame)["events"])
    assert rekordy[0]["match_id"] is None


def test_out_canon_pomijany_gdy_nie_podano(write_csv, row, tmp_path, capsys):
    main(["build", "--csv", write_csv([row("STRZAŁ")]), "--config", _config(tmp_path),
          "--out-html", str(tmp_path / "r.html"), "--out-meta", str(tmp_path / "m.json")])
    capsys.readouterr()
    assert not (tmp_path / "canon.json").exists()


@pytest.mark.skipif(not (GOLDEN / "mecz2.csv").exists(),
                    reason="Brak eksportu referencyjnego — dane klienta poza repozytorium")
def test_liczba_rekordow_rowna_liczbie_wierszy_na_danych_realnych(tmp_path, capsys):
    csv_path = str(GOLDEN / "mecz2.csv")
    _, payload = _build(tmp_path, csv_path, capsys)

    assert payload["count"] == len(parse.prep_events(csv_path)["events"]) == 294
    assert sum(1 for e in payload["events"] if e["concept"] is None) == 7, (
        "AKCJA DEFENSYWNA — 7 zdarzeń bez mapowania, zachowanych świadomie"
    )
    assert all(e["t_ms"] is not None and e["t_ms"] >= 0 for e in payload["events"])
