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
import re

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


@pytest.mark.parametrize("generacja", sorted(render.TEMPLATE_FILES))
def test_render_nie_zmienia_niczego_poza_placeholderami(generacja):
    """Najmocniejszy niezmiennik tego modułu: HTML to szablon plus same podmiany.

    Odwracamy wstrzyknięcie i porównujemy z szablonem bajt w bajt. Gdyby render
    dopisywał cokolwiek od siebie — nagłówek, znacznik czasu, wersję silnika —
    porównanie raportu z raportem produkcyjnym przestałoby cokolwiek znaczyć.

    Obie generacje, bo to jedyny test, który dla v21 sprawdza wyjście CAŁEGO pliku.
    Wzorca złotego v21 jeszcze nie ma (wymaga zgody klienta), więc dopóki go nie ma,
    ten niezmiennik jest tu najmocniejszym, co da się powiedzieć o nowym szablonie.
    """
    # Wartości dobrane tak, żeby każda wstawiona była w dokumencie unikalna
    # i dała się jednoznacznie cofnąć. Przy zwykłych nazwach („A") odwrócenie
    # sprawdzałoby siebie samo, a nie render.
    #
    # BARWY MUSZĄ BYĆ JASNE I WYRAŹNIE RÓŻNE. Barwa już ciemna wraca z `hex_to_light`
    # bez zmiany, więc `__TEAM_*_COLOR__` i `__TEAM_*_COLOR_L__` dostałyby tę samą
    # wartość; dwie barwy bliskie sobie schodzą po przyciemnieniu do wspólnego zapisu.
    # W obu przypadkach odwrócenie przestaje być jednoznaczne — nie z winy renderu.
    config = {
        "teams": {
            "us": {"name": "ZzAlfa", "short": "ZzAlfaSkrot", "color": "#FFEE11"},
            "them": {"name": "ZzBeta", "short": "ZzBetaSkrot", "color": "#11CCFF"},
        },
        "match": {"season": "ZzSezon", "round": "ZzKolejka", "date": "ZzData"},
    }
    # KIERUNEK PODANY JAWNIE, a nie wykryty z `RAMKA`: znaczniki grupy `kierunek`
    # przy nieznanym kierunku są PUSTYMI napisami, a pustego napisu nie da się
    # odwrócić z powrotem w znacznik — test przestałby cokolwiek sprawdzać.
    kierunek = {"us": "right", "them": "left", "confidence": "high"}
    # TEMPLAT Z NIEPUSTYM NADPISANIEM SŁOWNIKA. Pusty dałby literał `{}`, a tego
    # napisu w szablonie jest mnóstwo — odwracanie podmieniłoby pierwszy lepszy
    # `{}` na znacznik i test sprawdzałby własną pomyłkę, nie render.
    templat = {"variables": [{"source": {"type": "tag", "raw": "ZzTag"},
                              "display_label": "ZzEtykieta", "aliases": ["ZzAlias"]}]}
    sciezka = render.template_path_for(generacja)
    szablon = render.load_template(sciezka)
    html, _ = render.render(
        RAMKA, palette={"tags": {}, "labels": {}}, config=config, template_path=sciezka,
        direction=kierunek, report_template=templat,
    )

    # v21 niesie kafelek zawodnikow, wiec `DATA` dostaje pole `player` — a v17 nie.
    # Test odwraca render, wiec musi serializowac dokladnie to, co render wstrzyknal.
    z_zawodnikami = render.WIDGET_ZAWODNICY in szablon
    dane = json.dumps(render.view_data(RAMKA, players=z_zawodnikami),
                      ensure_ascii=False, separators=(",", ":"))
    paleta = json.dumps({"tags": {}, "labels": {}}, ensure_ascii=False)
    odwrocone = html.replace(dane + ";", "/*__DATA__*/;").replace(paleta + ";", "/*__PAL__*/;")

    slots, _ = render.team_slots(RAMKA, config["teams"])
    slots.update(render.match_slots(config))
    slots.update(render.direction_slots(
        kierunek,
        labels={slot: slots["__TEAM_{}_LABEL__".format(slot)] for _s, slot in render.TEAM_SLOTS},
    ))
    slots.update(render.progi_slot())
    slots.update(render.vars_slot(templat))
    # Malejąco po długości wstawionej wartości — krótsza nie może zjeść fragmentu dłuższej.
    for placeholder in sorted(slots, key=lambda p: len(slots[p]), reverse=True):
        odwrocone = odwrocone.replace(slots[placeholder], placeholder)

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
def test_poprawny_render_nie_zostawia_znacznikow():
    """Szablon nie zna klubu — po renderze nie ma prawa zostać ani jeden `__COŚ__`."""
    _, raport = render.render(RAMKA)
    assert raport["unresolved_placeholders"] == []


