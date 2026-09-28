"""Narzędzia testu W7-T: karta dowodowa i twarde reguły mapowania.

Wyłącznie dane syntetyczne — prawdziwe eksporty klienta nie trafiają do repo (CLAUDE.md §7).
"""
import csv
import importlib.util
import json
import os
import pathlib

import pytest

NARZEDZIA = pathlib.Path(__file__).resolve().parent.parent / "tools"


def _modul(nazwa):
    spec = importlib.util.spec_from_file_location(nazwa, NARZEDZIA / f"{nazwa}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


karta_mod = _modul("karta_dowodowa")
sprawdz_mod = _modul("sprawdz_mapowanie")

KOLUMNY = ["tag_name", "begin", "end", "players", "labels", "team", "comment",
           "pos_x_meters", "pos_y_meters", "pos_target_x_meters", "pos_target_y_meters"]

U_STRZAL, U_POS_A, U_POS_B, U_ODB, U_GOL = (
    "u-strzal", "u-pos-a", "u-pos-b", "u-odbior", "u-gol")


def _zapisz_csv(path, wiersze):
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh, quoting=csv.QUOTE_NONNUMERIC)
        w.writerow(KOLUMNY)
        for r in wiersze:
            w.writerow([r.get(k, "") for k in KOLUMNY])


def _zapisz_json(path, tagi):
    deps = [{"type": "team", "data": {"name": "Alfa", "uuid": "t1"}},
            {"type": "team", "data": {"name": "Beta", "uuid": "t2"}},
            {"type": "player", "data": {"name": "Kowalski Jan", "uuid": "p1"}}]
    for uuid, nazwa, params in tagi:
        deps.append({"type": "tag", "data": {
            "uuid": uuid, "name": nazwa, "params": params, "time_before": 5, "time_after": 5}})
    deps.append({"type": "tagging", "data": {"rows": [
        {"tag_uuid": U_STRZAL, "entities": [
            {"location": {"pitch_view": {"icon_name": "circle"}}}]}]}})
    with open(path, "w", encoding="utf-8") as fh:
        json.dump({"dependencies": deps, "software": {"version": "9.9"}}, fh)


TAGI = [
    (U_STRZAL, "Strzał", {"cm": False}),
    (U_POS_A, "Posiadanie Alfa", {"cm": True, "deactivation_tags": [U_POS_B]}),
    (U_POS_B, "Posiadanie Gamma", {"cm": True}),
    (U_ODB, "Odbiór", {"cm": False, "activation_tags": [U_POS_A]}),
    (U_GOL, "Gol", {"cm": False}),
]


def _mecz():
    """Alfa atakuje w prawo, Beta w lewo, w obu połowach. Przerwa ok. 2700 s."""
    w = []
    for t0 in (100, 3000):
        for i in range(4):
            w.append({"tag_name": "Strzał", "begin": t0 + i * 60, "end": t0 + i * 60 + 10,
                      "team": "Alfa", "players": "Kowalski Jan", "labels": "CELNY, Głowa",
                      "comment": f"xG 0,{i + 1}", "pos_x_meters": 90.0 + i})
            w.append({"tag_name": "Strzał", "begin": t0 + i * 60 + 30, "end": t0 + i * 60 + 40,
                      "team": "Beta", "labels": "NIECELNY", "comment": "Nowak strzelił po rogu",
                      "pos_x_meters": 12.0 + i})
    for i in range(0, 3600, 200):
        w.append({"tag_name": "Posiadanie Alfa", "begin": i, "end": i + 5 + (i % 7) * 3})
    for i in range(10):
        w.append({"tag_name": "Odbiór", "begin": 50 + i * 300, "end": 60 + i * 300})
    w.append({"tag_name": "Gol", "begin": 105, "end": 115, "team": "Beta"})
    return w


@pytest.fixture
def eksport(tmp_path):
    c, j = tmp_path / "mecz.csv", tmp_path / "mecz.json"
    _zapisz_csv(c, _mecz())
    _zapisz_json(j, TAGI)
    return c, j


def _tag(karta, nazwa):
    return next(t for t in karta["tagi"] if t["nazwa"] == nazwa)


# ─── karta dowodowa ────────────────────────────────────────────────────────


def test_karta_nie_zawiera_nazwisk_ani_tresci_komentarzy(eksport):
    tekst = json.dumps(karta_mod.karta(*eksport), ensure_ascii=False)
    assert "Kowalski" not in tekst
    assert "Nowak" not in tekst and "rogu" not in tekst
    assert "xG" not in tekst


