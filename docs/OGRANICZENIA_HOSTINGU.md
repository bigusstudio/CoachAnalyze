# Ograniczenia hostingu lh.pl (Mango) — wynik bramki technicznej

**Data weryfikacji:** 2026-08-10 · Serwer `serwer400227.lh.pl`, port 40022

## Wynik bramki

| Element | Stan | Uwagi |
|---|---|---|
| Python | **3.11.2** | `/usr/bin/python3.11` |
| PHP | **8.3.33** | CLI, `max_execution_time=0`, `memory_limit=256M` |
| `proc_open` | **dostępne** | PHP może uruchamiać Pythona — architektura workera stoi |
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

**Konsekwencja architektoniczna — ścieżka podstawowa nie czeka na cron.** Skoro `proc_open` działa,
PHP uruchamia silnik natychmiast po wgraniu eksportu, jako proces w tle:

```php
proc_open("nohup {$python} -m coachanalyze build ... > /dev/null 2>&1 &", $desc, $pipes);
```

Użytkownik dostaje stronę statusu odświeżaną co kilka sekund; raport pojawia się po kilkudziesięciu.

Cron zostaje wyłącznie jako siatka bezpieczeństwa: ponowienie zadań zakończonych błędem
i sprzątanie zawieszonych. W tej roli interwał 15 minut jest w zupełności wystarczający.


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

Dlatego silnik uruchamiany przez `proc_open` działa normalnie, mimo że leży poza katalogiem domeny.

### Wynikający układ katalogów

```
~/CoachAnalyze/                      poza zasięgiem PHP-FPM
├── repo/                            źródło (git)
├── venv/                            silnik Pythona
└── shared/
    ├── .env                         sekrety
    ├── storage/                     uploady, raporty, herby
    └── backups/

~/public_html/app.coachanalyze.pl/   synchronizowany przy wdrożeniu
├── index.php  assets/  .htaccess    publiczne
├── app/                             kod — .htaccess: Require all denied
├── .env      -> ~/CoachAnalyze/shared/.env
└── storage/  -> ~/CoachAnalyze/shared/storage
```

**Symlink całego katalogu domeny nie działa** — hosting za nim podąża (sprawdzone),
ale `open_basedir` i tak blokuje pliki spoza listy. Dlatego `deploy.sh` **synchronizuje**
pliki przez `rsync`, zamiast przepinać dowiązanie.

Symlinki pojedynczych zasobów (`.env`, `storage/`) działają, bo ich cel jest odczytywany
przez CLI i Pythona, nie przez FPM.

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
