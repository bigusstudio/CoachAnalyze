# CoachAnalyze — instrukcja dla Claude Code

Ten plik jest źródłem prawdy o projekcie. Przeczytaj go przed pierwszą zmianą w kodzie.
Jeśli zamierzasz zrobić coś, co jest tu zabronione — zatrzymaj się i zapytaj, zamiast obchodzić regułę.

**Produkt:** CoachAnalyze — analityka meczowa dla klubów piłkarskich, na bazie eksportów LiveTag.Pro.
**Wykonawca:** bigus.studio · **Serwer:** lh.pl (plan Mango, SSH, Redis) · **Język produktu:** polski.

---

## 1. Decyzje zamrożone (D1–D7)

Nie proponuj ich zmiany. Każda ma powód, który nie wynika z wygody implementacji.

| # | Decyzja | Powód |
|---|---|---|
| **D1** | **Python zostaje po stronie serwera. Zero portu parsera do JS lub PHP.** | Istniejący skrypt generuje dziś poprawne raporty. Każde przepisanie to szansa, że liczby się rozjadą. To była najbardziej ryzykowna pozycja we wszystkich wcześniejszych wersjach planu i została świadomie usunięta. |
| **D2** | WordPress to wyłącznie landing sprzedażowy na `domena.com`. Aplikacja nie zależy od WP w żadnym punkcie. | Rozdzielenie cykli wdrożeniowych. |
| **D3** | Aplikacja na `app.domena.com`. Raporty publiczne pod `/r/{club_key}/{token}`. | Osobny vhost, osobne uprawnienia. |
| **D4** | Model kanoniczny jako warstwa pośrednia. **Render nigdy nie widzi surowego CSV.** | Format LiveTag nie ma opublikowanej specyfikacji i zmienia się między wersjami. |
| **D5** | Silnik regułowy liczy, model językowy wyłącznie opisuje. **LLM nigdy nie wykonuje obliczeń.** | Jeden wymyślony wynik pokazany zarządowi kosztuje więcej niż cały moduł. |
| **D6** | Serwer stoi po stronie wykonawcy. | Naprawy bez proszenia klienta o dostęp. |
| **D7** | Kalkulator xG bez warstwy bukmacherskiej. Odprawy jako eksport PPTX, nie własny edytor slajdów. | Zakres zamknięty handlowo. |

---

## 2. Reguła nadrzędna: test złoty

**Refaktor silnika, który zmienia choć jedną liczbę w wyjściu, jest wadliwy.**

Dla każdego eksportu referencyjnego w `engine/tests/golden/` pakiet musi produkować wyjście identyczne
z zatwierdzonym wzorcem. Weryfikacja przez porównanie skrótów SHA-256 (`golden/manifest.json`).

- Test złoty jest bramką wdrożenia. Czerwony test = brak merge do `main`.
- Nie „poprawiaj" wzorca, żeby test przeszedł. Jeśli wyjście się zmieniło, to albo jest to świadoma
  zmiana wymagająca zatwierdzenia przez człowieka i wpisu w `CHANGELOG.md`, albo jest to błąd.
- Aktualizacja wzorca wyłącznie jawną komendą i nigdy w tym samym commicie co zmiana logiki.

---

## 3. Pułapki danych LiveTag — nie usuwaj tych obejść

Każda z nich kosztowała realny czas na wykryciu. Kod, który je omija, wygląda na nadmiarowy i nim nie jest.

| # | Pułapka | Obowiązkowe zachowanie |
|---|---|---|
| 1 | xG bywa zapisane w polu `comment` jako `X 0,81`, `xG 0,09`, `x 0,14` | Regex + zamiana polskiego przecinka na kropkę |
| 2 | Współrzędne w metrach, znormalizowane kierunkowo już w eksporcie | **Nie lustrzyć.** Skala 1 m = 10 px w renderze |
| 3 | III STREFA bywa bez współrzędnych | Sekcja warunkowa; brak danych = wyszarzenie z wyjaśnieniem, nie pusty wykres |
| 4 | Kolumna zawodnika bywa pusta | Brak warstwy indywidualnej dopóki dane nie istnieją |
| 5 | `team` obecne tylko na części tagów | Grupa „bez przypisania drużyny" jako osobna sekcja |
| 6 | Kształt znacznika koduje wynik strzału: `●` celny, `○` niecelny, `◆` zablokowany | Bez zmian semantyki |
| 7 | **Dopasowanie etykiet wyłącznie przez równość na rozdzielonej liście** | `substring` łapie `CELNY` wewnątrz `NIECELNY`. To jedyne realne źródło cichych błędów w liczbach |
| 8 | Czas w eksporcie to czas wideo; przerwa wykrywana z luki w tagowaniu | Największa luka w środkowej ⅓ meczu |
| 9 | Literówka `MASZA` zamiast `NASZA` w eksportach klienta | Mapowanie z jawnym ostrzeżeniem w raporcie pokrycia |
| 10 | `begin` bywa ujemny (bufor taga) | `max(0, begin)` |
| 11 | Pole `labels` zawiera przecinki wewnątrz cudzysłowów | Parser respektujący cytowanie. **Nigdy `split(',')` po całej linii** |

---

## 3a. Ograniczenie hostingu: zero zależności kompilowanych

Katalog domowy na lh.pl jest zamontowany z **`noexec`** — pliki `.so` nie dają się załadować.
Zweryfikowane 2026-08-10, szczegóły w `docs/OGRANICZENIA_HOSTINGU.md`.

