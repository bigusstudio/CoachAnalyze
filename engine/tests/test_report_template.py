"""Templat raportu jako wejscie pipeline'u (Sesja 5 przebudowy).

Eksport referencyjny ma KOMPLET sekcji dostepnych, wiec bramka regresji nie
cwiczy na nim usuwania — te przypadki musza przyjsc z syntetyku. Bez tego
`drop_sections` bylby przetestowany asercja `[] == []`.
"""

import pytest

from coachanalyze import canon, coverage, render, report_template as tpl
from coachanalyze.sources.livetag import parse


def templat(variables, sections=None, markers=None):
    return {
        "schema_version": 1,
        "team_us_rule": {"markers": markers if markers is not None else ["NASZA", "MASZA"]},
        "sections_enabled": sections if sections is not None else list(coverage.ALL_SECTIONS),
        "variables": variables,
    }


def zmienna(raw, canon_value, typ="tag", **nadpisz):
    baza = {
        "id": "v_" + raw[:6],
        "source": {"type": typ, "raw": raw},
        "canon": canon_value,
        "display_label": raw.title(),
        "color": "#8899AA",
        "sections": ["bilans", "tl_bilans"],
        "visible": True,
    }
    baza.update(nadpisz)
    return baza


# ---------------------------------------------------------------- brak templatu
def test_brak_templatu_nie_zmienia_niczego():
    """`None` ma dawac wejscie nietkniete, a nie „rozsadna wartosc domyslna"."""
    assert tpl.load(None) is None
    assert tpl.sections_enabled(None) is None
    assert tpl.team_markers(None) == []
    assert tpl.mapping_profile(None) is None
    assert tpl.generic_variables(None) == []


def test_templat_bez_zmiennych_nie_daje_profilu():
    """Pusta lista zmiennych nie moze skasowac slownika domyslnego silnika —
    inaczej templat bez zmiennych wyzerowalby caly raport."""
    assert tpl.mapping_profile(templat([])) is None


# ---------------------------------------------------------------- mapowanie
def test_tag_z_templatu_nadpisuje_slownik_domyslny(write_csv, row):
    """Klub moze nazwac strzal po swojemu i templat ma to unieść."""
    frame = parse.prep_frame(write_csv([row("NASZ STRZAL", team="A", x="80", y="30")]))

    bez = canon.build(frame)
    z_tpl = canon.build(frame, report_template=templat([zmienna("NASZ STRZAL", "shot")]))

    assert bez["events"][0]["concept"] is None, "bez templatu tag jest nieznany"
    assert z_tpl["events"][0]["concept"] == "shot"


def test_zmienna_bez_pojecia_jest_ZNANA_a_nie_nierozpoznana(write_csv, row):
    """Roznica jest istotna: brak wpisu znaczy „silnik tego nie zna" i laduje
    w `unmapped_tags`; jawne `canon: null` znaczy „czlowiek zdecydowal, ze to
    zmienna niestandardowa". Pierwsze jest usterka, drugie decyzja."""
    frame = parse.prep_frame(write_csv([row("WYSOKIE WYBICIE", team="A")]))

    z_tpl = canon.build(frame, report_template=templat([zmienna("WYSOKIE WYBICIE", None)]))
    meta = coverage.build_meta(frame, z_tpl)

    assert z_tpl["events"][0]["concept"] is None
    assert meta["unmapped_tags"] == [], "decyzja czlowieka nie jest brakiem mapowania"
    assert meta["coverage"]["unanalysed"] == 1, "ale zdarzenie nadal jest poza metrykami"


def test_etykieta_z_templatu_mapuje_sie_na_kwalifikator(write_csv, row):
    frame = parse.prep_frame(write_csv([row("STRZAŁ", labels="NASZA ETYKIETA", x="80", y="30")]))

    z_tpl = canon.build(frame, report_template=templat([
        zmienna("NASZA ETYKIETA", "on_target", typ="label"),
    ]))

    assert "on_target" in z_tpl["events"][0]["qualifiers"]


def test_zdarzenia_nie_znikaja_przy_templacie(write_csv, row):
    """Suma zdarzen kanonicznych zawsze rowna sie liczbie wierszy eksportu."""
    frame = parse.prep_frame(write_csv([
        row("STRZAŁ", x="80", y="30"), row("COS OBCEGO"), row("STRATA"),
    ]))
    wynik = canon.build(frame, report_template=templat([zmienna("STRZAŁ", "shot")]))

    assert len(wynik["events"]) == 3


