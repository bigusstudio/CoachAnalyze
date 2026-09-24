# CHANGELOG

Format: [wersja silnika] — data — opis.
Każda zmiana, która modyfikuje wyjście silnika, MUSI mieć tu wpis wraz z powodem.

## [0.14.0] — 2026-09-24 · sesja 4a pivotu „viewer"
### Lewy slot należy do klubu-tenanta, kierunek ataku wychodzi z danych

**ZMIANA WYJŚCIA — WYŁĄCZNIE W GENERACJI v21.** Szablon v17 nie ma ani jednego
nowego znacznika, a domyślna ścieżka (bez templatu, bez `match.tenant_club_id`)
produkuje plik co do bajtu taki jak dotąd. Test złoty nietknięty.

- **`HOME` = klub-tenant, `AWAY` = rywal — zawsze** (decyzja właściciela,
  docs/STAN_PIVOTU.md §7.7 a). Zastępuje konwencję „HOME = drużyna atakująca
  w lewo". Powód jest po stronie odbiorcy: sztab ogląda serię raportów przez
  sezon i ma widzieć swoją drużynę zawsze w tym samym miejscu. Nazwy slotów
  zostają, bo ich zmiana dotknęłaby obu szablonów i wzorca złotego.
  Przy scoutingu (tenant po stronie `them`) sloty odwraca `render.tenant_side`,
  czytając `match.tenant_club_id` i nowe `teams.*.club_id`.
- **Nowy moduł `direction.py`** — kierunek ataku każdej drużyny wyprowadzony
  z danych, nigdy z konfiguracji. Rozstrzyga mediana `pos_x_meters` strzałów
  (`> 52,5` → atak w prawo); wejścia w SBZ i zwrot podań w III strefę są
  kontrolne i nie przegłosowują strzałów. Liczone RAZ NA MECZ: współrzędne
  w eksporcie są już znormalizowane kierunkowo (pułapka 2), więc drużyny nie
  zmieniają w nich stron po przerwie — zweryfikowane na eksporcie JDRZ.
  Próbka mniejsza niż trzy zdarzenia nie rozstrzyga niczego.
- **Odbicie współrzędnych, gdy tenant atakuje w lewo:** `x' = 105 - x`
  (także `tx`), `y` nietknięte — zamieniamy strony boiska, nie skrzydła.
  Odbijamy OBIE drużyny razem; lustrzenie jednej rozjechałoby mecz na dwa
  układy współrzędnych. To jedyne miejsce w silniku, w którym wolno tknąć
  współrzędne, i jako jedyne jest wyprowadzone z danych oraz odnotowane.
- **Model kanoniczny, metryki i `--out-canon` dostają ramkę ORYGINALNĄ.**
  Archiwum zapisuje to, co było w eksporcie. Odbicie jest decyzją prezentacji.
  **`--out-events` zapisuje współrzędne PO odbiciu** — tabela ma jeden układ
  (tenant atakuje w prawo), żeby porównanie sezonowe nie sumowało map z dwóch
  przeciwnych stron boiska. Rozjazd tabeli z archiwum jest świadomy i opisany
  w docs/KONTRAKT_CLI.md.
- **`meta.direction` i `meta.mirrored`** — kierunek sprzed odbicia wraz
  z dowodami (mediany i liczebności trzech miar) plus flaga odbicia. Razem
  odpowiadają na pytanie „czy ta mapa jest odbita" bez liczenia median od nowa.
- **Ostrzeżenie `KIERUNEK_NIEPEWNY`** przy sprzeczności miar albo kierunku
  z miary kontrolnej. **Brak kierunku ostrzeżeniem NIE JEST:** eksport bez
  pozycji to stan normalny (pułapka 3), a ostrzeżenie o stanie normalnym uczy
  ignorować ostrzeżenia.
- **Nagłówek v21 podpisuje kierunek z danych**, a nie stałą konwencją.
  Nowe znaczniki `__KIERUNEK_HOME__`, `__KIERUNEK_AWAY__`, `__KIERUNEK_OPIS__`
  (grupa opcjonalna `kierunek`). Podpis mówi, CO WIDAĆ NA MAPIE — czyli kierunek
  po odbiciu — bo czytelnik patrzy na mapę, a nie na surowy eksport.
  Kierunek nieznany daje PUSTY napis, nie „nieznany" ani konwencję zastępczą.

