# Ograniczenia hostingu lh.pl (Mango) — wynik bramki technicznej

**Data weryfikacji:** 2026-08-10 · Serwer `serwer400227.lh.pl`, port 40022

## Wynik bramki

| Element | Stan | Uwagi |
|---|---|---|
| Python | **3.11.2** | `/usr/bin/python3.11` |
| PHP | **8.3.33** | CLI, `max_execution_time=0`, `memory_limit=256M` |
| `proc_open` | **tylko CLI** | Bramkę robiono przez SSH, czyli w CLI. Na PHP-FPM ta funkcja jest na liście `disable_functions` — patrz niżej. Silnik uruchamia wyłącznie cron |
| phpredis | **załadowane** | Połączenie do serwera Redis do potwierdzenia |
| Redis | **działa** | Gniazdo uniksowe `/usr/local/redis/sockets/serwer400227.sock`, port `0`. Host/port TCP odmawia połączenia — to normalne |
| MariaDB | **10.11** | Nie MySQL. `SKIP LOCKED` dostępne (od 10.6), `JSON` to alias `LONGTEXT` |
| git, mysql, mysqldump | są | Wdrożenie przez `git pull` możliwe |
| `crontab` z konsoli | **brak** | `/var/spool/cron` nie istnieje — cron konfigurowany w panelu |
| Struktura WWW | `~/public_html/{domena}` | Subdomena = osobny katalog obok domeny głównej, nie podkatalog |
| venv | działa z obejściem | `--without-pip` + `get-pip.py`; pip wołany przez `python -m pip` |
| **Zależności kompilowane** | **NIEMOŻLIWE** | patrz niżej |

## Blokada: `noexec` na katalogu domowym

```
/dev/vda3 on /home/platne/serwer400227 type ext4 (rw,nosuid,nodev,noexec,noatime,...)
```

Konsekwencja: pliki `.so` w katalogu domowym **nie dają się załadować**. Objaw przy próbie importu:

```
ImportError: ... _multiarray_umath.cpython-311-x86_64-linux-gnu.so:
failed to map segment from shared object
```

Systemowego `numpy` na koncie nie ma, więc nie ma z czego skorzystać zamiast lokalnej instalacji.

**To nie jest problem uprawnień pliku i nie da się go naprawić przez `chmod`.** To sposób zamontowania
systemu plików przez hosting.

## Decyzja architektoniczna

**Silnik `coachanalyze` korzysta wyłącznie z biblioteki standardowej Pythona.**

Zakazane w kodzie silnika: `pandas`, `numpy`, `lxml`, `Pillow`, `python-pptx`, `matplotlib`
i każda inna paczka z rozszerzeniem w C.

Dozwolone i wystarczające przy tej skali danych (kilkaset zdarzeń na mecz):

| Zamiast | Używamy |
|---|---|
| `pandas.read_csv` | `csv.DictReader` — obsługuje cytowane pola, czyli pułapkę pola `labels` |
| ramki danych | listy słowników |
| `numpy` (statystyki) | `statistics`, `math` |
| operacje na kolorach | arytmetyka na `float`, tak jak w dotychczasowym `to_hex` |

Zysk poza samym odblokowaniem: szybszy start workera (brak importu ciężkich paczek przy każdym
zadaniu z crona), brak instalacji do utrzymania, prostsza migracja na VPS.

Koszt: refaktor w Etapie 2 przepisuje operacje na ramkach na listy słowników.
**Test złoty pilnuje, żeby liczby pozostały identyczne** — dokładnie po to istnieje.

## Wpływ na moduły — planować z wyprzedzeniem

| Moduł | Wpływ |
|---|---|
| BAZA, M1, M2, M4, M5 | Bez wpływu — czysta logika i tekst |
| M3 (xG ze współrzędnych) | Bez wpływu — model regresyjny da się policzyć na `math` |
| **M6 (eksport PPTX)** | **Blokada.** `python-pptx` wymaga `lxml` i `Pillow`, obie kompilowane |
| M7 | Bez wpływu, o ile wykresy generuje istniejący render SVG, a nie `matplotlib` |

