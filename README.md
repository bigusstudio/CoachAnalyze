# CoachAnalyze

Analityka meczowa dla klubów piłkarskich. Przetwarza eksporty z LiveTag.Pro na raporty taktyczne
i interaktywne dashboardy.

**Wykonawca:** bigus.studio · **Serwer:** lh.pl (Mango) · **Repozytorium prywatne.**

> Przed pierwszą zmianą w kodzie przeczytaj **[CLAUDE.md](CLAUDE.md)** — zamrożone decyzje,
> pułapki formatu i reguła testu złotego. Kilka rzeczy, które wyglądają na nadmiarowe, nimi nie są.

## Struktura

```
app/       PHP 8.2 — warstwa webowa: sesja, upload, kolejka, panel, publikacja
engine/    Python 3.11 — silnik: parsowanie, model kanoniczny, metryki, render
deploy/    nginx, cron, deploy.sh
docs/      kontrakty i specyfikacje — źródło prawdy dla obu warstw
```

Granica jest ostra i celowa: **PHP nie liczy metryk, Python nie zna sesji.**
Kontakt wyłącznie przez CLI i pliki JSON — dzięki temu silnik da się uruchomić z palca
i porównać wynik. Na tym stoi test złoty.

## Dokumenty

| Plik | Zawartość |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Decyzje zamrożone, pułapki, reguły pracy |
| [docs/KONTRAKT_CLI.md](docs/KONTRAKT_CLI.md) | Styk PHP ↔ Python: wywołanie, `meta.json`, kody wyjścia |
| [docs/MODEL_KANONICZNY.md](docs/MODEL_KANONICZNY.md) | Ontologia zdarzeń, profile mapowań |
| [docs/FORMAT_LIVETAG.md](docs/FORMAT_LIVETAG.md) | Empiryczna specyfikacja eksportu |
| [docs/RUNBOOK.md](docs/RUNBOOK.md) | Co robić, gdy coś padnie |

## Start lokalny

```bash
# Silnik
cd engine
python3.11 -m venv ../venv && source ../venv/bin/activate
pip install -e ".[dev]"
python -m coachanalyze --version
python -m pytest

# Baza
mysql -u root coachanalyze < app/migrations/001_init.sql

# Konfiguracja
cp .env.example .env    # uzupełnić
```

## Adresy

| Adres | Przeznaczenie |
|---|---|
| `domena.com` | WordPress — wyłącznie landing sprzedażowy |
| `app.domena.com` | Aplikacja |
| `app.domena.com/r/{club_key}/{token}` | Raport publiczny, read-only, odwoływalny |

`club_key` to stały, losowy klucz klubu — nazwa klubu nie pojawia się w adresie.
Przy złym kluczu i przy złym tokenie zwracamy **identyczne 404**.

## Kolejka zadań

Warstwa żądań wyłącznie **kolejkuje** — PHP-FPM na lh.pl nie może uruchomić procesu
(`disable_functions`, patrz `docs/OGRANICZENIA_HOSTINGU.md`). Wykonaniem zajmuje się
`app/bin/run_job.php`, uruchamiany z crona co minutę.

| Typ zadania | Co robi | Skutek uboczny |
|---|---|---|
| `inspect` | raport pokrycia bez renderu | zapis `imports.coverage_json` |
| `build_report` | pełny render HTML | nowy wiersz w `reports`, powiadomienie |
| `send_mail` | wysyłka jednego powiadomienia | zmiana `notifications.mail_status` |

`send_mail` jest **osobnym typem zadania**, a nie doczepką do renderu. Serwer poczty
bywa wolny i bywa niedostępny; gdyby wysyłka siedziała w zadaniu renderu, awaria SMTP
oznaczałaby „raport się nie wygenerował" i uruchamiała ponawianie ciężkiej pracy silnika.

Kolumna `jobs.available_at` odsuwa zadanie w czasie. Używa jej mail „przetwarzanie
w toku", który ma pójść dopiero, gdy przetwarzanie faktycznie się przeciąga —
a przed samą wysyłką worker sprawdza jeszcze, czy powód nadal obowiązuje.

## Powiadomienia

Chmurki w rogu ekranu, na każdej stronie panelu, w trzech odmianach: **gotowe**,
**w toku**, **błąd**. Powiadomienie o gotowym raporcie prowadzi wprost do niego.
Zamknięcie oznacza jako odczytane; po kilkunastu sekundach chmurka znika sama,
ale **nie** oznacza się jako odczytana — to, czego ktoś nie zdążył przeczytać,
ma zostać w liczniku.

Renderuje je serwer, a `app/public/assets/powiadomienia.js` je **przyspiesza**:
odpytuje `GET /powiadomienia/nowe` i dorysowuje nowe bez przeładowania.
Przy wyłączonym JavaScripcie panel działa bez zmian, tylko chmurki pojawiają się
przy przeładowaniu strony. To jedyny skrypt w panelu i jedyne zatwierdzone
odstępstwo od zasady „zero skryptów" — granice opisuje **CLAUDE.md §9**,
a pilnuje ich `app/tests/test_chmurki.php`.