def test_znacznik_usuniety_z_szablonu_przerywa_render(monkeypatch):
    """Edycja szablonu, która zjada znacznik drużyny, ma być błędem, nie cichą stratą."""
    okrojony = render.load_template().replace("__TEAM_AWAY_LABEL__", "Rywal")
    monkeypatch.setattr(render, "load_template", lambda path=None: okrojony)

    with pytest.raises(EngineError) as exc:
        render.render(RAMKA)
    assert "__TEAM_AWAY_LABEL__" in str(exc.value)


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


@pytest.mark.parametrize("generacja", sorted(render.TEMPLATE_FILES))
def test_szablon_z_repozytorium_ma_placeholdery(generacja):
    """Bramka na wypadek edycji szablonu — plik w repo musi dać się wypełnić."""
    szablon = render.load_template(render.template_path_for(generacja))
    assert render.assert_placeholders(szablon) == {
        "/*__DATA__*/": 1, "/*__PAL__*/": 1,
    }


# ------------------------------------------------------------------ wybór generacji
def test_domyslny_szablon_to_v17(monkeypatch):
    """Domyślny szablon ZOSTAJE v17 — to jego wyjścia pilnuje test złoty.

    Przestawienie domyślnej generacji znaczyłoby, że bramka wdrożenia sprawdza inny
    plik, niż produkuje produkcja. v21 włącza się świadomie, a nie przez pominięcie
    parametru.
    """
    monkeypatch.delenv(render.TEMPLATE_ENV, raising=False)
    assert render.DEFAULT_GENERATION == "v17"
    assert render.default_template_path().endswith("dashboard_template.html")
    assert render.resolve_template(None) == render.default_template_path()


def test_zmienna_srodowiskowa_wybiera_generacje(monkeypatch):
    monkeypatch.setenv(render.TEMPLATE_ENV, "v21")
    assert render.default_template_path().endswith("dashboard_template_v21.html")


def test_przelacznik_przyjmuje_nazwe_i_sciezke():
    """Dwa pytania, jeden parametr: „którą generację" i „ten konkretny plik"."""
    assert render.resolve_template("v21").endswith("dashboard_template_v21.html")
    assert render.resolve_template("/tmp/wariant.html") == "/tmp/wariant.html"


def test_jawna_wartosc_wygrywa_ze_srodowiskiem(monkeypatch):
    """Przelot z `--html-template` nie może zależeć od cudzego środowiska."""
    monkeypatch.setenv(render.TEMPLATE_ENV, "v21")
    assert render.resolve_template("v17").endswith("dashboard_template.html")


def test_nieznana_generacja_przerywa_render(monkeypatch):
    """Literówka w nazwie generacji nie może schodzić po cichu na domyślną.

    Raport wyszedłby wtedy w innym układzie, niż zamawiał ten, kto parametr podawał,
    a jedynym śladem byłby jego własny błąd. Rozstrzygamy po nazwie ze słownika,
    więc „v71" jest błędem, a nie ścieżką, której nie ma.
    """
    with pytest.raises(EngineError) as exc:
        render.resolve_template("v71")
    assert "v71" in str(exc.value)

    monkeypatch.setenv(render.TEMPLATE_ENV, "v71")
    with pytest.raises(EngineError):
        render.default_template_path()


