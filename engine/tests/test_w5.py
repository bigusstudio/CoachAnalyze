"""Golden layout W5 — zasada nadrzędna: wszystko z pliku LiveTag jest widoczne.

Eksport „na wzór Hetmana" (raport 30, mecz 27) jest SYNTETYCZNY: pliki klienta
nie trafiają do repozytorium (CLAUDE.md §7). Odtwarza to, co zepsuło się na
produkcji — templat klubu, który NIE przypisał `ZDOBYCIE SBZ`, `III STREFA`
ani pojedynków do ich sekcji (jak templat Pogoni v9), a eksport ma te tagi.

  1. pokrycie i raport zgodne co do listy sekcji (`coverage.tagi_sekcji`),
  2. każdy tag z CSV ma wiersz w tabeli makro, także ASYSTA i tag bez zmiennej,
  3. numer zakładki = numer nagłówka = numer slajdu; ukrycie Bilansu przesuwa
     Mapy z 04 na 03,
  4. baner informacyjny obejmuje zmienne-etykiety („STRZAŁ Z SBZ"),
  7. sekcja bez danych jest WYSZARZONA z powodem, nie wycięta (v21); mapa
     III strefy bez pozycji mówi o tym z licznikiem.
"""

import csv
import json
import re
import shutil
import subprocess

import pytest

from coachanalyze import coverage, render
from coachanalyze.cli import main

UKLAD = ("przeglad", "makro", "bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam")

# Tagi eksportu „Hetmana". Pojedynki, straty i odbiory bez drużyny (pułapka 5).
HETMAN = [
    # tag, begin, team, labels, x, y, gracz
    ("STRZAŁ", 60, "POGOŃ", "CELNY,POZYCYJNIE", "88", "34", "Kowalski Jan"),
    ("STRZAŁ", 400, "HETMAN", "NIECELNY", "80", "30", "Rywal Adam"),
    ("Gol", 62, "POGOŃ", "", "", "", "Kowalski Jan"),
    ("ZDOBYCIE SBZ", 120, "POGOŃ", "STRZAŁ,POZYCYJNIE", "70", "34", "Nowak Piotr"),
    ("ZDOBYCIE SBZ", 900, "HETMAN", "BRAK STRZAŁU", "72", "30", "Rywal Adam"),
    ("III STREFA", 200, "POGOŃ", "UDANA", "", "", "Nowak Piotr"),       # bez pozycji
    ("III STREFA", 1200, "HETMAN", "NIEUDANA", "", "", ""),
    ("1x1 OFF", 300, "", "WYGRANY", "", "", "Zieliński Ola"),
    ("1x1 DEF.", 320, "", "PRZEGRANY", "", "", ""),
    ("STRATA", 340, "", "ICH POŁOWA,REAKCJA", "", "", ""),
    ("ODBIÓR", 360, "", "NASZA POŁOWA", "", "", ""),
    ("PIERWSZY KONTAKT", 380, "", "WYGRANY,DRUGI KONTAKT", "", "", ""),
    ("ASYSTA", 58, "POGOŃ", "", "", "", "Nowak Piotr"),
    ("NISKUTECZNY", 1500, "", "STRZAŁ Z SBZ", "", "", ""),
    ("TAG KLUBU BEZ ZMIENNEJ", 1600, "", "", "", "", ""),
]
NAGLOWKI = ["tag_name", "begin", "end", "team", "labels", "comment",
            "pos_x_meters", "pos_y_meters", "pos_target_x_meters", "pos_target_y_meters", "players"]


