# RUNBOOK — co robić, gdy coś padnie

## Zadanie stoi w statusie `running` dłużej niż 5 minut

1. `ps aux | grep coachanalyze` — czy proces Pythona żyje
2. Log workera: `tail -100 app/logs/worker.log`
3. Jeśli proces martwy, a status `running` — zwolnić blokadę: `redis-cli DEL ca:lock:worker`
4. Ponowić zadanie z panelu

## Raport się nie wygenerował

Kod wyjścia z `jobs.error_text` mówi, co się stało:
- `2` — plik nie jest eksportem LiveTag. Sprawdzić, co operator wgrał
- `3` — brak wymaganych kolumn. Prawdopodobnie nowa wersja LiveTag; porównać `format_fingerprint`
- `4` — błąd silnika. Traceback w logu; odtworzyć lokalnie na tym samym pliku
- `5` — przekroczony czas. Sprawdzić obciążenie serwera i `ENGINE_TIMEOUT`

Odtworzenie z palca (najszybsza diagnoza):

```bash
venv/bin/python -m coachanalyze inspect --csv /storage/uploads/<plik>.csv
```

## Klient dzwoni w piątek przed meczem

1. Czy raport w ogóle powstał — panel, lista zadań
2. Czy link nie został odwołany — `share_links.revoked_at`
3. Jeśli raport jest, a link nie działa — wygenerować nowy token, wysłać
4. Jeśli raportu nie ma — wgrać eksport ręcznie po SSH i wysłać plik HTML mailem.
   **Zawsze jest ta ścieżka.** Silnik działa bez aplikacji webowej i to jest celowe.

## Nowy `format_fingerprint`

Sygnał, że LiveTag zmienił eksport. Nie ignorować.
1. Porównać nagłówki nowego i starego pliku
2. Uzupełnić `docs/FORMAT_LIVETAG.md`
3. Dodać plik do zestawu złotego jako nowy przypadek
4. Dopiero potem dostosowywać parser

## Wycofanie wdrożenia

```bash
cd /home/uzytkownik/CoachAnalyze
ln -sfn releases/<poprzednie> current
```

Migracje bazy nie cofają się automatycznie. Zrzut bazy przed każdą migracją jest obowiązkowy.

---

# HTTPS i nagłówki bezpieczeństwa

## Wymuszenie HTTPS mieszka w pliku, który wdrożenie nadpisuje

Przekierowanie HTTP→HTTPS niesie `app/public/.htaccess`, a przejście 2 rsync
w `deploy.sh` **nadpisuje ten plik wersją z repozytorium**. Reguła włączona ręcznie
na serwerze przeżywa więc dokładnie do najbliższego wdrożenia.

