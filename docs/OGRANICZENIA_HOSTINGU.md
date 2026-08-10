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
