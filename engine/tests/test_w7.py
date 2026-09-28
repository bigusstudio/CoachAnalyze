"""W7 — metoda importu v3: parser JSON (A), warstwa 1 (B), znaczenia (C),
profil (D), gol/wynik/strzał (F), anomalie (G), niezmienniki (H).

Wyłącznie dane syntetyczne (CLAUDE.md §7). Nazwy klubów i tagów zmyślone,
kształty — takie, jakie widać w korpusie klienta (raport testu W7-T).
"""

import json

import pytest

from coachanalyze import canon, kontrola, metoda, plik, render, znaczenie
from coachanalyze.cli import main
from coachanalyze.sources.livetag import parse, projekt as projekt_mod

HEADERS_Z_ZAWODNIKIEM = [
    "tag_name", "begin", "end", "players", "labels", "team", "comment",
    "pos_x_meters", "pos_y_meters", "pos_target_x_meters", "pos_target_y_meters",
]

UUID = {n: "00000000-0000-0000-0000-%012d" % i for i, n in enumerate([
    "Strzał", "Gol", "Posiadanie Alfa", "Posiadanie Gamma", "P2 Podanie",
    "P2 Przyjmujący", "Odbiór", "STRATA", "Nieużywany",
])}


def projekt_json(tmp_path, tagi=None, druzyny=("ALFA", "BETA"), etykiety=("Pozycyjny",)):
    tagi = tagi or {}
    deps = []
    for nazwa, uuid in UUID.items():
        dane = {"name": nazwa, "uuid": uuid, "color": "0.5 0.5 0.5",
                "time_before": 5, "time_after": 5, "params": {"cm": False, "version": 1}}
        dane.update(tagi.get(nazwa) or {})
        deps.append({"type": "tag", "data": dane})
    for d in druzyny:
        deps.append({"type": "team", "data": {"name": d, "uuid": "t-" + d}})
    for e in etykiety:
        deps.append({"type": "label", "data": {"name": e, "uuid": "l-" + e, "color": "0.1 0.2 0.3"}})
    deps.append({"type": "player", "data": {"name": "Zawodnik Jeden", "uuid": "p1", "team_uuid": "t-ALFA"}})
    sciezka = tmp_path / "projekt.json"
    sciezka.write_text(json.dumps({"dependencies": deps, "software": {"version": "1.12.21"}}),
                       encoding="utf-8")
    return str(sciezka)


def wiersz(tag, b, e=None, team="", labels="", comment="", x="", y="", tx="", ty="", player=""):
    return [tag, b, e if e is not None else b + 10, player, labels, team, comment, x, y, tx, ty]


# =========================================================================== A
def test_projekt_czyta_cm_i_aktywacje_po_nazwach(tmp_path):
    sciezka = projekt_json(tmp_path, tagi={
        "Posiadanie Alfa": {"params": {"cm": True, "deactivation_tags": [UUID["Posiadanie Gamma"]]}},
        "P2 Podanie": {"params": {"cm": False, "activation_tags": [UUID["P2 Przyjmujący"], "brak-uuid"]}},
    })
    p = projekt_mod.wczytaj(sciezka)
    assert p["wersja_livetag"] == "1.12.21"
    assert p["po_nazwie"]["Posiadanie Alfa"]["cm"] is True
    assert p["po_nazwie"]["Posiadanie Alfa"]["dezaktywuje"] == ["Posiadanie Gamma"]
    assert p["po_nazwie"]["P2 Podanie"]["aktywuje"] == ["P2 Przyjmujący"]
    assert p["nieznane_uuid"] == ["brak-uuid"], "uuid bez definicji nie znika po cichu"
    assert p["druzyny"] == ["ALFA", "BETA"] and p["zawodnicy"] == ["Zawodnik Jeden"]


def test_projekt_bez_cm_to_brak_informacji_nie_falsz():
    p = projekt_mod.z_danych({"dependencies": [
        {"type": "tag", "data": {"name": "X", "uuid": "u1", "params": {}}}]})
    assert p["po_nazwie"]["X"]["cm"] is None


