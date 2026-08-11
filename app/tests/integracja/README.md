# Testy integracyjne

Zestawy dotykające bazy, kolejki, poczty i pełnych ścieżek HTTP. **Nie są wpięte
w CI** — wymagają atrap i środowiska (Redis przez gniazdo, venv Pythona,
czasem otwartego portu). Uruchamiane ręcznie przy pracy nad daną częścią.

Testy z `app/tests/*.php` (bez tego podkatalogu) są statyczne, nie mają zależności
i **to one chodzą w CI**.

## Uruchamianie

```bash
cd app/tests/integracja

# Zestawy bez zależności
php test_mapowania.php
php test_powiadomienia.php
php test_4a.php  test_4b.php  test_4c.php  test_7.php  test_remember.php

# Wymagają atrapy Redisa (limiter logowania jest „fail closed")
php fake_redis.php /tmp/ca.sock &
php test_etap3.php /tmp/ca.sock
php test_konta.php /tmp/ca.sock

# Wymagają silnika Pythona (PYTHONPATH, bo venv bywa bez zainstalowanej paczki)
PYTHONPATH=../../../engine php test_kolejka.php

# Przelot całej ścieżki importu przez PRAWDZIWY HTTP: wbudowany serwer PHP,
# atrapa Redisa (uruchamia sam), cron i prawdziwy silnik. Jedyny zestaw, który
# łapie klasę błędu „testy zielone, funkcja nieosiągalna z interfejsu".
php test_mapowania_http.php

# Protokół SMTP przeciw atrapie serwera; certyfikat wytwarza sam test
php test_smtp.php

# Skrypt chmurek na atrapie DOM
node test_chmurki.js
```

## Atrapy

| Plik | Do czego |
|---|---|
| `seed.php` | schemat SQLite odwzorowujący migracje 001–008 + dane przykładowe |
| `fake_redis.php` | RESP przez gniazdo uniksowe, wiele połączeń naraz |
| `fake_smtp.php` | SMTP i SMTPS (tryb `smtps` wymaga pliku PEM) |

## Dane testowe

Pliki `*.csv` są **syntetyczne** — napisane na potrzeby testów, nie pochodzą
od klienta. Nazwy klubów są neutralne (`KLUB A`, `KLUB B`).

**Do tego katalogu nie wolno wkładać eksportów klienta** — są danymi taktycznymi
(CLAUDE.md §7). Jeśli test potrzebuje nowego przypadku, dopisz syntetyczny wiersz.

## Uwaga o SQLite

Te zestawy chodzą na SQLite, a produkcja na MariaDB. Różnic jest kilka i jedna
kosztowała już awarię: **powtórzony symbol nazwany** (`:x` dwa razy w jednym
zapytaniu) działa na SQLite i wywala się na MySQL komunikatem
`Invalid parameter number`. Żaden przebieg tych testów tego nie złapie —
pilnuje tego `app/tests/test_sql_parametry.php` (statyczny, w CI).