def test_ksztalty_komentarzy(eksport):
    s = _tag(karta_mod.karta(*eksport), "Strzał")
    assert s["ksztalty_komentarzy"] == {"<litery> <liczba z przecinkiem>": 8, "<tekst>": 8}
    assert s["liczby_z_komentarzy"] == {"min": 0.1, "mediana": 0.25, "max": 0.4}


@pytest.mark.parametrize("wejscie,ksztalt", [
    ("xG 0,55", "<litery> <liczba z przecinkiem>"),
    ("X 0,81", "<litery> <liczba z przecinkiem>"),
    ("0.3", "<liczba>"),
    ("xg: 0,1", "<litery> : <liczba z przecinkiem>"),
    ("strzał z daleka 0,1", "<tekst>"),
    ("Kowalski", "<tekst>"),
])
def test_ksztalt_komentarza(wejscie, ksztalt):
    assert karta_mod.ksztalt_komentarza(wejscie)[0] == ksztalt


def test_druzyny_z_brakiem_i_pozycje(eksport):
    k = karta_mod.karta(*eksport)
    s = _tag(k, "Strzał")
    assert s["druzyny"] == {"Alfa": 8, "Beta": 8}
    assert s["z_pos_x"] == 16 and s["z_zawodnikiem"] == 8
    assert s["etykiety_top"] == {"CELNY": 8, "Głowa": 8, "NIECELNY": 8}
    assert s["znaczniki_na_boisku"] == {"circle": 1}
    assert _tag(k, "Odbiór")["druzyny"] == {"brak": 10}
    assert k["plik"]["druzyny_csv"] == {"Alfa": 8, "Beta": 9}


def test_aktywacje_z_params_zamienione_na_nazwy(eksport):
    k = karta_mod.karta(*eksport)
    assert _tag(k, "Odbiór")["aktywowany_przez"] == ["Posiadanie Alfa"]
    assert _tag(k, "Posiadanie Alfa")["dezaktywowany_przez"] == ["Posiadanie Gamma"]
    assert _tag(k, "Posiadanie Alfa")["cm"] is True


def test_tag_tylko_w_json(eksport, tmp_path):
    _zapisz_json(eksport[1], TAGI + [("u-x", "Nieużywany", {})])
    t = _tag(karta_mod.karta(*eksport), "Nieużywany")
    assert t["n"] == 0 and t["tylko_w_json"] is True


def test_sha_zdarzen_nie_zalezy_od_kolejnosci(tmp_path):
    w = _mecz()
    a, b = tmp_path / "a.csv", tmp_path / "b.csv"
    _zapisz_csv(a, w)
    _zapisz_csv(b, list(reversed(w)))
    ra, rb = karta_mod.read_rows(a)[0], karta_mod.read_rows(b)[0]
    assert karta_mod.sha256_pliku(a) != karta_mod.sha256_pliku(b)
    assert karta_mod.sha256_zdarzen(ra) == karta_mod.sha256_zdarzen(rb)


def test_jaccard():
    assert karta_mod.jaccard(["a", "b"], ["a", "b"]) == 1.0
    assert karta_mod.jaccard(["a", "b"], ["b", "c"]) == pytest.approx(0.333, abs=1e-3)


def test_korpus_paruje_po_tresci_i_deduplikuje(tmp_path):
    k1, k2 = tmp_path / "serwer", tmp_path / "lokalne"
    k1.mkdir(), k2.mkdir()
    w = _mecz()
    _zapisz_csv(k1 / "1a2b.csv", w)
    _zapisz_json(k1 / "ffee.json", TAGI)
    # Obcy JSON o innych tagach — nie może zostać sparowany.
    _zapisz_json(k1 / "0000.json", [(U_STRZAL, "Inny tag", {})])
    _zapisz_csv(k2 / "Mecz.csv", list(reversed(w)))
    _zapisz_json(k2 / "Mecz.json", TAGI)
    os.utime(k1 / "0000.json", (0, os.stat(k1 / "1a2b.csv").st_mtime))
    wynik = karta_mod.korpus([k1, k2])
    assert wynik["par"] == 2 and wynik["csv_bez_pary"] == []
    assert wynik["unikalnych_plikow_sha256"] == 2
    assert wynik["unikalnych_meczow"] == 1
    (mecz,) = wynik["mecze"].values()
    assert sorted(pathlib.Path(w["json"]).name for w in mecz["wystapienia"]) == ["Mecz.json", "ffee.json"]


