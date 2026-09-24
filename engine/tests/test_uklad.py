"""Układ sekcji i aliasy zmiennych z templatu klubu (sesja 5).

Dwa twierdzenia, na których stoi ta sesja:

1. TEMPLAT SCHEMATU 1 DAJE DOKŁADNIE TEN SAM RAPORT, CO PRZED SESJĄ 5.
   W bazie leżą takie templaty i mają dalej działać — zgodność wstecz jest
   warunkiem, nie miłym dodatkiem.
2. TEMPLAT SCHEMATU 2 USTAWIA KOLEJNOŚĆ, SZEROKOŚĆ I TYTUŁY, a nazwy zmiennych
   scalają się po aliasach, więc zmiana nazwy taga między sezonami nie rozbija
   jednej serii na dwie.
"""

import json
import re

import pytest

from coachanalyze import render, report_template as tpl
from coachanalyze.cli import main

V21 = render.template_path_for("v21")


def templat(**pola):
    baza = {
        "schema_version": 1,
        "variables": [
            {"id": "v_001", "source": {"type": "tag", "raw": "STRZAŁ"}, "canon": None,
             "display_label": "Strzał", "sections": ["bilans", "mapy"]},
        ],
        "sections_enabled": ["bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam"],
    }
    baza.update(pola)
    return baza


# ===========================================================================
# SCHEMAT 1 — ZGODNOŚĆ WSTECZ


def test_schemat_1_nie_ma_ukladu():
    """Brak układu to `None`, nie „układ domyślny": render nie ma czego ruszać."""
    assert tpl.sections_layout(templat()) is None
    assert tpl.schema_version(templat()) == 1
    assert tpl.schema_version({}) == 1, "brak pola znaczy 1 — tak wyglądają templaty w bazie"


def test_schemat_1_czyta_sekcje_ze_starego_pola():
    assert tpl.sections_enabled(templat()) == [
        "bilans", "mapy", "tl_sbz", "tl_iii", "tl_bilans", "duels", "noteam"
    ]


def test_schemat_1_nie_zmienia_kolejnosci_w_html(write_csv, row):
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    bez, raport_bez = render.render(frame, template_path=V21)
    ze_starym, raport_stary = render.render(
        frame, template_path=V21, report_template=templat()
    )

    kolejnosc = lambda h: re.findall(r'<section id="sec-([a-z0-9]+)"', h)
    assert kolejnosc(bez) == kolejnosc(ze_starym)
    assert raport_stary["sections_order"] == [], "templat bez układu nie przestawia niczego"
    assert 'id="ca-sekcje"' not in bez and 'id="ca-sekcje"' not in ze_starym


def test_schemat_1_z_etykieta_zmienia_JEDYNIE_slownik(write_csv, row):
    """Jedyna różnica, jaką templat schematu 1 robi w v21 od sesji 5.

    `display_label` istniało w templacie od dawna i NIE DOCHODZIŁO do raportu —
    szablon miał własny słownik nazw. Od tej sesji dochodzi, więc klub widzi
    w raporcie nazwę, którą sam wpisał. Zmiana jest zamierzona i dotyczy
    WYŁĄCZNIE generacji v21; układ, kolejność i liczby zostają nietknięte.
    """
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    bez, _ = render.render(frame, template_path=V21)
    ze_starym, _ = render.render(frame, template_path=V21, report_template=templat())

    assert bez.replace('Object.entries({})',
                       'Object.entries({"STRZAŁ":{"display":"Strzał"}})') == ze_starym


# ===========================================================================
# SCHEMAT 2 — UKŁAD


def uklad(*wpisy):
    return templat(schema_version=2, sections=list(wpisy))


def test_uklad_daje_kolejnosc_i_szerokosc():
    wynik = tpl.sections_layout(uklad(
        {"id": "s1", "size": "1", "widgets": ["mapy"], "title": "Mapy klubu"},
        {"id": "s2", "size": "1/2", "widgets": ["donuty"]},
        {"id": "s3", "size": "1/3", "widgets": ["okazje"]},
    ))

    assert [w["widget"] for w in wynik] == ["mapy", "donuty", "okazje"]
    assert [w["span"] for w in wynik] == [6, 3, 2]
    assert wynik[0]["title"] == "Mapy klubu"


