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