@pytest.mark.parametrize("komentarz,xg", [
    ("xG 0,55", 0.55), ("x 0,14", 0.14), ("X 0.81", 0.81), ("Xg 0,75", 0.75),
    ("XG:0,2", 0.2), ("xG = 0.3", 0.3), ("3 zawodników w polu karnym", None),
    ("xG 1,2", None),
])
def test_ksztalt_xg(komentarz, xg):
    assert parse.parse_xg(komentarz) == xg


def test_sha256_zdarzen_niezalezny_od_kolejnosci(write_csv):
    a = [wiersz("Strzał", 10, team="ALFA", comment="xG 0,1"), wiersz("Gol", 11, team="ALFA")]
    p1 = write_csv(a, headers=HEADERS_Z_ZAWODNIKIEM, name="a.csv")
    p2 = write_csv(list(reversed(a)), headers=HEADERS_Z_ZAWODNIKIEM, name="b.csv")
    p3 = write_csv(a[:1], headers=HEADERS_Z_ZAWODNIKIEM, name="c.csv")
    h = [parse.prep_frame(p)["sha256_zdarzen"] for p in (p1, p2, p3)]
    assert h[0] == h[1] != h[2]


def test_sha256_zdarzen_zgodny_z_narzedziem_w7t(write_csv):
    """Ten sam algorytm co w karcie dowodowej — identyfikatory meczów z raportu testu."""
    import sys, pathlib
    sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "tools"))
    import karta_dowodowa
    p = write_csv([wiersz("Strzał", 10, labels="A, B", comment="xG 0,1")], headers=HEADERS_Z_ZAWODNIKIEM)
    rows, _ = parse.read_rows(p)
    assert karta_dowodowa.sha256_zdarzen(rows) == parse.sha256_zdarzen(rows)


# =========================================================================== C
def _ramka(write_csv, wiersze):
    return parse.prep_frame(write_csv(wiersze, headers=HEADERS_Z_ZAWODNIKIEM))


def test_nazwa_po_normalizacji_strzal_to_STRZAL(write_csv):
    """Raport 32: Stal taguje „Strzał" — to ta sama nazwa co STRZAŁ."""
    f = _ramka(write_csv, [wiersz("Strzał", 10, team="ALFA"), wiersz("1x1 def", 20)])
    z = znaczenie.rozstrzygnij(f)
    assert (z["Strzał"]["pojecie"], z["Strzał"]["zrodlo"], z["Strzał"]["klucz"]) == ("shot", "nazwa", "STRZAŁ")
    assert z["1x1 def"]["klucz"] == "1x1 DEF.", "kropka i wielkość liter nie zmieniają nazwy"


def test_normalizacja_nie_jest_dopasowaniem_fragmentu():
    """Pułapka 7: `SBZ PODAJĄCY/OTRZYMUJĄCY` to nie `SBZ PODAJĄCY`."""
    assert znaczenie.normalizuj("SBZ PODAJĄCY/OTRZYMUJĄCY") != znaczenie.normalizuj("SBZ PODAJĄCY")
    assert znaczenie.normalizuj("  Strzał. ") == znaczenie.normalizuj("STRZAŁ")


def test_xg_w_komentarzu_daje_strzal(write_csv):
    f = _ramka(write_csv, [wiersz("Uderzenie", 10, comment="xG 0,3")])
    z = znaczenie.rozstrzygnij(f)["Uderzenie"]
    assert (z["pojecie"], z["zrodlo"], z["klucz"]) == ("shot", "xg", "STRZAŁ")


def test_slownik_wygrywa_z_xg_i_nazwa(write_csv):
    f = _ramka(write_csv, [wiersz("Strzał", 10, comment="xG 0,3")])
    tpl = {"variables": [{"source": {"type": "tag", "raw": "Strzał"}, "canon": "pass"}]}
    z = znaczenie.rozstrzygnij(f, template=tpl)["Strzał"]
    assert (z["pojecie"], z["zrodlo"]) == ("pass", "slownik")


