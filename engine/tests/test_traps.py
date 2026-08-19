"""Testy pułapek eksportu LiveTag (CLAUDE.md §3).

Uruchamiane na danych syntetycznych, więc nie wymagają eksportów klienta i działają w CI od pierwszego dnia.

Każdy test odpowiada jednej pozycji z tabeli pułapek. Jeśli któryś zaczyna być
niewygodny — to jest dokładnie ten moment, w którym ktoś próbuje usunąć obejście,
które kosztowało realny czas na wykryciu.
"""

import json

import pytest

from coachanalyze import canon, coverage
from coachanalyze.cli import main
from coachanalyze.sources.livetag import parse


def _meta(csv_path, **kwargs):
    frame = parse.prep_frame(csv_path)
    result = canon.build(frame, **kwargs)
    return frame, result, coverage.build_meta(frame, result)


# ---------------------------------------------------------------- pułapka 1
@pytest.mark.parametrize("comment,expected", [
    ("X 0,81", 0.81),
    ("xG 0,09", 0.09),
    ("x 0,14", 0.14),
    ("X 0.5", 0.5),
    ("bez liczby", None),
    ("", None),
])
def test_xg_z_polskiego_przecinka(comment, expected):
    """'X 0,81' -> 0.81 ; 'xG 0,09' -> 0.09 ; 'x 0,14' -> 0.14"""
    assert parse.parse_xg(comment) == expected


def test_komentarz_przy_niestrzale_nie_daje_xg(write_csv, row):
    """„3 zawodników w polu karnym" przy ZDOBYCIE SBZ nie może dać xG = 3.0.

    Parser wyciąga z komentarza pierwszą liczbę, jaką znajdzie — dopiero model
    kanoniczny wie, czy zdarzenie jest strzałem.
    """
    path = write_csv([
        row("ZDOBYCIE SBZ", comment="3 zawodników w polu karnym", team="A", x="90", y="40"),
        row("STRZAŁ", comment="X 0,81", team="A", x="88", y="31"),
    ])
    frame, result, meta = _meta(path)

    assert frame["events"][0]["xg"] == 3.0, "parser widzi samą liczbę, bez kontekstu"
    assert result["events"][0]["xg"] is None, "xG poza strzałem odrzucone"
    assert result["events"][0]["xg_source"] is None
    assert result["events"][1]["xg"] == 0.81, "strzał zachowuje xG"

    assert meta["coverage"]["xg_sum"] == 0.81, "suma xG nie może połknąć liczby z komentarza"
    ostrzezenie = next(w for w in meta["warnings"] if w["code"] == "XG_POZA_STRZALEM")
    assert ostrzezenie["count"] == 1
    assert ostrzezenie["tags"] == ["ZDOBYCIE SBZ"]


def test_brak_ostrzezenia_gdy_xg_tylko_na_strzalach(write_csv, row):
    path = write_csv([row("STRZAŁ", comment="X 0,50", team="A", x="88", y="31")])
    _, _, meta = _meta(path)
    assert not [w for w in meta["warnings"] if w["code"] == "XG_POZA_STRZALEM"]


def test_xg_trafia_do_zdarzenia_kanonicznego(write_csv, row):
    path = write_csv([row("STRZAŁ", comment="X 0,81", x="88.4", y="31.2")])
    _, result, meta = _meta(path)
    event = result["events"][0]
    assert event["xg"] == 0.81
    assert event["xg_source"] == "analyst", "xG z komentarza jest wpisane przez analityka"
    assert meta["coverage"]["xg_parsed"] == 1
    assert meta["coverage"]["xg_missing"] == 0


# ---------------------------------------------------------------- pułapka 7
def test_etykiety_dopasowanie_przez_rownosc(write_csv, row):
    """CELNY nie może się dopasować do NIECELNY. Najważniejszy test w tym pliku."""
    path = write_csv([
        row("STRZAŁ", labels="NIECELNY", comment="X 0,10", x="80", y="30"),
        row("STRZAŁ", labels="CELNY", comment="X 0,20", x="81", y="31"),
    ])
    _, result, _ = _meta(path)
    niecelny, celny = result["events"]

    assert "off_target" in niecelny["qualifiers"]
    assert "on_target" not in niecelny["qualifiers"], (
        "CELNY dopasowało się wewnątrz NIECELNY — dopasowanie przez fragment "
        "tekstu psuje liczby po cichu, bez błędu i bez ostrzeżenia"
    )
    assert "on_target" in celny["qualifiers"]
    assert "off_target" not in celny["qualifiers"]


