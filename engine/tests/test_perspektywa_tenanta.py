"""Perspektywa klubu-tenanta w v21 (0.16.3).

Tagi bez drużyny — odbiór, strata, 1x1, pressing — taguje analityk tenanta.
Lewy slot raportu (HOME/HUT) to tenant, więc te liczby należą do LEWEJ kolumny.
Do 0.16.2 szablon miał `USK=POG` i pokazywał odbiory tenanta przy nazwie rywala:
na raporcie 27 „odbiory 8 : 23" zamiast 23 : 8, fakty „perspektywa JDRZ".

Test idzie przez prawdziwy render (CLI, szablon v21) i liczy narzędziem odbioru,
które NIE dzieli kodu z szablonem — plus statyczne sprawdzenie, że szablon
podaje liczby tenanta jako pierwszy argument KPI.
"""

import importlib.util
import json
import pathlib

from coachanalyze import render
from coachanalyze.cli import main

NARZEDZIE = pathlib.Path(__file__).resolve().parent.parent / "tools" / "przeglad_liczby.py"


def _narzedzie():
    spec = importlib.util.spec_from_file_location("przeglad_liczby", NARZEDZIE)
    modul = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(modul)
    return modul


def _raport(write_csv, row, tmp_path):
    """Tenant A ma 3 odbiory, rywal B — żadnego (brak strat A)."""
    csv_path = write_csv([
        row("STRZAŁ", begin=100, team="A", labels="CELNY", comment="X 0,30", x="88", y="31"),
        row("STRZAŁ", begin=900, team="B", labels="NIECELNY", comment="X 0,10", x="90", y="30"),
        row("ODBIÓR", begin=200, labels="ICH POŁOWA"),
        row("ODBIÓR", begin=300, labels="NASZA POŁOWA"),
        row("ODBIÓR", begin=400, labels="ICH POŁOWA"),
        row("1x1 DEF.", begin=500, labels="WYGRANY"),
        row("1x1 OFF", begin=600, labels="PRZEGRANY"),
    ])
    config = tmp_path / "config.json"
    config.write_text(json.dumps({
        "match_id": 1,
        "teams": {"us": {"name": "A", "club_id": 7}, "them": {"name": "B", "club_id": 8}},
        "match": {"tenant_club_id": 7},
    }), encoding="utf-8")
    out_html = tmp_path / "raport.html"
    kod = main([
        "build", "--csv", csv_path, "--config", str(config), "--html-template", "v21",
        "--out-html", str(out_html), "--out-meta", str(tmp_path / "meta.json"),
        "--out-metrics", str(tmp_path / "metrics.json"),
    ])
    assert kod == 0
    return out_html.read_text(encoding="utf-8")


def test_odbiory_tenanta_w_lewej_kolumnie(write_csv, row, tmp_path, capsys):
    tenant, rywal, wiersze = _narzedzie().liczby(_raport(write_csv, row, tmp_path))
    capsys.readouterr()
    w = {etykieta: (lewa, prawa) for etykieta, lewa, prawa in wiersze}

    assert (tenant, rywal) == ("A", "B"), "lewy slot (HUT) ma być tenantem"
    assert w["odbiory"] == (3, 0), "odbiory tenanta w LEWEJ kolumnie"
    assert w["straty"] == (0, 3), "nasz odbiór to strata rywala"
    assert w["1x1 wygrane"] == (1, 1), "przegrany pojedynek tenanta to wygrany rywala"


def test_szablon_przypina_perspektywe_do_lewego_slotu():
    szablon = render.load_template(render.template_path_for("v21"))

    assert "const TENANT=HUT, RIVAL=POG;" in szablon
    assert "USK" not in szablon.replace("`USK=POG`", ""), "stara stała wróciła"
    # KPI: lewa kolumna = tenant (u.*), prawa = to, co z tagów tenanta wynika dla rywala.
    assert "k('Odbiory',u.odb,them.odb," in szablon
    assert "k('1x1 wygrane',u.won,them.won," in szablon
    # Fakty: „perspektywa …" i „xG → gole …" z nazwą LEWEGO slotu.
    assert "me=A[TENANT],ry=A[RIVAL],short='__TEAM_HOME_SHORT__'" in szablon
    assert "perspektywa __TEAM_HOME_LABEL__ ·" in szablon
    assert "MC.teams.has(TENANT)" in szablon
