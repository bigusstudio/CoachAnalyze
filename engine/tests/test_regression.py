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

from coachanalyze import canon, direction, coverage, render
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


def wymagaj_pliku(case, klucz, powod):
    nazwa = case.get(klucz)
    src = GOLDEN / nazwa if nazwa else None
    if src is None or not src.exists():
        pytest.skip(powod)
    return src


def config_z_manifestu(case):
    """Konfiguracja odtwarzająca wzorzec. Ścieżki herbów względem katalogu golden."""
    teams = json.loads(json.dumps(case["noname_teams"]))
    for cfg in teams.values():
        if cfg.get("crest"):
            cfg["crest"] = str(GOLDEN / cfg["crest"])
    return {"teams": teams}


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_render_odtwarza_raport_produkcyjny(case):
    """KRYTERIUM ODBIORU: render odtwarza raport produkcyjny co do bajtu.

    Jedyne dopuszczone różnice to dwa zdarzenia z przycięcia ujemnego `begin`
    (CHANGELOG 0.4.0). Wszystko inne — układ, style, teksty, herby, serializacja —
    musi się zgadzać, inaczej klient dostaje inny raport niż ten, który zatwierdził.

    Przed porównaniem cofamy jedyną zamierzoną zmianę strukturalną: nazwy zmiennych
    CSS przestały nazywać się od klubów (`--hut` -> `--team-home`). Mapowanie leży
    w manifeście, żeby nie dało się go po cichu rozszerzyć o kolejne „drobiazgi".
    """
    src = wymagaj_csv(case)
    wzorzec = wymagaj_pliku(
        case, "noname_html", "Brak wzorca raportu — dane meczowe poza repozytorium"
    ).read_text(encoding="utf-8")

    config = config_z_manifestu(case)
    frame = parse.prep_frame(str(src))
    canon_result = canon.build(frame, teams=config["teams"])
    html, raport = render.render(
        frame,
        palette=parse.prep_palette(str(GOLDEN / case["json"])),
        canon_result=canon_result,
        config=config,
    )
    assert raport["unresolved_placeholders"] == []
    assert raport["teams_defaulted"] == []
    assert raport["crests_generated"] == []

    for nowa, stara in case["noname_css_alias"].items():
        html = html.replace(nowa, stara)

    nasze, ich = html.split("\n"), wzorzec.split("\n")
    assert len(nasze) == len(ich), "Render zmienił liczbę linii raportu"

    rozne = [i for i, (x, y) in enumerate(zip(nasze, ich), 1) if x != y]
    linia_danych = next(i for i, l in enumerate(ich, 1) if l.startswith("const DATA = "))
    assert rozne == [linia_danych], (
        "Raport różni się od wzorca poza linią danych, w liniach: {}".format(
            [i for i in rozne if i != linia_danych]
        )
    )

    a, b = json.loads(wytnij(html, "DATA")), json.loads(wytnij(wzorzec, "DATA"))
    assert a["half_split"] == b["half_split"]
    roznice = [
        {"index": i, "pole": k, "v23": b["events"][i][k], "teraz": a["events"][i][k]}
        for i in range(len(a["events"]))
        for k in a["events"][i]
        if a["events"][i][k] != b["events"][i][k]
    ]
    assert roznice == case["v23_data_roznice"], (
        "Wyjście renderu rozjechało się z raportem produkcyjnym poza zmianami "
        "zatwierdzonymi w CHANGELOG.md. To NIE jest powód do aktualizacji wzorca."
    )


def test_szablon_nie_zna_zadnego_klubu():
    """Szablon jest wspólny dla wszystkich klientów — nazwa klubu w nim to wyciek.

    Poprzednia generacja (ARCHIWUM/v17.html) miała wpisane „Hutnik Kraków",
    „Pogoń-Sokół Lubaczów" i oba herby. Raport dla drugiego klubu byłby podpisany
    nazwą pierwszego.
    """
    szablon = render.load_template()
    for slad in ("HUTNIK", "POGOŃ", "Hutnik", "Pogoń", "DRUŻYNA A", "Drużyna A", "base64,"):
        assert slad not in szablon, "Szablon niesie ślad konkretnego klubu: {!r}".format(slad)