def test_raport_renderu_nazywa_generacje():
    _, raport = render.render(RAMKA, template_path="v17")
    assert raport["template_generation"] == "v17"

    _, raport = render.render(RAMKA, template_path="v21")
    assert raport["template_generation"] == "v21"


def test_cli_przyjmuje_html_template(write_csv, row, tmp_path, capsys):
    """Przelot przez kontrakt CLI — `--html-template v21` ma dać szablon v21."""
    out_html = tmp_path / "raport.html"
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A", comment="X 0,81")]),
        "--config", _config(tmp_path), "--html-template", "v21",
        "--out-html", str(out_html), "--out-meta", str(tmp_path / "meta.json"),
    ])
    wyjscie = capsys.readouterr()

    assert kod == 0
    assert 'id="hdr2"' in out_html.read_text(encoding="utf-8")
    assert "szablon HTML: v21" in wyjscie.err, "generacja ma trafiać do logu"


def test_cli_zglasza_brakujacy_znacznik_w_meta(write_csv, row, tmp_path, capsys, monkeypatch):
    """Szablon z ubytkiem daje RAPORT I OSTRZEŻENIE, nie kod błędu.

    Ostrzeżenie idzie do `meta.warnings`, czyli tam, gdzie operator ogląda resztę
    ubytków tego raportu — a nie do logu, który czyta wykonawca.
    """
    okrojony = render.load_template(render.template_path_for("v21")) \
        .replace("__TEAM_AWAY_DIM_L__", "#333333")
    monkeypatch.setattr(render, "load_template", lambda path=None: okrojony)

    out_html = tmp_path / "raport.html"
    out_meta = tmp_path / "meta.json"
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]),
        "--config", _config(tmp_path),
        "--out-html", str(out_html), "--out-meta", str(out_meta),
    ])
    wyjscie = capsys.readouterr()

    assert kod == 0, "raport ma powstać mimo ubytku w szablonie"
    assert out_html.exists()

    meta = json.loads(out_meta.read_text(encoding="utf-8"))
    braki = [w for w in meta["warnings"] if w["code"] == "BRAKUJACY_ZNACZNIK"]
    assert len(braki) == 1
    assert braki[0]["placeholders"] == ["__TEAM_AWAY_DIM_L__"]
    assert braki[0]["count"] == 1
    assert "__TEAM_AWAY_DIM_L__" in wyjscie.err


def test_cli_nie_zglasza_brakow_przy_zdrowym_szablonie(write_csv, row, tmp_path, capsys):
    """v17 nie ma znaczników v21 i NIE JEST to ubytek — ostrzeżenia ma nie być.

    Ostrzeżenie o stanie normalnym uczy ignorować ostrzeżenia.
    """
    out_meta = tmp_path / "meta.json"
    main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]),
        "--config", _config(tmp_path), "--html-template", "v17",
        "--out-html", str(tmp_path / "raport.html"), "--out-meta", str(out_meta),
    ])
    capsys.readouterr()

    kody = [w["code"] for w in json.loads(out_meta.read_text(encoding="utf-8"))["warnings"]]
    assert "BRAKUJACY_ZNACZNIK" not in kody


def test_cli_odrzuca_nieznana_generacje(write_csv, row, tmp_path, capsys):
    """Literówka w parametrze kończy się kodem 4, a nie cichym raportem v17."""
    kod = main([
        "build", "--csv", write_csv([row("STRZAŁ", team="A")]),
        "--config", _config(tmp_path), "--html-template", "v71",
        "--out-html", str(tmp_path / "raport.html"),
        "--out-meta", str(tmp_path / "meta.json"),
    ])
    capsys.readouterr()
    assert kod == 4


# ------------------------------------------------------------------ grupy znaczników
def test_v17_nie_ma_znacznikow_v21_i_to_jest_poprawne():
    """Znaczniki motywu jasnego i meta meczu są OPCJONALNE CAŁĄ GRUPĄ.

    v17 nie ma ani jednego z nich — i ma się renderować bez słowa skargi.
    """
    szablon = render.load_template(render.template_path_for("v17"))
    slots, _ = render.team_slots(RAMKA, None)
    slots.update(render.match_slots({"match": {"season": "2026/27"}}))

    liczby = render.assert_placeholders(szablon, slots)

    for grupa in render.SLOT_GROUPS.values():
        for placeholder in grupa:
            assert placeholder not in liczby, (
                "{} nie istnieje w v17 i nie ma czego podmieniać".format(placeholder)
            )