def test_etykieta_bez_mapowania_nie_znika_po_cichu(write_csv, row):
    path = write_csv([row("STRZAŁ", labels="ETYKIETA SPOZA SŁOWNIKA")])
    _, result, meta = _meta(path)
    assert result["report"]["unmapped_labels"] == ["ETYKIETA SPOZA SŁOWNIKA"]
    assert meta["unmapped_labels"] == [{"label": "ETYKIETA SPOZA SŁOWNIKA", "count": 1}]
    assert result["events"][0]["source_labels"] == ["ETYKIETA SPOZA SŁOWNIKA"]


# --------------------------------------------------------------- pułapka 10
def test_ujemny_begin_przyciety_do_zera(write_csv, row):
    """begin = -3200 -> 0"""
    path = write_csv([row("STRZAŁ", begin=-3.2)])
    frame, result, meta = _meta(path)

    assert frame["events"][0]["b"] == 0.0, "parser przycina bufor taga do zera"
    assert result["events"][0]["t_ms"] == 0
    assert meta["coverage"]["negative_begin"] == 1, (
        "licznik musi przeżyć przycięcie — inaczej ostrzeżenie cicho znika"
    )
    assert [w for w in meta["warnings"] if w["code"] == "NEGATIVE_BEGIN"]


def test_przyciecie_nie_przesuwa_wykrywania_przerwy(write_csv, row):
    """Bufor taga nie może ruszyć `half_split` ani przypisania połowy.

    Gdyby wykrywanie przerwy liczyło się z przyciętych wartości, ujemny tag
    na starcie zbiłby rozkład luk i przesunął podział na połowy.
    """
    begins = (-3.2, 500, 900, 1300, 2000, 2400, 2800, 3000)
    path = write_csv([row("STRATA", begin=t, end=t + 5) for t in begins])
    frame, result, _ = _meta(path)

    assert frame["half_split"] == 1650.0, "podział liczony z surowych wartości"
    assert frame["events"][0]["b"] == 0.0
    assert [e["half"] for e in result["events"]] == [1, 1, 1, 1, 2, 2, 2, 2]


def test_zadne_b_nie_jest_ujemne(write_csv, row):
    path = write_csv([row("STRATA", begin=t) for t in (-3.2, -0.4, 0.0, 12.5)])
    frame, _, meta = _meta(path)

    assert all(e["b"] >= 0 for e in frame["events"])
    assert meta["coverage"]["negative_begin"] == 2


# ---------------------------------------------------------------- pułapka 9
def test_literowka_masza_mapowana_z_ostrzezeniem(write_csv, row):
    """MASZA -> NASZA + warning TYPO_MASZA z licznikiem wystąpień"""
    path = write_csv([
        row("STRATA", labels="MASZA POŁOWA"),
        row("ODBIÓR", labels="MASZA POŁOWA"),
        row("ODBIÓR", labels="NASZA POŁOWA"),
    ])
    _, result, meta = _meta(path)

    assert all("own_half" in e["qualifiers"] for e in result["events"])
    warning = next(w for w in meta["warnings"] if w["code"] == "TYPO_MASZA")
    assert warning["count"] == 2, "licznik ma pokazywać liczbę wystąpień, nie sam fakt"
    assert "MASZA" in warning["msg"] and "NASZA" in warning["msg"]


def test_literowka_nie_jest_podmiana_fragmentu():
    """Mapowanie przez równość całego napisu — nie przez str.replace."""
    assert canon.normalize_name("MASZA POŁOWA") == ("NASZA POŁOWA", True)
    assert canon.normalize_name("NIE MASZA POŁOWA") == ("NIE MASZA POŁOWA", False)


# ---------------------------------------------------------------- pułapka 3
def test_brak_pozycji_iii_strefy_wylacza_sekcje(write_csv, row):
    """sections_unavailable zawiera tl_iii z powodem po polsku"""
    path = write_csv([
        row("III STREFA", team="A", labels="UDANA"),
        row("III STREFA", team="A", labels="NIEUDANA"),
    ])
    _, _, meta = _meta(path)

    assert meta["coverage"]["third"] == 2, "same zdarzenia III STREFY są obecne"
    assert meta["coverage"]["third_pos"] == 0, "ale bez współrzędnych"
    assert "tl_iii" not in meta["sections_available"]

    missing = next(s for s in meta["sections_unavailable"] if s["id"] == "tl_iii")
    assert "III STREFY" in missing["reason"], "powód po polsku, prosto do interfejsu"


def test_iii_strefa_z_pozycjami_wlacza_sekcje(write_csv, row):
    path = write_csv([row("III STREFA", team="A", x="50", y="30", tx="60", ty="35")])
    _, _, meta = _meta(path)
    assert "tl_iii" in meta["sections_available"]


