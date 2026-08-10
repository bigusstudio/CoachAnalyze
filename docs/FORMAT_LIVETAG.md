# Format eksportu LiveTag.Pro — specyfikacja empiryczna

**Status: wiedza zdobyta w praktyce, nie dokumentacja producenta.**
Producent nie publikuje specyfikacji formatu. Wszystko poniżej pochodzi z analizy realnych eksportów
i może się zmienić bez zapowiedzi wraz z wersją LiveTag.

Ten plik jest do uzupełnienia po przekazaniu eksportów referencyjnych. Sekcje oznaczone `TODO`
wypełnia się na podstawie plików, nie z pamięci.

## Pliki eksportu

| Plik | Zawartość | Wymagany |
|---|---|---|
| `tagging.csv` | Tabela zdarzeń | tak |
| `tagging.json` | Projekt LiveTag: tagi, etykiety, paleta tablicy kodowej | nie |
| `tagging.xml` | Wariant tabeli zdarzeń | nie |
| `*.ltp3` | Projekt natywny | nie |

## Kolumny CSV

Ustalone empirycznie z dwóch eksportów referencyjnych (mecz Hutnik – Pogoń-Sokół, 294 zdarzenia).
Oba mają **ten sam zestaw 11 kolumn, w tej samej kolejności**:

| # | Kolumna | Typ | Wymagana | Uwagi |
|---|---|---|---|---|
| 1 | `tag_name` | tekst | **tak** | Nazwa taga, np. `STRZAŁ`, `III STREFA`, `1x1 DEF.` |
| 2 | `begin` | sekundy, zmiennoprzecinkowe | **tak** | **Bywa ujemny** — bufor przed tagiem (pułapka 10) |
| 3 | `end` | sekundy, zmiennoprzecinkowe | **tak** | |
| 4 | `players` | tekst | nie | **Nazwa w liczbie mnogiej.** W obu eksportach pusta w 100% wierszy |
| 5 | `labels` | tekst | nie | Lista rozdzielona przecinkami **wewnątrz jednej komórki**, w cudzysłowach |
| 6 | `team` | tekst | nie | Pełna nazwa klubu albo pusta. Pusta w 196/294 wierszy |
| 7 | `comment` | tekst | nie | Nośnik xG: `X 0,81`, `xG 0,09`, `x 0,14` |
| 8 | `pos_x_meters` | metry | nie | Pozycja zdarzenia |
| 9 | `pos_y_meters` | metry | nie | |
| 10 | `pos_target_x_meters` | metry | nie | Punkt docelowy — strzałka na mapie |
| 11 | `pos_target_y_meters` | metry | nie | |

Kolejność kolumn nie ma znaczenia — czytamy przez `csv.DictReader`, po nazwach.
Dlatego `format_fingerprint` liczy skrót z **posortowanego** zestawu nazw:
zmiana kolejności niczego nie psuje i nie ma podnosić alarmu.

Współrzędne są wypełnione tylko dla części tagów. W eksporcie referencyjnym mają je
`STRZAŁ` (29/29), `ZDOBYCIE SBZ` (34/34) i `III STREFA` (33/35); pozostałe tagi nie mają ich wcale.

### Słownik tagów i etykiet

Oba eksporty niosą **11 tagów** i **24 etykiety**.

Tagi: `STRATA` · `ODBIÓR` · `III STREFA` · `ZDOBYCIE SBZ` · `PIERWSZY KONTAKT` · `STRZAŁ` ·
`SKUTECZNY` · `NISKUTECZNY` · `1x1 OFF` · `1x1 DEF.` · `AKCJA DEFENSYWNA`

`AKCJA DEFENSYWNA` nie ma odpowiednika wśród pojęć bazowych i celowo pozostaje bez mapowania
do czasu decyzji człowieka — patrz `engine/coachanalyze/canon.py`.

## Znane pułapki

