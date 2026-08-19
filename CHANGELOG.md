# CHANGELOG

Format: [wersja silnika] — data — opis.
Każda zmiana, która modyfikuje wyjście silnika, MUSI mieć tu wpis wraz z powodem.

## [0.10.0] — 2026-08-19
### `meta.json`: pełny słownik eksportu (`dictionary`)
Konfigurator raportu klubu (Sesja 3 przebudowy) buduje templat z pierwszego importu
i potrzebuje KOMPLETNEJ listy tego, co w pliku jest. `unmapped_tags` do tego nie
wystarcza — niesie wyłącznie pozycje, których silnik NIE rozpoznał. `inspect` nie
dostaje profilu klubu, więc tagi z domyślnego słownika (`STRZAŁ`, `ZDOBYCIE SBZ`,
`III STREFA`, `STRATA`, `ODBIÓR`…) są rozpoznawane i z tamtej listy znikają.
Na eksporcie referencyjnym to **9 z 11 tagów** — konfigurator pokazywałby prawie
pustą listę dokładnie wtedy, gdy operator buduje z niej templat.

- `meta.dictionary.tags`: `[{ "tag", "count", "samples" }]`
- `meta.dictionary.labels`: `[{ "label", "count", "samples" }]`
- `samples` niosą wyłącznie `b`, `team`, `labels` — najwyżej trzy na pozycję.
  Bez współrzędnych, komentarza i xG: próbka ma odpowiedzieć „co to za tag",
  a nie odtwarzać przebieg meczu.
- Kolejność deterministyczna: malejąco po `count`, remisy alfabetycznie.

Blok jest **czysto addytywny** — agregacja po zdarzeniach już sparsowanych, żadna
wartość w `coverage`, w metrykach ani w renderze się nie zmienia. Wyjście testu
złotego bez zmian.

### Naprawa: `parse_xg` przewracał import na komentarzu bez liczby
Wzorzec `([\d,\.]+)` dopasowywał także sam przecinek. `comment` jest polem
swobodnym, więc „zmiana, potem strzał" — zwyczajny wpis trenera — dawał
`float(".")` i `ValueError`, który kładł CAŁY import. Operator widział komunikat
o konwersji na liczbę, z którego nie wynikało nic o przecinku w komentarzu.

- Wzorzec `([\d,\.]*\d[\d,\.]*)` wymaga co najmniej jednej cyfry. Cyfra jest
  wymagana W ŚRODKU, nie na początku: zapis `.5` parsował się dotąd na 0.5
  i parsuje się dalej. `(\d[\d,\.]*)` zmieniłoby to po cichu na 5.0.
