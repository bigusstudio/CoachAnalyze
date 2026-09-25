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

    assert wynik["us"] is None and wynik["them"] is None
    assert wynik["confidence"] == "none"
    assert wynik["conflicts"] == [] and wynik["warnings"] == []


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


def test_baner_pojawia_sie_tylko_przy_zmianie_stron():
    """Pusto znaczy pusto, nie „wszystko w porządku".

    Baner wyświetlany zawsze przestaje być czytany po trzecim raporcie, więc
    raport bez ostrzeżenia ma wyglądać dokładnie tak, jak wyglądał.
    """
    assert render.baner_slot(None)["__BANER__"] == ""
    assert render.baner_slot([])["__BANER__"] == ""
    assert render.baner_slot(["KIERUNEK_NIEPEWNY"])["__BANER__"] == ""

    z_banerem = render.baner_slot([direction.KOD_ZMIANA_POLOWY])["__BANER__"]
    assert "bez normalizacji stron" in z_banerem
    assert 'class="baner"' in z_banerem


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


def test_naglowek_v21_nie_podpisuje_kierunku(write_csv, row, tmp_path, capsys):
    """Po normalizacji stron podpis byłby ZAWSZE taki sam, czyli szumem (sesja 7).

    Mapy są sprowadzane do jednego układu, więc tenant atakuje w prawo w każdym
    raporcie. Ślad został jeden — mała strzałka w legendzie map.
    """
    html = tmp_path / "r.html"
    main([
        "build", "--csv", _csv_atak_w_lewo(write_csv, row), "--config", _config(tmp_path),
        "--html-template", "v21",
        "--out-html", str(html), "--out-meta", str(tmp_path / "meta.json"),
    ])
    capsys.readouterr()
    tresc = html.read_text(encoding="utf-8")

    assert "atakuje w prawo" not in tresc
    assert "atakuje w lewo" not in tresc
    assert "__KIERUNEK" not in tresc
    assert tresc.count("▶ kierunek ataku") == 1, "jeden ślad, w legendzie map"


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
    # Baner wypełniony pustką, nie zostawiony w pliku.
    tresc = html.read_text(encoding="utf-8")
    assert "__BANER__" not in tresc and 'class="baner"' not in tresc


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


# ===========================================================================
# ZMIANA STRON PO PRZERWIE (sesja 7)


def _mecz_ze_zmiana_stron():
    """Drużyny zamieniają połowy boiska po przerwie — eksport bez normalizacji."""
    return ramka(
        strzaly("NASI", [88.0, 92.0, 95.0], half=1)
        + strzaly("NASI", [12.0, 9.0, 15.0], half=2)
        + strzaly("RYWAL", [14.0, 11.0, 18.0], half=1)
        + strzaly("RYWAL", [90.0, 93.0, 87.0], half=2)
    )