**M6 wymusi przeniesienie silnika na VPS** albo osobną usługę generującą PPTX poza tym hostingiem.
Decyzja zapada przed zamówieniem modułu, nie w jego trakcie. Koszt VPS (60–150 zł/mies.)
jest już ujęty w kosztach po stronie klienta.

## Renderowanie obrazów

Jeśli kiedykolwiek pojawi się potrzeba rastrowania wykresów (miniatury raportów, PPTX),
nie robimy tego w Pythonie na tym hostingu. Opcje: generowanie SVG i konwersja po stronie
przeglądarki, albo usługa zewnętrzna, albo VPS.

## Do domknięcia

- [ ] Połączenie do Redis przez phpredis (`127.0.0.1:6379` lub gniazdo uniksowe)
- [ ] Dostępność `cron`, `git`, `mysql` na koncie
- [ ] Faktyczna kwota dyskowa konta (wolumen współdzielony pokazuje 85% zajętości)


## Cron: konfigurowany w panelu, nie przez `crontab`

`crontab -l` zwraca `/var/spool/cron: No such file or directory`. Zadania cykliczne ustawia się
w panelu klienta, a minimalny interwał bywa dłuższy niż minuta.

**Konsekwencja architektoniczna — cron jest ścieżką PODSTAWOWĄ, nie siatką bezpieczeństwa.**

Pierwotny plan zakładał, że PHP uruchomi silnik natychmiast po wgraniu eksportu
(`proc_open` z procesem w tle), a cron będzie tylko ponawiał to, co padło. Ten plan upadł:
PHP-FPM ma `proc_open` na liście `disable_functions` (patrz sekcja na końcu dokumentu),
więc z przeglądarki nie da się uruchomić niczego.

Warstwa żądań zapisuje plik i wstawia zadanie ze statusem `queued`. Wykonaniem zajmuje się
`app/bin/run_job.php`, uruchamiany z crona **co minutę** — to najkrótszy interwał, jaki daje
panel lh.pl. Użytkownik dostaje stronę stanu odświeżaną przez `<meta http-equiv="refresh">`
i komunikat, że raport powstaje w tle i można wrócić później.

Minuta oczekiwania jest ceną tego hostingu. Na VPS-ie cron zastąpi demon, a opóźnienie
spadnie poniżej sekundy — bez zmiany reszty architektury.


## `open_basedir` — aplikacja webowa musi mieszkać w katalogu domeny

Zweryfikowane 2026-08-10. PHP-FPM obsługujący stronę ma `open_basedir` ograniczony do:

```
/home/platne/serwer400227/public_html/app.coachanalyze.pl/
/home/platne/serwer400227/tmp/
/usr/local/php84-fpm/lib/php/
/tmp  /home/tmp  /var/lib/php5
```

Próba `require` pliku spoza tej listy kończy się `Operation not permitted`.
Panel lh.pl **nie udostępnia** opcji rozszerzenia tej listy, a lokalny `php.ini`
w katalogu domeny jest przy FPM ignorowany.

**Kluczowe rozróżnienie:** ograniczenie dotyczy WYŁĄCZNIE PHP-FPM (żądania z przeglądarki).

| Warstwa | `open_basedir` |
|---|---|
| PHP-FPM — obsługa żądań | ograniczony do katalogu domeny |
| PHP CLI — worker, cron, deploy | **brak ograniczeń** (`php -i` → `no value`) |
| Python — silnik | **brak ograniczeń** |

Dlatego silnik uruchamiany z crona działa normalnie, mimo że leży poza katalogiem domeny.
Z przeglądarki nie da się go uruchomić w ogóle — ale nie przez `open_basedir`, tylko przez
`disable_functions` (osobna sekcja na końcu dokumentu). To dwa niezależne ograniczenia
i mylenie ich prowadzi diagnostykę w złą stronę.

### Wynikający układ katalogów

