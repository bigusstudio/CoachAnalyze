"""Kierunek ataku z danych, odbicie współrzędnych i strony raportu (sesja 4a).

Decyzja właściciela: docs/STAN_PIVOTU.md §7.7. Trzy rzeczy do sprawdzenia:
kierunek wychodzi z danych, mapy zawsze pokazują tenanta atakującego w prawo,
a domyślna ścieżka v17 — bez templatu i bez `tenant_club_id` — nie zmienia się
ani o bajt.
"""

import json

from coachanalyze import canon, direction, render
from coachanalyze.cli import main

TEAMS = {
    "us": {"name": "NASI", "color": "#E6A23C", "club_id": 7},
    "them": {"name": "RYWAL", "color": "#5CA8E0", "club_id": 9},
}
TAGI = canon.resolve_profile(None)["tags"]
LOOKUP = canon.build_team_lookup(TEAMS)


def ramka(events):
    return {"events": events, "half_split": 1500.0}


def strzaly(team, xs, half=1):
    return [
        {"tag": "STRZAŁ", "team": team, "b": 60.0 + i, "e": None, "labels": [],
         "xg": None, "x": x, "y": 34.0, "tx": None, "ty": None, "half": half}
        for i, x in enumerate(xs)
    ]


# ===========================================================================
# WYKRYWANIE KIERUNKU


def test_mediana_strzalow_po_prawej_daje_atak_w_prawo():
    """Liczby jak na eksporcie JDRZ: Pogoń 92,5 — rozdzielenie jednoznaczne."""
    frame = ramka(strzaly("NASI", [88.0, 92.5, 95.0, 90.6]) + strzaly("RYWAL", [27.7, 12.5, 20.0]))
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] == "right"
    assert wynik["them"] == "left"
    assert wynik["confidence"] == "high"
    assert wynik["evidence"]["us"]["shots"]["median_x"] == 91.55
    assert wynik["evidence"]["us"]["shots"]["n"] == 4


def test_mecz_w_druga_strone_daje_kierunek_odwrotny():
    frame = ramka(strzaly("NASI", [12.5, 17.0, 9.0]) + strzaly("RYWAL", [90.0, 88.0, 95.0]))
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert (wynik["us"], wynik["them"]) == ("left", "right")


def test_druga_polowa_nie_zmienia_stron():
    """Współrzędne są znormalizowane kierunkowo (pułapka 2) — liczymy raz na mecz.

    Gdyby kierunek liczył się per połowa, ten mecz dałby dwie różne odpowiedzi
    i mapa drugiej połowy wyszłaby odbita względem pierwszej.
    """
    frame = ramka(
        strzaly("NASI", [88.0, 92.0, 95.0], half=1)
        + strzaly("NASI", [90.0, 91.0, 93.0], half=2)
    )
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] == "right"


def test_eksport_bez_pozycji_nie_daje_odpowiedzi():
    """Brak odpowiedzi jest lepszy niż odpowiedź zmyślona — i nie jest ostrzeżeniem."""
    frame = ramka(strzaly("NASI", [None, None, None]))
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik == {"us": None, "them": None, "confidence": "none",
                     "evidence": wynik["evidence"], "conflicts": []}


def test_probka_mniejsza_niz_trzy_nie_rozstrzyga():
    """Dwa strzały to nie mediana, tylko dwie obserwacje."""
    frame = ramka(strzaly("NASI", [95.0, 92.0]))
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] is None


def test_sprzecznosc_strzalow_i_sbz_rozstrzygaja_strzaly():
    """Kontrola mówi co innego niż strzały: wygrywają strzały, idzie ostrzeżenie."""
    sbz = [
        {"tag": "ZDOBYCIE SBZ", "team": "NASI", "b": 100.0 + i, "e": None, "labels": [],
         "xg": None, "x": 40.0, "y": 34.0, "tx": tx, "ty": 34.0, "half": 1}
        for i, tx in enumerate([10.0, 12.0, 8.0])
    ]
    frame = ramka(strzaly("NASI", [88.0, 92.0, 95.0]) + sbz)
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] == "right"
    assert wynik["confidence"] == "low"
    assert wynik["conflicts"] == ["us:entry_sbz"]