CECHY_V23 = ("QRANGES", "inSet(", "rXG(e.xg)", 'id="topbar"')


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_generacja_szablonu_zgodna_z_manifestem(case):
    """Bramka na podmianę szablonu: manifest ma wiedzieć, na której generacji stoimy.

    Cel: żeby nikt nie zmienił wyglądu raportu przez przypadek. Podmiana szablonu
    wymaga zmiany `szablon_generacja` w manifeście, inaczej test czerwienieje.
    """
    szablon = render.load_template()
    generacja = case["szablon_generacja"]
    assert generacja == "v23-noname", (
        "Nieznana generacja szablonu w manifeście: {}".format(generacja)
    )

    braki = [c for c in CECHY_V23 if c not in szablon]
    assert not braki, "Szablon zgubił cechy generacji v23: {}".format(braki)


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
    tagi_ref = set(canon.DEFAULT_TAG_RULES) | {p["tag"] for p in meta["unmapped_tags"]}
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


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_golden_slownik_obejmuje_caly_eksport(case):
    """Pełny słownik (`meta.dictionary`) na eksporcie referencyjnym.

    Blok zasila konfigurator raportu klubu (Sesja 3 przebudowy) i jego jedyną
    obietnicą jest KOMPLETNOŚĆ: ma pokazać wszystko, co w pliku jest — także
    tagi, które silnik rozpoznaje z domyślnego słownika i których dlatego
    NIE MA w `unmapped_tags`. Przy imporcie założycielskim `inspect` nie
    dostaje profilu klubu, więc bez tego bloku najważniejsze tagi klubu byłyby
    w konfiguratorze niewidoczne.

    Sprawdzamy to na realnym eksporcie, nie na syntetyku: liczby biorą się
    z manifestu, więc rozjazd z rzeczywistością zapala się tutaj.
    """
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    meta = coverage.build_meta(frame, canon.build(frame))

    slownik = meta["dictionary"]
    tagi = {p["tag"]: p["count"] for p in slownik["tags"]}

    # 1. KOMPLETNOŚĆ: suma wystąpień tagów = liczba zdarzeń w eksporcie.
    #    Każde zdarzenie ma dokładnie jeden tag, więc te liczby muszą się zgadzać
    #    co do jedności. Nierówność znaczy, że słownik gubi zdarzenia.
    assert sum(tagi.values()) == case["coverage"]["events"], (
        "Słownik nie obejmuje wszystkich zdarzeń eksportu"
    )

    # 2. Tagi z DOMYŚLNEGO słownika silnika są widoczne z licznikami — czyli
    #    dokładnie to, czego `unmapped_tags` nie pokazuje.
    nierozpoznane = {p["tag"] for p in meta["unmapped_tags"]}
    rozpoznane = [t for t in tagi if t not in nierozpoznane]
    assert rozpoznane, "eksport referencyjny musi zawierać tagi ze słownika domyślnego"
    for tag in rozpoznane:
        assert tagi[tag] > 0

    # 3. Strzały: liczba wystąpień tagów zmapowanych na `shot` nie może być
    #    mniejsza niż liczba strzałów w pokryciu. Wiąże słownik z metryką,
    #    więc rozjazd między nimi przestaje być niewidoczny.
    assert sum(tagi.values()) >= case["coverage"]["shots"]

    # 4. Próbka jest przycięta i niesie wyłącznie to, co pomaga rozpoznać tag.
    for pozycja in slownik["tags"]:
        assert 1 <= len(pozycja["samples"]) <= 3
        for probka in pozycja["samples"]:
            assert set(probka) == {"b", "team", "labels"}

    # 5. Porządek deterministyczny: malejąco po liczbie wystąpień.
    liczby = [p["count"] for p in slownik["tags"]]
    assert liczby == sorted(liczby, reverse=True)


# ═══════════════════════════════════════════════════════════════════════════
# BRAMKA SESJI 5: templat jako wejscie pipeline'u
#
# Templat odwzorowujacy dzisiejszy raport 1:1 MUSI dac wyjscie funkcjonalnie
# rowne temu bez templatu. Dopoki to nie przechodzi, templat nie jest droga
# do tego samego raportu, tylko do innego — a wtedy przelaczenie produkcji na
# templaty zmienia liczby, ktorych nikt nie prosil o zmiane.
#
# „Funkcjonalnie rowne", nie „co do bajtu": stopka ze stemplem wersji jest
# celowa roznica, a sekcje moga zniknac, gdy eksport nie niesie dla nich danych.
# Bajt w bajt pilnuje osobny test, dla sciezki BEZ templatu.
# ═══════════════════════════════════════════════════════════════════════════