# ─── twarde reguły ─────────────────────────────────────────────────────────


def _mapowanie(pewnosc=0.95, **nadpisz):
    m = {"Strzał": "shot", "Posiadanie Alfa": "possession", "Odbiór": "recovery", "Gol": "goal"}
    m.update(nadpisz)
    return [{"tag": t, "pojecie": p, "pewnosc": pewnosc} for t, p in m.items()]


def _po_tagu(wynik):
    return {t["tag"]: t for t in wynik["tagi"]}


def test_komplet_regul_i_pewnosc_daje_auto(eksport):
    w = _po_tagu(sprawdz_mod.sprawdz(eksport[0], _mapowanie(), eksport[1]))
    assert {t: v["status"] for t, v in w.items()} == dict.fromkeys(w, sprawdz_mod.AUTO)


def test_niska_pewnosc_idzie_do_admina(eksport):
    w = _po_tagu(sprawdz_mod.sprawdz(eksport[0], _mapowanie(pewnosc=0.89), eksport[1]))
    assert all(v["status"] == sprawdz_mod.ADMIN for v in w.values())


def test_odbior_jako_strzal_oblewa_pozycje(eksport):
    w = _po_tagu(sprawdz_mod.sprawdz(eksport[0], _mapowanie(**{"Odbiór": "shot"}), eksport[1]))
    assert w["Odbiór"]["reguly"]["R1_shot_pozycja"]["wynik"] == "nie"
    assert w["Odbiór"]["status"] == sprawdz_mod.ADMIN


def test_posiadanie_jako_strzal_oblewa_kierunek(tmp_path, eksport):
    w = _mecz()
    for r in w:
        if r["tag_name"] == "Posiadanie Alfa":
            r["pos_x_meters"], r["team"] = (10.0 if r["begin"] % 400 else 95.0), "Alfa"
    _zapisz_csv(eksport[0], w)
    wynik = _po_tagu(sprawdz_mod.sprawdz(
        eksport[0], _mapowanie(**{"Posiadanie Alfa": "shot"}), eksport[1]))
    assert wynik["Posiadanie Alfa"]["reguly"]["R2_shot_kierunek"]["wynik"] == "nie"


def test_wiecej_goli_niz_strzalow_oblewa(eksport):
    w = _po_tagu(sprawdz_mod.sprawdz(eksport[0], _mapowanie(**{"Posiadanie Alfa": "goal"}), eksport[1]))
    assert w["Gol"]["reguly"]["R3_goal_le_shot"]["wynik"] == "nie"


def test_znacznik_chwili_nie_jest_przedzialem(eksport):
    w = _po_tagu(sprawdz_mod.sprawdz(eksport[0], _mapowanie(**{"Odbiór": "press"}), eksport[1]))
    assert w["Odbiór"]["reguly"]["R5_przedzial"]["wynik"] == "nie"


def test_przedzial_ze_zmiennego_czasu_bez_cm(eksport):
    _zapisz_json(eksport[1], [(u, n, {"cm": False}) for u, n, _ in TAGI])
    w = _po_tagu(sprawdz_mod.sprawdz(eksport[0], _mapowanie(), eksport[1]))
    assert w["Posiadanie Alfa"]["reguly"]["R5_przedzial"]["wynik"] == "tak"


def test_brak_danych_do_reguly_to_nie_zaliczenie(tmp_path):
    c = tmp_path / "m.csv"
    _zapisz_csv(c, [{"tag_name": "Strzał", "begin": 1, "end": 2}])
    (w,) = sprawdz_mod.sprawdz(c, [{"tag": "Strzał", "pojecie": "shot", "pewnosc": 1}])["tagi"]
    assert w["reguly"]["R2_shot_kierunek"]["wynik"] == "nd"
    assert w["status"] == sprawdz_mod.ADMIN


def test_anomalie_gola_i_nazwy_druzyny(eksport):
    wynik = sprawdz_mod.sprawdz(
        eksport[0], _mapowanie(**{"Posiadanie Gamma": "possession"}), eksport[1])
    typy = {(a["typ"], a["tag"]) for a in wynik["anomalie"]}
    assert ("gol_inna_druzyna_niz_strzal", "Gol") in typy
    assert ("druzyna_w_nazwie_tagu", "Posiadanie Gamma") in typy
    assert ("druzyna_w_nazwie_tagu", "Posiadanie Alfa") not in typy