def test_niekompletna_grupa_nie_przerywa_renderu():
    """Raport ma powstać ZAWSZE — także z szablonu, któremu zjadło znacznik.

    Zamiast wyjątku idzie lista braków, którą CLI zamienia na ostrzeżenie
    `BRAKUJACY_ZNACZNIK` w `meta.warnings`.
    """
    szablon = render.load_template(render.template_path_for("v21"))
    okrojony = szablon.replace("__TEAM_AWAY_DIM_L__", "#333333")
    slots, _ = render.team_slots(RAMKA, None)
    slots.update(render.match_slots(None))

    # Sama asercja szablonu przechodzi — brak znacznika z grupy nie jest błędem.
    render.assert_placeholders(okrojony, slots)
    assert render.missing_slots(okrojony, slots) == ["__TEAM_AWAY_DIM_L__"]


def test_brak_calej_grupy_nie_jest_brakiem():
    """v17 nie ma ANI JEDNEGO z tych znaczników i to jest poprawne, nie ubytek.

    Ostrzeżenie o stanie normalnym uczy ignorować ostrzeżenia — dlatego grupa
    nieobecna w całości milczy, a tylko obecna w połowie się odzywa.
    """
    szablon = render.load_template(render.template_path_for("v17"))
    slots, _ = render.team_slots(RAMKA, None)
    slots.update(render.match_slots(None))

    assert render.missing_slots(szablon, slots) == []


def test_data_meczu_ma_wlasna_nazwe_znacznika():
    """`__DATA_MECZU__`, nie `__DATA__` — to drugie jest podnapisem `/*__DATA__*/`.

    Przy wspólnej nazwie tag zdarzenia o treści `__DATA__` mógłby zostać podmieniony
    na datę meczu, a liczenie wystąpień wymagało korekty. Po przemianowaniu w szablonie
    obie rzeczy są po prostu różnymi napisami.
    """
    assert "__DATA__" not in dict(render.MATCH_SLOT_SOURCES)
    assert "__DATA_MECZU__" in render.SLOT_GROUPS["meta_meczu"]

    szablon = render.load_template(render.template_path_for("v21"))
    assert szablon.count("__DATA__") == szablon.count(render.DATA_PLACEHOLDER) == 1
    assert szablon.count("__DATA_MECZU__") >= 1


def test_zdarzenie_z_nazwa_znacznika_nie_zostaje_podmienione():
    """Podmiana znaczników nie ma prawa wejść w dane meczu.

    Tag o treści `__DATA__` musi przetrwać w `DATA` nietknięty — inaczej raport
    pokazałby inne liczby niż archiwum.
    """
    ramka = {"events": [dict(RAMKA["events"][0], tag="__DATA__")], "half_split": 1.0}
    config = {"match": {"season": "2026/27", "round": "3", "date": "2026-08-01"}}

    html, _ = render.render(ramka, config=config, template_path="v21")

    assert '"tag":"__DATA__"' in html, "podmiana znacznika weszła w dane meczu"
    assert "'2026-08-01'" in html, "data meczu nie trafiła do nagłówka"


# ------------------------------------------------------------------ meta meczu
def test_meta_meczu_z_konfiguracji_wchodzi_do_naglowka():
    config = {"match": {"season": "2026/27", "round": "3", "date": "2026-08-01"}}
    html, raport = render.render(RAMKA, config=config, template_path="v21")

    assert "metaCzesc('sezon','2026/27')" in html
    assert "metaCzesc('kolejka','3')" in html
    assert "metaCzesc('','2026-08-01')" in html
    assert raport["unresolved_placeholders"] == []