## [0.13.2] — 2026-09-24
### Minuta meczu zaczyna się od 1
Poprawka z odbioru sesji 2. `ceil(0/60)` dawało zero, a zdarzeń w sekundzie 0
jest w eksportach sporo: pułapka 10 przycina ujemny `begin` (bufor taga) właśnie
do zera, więc każdy tag wstawiony przed pierwszym gwizdkiem lądował w „0. minucie".
Takiej minuty nie ma ani w meczu, ani na osi czasu raportu. Odtąd
`max(1, ceil(t/60))`; przycięcie pierwszej połowy do 45 bez zmian.

## Aplikacja — 2026-09-24 · sesja 3 pivotu „viewer"
### Metryki na tabeli `events`, katalog tagów, endpoint JSON
- **Migracja `015`** (addytywna): `tag_catalog` — co klub W OGÓLE taguje.
  Zapełniana przy imporcie i przeliczeniu z `meta.dictionary` i `meta.palette`,
  czyli z danych, które silnik liczy od 0.10.0 i 0.11.0. **Kasowania nie ma:**
  tag, który zniknął z eksportów trzy miesiące temu, zostaje — to znaczy,
  że klub zmienił metodykę, i właśnie to ma być widoczne.
- **`Metrics::compute(definicja, zakres)`** — jeden interfejs. Definicja to
  FILTR (tagi z aliasami, etykiety `ma`/`nie ma`, strona, połowa, zakres minut)
  plus AGREGATOR (`count`, `sum_xg`, `ratio`, `avg_per_match`). Zakres to mecz
  albo lista meczów; **SUMA sezonu to ta sama definicja bez filtra meczu**,
  nie osobna metryka — inaczej zestawienie mogłoby się nie zgadzać z sumą kolejek.
- **Wskaźnik z zerowym mianownikiem daje `null`, NIGDY zero.** „0% wejść w SBZ
  zakończonych strzałem" i „nie było wejść w SBZ" to dwa różne zdania o meczu.
  Zerowy licznik przy niezerowym mianowniku to co innego — **to jest wynik** i daje 0.
- **Brak taga w katalogu klubu daje `null` i wpis w `catalog_coverage`**, nie zero.
  To jest cały powód, dla którego `tag_catalog` powstał.
- **Zdarzenia bez drużyny (`none`) liczą się dla tenanta** (pułapka 5, reguła
  z `docs/STAN_PIVOTU.md`): analityk klubu taguje własne straty i odbiory, nie cudze.
  Tam, gdzie zdarzenie MA drużynę (strzały, SBZ), `none` nie wchodzi — inaczej
  doliczylibyśmy klubowi strzały rywala bez przypisania.
- **`GET /api/metryki?club=&season=&match=`** → `{scope, metrics[], catalog_coverage[]}`.
  Puste `match` znaczy SUMA sezonu. Brak sesji daje **401**, nie 404 jak przy
  chmurkach: tamte odpytuje skrypt w pętli na każdej stronie, tę trasę woła się
  świadomie — a 302 dałoby klientowi stronę logowania jako „odpowiedź JSON".
  Cudzy klub: 403.
