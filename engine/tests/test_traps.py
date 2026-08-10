"""Testy pułapek eksportu LiveTag (CLAUDE.md §3).

Uruchamiane na danych syntetycznych, więc nie wymagają eksportów klienta i działają w CI od pierwszego dnia.
"""

import pytest

pytestmark = pytest.mark.skip(reason="Aktywne po implementacji parsera (Etap 2)")


def test_xg_z_polskiego_przecinka():
    """'X 0,81' -> 0.81 ; 'xG 0,09' -> 0.09 ; 'x 0,14' -> 0.14"""


def test_etykiety_dopasowanie_przez_rownosc():
    """CELNY nie może się dopasować do NIECELNY. Najważniejszy test w tym pliku."""


def test_ujemny_begin_przyciety_do_zera():
    """begin = -3200 -> 0"""


def test_literowka_masza_mapowana_z_ostrzezeniem():
    """MASZA -> NASZA + warning TYPO_MASZA z licznikiem wystąpień"""


def test_brak_pozycji_iii_strefy_wylacza_sekcje():
    """sections_unavailable zawiera tl_iii z powodem po polsku"""


def test_pole_labels_z_przecinkami_w_cudzyslowie():
    '''"POZYCYJNIE, CELNY, KONTRATAK" -> trzy etykiety, nie rozjechane kolumny'''


def test_plik_niebedacy_eksportem_livetag():
    """Kod wyjścia 2, nie traceback"""
