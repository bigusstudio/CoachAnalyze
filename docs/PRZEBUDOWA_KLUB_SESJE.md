# CoachAnalyze — przebudowa pod Klub jako tenant · specyfikacje sesji Claude Code

**Data:** 2026-08-17 · **Właściciel:** Tomas (bigus.studio)
**Zasada pracy:** Claude Code w auto mode, commity i migracje odpala Tomas ręcznie. Każda sesja kończy się listą zmienionych plików + treścią migracji (jeśli jest) do ręcznego uruchomienia.

---

## KONTEKST GLOBALNY (wklejać na początek każdej sesji)

Pracujesz nad CoachAnalyze — SaaS analityki meczowej na eksportach LiveTag.Pro. Stan: kompletna BAZA na hostingu lh.pl (Mango, serwer400227) — PHP bez frameworka (warstwa web), Python (silnik obliczeń, pipeline parse → canon → metrics → render), MariaDB, Redis, cron-queue do generowania raportów.

> **AKTUALIZACJA 2026-08-17 (po Sesji 1).** Pierwotna wersja tego dokumentu mówiła o 13 tabelach i migracjach 001–008. Repozytorium było wtedy dalej: wdrożone są migracje **001–011** (m.in. powiadomienia, kreator mapowań, konta, reset hasła, indeks współczynników, kalkulator xG), a tabel jest 18. **Migracja przebudowy to `012`, nie `009`** — wszystkie odwołania do „009" w tym dokumencie czytaj jako „012".

**Decyzje zamrożone — nie relitygować:**
- Python zostaje po stronie serwera, nigdy nie portujemy do JS.
- PHP obsługuje requesty, Python liczy — komunikacja przez kontrakty CLI (cron worker woła skrypt z argumentami/ścieżkami).
- Silnik regułowy liczy, model językowy tylko opisuje.
- Zero zewnętrznych bibliotek komponentów JS; theming przez CSS variables (dark/light już działa).
- Format wejścia: **CSV + JSON** (bez XML — decyzja z 2026-08-17).
- Terminologia piłkarska po polsku wszędzie (UI, kod, prompty): SBZ, III strefa, pressing, transformacja, bilans. Nie tłumaczyć.

**Ograniczenia hostingu** (pełna lista: `docs/OGRANICZENIA_HOSTINGU.md`): `noexec` na katalogu domowym (brak pandas/numpy/python-pptx — silnik działa na czystym Pythonie), `disable_functions` blokuje `proc_open` z PHP-FPM (dlatego kolejka cron co minutę), `open_basedir` ogranicza PHP do katalogu domeny.

**Znane pułapki danych LiveTag:** xG w polu komentarza z polskim przecinkiem; literówka `MASZA`→`NASZA`; ujemne `begin` (clamp do 0); pozycje bywają puste w części eksportów (pierwszy eksport nie miał pozycji III STREFY); dopasowanie etykiet zawsze `===` po splicie, nigdy substring (CELNY ⊂ NIECELNY); kolumna `players` pusta; 3 różne słowniki tagów w 4 eksportach — mapowanie jest cechą stałą produktu.

**Wersjonowanie i odwracalność:** stan sprzed przebudowy jest otagowany `baza-1.0` (single-club, pełnoprawna wersja — może żyć jako osobna instalacja z własną bazą). Wszystkie migracje przebudowy są **czysto addytywne** — zero DROP/DELETE/zmian typów istniejących kolumn. Przed każdą migracją Tomas robi `mysqldump`. Zakaz mieszania: kod `baza-1.0` nie działa na bazie po migracji 012+ (`club_id NOT NULL`).

**Nowa architektura (cel przebudowy):** Klub = tenant. Zakładka **Klub** jest domyślnym widokiem po zalogowaniu. Na razie admin i wszyscy użytkownicy widzą wszystkie kluby, ale `club_id` jest w każdym query od dziś — późniejsze ograniczenie widoczności ma być jednym WHERE + rolą. Per klub istnieje **templat raportu** (wersjonowany): zbudowany w konfiguratorze z pierwszego importu CSV+JSON, definiuje zmienne (tag/etykieta → binding kanoniczny → etykieta wyświetlana → kolor → sekcje). Kolejne importy tylko mapują na templat. Regeneracja raportów: **zawsze pod kompletny, aktualny templat** (nie renderujemy starych wersji) — surowe eksporty trzymamy per mecz na stałe, przeliczenie podmienia HTML pod istniejącym tokenem publicznym.

---

## PRE-FLIGHT — obowiązkowe kroki PRZED Sesją 1 (wykonuje Tomas, Claude Code egzekwuje)

