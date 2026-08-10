"""Render HTML — wstrzykiwanie danych w szablon.

Sedno tego modułu to nie „czy powstał plik", tylko dwie rzeczy, które w tym projekcie
już raz kosztowały czas:

1. Podmiana bez asercji liczby wystąpień (incydent v13 — skrypt trafił w komentarz
   `/* timeline */` w CSS i zniszczył szablon).
2. Podmiana, która nie trafiła w nic i nie zgłosiła błędu. Szablon bez średnika po
   placeholderze przechodzi test `'/*__DATA__*/' in html`, a przeglądarka dostaje
   `const DATA = ;`. Raport jest pusty, wdrożenie zielone.
"""

import json

import pytest

from coachanalyze import canon, render
from coachanalyze.cli import main
from coachanalyze.errors import EngineError
from coachanalyze.sources.livetag import parse

SZABLON = "<script>\nconst DATA = /*__DATA__*/;\nconst PAL = /*__PAL__*/;\n</script>"

RAMKA = {"events": [{"tag": "STRZAŁ", "b": 1.0, "e": 5.0, "team": "A", "labels": ["CELNY"],
                     "xg": 0.5, "x": 88.4, "y": 31.2, "tx": None, "ty": None, "half": 1}],
         "half_split": 2733.6}


# ------------------------------------------------------------------ asercje szablonu
def test_brak_placeholdera_przerywa_render():
    with pytest.raises(EngineError) as exc:
        render.inject("<script>const DATA = 1;</script>", RAMKA, render.EMPTY_PALETTE)
    assert "/*__DATA__*/;" in str(exc.value)


def test_placeholder_bez_srednika_przerywa_render():
    """Klasa błędu, której samo `in` nie wykrywa — podmiana nie trafiłaby w nic."""
    bez_srednika = SZABLON.replace("/*__DATA__*/;", "/*__DATA__*/")
    with pytest.raises(EngineError) as exc:
        render.inject(bez_srednika, RAMKA, render.EMPTY_PALETTE)
    assert "bez średnika" in str(exc.value)


def test_powtorzony_placeholder_przerywa_render():
    """Incydent v13: podmiana wzorca, który występuje więcej niż raz, jest błędem."""
    podwojony = SZABLON + "\n<script>const DATA = /*__DATA__*/;</script>"
    with pytest.raises(EngineError) as exc:
        render.inject(podwojony, RAMKA, render.EMPTY_PALETTE)
    assert "2 wystąpienia" in str(exc.value)


def test_asercja_zwraca_liczby_wystapien():
    assert render.assert_placeholders(SZABLON) == {"/*__DATA__*/": 1, "/*__PAL__*/": 1}


# ------------------------------------------------------------------ kształt wyjścia
def test_data_kompaktowo_pal_ze_spacjami():
    """Serializacja jest częścią wyjścia — zmiana separatorów zmienia bajty pliku."""
    html = render.inject(SZABLON, {"events": [], "half_split": 1.0}, {"tags": {"A": "#FFFFFF"}})

    assert 'const DATA = {"events":[],"half_split":1.0};' in html
    assert 'const PAL = {"tags": {"A": "#FFFFFF"}};' in html


def test_render_nie_zmienia_niczego_poza_placeholderami():
    """Najmocniejszy niezmiennik tego modułu: HTML to szablon plus dwie podmiany.

    Odwracamy wstrzyknięcie i porównujemy z szablonem bajt w bajt. Gdyby render
    dopisywał cokolwiek od siebie — nagłówek, znacznik czasu, wersję silnika —
    porównanie raportu z wzorcem v23 przestałoby cokolwiek znaczyć.
    """
    szablon = render.load_template()
    html, _ = render.render(RAMKA, palette={"tags": {}, "labels": {}})

    dane = json.dumps(render.view_data(RAMKA), ensure_ascii=False, separators=(",", ":"))
    paleta = json.dumps({"tags": {}, "labels": {}}, ensure_ascii=False)
    odwrocone = html.replace(dane + ";", "/*__DATA__*/;").replace(paleta + ";", "/*__PAL__*/;")

    assert odwrocone == szablon


def test_do_przegladarki_ida_tylko_zdarzenia_i_przerwa():
    """`prep_frame` niesie opis pliku wejściowego — raport jest publiczny (D3)."""
    frame = dict(RAMKA, headers=["tag_name"], format_fingerprint="sha256:tajne",
                 player_column="players", players=[None], negative_begin=0)

    dane = render.view_data(frame)
    assert list(dane) == ["events", "half_split"]

    html, _ = render.render(frame)
    assert "sha256:tajne" not in html
    assert "format_fingerprint" not in html


def test_bez_json_paleta_jest_pusta_a_nie_zmyslona():
    """Brak pliku projektu = brak kolorów. Szablon ma własną barwę zapasową."""
    html, raport = render.render(RAMKA, palette=None)

    assert 'const PAL = {"tags": {}, "labels": {}};' in html
    assert raport["has_palette"] is False


