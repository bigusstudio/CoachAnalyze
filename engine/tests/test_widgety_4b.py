"""Progi faktów, rejestr sekcji i nazwiska w `DATA` (sesja 4b).

Trzy rzeczy, których nie widać po samym renderze: skąd biorą się progi Przeglądu,
które sekcje są DOSTĘPNE, a które DOMYŚLNE, i kiedy skład jedzie do przeglądarki.
"""

import json

import pytest

from coachanalyze import coverage, render
from coachanalyze.cli import main
from coachanalyze.errors import EngineError

V21 = render.template_path_for("v21")
V17 = render.template_path_for("v17")


# ===========================================================================
# PROGI FAKTÓW


def test_progi_globalne_z_pliku_pakietu():
    progi = render.progi_globalne()

    assert progi == {"pressing": 70, "sbz_strzal": 60, "reakcja": 40,
                     "duel_def": 50, "p3": 40}
    assert not [k for k in progi if k.startswith("_")], "klucze opisowe nie są progami"


def test_templat_nadpisuje_prog():
    assert render.progi({"thresholds": {"pressing": 55}})["pressing"] == 55


@pytest.mark.parametrize("wartosc", ["70", "siedemdziesiąt", None, True, 140, -1, float("nan")])
def test_prog_spoza_zakresu_albo_nieliczbowy_odpada(wartosc):
    """Te wartości idą wprost do literału JS w raporcie pod publicznym adresem.

    Napis w tym miejscu nie jest „złym progiem", tylko treścią do wykonania;
    `True` odpada osobno, bo w Pythonie jest liczbą i przeszłoby jako 1%.
    """
    assert render.progi({"thresholds": {"pressing": wartosc}})["pressing"] == 70


def test_klucz_spoza_zestawu_nie_wchodzi():
    progi = render.progi({"thresholds": {"wymyslony_prog": 10}})
    assert "wymyslony_prog" not in progi


def test_prog_ulamkowy_zostaje_liczba():
    assert render.progi({"thresholds": {"p3": 42.5}})["p3"] == 42.5
    assert render.progi({"thresholds": {"p3": 42.0}})["p3"] == 42


def test_slot_progow_jest_literalem_obiektu_js():
    slot = render.progi_slot()["__PROGI__"]

    assert slot.startswith("{") and slot.endswith("}")
    assert json.loads(slot)["pressing"] == 70
    assert " " not in slot, "zapis kompaktowy — to wchodzi w jedną linię JS"


def test_brak_pliku_progow_przerywa_glosno(monkeypatch):
    """Cicha wartość zastępcza znaczyłaby progi inne niż uzgodnione z klubem."""
    monkeypatch.setattr(render, "PROGI_PLIK", "/nie/ma/takiego/pliku.json")
    with pytest.raises(EngineError):
        render.progi_globalne()


def test_v21_dostaje_progi_a_v17_ich_nie_ma(write_csv, row):
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    html21, _ = render.render(frame, template_path=V21)
    html17, _ = render.render(frame, template_path=V17)

    assert '"pressing":70' in html21
    assert "__PROGI__" not in html21
    assert "PROGI" not in html17, "v17 nie zna tej grupy znaczników i nie ma jej dostać"


# ===========================================================================
# REJESTR SEKCJI


def test_domyslne_sa_podzbiorem_znanych():
    assert set(coverage.DOMYSLNE_SEKCJE) <= set(coverage.ALL_SECTIONS)
    assert set(render.SECTION_DOM_ID) == set(coverage.ALL_SECTIONS)


def test_kafle_z_4b_sa_dostepne_ale_nie_domyslne():
    """Dostępne, a nie domyślne — dokłada je kreator, nie sam fakt istnienia."""
    for sekcja in ("donuty", "okazje", "zawodnicy", "siatka"):
        assert sekcja in coverage.ALL_SECTIONS
        assert sekcja not in coverage.DOMYSLNE_SEKCJE

    for sekcja in ("przeglad", "makro"):
        assert sekcja in coverage.DOMYSLNE_SEKCJE


def test_templat_schematu_1_nie_wylacza_sekcji_ktorej_nie_znal():
    """Klub z istniejącym templatem nie może stracić Przeglądu, którego używa."""
    stary = ["bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam"]
    uzupelnione = coverage.sekcje_z_templatu(stary, {"schema_version": 1})

    assert "przeglad" in uzupelnione and "makro" in uzupelnione
    # Kafle nie-domyślne zostają wyłączone — te są świadomą decyzją kreatora.
    assert "donuty" not in uzupelnione and "siatka" not in uzupelnione
    assert uzupelnione[:len(stary)] == stary, "kolejność templatu zostaje nietknięta"