```
~/CoachAnalyze/                      poza zasięgiem PHP-FPM
├── repo/                            źródło (git) — stąd startuje cron
├── venv/                            silnik Pythona
└── shared/
    ├── .env                         wzorzec sekretów
    └── backups/

~/tmp/                               na liście open_basedir — logi aplikacji i crona

~/public_html/app.coachanalyze.pl/   synchronizowany przy wdrożeniu
├── index.php  assets/  .htaccess    publiczne
├── app/                             kod — .htaccess: Require all denied
├── .env                             ZWYKŁY PLIK, kopiowany przy wdrożeniu
└── storage/                         PRAWDZIWY KATALOG — .htaccess: Require all denied
```

**Symlink całego katalogu domeny nie działa** — hosting za nim podąża (sprawdzone),
ale `open_basedir` i tak blokuje pliki spoza listy. Dlatego `deploy.sh` **synchronizuje**
pliki przez `rsync`, zamiast przepinać dowiązanie.

**Dowiązania pojedynczych zasobów też nie działają** — ani przy odczycie, ani przy zapisie
(osobna sekcja niżej). Wcześniejszy układ z `storage/ -> ~/CoachAnalyze/shared/storage`
przechodził wdrożenie i wywalał się dopiero przy pierwszym uploadzie. Dlatego `.env` jest
kopiowany jako zwykły plik, a `storage/` jest prawdziwym katalogiem w drzewie domeny.
Poufności nie daje położenie poza drzewem, tylko `.htaccess` — i to jest sprawdzane
po każdym wdrożeniu.

### Ochrona plików

Skoro kod leży w drzewie webowym, ochronę zapewniają trzy warstwy `.htaccess`:

1. `app/public/.htaccess` — `RewriteRule ^(app|storage|...)/ - [F,L]` jako pierwsza reguła
2. `app/.htaccess` — `Require all denied`
3. `storage/.htaccess` — `Require all denied`

`deploy.sh` po każdym wdrożeniu **sprawdza automatycznie**, czy te ścieżki zwracają 403.
Wdrożenie, które wystawia kod publicznie, ma być widoczne od razu, a nie odkryte przez kogoś innego.


## Blokada: `libargon2` bez obsługi wątków

Zweryfikowane na serwerze. `password_hash` z `PASSWORD_ARGON2ID` i parametrem `threads` większym
niż 1 kończy się błędem:

```
A thread value other than 1 is not supported by this implementation
```

`libargon2` na lh.pl jest zbudowane bez wsparcia dla wielu wątków (brak `ARGON2_NO_THREADS`
po stronie budowania biblioteki). Dotyczy to **wszystkich** wywołań: haszowania i weryfikacji.

### Konsekwencja

`threads` musi zostać **1**. Utratę równoległości rekompensujemy pozostałymi parametrami —
w argon2 koszt to w przybliżeniu iloczyn pamięci i liczby przebiegów, a `p` dzieli tę pracę
na pasma. Zmierzone na maszynie deweloperskiej (PHP 8.5):

| `memory_cost` | `time_cost` | `threads` | Czas weryfikacji |
|---|---|---|---|
| 65536 (64 MB) | 4 | 2 | 64 ms — konfiguracja sprzed poprawki |
| 65536 (64 MB) | 4 | 1 | 122 ms |
| 65536 (64 MB) | 6 | 1 | 188 ms |
| **98304 (96 MB)** | **5** | **1** | **237 ms** — wartości domyślne |
| 131072 (128 MB) | 4 | 1 | 256 ms |

Warto odnotować, że samo zejście z `p=2` na `p=1` **podnosi** koszt, a nie obniża: przy jednym
paśmie ten sam obszar pamięci jest przetwarzany sekwencyjnie. Podniesienie pamięci i liczby
przebiegów jest więc zapasem, a nie łataniem straty.

Parametry siedzą w `.env` (`ARGON_MEMORY_COST`, `ARGON_TIME_COST`, `ARGON_THREADS`) i dają się
podnieść bez wydania nowej wersji aplikacji. Pamięć jest liczona w KiB.

### Pułapka przy migracji

