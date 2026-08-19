"""Zgodność wersji pakietu z wersją silnika — BRAMKA NIEZALEŻNA OD `tomllib`.

═══════════════════════════════════════════════════════════════════════════
POWÓD ISTNIENIA TEGO OSOBNEGO MODUŁU.

Ta asercja mieszkała w `test_pakowanie.py`, który zaczyna się od
`pytest.importorskip("tomllib")`. `tomllib` wszedł w Pythonie 3.11, więc na
starszym interpreterze pytest pomija CAŁY tamten moduł — siedem testów naraz,
zameldowanych jako jedno „1 skipped".

Skutek był realny: wdrożenie zatrzymało się na rozjeździe `pyproject.toml`
(0.9.0) z `__init__.py` (0.11.0), mimo że lokalne przebiegi kończyły się
„167 passed, 1 skipped" przy każdej z trzech sesji, które tę wersję zmieniały.
Bramka istniała, ale nie miała jak zadziałać tam, gdzie pracujemy.

Dlatego NAJWAŻNIEJSZE sprawdzenie z tamtego modułu stoi tutaj i czyta
`pyproject.toml` wyrażeniem regularnym. Reszta (lista pakietów, `package-data`,
archiwum szablonów) wymaga prawdziwego parsera TOML i zostaje pod
`importorskip` — tamte rzeczy zmieniają się rzadko i nie towarzyszą każdej
zmianie wersji.

DLACZEGO AKURAT TA ASERCJA: wersja silnika trafia do KAŻDEGO raportu
(`reports.engine_version`). Dwa źródła prawdy rozjeżdżają się cicho, a raport
zaczyna twierdzić, że powstał wersją, której pakiet nie deklaruje — i pytanie
„dlaczego raport z marca pokazuje inną liczbę" (CLAUDE.md §7) traci odpowiedź.
═══════════════════════════════════════════════════════════════════════════
"""

import pathlib
import re

from coachanalyze import __version__

ENGINE = pathlib.Path(__file__).resolve().parent.parent
PYPROJECT = ENGINE / "pyproject.toml"


def wersja_z_pyproject():
    """`version` z sekcji `[project]`, bez parsera TOML.

    Kotwiczymy się w SEKCJI, nie w pierwszym napotkanym `version`: w pliku
    mogą z czasem pojawić się inne tabele (`[tool.*]`) z własnym kluczem
    o tej samej nazwie, a wtedy naiwne dopasowanie porównywałoby coś innego,
    niż nazwa testu obiecuje.
    """
    tresc = PYPROJECT.read_text(encoding="utf-8")

    sekcja = re.search(r"^\[project\]\s*$(.*?)(?=^\[|\Z)", tresc, re.M | re.S)
    assert sekcja, "pyproject.toml nie ma sekcji [project]"

    dopasowanie = re.search(
        r"""^version\s*=\s*["']([^"']+)["']""", sekcja.group(1), re.M
    )
    assert dopasowanie, "sekcja [project] nie deklaruje `version`"
    return dopasowanie.group(1)


def test_wersja_pakietu_zgadza_sie_z_silnikiem():
    """Wersja silnika trafia do każdego raportu — dwa źródła prawdy rozjadą się cicho."""
    z_pliku = wersja_z_pyproject()

    assert z_pliku == __version__, (
        "pyproject.toml deklaruje {}, a coachanalyze/__init__.py {}. "
        "Instalacja pakietu i stempel w raporcie mówiłyby wtedy co innego.".format(
            z_pliku, __version__
        )
    )


def test_wersja_ma_ksztalt_numeru_wydania():
    """Zapis `major.minor.patch` — CHANGELOG i porównania między raportami na tym stoją."""
    assert re.fullmatch(r"\d+\.\d+\.\d+", __version__), __version__


def test_odczyt_wersji_dziala_bez_tomllib():
    """Sedno tego modułu: ma chodzić na KAŻDYM Pythonie, także sprzed 3.11.

    Gdyby ktoś „uporządkował" odczyt, sięgając tu po `tomllib`, bramka wróciłaby
    do pomijania na starszym interpreterze — czyli dokładnie tam, gdzie
    wcześniej przepuściła rozjazd aż do wdrożenia.
    """
    zrodlo = pathlib.Path(__file__).read_text(encoding="utf-8")
    kod = "\n".join(
        linia for linia in zrodlo.splitlines()
        if not linia.lstrip().startswith("#")
    )
    # Nazwa pada w komentarzach i docstringach z konieczności — wyjaśniają,
    # czemu ten moduł istnieje. Liczy się brak IMPORTU.
    assert not re.search(r"^\s*(import|from)\s+tomllib", kod, re.M)
