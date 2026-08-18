# Skrypty naprawcze

Jednorazowe operacje na **danych**, uruchamiane ręcznie, na żądanie.

## Czym to NIE jest

To nie są migracje i nie wolno ich wkładać do `app/migrations/`. Różnica jest
twarda i wynika z CLAUDE.md §7 („migracje numerowane, wyłącznie w przód, nigdy
edytowane po wdrożeniu"):

| | `app/migrations/` | `app/repairs/` |
|---|---|---|
| Co zmienia | schemat | dane |
| Numeracja | ciągła, `001`…`012` | brak — nazwa z datą |
| Kiedy | raz, w kolejności | na żądanie, gdy zajdzie potrzeba |
| Powtórzenie | zabronione | **musi być bezpieczne** |
| Edycja po wdrożeniu | zabroniona | dozwolona |

Numer w nazwie sugerowałby miejsce w łańcuchu migracji, a tego miejsca tu nie
ma — skrypt naprawczy bywa uruchomiony raz, wcale albo trzy razy.

## Zasady

1. **Idempotencja jest wymagana.** Skrypt uruchomiony drugi raz nie może
   zepsuć stanu ani zdublować pracy. Najlepiej, gdy wynika to z warunku `WHERE`,
   a nie z pamięci operatora.
2. **Podgląd przed zmianą.** Każdy plik zaczyna się od `SELECT`, który pokazuje,
   czego zmiana dotknie. Skrypt, którego skutku nie widać przed uruchomieniem,
   jest zgadywaniem.
3. **Kontrola po zmianie.** Na końcu `SELECT`, który potwierdza wynik — łącznie
   z inwariantami, które operacja mogła naruszyć po drodze.
4. **Zrzut bazy przed uruchomieniem**, tak samo jak przy migracji:
   ```bash
   mysqldump --single-transaction -u USER -p BAZA > backup_przed_naprawa_$(date +%F).sql
   ```
5. **Komentarz mówi PO CO**, nie tylko co. Za pół roku nikt nie odtworzy
   powodu z samego SQL-a.

## Dostępność przez HTTP

Katalog leży pod `app/`, więc jest zablokowany dwiema niezależnymi warstwami:
`RewriteRule ^(app|storage|…)/ - [F,L]` oraz `Require all denied`
w `app/.htaccess`. Dodatkowo `FilesMatch` w `app/public/.htaccess` odmawia
plikom `.sql`. Skrypt naprawczy nie jest tajemnicą, ale nie ma powodu, żeby
wisiał publicznie.