def templat_odwzorowujacy():
    """Templat 1:1 wobec dzisiejszego zachowania silnika.

    Zmienne pokrywaja domyslny slownik silnika tymi samymi pojeciami, wiec
    warstwa kanoniczna ma nie zauwazyc roznicy. Sekcje: komplet.
    """
    zmienne = [
        ("STRZAŁ", "shot"),
        ("ZDOBYCIE SBZ", "entry_sbz"),
        ("III STREFA", "entry_third"),
        ("STRATA", "loss"),
        ("ODBIÓR", "recovery"),
        ("PIERWSZY KONTAKT", "duel"),
        ("1x1 OFF", "duel"),
        ("1x1 DEF.", "duel"),
        ("SKUTECZNY", "press"),
        ("NISKUTECZNY", "press"),
    ]
    return {
        "schema_version": 1,
        "team_us_rule": {"markers": ["NASZA", "MASZA"]},
        "sections_enabled": list(coverage.ALL_SECTIONS),
        "variables": [
            {
                "id": "v_{:03d}".format(i + 1),
                "source": {"type": "tag", "raw": raw},
                "canon": canon_value,
                "display_label": raw.title(),
                "color": "#8899AA",
                "sections": ["bilans", "tl_bilans"],
                "visible": True,
            }
            for i, (raw, canon_value) in enumerate(zmienne)
        ],
    }


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_bramka_s5_templat_nie_zmienia_liczb(case):
    """Ten sam eksport, z templatem i bez — te same liczby w pokryciu."""
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))

    bez = canon.build(frame)
    z_templatem = canon.build(frame, report_template=templat_odwzorowujacy())

    assert len(bez["events"]) == len(z_templatem["events"]) == case["coverage"]["events"]

    # Pojecie kanoniczne zdarzenie po zdarzeniu — nie sama suma. Dwie rozne
    # pomylki potrafia dac te sama liczbe.
    rozne = [
        i for i, (a, b) in enumerate(zip(bez["events"], z_templatem["events"]))
        if a["concept"] != b["concept"]
    ]
    assert rozne == [], "templat zmienil pojecie dla {} zdarzen".format(len(rozne))

    meta_bez = coverage.build_meta(frame, bez)
    meta_z = coverage.build_meta(frame, z_templatem)

    for klucz in ("events", "shots", "sbz", "third", "duels", "xg_parsed", "xg_sum",
                  "unanalysed", "no_team"):
        assert meta_bez["coverage"][klucz] == meta_z["coverage"][klucz], (
            "{} rozjechalo sie po wlaczeniu templatu".format(klucz)
        )

    assert meta_z["coverage"]["xg_sum"] == case["coverage"]["xg_sum"]
    assert frame["half_split"] == case["half_split"], "polowa ~45,5' bez zmian"


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_bramka_s5_render_z_templatem_ma_te_same_sekcje(case):
    """Templat z kompletem sekcji nie usuwa niczego, co eksport niesie."""
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    paleta = parse.prep_palette(str(GOLDEN / case["json"]))

    templat = templat_odwzorowujacy()
    canon_result = canon.build(frame, report_template=templat)
    meta = coverage.build_meta(frame, canon_result, config={"sections": templat["sections_enabled"]})

    html, raport = render.render(
        frame, palette=paleta, canon_result=canon_result,
        config={"drop_sections": [s["id"] for s in meta["sections_unavailable"]]},
    )

    # Eksport referencyjny nie niesie pozycji III STREFY (pulapka 3), wiec ta
    # jedna sekcja ma zniknac — z powodem, nie po cichu.
    niedostepne = {s["id"]: s["reason"] for s in meta["sections_unavailable"]}
    assert raport["sections_dropped"] == list(niedostepne), (
        "render usunal co innego, niz orzekl raport pokrycia"
    )
    for powod in niedostepne.values():
        assert powod.strip(), "kazda usunieta sekcja niesie powod"

    # OCZEKIWANIE LICZONE Z SZABLONU, nie z samego rejestru sekcji: sekcje
    # dolozone w v21 (Przeglad, makro, donuty, okazje, zawodnicy, siatka) nie
    # istnieja w v17, a render nie ma prawa ich tam wyczarowac. Test ma pilnowac
    # zgodnosci HTML-a z raportem pokrycia, a nie kompletu generacji.
    szablon = render.load_template(render.default_template_path())
    for sid in templat["sections_enabled"]:
        znacznik = 'id="{}"'.format(render.SECTION_DOM_ID[sid])
        w_szablonie = znacznik in szablon
        assert (znacznik in html) == (w_szablonie and sid not in niedostepne), (
            "sekcja {} jest w HTML-u niezgodnie z raportem pokrycia".format(sid)
        )

    assert raport["unresolved_placeholders"] == []


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_eksporty_referencyjne_sa_znormalizowane_kierunkowo(case):
    """Pułapka 2: współrzędne w eksporcie LiveTag są znormalizowane kierunkowo.

    Ostrzeżenie o zmianie stron (sesja 7) ma się NIE zapalać na plikach, które
    znamy — inaczej byłoby ostrzeżeniem o stanie normalnym, a takie uczą
    ignorować wszystkie pozostałe.
    """
    frame = parse.prep_frame(str(wymagaj_csv(case)))
    lookup = canon.build_team_lookup(case["noname_teams"])
    kierunek = direction.wykryj(
        frame, tag_rules=canon.resolve_profile(None)["tags"], lookup=lookup
    )

    assert kierunek["warnings"] == [], (
        "eksport referencyjny nie zmienia stron po przerwie: " + repr(kierunek["halves"])
    )
    # Obie drużyny rozstrzygnięte i po przeciwnych stronach — to jest stan,
    # w którym normalizacja map ma sens.
    assert {kierunek["us"], kierunek["them"]} == {"left", "right"}