def test_brak_meta_meczu_daje_pusty_czlon_a_nie_wymyslona_date():
    """CLAUDE.md §8 — brak danych ma być widoczny, nie zamaskowany.

    Szablon składa nagłówek z NIEPUSTYCH części (`metaLinia`), więc brak kolejki
    zabiera cały człon zamiast zostawiać „kolejka  · " z samym separatorem.
    """
    html, raport = render.render(RAMKA, template_path="v21")

    assert render.match_slots(None) == {
        "__SEZON__": "", "__KOLEJKA__": "", "__DATA_MECZU__": "",
    }
    assert "metaCzesc('kolejka','')" in html
    assert raport["unresolved_placeholders"] == []


def test_naglowek_pomija_puste_czlony():
    """Składanie nagłówka mieszka w szablonie — sprawdzamy jego regułę wprost.

    Reguła jest w JS, więc odtwarzamy ją tutaj na tych samych wejściach: pusta
    kolejka ma zniknąć razem ze swoim separatorem, a nie zostawić po sobie kropkę.
    """
    def metaCzesc(etykieta, wartosc):
        w = (wartosc or "").strip()
        return (etykieta + " " + w if etykieta else w) if w else ""

    def metaLinia(*czesci):
        return " · ".join(c for c in czesci if c)

    assert metaLinia("Raport pomeczowy", metaCzesc("sezon", "2026/27"),
                     metaCzesc("kolejka", ""), metaCzesc("", "2026-08-01")) \
        == "Raport pomeczowy · sezon 2026/27 · 2026-08-01"
    assert metaLinia("Raport pomeczowy", metaCzesc("sezon", ""),
                     metaCzesc("kolejka", ""), metaCzesc("", "")) == "Raport pomeczowy"


def test_sezon_bierze_sie_takze_z_season_label():
    """`season_label` jest w kontrakcie od dawna — szkoda wymagać drugiego wpisu."""
    assert render.match_slots({"season_label": "2026/2027"})["__SEZON__"] == "2026/2027"
    # Blok `match` jest konkretniejszy i wygrywa.
    assert render.match_slots(
        {"season_label": "2026/2027", "match": {"season": "R2"}}
    )["__SEZON__"] == "R2"


def test_meta_meczu_nie_da_sie_wstrzyknac_do_raportu():
    """Raport wisi pod publicznym adresem, a meta meczu wpisuje operator.

    Wartość ląduje i w treści HTML, i w literale szablonowym JS — musi być
    bezpieczna w obu kontekstach naraz.
    """
    zlosliwe = render.match_slots({"match": {
        "season": '</script><img src=x onerror=alert(1)>',
        "round": "`+alert(2)+`",
        "date": "${alert(3)}",
    }})

    assert "<" not in zlosliwe["__SEZON__"] and ">" not in zlosliwe["__SEZON__"]
    assert "`" not in zlosliwe["__KOLEJKA__"]
    assert "${" not in zlosliwe["__DATA_MECZU__"]