# --------------------------------------------------------------- pułapka 11
def test_pole_labels_z_przecinkami_w_cudzyslowie(write_csv, row):
    '''"POZYCYJNIE, CELNY, KONTRATAK" -> trzy etykiety, nie rozjechane kolumny'''
    path = write_csv([
        row("STRZAŁ", labels="POZYCYJNIE, CELNY, KONTRATAK", x="88.4", y="31.2"),
    ])
    frame, result, _ = _meta(path)

    assert frame["events"][0]["labels"] == ["POZYCYJNIE", "CELNY", "KONTRATAK"]
    assert frame["events"][0]["x"] == 88.4, (
        "współrzędna wjechała do złej kolumny — przecinek w etykietach rozsunął wiersz"
    )
    assert set(result["events"][0]["qualifiers"]) == {"positional", "on_target", "counter"}


# ---------------------------------------------------------------- pułapka 5
def test_zdarzenia_bez_druzyny_trafiaja_do_osobnej_sekcji(write_csv, row):
    path = write_csv([
        row("STRATA", team=""),
        row("ODBIÓR", team="HUTNIK"),
    ])
    _, result, meta = _meta(path, teams={"us": {"name": "HUTNIK"}})

    assert [e["team_side"] for e in result["events"]] == ["none", "us"]
    assert meta["coverage"]["no_team"] == 1
    assert "noteam" in meta["sections_available"]


# ---------------------------------------------------------------- pułapka 4
def test_brak_kolumny_zawodnika_blokuje_warstwe_indywidualna(write_csv, row):
    path = write_csv([row("STRZAŁ")])
    _, _, meta = _meta(path)

    assert meta["coverage"]["players_filled"] == 0
    assert [w for w in meta["warnings"] if w["code"] == "NO_PLAYER_COLUMN"]
    assert all(e["player_id"] is None for e in _meta(path)[1]["events"])


def test_pusta_kolumna_zawodnika_jest_odrozniana_od_braku_kolumny(write_csv, row):
    headers = ["tag_name", "begin", "end", "team", "labels", "comment",
               "pos_x_meters", "pos_y_meters", "pos_target_x_meters",
               "pos_target_y_meters", "player"]
    path = write_csv([row("STRZAŁ") + [""]], headers=headers)
    _, _, meta = _meta(path)

    codes = [w["code"] for w in meta["warnings"]]
    assert "EMPTY_PLAYER_COLUMN" in codes
    assert "NO_PLAYER_COLUMN" not in codes


# ---------------------------------------------------------------- pułapka 8
def test_przerwa_wykryta_z_najwiekszej_luki_w_srodkowej_czesci(write_csv, row):
    # Kodowanie gęste do 1300 s, przerwa, wznowienie od 2000 s. Luka 700 s leży
    # w środkowej ⅓ zapisu i jest największa — to ona wyznacza podział na połowy.
    begins = (100, 500, 900, 1300, 2000, 2400, 2800, 3000)
    path = write_csv([row("STRATA", begin=t, end=t + 5) for t in begins])
    frame, result, meta = _meta(path)

    assert frame["half_split"] == 1650.0
    assert meta["half_split_ms"] == 1650000
    assert [e["half"] for e in result["events"]] == [1, 1, 1, 1, 2, 2, 2, 2]


def test_remis_luk_rozstrzygany_pozniejsza(write_csv, row):
    """Przy równych lukach wygrywa późniejsza — max() po krotce (luka, t, t+1).

    ZGODNOŚĆ z v23: zmiana tej kolejności przesunęłaby podział na połowy
    w meczach o równomiernym kodowaniu, czyli zmieniłaby liczby w raporcie.
    """
    path = write_csv([row("STRATA", begin=t, end=t + 5) for t in (10, 20, 30)])
    frame, _, _ = _meta(path)
    assert frame["half_split"] == 25.0


def test_brak_luki_w_srodkowej_czesci_nie_wywraca_podzialu(write_csv, row):
    """Nagranie w dwóch plikach psuje heurystykę — ma dać wynik, nie wyjątek."""
    path = write_csv([row("STRATA", begin=t, end=t + 5) for t in (10, 20, 3000)])
    frame, _, _ = _meta(path)
    assert isinstance(frame["half_split"], float)


# ------------------------------------------------------------ kody wyjścia
def test_plik_niebedacy_eksportem_livetag(tmp_path, capsys):
    """Kod wyjścia 2, nie traceback"""
    path = tmp_path / "cokolwiek.csv"
    path.write_text("imie,nazwisko\nJan,Kowalski\n", encoding="utf-8")

    code = main(["inspect", "--csv", str(path)])
    payload = json.loads(capsys.readouterr().out)

    assert code == 2
    assert payload["ok"] is False
    assert payload["code"] == "NOT_LIVETAG"