def test_sekcja_z_kilkoma_kaflami_splaszcza_sie():
    """DOM v21 jest płaski — tytuł nadpisuje nagłówek tylko pierwszego kafelka."""
    wynik = tpl.sections_layout(uklad(
        {"id": "s1", "size": "1/2", "widgets": ["donuty", "okazje"], "title": "Skuteczność"},
    ))

    assert [w["widget"] for w in wynik] == ["donuty", "okazje"]
    assert [w["span"] for w in wynik] == [3, 3]
    assert [w["title"] for w in wynik] == ["Skuteczność", ""]


@pytest.mark.parametrize("rozmiar", ["2/3", "", None, "1/7", 5])
def test_nieznana_szerokosc_schodzi_na_pelna(rozmiar):
    wynik = tpl.sections_layout(uklad({"id": "s1", "size": rozmiar, "widgets": ["mapy"]}))
    assert wynik[0]["span"] == 6


def test_schemat_2_czyta_sekcje_z_ukladu_a_nie_ze_starego_pola():
    """Jedna lista, nie dwie — dwie rozjeżdżają się przy pierwszej edycji."""
    t = uklad({"id": "s1", "size": "1", "widgets": ["mapy"]},
              {"id": "s2", "size": "1", "widgets": ["duels"]})
    t["sections_enabled"] = ["bilans", "noteam"]   # pole zignorowane przy schemacie 2

    assert tpl.sections_enabled(t) == ["mapy", "duels"]


def test_render_przestawia_sekcje_i_wstawia_siatke(write_csv, row):
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    t = uklad(
        {"id": "s1", "size": "1", "widgets": ["duels"], "title": "Najpierw pojedynki"},
        {"id": "s2", "size": "1/2", "widgets": ["mapy"]},
        {"id": "s3", "size": "1/2", "widgets": ["bilans"]},
    )
    html, raport = render.render(frame, template_path=V21, report_template=t)

    kolejnosc = re.findall(r'<section id="sec-([a-z0-9]+)"', html)
    assert kolejnosc[:3] == ["duels", "mapy", "bilans"]
    assert raport["sections_order"][:3] == ["duels", "mapy", "bilans"]

    assert 'id="ca-sekcje" data-uklad="siatka"' in html
    assert '<section id="sec-mapy" data-widget="mapy" style="grid-column:span 3"' in html
    assert "<h2>Najpierw pojedynki</h2>" in html


def test_uklad_z_samych_pelnych_kafli_nie_wlacza_siatki(write_csv, row):
    """`display: grid` zmienia podział stron przy druku — nie włączamy go bez powodu."""
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    t = uklad({"id": "s1", "size": "1", "widgets": ["duels"]},
              {"id": "s2", "size": "1", "widgets": ["mapy"]})
    html, _ = render.render(frame, template_path=V21, report_template=t)

    assert 'id="ca-sekcje"' not in html
    assert re.findall(r'<section id="sec-([a-z0-9]+)"', html)[:2] == ["duels", "mapy"]


def test_pusty_tytul_zostawia_naglowek_szablonu(write_csv, row):
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    t = uklad({"id": "s1", "size": "1", "widgets": ["duels"], "title": ""})
    html, _ = render.render(frame, template_path=V21, report_template=t)

    assert "Pojedynki, straty, odbiory" in html


# ===========================================================================
# ALIASY ZMIENNYCH


def test_aliasy_i_etykieta_ida_do_szablonu():
    t = templat(variables=[
        {"source": {"type": "tag", "raw": "ZDOBYCIE SBZ"},
         "display_label": "Wejście w SBZ", "aliases": ["SBZ PODAJĄCY", "SBZ NASZA"]},
        {"source": {"type": "label", "raw": "CELNY"}, "display_label": "Celny"},
    ])
    nadpisania = tpl.variable_overrides(t)

    assert nadpisania == {
        "ZDOBYCIE SBZ": {"display": "Wejście w SBZ", "aliases": ["SBZ NASZA", "SBZ PODAJĄCY"]},
    }, "etykieta nie wchodzi do słownika VARS — to przymiotnik, nie zdarzenie"