def test_zmienna_bez_pojecia_z_importu_nie_blokuje_regul(write_csv):
    """Import dopisuje tagi do templatu z `canon: null` — to nie decyzja człowieka."""
    f = _ramka(write_csv, [wiersz("Strzał", 10)])
    tpl = {"variables": [{"source": {"type": "tag", "raw": "Strzał"}, "canon": None}]}
    assert znaczenie.rozstrzygnij(f, template=tpl)["Strzał"]["pojecie"] == "shot"


def test_slownik_po_uuid_przezywa_zmiane_nazwy(write_csv, tmp_path):
    """D: przypisanie ze Słownika po UUID tagu, gdy analityk przemianował tag."""
    f = _ramka(write_csv, [wiersz("P2 Podanie", 10)])
    p = projekt_mod.wczytaj(projekt_json(tmp_path))
    tpl = {"variables": [{"source": {"type": "tag", "raw": "Podanie strefa 2"}, "canon": "pass",
                          "uuids": [UUID["P2 Podanie"]]}]}
    assert znaczenie.rozstrzygnij(f, p, tpl)["P2 Podanie"]["pojecie"] == "pass"


def test_slownik_zapasowo_po_nazwie_znormalizowanej(write_csv):
    f = _ramka(write_csv, [wiersz("p2 podanie", 10)])
    tpl = {"variables": [{"source": {"type": "tag", "raw": "P2 Podanie"}, "canon": "pass"}]}
    assert znaczenie.rozstrzygnij(f, template=tpl)["p2 podanie"]["pojecie"] == "pass"


def test_brak_reguly_zostawia_tag_bez_interpretacji(write_csv):
    f = _ramka(write_csv, [wiersz("Akcja def. PK", 10)])
    z = znaczenie.rozstrzygnij(f)["Akcja def. PK"]
    assert z["pojecie"] is None and z["zrodlo"] is None and z["klucz"] is None


def test_posiadanie_dostaje_strone_nie_nazwe_klubu(write_csv):
    """Nazwa tagu nie mówi, czyja to akcja — strona tylko ze Słownika."""
    f = _ramka(write_csv, [wiersz("Posiadanie Alfa", 10, e=40), wiersz("Posiadanie Gamma", 50, e=70)])
    assert znaczenie.rozstrzygnij(f)["Posiadanie Alfa"]["strona"] is None
    tpl = {"variables": [{"source": {"type": "tag", "raw": "Posiadanie Gamma"}, "canon": "possession",
                          "side": "us"}]}
    wynik = canon.build(f, teams={"us": {"name": "ALFA"}, "them": {"name": "BETA"}},
                        znaczenia=znaczenie.rozstrzygnij(f, template=tpl))
    assert [e["team_side"] for e in wynik["events"]] == ["none", "us"]


def test_znaczenia_w_modelu_kanonicznym_i_vars(write_csv):
    f = _ramka(write_csv, [wiersz("Strzał", 10, team="ALFA", comment="xG 0,4")])
    z = znaczenie.rozstrzygnij(f)
    wynik = canon.build(f, znaczenia=z)
    assert wynik["events"][0]["concept"] == "shot" and wynik["events"][0]["xg"] == 0.4
    # W7-b: znaczenie NIE jest aliasem zmiennej — jedzie osobno w PLIK.klucze.
    slot = json.loads(render.vars_slot(None, z)["__VARS_TEMPLATU__"])
    assert "Strzał" not in str(slot)
    assert json.loads(render.plik_slot({}, {}, znaczenia=z)["__PLIK__"])["klucze"] == {"Strzał": "STRZAŁ"}