def _csv(tmp_path, wiersze=HETMAN):
    sciezka = tmp_path / "hetman.csv"
    with open(sciezka, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(NAGLOWKI)
        for tag, b, team, labels, x, y, gracz in wiersze:
            w.writerow([tag, b, b + 5, team, labels, "", x, y, "", "", gracz])
    return str(sciezka)


def _templat(uklad=UKLAD, dodatkowe=()):
    """Templat na wzór Pogoni v9: zmienne tylko w bilansie — osie i pojedynki
    NIE mają przypisanych zmiennych, a szablon i tak je liczy."""
    zmienne = [
        {"id": "v_%03d" % (i + 1), "source": {"type": "tag", "raw": raw}, "canon": None,
         "display_label": raw, "color": "#112233", "sections": ["bilans"], "visible": True, "aliases": []}
        for i, raw in enumerate(("STRZAŁ", "ASYSTA", "NISKUTECZNY") + tuple(dodatkowe))
    ]
    zmienne.append({"id": "v_099", "source": {"type": "label", "raw": "STRZAŁ Z SBZ"}, "canon": None,
                    "display_label": "STRZAŁ Z SBZ", "color": "#112233", "sections": ["bilans"],
                    "visible": True, "aliases": []})
    return {
        "schema_version": 2,
        "team_us_rule": {"markers": ["NASZA", "MASZA"]},
        "sections": [{"id": "s%d" % (i + 1), "size": "1", "widgets": [w], "title": ""}
                     for i, w in enumerate(uklad)],
        "variables": zmienne,
    }


def _zbuduj(tmp_path, capsys, szablon="v21", uklad=UKLAD, wiersze=HETMAN, config_extra=None):
    templat = tmp_path / "template.json"
    templat.write_text(json.dumps(_templat(uklad)), encoding="utf-8")
    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps(dict({
        "teams": {"us": {"name": "POGOŃ", "color": "#E6A23C"},
                  "them": {"name": "HETMAN", "color": "#5CA8E0"}},
    }, **(config_extra or {}))), encoding="utf-8")
    html = tmp_path / "r.html"
    meta = tmp_path / "meta.json"
    main(["build", "--csv", _csv(tmp_path, wiersze), "--config", str(cfg), "--template", str(templat),
          "--html-template", szablon, "--out-html", str(html), "--out-meta", str(meta)])
    capsys.readouterr()
    return html.read_text(encoding="utf-8"), json.loads(meta.read_text(encoding="utf-8"))


def _widgety(html):
    """Kolejność kafelków w dokumencie — dokładnie to, po czym numeruje skrypt."""
    return re.findall(r'<section (?:data-brak-danych="1" )?id="[^"]+" data-widget="([^"]+)"', html)


# ----------------------------------------------------------------- 1. pokrycie
def test_pokrycie_hetmana_widzi_osie_i_pojedynki(tmp_path, capsys):
    _html, meta = _zbuduj(tmp_path, capsys)
    niedostepne = {s["id"]: s["reason"] for s in meta["sections_unavailable"]}
    for sekcja in ("mapy", "tl_sbz", "tl_iii", "duels"):
        assert sekcja in meta["sections_available"], (sekcja, niedostepne.get(sekcja))


def test_pokrycie_i_raport_zgodne_co_do_listy_sekcji(tmp_path, capsys):
    html, meta = _zbuduj(tmp_path, capsys)
    w_raporcie = _widgety(html)
    wyszarzone = set(re.findall(r'<section data-brak-danych="1" id="[^"]+" data-widget="([^"]+)"', html))
    assert w_raporcie == list(UKLAD), "raport ma DOKŁADNIE sekcje z Układu, w jego kolejności"
    assert set(meta["sections_available"]) == set(UKLAD) - wyszarzone
    assert wyszarzone == {s["id"] for s in meta["sections_unavailable"]}


def test_tagi_sekcji_zgodne_z_szablonem():
    """Kopia listy tagów w silniku ma odpowiadać temu, co liczy JS szablonu."""
    szablon = render.load_template(render.template_path_for("v21"))
    for sekcja, tagi in coverage.TAGI_SEKCJI_SZABLONU.items():
        for tag in tagi:
            assert "'%s'" % tag in szablon, (sekcja, tag)


def test_alias_silnika_liczy_sie_do_osi(tmp_path, capsys):
    wiersze = [w for w in HETMAN if w[0] != "ZDOBYCIE SBZ"] + [
        ("SBZ PODAJĄCY", 130, "POGOŃ", "STRZAŁ", "70", "34", "")]
    _html, meta = _zbuduj(tmp_path, capsys, wiersze=wiersze)
    assert "tl_sbz" in meta["sections_available"]


# ------------------------------------------------ 7. wyszarzenie, nie wycięcie
def test_sekcja_bez_danych_wyszarzona_z_powodem_w_v21(tmp_path, capsys):
    wiersze = [w for w in HETMAN if w[0] != "III STREFA"]
    html, meta = _zbuduj(tmp_path, capsys, wiersze=wiersze)
    assert "tl_iii" in {s["id"] for s in meta["sections_unavailable"]}
    blok = re.search(r'<section data-brak-danych="1" id="sec-tl3".*?</section>', html, re.S)
    assert blok, "sekcja bez danych zostaje w raporcie"
    assert "Brak danych w tym eksporcie." in blok.group(0) and "<h2>" in blok.group(0)
    assert 'id="tl3"' not in blok.group(0), "treść zastąpiona komunikatem"