Przebudowa jest odwracalna tylko pod warunkiem wykonania tych kroków. Claude Code w Sesji 1 **zaczyna od sprawdzenia checklisty** i nie przechodzi do pisania migracji, dopóki Tomas nie potwierdzi punktów 1–3.

1. **Czyste drzewo.** Cała zaległa praca (naprawa sesji/logowania, HTTPS + nagłówki) zacommitowana i wdrożona, deploy zielony (kontrole 301 + DENY przeszły), `git status` czysty poza scratchpadem.
2. **Tag wersji przed przebudową:**
   ```bash
   git tag baza-1.0 && git push --tags
   ```
   Od tego momentu wersja single-club jest wiecznie dostępna (`git checkout baza-1.0`) — także do osobnego wdrożenia równoległego (inny katalog domeny + WŁASNA baza), np. jako prostsza instalacja dla klienta bez potrzeby wielu klubów.
3. **Zrzut bazy — przycisk „cofnij" dla danych:**
   ```bash
   mysqldump --single-transaction -u USER -p BAZA > backup_przed_012_$(date +%F).sql
   ls -lh backup_przed_012_*.sql   # sanity: rozmiar > 0, data dzisiejsza
   ```
   Rytuał na stałe: zrzut przed KAŻDĄ migracją, nie tylko tą.
4. **Reguła kompatybilności (zakaz mieszania):**
   - ✅ nowy kod + nowa baza (produkcja po przebudowie)
   - ✅ kod z taga `baza-1.0` + stara baza / restore z dumpa / osobna świeża instalacja
   - ❌ kod z taga `baza-1.0` + baza po migracji 012 — INSERT-y wywalą się na `club_id NOT NULL`. Powrót do starej wersji na produkcji = checkout taga **i** restore dumpa razem, nigdy samo jedno.
5. **Rollback (gdyby był potrzebny):** `mysql -u USER -p BAZA < backup_przed_012_DATA.sql` + `git checkout baza-1.0` + deploy. Publiczne linki `/r/{club_key}/{token}` działają w obu wersjach — tokeny i HTML-e raportów nie są ruszane przez migrację.

---

## SESJA 1 — Migracja 012: schema pod kluby i templaty

> **STAN: NAPISANE 2026-08-17, MIGRACJA NIEURUCHOMIONA.** Plik
> `app/migrations/012_kluby_templaty.sql` czeka na ręczne odpalenie przez Tomasa.
> Poniższy plan zadań jest oryginalny; **rozstrzygnięcia, które go zmieniły, są
> pod nim** w sekcji „Co wyszło w praniu". Czytaj oba, w tej kolejności.

### Cel
Fundament danych pod całą przebudowę. Sama migracja + minimalne modele PHP, zero UI.

### Zadania
0. **Bramka pre-flight:** zapytaj Tomasa wprost o potwierdzenie trzech rzeczy z sekcji PRE-FLIGHT (czyste drzewo po deployu, tag `baza-1.0` wypchnięty, dump `backup_przed_012_*.sql` istnieje i ma rozmiar > 0). Bez potwierdzenia — nie pisz migracji.
1. Napisz migrację `012` (konwencja jak 001–011) tworzącą — **zasada twarda: migracja czysto addytywna** (CREATE TABLE / ALTER ADD / UPDATE backfill; zero DROP, zero DELETE, zero zmiany typów istniejących kolumn):

   **`clubs`**
   - `id` INT PK AI
   - `name` VARCHAR(120) NOT NULL
   - `short_name` VARCHAR(40) NULL
   - `club_key` VARCHAR(40) NOT NULL UNIQUE — slug do URL publicznych `/r/{club_key}/{token}` (generowany z nazwy, edytowalny tylko przy tworzeniu)
   - `logo_path` VARCHAR(255) NULL — plik na dysku, NIE base64 w bazie
   - `color_primary` CHAR(7) NULL, `color_secondary` CHAR(7) NULL — hex
   - `details` JSON NULL — miasto, liga, sezon bieżący, dowolne pola opisowe
   - `created_by` INT FK→users, `created_at`, `updated_at`

   **`club_report_templates`**
   - `id` INT PK AI
   - `club_id` INT FK→clubs NOT NULL
   - `version` INT NOT NULL — licznik od 1
   - `config` LONGTEXT NOT NULL — JSON (struktura w Sesji 3/4)
   - `created_by` INT FK→users, `created_at`
   - UNIQUE(`club_id`, `version`)
   - Aktualny templat klubu = MAX(version). Bez flagi `is_active` — zawsze najnowszy.

   **`club_ignored_tags`**
   - `id`, `club_id` FK, `source_type` ENUM('tag','label'), `raw_name` VARCHAR(190), `created_at`
   - UNIQUE(`club_id`, `source_type`, `raw_name`)
   - Osobna tabela celowo: „zignoruj na stałe" NIE podbija wersji templatu.