def test_kropka_to_samo_znaczenie_osobne_zmienne(write_csv, tmp_path, capsys):
    """W7-b: „1x1 DEF" i „1x1 DEF." — jedno ZNACZENIE, dwie ZMIENNE.

    Normalizacja (casefold, spacje, kropki) rozstrzyga wyłącznie znaczenie.
    Tożsamość, warstwa 1, tabela makro i Słownik zostają przy nazwie z pliku 1:1.
    """
    wiersze = [wiersz("1x1 DEF", 10, labels="WYGRANY"), wiersz("1x1 DEF.", 20, labels="PRZEGRANY"),
               wiersz("1x1 DEF.", 30, labels="WYGRANY")]
    f = _ramka(write_csv, wiersze)
    z = znaczenie.rozstrzygnij(f)
    assert (z["1x1 DEF"]["pojecie"], z["1x1 DEF"]["kwalifikatory"]) == \
           (z["1x1 DEF."]["pojecie"], z["1x1 DEF."]["kwalifikatory"]) == ("duel", ["defensive"])
    assert z["1x1 DEF"]["klucz"] == z["1x1 DEF."]["klucz"] == "1x1 DEF."
    w1 = plik.zbuduj(f, znaczenia=z)
    assert {t["nazwa"]: t["n"] for t in w1["tagi"]} == {"1x1 DEF.": 2, "1x1 DEF": 1}
    slot = json.loads(render.vars_slot(None, z)["__VARS_TEMPLATU__"])
    assert "1x1 DEF" not in json.dumps(slot, ensure_ascii=False).replace("1x1 DEF.", "")

    _build(write_csv, tmp_path, wiersze, capsys)
    w = _wykonaj(tmp_path / "r.html")
    assert "1x1 DEF (liczony jako 1x1 w defensywie)" in w["makro"], w["makro"]
    assert "1x1 w defensywie" in w["makro"].replace("(liczony jako 1x1 w defensywie)", ""), "osobny wiersz 1x1 DEF."
    assert "1x1 wygrane 2" in w["kpi"], "oba warianty liczą się do pojedynków"


def test_decyzja_nie_analizuj_z_kreatora_jest_szanowana(write_csv):
    f = _ramka(write_csv, [wiersz("Strzał", 10, comment="xG 0,4")])
    profil = {"rules": [{"match": {"tag": "Strzał"}, "concept": None}]}
    wynik = canon.build(f, mapping_profile=profil, znaczenia=znaczenie.rozstrzygnij(f))
    assert wynik["events"][0]["concept"] is None


# =========================================================================== B
def test_warstwa1_wszystko_z_pliku(write_csv, tmp_path):
    sciezka = projekt_json(tmp_path, tagi={
        "Posiadanie Alfa": {"params": {"cm": True}},
        "P2 Podanie": {"params": {"cm": False, "activation_tags": [UUID["P2 Przyjmujący"]]}},
    }, etykiety=("Pozycyjny", "Nieużyta"))
    f = _ramka(write_csv, [
        wiersz("P2 Podanie", 10, e=20, team="ALFA", labels="Pozycyjny", x="30", y="20", tx="40", ty="25",
               player="Zawodnik Jeden"),
        wiersz("P2 Przyjmujący", 15, e=25),
        wiersz("P2 Podanie", 100, e=110, team="GAMMA"),
        wiersz("Posiadanie Alfa", 0, e=60), wiersz("Posiadanie Alfa", 30, e=90),
        wiersz("Strzał", 3000, team="BETA", comment="xG 0,25"),
        wiersz("Strzał", 3100, team="BETA", comment="xG 0,5"),
    ])
    p = projekt_mod.wczytaj(sciezka)
    w1 = plik.zbuduj(f, projekt=p, znaczenia=znaczenie.rozstrzygnij(f, p))
    tagi = {t["nazwa"]: t for t in w1["tagi"]}

    assert w1["zdarzen"] == 7 and sum(t["n"] for t in w1["tagi"]) == 7
    assert [n for n, _ in w1["druzyny"]] == ["BETA", "ALFA", "GAMMA"], "trzecia drużyna pod własną nazwą"
    podanie = tagi["P2 Podanie"]
    assert podanie["d"] == [0, 1, 1] and podanie["bez"] == 0 and podanie["n"] == 2
    assert podanie["pozycje"] == [[30.0, 20.0, 40.0, 25.0, 1, 1]], "wektor przy pos_target"
    assert tagi["Posiadanie Alfa"]["cm"] is True
    assert tagi["Posiadanie Alfa"]["czas_s"] == 90.0, "nakładające się przedziały liczone raz"
    assert tagi["Strzał"]["xg_n"] == 2 and tagi["Strzał"]["xg_suma"] == 0.75
    assert w1["pary"] == [{"a": "P2 Podanie", "b": "P2 Przyjmujący", "n": 1, "z": 2}]
    assert ["Pozycyjny", 1] in [list(x) for x in w1["etykiety"]]
    assert w1["etykiety_bez_zdarzen"] == ["Nieużyta"]
    assert [list(x) for x in w1["zawodnicy"]] == [["Zawodnik Jeden", 1]]
    assert "Nieużywany" in w1["tagi_bez_zdarzen"]