**Silnik używa wyłącznie biblioteki standardowej Pythona.**
Zakazane: `pandas`, `numpy`, `lxml`, `Pillow`, `python-pptx`, `matplotlib` i każda paczka z rozszerzeniem w C.

| Zamiast | Używamy |
|---|---|
| `pandas.read_csv` | `csv.DictReader` — obsługuje cytowane pola |
| ramki danych | listy słowników |
| `numpy` | `statistics`, `math` |

Jeśli uważasz, że zadanie wymaga paczki kompilowanej — **zatrzymaj się i zapytaj**.
Nie instaluj jej „na próbę": zainstaluje się bez błędu i wywali się dopiero przy imporcie,
w produkcji, przy generowaniu raportu.

Moduł M6 (eksport PPTX) jest tym ograniczeniem zablokowany i wymaga VPS — planowane, nie zaskoczenie.

---

## 4. Podział odpowiedzialności PHP ↔ Python

- **PHP** — sesja, formularze, lista meczów, kluby, tokeny, kolejkowanie. **Nie liczy żadnej metryki piłkarskiej.**
- **Python** — parsowanie, model kanoniczny, metryki, xG, render HTML. **Nie zna sesji, nie chodzi do bazy.**
- Kontrakt między nimi to proces CLI i pliki JSON — nigdy wspólny dostęp do bazy danych.

Powód: silnik musi dać się uruchomić z palca na dowolnej maszynie i porównać wynik. Na tym stoi test złoty.

Szczegóły kontraktu: `docs/KONTRAKT_CLI.md`. Zmiana kontraktu wymaga aktualizacji tego dokumentu w tym samym commicie.

---

## 5. Bezpieczeństwo — reguły nienegocjowalne

- Pliki użytkownika **nigdy** nie są serwowane bezpośrednio. Fizycznie leżą w `~/CoachAnalyze/shared/storage`
  (dowiązanym do katalogu domeny), a dostęp do nich blokują trzy warstwy `.htaccess` — hosting wymusza
  ten układ przez `open_basedir`, patrz `docs/OGRANICZENIA_HOSTINGU.md`. Nazwa pliku losowa, serwowanie przez PHP.
- **Nie usuwaj plików `.htaccess`** z `app/`, `app/public/` i `storage/`. To jedyne, co oddziela kod aplikacji
  od publicznego internetu w tym układzie. `deploy.sh` weryfikuje je po każdym wdrożeniu.
- Adres publiczny to `/r/{club_key}/{token}`. `club_key` jest stały dla klubu, `token` odwoływalny.
  **Przy złym `club_key` i przy złym `token` zwracamy identyczne 404** — inaczej klucz klubu da się wysondować.
- Token ≥128 bitów z CSPRNG. Nagłówki: `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`.
- Hasła: argon2id. Rate limit logowania w Redis.
- Traceback Pythona nigdy nie trafia do przeglądarki — tylko do logu.
- Do modelu językowego wysyłamy **nazwy tagów i policzone metryki, nigdy surowych zdarzeń meczowych**.
  Poufność taktyki jest argumentem sprzedażowym.

---

## 6. Język i terminologia

Interfejs, komunikaty błędów i prompty AI — **po polsku**. Terminologia piłkarska klienta jest częścią produktu
i nie wolno jej tłumaczyć: **SBZ** (strefa bezpośredniego zagrożenia), **III strefa**, **pressing**, **transformacja**,
**bilans**, **fragmentator**, **odbiór**, **strata**.

Pojęcia modelu kanonicznego są angielskie wyłącznie wewnątrz kodu (`shot`, `entry_sbz`, `on_target`) — dla spójności
ze SPADL i żeby uniknąć polskich znaków w kluczach JSON. W warstwie prezentacji wracają nazwy polskie.

Teksty UI trzymamy w jednym miejscu (`app/src/lang/pl.php`), nie w szablonach — na wypadek wersji anglojęzycznej.

---

## 7. Konwencje pracy

- Gałęzie: `main` = produkcja, `dev` = staging, robocze `feat/*`. Merge do `main` tylko po zielonym CI.
- Migracje bazy numerowane, wyłącznie „w przód", nigdy edytowane po wdrożeniu.
- Wersja silnika (`engine/coachanalyze/__init__.py::__version__`) zapisywana przy każdym raporcie.
  Pytanie „dlaczego raport z marca pokazuje inną liczbę" musi mieć odpowiedź w historii commitów.
- Sekrety wyłącznie w `.env` (poza repo). Nigdy w kodzie, nigdy w commicie.
- **Do repo nie trafiają dane meczowe klienta** — eksporty CSV/JSON są danymi taktycznymi.
  W `engine/tests/golden/` leżą wyłącznie skróty oczekiwanych wyjść, a nie pliki źródłowe.

## 8. Czego nie robić bez pytania

- Nie przepisuj Pythona na PHP/JS „dla uproszczenia stacku" (D1).
- Nie dodawaj frameworka JS do raportu — szablon v17 jest samowystarczalny i to jest cecha, nie brak.
- Nie wprowadzaj ORM-a ani frameworka PHP bez uzgodnienia; skala projektu tego nie wymaga.
- Nie zmieniaj struktury `meta.json` ani kodów wyjścia CLI bez aktualizacji `docs/KONTRAKT_CLI.md`.
- Nie generuj danych zastępczych, gdy pole jest puste. Brak danych ma być widoczny, nie zamaskowany.