def test_v17_bez_zmian_wycina_niedostepne(tmp_path, capsys):
    wiersze = [w for w in HETMAN if w[0] != "III STREFA"]
    html, _meta = _zbuduj(tmp_path, capsys, szablon="v17", wiersze=wiersze)
    assert "data-brak-danych" not in html


def test_mapa_iii_strefy_bez_pozycji_ma_komunikat_i_licznik():
    szablon = render.load_template(render.template_path_for("v21"))
    assert 'id="np4"' in szablon
    assert "mapaBezPozycji('mp4','np4',I3" in szablon
    assert "bez pozycji w eksporcie — nie ma ich na mapie" in szablon


# ------------------------------------------------------------ 3. numeracja
def _numery_js(html):
    """Symulacja numeracji skryptu: kolejne kafelki z `nav` w porządku dokumentu."""
    return {w: "%02d" % (i + 1) for i, w in enumerate(_widgety(html))}


def test_ukrycie_bilansu_przesuwa_mapy_na_03(tmp_path, capsys):
    html, _ = _zbuduj(tmp_path, capsys)
    assert _numery_js(html)["mapy"] == "04" and _numery_js(html)["bilans"] == "03"
    bez_bilansu = tuple(w for w in UKLAD if w != "bilans")
    html, _ = _zbuduj(tmp_path, capsys, uklad=bez_bilansu)
    assert "bilans" not in _widgety(html)
    assert _numery_js(html)["mapy"] == "03" and _numery_js(html)["tl_sbz"] == "04"


def test_numer_slajdu_z_naglowka_sekcji():
    szablon = render.load_template(render.template_path_for("v21"))
    slajdy = szablon[szablon.index("function slideDefs()"):szablon.index("const STYLE_PROPS")]
    assert "el.querySelector('h2 .nr')" in slajdy, "slajd bierze numer z nagłówka, nie z własnego licznika"


# ------------------------------------------------------------ 4. baner
def test_baner_informacyjny_obejmuje_zmienna_etykiete():
    cfg = {"dictionary_notice": {"auto_added": ["STRZAŁ Z SBZ"]}}
    assert render.dodane_automatycznie(_templat(), cfg) == ["STRZAŁ Z SBZ"]


def test_club_ignored_tags_nie_zmienia_raportu(tmp_path, capsys):
    a, _ = _zbuduj(tmp_path, capsys)
    b, _ = _zbuduj(tmp_path, capsys, config_extra={"dictionary_notice": {"ignored": ["ASYSTA", "TAG KLUBU BEZ ZMIENNEJ"]}})
    assert a == b


# ------------------------------------------------------------ 2. makro
NODE = shutil.which("node")


@pytest.mark.skipif(NODE is None, reason="brak node — wiersze makro niesprawdzone")
def test_kazdy_tag_z_csv_ma_wiersz_w_tabeli_makro(tmp_path, capsys):
    html, _ = _zbuduj(tmp_path, capsys)
    blok = re.search(r"/\*<liczby-v21>\*/(.*?)/\*</liczby-v21>\*/", html, re.S).group(1)
    dane = re.search(r"const DATA = (.*?);\n", html, re.S).group(1)
    vars_ = re.search(r"const VARS = (\{.*?\n\});", html, re.S).group(1)
    kod = blok + "\nconst VARS=" + vars_ + ";const D=" + dane + ";" \
        "console.log(JSON.stringify(kluczeMakro(D.events, VARS)));"
    wynik = subprocess.run([NODE, "-e", kod], capture_output=True, text=True, timeout=30)
    assert wynik.returncode == 0, wynik.stderr
    wiersze = json.loads(wynik.stdout)
    tagi_csv = {w[0] for w in HETMAN}
    assert set(wiersze) == tagi_csv, "zasada nadrzędna: liczba tagów w CSV = liczba wierszy makro"
    assert len(wiersze) == len(tagi_csv)
    assert "ASYSTA" in wiersze and "TAG KLUBU BEZ ZMIENNEJ" in wiersze
