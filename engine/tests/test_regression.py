"""Test złoty — bramka wdrożenia.

Dla każdego eksportu referencyjnego silnik musi produkować wyjście identyczne z zatwierdzonym
wzorcem. Porównujemy skróty SHA-256, bo same pliki eksportu to dane taktyczne klienta
i nie trafiają do repozytorium (CLAUDE.md §7).

CZERWONY TEST NIE OZNACZA, ŻE TRZEBA ZAKTUALIZOWAĆ WZORZEC.
Oznacza, że wyjście silnika się zmieniło. Aktualizacja wzorca wymaga świadomej decyzji
człowieka i wpisu w CHANGELOG.md — nigdy w tym samym commicie co zmiana logiki.
"""

import hashlib
import json
import pathlib

import pytest

GOLDEN = pathlib.Path(__file__).parent / "golden"
MANIFEST = GOLDEN / "manifest.json"


def sha256(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load_cases():
    if not MANIFEST.exists():
        return []
    return json.loads(MANIFEST.read_text(encoding="utf-8")).get("cases", [])


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_output_unchanged(case, tmp_path):
    src = GOLDEN / case["csv"]
    if not src.exists():
        pytest.skip(f"Brak pliku referencyjnego {case['csv']} — dane klienta poza repozytorium")

    pytest.skip("Silnik do zaimplementowania (Etap 2). Test aktywuje się po refaktorze.")


def test_manifest_exists():
    """Sam manifest jest w repozytorium i musi istnieć — inaczej nikt nie zauważy braku testów."""
    assert MANIFEST.exists(), "Brak engine/tests/golden/manifest.json"