def test_alias_rowny_wlasnej_nazwie_odpada():
    """Alias na samego siebie zapętliłby scalanie w szablonie."""
    t = templat(variables=[
        {"source": {"type": "tag", "raw": "STRZAŁ"}, "aliases": ["STRZAŁ", "  "]},
    ])
    assert tpl.variable_overrides(t) == {}


def test_szablon_dostaje_literal_z_nadpisaniami(write_csv, row):
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    t = templat(variables=[
        {"source": {"type": "tag", "raw": "ZDOBYCIE SBZ"}, "aliases": ["SBZ PODAJĄCY"]},
    ])
    html, _ = render.render(frame, template_path=V21, report_template=t)

    assert '"ZDOBYCIE SBZ":{"aliases":["SBZ PODAJĄCY"]}' in html
    assert "__VARS_TEMPLATU__" not in html


def test_nazwa_zmiennej_nie_domyka_bloku_skryptu(write_csv, row):
    """Nazwa pochodzi z bazy, a raport wisi pod publicznym adresem (CLAUDE.md §5)."""
    from coachanalyze.sources.livetag import parse
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))

    t = templat(variables=[
        {"source": {"type": "tag", "raw": "ZŁY</script><script>alert(1)"},
         "display_label": "X & Y"},
    ])
    html, _ = render.render(frame, template_path=V21, report_template=t)

    assert "</script><script>alert(1)" not in html
    assert "\\u003c" in html


def test_alias_liczy_sie_razem_ze_zmienna(write_csv, row, tmp_path, capsys):
    """SBZ PODAJĄCY → ZDOBYCIE SBZ: jedna seria, nie dwie.

    Sprawdzamy to na DANYCH W RAPORCIE, bo scalanie robi szablon w przeglądarce:
    silnik wstrzykuje słownik, a nie przepisuje zdarzeń. Gdyby przepisywał,
    tabela `events` i archiwum przestałyby się zgadzać z eksportem.
    """
    csv_path = write_csv([
        row("ZDOBYCIE SBZ", begin=60, team="A", x="80", y="30"),
        row("SBZ PODAJĄCY", begin=120, team="A", x="70", y="30"),
        row("SBZ PODAJĄCY", begin=180, team="A", x="75", y="34"),
    ])
    templat_path = tmp_path / "template.json"
    templat_path.write_text(json.dumps(templat(variables=[
        {"id": "v_001", "source": {"type": "tag", "raw": "ZDOBYCIE SBZ"}, "canon": None,
         "display_label": "Wejście w SBZ", "aliases": ["SBZ PODAJĄCY"],
         "sections": ["bilans", "tl_sbz"]},
    ])), encoding="utf-8")

    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps({"teams": {
        "us": {"name": "A", "color": "#E6A23C"}, "them": {"name": "B", "color": "#5CA8E0"},
    }}), encoding="utf-8")
    html_path = tmp_path / "r.html"

    main(["build", "--csv", csv_path, "--config", str(cfg), "--template", str(templat_path),
          "--html-template", "v21", "--out-html", str(html_path),
          "--out-meta", str(tmp_path / "meta.json")])
    capsys.readouterr()
    tresc = html_path.read_text(encoding="utf-8")

    assert '"SBZ PODAJĄCY"' in tresc, "alias musi dojechać do szablonu"
    # Zdarzenia w `DATA` zostają pod SUROWĄ nazwą — scala dopiero szablon.
    dane = json.loads(re.search(r"const DATA = (.*?);\n", tresc, re.S).group(1))
    assert sorted(e["tag"] for e in dane["events"]) == [
        "SBZ PODAJĄCY", "SBZ PODAJĄCY", "ZDOBYCIE SBZ"
    ]