- **Pulpit**: trzy kafle („SBZ na mecz", pressing, reakcja na stratę) i kolumna
  SBZ w tabeli liczone z `Metrics`. Kreska zostaje WYŁĄCZNIE wtedy, gdy wartość
  jest `null`.

**Czy to łamie CLAUDE.md §4?** Nie. §4 zabrania PHP rozstrzygania, CZYM jest
zdarzenie — czy strzał był golem, skąd xG, która drużyna jest „nasza". To
policzył silnik i zapisał w wierszach (migracja 014). `Metrics` filtruje gotowe
wiersze i sumuje gotowe kolumny, w SQL-u. Agregacja idzie w bazie, nie w pętli
PHP, właśnie dlatego, że pętla byłaby zaproszeniem do dopisania w niej warunku —
a warunek w pętli to już reguła piłkarska.

**Definicje metryk leżą w `app/config/metryki_domyslne.php` i to jest plik
PRZEJŚCIOWY.** W sesji 5 przechodzą do templatu klubu, bo metodyka jest częścią
templatu, nie kodu.

### Filtr statusu na liście meczów
Zgłoszony przy odbiorze sesji 3,5. `Matches::search()` umiał go od dawna —
brakowało wyłącznie pola na ekranie i przepuszczenia parametru w trasie.
Lista dozwolonych wartości to `Matches::STATUSY`, jedno źródło prawdy.

## Aplikacja — 2026-09-24 · sesja 3,5 pivotu „viewer"
### Lifting panelu wg `docs/podglad_pulpit_v1.html`
**Wpis APLIKACJI.** Silnik zmienia się wyłącznie w ucieczce nazw klubów (niżej);
wyjście renderu dla nazw bez znaków specjalnych jest bit w bit takie samo,
test złoty nietknięty, wersja silnika NIE podbijana.

- **Arkusz v2 na tokenach wspólnych z szablonem v21.** Panel i raport pokazywały
  ten sam mecz w dwóch paletach — panel pomarańczowy na chłodnej szarości, raport
  zielony na ciemnej zieleni. Ten sam klub wyglądał na dwa produkty. Nazwy polskie
  (`--tlo`, `--akcent`) ZOSTAJĄ jako warstwa zgodności dla 196 selektorów w 41
  widokach; nazwy z v21 (`--bg`, `--acc`) stoją obok i wskazują na te same barwy.
- **Fonty self-hosted**, bez jednego odwołania do `fonts.googleapis.com`:
  Barlow Condensed 500–800, IBM Plex Sans 400–600, IBM Plex Mono 400–500,
  woff2, podzbiory `latin` + `latin-ext` (polskie znaki), `font-display: swap`.
  Razem 364 kB w 18 plikach. Panel działa w sieci klubowej, która bywa
  filtrowana — dwa źródła krojów znaczyłyby, że wygląda inaczej, gdy CDN milczy.
- **Ciemna szyna boczna, ZAWSZE ciemna**, także w motywie jasnym: kontekst klubu
  i sezonu u góry, grupy PULPIT · KLUB · NARZĘDZIA · ADMINISTRACJA, liczniki
  przy pozycjach z istniejących danych, wersja silnika i stan kolejki w stopce.
  Pozycja bez czego liczyć NIE DOSTAJE licznika — zero obok nazwy wygląda jak
  awaria danych, a nie jak „nic nie czeka".
- **Górny pasek**: okruszki, wyszukiwarka prowadząca do istniejącej listy meczów
  z filtrem `q`, powiadomienia z kropką, motyw, awatar z inicjałami.
- **Mobile poniżej 900 px**: szyna jako wysuwany panel na `:target` (bez skryptu),
  górny pasek w dwóch wierszach, tabele przewijane we własnym kontenerze.
  Sprawdzone na 390 px: ZERO poziomego przewijania na czterech ekranach.

### Pulpit i lista meczów na danych z tabeli `events`
- Karta „Ostatni mecz": wynik z `SUM(is_goal)` po `team_side`, xG z `SUM(xg)`.
  **PHP nic tu nie liczy** (CLAUDE.md §4) — `is_goal` i `xg` policzył silnik
  i zapisał w wierszach (migracja 014); tutaj jest `SUM()` po gotowej kolumnie.
- **Mecz bez zdarzeń pokazuje KRESKI, nie zera.** Mecz sprzed migracji 014 albo
  sprzed przeliczenia raportu nie ma jeszcze wierszy, a „0:0" jest wynikiem.
- Sześć kafli: trzy wypełnione danymi, które są dziś; **trzy z kreską i podpisem
  „od sesji 3"** — SBZ na mecz, pressing, reakcja na stratę wymagają metryk,
  których warstwa żądań nie ma. Wpisanie tam czegokolwiek byłoby wymyśloną
  liczbą pokazaną zarządowi klubu (CLAUDE.md §8, D5).
- Lista meczów: kolumny Import / Raport / Link jako pastylki. Filtry i
  stronicowanie bez zmian.

### Dług 7.5 spłacony: nazwy klubów z ucieczką, w DWÓCH kontekstach
Nazwa klubu pochodzi z bazy, czyli od użytkownika, a raport wisi pod publicznym
adresem (CLAUDE.md §5). Ucieczka nie może być jedna:

| Znacznik | Kontekst | Ucieczka |
|---|---|---|
| `__TEAM_*_LABEL__`, `__TEAM_*_SHORT__` | treść HTML i literał szablonowy | HTML |
| `__TEAM_*__` | **literał JS porównywany ze zdarzeniami** (`e.team === HUT`) | JS, **nigdy HTML** |

Ucieczka HTML w drugim przypadku zamieniłaby `&` na `&amp;` i klub „Test & Spółka"
dostałby raport z ZEREM własnych zdarzeń — po obu stronach porównania stałyby
różne napisy, bez żadnego ostrzeżenia. `view_data()` przepuszcza nazwę przez tę
samą funkcję, więc obie strony porównania są z definicji identyczne.
Test na nazwie `<Test & Spółka's>`.

### Poprawione przy okazji
- `Stats::seasonMatches()` powtarzał symbol nazwany `:tag` — PDO bez emulacji
  tego nie przyjmuje. Złapał to `test_sql_parametry.php`.
- Zmienne murawy kalkulatora xG wypadły z bloku `prefers-color-scheme` przy
  przepisywaniu tokenów. Złapał to `test_xg_boisko.php`.
- `app/tests/podglad_router.php`: router wbudowanego serwera do podglądu.
  Bez niego `php -S … index.php` kieruje do routera także `/assets/app.css`,
  a strona renderuje się BEZ STYLÓW i wygląda na zepsutą. Kosztowało to jedną
  turę zrzutów ekranu — wszystkie cztery wyszły identyczne, bo były tą samą
  stroną logowania bez arkusza.

### Rozszerzenie odstępstwa na skrypty — ZATWIERDZONE
`layout.php` niesie **jeden skrypt inline w `<head>`**, ustawiający motyw przed
pierwszym malowaniem. Nie da się tego zrobić plikiem zewnętrznym: ten wczytuje
się PO pierwszym malowaniu, czyli za późno, a skutkiem jest mignięcie jasnym
tłem przy każdym wejściu na każdą podstronę. **Liczba PLIKÓW skryptu nadal
wynosi jeden.** Granice pilnuje `test_chmurki.php`: jeden inline, wyłącznie
w `<head>`, wyłącznie o motywie, bez ani jednego polskiego zdania, poniżej
400 znaków.

## [0.13.1] — 2026-09-24
### Dostępność sekcji i xG po surowych tagach templatu
**Naprawa. Odbiór sesji 1 na serwerze tego nie przeszedł.**

Templat, w którym `STRZAŁ` i `ZDOBYCIE SBZ` mają `canon: null` — stan NORMALNY
od sesji 1 (docs/STAN_PIVOTU.md §2.3) — dawał raport **bez map i bez osi SBZ**,
z powodami „Brak zdarzeń ze współrzędnymi" i „Eksport nie zawiera zdarzeń zdobycia
SBZ", a `meta.coverage.xg_sum` wynosiło **0** przy ostrzeżeniu `XG_POZA_STRZALEM`
na 29 własnych strzałach klubu. Szablon v21 liczył przy tym poprawnie
(12:17, xG 1,49:2,91) — `drop_sections` wycinało gotowy, policzony DOM.

Przyczyna: `coverage.build_sections` liczyło dostępność z POJĘĆ KANONICZNYCH
(`coverage["shots"]`, `coverage["sbz"]`), a `canon.py` czytał xG wyłącznie przy
`concept == "shot"`. **Uśpiona warstwa dalej egzekwowała wycofaną regułę.**

- **Dostępność sekcji z SUROWYCH TAGÓW, gdy jest templat.** `mapy` są dostępne,
  gdy którakolwiek zmienna z tą sekcją ma ≥1 zdarzenie ze współrzędnymi;
  `tl_sbz` i `tl_iii` — gdy ma ≥1 zdarzenie, przy czym `tl_iii` zachowuje
  rozróżnienie z pułapki 3 (brak zdarzeń ≠ zdarzenia bez pozycji).
  **Bez templatu ścieżka nietknięta** — test złoty bajt w bajt.
- **xG dla zmiennej templatu bez pojęcia.** Obroną przed „3 zawodników w polu
  karnym" jest teraz KSZTAŁT LICZBY, a nie pojęcie: wartość musi mieć część
  ułamkową i mieścić się w (0,1] — tak samo liczy szablon v21. `XG_POZA_STRZALEM`
  zostaje dla przypadków, w których pojęcie JEST i nie jest strzałem.
- **`tags_without_concept()`** odróżnia zmienną templatu bez pojęcia od
  `NIE_ANALIZUJ` z kreatora. W profilu mapowań obie dają `concept: None`
  i po kształcie reguły są nie do rozróżnienia — a to dwie różne decyzje
  człowieka: „pokaż, nie umiem nazwać" kontra „to mnie nie interesuje".
- **`coverage.xg_parsed` liczy wszystkie zdarzenia z xG**, nie tylko strzały —
  tak samo jak `xg_sum`. Dwie podstawy dawałyby „xg_parsed 0" obok „xg_sum 4,40"
  w jednym raporcie pokrycia. Bez templatu obie liczby są jak dotąd.

### Sekcja pojedynków tą samą miarą
Dopisane osobnym commitem. `duels` liczyło się dalej z pojęcia `duel`, więc przy
templacie bez pojęć znikało dokładnie tak, jak znikały mapy — ta sama usterka
zostawiona poza pierwotnym zakresem. Sekcja jest dostępna, gdy którakolwiek
zmienna z sekcją `duels` ma ≥1 zdarzenie. Sprawdzenie po pojęciu zostaje na
ścieżce BEZ templatu.

`noteam` zostaje wspólne dla obu ścieżek: liczy się z surowego pola `team`,
a nie z pojęcia, więc templat nie ma tam czego zmieniać.

Na eksporcie referencyjnym z templatem bez pojęć: `xg_sum` **4,40**, brak
`XG_POZA_STRZALEM` i **komplet siedmiu sekcji**.

## [0.13.0] — 2026-09-24
### `--out-events`: zdarzenia meczu po SUROWYCH nazwach tagów
Sesja 2 pivotu. Nowy, OPCJONALNY parametr komendy `build`. Bez niego pipeline
zachowuje się dokładnie jak w 0.12.0 — test złoty nietknięty.

**To NIE jest `--out-canon` w innym opakowaniu.** `--out-canon` tłumaczy tagi na
pojęcia (`shot`, `entry_sbz`) dla archiwum; ta warstwa jest uśpiona
(docs/STAN_PIVOTU.md §2.1). `--out-events` zapisuje to, co JEST W EKSPORCIE, pod
nazwą wpisaną przez analityka — tak samo, jak liczy szablon raportu w przeglądarce
(`e.tag==='STRZAŁ'`). Dzięki temu tabela i raport odpowiadają na pytania tą samą
miarą. Mapowanie kanoniczne w tej warstwie jest **zakazane**: dołożone, rozjechałoby
tabelę z raportem tak, że obie liczby wyglądałyby sensownie.

Wejściem jest **ramka renderu** (D4), nie surowy CSV — ten sam obiekt, z którego
powstaje HTML, daje wiersze do bazy.

### Trzy reguły
- **Minuta**: `ceil(t/60)`, **pierwsza połowa przycięta do 45**. Czas w eksporcie to
  czas wideo (pułapka 8), więc doliczony czas rośnie dalej, a przerwa nie zeruje
  licznika; bez przycięcia zdarzenie sprzed przerwy trafiałoby do 47. minuty,
  a tuż po przerwie do 49. — i oś czasu pokazywałaby przerwę jako dwie minuty gry.
  Druga połowa **bez** przycięcia: górnej granicy meczu nie znamy.
- **Gol**: tag `Gol` przypisany do NAJBLIŻSZEGO W CZASIE `STRZAŁ` (okno 30 s),
  drużyna gola brana ze strzału — `team_uuid` przy golu w eksporcie bywa błędny.
  Strzał dostaje `is_goal: 1`, a **wiersz `Gol` zostaje** ze skorygowaną drużyną:
  sklejenie ich zmieniłoby sumę zdarzeń meczu. Ta sama reguła co w szablonie v21,
  bo rozjazd tutaj to rozjazd na WYNIKU MECZU.
- **Strona**: `us`/`them` wg `config.teams` (to samo dopasowanie, co w renderze),
  wiersz bez drużyny to `none`. Perspektywę klubu-tenanta interpretuje SZABLON,
  nie silnik — tutaj zapisujemy stronę meczu, nie punkt widzenia.

### Meta meczu: `config.match.tenant_club_id`
Blok `match` z 0.12.0 przyjmuje dodatkowe pole — id klubu-tenanta. Render go
jeszcze NIE UŻYWA; wchodzi teraz, bo od niego będzie zależeć, która drużyna zajmuje
lewą stronę raportu (docs/STAN_PIVOTU.md §7.7d), a pole ma być na miejscu, zanim
zacznie być potrzebne.

### Zdarzenie bez czasu
Pomijane i policzone w `skipped_no_time`, plus ostrzeżenie na stderr. Kolumna `t_ms`
jest `NOT NULL`, a zera nie podstawiamy: zero to konkretna 0. sekunda i nie da się
jej odróżnić od braku danych (CLAUDE.md §8). W eksportach referencyjnych takich
wierszy nie ma — gdyby się pojawiły, licznik powie o tym głośno.

## Aplikacja — 2026-09-24 · sesja 2 pivotu „viewer"
### Tabela `events`, migracja 014 i meta meczu z bazy
- **Migracja `014`** (addytywna): `CREATE TABLE events` + `ALTER TABLE matches ADD
  COLUMN round`. `events_canonical` **nietknięta** — dwie tabele obok siebie są tańsze
  niż jedna kolumna wypełniana raz tak, raz inaczej. `ON DELETE CASCADE` przy
  `events.match_id` jest jedynym kaskadowym kasowaniem w schemacie i jest świadome:
  zdarzenia są odtwarzalne z surowego eksportu.
- **`Events::replaceForMatch()`** — `DELETE` + `INSERT` wsadowy (paczki po 500)
  w JEDNEJ transakcji. Kasujemy całość, nie scalamy: eksport LiveTag nie niesie
  identyfikatora zdarzenia, więc scalanie wymagałoby wymyślonego klucza.
- **Awaria zapisu zdarzeń NIE unieważnia raportu.** Brak pliku, niepoprawny JSON
  albo błąd bazy to wpis w logu. Zdarzenia są odtwarzalne przeliczeniem, raport —
  który operator już rozesłał — nie. Wywrócenie zadania zabrałoby rzecz
  nieodwracalną, żeby uratować odwracalną.
- **`run_job.php` wypełnia `config.match` z bazy**: `date` z `matches.played_at`,
  `season` z `seasons.label`, `round` z nowej kolumny, `tenant_club_id` z `matches.club_id`.
  Do tej sesji `season_label` było wpisane na sztywno jako `null` i nagłówek v21
  zostawał pusty także tam, gdzie dane w bazie są. Brak wartości daje PUSTY NAPIS,
  nie `null` — szablon składa nagłówek z członów niepustych.

### Poprawione przy okazji
Trzy asercje w dwóch zestawach pinowały NUMER migracji („najwyższa migracja to 013",
„nie dołożono migracji 014") i zapaliły się na czerwono, gdy migracja 014 powstała
z powodu niemającego z nimi nic wspólnego. Mierzyły cudzą pracę zamiast własnej
intencji; sprawdzają teraz to, co miały znaczyć — że sezon i hasła indeksu nie
wymagały własnej migracji.

## Aplikacja — 2026-09-24 · sesja 1 pivotu „viewer"
### Pojęcie kanoniczne opcjonalne — zmienna liczy się po nazwie
**Wpis APLIKACJI, nie silnika.** `engine/` zmienia wyłącznie jeden docstring
(`report_template.generic_variables`), wyjście silnika jest bit w bit takie samo,
test złoty nietknięty — dlatego wersja silnika NIE jest podbijana.

Wycofana zasada z Sesji 4 przebudowy (pkt 2): „zmienna bez bindingu kanonicznego
dostaje wyłącznie licznik w bilansie i pas na osi czasu". Powód w
`docs/STAN_PIVOTU.md` §2.3 — raport liczy po **surowej nazwie tagu**
(`e.tag==='STRZAŁ'` w szablonie), więc pojęcie kanoniczne nigdy nie było warunkiem
narysowania czegokolwiek. Blokada broniła dostępu do sekcji, których nic już nie
broniło, a jej jedynym skutkiem było kazanie operatorowi wypełnić pole, zanim
zobaczy pierwszy raport.

- **Zniknął błąd `conf.err.canon_required`** i jego klucz w `pl.php`.
  `Configurator::bledyConfigu()` nie sprawdza już sekcji pod kątem pojęcia.
- **`Configurator::SEKCJE_GENERYCZNE` zostaje, ale przestaje ograniczać** — jest
  odtąd zestawem domyślnym nowej zmiennej (bilans + oś czasu), czyli propozycją
  startową. Nowa zmienna nadal zaczyna od dwóch sekcji: propozycja przesądzająca
  o kształcie raportu bez niczyjej decyzji nie byłaby propozycją.
- **`TemplateDiff::nowyConfig()` przestało odcinać sekcje** przy `canon === null`.
  To był cichy ubytek: ekran diffu pozwalał zaznaczyć sekcję, a zapis ją wyrzucał
  — bez śladu poza gotowym raportem.
- **Widoki**: checkbox sekcji jest blokowany WYŁĄCZNIE wtedy, gdy sekcja jest
  wyłączona w templacie. Podpowiedź o blokadzie usunięta; klasa
  `zmienna--generyczna` zostaje jako styl, bez znaczenia zakazu.
- **Select pojęcia zszedł pod „Zaawansowane"** (`<details>`, domyślnie zwinięte)
  w konfiguratorze i na ekranie diffu. `<details>` to element HTML, nie skrypt —
  panel nadal działa bez JS (CLAUDE.md §9). Zapis w `index.php` bez zmian: `canon`
  dalej opcjonalny i walidowany przez `dozwoloneCanon()`.

**Czego to NIE zmienia:** walidacja pojęcia spoza słownika (`conf.err.unknown_canon`)
i sekcji spoza templatu (`conf.err.section_disabled`) działa jak dotąd — serwer
pozostaje jedyną kontrolą, której nie da się ominąć z konsoli.

**Świadoma cena:** zmienna może trafić do mapy, choć eksport nie niesie dla niej
pozycji. Mapa jest wtedy pusta, a powód stoi w `sections_unavailable` — ten sam
przypadek co III STREFA bez `pos_*` (pułapka 3). Brak danych ma być widoczny,
a nie uprzedzony zakazem; własny komunikat w kafelku mapy to `STAN_PIVOTU` §7.8.

## [0.12.0] — 2026-09-24
### Szablon raportu: druga generacja (`v21`) i przełącznik `--html-template`
Sesja 0 pivotu „viewer". Szablon przestaje być jeden.

- **`--html-template v17|v21|ŚCIEŻKA`** w komendzie `build`, OPCJONALNY. Bez niego
  obowiązuje `CA_HTML_TEMPLATE`, a bez niej **`v17`** — czyli szablon, którego wyjścia
  pilnuje test złoty. **Wyjście domyślnej ścieżki jest niezmienione co do bajtu**:
  ani szablon, ani wzorzec złoty, ani manifest nie zostały tknięte.
- **To NIE jest `--template`.** Tamten parametr niesie templat raportu KLUBU (zmienne,
  bindingi kanoniczne, sekcje z konfiguratora) i mówi, CO raport liczy. Nowy mówi,
  JAK wygląda. Nazwy stoją blisko siebie, więc w kontrakcie CLI są opisane obok siebie,
  a w kodzie obu miejsc stoi komentarz.
- **Nieznana nazwa generacji przerywa render kodem `4`** i wymienia dozwolone.
  Literówka (`v71`) nie może po cichu dać raportu w innym układzie niż zamawiany;
  rozróżnienie „nazwa czy ścieżka" idzie po kształcie napisu, nie po istnieniu pliku —
  inaczej komunikat brzmiałby „nie udało się wczytać szablonu: v71".
- **Generacja idzie na stderr przy każdym renderze** (`szablon HTML: v17 (…)`).
  Pytanie „dlaczego raport z marca wygląda inaczej" (CLAUDE.md §7) ma mieć odpowiedź
  w logu, a nie w zgadywaniu, co wtedy było w środowisku.
- **`v21` NIE MA wzorca złotego** i to jest stan świadomy: wzorzec wymaga porównania
  wizualnego sekcja po sekcji i zgody klienta (docs/PRZEBUDOWA_KLUB_SESJE.md, S5b).
  Do tego czasu nową generację pilnują niezmienniki: „HTML to szablon plus same
  podmiany" (dla obu generacji), brak resztek `__COŚ__` po renderze, obecność cech
  generacji i brak śladu jakiegokolwiek klubu w pliku.

### Nowe znaczniki szablonu — wypełniane tylko wtedy, gdy szablon je zawiera
`v17` nie ma ani jednego z nich i renderuje się bez zmian. `v21` ma komplet.

**GRUPA NIEKOMPLETNA NIE PRZERYWA RENDERU** — raport ma powstać zawsze. Grupa nieobecna
w całości to inna generacja szablonu i milczy (ostrzeżenie o stanie normalnym uczy
ignorować ostrzeżenia). Grupa obecna w CZĘŚCI to ślad po edycji, która zjadła znacznik:
brakujące są pomijane, a `meta.warnings` niesie nowy kod **`BRAKUJACY_ZNACZNIK`**
z listą w polu `placeholders`. `LEFTOVER_RE` dalej pilnuje, żeby w HTML-u nic nie zostało.

- **`__TEAM_*_COLOR_L__` / `__TEAM_*_DIM_L__`** — barwa drużyny w motywie jasnym.
  Liczona z barwy klubu jak `hex_to_dim`, tylko w drugą stronę: skalowanie kanałów do
  ustalonej jasności względnej (`LIGHT_TARGET_LUM`), tymi samymi współczynnikami co
  korekta palety w parserze. Barwa już ciemna wraca bez zmiany. Klub, dla którego
  reguła wypadnie źle, podaje `teams.*.color_light` w konfiguracji —
  docelowo ma to być pole klubu (TODO sesja 4).
- **`__SEZON__` / `__KOLEJKA__` / `__DATA_MECZU__`** — meta meczu z `config.match`
  (`season`, `round`, `date`); sezon schodzi zapasowo na istniejące `season_label`.
  Brak wartości daje PUSTE MIEJSCE, nie wymyśloną datę (CLAUDE.md §8). Wartości
  przechodzą przez ucieczkę: wpisuje je operator, a lądują i w treści HTML,
  i w literale szablonowym JS.

### `__DATA_MECZU__` zamiast `__DATA__` — kolizja usunięta u źródła
`__DATA__` było podnapisem `/*__DATA__*/`, czyli miejsca na zdarzenia meczu. Wspólna
nazwa wymuszała liczenie wystąpień z korektą i podmianę w ustalonej kolejności, a tag
zdarzenia o treści `__DATA__` mógł zostać zamieniony na datę meczu — raport pokazałby
wtedy inne liczby niż archiwum. Znacznik został przemianowany w szablonie v21
(nagłówek `hdr2` i stopka slajdów) i cały ten mechanizm zniknął z `render.py`.
Podstawienie jest znowu zwykłe. `/*__DATA__*/` bez zmian.

### Szablon v21: nagłówek składany z niepustych części
Sezon, kolejka i data bywają puste (kolejki nie ma dziś skąd wziąć — brak kolumny
w bazie). Separatory wpisane na sztywno dawały wtedy „sezon  · kolejka  · ", czyli
trzy kropki bez treści. Nagłówek i stopka slajdów składają się odtąd z członów
niepustych: brak danych zabiera CAŁY człon, a nie zostawia po sobie ozdobnika.
Zmiana dotyczy **wyłącznie szablonu v21**; v17 nietknięty.

## [0.11.0] — 2026-08-19
### Kontrakt CLI: `--template` — templat raportu klubu jako wejście pipeline'u
Sesja 5 przebudowy. Silnik przyjmuje config templatu zbudowany w konfiguratorze
(Sesje 3+4) i liczy według niego, zamiast wyłącznie według słownika domyślnego
i profilu kreatora.

- **`--template ŚCIEŻKA`** w komendzie `build`, OPCJONALNY. Bez niego cały
  pipeline zachowuje się dokładnie jak w 0.10.0 — co do bajtu w wyjściu renderu.
- `variables[].source → canon` tłumaczone na profil mapowań, który rozumie
  `canon.build()`. Templat wygrywa z profilem kreatora: jest nowszy i zatwierdzony
  przez człowieka. Zmienna `canon: null` daje regułę z jawnym `None`, a NIE brak
  wpisu — dzięki temu tag jest silnikowi ZNANY i nie ląduje w `unmapped_tags`.
  Różnica jest istotna: brak wpisu to usterka do naprawienia, jawne `null`
  to decyzja do uszanowania.
- `team_us_rule.markers` steruje przypisaniem „naszej" drużyny. Korekta literówki
  `MASZA`/`NASZA` dla kolumny `team` jest odtąd DANĄ TEMPLATU, nie regułą wpisaną
  w silnik — kolejny klub może mieć własną literówkę bez zmiany kodu. Nazwa klubu
  z konfiguracji wygrywa z markerem, bo jest konkretniejsza.
- **Coverage templat × eksport**: `sections_enabled` trafia do `build_sections`,
  które i tak dokłada powód do każdej sekcji bez danych. Sekcja włączona
  w templacie, ale niemożliwa dla tego eksportu, znika z HTML-a i zostaje
  z powodem w `sections_unavailable`. **Generowanie NIGDY nie pada z tego powodu.**
- `config.template_version` + `config.generated_at` → dyskretna stopka
  „templat vN · wygenerowano DATA". Bez wersji stopki nie ma.

### `meta.json`: paleta tablicy kodowej (`palette`)
Paleta z pliku projektu LiveTag (po korekcie jasności `to_hex`) była dotąd
wyłącznie WEJŚCIEM do ostrzeżeń i nigdzie nie wychodziła. Konfigurator nie miał
więc jak zaproponować barw zmiennych i schodził na barwy klubu. PHP nie może jej
policzyć sam: uruchomienie silnika z warstwy żądań blokuje `disable_functions`,
a przepisanie `to_hex` byłoby przeniesieniem arytmetyki koloru do PHP.
`null` przy imporcie bez pliku projektu — to poprawny stan, nie brak danych.

### Filtrowanie sekcji w wyjściu — MOSTEK PRZEJŚCIOWY do S5b
`render.drop_sections()` wycina blok `<section>` z GOTOWEGO HTML-a, bo szablon
raportu ma nazwy tagów i etykiety wpisane na sztywno w JS i nie da się nim
sterować konfiguracją. To rozwiązanie tymczasowe, opisane w kodzie i w spec
(sekcja S5b): docelowo szablon ma być sterowany templatem, co wymaga
przebazowania wzorca złotego i decyzji klienta.

**`display_label`, `color` i generyczny renderer dla `canon: null` NIE DZIAŁAJĄ
jeszcze w raporcie** — z tego samego powodu. Konfigurator je zapisuje, silnik
przenosi do templatu, ale szablon ich nie czyta. Interfejs mówi o tym wprost,
zamiast pozwolić operatorowi myśleć, że ustawił coś, co nie zadziała.

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