def test_brak_wymaganych_kolumn_zwraca_liste_brakow(tmp_path, capsys):
    path = tmp_path / "polowiczny.csv"
    path.write_text("tag_name,begin\nSTRZAŁ,10\n", encoding="utf-8")

    code = main(["inspect", "--csv", str(path)])
    payload = json.loads(capsys.readouterr().out)

    assert code == 3
    assert payload["code"] == "MISSING_COLUMNS"
    assert payload["missing_columns"] == ["end"], "PHP pokazuje tę listę operatorowi"


def test_meta_zapisywane_takze_przy_bledzie(tmp_path, capsys):
    """Przy kodach 2 i 3 silnik mimo wszystko zapisuje meta.json (KONTRAKT_CLI.md)."""
    csv_path = tmp_path / "zly.csv"
    csv_path.write_text("imie\nJan\n", encoding="utf-8")
    meta_path = tmp_path / "meta.json"

    code = main(["inspect", "--csv", str(csv_path), "--out-meta", str(meta_path)])
    capsys.readouterr()

    assert code == 2
    assert json.loads(meta_path.read_text(encoding="utf-8"))["ok"] is False


# ===========================================================================
# KOMENTARZ BEZ LICZBY — `comment` jest polem SWOBODNYM
#
# Poprzednia wersja `parse_xg` dopasowywala `([\d,\.]+)`, czyli takze sam
# przecinek. Komentarz trenera „zmiana, potem strzal" dawal `float(".")`
# i ValueError, ktory przewracal CALY import — a operator widzial komunikat
# o konwersji na liczbe, z ktorego nie wynikalo nic o przecinku w komentarzu.
# ===========================================================================


@pytest.mark.parametrize("comment", [
    "zmiana, potem strzal",
    "uwaga, druga polowa",
    ",",
    ".",
    "...",
    "dobra akcja",
    "",
])
def test_komentarz_bez_liczby_nie_wywala_importu(comment):
    """Zero wyjatku i zero xG — komentarz bez cyfry to zwykly opis."""
    assert parse.parse_xg(comment) is None


@pytest.mark.parametrize("comment,oczekiwane", [
    ("X 0,81", 0.81),
    ("xG 0,09", 0.09),
    ("x 0,14", 0.14),
    ("5 minut", 5.0),
    # Zapis z wiodaca kropka parsowal sie dotad na 0.5 i MA sie parsowac dalej.
    # Wzorzec wymagajacy cyfry NA POCZATKU zmienilby to po cichu na 5.0.
    (".5", 0.5),
    ("uwaga, 5", 5.0),
])
def test_liczba_w_komentarzu_czytana_bez_zmian(comment, oczekiwane):
    assert parse.parse_xg(comment) == oczekiwane


def test_komentarz_nieczytelny_daje_none_zamiast_wyjatku():
    """„1,2,3" wyglada na liczbe i nia nie jest. Zamiast wyjatku — brak xG."""
    assert parse.parse_xg("1,2,3") is None


def test_nieczytelne_xg_trafia_do_pokrycia_i_ostrzezen(write_csv, row):
    """Brak xG ma byc WIDOCZNY, nie zamaskowany.

    Nie bylo xG i bylo, ale nieczytelne, to dwie rozne rzeczy dla analityka,
    wiec drugie liczy sie osobno i niesie ostrzezenie.
    """
    frame = parse.prep_frame(write_csv([
        row("STRZAŁ", comment="1,2,3", x="80", y="30"),
        row("STRZAŁ", comment="X 0,50", x="81", y="31"),
        row("STRZAŁ", comment="dobra akcja", x="82", y="32"),
    ]))
    meta = coverage.build_meta(frame, canon.build(frame))

    assert meta["coverage"]["xg_unparsed"] == 1, "nieczytelny jest wylacznie 1,2,3"
    assert meta["coverage"]["xg_parsed"] == 1, "X 0,50 czyta sie bez zmian"
    assert [w for w in meta["warnings"] if w["code"] == "XG_NIECZYTELNE"]


def test_komentarz_opisowy_nie_jest_zglaszany_jako_nieczytelny(write_csv, row):
    """Komentarz bez ani jednej cyfry to opis, nie zepsute xG — cisza jest tu poprawna."""
    frame = parse.prep_frame(write_csv([row("STRZAŁ", comment="zmiana, potem strzal")]))
    meta = coverage.build_meta(frame, canon.build(frame))

    assert meta["coverage"]["xg_unparsed"] == 0
    assert not [w for w in meta["warnings"] if w["code"] == "XG_NIECZYTELNE"]
