# Testy integracyjne

Zestawy dotykające bazy, kolejki, poczty i pełnych ścieżek HTTP. **Nie są wpięte
w CI** — wymagają atrap i środowiska (Redis przez gniazdo, venv Pythona,
czasem otwartego portu). Uruchamiane ręcznie przy pracy nad daną częścią.

Testy z `app/tests/*.php` (bez tego podkatalogu) są statyczne, nie mają zależności
i **to one chodzą w CI**. Gdy coś tutaj wykryje usterkę, warto zapytać, czy da się
jej pilnować także statycznie — tak powstały `test_sql_parametry`, `test_bramki_rol`
i `test_komunikaty_sesji`.

## Uruchamianie — wszystko naraz

```bash
bash app/tests/integracja/uruchom.sh              # statyczne + integracyjne
bash app/tests/integracja/uruchom.sh --bez-http   # bez przelotów HTTP (szybciej)
bash app/tests/integracja/uruchom.sh --tylko-statyczne
```

Skrypt sam podnosi atrapę Redisa, podaje ją zestawom, które jej wymagają,
i uruchamia wszystko w kolejności od najtańszego do najbardziej zależnego od
środowiska. Kończy jednym wynikiem zbiorczym i kodem wyjścia: `0` wszystko
zielone, `1` jakiś zestaw nie przeszedł, `2` zły argument.

**Pominięcia są głośne.** Brak `node` albo venv nie wywala przebiegu, ale ląduje
w podsumowaniu jako POMINIĘTY, z powodem. Zestaw cicho pominięty jest gorszy
niż zestaw czerwony, bo wygląda jak zielony.

Stan wyjściowy przy pisaniu tego pliku: **37 zestawów, 1533 asercje, 0 błędów**.

## Uruchamianie pojedynczo

```bash
cd app/tests/integracja

# Zestawy bez zależności
php test_mapowania.php
php test_indeks.php
php test_xg.php
php test_kluby_templaty.php   # Sesja 1 przebudowy: tenant, templaty, tagi ignorowane
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

# Reset i zmiana hasła przez PRAWDZIWY HTTP: serwer PHP, atrapy Redisa i SMTP,
# cron. Sprawdza m.in. identyczność odpowiedzi dla adresu istniejącego
# i nieistniejącego oraz to, że surowy token nie istnieje poza mailem.
php test_haslo_http.php

# Sesja i token CSRF przez PRAWDZIWY HTTP: logowanie bez żadnej sesji na
# wejściu, komunikaty przy braku ciasteczka, przy sesji wygasłej i przy
# faktycznie starym formularzu. Podnosi DWA serwery — drugi z APP_URL po
# HTTPS, żeby odtworzyć ciasteczko `Secure` odrzucane przy żądaniu bez
# szyfrowania (objaw zgłoszony z produkcji).
php test_sesja_http.php

# Protokół SMTP przeciw atrapie serwera; certyfikat wytwarza sam test
php test_smtp.php

# Regeneracja raportów pod aktualny templat (Sesja 7). Jedyny zestaw, który
# sprawdza ATOMOWOŚĆ podmiany: kolejkuje przeliczenie, psuje eksport w locie
# i pilnuje, żeby pod adresem publicznym dalej leżał STARY raport, bajt w bajt.
PYTHONPATH=../../../engine php test_przelicz_http.php

# Wskaźnik pracy kolejki i chmurka wyniku. Sprawdza m.in. że punkty stanu bez
# sesji dają 404 (a nie przekierowanie, które fetch wykonałby po cichu), że
# wskaźnik jest w HTML-u z serwera, że po trzech minutach w kolejce pojawia się
# „trwa dłużej niż zwykle" i że partia daje JEDNĄ chmurkę zamiast N.
PYTHONPATH=../../../engine php test_wskaznik_http.php

# Skrypt chmurek na atrapie DOM
node test_chmurki.js
```

## Atrapy

| Plik | Do czego |
|---|---|
| `seed.php` | schemat SQLite odwzorowujący migracje 001–013 + dane przykładowe |
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