Hasła zapisane **przed** tą zmianą mają w zapisie hasha `p=2`. Na tym hostingu **nie dadzą się
zweryfikować** — parametry są częścią zapisu, a biblioteka odmówi tak samo jak przy haszowaniu.
Automatyczne przehaszowanie przy logowaniu (`password_needs_rehash`) tu nie pomoże, bo wymaga
udanej weryfikacji.

**Konto operatora założone przed poprawką trzeba utworzyć na nowo:**

```bash
php app/bin/create_user.php operator@example.com "Operator"
```

Skrypt nadpisuje hash istniejącego konta, więc adres i rola zostają bez zmian.

### Sprawdzenie na serwerze

```bash
php -r 'try { password_hash("x", PASSWORD_ARGON2ID, ["threads"=>2]); echo "wątki OK\n"; }
        catch (Throwable $e) { echo "brak wątków: ", $e->getMessage(), "\n"; }'
```


## Pułapka: `is_file()` kłamie na ścieżkach spoza `open_basedir`

Zweryfikowane przez FPM na lh.pl. **Sprawdzenie istnienia pliku podlega `open_basedir`,
a samo otwarcie zasobu już nie.** Dla ścieżki spoza listy `is_file()` zwraca `false`
nawet wtedy, gdy zasób jest w pełni osiągalny:

```php
is_file('/usr/local/redis/sockets/serwer400227.sock');   // false
$r = new Redis();
$r->connect('/usr/local/redis/sockets/serwer400227.sock'); // przechodzi
$r->ping();                                               // 1
```

To samo dotyczy `file_exists()` i `is_readable()`.

### Konsekwencja: nie pytamy o istnienie, tylko próbujemy

Wstępne sprawdzenie „czy plik jest" kłamie dokładnie tam, gdzie miałoby pomóc,
i zamienia działający zasób w niedostępny. W warstwie wejścia aplikacji nie ma
więc takich sprawdzeń — wykonujemy operację i obsługujemy niepowodzenie:

| Zasób | Zamiast | Robimy |
|---|---|---|
| gniazdo Redis | `is_file()` przed połączeniem | `@stream_socket_client()`, wyjątek przy porażce |
| plik `.env` | `is_file()` / `is_readable()` | `@file_get_contents()`, `false` znaczy „nie ma" |

Konkretny objaw, po którym to wyszło: ekran logowania odpowiadał „Logowanie jest
chwilowo niedostępne". `Config` szukał `.env`, sprawdzając istnienie pliku w kolejnych
katalogach nadrzędnych; sprawdzenia zwracały `false`, konfiguracja nie wczytywała się wcale,
a `Config::require('REDIS_SOCKET')` rzucał wyjątkiem **zanim cokolwiek spróbowało
połączyć się z Redisem**. Zabezpieczenie „fail closed" z limitera prób zadziałało
na podstawie nieudanego `is_file`, a nie nieudanego połączenia.

### Konsekwencja: `.env` z jawnej ścieżki

Przeszukiwanie drzewa w górę oznacza, że część sprawdzanych katalogów leży poza
`open_basedir`. Każde takie sprawdzenie to ostrzeżenie PHP — przy czterech poziomach
trzy ostrzeżenia wypisane nad formularzem logowania. Położenie `.env` bierze się dziś
z jednej stałej (`CA_ROOT . '/.env'`), a odczyt jest wyciszony.

### Konsekwencja: `display_errors` wyłączane od pierwszej instrukcji

Ostrzeżenia powstawały w trakcie wczytywania konfiguracji, czyli **zanim** kod zdążył
ustawić `display_errors` na podstawie tej konfiguracji. Bootstrap wyłącza je teraz
w pierwszych liniach, a włącza z powrotem tylko poza produkcją i tylko przy `APP_DEBUG`.
Docelowy plik logu (`LOG_PATH`) podłącza się później, gdy konfiguracja jest już znana.

Pilnuje tego `app/tests/test_layout.php`.


## Pułapka: dowiązania poza `open_basedir` są dla FPM niewidoczne