2. Rozszerz istniejące tabele (ALTER w tej samej migracji):
   - `matches`: + `club_id` FK NOT NULL (po backfillu), + `opponent_name` VARCHAR(120), + `is_home` TINYINT(1), + `raw_csv_path` VARCHAR(255), + `raw_json_path` VARCHAR(255). Jeśli któraś kolumna meta już istnieje (data, wynik, sezon) — nie duplikuj, sprawdź schema przed pisaniem.
   - `reports`: + `club_id` FK, + `template_version` INT NULL (NULL = wygenerowany przed erą templatów).
   - `notes`: + `club_id` FK (poziom klubowy już istnieje w module notatek — zweryfikuj jak jest zamodelowany i ujednolić).

3. **Backfill:** utwórz klub domyślny z danych obecnego klienta (nazwa/klucz do potwierdzenia w kodzie jako stała na górze migracji, żeby Tomas mógł podmienić przed odpaleniem). Przypisz wszystkie istniejące matches/reports/notes do niego. Dopiero po backfillu dołóż NOT NULL + FK.

4. Modele/repozytoria PHP w konwencji projektu: `ClubRepository`, `TemplateRepository` (getCurrent(club_id), saveNewVersion(club_id, config), getVersion), `IgnoredTagsRepository`. Bez UI.

5. Przejdź po istniejących query w kodzie (lista meczów, raporty, notatki, tokeny publiczne) i dołóż filtr `club_id` wszędzie, gdzie kontekst klubu jest znany. Tam gdzie jeszcze nie ma kontekstu — TODO z komentarzem `// TODO(club-scope)`.

### Kryteria akceptacji
- Pre-flight potwierdzony przez Tomasa PRZED napisaniem pierwszej linii migracji.
- Migracja nie zawiera żadnego DROP/DELETE/MODIFY istniejących kolumn (`grep -iE 'drop|delete from|modify column' migracja` → pusto, poza ewentualnym komentarzem).
- Migracja idempotentnie sprawdzalna na kopii bazy (Tomas odpala ręcznie; zalecany test na kopii z dumpa przed produkcją).
- Po migracji: wszystkie istniejące mecze/raporty mają `club_id` klubu domyślnego, publiczne linki `/r/{club_key}/{token}` działają bez zmian.
- `grep TODO(club-scope)` zwraca kompletną listę miejsc do domknięcia.

### Poza zakresem
UI klubów, konfigurator, jakiekolwiek zmiany w silniku Python.

### Co wyszło w praniu — rozstrzygnięcia z 2026-08-17

Plan zakładał bazę, której nie było. Sześć punktów zderzyło się ze stanem faktycznym
i zostało rozstrzygniętych z Tomasem PRZED napisaniem migracji. **To jest wersja
obowiązująca dla kolejnych sesji.**

| Plan mówił | Baza mówi | Decyzja |
|---|---|---|
| `CREATE TABLE clubs` | `clubs` istnieje **od migracji 001** (club_key, barwy, `crest_path`, `is_own_team`, `aliases_json`) | wyłącznie `ALTER ADD details, updated_at`. Spec-owe `logo_path` **to istniejące `crest_path`** — bez zmiany nazwy |
| `matches.club_id` jako nowość | `matches` ma już `club_home_id` = strona „nasza" | `club_id` dochodzi jako **tenant**, `club_home_id` zostaje nietknięte. Rozdział opisany w `docs/MODEL_KANONICZNY.md` |
| `matches.opponent_name` (wolny tekst) | rywal jest wierszem w `clubs` (`club_away_id`) z herbem, barwami i aliasami | **kolumna NIE powstaje.** Istniejący model jest bogatszy; wolny tekst byłby drugim źródłem prawdy i utratą `aliases_json` |
| `matches.raw_csv_path/raw_json_path` | `imports.csv_path` / `imports.json_path` istnieją od 001 | **kolumny NIE powstają.** Kopia rozjechałaby się przy pierwszym reimporcie |
| `notes` + `club_id` | kolumna istnieje od 001, ale wypełniana tylko dla `scope='club'` | backfill z meczu dla notatek meczowych i zdarzeniowych + klucz obcy |
| „utwórz klub domyślny" | klub klienta już istnieje | **żadnego INSERT-a klubu.** Stała na górze migracji wskazuje `club_key` istniejącego klubu; zły klucz przerywa migrację |