def test_zmiana_stron_jest_wykrywana():
    wynik = direction.wykryj(_mecz_ze_zmiana_stron(), tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["warnings"] == [direction.KOD_ZMIANA_POLOWY]
    assert wynik["halves"]["us"] == {"1": "right", "2": "left"}
    assert wynik["halves"]["them"] == {"1": "left", "2": "right"}


def test_mecz_znormalizowany_nie_zglasza_zmiany_stron():
    """Tak wyglądają wszystkie eksporty, które widzieliśmy (pułapka 2)."""
    frame = ramka(
        strzaly("NASI", [88.0, 92.0, 95.0], half=1)
        + strzaly("NASI", [90.0, 91.0, 93.0], half=2)
        + strzaly("RYWAL", [14.0, 11.0, 18.0], half=1)
        + strzaly("RYWAL", [12.0, 9.0, 15.0], half=2)
    )
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["warnings"] == []


def test_polowa_bez_strzalow_nie_zglasza_zmiany():
    """Brak odpowiedzi to nie jest odpowiedź przeciwna.

    Ostrzeżenie o stanie, którego nie dało się sprawdzić, uczy ignorować
    ostrzeżenia (CLAUDE.md §8 w duchu).
    """
    frame = ramka(strzaly("NASI", [88.0, 92.0, 95.0], half=1))
    wynik = direction.wykryj(frame, tag_rules=TAGI, lookup=LOOKUP)

    assert wynik["warnings"] == []
    assert wynik["halves"]["us"]["2"] is None


def test_zmiana_stron_daje_ostrzezenie_i_baner(write_csv, row, tmp_path, capsys):
    wiersze = []
    for i, (team, x, b) in enumerate(
        [("NASI", "88", 60), ("NASI", "92", 120), ("NASI", "95", 180),
         ("NASI", "12", 3000), ("NASI", "9", 3100), ("NASI", "15", 3200),
         ("RYWAL", "14", 240), ("RYWAL", "11", 300), ("RYWAL", "18", 360),
         ("RYWAL", "90", 3300), ("RYWAL", "93", 3400), ("RYWAL", "87", 3500)]
    ):
        wiersze.append(row("STRZAŁ", begin=b, team=team, x=x, y="34"))

    html = tmp_path / "r.html"
    main(["build", "--csv", write_csv(wiersze), "--config", _config(tmp_path),
          "--html-template", "v21", "--out-html", str(html),
          "--out-meta", str(tmp_path / "meta.json")])
    meta = json.loads(capsys.readouterr().out)

    kody = [w["code"] for w in meta["warnings"]]
    assert direction.KOD_ZMIANA_POLOWY in kody
    assert "odwrócone" in next(w for w in meta["warnings"]
                               if w["code"] == direction.KOD_ZMIANA_POLOWY)["msg"]

    tresc = html.read_text(encoding="utf-8")
    assert "bez normalizacji stron" in tresc
    assert 'class="baner"' in tresc

    # ODBICIA PER POŁOWA NIE ROBIMY — zgłaszamy i zostawiamy decyzję.
    assert meta["mirrored"] is False


def test_aliasy_domyslne_daja_dostepna_os_sbz(write_csv, row, tmp_path, capsys):
    """USTERKA Z ODBIORU NA SERWERZE (eksport JDRZ), sesja 7.

    Eksport taguje wejścia w SBZ jako `SBZ PODAJĄCY`. Szablon znał ten alias
    i liczył 11:20 w Przeglądzie; silnik go nie znał, więc pokrycie widziało
    zero wejść i wycinało całą oś SBZ z powodem „Eksport nie zawiera zdarzeń
    zdobycia SBZ". Jeden raport, dwie odpowiedzi na to samo pytanie.

    BEZ TEMPLATU — to jest sedno: klub JDRZ templatu nie ma.
    """
    csv_path = write_csv([
        row("SBZ PODAJĄCY", begin=60, team="NASI", x="70", y="34", tx="90", ty="34"),
        row("SBZ PODAJĄCY", begin=120, team="NASI", x="72", y="30", tx="92", ty="30"),
        row("III STREFA PODAJĄCY/OTRZYMUJĄCY", begin=180, team="NASI", x="70", y="34",
            tx="40", ty="34"),
        row("STRZAŁ", begin=240, team="NASI", x="88", y="34", labels="CELNY"),
        row("STRZAŁ", begin=300, team="NASI", x="92", y="30", labels="NIECELNY"),
        row("STRZAŁ", begin=360, team="NASI", x="95", y="38", labels="CELNY"),
    ])
    main(["build", "--csv", csv_path, "--config", _config(tmp_path),
          "--html-template", "v21", "--out-html", str(tmp_path / "r.html"),
          "--out-meta", str(tmp_path / "meta.json")])
    meta = json.loads(capsys.readouterr().out)

    assert "tl_sbz" in meta["sections_available"]
    assert meta["coverage"]["sbz"] == 2, "alias liczy się razem z nazwą główną"
    assert meta["coverage"]["third"] == 1
    # Tag pod aliasem NIE jest „nierozpoznany": ma pojęcie swojej nazwy głównej.
    assert meta["unmapped_tags"] == []


def test_alias_nie_jest_tagiem_podobnym():
    """`SBZ OTRZYMUJĄCY` to OSOBNE zdarzenie, nie alias `ZDOBYCIE SBZ`.

    Policzone razem podwoiłoby wejścia w SBZ — tagowane są na dwóch różnych
    zawodnikach tej samej akcji. Dopasowanie idzie przez równość całej nazwy
    (pułapka 7), więc `SBZ PODAJĄCY` nie łapie się też wewnątrz
    `SBZ PODAJĄCY/OTRZYMUJĄCY`.
    """
    from coachanalyze import aliasy

    odwr = aliasy.odwrotne()
    assert odwr["SBZ PODAJĄCY"] == "ZDOBYCIE SBZ"
    assert "SBZ OTRZYMUJĄCY" not in odwr
    assert "SBZ PODAJĄCY/OTRZYMUJĄCY" not in odwr