# =========================================================================== F
def test_gol_druzyna_ze_strzalu_mimo_wypelnionej_kolumny(write_csv, tmp_path):
    """Jędrzejów: wiersz gola ma POGOŃ, strzał — NAPRZÓD. Liczy się strzał; rozbieżność = anomalia."""
    sciezka = projekt_json(tmp_path, tagi={"Gol": {"time_before": 5}, "Strzał": {"time_before": 3}})
    f = _ramka(write_csv, [
        wiersz("Strzał", 1302, team="NAPRZÓD", comment="xG 0,2"),
        wiersz("Gol", 1300, team="POGOŃ"),
    ])
    ramka, stan = metoda.przygotuj(f, sciezka)
    assert stan["tagi_goli"] == ["Gol"] and stan["tagi_strzalow"] == ["Strzał"]
    assert ramka["events"][1]["team"] == "NAPRZÓD"
    assert f["events"][1]["team"] == "POGOŃ", "ramka z pliku nietknięta (warstwa 1)"
    typy = [a["typ"] for a in kontrola.anomalie(f, stan["projekt"], stan["znaczenia"], stan["przypisanie_goli"])]
    assert "gol_inna_druzyna_niz_strzal" in typy


def test_gol_bez_strzalu_to_anomalia(write_csv):
    f = _ramka(write_csv, [wiersz("Strzał", 10, team="ALFA"), wiersz("Gol", 900, team="ALFA")])
    ramka, stan = metoda.przygotuj(f)
    assert ramka["events"][1]["team"] is None
    typy = [a["typ"] for a in kontrola.anomalie(f, None, stan["znaczenia"], stan["przypisanie_goli"])]
    assert typy == ["gol_bez_strzalu"]


def _build(write_csv, tmp_path, wiersze, capsys, config=None, template=None):
    csv_path = write_csv(wiersze, headers=HEADERS_Z_ZAWODNIKIEM)
    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps(config or {"teams": {"us": {"name": "ALFA"}, "them": {"name": "BETA"}}}),
                   encoding="utf-8")
    argv = ["build", "--csv", csv_path, "--config", str(cfg), "--html-template", "v21",
            "--out-html", str(tmp_path / "r.html"), "--out-meta", str(tmp_path / "m.json"),
            "--out-events", str(tmp_path / "e.json")]
    if template is not None:
        t = tmp_path / "t.json"
        t.write_text(json.dumps(template), encoding="utf-8")
        argv += ["--template", str(t)]
    assert main(argv) == 0
    capsys.readouterr()
    return (json.loads((tmp_path / "m.json").read_text(encoding="utf-8")),
            (tmp_path / "r.html").read_text(encoding="utf-8"),
            json.loads((tmp_path / "e.json").read_text(encoding="utf-8")))


def _plik(html):
    start = html.index("const PLIK = ") + len("const PLIK = ")
    return json.loads(html[start:html.index(";\n", start)])


def test_brak_tagu_gola_i_wyniku_strzalu(write_csv, tmp_path, capsys):
    """Stal: brak tagu gola i etykiet wyniku — „brak w eksporcie", nigdy 0:0."""
    meta, html, _ = _build(write_csv, tmp_path, [
        wiersz("Strzał", 10, team="ALFA", comment="xG 0,1", labels="Pozycyjny", x="90", y="30"),
        wiersz("Strzał", 20, team="BETA", comment="xG 0,2", x="20", y="30"),
    ], capsys)
    assert meta["gol_w_eksporcie"] is False and meta["wynik_strzalu"] is False
    assert "brak w eksporcie" in html and "wynik strzału nie występuje w tym eksporcie" in html
    assert _plik(html)["wynik_reczny"] is None


