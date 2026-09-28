"""Liczby raportu v21 policzone W NODE — blok `/*<liczby-v21>*/` szablonu (golden layout W1).

Szablon v21 liczy Przegląd w przeglądarce. Do W1 jedynym sprawdzeniem było
narzędzie odbioru, które liczy NIEZALEŻNIE (celowo) — więc błąd w samym
szablonie, jak gole bez strzału przypisane tenantowi w raporcie 28, był
widoczny dopiero na ekranie klienta. Teraz funkcje liczące są czyste i wydzielone,
a test uruchamia DOKŁADNIE ten kod, który pójdzie do przeglądarki.

Brak `node` = test POMINIĘTY z powodem (widoczny w `pytest -rs`), nie zaliczony.
"""

import json
import re
import shutil
import subprocess

import pytest

from coachanalyze import render

NODE = shutil.which("node")
pytestmark = pytest.mark.skipif(NODE is None, reason="brak node — liczby v21 niesprawdzone")


def _blok():
    szablon = render.load_template(render.template_path_for("v21"))
    m = re.search(r"/\*<liczby-v21>\*/(.*?)/\*</liczby-v21>\*/", szablon, re.S)
    assert m, "blok liczb v21 zniknął z szablonu"
    return m.group(1)


def _uruchom(kod):
    wynik = subprocess.run(
        [NODE, "-e", _blok() + "\n" + kod],
        capture_output=True, text=True, timeout=30,
    )
    assert wynik.returncode == 0, wynik.stderr
    return json.loads(wynik.stdout)


def _ev(tag, b, team=None, labels=(), xg=None):
    return {"tag": tag, "b": b, "team": team, "labels": list(labels), "xg": xg}


def test_bez_strzalow_gole_nie_leca_na_tenanta():
    """Raport 28: STRZAŁ przemianowany aliasem, trzy gole z drużyną tenanta z eksportu."""
    ev = [_ev("Gol", 100, "POG"), _ev("Gol", 900, "POG"), _ev("Gol", 1500, "POG"),
          _ev("ODBIÓR", 200, None, ["ICH POŁOWA"])]
    out = _uruchom(
        "const ev=atrybujGole(%s);"
        "const d=dostepnosc(ev);const o=liczPrzeglad(ev,'POG','JDRZ');"
        "console.log(JSON.stringify({d,gT:o.POG.gole,gR:o.JDRZ.gole,druzyny:ev.filter(e=>e.tag==='Gol').map(e=>e.team)}));"
        % json.dumps(ev)
    )
    assert out["d"]["strzaly"] is False, "brak strzałów w meczu ma dawać „–”, nie 0"
    assert out["druzyny"] == [None, None, None], "gol bez strzału nie dostaje drużyny"
    assert (out["gT"], out["gR"]) == (0, 0), "nie 3:0 na tenanta"
    assert out["d"]["odb"] is True


def test_gol_przypisany_do_najblizszego_strzalu():
    ev = [_ev("STRZAŁ", 100, "POG", ["CELNY"], 0.3), _ev("STRZAŁ", 900, "JDRZ", ["CELNY"], 0.4),
          _ev("Gol", 905, "POG")]
    out = _uruchom(
        "const ev=atrybujGole(%s);const o=liczPrzeglad(ev,'POG','JDRZ');"
        "console.log(JSON.stringify({gT:o.POG.gole,gR:o.JDRZ.gole,xgR:o.JDRZ.xg,d:dostepnosc(ev)}));"
        % json.dumps(ev)
    )
    assert (out["gT"], out["gR"]) == (0, 1)
    assert out["xgR"] == pytest.approx(0.4)
    assert out["d"]["strzaly"] is True and out["d"]["sbz"] is False


def test_pressing_suma_kafelkow_rowna_liczbie_akcji():
    """JDRZ: NISKUTECZNY 2, pod spodem tylko POSIADANIE 1 — druga akcja znikała."""
    ev = [_ev("NISKUTECZNY", 10, None, ["POSIADANIE"]), _ev("NISKUTECZNY", 20, None, [])]
    out = _uruchom("console.log(JSON.stringify(kubelkiPressingu(%s)));" % json.dumps(ev))
    kafelki = dict(out)
    assert sum(kafelki.values()) == 2
    assert kafelki["INNE"] == 1 and kafelki["POSIADANIE"] == 1


def test_pressing_akcja_w_jednym_kubelku():
    """Dwie etykiety wyniku nie liczą akcji dwa razy — wygrywa dalej doprowadzone wyjście."""
    ev = [_ev("SKUTECZNY", 10, None, ["ZDOBYCIE SBZ", "STRZAŁ Z SBZ"]),
          _ev("SKUTECZNY", 20, None, ["INNE"]), _ev("SKUTECZNY", 30, None, ["STRATA"])]
    kafelki = dict(_uruchom("console.log(JSON.stringify(kubelkiPressingu(%s)));" % json.dumps(ev)))
    assert sum(kafelki.values()) == 3
    assert kafelki["STRZAŁ Z SBZ"] == 1 and kafelki["ZDOBYCIE SBZ"] == 0
    assert kafelki["INNE"] == 1 and kafelki["STRATA"] == 1


def test_szablon_pokazuje_kreske_przy_braku_tagu():
    """Wyświetlanie: nagłówek bierze „–” z `DOST`, a fakt xG → gole znika.

    W7 E: Przegląd nie pokazuje już kafli z „–" — pojęcie nieobecne idzie do
    jednej linii „nie występuje w tym eksporcie" (`dodaj`). Wynik meczu bez
    tagu gola to „brak w eksporcie", nie kreska ani 0:0 (`wynikMeczu`).
    """
    szablon = render.load_template(render.template_path_for("v21"))
    assert "dodaj(DOST.gole, k('Gole',h.gole,p.gole), 'gole');" in szablon
    assert "Nie występuje w tym eksporcie:" in szablon
    assert "if(DOST.gole) return" in szablon and "brak w eksporcie" in szablon
    assert "${DOST.strzaly?h.strzaly:KRESKA} strzałów" in szablon
    assert "if(DOST.strzaly)push(" in szablon
    assert "const KRESKA = '–';" in szablon