1. **xG w polu `comment`** — zapisy `X 0,81`, `xG 0,09`, `x 0,14`. Polski przecinek dziesiętny.
2. **Współrzędne w metrach, znormalizowane kierunkowo** — nie lustrzyć ponownie.
3. **III STREFA bywa bez współrzędnych** — pola pozycji puste mimo obecności zdarzeń.
4. **Kolumna zawodnika bywa pusta** — blokuje całą warstwę indywidualną.
5. **`team` obecne tylko na części tagów** — reszta bez przypisania drużyny.
6. **Etykiety w jednej komórce, rozdzielone przecinkiem, w cudzysłowach** — parser musi respektować cytowanie.
7. **Dopasowanie etykiet tylko przez równość** — `CELNY` jest fragmentem `NIECELNY`.
8. **Czas to czas wideo, nie czas meczu** — przerwa wykrywana z największej luki w środkowej ⅓.
9. **Literówki w nazwach tagów** — potwierdzona: `MASZA` zamiast `NASZA`.
10. **`begin` bywa ujemny** — bufor przed tagiem; przycinać do zera.

## Ryzyka zmienności między wersjami

- Zmienny zestaw kolumn
- Separator i kodowanie zależne od ustawień regionalnych stanowiska
- Heurystyka wykrywania przerwy zakłada ciągły plik wideo — nagranie w dwóch plikach ją psuje
- Założenie o orientacji współrzędnych może się zmienić

Mechanizm ochronny: `format_fingerprint` w `meta.json` — skrót zestawu kolumn. Rozjazd względem
poprzednich importów jest sygnalizowany operatorowi zamiast po cichu zjadać zdarzenia.

## Rozjazd między eksportami tego samego meczu

Ustalone na dwóch eksportach referencyjnych. **To jest ten sam mecz, w dwóch przejściach tagowania.**

**Najważniejszy wniosek: `format_fingerprint` tego rozjazdu NIE wykrywa i nie taka jest jego rola.**
Oba pliki mają identyczny zestaw kolumn, więc identyczny odcisk
(`sha256:75ce78bc…`). Odcisk pilnuje **struktury** pliku. Rozjazd **treści** widać wyłącznie
w liczbach pokrycia i w ostrzeżeniach — i dlatego raport pokrycia jest osobnym ekranem
przed generowaniem raportu, a nie ozdobnikiem.

| Co | Wcześniejsze przejście (`mecz1`) | Referencyjne, v23 (`mecz2`) | Skutek w raporcie |
|---|---|---|---|
| Pozycje III strefy | 0/35 | 33/35 | Sekcja `tl_iii` **wyłączona** (pułapka 3) |
| `MASZA POŁOWA` w zdarzeniach | 45 | 0 | Ostrzeżenie `TYPO_MASZA` (pułapka 9) |
| `NASZA POŁOWA` w zdarzeniach | 0 | 45 | — literówka poprawiona między przejściami |
| Etykieta `GOL` | 0 | 4 | Gole nieoznaczone w starszym przejściu |
| Etykieta `CELNY` | 8 | 15 | Mniej etykiet wyniku strzału |
| Etykieta `ODBIÓR` | 4 | 0 | Etykieta wycofana |
| Etykiet łącznie | 415 | 449 | |

Bez zmian w obu: liczba zdarzeń (294), `half_split` (2733,6), zestaw tagów (11),
strzały (29), xG (29, suma 4,40), zdobycia SBZ (34), zdarzenia bez drużyny (196).

Wniosek praktyczny: **liczba zdarzeń i zestaw tagów nie wystarczą do identyfikacji wersji eksportu.**
Dwa przejścia tagowania dają te same liczby zbiorcze i różne raporty. Rozróżnia je dopiero
porównanie pola po polu — dlatego wzorzec złoty jest porównaniem struktury, a nie licznikiem.

### Literówka `MASZA POŁOWA` żyje też w palecie

Plik projektu (`*.json`) niesie własną listę etykiet z barwami. W starszym przejściu
paleta zawiera `MASZA POŁOWA`, w nowszym `NASZA POŁOWA`. To znaczy, że literówki trzeba
szukać **w obu miejscach** — samo poprawienie zdarzeń zostawia rozjazd w barwach osi czasu.
`coverage.build_warnings` sprawdza jedno i drugie.