def test_wynik_reczny_trafia_do_raportu(write_csv, tmp_path, capsys):
    cfg = {"teams": {"us": {"name": "ALFA"}, "them": {"name": "BETA"}},
           "match": {"score": {"us": 2, "them": 1}}}
    _, html, _ = _build(write_csv, tmp_path, [wiersz("Strzał", 10, team="ALFA")], capsys, config=cfg)
    assert _plik(html)["wynik_reczny"] == {"us": 2, "them": 1}


def test_tabela_zdarzen_gol_ze_strzalu_po_znaczeniu(write_csv, tmp_path, capsys):
    _, _, ev = _build(write_csv, tmp_path, [
        wiersz("Strzał", 100, team="BETA", comment="xG 0,3"),
        wiersz("GOL", 101, team="ALFA"),
    ], capsys)
    gol = next(w for w in ev["events"] if w["tag_name"] == "GOL")
    strzal = next(w for w in ev["events"] if w["tag_name"] == "Strzał")
    assert gol["team"] == "BETA" and gol["team_side"] == "them" and strzal["is_goal"] == 1


# =========================================================================== G
def test_druzyna_spoza_meczu_w_nazwie_tagu(write_csv):
    f = _ramka(write_csv, [wiersz("Posiadanie Gamma Dolna", 10), wiersz("Posiadanie Alfa", 20),
                           wiersz("Strzał", 30, team="ALFA"), wiersz("Strzał", 40, team="BETA")])
    wynik = kontrola.anomalie(f, config={"znane_druzyny": ["Gamma Dolna", "KS Alfa"],
                                          "teams": {"us": {"name": "ALFA"}, "them": {"name": "BETA"}}})
    assert [(a["typ"], a["tag"]) for a in wynik] == [("druzyna_w_nazwie_tagu", "Posiadanie Gamma Dolna")]


def test_trzecia_druzyna_nie_laczona(write_csv, tmp_path, capsys):
    meta, html, _ = _build(write_csv, tmp_path, [
        wiersz("Strzał", 10, team="ALFA"), wiersz("Strzał", 20, team="BETA"),
        wiersz("Strzał", 30, team="GAMMA"),
    ], capsys)
    assert [(a["typ"], a["druzyna"]) for a in meta["anomalie"]] == [("trzecia_druzyna", "GAMMA")]
    assert "GAMMA" in [n for n, _ in _plik(html)["druzyny"]]


def test_xg_poza_zakresem_w_anomaliach(write_csv):
    f = _ramka(write_csv, [wiersz("Strzał", 10, comment="xG 1,7")])
    assert [a["typ"] for a in kontrola.anomalie(f)] == ["xg_poza_zakresem"]


# =========================================================================== H
def test_niezmienniki_w_meta_i_naruszenie_to_baner(write_csv, tmp_path, capsys):
    meta, html, _ = _build(write_csv, tmp_path, [
        wiersz("Strzał", 10, team="ALFA", comment="xG 0,1", player="Zawodnik Jeden"),
        wiersz("Coś", 20),
    ], capsys)
    assert meta["niezmienniki_ok"] is True
    assert {n["kod"] for n in meta["niezmienniki"]} == {
        "wiersze_warstwa1", "tagi_warstwa1", "wiersze_zdarzenia", "zawodnicy", "xg", "kierunek"}
    assert 'data-baner="niezmienniki"' not in html

    zle = kontrola.niezmienniki({"events": [{"tag": "A", "xg": 0.3}]}, {"tagi": []})
    assert not kontrola.wszystkie_ok(zle)
    slot = render.baner_niewliczone_slot({"events": []}, w7={"niezmienniki": zle})
    assert 'data-baner="niezmienniki"' in slot["__BANER_NIEWLICZONE__"]


def test_xg_z_komentarza_poza_strzalem_lamie_niezmiennik(write_csv):
    """Człowiek nadał tagowi z xG inne pojęcie — suma xG raportu ≠ suma z komentarzy."""
    f = _ramka(write_csv, [wiersz("Uderzenie", 10, comment="xG 0,3")])
    tpl = {"variables": [{"source": {"type": "tag", "raw": "Uderzenie"}, "canon": "pass"}]}
    z = znaczenie.rozstrzygnij(f, template=tpl)
    wynik = {n["kod"]: n["ok"] for n in kontrola.niezmienniki(f, plik.zbuduj(f, znaczenia=z), z)}
    assert wynik["xg"] is False