# ------------------------------------------------------------------ barwy motywu jasnego
def test_jasna_barwa_jest_przyciemniana_pod_jasne_tlo():
    """Żółć klubu na białym panelu znika — wraca ciemniejsza żółć, nie inny odcień."""
    jasna = render.hex_to_light("#FFEE11")
    r, g, b = render._kanaly(jasna)
    assert (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255 <= render.LIGHT_TARGET_LUM + 0.01
    assert r > g > b, "kolejność kanałów ma zostać — inaczej to już inna barwa"


def test_ciemna_barwa_zostaje_bez_zmiany():
    """Rozjaśnianie „dla symetrii" byłoby wymyślaniem barwy, której klub nie ma."""
    assert render.hex_to_light("#1A2B3C") == "#1A2B3C"


def test_barwa_jasna_z_konfiguracji_wygrywa_z_regula():
    """TODO(sesja-4): `color_light` ma docelowo przyjść z pola klubu w bazie.

    Dziś nie ma takiej kolumny i migracji pod nią NIE dokładamy — reguła
    z `hex_to_light` wystarcza, a jawna wartość w konfiguracji jest furtką
    dla klubu, któremu reguła nie pasuje.
    """
    slots, _ = render.team_slots(RAMKA, {
        "us": {"name": "A", "color": "#FFEE11", "color_light": "#A8780A"},
    })
    assert slots["__TEAM_HOME_COLOR_L__"] == "#A8780A"
    assert slots["__TEAM_HOME_DIM_L__"] == "rgba(168,120,10,.16)"


# ------------------------------------------------------------------ szablon v21
def test_v21_renderuje_sie_bez_reszty_znacznikow():
    """Kryterium odbioru nowego szablonu, dopóki nie ma dla niego wzorca złotego.

    `LEFTOVER_RE` łapie każdy `__COŚ__`, który przeżył render — czyli znacznik,
    o którym render nie wie. Przy dwóch generacjach to najtańsza bramka na to,
    żeby nowy szablon nie wyjechał z dziurą w nagłówku.
    """
    config = {
        "teams": {
            "us": {"name": "Klub A", "short": "KLA", "color": "#E8C558"},
            "them": {"name": "Klub B", "short": "KLB", "color": "#5B8DEF"},
        },
        "match": {"season": "2026/2027", "round": "3", "date": "2026-08-01"},
    }
    html, raport = render.render(RAMKA, config=config, template_path="v21")

    assert raport["unresolved_placeholders"] == []
    assert render.unresolved_placeholders(html) == []
    assert raport["template_generation"] == "v21"


def test_v21_niesie_swoje_cechy():
    """Bramka na podmianę pliku v21 czymś innym — jak `CECHY_V23` dla v17.

    Nagłówek transmisyjny (`id="hdr2"`) i eksport slajdów to rzeczy, których
    v17 nie ma. Ich zniknięcie znaczy, że pod nazwą v21 leży inna generacja.
    """
    szablon = render.load_template(render.template_path_for("v21"))
    for cecha in ('id="hdr2"', "SLAJDY PNG", 'id="sec-przeglad"'):
        assert cecha in szablon, "szablon v21 zgubił cechę: {!r}".format(cecha)


@pytest.mark.parametrize("generacja", sorted(render.TEMPLATE_FILES))
def test_zaden_szablon_nie_zna_klubu(generacja):
    """Szablon jest wspólny dla wszystkich klientów — nazwa klubu w nim to wyciek.

    Test złoty sprawdza to dla v17; tutaj obejmujemy KAŻDĄ generację. Nowy szablon
    powstaje z raportu konkretnego meczu (v21 z Naprzodu Jędrzejów), więc ślady
    klubu zostają w nim domyślnie i usuwa się je ręcznie.
    """
    szablon = render.load_template(render.template_path_for(generacja))
    for slad in ("HUTNIK", "POGOŃ", "Hutnik", "Pogoń", "base64,",
                 "JĘDRZEJÓW", "Jędrzejów", "JDRZ", "Lubaczów"):
        assert slad not in szablon, (
            "Szablon {} niesie ślad konkretnego klubu: {!r}".format(generacja, slad)
        )


def test_indeks_wspolczynnikow_doklejany_tylko_z_konfiguracja():
    """Odsyłacze do indeksu (M1) są OPT-IN przez config.options.index_base.

    Bez konfiguracji wyjście musi być bajt w bajt takie jak dotąd — na tym
    stoi złoty test odtworzenia raportu produkcyjnego. Etykiety przechodzą
    przez ucieczkę HTML: pochodzą z bazy, czyli od użytkownika.
    """
    cfg = {"options": {"index_base": "/r/KLUCZ/i/", "index_links": [
        {"slug": "xg", "label": "xG <gole & oczekiwane>", "estimated": True},
        {"slug": "celnosc", "label": "Celność strzałów", "estimated": False},
        {"slug": "zły/slug", "label": "odpada", "estimated": False},
    ]}}

    html, _ = render.render(RAMKA, config=cfg)
    assert 'id="ca-indeks"' in html
    assert '/r/KLUCZ/i/xg' in html and '/r/KLUCZ/i/celnosc' in html
    assert 'xG &lt;gole &amp; oczekiwane&gt;' in html, "etykieta bez ucieczki HTML"
    assert 'wskaźnik szacowany' in html
    assert 'zły/slug' not in html, "slug spoza [a-z0-9-] nie może wejść do adresu"

    bez, _ = render.render(RAMKA)
    assert 'ca-indeks' not in bez

    zwykly_config, _ = render.render(RAMKA, config={"options": {"contrast_fix": True}})
    assert zwykly_config == bez, "config bez index_base nie może zmienić ani bajta"


def test_paleta_z_parsera_wchodzi_do_html(tmp_path):
    """Kolory z pliku projektu trafiają do raportu bez zmiany zapisu."""
    projekt = tmp_path / "projekt.json"
    projekt.write_text(json.dumps({"dependencies": [
        {"type": "tag", "data": {"name": "STRZAŁ", "color": "0.3 0.2 0.5"}},
    ]}), encoding="utf-8")

    html, _ = render.render(RAMKA, palette=parse.prep_palette(str(projekt)))
    assert '"STRZAŁ": "#4C3380"' in html


# ------------------------------------------------------------------ dług 7.5
def test_nazwa_klubu_ze_znakami_specjalnymi_nie_rozbija_raportu():
    """Dług 7.5 z docs/STAN_PIVOTU.md: nazwa klubu pochodzi z bazy, czyli od
    użytkownika, a raport wisi pod publicznym adresem (CLAUDE.md §5).

    ═══════════════════════════════════════════════════════════════════════════
    DWA KONTEKSTY, DWIE UCIECZKI — i to jest sedno tego testu.

    `__TEAM_*_LABEL__` idzie do treści HTML → ucieczka HTML.
    `__TEAM_*__` idzie do literału JS PORÓWNYWANEGO ZE ZDARZENIAMI → ucieczka
    HTML jest tam ZABRONIONA: `&amp;` po jednej stronie i `&` po drugiej znaczą
    raport z zerem zdarzeń dla klubu z ampersandem w nazwie, bez ostrzeżenia.
    ═══════════════════════════════════════════════════════════════════════════
    """
    zlosliwa = "<Test & Spółka's>"
    config = {"teams": {
        "us": {"name": zlosliwa, "source_names": [zlosliwa]},
        "them": {"name": "Rywal"},
    }}
    frame = {"events": [dict(RAMKA["events"][0], team=zlosliwa)], "half_split": 1.0}
    canon_result = canon.build(frame, teams=config["teams"])

    html, raport = render.render(frame, canon_result=canon_result, config=config)

    # 1. Nic nie wyszło z kontekstu HTML.
    assert "<Test" not in html, "nazwa klubu wstrzyknięta jako znacznik HTML"
    assert "&lt;Test &amp; Spółka" in html, "etykieta bez ucieczki HTML"

    # 2. Literał JS nie został rozbity apostrofem.
    dopasowanie = re.search(r"const HUT\s*=\s*'([^']*)'", html)
    assert dopasowanie, "literał nazwy drużyny rozbity — apostrof wyszedł z napisu"
    klucz = dopasowanie.group(1)

    # 3. NAJWAŻNIEJSZE: klucz w literale i `team` w danych to TEN SAM napis.
    #    Rozjazd tutaj daje raport z zerem zdarzeń i bez żadnego ostrzeżenia.
    dane = json.loads(re.search(r"const DATA = (.*?);\n", html, re.S).group(1))
    assert dane["events"][0]["team"] == klucz, (
        "klucz dopasowania rozjechał się z nazwą w danych: "
        "{!r} != {!r}".format(klucz, dane["events"][0]["team"])
    )

    assert raport["unresolved_placeholders"] == []


def test_ampersand_w_nazwie_nie_znika_z_klucza_dopasowania():
    """`&` ma ZOSTAĆ w literale JS. Ucieczka HTML zamieniłaby go na `&amp;`
    i zdarzenia klubu przestałyby się dopasowywać."""
    slots, _ = render.team_slots(RAMKA, {"us": {"name": "Test & Spółka"}})
    assert "&" in slots["__TEAM_HOME__"]
    assert "&amp;" not in slots["__TEAM_HOME__"]
    assert "&amp;" in slots["__TEAM_HOME_LABEL__"], "etykieta idzie do HTML"
