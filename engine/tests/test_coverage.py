"""Raport pokrycia i kontrakt `meta.json` — docs/KONTRAKT_CLI.md."""

import json

from coachanalyze import canon, coverage
from coachanalyze.cli import main
from coachanalyze.sources.livetag import parse

# Klucze, które warstwa PHP czyta wprost. Usunięcie któregokolwiek to zmiana
# kontraktu, nie refaktor.
KLUCZE_META = {
    "ok", "engine_version", "format_fingerprint", "half_split_ms", "duration_ms",
    "coverage", "sections_available", "sections_unavailable", "warnings",
    "unmapped_tags", "unmapped_labels",
}

KLUCZE_COVERAGE = {
    "events", "shots", "duels", "sbz", "sbz_with_vector", "third", "third_pos",
    "teams", "no_team", "xg_parsed", "xg_missing", "xg_sum", "negative_begin",
    "has_json", "players_filled",
}


def meta_for(csv_path, **kwargs):
    frame = parse.prep_frame(csv_path)
    return coverage.build_meta(frame, canon.build(frame), **kwargs)


def test_meta_ma_wszystkie_klucze_kontraktu(write_csv, row):
    meta = meta_for(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    assert set(meta) == KLUCZE_META
    assert set(meta["coverage"]) == KLUCZE_COVERAGE


def test_kazda_niedostepna_sekcja_niesie_powod(write_csv, row):
    """Powód trafia wprost do interfejsu — pusta sekcja bez wyjaśnienia to błąd."""
    meta = meta_for(write_csv([row("STRATA")]))

    assert meta["sections_unavailable"], "przy jednym zdarzeniu część sekcji musi odpaść"
    for sekcja in meta["sections_unavailable"]:
        assert set(sekcja) == {"id", "reason"}
        assert sekcja["reason"].strip(), "powód nie może być pusty"
        assert sekcja["id"] not in meta["sections_available"]


def test_sekcje_ograniczone_konfiguracja(write_csv, row):
    csv_path = write_csv([row("STRZAŁ", team="A", x="80", y="30")])
    meta = meta_for(csv_path, config={"sections": ["bilans", "mapy"]})

    assert meta["sections_available"] == ["bilans", "mapy"]
    assert meta["sections_unavailable"] == []


def test_nieznana_sekcja_jest_zglaszana_a_nie_pomijana(write_csv, row):
    meta = meta_for(write_csv([row("STRZAŁ")]), config={"sections": ["bilans", "wykres_3d"]})

    braki = {s["id"]: s["reason"] for s in meta["sections_unavailable"]}
    assert "wykres_3d" in braki
    assert "nieznana" in braki["wykres_3d"].lower()


def test_xg_liczone_na_strzalach(write_csv, row):
    csv_path = write_csv([
        row("STRZAŁ", comment="X 0,50", x="80", y="30"),
        row("STRZAŁ", x="81", y="31"),
    ])
    pokrycie = meta_for(csv_path)["coverage"]

    assert pokrycie["shots"] == 2
    assert pokrycie["xg_parsed"] == 1
    assert pokrycie["xg_missing"] == 1, "brakujące xG ma być widoczne, nie dopisane"
    assert pokrycie["xg_sum"] == 0.5


def test_brak_pliku_projektu_odnotowany_w_ostrzezeniach(write_csv, row):
    meta = meta_for(write_csv([row("STRZAŁ")]), has_json=False)

    assert meta["coverage"]["has_json"] is False
    assert [w for w in meta["warnings"] if w["code"] == "NO_JSON"]


def test_literowka_w_palecie_jest_wykrywana(write_csv, row):
    """W eksporcie referencyjnym literówka siedzi w palecie, nie w zdarzeniach."""
    paleta = {"tags": {}, "labels": {"MASZA POŁOWA": "#4B6584"}}
    meta = meta_for(write_csv([row("STRATA")]), has_json=True, palette=paleta)

    ostrzezenie = next(w for w in meta["warnings"] if w["code"] == "TYPO_MASZA")
    assert ostrzezenie["count"] == 1
    assert "palecie" in ostrzezenie["msg"]


def test_ostrzezenia_maja_stala_strukture(write_csv, row):
    meta = meta_for(write_csv([row("STRATA", begin=-1.0)]))

    assert meta["warnings"], "co najmniej NEGATIVE_BEGIN i brak kolumny zawodnika"
    for ostrzezenie in meta["warnings"]:
        # Trzy pola są obowiązkowe; część ostrzeżeń dokłada pola diagnostyczne
        # (np. `tags` przy XG_POZA_STRZALEM) — patrz docs/KONTRAKT_CLI.md.
        assert {"code", "msg", "count"} <= set(ostrzezenie)
        assert isinstance(ostrzezenie["count"], int)
        assert ostrzezenie["msg"].strip()


def test_odcisk_formatu_reaguje_na_zmiane_zestawu_kolumn(write_csv, row):
    podstawowe = ["tag_name", "begin", "end", "team", "labels", "comment",
                  "pos_x_meters", "pos_y_meters", "pos_target_x_meters",
                  "pos_target_y_meters"]
    a = meta_for(write_csv([row("STRZAŁ")], headers=podstawowe, name="a.csv"))
    b = meta_for(write_csv([row("STRZAŁ") + [""]], headers=podstawowe + ["player"], name="b.csv"))

    assert a["format_fingerprint"] != b["format_fingerprint"]
    assert a["format_fingerprint"].startswith("sha256:")


def test_czas_trwania_z_ostatniego_konca(write_csv, row):
    meta = meta_for(write_csv([
        row("STRATA", begin=10.0, end=20.0),
        row("ODBIÓR", begin=100.0, end=115.5),
    ]))
    assert meta["duration_ms"] == 115500


# ------------------------------------------------------------------- CLI
def test_inspect_wypisuje_json_na_stdout(write_csv, row, capsys):
    kod = main(["inspect", "--csv", write_csv([row("STRZAŁ", team="A", x="80", y="30")])])
    wyjscie = capsys.readouterr()

    assert kod == 0
    payload = json.loads(wyjscie.out)  # stdout jest zarezerwowany na JSON
    assert payload["ok"] is True
    assert wyjscie.err == "", "komunikaty diagnostyczne nigdy nie mieszają się z JSON-em"


def test_inspect_zapisuje_out_meta(write_csv, row, tmp_path, capsys):
    sciezka = tmp_path / "meta.json"
    kod = main(["inspect", "--csv", write_csv([row("STRZAŁ")]), "--out-meta", str(sciezka)])
    capsys.readouterr()

    assert kod == 0
    zapisane = json.loads(sciezka.read_text(encoding="utf-8"))
    assert zapisane["engine_version"]
    assert set(zapisane) == KLUCZE_META


def test_inspect_zwraca_wykryte_nazwy_druzyn(write_csv, row, capsys):
    """PHP proponuje na tej podstawie dopasowanie klubów przy pierwszym imporcie."""
    main(["inspect", "--csv", write_csv([
        row("STRZAŁ", team="HUTNIK KRAKÓW"),
        row("STRZAŁ", team="POGOŃ-SOKÓŁ LUBACZÓW"),
    ])])
    meta = json.loads(capsys.readouterr().out)

    assert meta["coverage"]["teams"] == ["HUTNIK KRAKÓW", "POGOŃ-SOKÓŁ LUBACZÓW"]