- Dopasowanie, którego `float()` nie przyjmie (np. „1,2,3"), daje `None` zamiast
  wyjątku i jest ZLICZANE: nowy `coverage.xg_unparsed` + ostrzeżenie
  `XG_NIECZYTELNE`. Liczone osobno od `xg_missing`, bo „strzał bez xG"
  i „xG było, ale zepsute" to dwie różne rzeczy dla analityka.
- Komentarz bez ani jednej cyfry nie jest liczony — to opis, nie zepsute xG.

Wyjście na eksporcie referencyjnym bez zmian: `xg_parsed` 29, `xg_sum` 4,4,
`xg_missing` 0, nowy `xg_unparsed` 0. Test złoty zielony przed i po.

## [0.9.0] — 2026-08-11
### `meta.json`: liczby wystąpień nierozpoznanych tagów i etykiety towarzyszące
Kreator mapowań pokazywał „—" zamiast liczby wystąpień, bo `inspect` zwracał same nazwy —
`canon.build()` liczby zbierał i gubił je przy emisji (`sorted(unmapped_tags)` zwraca
same klucze). Operator decydował w ciemno: „tag wystąpił 2 razy" i „tag wystąpił 140 razy"
to zupełnie inne decyzje.

- `meta.unmapped_tags`: `[{ "tag", "count", "sample_labels" }]` — próbka etykiet
  towarzyszących w kolejności pierwszego wystąpienia, najwyżej 8 pozycji.
- `meta.unmapped_labels`: `[{ "label", "count" }]`.
- `meta.coverage.unanalysed` — liczba zdarzeń poza analizą (`concept: null`), żeby raport
  pokrycia mówił „7 z 120 zdarzeń nie wchodzi do metryk" zamiast samej listy tagów.

**Naprawa przy okazji, wykryta przelotem HTTP** (`app/tests/integracja/test_mapowania_http.php`):
etykieta z regułą `qualifier: null` („nie analizuj" z kreatora) wracała w `unmapped_labels`
przy każdym renderze, jakby decyzji nie było — `label_rules.get(label)` nie odróżniał jawnego
`null` od braku reguły. Po naprawie działa jak tag z `concept: null`: etykieta jest znana,
zostaje w `source_labels` zdarzenia, nie wchodzi do metryk. Słownik domyślny nie ma reguł
`null`, więc zmiana dotyka wyłącznie profili klubów — wyjście testu złotego bez zmian.

**Moduł M3 — model xG** (`engine/coachanalyze/xg.py`, wyłącznie `math`): regresja
logistyczna ze współczynnikami referencyjnymi z literatury (distance −0,3135,
angle +0,0910, bodypart_head −1,2946, start_x −0,1290; wyrazy wolne dokalibrowane
do skuteczności ~10,8%). Osobne modele: gra otwarta nogą / główką, wolny bezpośredni,
karny = stała 0,76. OPT-IN przez `config.options.xg_model`: uzupełnia WYŁĄCZNIE
strzały bez xG od analityka (`xg_source: "model"`, ostrzeżenie `XG_MODEL`);
wartości ręczne nigdy nie są nadpisywane. **Domyślnie wyłączone — bez flagi ani
jedna liczba się nie zmienia (bramka odbioru, test złoty).** Nowa komenda
`xg-grid` generuje siatkę wartości dla warstwy PHP (interaktywne boisko bez JS).
Zastrzeżenie o kalibracji: nagłówek `xg.py`, `docs/MODEL_KANONICZNY.md`, hasło M1.

**Moduł M1 — odsyłacze do indeksu współczynników w renderze.** `config.options.index_base`
+ `index_links` włączają doklejany przed `</body>` blok odsyłaczy do słownika metodycznego
(`render.index_block`). OPT-IN: bez tych pól wyjście jest bajt w bajt niezmienione —
złoty test odtworzenia raportu produkcyjnego przechodzi bez aktualizacji wzorca.
Kontrakt: `docs/KONTRAKT_CLI.md`, sekcja „Odsyłacze do indeksu współczynników".

`canon.build()["report"]` zachowuje `unmapped_tags`/`unmapped_labels` jako same nazwy
(konsumują je teksty ostrzeżeń i `metrics`); kształt z liczbami idzie osobno
(`*_detail`). **Render i metryki bez zmian — test złoty nietknięty.** Warstwa PHP czyta
oba kształty (`Mappings::unknown()`), więc artefakty sprzed 0.9.0 pozostają czytelne.
Kontrakt: `docs/KONTRAKT_CLI.md`, sekcja „Dane dla kreatora".

## [0.8.1] — 2026-08-11
### Pakowanie silnika — wdrożenie padało na `pip install -e`
Automatyczne wykrywanie pakietów setuptools widziało w płaskim układzie `engine/` dwa pakiety
najwyższego poziomu — `coachanalyze` i `templates` — i odmawiało budowy („Multiple top-level
packages discovered"). Awaria wychodziła na serwerze, w kroku `deploy.sh`, po którym test złoty
dopiero się uruchamia; CI tego nie łapało, bo instaluje z tego samego pliku, ale problem pojawił
się dopiero po dodaniu katalogu szablonów w 0.8.0.

**Szablony przeniesione do wnętrza pakietu**: `engine/coachanalyze/templates/`. To nie jest
kosmetyka — szablon obok pakietu nie instaluje się nigdzie, więc `render` znajdowałby go tylko
przy uruchomieniu z katalogu repozytorium. Silnik startuje z katalogu zadania, nie z repo.

`pyproject.toml`:
- `[build-system]` zadeklarowany wprost, zamiast polegania na domyślnym zachowaniu pip.
- `[tool.setuptools] packages` — jawna lista trzech pakietów zamiast wykrywania.
- `[tool.setuptools.package-data]` — `templates/*.html`. Wzorzec **nierekurencyjny**:
  `templates/ARCHIWUM/` zostaje w repozytorium jako materiał historyczny, ale nie jedzie
  w każdej instalacji.

`render.default_template_path()` liczy ścieżkę względem katalogu pakietu, nie katalogu wyżej.

`tests/test_pakowanie.py` — bramka na tę klasę błędów. Testy uruchamiane z katalogu repozytorium
jej nie widzą, bo `conftest.py` dokłada `engine/` do `sys.path` i wszystko się importuje niezależnie
od tego, co mówi `pyproject.toml`. Sprawdzamy: lista pakietów zgadza się z drzewem katalogów
(brak wpisu nie wywala budowy — wycina moduł z instalacji i objawia się `ModuleNotFoundError`
przy pierwszym raporcie), wersja w `pyproject.toml` zgadza się z `__version__`, szablon leży
w pakiecie i faktycznie pasuje do wzorca `package-data`, a archiwum nie.

Zweryfikowane na Pythonie 3.13, poza katalogiem repozytorium:
- `pip install -e engine` — przechodzi, `python -m coachanalyze --version` zwraca `0.8.1`.
- `pip install engine` (nieedytowalna) — w `site-packages` leżą trzy pakiety i szablon,
  bez `ARCHIWUM/`. Pełny `build` z tej instalacji daje raport **identyczny co do bajtu**
  z tym z repozytorium: 933 linie, różnica wyłącznie w linii `const DATA` (dwa zdarzenia
  z przycięcia ujemnego `begin`).

Wyjście silnika bez zmian — podbicie wersji, bo zmienia się sposób instalacji i miejsce,
z którego czytany jest szablon, a `engine_version` trafia do każdego raportu.

## [0.8.0] — 2026-08-11
### Szablon przestał znać klub — generacja v23-noname, ZMIANA WYJŚCIA
Poprzedni szablon (v17) miał wpisane na sztywno „Hutnik Kraków", „Pogoń-Sokół Lubaczów",
oba herby i barwy nazwane od klubów. Raport dla drugiego klubu byłby podpisany nazwą pierwszego.

Nowy `engine/templates/dashboard_template.html` powstał z `livetag_dashboard_noname_1.html` —
generacji v23, już zanonimizowanej. Stary szablon leży w `engine/templates/ARCHIWUM/v17.html`.

Co przyszło razem z generacją v23: wielokrotny wybór przedziałów 15-minutowych zamiast pojedynczego
fragmentu, belka nawigacyjna z podsumowaniem aktywnych filtrów, powierzchnia znacznika strzału
proporcjonalna do xG, tooltipy działające na dotyk, kotwice sekcji. Zniknęła stopka i karta
„Wyprowadzenie: skuteczne / nie".

Konwersja szablonu robiona skryptem `engine/tools/szablon_z_raportu.py`, z **asercją liczby
wystąpień przy każdej podmianie** — 13 podmian, każda z oczekiwaną liczbą trafień. Po incydencie
v13 (skrypt trafił w komentarz `/* timeline */` w CSS i zniszczył szablon) podmiana, która
trafiła w inną liczbę miejsc, niż zakładano, przerywa konwersję.

Skrypt zostaje w repozytorium jako recepta na następną generację: raporty powstają ręcznie
w kolejnych wersjach, a szablon ma z nich powstawać powtarzalnie, nie przez ręczne szukanie
i zamienianie. Liczby wystąpień są w nim parametrem — przy nowej generacji najpierw uruchom,
zobacz, co zgłosi, sprawdź każdą zmianę w źródle i dopiero wtedy popraw oczekiwania.
Skrypt odmawia zapisu, gdy w wyniku zostanie ślad konkretnego klubu.

Nowe znaczniki, wypełniane z `config.teams`: `__TEAM_{HOME,AWAY}__` (klucz dopasowania),
`__TEAM_*_LABEL__` (nazwa wyświetlana), `__TEAM_*_SHORT__` (etykieta toru), `__TEAM_*_COLOR__`
i `__TEAM_*_DIM__` (barwy), `__LOGO_{HOME,AWAY}__` (pełny adres `data:` herbu — **typ MIME idzie
za rozszerzeniem pliku**, wpisany na sztywno wyświetlałby PNG jako SVG). Zmienne CSS `--hut`/`--pog`
nazywają się teraz `--team-home`/`--team-away`.

**`team` w zdarzeniu dostaje nazwę z konfiguracji**, wybraną po `team_side` z modelu kanonicznego,
zamiast surowego napisu z eksportu. Szablon porównuje `e.team` z nazwą klubu przez równość — klub,
który w kolejnym eksporcie zapisze nazwę inaczej (inna wielkość liter, literówka, zmiana nazwy
w LiveTag), dostawał raport z zerem zdarzeń dla własnej drużyny i bez ostrzeżenia. Teraz
dopasowaniem zajmuje się model kanoniczny. Nazwa nierozpoznana **zostaje bez zmian**: skasowanie
jej przeniosłoby zdarzenie do sekcji „bez przypisania drużyny" i zmieniło liczby.

`config.teams.*.source_names` (nowe, opcjonalne) — nazwy tak, jak zapisał je LiveTag. Rozdzielenie
nazwy wyświetlanej od nazwy w eksporcie jest po to, żeby zmiana zapisu w LiveTag nie wymuszała
zmiany nazwy klubu w aplikacji. `docs/KONTRAKT_CLI.md` zaktualizowany w tym samym commicie.

Braki są głośne, ale nie wywracają renderu — jest ostatnim krokiem i wywrócenie się w nim kasuje
całe przetworzenie. Brak nazwy → `Drużyna A`/`Drużyna B`, brak herbu → biały krążek z pierwszą
literą nazwy; jedno i drugie z ostrzeżeniem na stderr (`teams_defaulted`, `crests_generated`).

### Zgodność z raportem produkcyjnym
Render na eksporcie referencyjnym z konfiguracją odtwarzającą wzorzec daje plik **różniący się od
`livetag_dashboard_noname_1.html` w jednej linii — linii `const DATA`** — i w dwóch zdarzeniach
z 294 (`b`: −3,2 → 0,0 oraz −0,4 → 0,0, CHANGELOG 0.4.0). `PAL` identyczne bajtowo, `half_split`
bez zmian, pozostałe 932 linie identyczne.

Porównanie cofa jedną zamierzoną zmianę strukturalną — nazwy zmiennych CSS. Mapowanie leży
w manifeście (`noname_css_alias`), żeby nie dało się go po cichu rozszerzyć o kolejne „drobiazgi".

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
