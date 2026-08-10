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

TODO — uzupełnić z eksportów referencyjnych: pełna lista, typy, przykłady, które są opcjonalne.

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