Wynika wprost z poprzedniej sekcji, ale kosztowało osobną awarię, więc dostaje własną.
**`open_basedir` sprawdza ścieżkę PO ROZWINIĘCIU dowiązania.** Symlink leżący w katalogu
domeny, którego cel jest poza listą, nie daje FPM żadnego dostępu — **ani do odczytu,
ani do zapisu**. Z konsoli wszystko wygląda poprawnie, bo CLI nie ma tego ograniczenia.

Pierwotny układ zakładał dwa dowiązania:

```
{domena}/.env      -> ~/CoachAnalyze/shared/.env        # FPM nie odczyta
{domena}/storage/  -> ~/CoachAnalyze/shared/storage     # FPM nie zapisze
```

Oba zostały zastąpione:

| Zasób | Było | Jest |
|---|---|---|
| `.env` | dowiązanie do `shared/.env` | **kopia** w katalogu domeny (`cp` przy wdrożeniu, `chmod 640`) |
| `storage/` | dowiązanie do `shared/storage` | **prawdziwy katalog** w drzewie domeny |
| log aplikacji | `shared/logs/app.log` | `~/tmp/coachanalyze.log` — `~/tmp` jest na liście `open_basedir` |

Źródłem prawdy dla `.env` zostaje `~/CoachAnalyze/shared/.env`; do katalogu domeny trafia
jego kopia przy każdym wdrożeniu. `storage/` i `.env` są wykluczone z obu przejść `rsync`,
więc przeżywają wdrożenie.

**Konsekwencja dla kopii zapasowych:** dane użytkownika nie leżą już w `shared/`.
Kopia musi obejmować `{domena}/storage/`.

### Cichy log to najgorszy z możliwych stanów

`LOG_PATH` wskazujący katalog spoza `open_basedir` sprawia, że `error_log()` nie ma dokąd
pisać. Aplikacja zgłasza wtedy użytkownikowi ogólny komunikat, a przyczyna nie zostaje
nigdzie — pierwsza awaria produkcyjna zajęła przez to godzinę.

Bootstrap sprawdza więc zapisywalność **próbą zapisu** (`is_writable` kłamie na tym
hostingu tak samo jak `is_file`), a przy niepowodzeniu schodzi do katalogu tymczasowego
i odnotowuje ten fakt. Log w nieoczywistym miejscu jest lepszy niż brak logu.

### Kontrola przy wdrożeniu

`deploy.sh` sprawdza po każdym wdrożeniu i **kończy się kodem 1**, gdy coś się nie zgadza:

- `.env` jest zwykłym plikiem, nie dowiązaniem,
- `storage/` jest katalogiem, nie dowiązaniem, i jest zapisywalny,
- katalog z `LOG_PATH` istnieje i daje się do niego zapisać,
- `/.env`, `/storage/`, `/storage/uploads/` i `/app/src/bootstrap.php` zwracają 403.

---

## Blokada: `disable_functions` na PHP-FPM — warstwa żądań nie uruchamia procesów

**Wykryte na produkcji 2026-08-11.** Bramka techniczna tego nie złapała, bo była robiona
przez SSH, a `php -i` w CLI pokazuje inną konfigurację niż ta, z którą chodzi FPM.
To jest wzorzec do zapamiętania: **wszystko, co dotyczy warstwy żądań, trzeba sprawdzić
w przeglądarce, nie w konsoli.**

Objaw w logu:

```
PHP Fatal error: Uncaught Error: Call to undefined function CoachAnalyze\proc_open()
```

„Undefined", nie „disabled" — funkcja wyłączona przez `disable_functions` znika z przestrzeni
nazw, więc komunikat sugeruje brakujące rozszerzenie i prowadzi diagnostykę w złą stronę.

### Pełna lista wyłączonych funkcji (PHP-FPM, zweryfikowana na serwerze)

```
exec, system, passthru, shell_exec, proc_close, proc_open, dl, popen, show_source,
posix_kill, posix_mkfifo, posix_getpwuid, posix_setpgid, posix_setsid,
posix_setuid, posix_setgid, posix_seteuid, posix_setegid, posix_uname,
opcache_reset, opcache_invalidate, opcache_compile_file,
opcache_get_configuration, opcache_get_status
```