def test_bramka_s5_mapowanie_sekcji_pokrywa_wszystkie():
    """Kazda sekcja silnika ma odpowiednik w szablonie — inaczej filtrowanie
    po cichu pomijaloby te, ktorej nie ma w mapie."""
    assert set(render.SECTION_DOM_ID) == set(coverage.ALL_SECTIONS)


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_bramka_s5_brak_templatu_nie_zmienia_nic(case):
    """Sciezka BEZ templatu ma zostac nietknieta co do bajtu.

    Duplikuje intencje testu odtworzenia raportu produkcyjnego, ale sprawdza ja
    wprost wobec zmian z Sesji 5: `drop_sections` i stempel maja byc martwe,
    dopoki nikt ich nie wlaczy.
    """
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    paleta = parse.prep_palette(str(GOLDEN / case["json"]))

    a, raport_a = render.render(frame, palette=paleta)
    b, raport_b = render.render(frame, palette=paleta, config={})

    assert a == b
    assert raport_a["sections_dropped"] == []
    assert "templat v" not in a, "stempel bez wersji nie ma prawa sie pojawic"


# ═══════════════════════════════════════════════════════════════════════════
# SESJA 1b — DOSTĘPNOŚĆ SEKCJI I xG PO SUROWYCH TAGACH TEMPLATU
#
# Odbiór sesji 1 na serwerze tego NIE PRZESZEDŁ. Templat, w którym `STRZAŁ`
# i `ZDOBYCIE SBZ` mają `canon: null` — stan NORMALNY od sesji 1 — dawał raport
# BEZ map i BEZ osi SBZ, a `meta.coverage.xg_sum` wynosiło 0 przy ostrzeżeniu
# XG_POZA_STRZALEM na 29 strzałach. Szablon v21 liczył wtedy poprawnie
# (12:17, xG 1,49:2,91), więc rozjazd był wyłącznie po stronie silnika:
# uśpiona warstwa kanoniczna egzekwowała wycofaną regułę.
# ═══════════════════════════════════════════════════════════════════════════