# ---------------------------------------------------------------- markery druzyn
def test_marker_przypisuje_nasza_druzyne(write_csv, row):
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="NASZA", x="80", y="30")]))

    wynik = canon.build(frame, report_template=templat([zmienna("STRZAŁ", "shot")]))

    assert wynik["events"][0]["team_side"] == "us"


def test_literowka_MASZA_jest_dana_templatu_a_nie_regula_w_kodzie(write_csv, row):
    """Pulapka 9. Kolejny klub moze miec wlasna literowke i nie ma to wymagac
    zmiany w silniku — dlatego markery sa w templacie."""
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="MASZA", x="80", y="30")]))

    z_marker = canon.build(frame, report_template=templat([zmienna("STRZAŁ", "shot")]))
    bez_markera = canon.build(frame, report_template=templat(
        [zmienna("STRZAŁ", "shot")], markers=["NASZA"],
    ))

    assert z_marker["events"][0]["team_side"] == "us"
    assert bez_markera["events"][0]["team_side"] == "none", (
        "bez markera literowka nie ma sie mapowac po cichu"
    )


def test_nazwa_klubu_z_konfiguracji_wygrywa_z_markerem(write_csv, row):
    """Marker jest ogolniejszy niz nazwa klubu i nie moze jej przykryc."""
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="KLUB B", x="80", y="30")]))

    wynik = canon.build(
        frame,
        teams={"us": {"name": "KLUB A"}, "them": {"name": "KLUB B"}},
        report_template=templat([zmienna("STRZAŁ", "shot")], markers=["KLUB B"]),
    )

    assert wynik["events"][0]["team_side"] == "them"


# ---------------------------------------------------------------- sekcje
def test_sekcje_z_templatu_ograniczaja_pokrycie(write_csv, row):
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))
    meta = coverage.build_meta(frame, canon.build(frame), config={"sections": ["bilans", "mapy"]})

    assert meta["sections_available"] == ["bilans", "mapy"]


def test_drop_sections_usuwa_wskazane_i_zostawia_reszte(write_csv, row):
    """Sedno mostka PRZEJSCIOWEGO do S5b — sprawdzone na prawdziwym HTML-u."""
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))
    html, _ = render.render(frame)

    for sid, dom in render.SECTION_DOM_ID.items():
        assert 'id="{}"'.format(dom) in html, "szablon ma miec sekcje {}".format(sid)

    okrojony, usuniete = render.drop_sections(html, ["mapy", "duels"])

    assert usuniete == ["mapy", "duels"]
    assert 'id="sec-mapy"' not in okrojony
    assert 'id="sec-duels"' not in okrojony
    assert 'id="sec-bilans"' in okrojony, "sekcje nieusuwane maja zostac"
    assert len(okrojony) < len(html)


def test_drop_sections_bez_listy_nie_rusza_html(write_csv, row):
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))
    html, _ = render.render(frame)

    assert render.drop_sections(html, [])[0] == html
    assert render.drop_sections(html, None)[0] == html


def test_drop_sections_ignoruje_nieznana_sekcje(write_csv, row):
    """Nieznana nazwa nie ma prawa uszkodzic HTML-a — zostaje zglaszana
    przez `build_sections` jako powod, a nie przez wycinanie na oslep."""
    frame = parse.prep_frame(write_csv([row("STRZAŁ", team="A", x="80", y="30")]))
    html, _ = render.render(frame)

    okrojony, usuniete = render.drop_sections(html, ["wykres_3d"])

    assert okrojony == html
    assert usuniete == []


# ---------------------------------------------------------------- stempel
def test_stempel_niesie_wersje_i_date():
    blok = render.stamp_block(3, "2026-08-19 12:00")

    assert "templat v3" in blok
    assert "2026-08-19 12:00" in blok


def test_brak_wersji_to_brak_stempla():
    """Raport sprzed ery templatow nie ma czym sie stemplowac."""
    assert render.stamp_block(None, "2026-08-19") == ""


def test_stempel_nie_zalezy_od_klas_szablonu():
    """Doklejka ma byc samowystarczalna — tak samo jak blok indeksu (M1).
    Zaleznosc od klasy szablonu pekaby przy jego kolejnej generacji."""
    blok = render.stamp_block(1, None)

    assert "class=" not in blok
    assert "style=" in blok


# ---------------------------------------------------------------- generyczne
def test_generic_variables_wybiera_tylko_bez_pojecia():
    t = templat([
        zmienna("STRZAŁ", "shot"),
        zmienna("WYSOKIE WYBICIE", None),
        zmienna("INNY", None),
    ])

    assert [z["source"]["raw"] for z in tpl.generic_variables(t)] == [
        "WYSOKIE WYBICIE", "INNY",
    ]