**W PHP CLI żadna z nich nie jest wyłączona.** Cron i ręczne uruchomienia działają normalnie.

### Wniosek: FPM nie uruchamia żadnego procesu

Nie da się tego obejść — nie ma zapasowej funkcji, którą przeoczono. `popen`, `system`
i `passthru` są na liście razem z `proc_open`, a `pcntl` nie jest na tym hostingu dostępne.
Architektura musiała się do tego dostosować, a nie znaleźć furtkę.

| Warstwa | Rola | Uruchamia Pythona |
|---|---|---|
| PHP-FPM (przeglądarka) | zapis pliku, `INSERT` do `jobs` ze statusem `queued`, wyświetlenie stanu | **nie, nigdy** |
| PHP CLI (cron, co minutę) | pobranie zadania, uruchomienie silnika, zapis wyniku | tak, to jego jedyne zadanie |

Warstwa żądań **wyłącznie kolejkuje**. `Engine::inspect()` też — nie ma ścieżki „szybkiej",
która omijałaby kolejkę, bo taka ścieżka wywalała się wyjątkiem *przed* zapisem zadania
i tabela `jobs` zostawała pusta: nie było nawet czego ponowić.

### Granica jest fizyczna, nie umowna

Kod uruchamiający procesy leży w `app/bin/EngineRunner.php` — **poza drzewem autoloadera**.
Warstwa żądań nie ma jak go zawołać, bo autoloader go nie znajdzie, a `require` musi być
napisany ręcznie. Wcześniejszy wariant ze sprawdzeniem `PHP_SAPI !== 'cli'` w czasie
wykonania był słabszy: wystarczyło jedno nowe wywołanie z kontrolera, żeby błąd wrócił,
i to znowu dopiero na produkcji.

`app/src/Engine.php` w warstwie żądań **czyta gotowe artefakty**, których nie tworzy —
`version()` bierze wersję silnika z pliku zapisanego przez crona, a wyniki inspekcji
i renderu pochodzą z bazy i z plików JSON zapisanych przez CLI.

Pilnuje tego `app/tests/test_disabled_functions.php`: skanuje `app/public/index.php`
oraz całe `app/src/**` **tokenizerem** (`token_get_all`), nie `grep`-em. Wyszukiwanie
tekstowe nie nadaje się, bo komentarze w tym projekcie wymieniają te funkcje z nazwy —
wyjaśniają właśnie to, dlaczego ich nie używamy. Test ma też autotesty samego skanera:
udowadnia, że wykrywa prawdziwe wywołanie i że ignoruje komentarz, napis oraz metodę
o tej samej nazwie. Bez tego zielony wynik nie znaczyłby nic.

### Skutki uboczne, o których łatwo zapomnieć

- **`opcache_reset` jest wyłączone**, więc wdrożenie nie może wyczyścić cache kodu.
  Nowe pliki wchodzą po wygaśnięciu `opcache.revalidate_freq`. Przy pilnej poprawce
  zostaje restart puli PHP z panelu klienta.
- **`opcache_get_status` jest wyłączone**, więc żadna diagnostyka nie może na nim polegać.
- Rozszerzenia PHP wymagające `dl()` odpadają — i tak są niemożliwe przez `noexec`.

### To ograniczenie znika na Cloud Server

`disable_functions` to decyzja operatora współdzielonego hostingu, nie ograniczenie
techniczne. Na VPS-ie (tym samym, który jest już potrzebny dla modułu M6 — eksport PPTX,
patrz „Wpływ na moduły") lista jest pusta i **zmianę da się cofnąć**: wystarczy przywrócić
uruchamianie silnika zaraz po wgraniu eksportu.

Cofać jednak nie warto bez powodu. Kolejka daje rzeczy, których natychmiastowe uruchamianie
nie dawało: ponowienia, widoczny stan zadania, limit równoległości, historię błędów.
Na VPS-ie sensowniejsze jest zostawienie kolejki i zastąpienie crona demonem —
zysk to opóźnienie startu poniżej sekundy zamiast do minuty.
