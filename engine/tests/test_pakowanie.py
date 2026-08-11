"""Pakowanie silnika — bramka na awarię wdrożenia.

Ta klasa błędów nie objawia się w testach uruchamianych z katalogu repozytorium:
`conftest.py` dokłada katalog `engine/` do `sys.path`, więc wszystko się importuje
niezależnie od tego, co mówi `pyproject.toml`. Rozjazd wychodzi dopiero na serwerze,
w `pip install -e`, czyli w kroku, po którym `deploy.sh` dopiero uruchamia test złoty.

Sprawdzamy dwie rzeczy, które już raz zatrzymały wdrożenie albo mogą je zatrzymać:

1. Lista pakietów w `pyproject.toml` zgadza się z tym, co leży na dysku. Brak wpisu
   nie wywala budowy — wycina moduł z instalacji i objawia się `ModuleNotFoundError`
   przy pierwszym raporcie.
2. Szablon jest wewnątrz pakietu i pasuje do wzorca `package-data`. Szablon obok
   pakietu instaluje się nigdzie, a `render` przestaje go znajdować poza repozytorium.
"""

import pathlib

import pytest

from coachanalyze import render

tomllib = pytest.importorskip(
    "tomllib", reason="tomllib jest od Pythona 3.11; silnik i tak wymaga >=3.11"
)

ENGINE = pathlib.Path(__file__).resolve().parent.parent
PYPROJECT = ENGINE / "pyproject.toml"


@pytest.fixture(scope="module")
def konfiguracja():
    with open(PYPROJECT, "rb") as fh:
        return tomllib.load(fh)


def pakiety_na_dysku():
    """Katalogi z `__init__.py` pod `coachanalyze/`, w zapisie kropkowym."""
    korzen = ENGINE / "coachanalyze"
    znalezione = []
    for init in sorted(korzen.rglob("__init__.py")):
        znalezione.append(".".join(init.parent.relative_to(ENGINE).parts))
    return znalezione


def test_lista_pakietow_zgadza_sie_z_dyskiem(konfiguracja):
    zadeklarowane = konfiguracja["tool"]["setuptools"]["packages"]
    na_dysku = pakiety_na_dysku()

    assert sorted(zadeklarowane) == sorted(na_dysku), (
        "Lista pakietów w pyproject.toml rozjechała się z drzewem katalogów. "
        "Brakujące: {} · nadmiarowe: {}".format(
            sorted(set(na_dysku) - set(zadeklarowane)),
            sorted(set(zadeklarowane) - set(na_dysku)),
        )
    )


def test_wykrywanie_pakietow_jest_wylaczone(konfiguracja):
    """Automatyczne wykrywanie widziało `templates/` jako drugi pakiet i psuło budowę."""
    setuptools = konfiguracja["tool"]["setuptools"]
    assert "packages" in setuptools, (
        "Jawna lista pakietów zniknęła — automatyczne wykrywanie w układzie płaskim "
        "odmawia budowy, gdy obok pakietu leży katalog z danymi"
    )
    assert "packages" not in konfiguracja.get("tool", {}).get("setuptools", {}).get("dynamic", {})


def test_wersja_pakietu_zgadza_sie_z_silnikiem(konfiguracja):
    """Wersja silnika trafia do każdego raportu — dwa źródła prawdy rozjadą się cicho."""
    from coachanalyze import __version__

    assert konfiguracja["project"]["version"] == __version__


def test_szablon_lezy_wewnatrz_pakietu():
    szablon = pathlib.Path(render.default_template_path())
    assert szablon.exists(), "Brak szablonu pod {}".format(szablon)

    wzgledna = szablon.relative_to(ENGINE / "coachanalyze")
    assert wzgledna.parts[0] == "templates", (
        "Szablon musi leżeć w pakiecie — obok pakietu nie zainstaluje się nigdzie"
    )


def test_szablon_pasuje_do_wzorca_package_data(konfiguracja):
    """Wzorzec z pyproject musi faktycznie łapać szablon, a nie tylko brzmieć sensownie."""
    wzorce = konfiguracja["tool"]["setuptools"]["package-data"]["coachanalyze"]
    korzen = ENGINE / "coachanalyze"
    szablon = pathlib.Path(render.default_template_path())

    zlapane = {p for wzorzec in wzorce for p in korzen.glob(wzorzec)}
    assert szablon in zlapane, (
        "Wzorce {} nie łapią {} — instalacja pojedzie bez szablonu".format(
            wzorce, szablon.relative_to(korzen)
        )
    )


def test_archiwum_nie_jedzie_w_instalacji(konfiguracja):
    """Poprzednie generacje szablonu zostają w repozytorium, nie w każdej instalacji."""
    wzorce = konfiguracja["tool"]["setuptools"]["package-data"]["coachanalyze"]
    korzen = ENGINE / "coachanalyze"
    archiwum = korzen / "templates" / "ARCHIWUM"

    if not archiwum.exists():
        pytest.skip("Brak archiwum szablonów")

    zlapane = {p for wzorzec in wzorce for p in korzen.glob(wzorzec)}
    assert not [p for p in zlapane if archiwum in p.parents]