def test_zwrot_iii_strefy_nie_rozstrzyga_sam():
    """Wektor III strefy potwierdza, ale nigdy nie odpowiada jako jedyny.

    Konwencja zwrotu zależy od tego, czy tag stoi na podającym, czy na
    otrzymującym — pomyłka w niej odwraca odpowiedź bez żadnego śladu.
    """
    trzecia = [
        {"tag": "III STREFA", "team": "NASI", "b": 100.0 + i, "e": None, "labels": [],
         "xg": None, "x": 30.0, "y": 34.0, "tx": 70.0, "ty": 34.0, "half": 1}
        for i in range(4)
    ]
    wynik = direction.wykryj(ramka(trzecia), tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] is None
    assert wynik["confidence"] == "none"
    assert wynik["evidence"]["us"]["third_dx"]["median_dx"] == -40.0


def test_zwrot_iii_strefy_liczony_od_otrzymujacego():
    """`x - tx`, nie odwrotnie — liczby jak w eksporcie referencyjnym.

    Drużyna strzelająca po lewej ma wejścia 33,9 -> 64,4; wektor `tx - x`
    wskazywałby jej stronę przeciwną do tej, w którą strzela.
    """
    trzecia = [
        {"tag": "III STREFA", "team": "NASI", "b": 100.0 + i, "e": None, "labels": [],
         "xg": None, "x": 33.9, "y": 34.0, "tx": 64.4, "ty": 34.0, "half": 1}
        for i in range(4)
    ]
    frame = ramka(strzaly("NASI", [13.1, 12.5, 14.0]) + trzecia)
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] == "left"
    assert wynik["conflicts"] == [], "wektor III strefy ma POTWIERDZAĆ strzały"
    assert wynik["confidence"] == "high"