# ------------------------------------------------------------------ raport renderu
def test_raport_wskazuje_nierozwiazane_znaczniki():
    """Szablon v17 ma herby klubu referencyjnego wstawione na sztywno."""
    _, raport = render.render(RAMKA)
    assert raport["unresolved_placeholders"] == ["__LOGO_HUT__", "__LOGO_POG__"]


def test_crosscheck_wykrywa_rozjazd_szablonu_z_modelem():
    """Profil klubu mapujący własną nazwę tagu rozjeżdża raport z archiwum.

    Szablon v17 liczy w JS po `e.tag==='STRZAŁ'`, model kanoniczny po `concept`.
    Przy takim profilu coach widzi zero strzałów, a porównanie sezonowe komplet.
    """
    from coachanalyze import metrics

    frame = {"events": [dict(RAMKA["events"][0], tag="STRZAŁ NASZA")], "half_split": 1.0}
    profil = {"version": 9, "rules": [{"match": {"tag": "STRZAŁ NASZA"}, "concept": "shot"}]}
    pakiet = metrics.build(canon.build(frame, mapping_profile=profil))

    _, raport = render.render(frame, metrics=pakiet)

    assert raport["tag_mismatch"] == [{
        "concept": "shot", "template_tag": "STRZAŁ",
        "template_count": 0, "metrics_count": 1,
    }]


def test_zgodny_profil_nie_zglasza_rozjazdu():
    from coachanalyze import metrics

    pakiet = metrics.build(canon.build(RAMKA))
    _, raport = render.render(RAMKA, metrics=pakiet)
    assert raport["tag_mismatch"] == []


# ------------------------------------------------------------------ spięcie z CLI
def _config(tmp_path):
    path = tmp_path / "config.json"
    path.write_text(json.dumps({"match_id": 1, "teams": {"us": {"name": "A"}}}), encoding="utf-8")
    return str(path)


def test_build_konczy_sie_zerem_i_zapisuje_raport(write_csv, row, tmp_path, capsys):
    csv_path = write_csv([row("STRZAŁ", team="A", comment="X 0,81", x="88.4", y="31.2")])
    out_html = tmp_path / "raport.html"

    kod = main([
        "build", "--csv", csv_path, "--config", _config(tmp_path),
        "--out-html", str(out_html), "--out-meta", str(tmp_path / "meta.json"),
        "--out-metrics", str(tmp_path / "metrics.json"),
    ])
    wyjscie = capsys.readouterr()

    assert kod == 0
    assert out_html.exists()
    assert '"tag":"STRZAŁ"' in out_html.read_text(encoding="utf-8")
    assert json.loads(wyjscie.out)["ok"] is True, "stdout ma nieść dokładnie jeden meta.json"
    assert (tmp_path / "metrics.json").exists()


def test_zepsuty_szablon_konczy_sie_kodem_4_bez_meta_ok(write_csv, row, tmp_path, capsys, monkeypatch):
    """Błąd renderu nie może zostawić `meta.json` z `ok: true` i bez raportu."""
    monkeypatch.setattr(render, "load_template", lambda path=None: "<script>const DATA = 1;</script>")

    out_meta = tmp_path / "meta.json"
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]), "--config", _config(tmp_path),
        "--out-html", str(tmp_path / "raport.html"), "--out-meta", str(out_meta),
    ])
    wyjscie = capsys.readouterr()

    assert kod == 4
    assert json.loads(out_meta.read_text(encoding="utf-8"))["ok"] is False
    assert len([w for w in wyjscie.out.splitlines() if w.strip()]) == 1


def test_artefakty_danych_powstaja_mimo_bledu_renderu(write_csv, row, tmp_path, capsys, monkeypatch):
    """Brak szablonu nie może kasować wyniku parsowania i modelu kanonicznego."""
    monkeypatch.setattr(render, "load_template", lambda path=None: "<script>const DATA = 1;</script>")

    out_canon = tmp_path / "canon.json"
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]), "--config", _config(tmp_path),
        "--out-html", str(tmp_path / "raport.html"), "--out-meta", str(tmp_path / "meta.json"),
        "--out-canon", str(out_canon), "--out-metrics", str(tmp_path / "metrics.json"),
    ])
    capsys.readouterr()

    assert kod == 4
    assert json.loads(out_canon.read_text(encoding="utf-8"))["count"] == 1
    assert (tmp_path / "metrics.json").exists()


def test_szablon_z_repozytorium_ma_placeholdery():
    """Bramka na wypadek edycji szablonu — plik w repo musi dać się wypełnić."""
    assert render.assert_placeholders(render.load_template()) == {
        "/*__DATA__*/": 1, "/*__PAL__*/": 1,
    }


def test_paleta_z_parsera_wchodzi_do_html(tmp_path):
    """Kolory z pliku projektu trafiają do raportu bez zmiany zapisu."""
    projekt = tmp_path / "projekt.json"
    projekt.write_text(json.dumps({"dependencies": [
        {"type": "tag", "data": {"name": "STRZAŁ", "color": "0.3 0.2 0.5"}},
    ]}), encoding="utf-8")

    html, _ = render.render(RAMKA, palette=parse.prep_palette(str(projekt)))
    assert '"STRZAŁ": "#4C3380"' in html
