#!/usr/bin/env bash
# Kopia bazy CoachAnalyze. Uruchamiana z crona — patrz deploy/crontab.example.
#
#   bash deploy/backup_db.sh daily     # kopia dobowa, trzymana 14 dni
#   bash deploy/backup_db.sh weekly    # kopia tygodniowa, trzymana 90 dni
#
# NAZWA BAZY POCHODZI Z `.env`, NIE Z `~/.my.cnf`.
# Plik `~/.my.cnf` przechowuje dane logowania, a nie wybór bazy. Wpis `database=`
# został z niego świadomie usunięty, bo psuł `mysqldump` (klient traktuje go jako
# argument pozycyjny i myli z nazwą tabeli). Bez jawnej nazwy `mysqldump` kończy
# się błędem „No database selected".
set -euo pipefail

BASE="${CA_BASE:-$HOME/CoachAnalyze}"
ENV_ZRODLO="${CA_ENV:-$BASE/shared/.env}"
RODZAJ="${1:-daily}"

case "$RODZAJ" in
  daily)  DNI=14 ;;
  weekly) DNI=90 ;;
  *)
    echo "Nieznany rodzaj kopii: $RODZAJ (dozwolone: daily, weekly)" >&2
    exit 2
    ;;
esac

# Wartość klucza z `.env`. Cudzysłowy zdejmujemy tylko wtedy, gdy otaczają CAŁĄ
# wartość — tak samo jak parser w PHP (app/src/Config.php). Rozjazd między tymi
# dwoma odczytami byłby błędem widocznym wyłącznie na produkcji.
wartosc_env() {
  local klucz="$1" wartosc
  wartosc=$(grep -E "^${klucz}=" "$ENV_ZRODLO" 2>/dev/null | tail -1 | cut -d= -f2- || true)
  wartosc="${wartosc%\"}"; wartosc="${wartosc#\"}"
  wartosc="${wartosc%\'}"; wartosc="${wartosc#\'}"
  printf '%s' "$wartosc"
}

DB_NAME=$(wartosc_env DB_NAME)

if [ -z "$DB_NAME" ]; then
  echo "BŁĄD: brak DB_NAME w $ENV_ZRODLO — nie wiem, którą bazę kopiować" >&2
  exit 1
fi

KATALOG="$BASE/shared/backups"
mkdir -p "$KATALOG"
PLIK="$KATALOG/${RODZAJ}-$(date +%Y%m%d-%H%M%S).sql.gz"

# ZAPIS DO PLIKU TYMCZASOWEGO, PRZENIESIENIE DOPIERO PO POWODZENIU.
#
# Zapis wprost pod docelową nazwą ma dwie wady i obie są ciche:
#  1. Przekierowanie `>` obcina plik ZANIM `mysqldump` cokolwiek wypisze. Przy
#     dwóch uruchomieniach w tej samej sekundzie nazwy się powtarzają i nieudany
#     przebieg niszczy poprzednią, dobrą kopię.
#  2. Przerwany przebieg zostawia urwany plik, który w katalogu wygląda
#     dokładnie jak kopia — i okazuje się bezużyteczny przy odtwarzaniu.
#
# Zrzut idzie przez `gzip`: kopie dobowe trzymamy dwa tygodnie, a SQL ściska się
# kilkunastokrotnie. `pipefail` na górze pilnuje, żeby awaria `mysqldump` nie
# została ukryta przez powodzenie `gzip`.
TMP=$(mktemp "$KATALOG/.${RODZAJ}-XXXXXX")
trap 'rm -f "$TMP" "$TMP.blad"' EXIT

if mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
     "$DB_NAME" 2> "$TMP.blad" | gzip > "$TMP"; then
  mv -f "$TMP" "$PLIK"
  rm -f "$TMP.blad"
  trap - EXIT
  echo "$(date '+%Y-%m-%d %H:%M') kopia $RODZAJ: $(basename "$PLIK") ($(wc -c < "$PLIK" | tr -d ' ') B)"
else
  echo "BŁĄD: nie udało się zrzucić bazy $DB_NAME" >&2
  sed 's/^/    /' "$TMP.blad" >&2 || true
  exit 1
fi

# Sprzątanie starych kopii. Wyłącznie w obrębie własnego rodzaju, żeby kopia
# tygodniowa nie zniknęła razem z dobowymi.
find "$KATALOG" -name "${RODZAJ}-*.sql.gz" -type f -mtime "+${DNI}" -delete 2>/dev/null || true

echo "    kopii $RODZAJ w katalogu: $(find "$KATALOG" -name "${RODZAJ}-*.sql.gz" -type f | wc -l | tr -d ' ')"
