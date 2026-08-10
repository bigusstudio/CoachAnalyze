# CHANGELOG

Format: [wersja silnika] — data — opis.
Każda zmiana, która modyfikuje wyjście silnika, MUSI mieć tu wpis wraz z powodem.

## [0.3.0] — 2026-08-11
### Model kanoniczny, raport pokrycia, komenda `inspect`
Etap 2 — druga część. Parser był gotowy, brakowało warstwy, na której cokolwiek się liczy.

**Wyjście parsera bez zmian.** `prep_events` przeniesione bez modyfikacji do `_build_events`,
żeby `prep_frame` czytał plik raz; zgodność z wzorcem v23 nadal weryfikowana.

Dodane:
- `canon.py` — `canonical_events[]` z profilem mapowań. Profil domyślny pokrywa cały słownik
  z eksportów referencyjnych (11 tagów, 24 etykiety); profil klubu nadpisuje go klucz po kluczu.
- `coverage.py` — `meta.coverage`, `meta.warnings`, dostępność sekcji z powodem po polsku.
- `cli.py inspect` — pełne spięcie, zgodne z docs/KONTRAKT_CLI.md.
- Testy pułapek (CLAUDE.md §3) na danych syntetycznych — 25 przypadków, działają bez eksportów klienta.
- Test złoty pokrycia — działa OD ZARAZ na wzorcu 294 zdarzeń leżącym w repozytorium.

Rozszerzenie `meta.json` (docs/KONTRAKT_CLI.md zaktualizowany w tym samym commicie):
- `coverage`: `duels`, `third`, `no_team`, `xg_sum`, `negative_begin`
- `unmapped_labels` obok `unmapped_tags`
- `missing_columns` w `meta.json` błędu przy kodzie wyjścia 3

Decyzje warte odnotowania:
- Zdarzenia z tagiem bez mapowania **nie znikają** — trafiają do wyniku z `concept: null`
  i `confidence: 0.0`. Suma zdarzeń kanonicznych zawsze równa się liczbie wierszy eksportu.
- `AKCJA DEFENSYWNA` (7 zdarzeń w eksporcie referencyjnym) celowo bez mapowania — nie ma
  odpowiednika wśród pojęć bazowych, a zgadywanie zmieniłoby liczby w raporcie.
- Przycinanie ujemnego `begin` do zera dzieje się w `canon`, nie w parserze — parser musi
  zwracać wartość surową, bo na tym stoi zgodność z v23.

Niezrobione: `render.py` i `metrics.py`.

### Eksporty referencyjne — identyfikacja i zestaw złoty
Po dostarczeniu trzech plików:

- `mecz2` == `mecz3` **bajtowo** (ten sam plik dwa razy). Zostają dwa różne eksporty.
- Wzorzec v23 to `mecz2.csv`, ale paleta v23 pochodzi z `mecz1.json` — produkcyjny raport
  powstał z nowszego CSV i starszego pliku projektu. Parowanie zapisane w manifeście
  wraz z uzasadnieniem; nie „poprawiane" pod test.
- `mecz1` to wcześniejsze przejście tagowania tego samego meczu: bez pozycji III strefy
  (0/35), z literówką `MASZA POŁOWA` (45×), bez etykiety `GOL`. Dodany jako przypadek
  wykrywania rozjazdu formatu.
- **`format_fingerprint` tego rozjazdu nie wykrywa** — zestaw kolumn jest identyczny.
  Wykrywają go liczby pokrycia i ostrzeżenia. Opisane w docs/FORMAT_LIVETAG.md.

Poprawka w parserze: kolumna zawodnika nazywa się `players` (liczba mnoga) i nie była
rozpoznawana. Skutek — ostrzeżenie `EMPTY_PLAYER_COLUMN` zamiast `NO_PLAYER_COLUMN`.

Test złoty przestał zależeć od plików `v23_expected_*.json`, których `.gitignore` nie
wpuszcza do repozytorium: wzorcem jest manifest ze skrótami SHA-256, a pliki są wygodą
lokalną. Bez danych klienta testy są pomijane, nigdy fałszywie zielone.

## [0.2.0] — 2026-08-11
### Port parsera z pandas na bibliotekę standardową
Powód: `noexec` na katalogu domowym lh.pl uniemożliwia załadowanie numpy/pandas
(docs/OGRANICZENIA_HOSTINGU.md).

**Wyjście bez zmian.** Zgodność potwierdzona na 294 zdarzeniach z v23: zero różnic
w 11 polach każdego zdarzenia, `half_split` identyczny (2733,6).

Odtworzone zachowania pandas, które łatwo zgubić przy porcie:
- lista napisów traktowanych jako NaN przy `read_csv` (`NA`, `null`, `n/a`, `None`, ...)
- bankierskie `round()` w zaokrąglaniu czasów, współrzędnych i składowych koloru
- `max()` na krotkach przy wykrywaniu przerwy — porównanie po rozmiarze luki, przy remisie po czasie
- `setdefault` w palecie — przy powtórzonej nazwie wygrywa pierwsze wystąpienie
- przypisanie połowy liczone na surowym `begin`, nie na zaokrąglonym

### Dodane (zmiana zachowania wobec oryginału — świadoma)
- Walidacja kolumn wejściowych: brak wszystkich kluczowych → kod wyjścia 2,
  brak części → kod wyjścia 3. Oryginał kończył się `KeyError` i tracebackiem.

## [0.1.0] — 2026-08-10
- Commit zerowy: struktura repozytorium, kontrakty, dokumentacja, CI.
- Infrastruktura na lh.pl: silnik, baza, Redis, HTTPS, wdrożenie z testem złotym.