def test_templat_schematu_2_decyduje_sam():
    """Kreator sekcji wymienia wszystko, co zna — brak na liście to decyzja."""
    wybor = ["bilans", "donuty"]
    assert coverage.sekcje_z_templatu(wybor, {"schema_version": 2}) == wybor


def test_sekcje_niewybrane_znikaja_z_v21(write_csv, row, tmp_path, capsys):
    csv_path = write_csv([
        row("STRZAŁ", begin=60, team="A", x="88", y="34", labels="CELNY"),
        row("ZDOBYCIE SBZ", begin=70, team="A", x="70", y="34", tx="90", ty="34"),
        row("STRATA", begin=80, labels="REAKCJA"),
    ])
    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps({"teams": {
        "us": {"name": "A", "color": "#E6A23C"}, "them": {"name": "B", "color": "#5CA8E0"},
    }}), encoding="utf-8")
    html = tmp_path / "r.html"

    main(["build", "--csv", csv_path, "--config", str(cfg), "--html-template", "v21",
          "--out-html", str(html), "--out-meta", str(tmp_path / "meta.json")])
    capsys.readouterr()
    tresc = html.read_text(encoding="utf-8")

    assert 'id="sec-makro"' in tresc, "makro jest w zestawie domyślnym"
    for sekcja in ("donuty", "okazje", "zawodnicy", "siatka"):
        assert 'id="sec-{}"'.format(sekcja) not in tresc


def test_kazdy_widget_szablonu_ma_sekcje_w_silniku():
    """Kafelek w szablonie bez sekcji w rejestrze byłby niewyłączalny."""
    szablon = render.load_template(V21)
    import re
    w_szablonie = set(re.findall(r'data-widget="([a-z_]+)"', szablon))

    assert w_szablonie == set(coverage.ALL_SECTIONS)


# ===========================================================================
# NAZWISKA W `DATA`


def test_nazwiska_ida_tylko_z_kafelkiem_zawodnikow(write_csv, row, tmp_path, capsys):
    """Raport wisi pod adresem publicznym — skład nie jedzie tam „na zapas"."""
    csv_path = write_csv(
        [row("STRZAŁ", begin=60, team="A", x="88", y="34") + ["Kowalski Jan"]],
        headers=[
            "tag_name", "begin", "end", "team", "labels", "comment",
            "pos_x_meters", "pos_y_meters", "pos_target_x_meters", "pos_target_y_meters",
            "players",
        ],
    )
    cfg = tmp_path / "config.json"
    baza = {"teams": {"us": {"name": "A", "color": "#E6A23C"},
                      "them": {"name": "B", "color": "#5CA8E0"}}}

    def zbuduj(nazwa, sekcje=None):
        cfg.write_text(json.dumps(dict(baza, **({"sections": sekcje} if sekcje else {}))),
                       encoding="utf-8")
        plik = tmp_path / nazwa
        main(["build", "--csv", csv_path, "--config", str(cfg), "--html-template", "v21",
              "--out-html", str(plik), "--out-meta", str(tmp_path / "meta.json")])
        capsys.readouterr()
        return plik.read_text(encoding="utf-8")

    # Zestaw domyślny nie ma kafelka zawodników — nazwisko zostaje na serwerze.
    assert "Kowalski Jan" not in zbuduj("bez.html")

    # Z kafelkiem zawodników nazwisko jedzie, bo raport ma je pokazać.
    z_kafelkiem = zbuduj("z.html", list(coverage.DOMYSLNE_SEKCJE) + ["zawodnicy"])
    assert "Kowalski Jan" in z_kafelkiem


def test_v17_nigdy_nie_dostaje_nazwisk(write_csv, row):
    """Bramka wdrożenia: `DATA` domyślnej generacji ma zostać bez zmian."""
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv(
        [row("STRZAŁ", team="A", x="80", y="30") + ["Kowalski Jan"]],
        headers=[
            "tag_name", "begin", "end", "team", "labels", "comment",
            "pos_x_meters", "pos_y_meters", "pos_target_x_meters", "pos_target_y_meters",
            "players",
        ],
    ))

    html, _ = render.render(frame, template_path=V17)
    assert "Kowalski Jan" not in html
    assert '"player"' not in html