Dodatkowo:

- **`config` jako `JSON`**, nie `LONGTEXT` — konwencja projektu (`aliases_json`, `coverage_json`).
- **Nazwy klas modeli** wg konwencji projektu: `ReportTemplates`, `IgnoredTags`,
  a rozszerzenia klubu w istniejącym `Clubs`. Nie `*Repository` — druga konwencja
  nazewnicza w jednym katalogu kosztuje więcej, niż jest warta.
- **Kryterium „zero MODIFY" ma jeden świadomy wyjątek**: `matches.club_id` powstaje
  jako NULL, jest backfillowany i dopiero potem zaciskany do `NOT NULL`. To `MODIFY`
  na kolumnie założonej w tym samym pliku, nie na zastanej — inaczej się nie da,
  bo wartości nie znamy w chwili DDL. Kryterium dotyczy kolumn istniejących wcześniej.
- **Nowe mecze muszą mieć tenanta.** `Imports::create()` przyjmuje `club_id`
  (na razie opcjonalny, domyślnie klub własny przez `Clubs::tenantDefault()`).
  Po Sesji 2 parametr staje się obowiązkowy i przychodzi z adresu.
- **Schemat testowy (`app/tests/integracja/seed.php`) odwzorowuje 012 wraz z `NOT NULL`.**
  Łagodniejszy schemat w testach to ta sama klasa błędu, co powtórzony symbol nazwany:
  przepuszcza kod, który wywala się dopiero u klienta.

---

## SESJA 2 — Zakładka Klub jako domyślny widok + CRUD klubu

### Cel
Klub staje się punktem wejścia do aplikacji.

### Zadania
1. **Routing:** po zalogowaniu przekierowanie na `/kluby` (lista). Dotychczasowy widok startowy dostępny z nawigacji.
2. **Lista klubów:** karty z logo, nazwą, licznikami (mecze, raporty), datą ostatniego importu. Na razie wszyscy widzą wszystkie kluby — ale każde query przez `ClubRepository` z jawnym `club_id`, żeby scope'owanie później było trywialne.
3. **Tworzenie klubu:** formularz — nazwa, short_name, `club_key` (auto-slug z nazwy, walidacja unikalności, edytowalny tylko tutaj), logo (upload PNG/JPG/SVG, limit 2 MB, zapis na dysk w katalogu klubu, walidacja MIME po treści nie rozszerzeniu), kolory primary/secondary (color picker, fallback tekstowy hex), pola z `details` (miasto, liga, sezon).
4. **Edycja klubu:** wszystko poza `club_key`.
5. **Widok klubu (dashboard klubu):** nagłówek z logo i barwami, sekcje: ostatnie mecze, ostatnie raporty, status templatu („brak templatu → skonfiguruj raporty" jako wyraźne CTA, albo „templat v3, zaktualizowany DATA"). Ten widok to hub, z którego wychodzi się do konfiguratora (Sesja 3) i importu meczu (Sesja 6).
6. **Nawigacja:** breadcrumb `Kluby → {Klub} → …` w całej aplikacji; biblioteka meczów i raporty filtrowane po klubie z kontekstu URL (`/klub/{id}/mecze` itd.).
7. Theming: barwy klubu jako CSS variables w scope widoku klubu (`--club-primary`, `--club-secondary`), z korektą kontrastu jak w istniejącej logice palety (jasność < progu → podbicie). Dark/light mode bez zmian.

### Kryteria akceptacji
- Świeży user po zalogowaniu ląduje na liście klubów.
- Tworzę klub z logo i kolorami → widzę hub klubu z CTA „Skonfiguruj raporty".
- Istniejący klub domyślny (z backfillu) ma działającą listę meczów i raportów pod nowym routingiem.
- Żaden publiczny link raportu się nie zmienił.

### Poza zakresem
Konfigurator, uprawnienia per klub (świadomie później).

---

## SESJA 3 — Konfigurator, część A: import założycielski + mapowanie z wizardem AI

### Cel
Pierwszy import CSV+JSON dla klubu → kompletna, wstępnie zmapowana lista zmiennych.

### Kontekst
Wizard mapowania tagów już istnieje (model proponuje, user potwierdza; profile per konto, cache w Redis). Ta sesja przenosi go do kontekstu klubu i robi z niego pierwszy krok konfiguratora.

