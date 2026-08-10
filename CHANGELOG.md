# CHANGELOG

Format: [wersja silnika] — data — opis.
Każda zmiana, która modyfikuje wyjście silnika, MUSI mieć tu wpis wraz z powodem.

## [0.7.0] — 2026-08-11
### Metryki i render — `build` działa end-to-end
Etap 2 domknięty. `build` kończył się dotąd kodem 4 mimo policzonych danych; teraz zwraca 0
i zapisuje raport HTML.

**`metrics.py`** — pakiet metryk z `canonical_events[]`. Wejście warstwy AI (D5) i porównań
sezonowych. Trzy zasady, których nie łamać:
- Liczy z pojęć kanonicznych, **nigdy z `source_tag`**. Nazwa tagu należy do klubu i zmienia
  się między przejściami tagowania — liczenie po niej wraca do problemu, dla którego istnieje
  model kanoniczny.
- Procent przy zerowym mianowniku to `None`, nie `0.0`. „0% wygranych pojedynków" i „nie było
  pojedynków" to dwa różne zdania.
- Podział `us`/`them`/`none` oraz na połowy jest rozłączny i zupełny — sprawdzane testem.
  `none` to 196 z 294 zdarzeń w eksporcie referencyjnym, czyli większość pojedynków, strat
  i odbiorów. To nie jest odpad.

Świadoma różnica wobec szablonu: strzał bez etykiety wyniku trafia do `outcome_unknown`.
Szablon domyśla się w tym miejscu `NIECELNY` (`outcomeOf` ma taką gałąź domyślną). Silnik
regułowy wyniku strzału się nie domyśla.

**`render.py`** — wstrzykiwanie w `/*__DATA__*/` i `/*__PAL__*/`, serializacja jak w oryginalnym
`build_dashboard.py` (DATA kompaktowo, PAL ze spacjami). Zmiana separatorów zmienia bajty pliku.

**Asercja na LICZBIE wystąpień, nie na obecności.** Powód jest historyczny: przy v13 skrypt
podmiany trafił w komentarz `/* timeline */` w CSS i zniszczył szablon (README oryginału, pkt 5).
Sprawdzamy dwa razy — przed podmianą, że każdy wzorzec jest dokładnie raz **razem ze średnikiem**,
i po niej, że żaden nie został. Samo `'/*__DATA__*/' in html` przepuściłoby szablon bez średnika:
`str.replace` nie trafiłby w nic, nie zgłosiłby błędu, a przeglądarka dostałaby `const DATA = ;`.
Raport pusty, wdrożenie zielone. Nie `assert` — `python -O` wycina te instrukcje z bajtkodu.

Do przeglądarki idą wyłącznie `events` i `half_split`. Nagłówki eksportu, `format_fingerprint`
i nazwa kolumny zawodnika zostają na serwerze — raport wisi pod publicznym adresem (D3).

**Pakiet metryk NIE jest wstrzykiwany w szablon.** Szablon v17 liczy wszystko sam, w JS, po
nazwach tagów klienta. Wstrzyknięcie zmieniłoby bajty pliku i zerwało porównanie z v23. Zamiast
tego render porównuje jedno z drugim i przy rozjeździe pisze ostrzeżenie na stderr: profil klubu
mapujący własną nazwę tagu na pojęcie kanoniczne rozjeżdża raport z archiwum.

Zmiany w `cli.py` (docs/KONTRAKT_CLI.md zaktualizowany w tym samym commicie):
- `--out-metrics`, opcjonalny.
- `meta.json` zapisywany **na końcu** i tylko przy powodzeniu. Wcześniej powstawał przed renderem,
  co przy błędzie renderu zostawiało na dysku `ok: true` bez raportu i wypisywało na stdout drugi
  obiekt JSON. PHP czyta stamtąd dokładnie jeden.
- `--out-canon` i `--out-metrics` nadal przed renderem: awaria szablonu nie kasuje wyniku parsowania.

### Wyjście renderu wobec v23 — zgodne co do danych, szablon starszy
Porównanie wygenerowanego raportu z `livetag_dashboard_v23.html` (raport produkcyjny klienta):

- **`PAL` identyczne bajtowo** (859 bajtów).
- **`DATA` różni się w dwóch zdarzeniach z 294** i są to dokładnie te zatwierdzone w 0.4.0
  (`b`: −3,2 → 0,0 oraz −0,4 → 0,0). `half_split` bez zmian. Różnice zapisane w manifeście
  jako `v23_data_roznice` — każda inna jest błędem.
- **Szablon w repozytorium to generacja v17, nie v23.** `engine/templates/dashboard_template.html`
  jest bajtowo tym plikiem, który dostarczono razem z `build_dashboard.py`. v23 to nowsze UI:
  wielokrotny wybór przedziałów 15-minutowych zamiast pojedynczego fragmentu, belka nawigacyjna
  z podsumowaniem filtrów, powierzchnia znacznika strzału proporcjonalna do xG, zamienione barwy
  drużyn, herby wstawione na stałe. Podmiana szablonu to osobna decyzja — nie robię jej przy okazji.
  Manifest niesie pole `szablon_generacja` i test czerwienieje, gdy szablon rozjedzie się z tym wpisem.

## [0.4.0] — 2026-08-11
### Ujemny `begin` przycinany do zera — ZMIANA WYJŚCIA
`_build_events` liczy `b` jako `max(0, begin)`. Ujemna wartość to bufor nagrania przed
tagiem (pułapka 10), a nie czas zdarzenia.

**Przypisanie połowy i wykrywanie `half_split` nadal na SUROWYM `begin`.** Bufor taga nie
może przesunąć wykrycia przerwy — przycięcie przed wyliczeniem luk zbiłoby ich rozkład.

Skutek na meczu referencyjnym: **dwa zdarzenia z 294** (`b`: −3,2 → 0,0 oraz −0,4 → 0,0).
Nic poza tym; `half_split` bez zmian (2733,6).

Licznik `negative_begin` przeniesiony do `prep_frame` i liczony na surowych wierszach —
po przycięciu nie dałoby się już ustalić, ilu tagów dotyczyło, i ostrzeżenie
`NEGATIVE_BEGIN` cicho by znikło.

**Wzorzec złoty aktualizowany w NASTĘPNYM commicie**, zgodnie z CLAUDE.md §2.
Ten commit ma czerwony test złoty — to jest oczekiwane i celowe.

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