def test_bez_strzalow_decyduje_kontrola_ale_z_zastrzezeniem():
    sbz = [
        {"tag": "ZDOBYCIE SBZ", "team": "NASI", "b": 100.0 + i, "e": None, "labels": [],
         "xg": None, "x": 40.0, "y": 34.0, "tx": tx, "ty": 34.0, "half": 1}
        for i, tx in enumerate([94.0, 96.0, 91.0])
    ]
    wynik = direction.wykryj(ramka(sbz), tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["us"] == "right"
    assert wynik["confidence"] == "low"


def test_tag_spoza_profilu_liczy_sie_po_nazwie_domyslnej():
    """Templat klubu ma zwykle `canon: null` (sesja 1b) — kierunek ma działać dalej.

    Profil mówi tu wprost, że `STRZAŁ` nie jest pojęciem `shot`. Kierunek to
    geometria, nie metryka, więc nazwa domyślna zostaje w zestawie.
    """
    profil_bez_pojec = {"STRZAŁ": {"concept": None, "qualifiers": (), "team_side": None}}
    frame = ramka(strzaly("NASI", [88.0, 92.0, 95.0]))
    wynik = direction.wykryj(frame, tag_rules=profil_bez_pojec, lookup=LOOKUP)

    assert wynik["us"] == "right"


# ===========================================================================
# ODBICIE WSPÓŁRZĘDNYCH


def test_odbicie_zmienia_x_i_tx_a_y_zostaje():
    frame = ramka([{"tag": "STRZAŁ", "team": "NASI", "b": 1.0, "e": None, "labels": [],
                    "xg": None, "x": 12.5, "y": 20.0, "tx": 5.0, "ty": 30.0, "half": 1}])
    odbita = direction.odbij_ramke(frame)
    zdarzenie = odbita["events"][0]

    assert (zdarzenie["x"], zdarzenie["tx"]) == (92.5, 100.0)
    assert (zdarzenie["y"], zdarzenie["ty"]) == (20.0, 30.0)
    # Ramka wejściowa zostaje nietknięta — model kanoniczny liczy z oryginału.
    assert frame["events"][0]["x"] == 12.5


def test_odbicie_zostawia_brak_pozycji_brakiem():
    """`None` to brak danych, nie zero — 105 - None nie jest 105."""
    frame = ramka([{"tag": "STRZAŁ", "team": "NASI", "b": 1.0, "e": None, "labels": [],
                    "xg": None, "x": None, "y": None, "tx": None, "ty": None, "half": 1}])
    zdarzenie = direction.odbij_ramke(frame)["events"][0]

    assert zdarzenie["x"] is None and zdarzenie["tx"] is None


# ===========================================================================
# STRONY RAPORTU


def test_lewy_slot_nalezy_do_tenanta():
    config = {"teams": TEAMS, "match": {"tenant_club_id": 7}}
    assert render.tenant_side(config) == "us"

    scouting = {"teams": TEAMS, "match": {"tenant_club_id": 9}}
    assert render.tenant_side(scouting) == "them"
    assert render.kolejnosc_slotow("them") == (("them", "HOME"), ("us", "AWAY"))


def test_tenant_po_stronie_them_dostaje_lewy_slot():
    frame = ramka(strzaly("NASI", [88.0, 92.0, 95.0]))
    slots, _ = render.team_slots(frame, TEAMS, tenant="them")

    assert slots["__TEAM_HOME_LABEL__"] == "RYWAL"
    assert slots["__TEAM_AWAY_LABEL__"] == "NASI"


def test_bez_tenant_club_id_zostaje_domyslne_us():
    """Brak pola nie przestawia stron po cichu."""
    assert render.tenant_side({"teams": TEAMS}) == "us"
    assert render.tenant_side(None) == "us"


def test_podpis_kierunku_idzie_za_odbiciem():
    """Nagłówek podpisuje to, CO WIDAĆ NA MAPIE, a nie surowy eksport."""
    kier = {"us": "left", "them": "right"}
    po_odbiciu = render.direction_slots(kier, mirrored=True, labels={"HOME": "A", "AWAY": "B"})

    assert po_odbiciu["__KIERUNEK_HOME__"] == "atakuje w prawo ▶ · "
    assert po_odbiciu["__KIERUNEK_AWAY__"] == " · ◀ atakuje w lewo"
    assert po_odbiciu["__KIERUNEK_OPIS__"] == "A atakuje bramkę po prawej, B po lewej."


def test_nieznany_kierunek_nie_podpisuje_niczego():
    """Pusto, nie „nieznany" i nie konwencja zastępcza (CLAUDE.md §8)."""
    puste = render.direction_slots(None, labels={"HOME": "A", "AWAY": "B"})
    assert set(puste.values()) == {""}


# ===========================================================================
# PRZELOT CLI


def _config(tmp_path, **nadpisz):
    cfg = {"teams": TEAMS, "match": {"tenant_club_id": 7}}
    cfg.update(nadpisz)
    sciezka = tmp_path / "config.json"
    sciezka.write_text(json.dumps(cfg), encoding="utf-8")
    return str(sciezka)


def _csv_atak_w_lewo(write_csv, row):
    return write_csv([
        row("STRZAŁ", begin=60, team="NASI", x="12.5", y="34", tx="2", ty="34"),
        row("STRZAŁ", begin=120, team="NASI", x="17.0", y="30", tx="4", ty="30"),
        row("STRZAŁ", begin=180, team="NASI", x="9.0", y="38", tx="1", ty="38"),
        row("STRZAŁ", begin=240, team="RYWAL", x="90.0", y="34"),
        row("STRZAŁ", begin=300, team="RYWAL", x="88.0", y="30"),
        row("STRZAŁ", begin=360, team="RYWAL", x="95.0", y="38"),
    ])


def test_build_odbija_gdy_tenant_atakuje_w_lewo(write_csv, row, tmp_path, capsys):
    zdarzenia = tmp_path / "events.json"
    kod = main([
        "build", "--csv", _csv_atak_w_lewo(write_csv, row), "--config", _config(tmp_path),
        "--html-template", "v21",
        "--out-html", str(tmp_path / "r.html"), "--out-meta", str(tmp_path / "meta.json"),
        "--out-events", str(zdarzenia),
    ])
    meta = json.loads(capsys.readouterr().out)

    assert kod == 0
    assert meta["direction"]["us"] == "left"
    assert meta["mirrored"] is True

    # TABELA `events` MA JEDEN UKŁAD: tenant atakuje w prawo. 12,5 -> 92,5.
    wiersze = json.loads(zdarzenia.read_text(encoding="utf-8"))["events"]
    nasze = [w for w in wiersze if w["team_side"] == "us"]
    assert sorted(w["x"] for w in nasze) == [88.0, 92.5, 96.0]
    assert sorted(w["tx"] for w in nasze) == [101.0, 103.0, 104.0]
    # `y` nietknięte — zamieniamy strony boiska, nie skrzydła.
    assert sorted(w["y"] for w in nasze) == [30.0, 34.0, 38.0]


def test_naglowek_v21_podpisuje_kierunek_z_danych(write_csv, row, tmp_path, capsys):
    html = tmp_path / "r.html"
    main([
        "build", "--csv", _csv_atak_w_lewo(write_csv, row), "--config", _config(tmp_path),
        "--html-template", "v21",
        "--out-html", str(html), "--out-meta", str(tmp_path / "meta.json"),
    ])
    capsys.readouterr()
    tresc = html.read_text(encoding="utf-8")

    # Po odbiciu tenant atakuje w prawo — i tak ma być podpisany lewy slot.
    assert "atakuje w prawo ▶ · " in tresc
    assert "NASI atakuje bramkę po prawej, RYWAL po lewej." in tresc
    assert "__KIERUNEK" not in tresc


def test_bez_pozycji_naglowek_milczy_i_nie_ma_ostrzezenia(write_csv, row, tmp_path, capsys):
    csv_path = write_csv([row("STRZAŁ", begin=60 * i, team="NASI") for i in range(1, 5)])
    html = tmp_path / "r.html"
    main([
        "build", "--csv", csv_path, "--config", _config(tmp_path), "--html-template", "v21",
        "--out-html", str(html), "--out-meta", str(tmp_path / "meta.json"),
    ])
    meta = json.loads(capsys.readouterr().out)

    assert meta["direction"]["confidence"] == "none"
    assert meta["mirrored"] is False
    assert [w for w in meta["warnings"] if w["code"] == "KIERUNEK_NIEPEWNY"] == []
    # Znacznik wypełniony pustką, nie zostawiony w pliku.
    assert "__KIERUNEK" not in html.read_text(encoding="utf-8")


def test_sprzecznosc_daje_ostrzezenie_w_meta(write_csv, row, tmp_path, capsys):
    csv_path = write_csv([
        row("STRZAŁ", begin=60, team="NASI", x="88", y="34"),
        row("STRZAŁ", begin=120, team="NASI", x="92", y="34"),
        row("STRZAŁ", begin=180, team="NASI", x="95", y="34"),
        row("ZDOBYCIE SBZ", begin=200, team="NASI", x="40", y="34", tx="10", ty="34"),
        row("ZDOBYCIE SBZ", begin=220, team="NASI", x="40", y="34", tx="12", ty="34"),
        row("ZDOBYCIE SBZ", begin=240, team="NASI", x="40", y="34", tx="8", ty="34"),
    ])
    main([
        "build", "--csv", csv_path, "--config", _config(tmp_path),
        "--out-html", str(tmp_path / "r.html"), "--out-meta", str(tmp_path / "meta.json"),
    ])
    meta = json.loads(capsys.readouterr().out)

    ostrzezenie = [w for w in meta["warnings"] if w["code"] == "KIERUNEK_NIEPEWNY"]
    assert len(ostrzezenie) == 1
    assert "entry_sbz" in ostrzezenie[0]["msg"]


def test_v17_bez_templatu_i_bez_tenanta_nie_zmienia_wyjscia(write_csv, row, tmp_path, capsys):
    """Bramka wdrożenia: domyślna ścieżka ma dać wyjście co do bajtu takie jak dotąd.

    Kierunek liczy się także tutaj (i ląduje w `meta`), ale v17 nie ma ani jednego
    znacznika grupy `kierunek`, a odbicie nie zachodzi, bo tenant atakuje w prawo.
    """
    csv_path = write_csv([
        row("STRZAŁ", begin=60, team="NASI", x="88", y="34"),
        row("STRZAŁ", begin=120, team="NASI", x="92", y="34"),
        row("STRZAŁ", begin=180, team="NASI", x="95", y="34"),
    ])
    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps({"teams": TEAMS}), encoding="utf-8")
    html = tmp_path / "r.html"

    main(["build", "--csv", csv_path, "--config", str(cfg),
          "--out-html", str(html), "--out-meta", str(tmp_path / "meta.json")])
    meta = json.loads(capsys.readouterr().out)

    assert meta["mirrored"] is False
    tresc = html.read_text(encoding="utf-8")
    assert "__KIERUNEK" not in tresc
    assert "atakuje w prawo" not in tresc