def templat_bez_pojec(sekcje=None):
    """Templat pokrywający eksport referencyjny, ze zmiennymi BEZ pojęć."""
    zmienne = [
        ("STRZAŁ", ["bilans", "mapy", "tl_bilans"]),
        ("ZDOBYCIE SBZ", ["bilans", "mapy", "tl_sbz"]),
        ("III STREFA", ["bilans", "tl_iii"]),
        ("STRATA", ["bilans"]),
        ("ODBIÓR", ["bilans"]),
        ("PIERWSZY KONTAKT", ["duels"]),
        ("1x1 OFF", ["duels"]),
        ("1x1 DEF.", ["duels"]),
    ]
    return {
        "schema_version": 1,
        "team_us_rule": {"markers": ["NASZA", "MASZA"]},
        "sections_enabled": list(sekcje) if sekcje else list(coverage.ALL_SECTIONS),
        "variables": [
            {
                "id": "v_{:03d}".format(i + 1),
                "source": {"type": "tag", "raw": raw},
                "canon": None,                     # ← o to w tym teście chodzi
                "display_label": raw.title(),
                "color": "#8899AA",
                "sections": sekcje_zmiennej,
                "visible": True,
            }
            for i, (raw, sekcje_zmiennej) in enumerate(zmienne)
        ],
    }


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_1b_sekcje_dostepne_mimo_braku_pojec(case):
    """Mapy i oś SBZ mają być DOSTĘPNE, choć żadna zmienna nie ma pojęcia."""
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    templat = templat_bez_pojec()

    canon_result = canon.build(frame, report_template=templat)
    meta = coverage.build_meta(
        frame, canon_result,
        config={"sections": templat["sections_enabled"]},
        report_template=templat,
    )

    niedostepne = {s["id"]: s["reason"] for s in meta["sections_unavailable"]}

    assert "mapy" not in niedostepne, (
        "mapy wycięte mimo zdarzeń ze współrzędnymi: " + niedostepne.get("mapy", "")
    )
    assert "tl_sbz" not in niedostepne, (
        "oś SBZ wycięta mimo zdarzeń: " + niedostepne.get("tl_sbz", "")
    )
    assert "tl_iii" not in niedostepne, (
        "oś III strefy wycięta mimo zdarzeń z pozycjami: " + niedostepne.get("tl_iii", "")
    )

    # POJEDYNKI TĄ SAMĄ MIARĄ. Sesja 1b zostawiła `duels` przy pojęciu `duel`,
    # więc sekcja znikała przy templacie bez pojęć dokładnie tak, jak znikały
    # mapy — ta sama usterka, domknięta osobnym commitem.
    assert "duels" not in niedostepne, (
        "sekcja pojedynków wycięta mimo zdarzeń: " + niedostepne.get("duels", "")
    )

    # Na eksporcie referencyjnym templat bez ANI JEDNEGO pojęcia kanonicznego
    # ma dać KOMPLET sekcji. To jest kryterium odbioru sesji 1b w jednym zdaniu.
    assert niedostepne == {}, "sekcje wycięte mimo danych: " + str(niedostepne)


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_1b_xg_sum_zgadza_sie_z_przegladem(case):
    """`xg_sum` ma być tą samą liczbą, którą pokazuje Przegląd (Hutnik: 4,40)."""
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    templat = templat_bez_pojec()

    canon_result = canon.build(frame, report_template=templat)
    meta = coverage.build_meta(frame, canon_result, report_template=templat)

    assert meta["coverage"]["xg_sum"] == case["coverage"]["xg_sum"]
    assert meta["coverage"]["xg_parsed"] == case["coverage"]["xg_parsed"]

    kody = {w["code"] for w in meta["warnings"]}
    assert "XG_POZA_STRZALEM" not in kody, (
        "xG z własnych strzałów klubu zgłoszone jako wartość poza strzałem"
    )


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_1b_sekcja_bez_zmiennej_jest_niedostepna_z_powodem(case):
    """Templat, który nie przypisał do map ANI JEDNEJ zmiennej, map nie dostaje.

    To jest druga strona tej samej reguły: dostępność liczy się z tego, co
    templat do sekcji przypisał, więc pusta sekcja zostaje pusta — z powodem,
    nie po cichu.
    """
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))

    templat = templat_bez_pojec()
    for zmienna in templat["variables"]:
        zmienna["sections"] = [s for s in zmienna["sections"] if s not in ("mapy", "duels")]

    canon_result = canon.build(frame, report_template=templat)
    meta = coverage.build_meta(
        frame, canon_result,
        config={"sections": templat["sections_enabled"]},
        report_template=templat,
    )

    niedostepne = {s["id"]: s["reason"] for s in meta["sections_unavailable"]}
    for sekcja in ("mapy", "duels"):
        assert sekcja in niedostepne, sekcja
        assert niedostepne[sekcja].strip(), "każda usunięta sekcja niesie powód"


@pytest.mark.parametrize("case", load_cases(), ids=lambda c: c["id"])
def test_1b_bez_templatu_wynik_bez_zmian(case):
    """Ścieżka BEZ templatu ma zostać nietknięta — na tym stoi test złoty."""
    src = wymagaj_csv(case)
    frame = parse.prep_frame(str(src))
    canon_result = canon.build(frame)

    meta = coverage.build_meta(frame, canon_result, has_json=True,
                               palette=parse.prep_palette(str(GOLDEN / case["json"])))

    for klucz, oczekiwane in case["coverage"].items():
        assert meta["coverage"][klucz] == oczekiwane, klucz
    assert [s["id"] for s in meta["sections_unavailable"]] == case["sections_unavailable"]
    assert {w["code"]: w["count"] for w in meta["warnings"]} == case["warnings"]