def test_nowy_profil_baner(write_csv, tmp_path, capsys):
    cfg = {"teams": {"us": {"name": "ALFA"}, "them": {"name": "BETA"}}, "profil": {"nowy": True}}
    _, html, _ = _build(write_csv, tmp_path, [wiersz("Strzał", 10, team="ALFA")], capsys, config=cfg)
    assert "Nowy układ tagów" in html and 'id="sec-plik"' in html


def test_profil_w_meta(write_csv, tmp_path, capsys):
    csv_path = write_csv([wiersz("P2 Podanie", 10)], headers=HEADERS_Z_ZAWODNIKIEM)
    p = projekt_json(tmp_path)
    main(["inspect", "--csv", csv_path, "--json", p])
    meta = json.loads(capsys.readouterr().out)
    assert meta["profil"]["uuid"] == sorted(UUID.values())
    assert "p2 podanie" in meta["profil"]["nazwy"]
    assert meta["profil"]["nazwa_uuid"]["P2 Podanie"] == UUID["P2 Podanie"]
    assert meta["sha256_zdarzen"] and meta["nierozpoznane"] == ["P2 Podanie"]


# ===================================================== skrypt raportu (Node)
import pathlib
import shutil
import subprocess

NODE = shutil.which("node")
ATRAPA = pathlib.Path(__file__).parent / "atrapa_dom_raportu.js"


def _wykonaj(html_path):
    if NODE is None:
        pytest.skip("brak node — skrypt raportu v21 niewykonany")
    wynik = subprocess.run([NODE, str(ATRAPA), str(html_path)], capture_output=True, text=True, timeout=60)
    assert wynik.returncode == 0, wynik.stderr[-2000:]
    return json.loads(wynik.stdout)


def test_skrypt_raportu_stal_bez_gola_i_wyniku(write_csv, tmp_path, capsys):
    """E/F wykonane, nie tylko obecne w kodzie: nagłówek, Przegląd, warstwa 1."""
    _build(write_csv, tmp_path, [
        wiersz("Strzał", 10, team="ALFA", comment="xG 0,1", x="90", y="30"),
        wiersz("Strzał", 20, team="BETA", comment="xG 0,2", x="20", y="30"),
        wiersz("P2 Podanie", 30, team="ALFA"),
        wiersz("Posiadanie Alfa", 40, e=100),
    ], capsys, template={"variables": [
        {"source": {"type": "tag", "raw": "P2 Podanie"}, "canon": "pass"},
        {"source": {"type": "tag", "raw": "Posiadanie Alfa"}, "canon": "possession", "side": "us"},
    ]})
    w = _wykonaj(tmp_path / "r.html")
    assert "brak w eksporcie" in w["hdr2"] and "0 : 0" not in w["hdr2"]
    assert "Strzały 1 : 1" in w["kpi"]
    assert "Podania 1 : 0" in w["kpi"] and "Posiadanie 100% : 0%" in w["kpi"]
    assert "Nie występuje w tym eksporcie: gole, wynik strzału" in w["kpi"]
    assert "Celne" not in w["kpi"] and "–" not in w["kpi"], "bez pustych kafli z kreską"
    assert "4 zdarzeń" in w["plik"]
    assert "Strzał" in w["plik"] and "P2 Podanie" in w["plik"] and "Posiadanie Alfa" in w["plik"]


def test_skrypt_raportu_gol_z_tagu(write_csv, tmp_path, capsys):
    _build(write_csv, tmp_path, [
        wiersz("STRZAŁ", 100, team="BETA", labels="CELNY", comment="xG 0,3"),
        wiersz("Gol", 101, team="ALFA"),
    ], capsys)
    w = _wykonaj(tmp_path / "r.html")
    assert "0 : 1" in w["hdr2"], "gol drużyny strzału, nie wiersza"
    assert "Gole 0 : 1" in w["kpi"] and "Celne 0 : 1" in w["kpi"]
