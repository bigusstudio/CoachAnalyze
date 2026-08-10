"""Test złoty — bramka wdrożenia.

Dla każdego eksportu referencyjnego silnik musi produkować wyjście identyczne z zatwierdzonym
wzorcem. Same pliki eksportu to dane taktyczne klienta i nie trafiają do repozytorium
(CLAUDE.md §7) — `.gitignore` wyklucza z `golden/` wszystko poza `manifest.json`.

Dlatego wzorcem w repozytorium jest MANIFEST: skróty SHA-256 oczekiwanych wyjść i liczby
pokrycia. Pliki `v23_expected_*.json` są wygodą lokalną — gdy są pod ręką, porównujemy
strukturę i widać, KTÓRE zdarzenie się rozjechało; gdy ich nie ma, zostaje skrót.

Bez plików źródłowych testy złote są POMIJANE, nigdy fałszywie zielone.
W CI bramką są testy pułapek na danych syntetycznych (test_traps.py).

CZERWONY TEST NIE OZNACZA, ŻE TRZEBA ZAKTUALIZOWAĆ WZORZEC.
Oznacza, że wyjście silnika się zmieniło. Aktualizacja wzorca wymaga świadomej decyzji
człowieka i wpisu w CHANGELOG.md — nigdy w tym samym commicie co zmiana logiki.
"""

import hashlib
import json
import pathlib
import re

import pytest

from coachanalyze import canon, coverage, render
from coachanalyze.sources.livetag import parse

GOLDEN = pathlib.Path(__file__).parent / "golden"
MANIFEST = GOLDEN / "manifest.json"


def _manifest():
    if not MANIFEST.exists():
        return {}
    return json.loads(MANIFEST.read_text(encoding="utf-8"))


def load_cases(key="cases"):
    return _manifest().get(key, [])


