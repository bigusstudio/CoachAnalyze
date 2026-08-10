"""Test złoty — bramka wdrożenia.

Dla każdego eksportu referencyjnego silnik musi produkować wyjście identyczne z zatwierdzonym
wzorcem. Same pliki eksportu to dane taktyczne klienta i nie trafiają do repozytorium
(CLAUDE.md §7) — leżą w nim wyłącznie WZORCE WYJŚCIA.

Porównujemy wprost strukturę, nie skrót SHA-256: gwarancja jest ta sama (równość co do
wartości), a przy czerwonym teście widać KTÓRE zdarzenie się rozjechało, zamiast dwóch
różnych ciągów szesnastkowych. CLAUDE.md §2 opisuje wariant ze skrótami — różnica
jest świadoma i warta odnotowania przy najbliższej aktualizacji dokumentu.

CZERWONY TEST NIE OZNACZA, ŻE TRZEBA ZAKTUALIZOWAĆ WZORZEC.
Oznacza, że wyjście silnika się zmieniło. Aktualizacja wzorca wymaga świadomej decyzji
człowieka i wpisu w CHANGELOG.md — nigdy w tym samym commicie co zmiana logiki.

Bramka działa w dwóch warstwach:

1. `test_golden_output_unchanged` — pełna, wymaga plików CSV/JSON klienta. Do czasu ich
   dostarczenia jest pomijana, a nie fałszywie zielona.
2. `test_golden_pokrycie_zgodne_z_manifestem` — działa OD ZARAZ, bo wzorzec wyjścia parsera
   (294 zdarzenia) leży w repozytorium. Pilnuje modelu kanonicznego i warstwy pokrycia,
   czyli tego, co powstało po parserze.
"""

import json
import pathlib

import pytest

from coachanalyze import canon, coverage
from coachanalyze.sources.livetag import parse

GOLDEN = pathlib.Path(__file__).parent / "golden"
MANIFEST = GOLDEN / "manifest.json"


def load_cases():
    if not MANIFEST.exists():
        return []
    return json.loads(MANIFEST.read_text(encoding="utf-8")).get("cases", [])


def load_expected_frame(case):
    """Wzorzec wyjścia parsera jako raw_frame gotowy dla warstwy kanonicznej."""
    frame = json.loads((GOLDEN / case["expected_data"]).read_text(encoding="utf-8"))
    # Metadane pliku źródłowego — wzorzec ich nie niesie, bo dotyczą samego CSV.
    frame.setdefault("format_fingerprint", None)
    frame.setdefault("player_column", None)
    frame.setdefault("players", [])
    return frame


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_output_unchanged(case, tmp_path):
    src = GOLDEN / case["csv"]
    if not src.exists():
        pytest.skip(f"Brak pliku referencyjnego {case['csv']} — dane klienta poza repozytorium")

    produced = parse.prep_events(str(src))
    expected = json.loads((GOLDEN / case["expected_data"]).read_text(encoding="utf-8"))
    assert produced == expected, (
        "Wyjście parsera różni się od wzorca v23. To NIE jest powód do aktualizacji wzorca."
    )

    src_json = GOLDEN / case["json"]
    if src_json.exists():
        produced_pal = parse.prep_palette(str(src_json))
        expected_pal = json.loads((GOLDEN / case["expected_pal"]).read_text(encoding="utf-8"))
        assert produced_pal == expected_pal


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_pokrycie_zgodne_z_manifestem(case):
    """Model kanoniczny + pokrycie na wzorcu 294 zdarzeń.

    Nazwy w manifeście są opisowe, w `meta.coverage` — kontraktowe (KONTRAKT_CLI.md).
    Mapowanie jest jawne, żeby rozjazd nazw nie przeszedł niezauważony.
    """
    expected = case.get("coverage")
    if not expected:
        pytest.skip("Przypadek bez oczekiwanych liczb pokrycia")

    frame = load_expected_frame(case)
    result = canon.build(frame)
    meta = coverage.build_meta(frame, result)
    produced = meta["coverage"]

    klucze = {
        "events": "events",
        "shots": "shots",
        "xg_parsed": "xg_parsed",
        "xg_sum": "xg_sum",
        "sbz": "sbz",
        "sbz_with_vector": "sbz_with_vector",
        "third": "third",
        "third_with_pos": "third_pos",
        "no_team": "no_team",
        "negative_begin": "negative_begin",
    }
    for klucz_manifestu, klucz_meta in klucze.items():
        if klucz_manifestu in expected:
            assert produced[klucz_meta] == expected[klucz_manifestu], (
                "{} rozjechało się z manifestem".format(klucz_manifestu)
            )

    if "half_split" in expected:
        assert meta["half_split_ms"] == int(round(expected["half_split"] * 1000))

    assert produced["events"] == len(result["events"]), (
        "Zdarzenie zniknęło w drodze do modelu kanonicznego — suma musi się zgadzać "
        "z liczbą wierszy eksportu, także dla tagów bez mapowania"
    )


def test_manifest_exists():
    """Sam manifest jest w repozytorium i musi istnieć — inaczej nikt nie zauważy braku testów."""
    assert MANIFEST.exists(), "Brak engine/tests/golden/manifest.json"