W aplikacji działają **zawsze**, mailem **tylko gdy SMTP jest skonfigurowany**.
Puste `SMTP_HOST` albo `MAIL_FROM` wyłącza warstwę mailową po cichu — bez błędu,
bez zmian w kodzie, bez wpływu na powiadomienia w panelu.

Poczta idzie własnym klientem SMTP (`app/src/Mailer.php`) przez `stream_socket_client`,
bez bibliotek zewnętrznych — ta sama zasada, która wyrzuciła `pandas` z silnika
i rozszerzenie `redis` z warstwy PHP.

`SMTP_ENCRYPTION` wybiera protokół i jest to wybór **jawny**, nie wyprowadzany
z numeru portu:

| Wartość | Protokół | Zachowanie |
|---|---|---|
| `ssl` | SMTPS | połączenie przez `ssl://`, szyfrowane od pierwszego bajtu, **bez** komendy STARTTLS |
| `tls` | STARTTLS | połączenie przez `tcp://`, szyfrowanie podnoszone komendą po EHLO |
| `none` | brak | wyklucza `SMTP_USER`/`SMTP_PASS` — hasło poszłoby jawnie |

**lh.pl to `ssl` na porcie 465.** Wysłanie tam `STARTTLS` jest błędem protokołu.
Nierozpoznana wartość przerywa wysyłkę zamiast cofać się do słabszego wariantu:
literówka nie ma prawa po cichu obniżyć poziomu szyfrowania.

Nieudana wysyłka **ponawia zadanie**, nie kończy go błędem — odstępy 1, 5, 15
i 60 minut, do pięciu prób. Awarie SMTP są w przewadze chwilowe, a raport jest
w tym momencie już wygenerowany i zapisany; jego zadanie pozostaje `done`.

## Sesja i logowanie

Ciasteczko sesji: `HttpOnly`, `SameSite=Lax`, `Secure` gdy APP_URL zaczyna się od
`https://` — **decyduje APP_URL, nigdy nagłówek żądania**, bo z nagłówka dałoby się
flagę zdjąć samym żądaniem po HTTP.

Token CSRF mieszka w sesji, a sesja w ciasteczku. Gdy ciasteczko nie wraca, nie ma
sesji, nie ma tokenu i **żadna liczba powtórzeń tego nie zmieni** — dlatego odrzucenie
tokenu niesie NAZWANĄ przyczynę, a nie jeden komunikat na wszystkie przypadki:

| Przyczyna | Komunikat |
|---|---|
| sesja żyje, token się nie zgadza | „Formularz stracił ważność. Spróbuj jeszcze raz." — jedyny przypadek, w którym powtórzenie pomaga |
| ciasteczko wróciło, sesja pusta | „Sesja wygasła — zaloguj się ponownie." |
| ciasteczko nie wróciło | wprost o ciasteczkach, z zaznaczeniem, że powtórzenie nic nie da |
| ciasteczko `Secure`, żądanie bez szyfrowania | wprost o HTTPS — i ostrzeżenie widać **przed** pierwszą próbą |
| serwer nie umie zapisać sesji | komunikat o usterce po naszej stronie, przyczyna do logu |

Zanim komunikat obwini przeglądarkę, aplikacja sprawdza siebie: próbuje zapisu
w katalogu sesji. Awaria zapisu wygląda z przeglądarki identycznie jak zablokowane
ciasteczka, a wysłanie użytkownika w ustawienia przeglądarki przy własnej awarii
kosztuje go dzień.

Pilnują tego `app/tests/test_komunikaty_sesji.php` (w CI) i przelot przez prawdziwy
HTTP `app/tests/integracja/test_sesja_http.php`.

## Gałęzie

`main` = produkcja · `dev` = staging · `feat/*` = robocze.
Merge do `main` wyłącznie po zielonym CI, w tym teście złotym.

## Kolejność prac

| Etap | Zakres | h |
|---|---|---|
| 0 | Bramka techniczna lh.pl, repo, CI | 12 |
| 1 | Baza danych i migracje | 10 |
| 2 | **Refaktor silnika + test złoty** ← bramka dla całej reszty | 18 |
| 3 | Logowanie | 5 |
| 4a | Panel i dashboard | 10 |
| 4b | Kluby: herby, barwy, `club_key` | 16 |
| 4c | Biblioteka meczów i sezony | 12 |
| 5 | Builder raportów: upload, kolejka, pokrycie, render | 20 |
| 6 | Notatnik | 14 |
| 7 | Publikacja i linki | 9 |
| 8 | Wdrożenie i odbiór | 8 |

Etap 2 jest bramką: dopóki silnik nie generuje wyjścia identycznego z dzisiejszym skryptem,
reszta nie ma na czym stanąć.

## Czego brakuje do startu

- [ ] Eksporty referencyjne LiveTag — **minimum 3 mecze**, najlepiej z różnych wersji
- [ ] Aktualny `build_dashboard.py` + `dashboard_template.html`
- [ ] Dostęp SSH do lh.pl
- [ ] Decyzja: `events_canonical` teraz (+8 h) czy później (−24 h w modułach)
