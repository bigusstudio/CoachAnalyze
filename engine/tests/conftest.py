"""Wspólne narzędzia testowe.

CI instaluje pakiet przez `pip install -e ".[dev]"`, ale testy mają dać się uruchomić
także bez instalacji — inaczej „szybkie sprawdzenie przed commitem" wymaga ceremonii
i przestaje być robione.
"""

import csv
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

import pytest  # noqa: E402

HEADERS = [
    "tag_name", "begin", "end", "team", "labels", "comment",
    "pos_x_meters", "pos_y_meters", "pos_target_x_meters", "pos_target_y_meters",
]


def _row(tag, begin=0.0, end=10.0, team="", labels="", comment="",
         x="", y="", tx="", ty=""):
    return [tag, begin, end, team, labels, comment, x, y, tx, ty]


@pytest.fixture
def write_csv(tmp_path):
    """Zapisuje syntetyczny eksport i zwraca ścieżkę.

    Piszemy modułem `csv`, żeby cytowanie pól było takie samo jak w prawdziwym
    eksporcie — ręcznie sklejany CSV rozjeżdża kolumny na pierwszym przecinku
    w etykietach i test zaczyna sprawdzać coś innego, niż miał.
    """
    def _write(rows, headers=None, name="tagging.csv"):
        path = tmp_path / name
        with open(path, "w", newline="", encoding="utf-8") as fh:
            writer = csv.writer(fh)
            writer.writerow(headers if headers is not None else HEADERS)
            writer.writerows(rows)
        return str(path)
    return _write


@pytest.fixture
def row():
    return _row