Skutek jej zniknięcia jest cichy i całkowity: panel wraca na HTTP, ciasteczko sesji
ma flagę `Secure` (bierze ją z `APP_URL`), więc przestaje wracać. Token CSRF nie ma się
gdzie zapisać i **logowanie odbija każdą próbę** — nikt nie wejdzie do panelu.
Aplikacja nazwie przyczynę na ekranie logowania („Strona została otwarta bez
szyfrowania…"), co skraca diagnozę, ale nie przywraca dostępu.

Dlatego reguła jest pilnowana w dwóch miejscach:

| Gdzie | Co sprawdza |
|---|---|
| `app/tests/test_layout.php` (CI) | reguły w `app/public/.htaccess` NIE są zakomentowane, a blokady `[F,L]` stoją przed przekierowaniem |
| `deploy.sh`, kontrola po wdrożeniu | `http://app.coachanalyze.pl/login` odpowiada 301 z `Location: https://…` |

Jeśli wdrożenie zatrzyma się na tej kontroli — odkomentuj `RewriteCond %{HTTPS} !=on`
**w repozytorium**, nie tylko w katalogu domeny.

## Nagłówki ustawia wyłącznie `.htaccess`

`Header always set` w Apache działa w fazie fixup i **zastępuje** nagłówek wysłany
przez PHP. Dublowanie tych ustawień w `app/src/bootstrap.php` dawało więc dwa źródła
prawdy, z których wygrywało to niewidoczne z kodu: kod deklarował
`X-Frame-Options: DENY`, a produkcja odpowiadała `SAMEORIGIN`. Rozjazd wyszedł dopiero
przy przeglądzie nagłówków na żywo (`curl -I`). Ustawienia z PHP zostały usunięte —
politykę zmienia się w `app/public/.htaccess`.

Kosztem jest brak zapasowego ustawienia: bez Apache z `mod_headers` tych nagłówków
nie ma w ogóle. Dlatego `deploy.sh` sprawdza po wdrożeniu, czy faktycznie wracają.

## `X-Frame-Options: DENY` blokuje osadzanie raportów w ramce

Świadoma decyzja, nie przeoczenie. Panel nie ma ani jednego własnego `iframe`,
więc `SAMEORIGIN` nie dawał niczego poza słabszą ochroną.

**Dotyczy to także raportów publicznych `/r/{club_key}/{token}`** — klub NIE osadzi
raportu w ramce na własnej stronie. Dziś nikt o to nie prosił; gdyby to miała być
funkcja, właściwą drogą jest `Content-Security-Policy: frame-ancestors` z listą domen
klubów (albo powrót do `SAMEORIGIN`), a **nie** zdjęcie nagłówka. Zdjęcie otwiera
raport na osadzenie przez dowolną stronę, a raport jest chroniony wyłącznie
nieodgadywalnością adresu.

Odnośnik do raportu wysłany klubowi otwiera się normalnie w karcie przeglądarki —
blokada dotyczy tylko ramek.

---

# Przypadki z wdrożenia 2026-08-11

Wszystkie poniższe wystąpiły naprawdę, przy pierwszym uruchomieniu na lh.pl.
Objaw jest tym, co widzi operator — przyczyna bywa o dwie warstwy głębiej.

## „Logowanie jest chwilowo niedostępne", choć Redis działa

**Objaw.** Ekran logowania odrzuca poprawne hasło tym komunikatem. `redis-cli ping`
z konsoli odpowiada `PONG`.

**Przyczyna.** Nie Redis. `Config` nie odczytał `.env`, więc zabrakło `REDIS_SOCKET`,
a limiter prób zgłosił wyjątek **zanim cokolwiek spróbowało się połączyć**.
Zabezpieczenie „fail closed" zadziałało na podstawie nieudanego odczytu konfiguracji.

**Dlaczego `.env` się nie odczytał.** `open_basedir` sprawdza ścieżkę **po rozwinięciu
dowiązania**. `{domena}/.env` był symlinkiem do `~/CoachAnalyze/shared/.env`, czyli
poza listę — dla PHP-FPM niewidocznym. Z konsoli działał, bo CLI nie ma tego ograniczenia.

**Naprawa.** `deploy.sh` kopiuje `.env` do katalogu domeny (`cp`, nie `ln -s`).
Kontrola po wdrożeniu przerywa proces, jeśli `.env` jest dowiązaniem.

**Sprawdzenie:**
```bash
ls -l ~/public_html/app.coachanalyze.pl/.env     # ma być zwykły plik, bez "->"
php -r 'var_dump(is_file("/pełna/ścieżka/.env"));'
```

## Trzy ostrzeżenia PHP nad formularzem logowania

**Objaw.** Nad polami logowania trzy komunikaty `Warning: is_file(): open_basedir restriction`.

**Przyczyna.** `Config` szukał `.env`, idąc w górę drzewa katalogów. Dwa ostatnie
poziomy leżą poza `open_basedir` — każde sprawdzenie to jedno ostrzeżenie. Powstawały
**zanim** kod zdążył ustawić `display_errors` na podstawie tej samej konfiguracji.

**Naprawa.** Położenie `.env` z jednej jawnej stałej (`CA_ROOT . '/.env'`).
`display_errors` wyłączane w **pierwszych liniach** bootstrapu, nie po wczytaniu konfiguracji.

## `is_file()` zwraca false dla działającego zasobu

**Objaw.** Kod broni się przed brakiem pliku, a zasób jest dostępny.

**Przyczyna.** Na lh.pl sprawdzenie istnienia podlega `open_basedir`, a otwarcie
zasobu już nie. Dla ścieżki spoza listy `is_file()`, `file_exists()` i `is_readable()`
zwracają `false`, mimo że `stream_socket_client()` albo `file_get_contents()` przechodzą.

**Zasada.** W warstwie wejścia **nie pytamy, czy zasób istnieje — próbujemy go użyć**
i obsługujemy niepowodzenie. Pilnuje tego `app/tests/test_layout.php`.

## Aplikacja nie ma gdzie zapisać przyczyny błędu

**Objaw.** Coś nie działa, w logu pusto. Diagnostyka zajęła godzinę.

**Przyczyna.** `LOG_PATH` wskazywał katalog spoza `open_basedir`, więc `error_log()`
nie miał dokąd pisać. Dodatkowo proces roboczy startuje odpięty, ze strumieniami
do `/dev/null` — bez `LOG_PATH` jego ślad przepada całkowicie.

**Naprawa.** `LOG_PATH` domyślnie w `~/tmp/` (katalog z listy `open_basedir`).
Bootstrap sprawdza zapisywalność **próbą zapisu** i przy niepowodzeniu schodzi
do katalogu tymczasowego, odnotowując to. Log w nieoczywistym miejscu jest lepszy niż brak logu.

## `password_hash` z argon2id kończy się błędem

**Objaw.** `A thread value other than 1 is not supported by this implementation`
przy zakładaniu konta.

**Przyczyna.** `libargon2` na lh.pl zbudowane bez obsługi wątków.

**Naprawa.** `ARGON_THREADS=1` w `.env`, koszt zrekompensowany pamięcią i przebiegami.
**Konta założone wcześniej trzeba utworzyć na nowo** — parametry są częścią zapisu
hasha, a stary hash z `p=2` nie da się na tym hostingu zweryfikować.

```bash
php app/bin/create_user.php operator@example.com "Operator"
```

## Po wdrożeniu znika katalog `app/`

**Objaw.** Strona zwraca 500, w logu `bootstrap.php` nie istnieje.

**Przyczyna.** Drugi `rsync --delete` synchronizuje `app/public/` do katalogu domeny.
Bez `--exclude` uznaje `app/`, `.env` i `storage/` za nadmiarowe i **kasuje je**,
razem z kodem wgranym pierwszym przejściem.

**Naprawa.** Wykluczenia w obu wywołaniach `rsync`. Błąd wystąpił dwa razy
(naprawiony w `01b1dc7`, cofnięty w `73eecb7`), więc pilnuje go teraz test.

## „Operation not permitted" przy starcie aplikacji

**Objaw.** Biała strona, w logu `require(): open_basedir restriction in effect`.

**Przyczyna.** `app/public/index.php` w repozytorium leży piętro niżej niż w produkcji.
`dirname(__DIR__)` wskazywał `~/public_html`, czyli poza `open_basedir`.

**Naprawa.** Stała `CA_ROOT` ustalana przez odnalezienie katalogu z `app/src/bootstrap.php`.
Test pilnuje, że `dirname(__DIR__)` nie wróci do tego pliku.