def sha256_struktury(obiekt) -> str:
    """Skrót struktury wyjścia. Serializacja opisana w manifeście — zmiana unieważnia skróty."""
    tekst = json.dumps(obiekt, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return "sha256:" + hashlib.sha256(tekst.encode("utf-8")).hexdigest()


def wymagaj_csv(case):
    src = GOLDEN / case["csv"]
    if not src.exists():
        pytest.skip(f"Brak pliku referencyjnego {case['csv']} — dane klienta poza repozytorium")
    return src


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_wyjscie_parsera_bez_zmian(case):
    src = wymagaj_csv(case)
    produced = parse.prep_events(str(src))

    wzorzec = GOLDEN / case["expected_data"]
    if wzorzec.exists():
        assert produced == json.loads(wzorzec.read_text(encoding="utf-8")), (
            "Wyjście parsera różni się od wzorca v23. To NIE jest powód do aktualizacji wzorca."
        )
    assert sha256_struktury(produced) == case["sha256_data"], (
        "Skrót wyjścia parsera nie zgadza się z manifestem"
    )


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_zaden_begin_nie_jest_ujemny(case):
    """0.4.0: bufor taga przycięty do zera — ale przerwa nadal z wartości surowych."""
    src = wymagaj_csv(case)
    frame = parse.prep_events(str(src))

    ujemne = [e for e in frame["events"] if e["b"] is not None and e["b"] < 0]
    assert not ujemne, "przycinanie ujemnego `begin` przestało działać"
    assert frame["half_split"] == case["half_split"], (
        "przycinanie przesunęło wykrywanie przerwy — `half_split` musi zostać "
        "policzony z surowych wartości"
    )


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_paleta_bez_zmian(case):
    wymagaj_csv(case)
    src_json = GOLDEN / case["json"]
    if not src_json.exists():
        pytest.skip(f"Brak pliku projektu {case['json']}")

    produced = parse.prep_palette(str(src_json))

    wzorzec = GOLDEN / case["expected_pal"]
    if wzorzec.exists():
        assert produced == json.loads(wzorzec.read_text(encoding="utf-8"))
    assert sha256_struktury(produced) == case["sha256_pal"]


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_pokrycie_zgodne_z_manifestem(case):
    """Model kanoniczny i warstwa pokrycia na eksporcie referencyjnym."""
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    result = canon.build(frame)
    # Z paletą, bo liczby w manifeście pochodzą z pełnego wywołania — bez niej
    # doszłoby ostrzeżenie NO_JSON i porównanie sprawdzałoby co innego.
    meta = coverage.build_meta(frame, result, has_json=True,
                               palette=parse.prep_palette(str(GOLDEN / case["json"])))

    assert frame["format_fingerprint"] == case["format_fingerprint"]
    assert frame["half_split"] == case["half_split"]

    for klucz, oczekiwane in case["coverage"].items():
        assert meta["coverage"][klucz] == oczekiwane, "{} rozjechało się z manifestem".format(klucz)

    assert meta["coverage"]["events"] == len(result["events"]), (
        "Zdarzenie zniknęło w drodze do modelu kanonicznego — suma musi się zgadzać "
        "z liczbą wierszy eksportu, także dla tagów bez mapowania"
    )

    braki = [s["id"] for s in meta["sections_unavailable"]]
    assert braki == case["sections_unavailable"]

    ostrzezenia = {w["code"]: w["count"] for w in meta["warnings"]}
    assert ostrzezenia == case["warnings"]


# --------------------------------------------------- render wobec raportu produkcyjnego
def wymagaj_v23(case):
    """Pełny raport v23 leży poza repozytorium — niesie dane meczowe inline (§7)."""
    src = GOLDEN / case.get("v23_html", "")
    if not case.get("v23_html") or not src.exists():
        pytest.skip("Brak livetag_dashboard_v23.html — raport produkcyjny poza repozytorium")
    return src


def wytnij(html, nazwa):
    """Surowy zapis `const DATA = …;` / `const PAL = …;` — bez ponownej serializacji.

    Porównujemy NAPISY, nie sparsowane obiekty: separatory JSON są częścią wyjścia
    i różnica w nich zmieniłaby bajty raportu, nie zmieniając struktury.
    """
    dopasowanie = re.search(r"const {} = (.*?);\n".format(nazwa), html, re.S)
    assert dopasowanie, "brak wstrzykniętego bloku {} w HTML".format(nazwa)
    return dopasowanie.group(1)


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_render_wstrzykuje_to_samo_co_v23(case):
    """Raport z silnika ma nieść te same dane, co plik, którego klient używa dzisiaj.

    Porównujemy zawartość wstrzykniętą, nie cały plik — szablon w repozytorium
    to inna generacja UI niż v23 (patrz `szablon_generacja` w manifeście).
    """
    src = wymagaj_csv(case)
    v23 = wymagaj_v23(case).read_text(encoding="utf-8")

    frame = parse.prep_frame(str(src))
    paleta = parse.prep_palette(str(GOLDEN / case["json"]))
    html, raport = render.render(frame, palette=paleta)

    assert wytnij(html, "PAL") == wytnij(v23, "PAL"), (
        "Paleta rozjechała się z raportem produkcyjnym"
    )

    nasze = json.loads(wytnij(html, "DATA"))
    ich = json.loads(wytnij(v23, "DATA"))

    assert nasze["half_split"] == ich["half_split"]
    assert len(nasze["events"]) == len(ich["events"]) == case["coverage"]["events"]

    roznice = [
        {"index": i, "pole": k, "v23": ich["events"][i][k], "teraz": nasze["events"][i][k]}
        for i in range(len(nasze["events"]))
        for k in nasze["events"][i]
        if nasze["events"][i][k] != ich["events"][i][k]
    ]
    assert roznice == case["v23_data_roznice"], (
        "Wyjście renderu rozjechało się z raportem produkcyjnym poza zmianami "
        "zatwierdzonymi w CHANGELOG.md. To NIE jest powód do aktualizacji wzorca."
    )
    assert raport["events"] == case["coverage"]["events"]


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_generacja_szablonu_zgodna_z_manifestem(case):
    """Bramka na podmianę szablonu: manifest ma wiedzieć, na której generacji stoimy.

    v23 wprowadził wielokrotny wybór przedziałów (`inSet`) i belkę nawigacyjną
    (`#topbar`) — szablon v17 nie ma ani jednego, ani drugiego. Po podmianie
    szablonu na v23 trzeba zmienić `szablon_generacja` w manifeście, inaczej test
    czerwienieje. Cel: żeby nikt nie zmienił wyglądu raportu przez przypadek.
    """
    v23 = wymagaj_v23(case).read_text(encoding="utf-8")
    szablon = render.load_template()

    cechy_v23 = ("inSet(", 'id="topbar"')
    assert all(cecha in v23 for cecha in cechy_v23), "plik v23 nie wygląda na v23"

    generacja = case["szablon_generacja"]
    ma_cechy = all(cecha in szablon for cecha in cechy_v23)

    assert ma_cechy is (generacja == "v23"), (
        "Szablon w repozytorium nie odpowiada generacji '{}' zapisanej w manifeście".format(
            generacja
        )
    )


# --------------------------------------------------- wykrywanie rozjazdu formatu
@pytest.mark.parametrize("case", load_cases("format_cases"), ids=lambda c: c["id"])
def test_rozjazd_formatu_wzgledem_referencyjnego(case):
    """Eksport z innego przejścia tagowania — silnik ma pokazać różnicę, nie zjeść jej.

    Wniosek z danych: `format_fingerprint` wykrywa zmianę ZESTAWU KOLUMN i tylko ją.
    Rozjazd treści (brak pozycji III strefy, literówka, inne etykiety) widać dopiero
    w liczbach pokrycia i w ostrzeżeniach — i to one są tu sprawdzane.
    """
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    result = canon.build(frame)
    meta = coverage.build_meta(frame, result, has_json=True,
                              palette=parse.prep_palette(str(GOLDEN / case["json"])))

    referencyjny = next(c for c in load_cases() if c["id"] == case["wzgledem"])

    assert frame["format_fingerprint"] == case["format_fingerprint"]
    assert (frame["format_fingerprint"] != referencyjny["format_fingerprint"]) is case["fingerprint_rozny"]

    tagi = {e["tag"] for e in frame["events"]}
    tagi_ref = set(canon.DEFAULT_TAG_RULES) | set(meta["unmapped_tags"])
    assert (tagi != tagi_ref) is case["tagi_rozne"]

    for klucz, oczekiwane in case["coverage"].items():
        assert meta["coverage"][klucz] == oczekiwane, "{} rozjechało się z manifestem".format(klucz)

    assert [s["id"] for s in meta["sections_unavailable"]] == case["sections_unavailable"]
    assert {w["code"]: w["count"] for w in meta["warnings"]} == case["warnings"]


@pytest.mark.parametrize("case", load_cases("format_cases"), ids=lambda c: c["id"])
def test_rozjazd_ma_udokumentowany_powod(case):
    """Każda różnica w manifeście niesie skutek — inaczej nikt nie wie, czy jest groźna."""
    for nazwa, roznica in case["roznice"].items():
        assert "referencyjny" in roznica and "ten_plik" in roznica, nazwa
        assert roznica["referencyjny"] != roznica["ten_plik"], (
            "{}: wpisano różnicę, której nie ma".format(nazwa)
        )


def test_manifest_exists():
    """Sam manifest jest w repozytorium i musi istnieć — inaczej nikt nie zauważy braku testów."""
    assert MANIFEST.exists(), "Brak engine/tests/golden/manifest.json"