### Zadania
1. **Ekran importu założycielskiego** (`/klub/{id}/konfigurator`): dwa pola drop — CSV (wymagany) i JSON projektu LiveTag (opcjonalny; bez niego brak palety LiveTag → fallback na barwy klubu). Walidacja twarda: wymagane kolumny (`tag_name`, `begin`, `end`, …), czytelny komunikat zamiast cichej awarii przy pliku, który nie wygląda jak eksport LiveTag.
2. **Parsowanie istniejącym pipeline** (bez zmian w parserze): zastosuj znane korekty (MASZA→NASZA z ostrzeżeniem, clamp ujemnych `begin`, xG z komentarza z przecinkiem, detekcja przerwy z luki czasowej).
3. **Raport pokrycia importu:** liczba zdarzeń, strzałów, wykryta przerwa, obecność pozycji (ogółem i per strefa), obecność `team`, obecność kolumny `players` (pusta = ostrzeżenie „poziom indywidualny niedostępny"), sparsowane xG. To samo, co robi obecny ekran coverage — przenieś/reużyj, nie pisz od nowa.
4. **Listing słownika:** wszystkie unikalne tagi i etykiety z importu, z licznikami wystąpień i próbką (3 przykładowe zdarzenia w tooltipie).
5. **Wizard AI proponuje binding kanoniczny** dla każdej pozycji słownika: tag/etykieta → pojęcie kanoniczne (`shot`, `on_target`, `blocked`, `xg`, `entry_sbz`, `entry_iii`, `team_us`, `team_them`, `loss`, `recovery`, `duel`, … — pełny słownik kanoniczny z istniejącego kodu canon). Kontrakt jak w istniejącym wizardzie: model **proponuje**, nic nie zapisuje się bez potwierdzenia usera. Pozycje bez sensownej propozycji → `canon: null` (zmienna niestandardowa).
6. Paleta z JSON (istniejąca logika `to_hex` z korektą jasności) jako domyślne kolory zmiennych; brak JSON → kolory z barw klubu + neutrale.
7. Wynik sesji A trzymany w stanie roboczym (draft w sesji PHP lub tabela tymczasowa) — zapis templatu to Sesja 4. Surowe pliki założycielskie zapisz od razu na dysk per mecz (ten import JEST pierwszym meczem klubu — patrz Sesja 6 pkt 1, formularz meta może być tu uproszczony lub odroczony).

### Kryteria akceptacji
- Wgranie referencyjnego eksportu (294 zdarzenia) → raport pokrycia zgodny z obecnym ekranem coverage, pełny słownik z licznikami, propozycje bindingów dla oczywistych tagów (strzały, SBZ, III strefa) poprawne.
- Wgranie starego eksportu (bez pozycji III strefy, z MASZA) → poprawne ostrzeżenia, zero wysypki.
- Wgranie śmieciowego CSV → czytelny błąd walidacji.

### Poza zakresem
Zapis templatu, generowanie raportu, silnik Python.

---

## SESJA 4 — Konfigurator, część B: edycja zmiennych + zapis templatu v1

*(Naturalna kontynuacja Sesji 3 — może iść w tej samej sesji Claude Code, jeśli kontekst wytrzyma.)*

### Cel
User dopina zmienne i zapisuje templat — kontrakt danych, na którym stanie wszystko dalej.

### Zadania
1. **Edytor zmiennych** — tabela/lista, per zmienna:
   - źródło (tag/etykieta, raw name) — read-only
   - **binding kanoniczny** — select ze słownika kanonicznego + opcja „brak (zmienna niestandardowa)"
   - **etykieta wyświetlana** — tekst, domyślnie raw name
   - **kolor** — picker, domyślnie z palety/barw
   - **sekcje** — w których sekcjach raportu zmienna występuje (multi)
   - **widoczna** — toggle
   - akcje: usuń z templatu (nie trafi do raportów), dodaj ręcznie (np. zmienna wyliczana z istniejącego canon, bez nowego taga)
2. **Twarda zasada w UI:** zmienna bez bindingu kanonicznego dostaje wyłącznie widoki generyczne — licznik w bilansie + pas na osi czasu. Sekcje wymagające semantyki (mapy, xG) są dla niej zablokowane z podpowiedzią „podepnij pojęcie kanoniczne, żeby użyć w mapach/xG". Zero cichego ignorowania.
3. **Wybór sekcji raportu** dla templatu (istniejący rejestr sekcji): bilans, mapy współczynników, oś SBZ, oś III strefy, oś bilansu, pojedynki/straty/odbiory, bez przypisania drużyny + moduły M1/M3 jeśli już wpięte w raport.
4. **Struktura `config` (JSON, zapisywana w `club_report_templates`):**
```json
{
  "schema_version": 1,
  "team_us_rule": { "markers": ["NASZA", "MASZA"] },
  "sections_enabled": ["bilans", "mapy", "tl-sbz", "tl-iii", "tl-bilans", "duels", "noteam"],
  "variables": [
    {
      "id": "v_001",
      "source": { "type": "tag", "raw": "STRZAŁ NASZA" },
      "canon": "shot",
      "display_label": "Strzały",
      "color": "#E8590C",
      "sections": ["bilans", "mapy"],
      "visible": true
    },
    {
      "id": "v_017",
      "source": { "type": "label", "raw": "WYSOKIE WYBICIE" },
      "canon": null,
      "display_label": "Wysokie wybicie",
      "color": "#8899AA",
      "sections": ["bilans", "tl-bilans"],
      "visible": true
    }
  ]
}
```
   Walidacja przy zapisie: unikalne `id`, sekcje ⊆ rejestru, kolory hex, przy `canon: null` sekcje ⊆ {bilans, osie}.
5. **Zapis = nowa wersja:** pierwszy zapis → version 1; każdy kolejny zapis edycji templatu → version+1 (append, nigdy update poprzednich wierszy). Widok „historia wersji" minimalny: lista wersji z datą, bez diffa (diff to nice-to-have później).
6. Ekran podsumowania po zapisie: „Templat v1 zapisany · X zmiennych (Y kanonicznych, Z niestandardowych) · N sekcji" + CTA „Wygeneruj przykładowy raport" (Sesja 5).

### Kryteria akceptacji
- Zapis templatu tworzy wiersz v1 z poprawnym JSON przechodzącym walidację.
- Edycja i ponowny zapis → v2, v1 nietknięta.
- Zmienna niestandardowa nie da się przypisać do sekcji map.
- Odświeżenie strony w trakcie edycji nie gubi draftu (stan roboczy z Sesji 3).

---

## SESJA 5 — Silnik: templat jako wejście pipeline + przykładowy raport

### Cel
Jedna ścieżka generowania: silnik Python przyjmuje config templatu i renderuje wyłącznie to, co templat definiuje. Przykładowy raport z konfiguratora idzie przez tę samą kolejkę cron co produkcja.

### Zadania
1. **Rozszerz kontrakt CLI silnika** o parametr ze ścieżką do pliku config templatu (JSON jak w Sesji 4). Worker cron dostaje w zadaniu: ścieżki CSV, JSON, config, output + club_id/match_id. PHP przed enqueue serializuje aktualny templat (MAX version) do pliku roboczego zadania.
2. **W silniku (czysty Python, pamiętaj o noexec — żadnych nowych zależności):**
   - warstwa canon mapuje zdarzenia wg `variables[].source → canon` z configu (zamiast dotychczasowego mapowania z profilu wizarda),
   - `team_us_rule.markers` steruje przypisaniem drużyn (w tym korekta MASZA),
   - render: tylko `sections_enabled`, etykiety z `display_label`, kolory z `color`,
   - zmienne `canon: null` → generyczny licznik w bilansie + pas na osi czasu (nowy, prosty renderer — licznik per drużyna jeśli zdarzenia mają team, inaczej łącznie),
   - **coverage templat × eksport:** sekcja włączona w templacie, ale bez danych w tym eksporcie (np. brak pozycji) → pomijana w HTML + wpis w raporcie pokrycia „sekcja X niedostępna: powód". Generowanie NIGDY nie pada z tego powodu.
3. **Stempel wersji:** wygenerowany raport zapisuje `template_version` w `reports`; w stopce HTML raportu dyskretna informacja „templat vN · wygenerowano DATA".
4. **Przykładowy raport z konfiguratora:** przycisk po zapisie templatu → enqueue zadania na pliki założycielskie przez istniejącą kolejkę. Zero osobnego code path — to zwykłe zadanie z flagą `is_sample` tylko do oznaczenia w UI. Ekran statusu jak przy normalnym generowaniu (kolejka cron = wynik do minuty-dwóch).
5. **Test regresji:** referencyjny eksport + templat odwzorowujący obecny raport 1:1 → wynikowy HTML funkcjonalnie równy dzisiejszemu (te same sekcje, liczby, xG, half_split ≈ 45,5'). To bramka akceptacji całej sesji.

### Kryteria akceptacji
- Raport generuje się wyłącznie z sekcjami z templatu, z etykietami i kolorami usera.
- Templat z wyłączoną sekcją map → map nie ma w HTML, bez błędu.
- Eksport bez pozycji III strefy + templat z osią III strefy → sekcja pominięta z powodem w coverage.
- Test regresji przechodzi.

### Poza zakresem
Regeneracja starych raportów (Sesja 7), diff tagów (Sesja 6).

---

## SESJA 6 — Import kolejnych meczów: meta, diff tagów, generowanie na templacie

### Cel
Import meczu nr 2+ w klubie z gotowym templatem = trzy minuty roboty usera.

### Zadania
1. **Formularz meta meczu** (przed uploadem lub razem z nim): przeciwnik, data, dom/wyjazd, wynik, sezon (z istniejącego modelu sezonów). Nazwa „naszej" drużyny z klubu, nie z formularza.

   > **KOREKTA 2026-08-17 (Sesja 1).** Przeciwnik **nie jest wolnym tekstem**.
   > Wybiera się go z listy klubów albo zakłada jako nowy wpis w `clubs`
   > z `is_own_team = 0`; mecz wskazuje go przez `club_away_id`. Kolumna
   > `matches.opponent_name` **nie istnieje i nie ma powstać**.
   >
   > Powód: wiersz w `clubs` niesie herb (`crest_path`), barwy i — najważniejsze —
   > `aliases_json`, czyli nazwy, pod jakimi klub występuje w eksportach LiveTag.
   > To one napędzają automatyczne dopasowanie drużyn przy imporcie
   > (`Clubs::matchByExportName`, `Clubs::rememberAlias`). Wolny tekst zabiłby
   > dopasowanie i kazałby operatorowi wpisywać przeciwnika przy każdym meczu
   > od nowa, także przy rewanżu z tym samym klubem.
   >
   > Herb przeciwnika wgrywa się jak herb klubu — tą samą ścieżką
   > (`Crest.php`, walidacja MIME po treści), bo to ten sam byt.

   `is_home` (TINYINT NULL, migracja 012) wypełnia **wyłącznie ten formularz**.
   Eksport LiveTag nie niesie informacji o gospodarzu i nie wolno jej zgadywać —
   `NULL` znaczy „nie wiemy" i jest poprawną wartością dla całej historii.
2. **Upload CSV+JSON → parsowanie → surowe pliki na dysk per mecz.** Reguła bezwzględna: surowe eksporty zostają na stałe — to warunek regeneracji (Sesja 7).

   > **KOREKTA 2026-08-17 (Sesja 1).** Ścieżki zapisujemy w **`imports.csv_path`
   > i `imports.json_path`** — istnieją od migracji 001. Kolumny
   > `matches.raw_csv_path/raw_json_path` **nie powstały i nie mają powstać**:
   > byłyby kopią tej samej informacji, która rozjeżdża się z oryginałem przy
   > pierwszym ponownym imporcie do tego samego meczu.
   >
   > Reimport = **nowy wiersz w `imports`**, stary zostaje. Obowiązuje NAJNOWSZY
   > (`ORDER BY id DESC LIMIT 1`), co daje darmową historię wgrań meczu.
3. **Diff słownika vs templat:** porównaj tagi/etykiety importu z `variables[].source` aktualnego templatu ∪ `club_ignored_tags`:
   - znane → mapują się cicho,
   - **nowe** → ekran „Nowe tagi w tym imporcie (N)" z trzema akcjami per pozycja:
     - **Dodaj do templatu** → mini-edytor jak w Sesji 4 (binding, etykieta, kolor, sekcje); zatwierdzenie całości → zapis nowej wersji templatu (jedna wersja na cały import, nie per tag),
     - **Zignoruj w tym imporcie** → nic nie zapisujemy, zdarzenia z tym tagiem pominięte w raporcie, odnotowane w coverage,
     - **Zignoruj na stałe** → wpis w `club_ignored_tags`, nigdy więcej nie pytamy.
   - Zero cichego wyrzucania danych: wszystko zignorowane jest wylistowane w raporcie pokrycia.
4. **Coverage templat × eksport** przed generowaniem (reużycie z Sesji 5): user widzi, które sekcje templatu będą niedostępne dla tego meczu i dlaczego — zanim kliknie „Generuj".
5. **Generowanie** przez kolejkę na aktualnym templacie (po ewentualnym podbiciu wersji z diffa). Raport ląduje w bibliotece meczów klubu, token publiczny jak dotychczas.
6. Biblioteka meczów klubu: kolumna/badge wersji templatu raportu (przygotowanie pod Sesję 7 — na razie tylko informacyjnie).

### Kryteria akceptacji
- Import eksportu ze słownikiem #2 (inny klub źródłowy — realny plik testowy) do klubu z templatem → diff pokazuje właściwe nowe tagi, każda z trzech akcji działa.
- „Dodaj do templatu" przy imporcie → powstaje dokładnie jedna nowa wersja templatu.
- „Zignoruj na stałe" → przy następnym imporcie z tym samym tagiem brak pytania, wpis w coverage.
- Pełna ścieżka: meta → upload → diff → coverage → generuj → raport z linkiem publicznym, bez dotykania konfiguratora.

---

## SESJA 7 — Regeneracja raportów pod aktualny templat

### Cel
Edycja templatu nie zostawia klubu z niespójnymi raportami. Regeneracja zawsze pod kompletny, aktualny templat; stare wersje NIE są renderowalne — wersja na raporcie służy tylko do wykrycia, że jest starszy.

### Zadania
1. **Badge na liście raportów:** `reports.template_version < MAX(version)` klubu → „wygenerowano z templatem vX (aktualny vY)" + akcja „Przelicz".
2. **Przelicz per raport:** enqueue zadania na surowych plikach meczu z aktualnym configiem.

   > **KOREKTA 2026-08-17 (Sesja 1).** Ścieżki bierzemy z **najnowszego wiersza
   > `imports` dla danego meczu**, nie z `matches` — patrz korekta w Sesji 6 pkt 2.
   >
   > ```sql
   > SELECT csv_path, json_path FROM imports
   >  WHERE match_id = :m ORDER BY id DESC LIMIT 1
   > ``` **Podmiana HTML in place pod tym samym tokenem publicznym** — link rozesłany sztabowi nie może umrzeć. Do czasu ukończenia zadania stary HTML pozostaje dostępny (podmiana atomowa: zapis do pliku tymczasowego + rename).
3. **Przelicz zbiorczo:** akcja na poziomie klubu „Przelicz wszystkie nieaktualne (N)" → enqueue N zadań; kolejka cron przetwarza po kolei, widok postępu (X/N gotowe, lista błędów per mecz). Zbiorcze przeliczenie NIE zatrzymuje się na pierwszym błędzie.
4. **Obsługa braków:** raport bez surowych plików (mecz bez żadnego wiersza w `imports` albo z plikami skasowanymi z dysku) → akcja „Przelicz" zablokowana z komunikatem „brak surowych plików importu — wgraj eksport ponownie w widoku meczu"; dołóż w widoku meczu możliwość ponownego wgrania surowych plików do istniejącego meczu (czyli dopisania nowego wiersza `imports`).
5. Po udanym przeliczeniu: update `reports.template_version`, timestamp, wpis w istniejącym systemie toastów.
6. Coverage przy regeneracji działa jak przy imporcie: sekcje niedostępne dla danego eksportu pomijane z powodem — templat mógł urosnąć o sekcje, których stary eksport nie obsłuży, i to jest OK.

### Kryteria akceptacji
- Edycja templatu (v1→v2) → wszystkie raporty v1 dostają badge i akcję Przelicz.
- Przeliczenie pojedynczego raportu: ten sam URL publiczny, nowa treść, poprawny stempel v2.
- Zbiorcze przeliczenie 3+ raportów przechodzi przez kolejkę z widocznym postępem; sztucznie zepsuty jeden plik → błąd odnotowany, reszta przeliczona.
- W żadnym momencie link publiczny nie zwraca 404/pustej strony (podmiana atomowa).

---

## PO SESJACH 1–7 (backlog, nie planować teraz)

- **Klonowanie templatu** przy tworzeniu klubu („zacznij od templatu klubu X" / templat systemowy) — tanie, mocne przy demo sprzedażowym.
- **Krok „test na drugim meczu"** w konfiguratorze — po zapisie v1 zachęta do wgrania drugiego eksportu dla walidacji pokrycia.
- **Diff wersji templatu** w historii (co się zmieniło między vN a vN+1).
- Uprawnienia per klub (user↔club), gdy skończy się faza „wszyscy widzą wszystko".
- Moduły M1/M3 jako sekcje templatu — jeśli w międzyczasie zostaną domknięte, dopisują się do rejestru sekcji z Sesji 4/5.

## KOLEJNOŚĆ I ZALEŻNOŚCI

```
1 (schema) → 2 (UI klubów) → 3+4 (konfigurator) → 5 (silnik+sample) → 6 (import n+1) → 7 (regeneracja)
```

Sesje 3+4 mogą iść w jednym oknie Claude Code. Sesja 5 to jedyna, która dotyka Pythona — reszta żyje w PHP/JS. Test regresji z Sesji 5 jest bramką: dopóki nie przechodzi, nie ruszać 6 i 7.
