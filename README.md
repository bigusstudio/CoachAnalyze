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
