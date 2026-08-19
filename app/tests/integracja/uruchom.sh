#!/usr/bin/env bash
# Uruchomienie całego zestawu testów: statycznych i integracyjnych.
#
# POWÓD ISTNIENIA: zestawy integracyjne wymagają środowiska (gniazdo Redis,
# venv Pythona, wolne porty), a wymagania każdego z nich trzeba było pamiętać.
# Kosztowało to już fałszywą diagnozę: `test_konta.php` uruchomiony bez atrapy
# Redisa sypie pięcioma błędami o komunikatach logowania, z których ani jeden
# nie wspomina o Redisie — i wygląda jak regresja, którą trzeba szukać w kodzie.
#
# Skrypt podnosi atrapy, uruchamia wszystko w kolejności od najtańszego do
# najbardziej zależnego od środowiska i kończy JEDNYM wynikiem zbiorczym.
#
# KOLEJNOŚĆ NIE JEST DOWOLNA:
#   0. testy statyczne — bez zależności, sekundy. Czerwony test statyczny
#      czyni wyniki integracyjne szumem, więc lecą pierwsze.
#   1. modele na SQLite — bez sieci i bez procesów potomnych,
#   2. zestawy z atrapą Redisa — jedno gniazdo, wspólne,
#   3. poczta i silnik Pythona — wolniejsze, z zależnościami zewnętrznymi,
#   4. przeloty HTTP — każdy podnosi własny serwer na stałym porcie, więc
#      MUSZĄ iść pojedynczo; równolegle biłyby się o port,
#   5. skrypt chmurek na atrapie DOM (node).
#
# POMINIĘCIE JEST GŁOŚNE. Brak `node` albo venv nie wywala przebiegu, ale
# ląduje w podsumowaniu jako POMINIĘTY — zestaw cicho pominięty jest gorszy
# niż zestaw, który nie przeszedł, bo wygląda jak zielony.
#
# Uruchomienie:
#   bash app/tests/integracja/uruchom.sh              # wszystko
#   bash app/tests/integracja/uruchom.sh --bez-http   # bez przelotów HTTP
#   bash app/tests/integracja/uruchom.sh --tylko-statyczne
#
# `set -e` NIE JEST TU WŁĄCZONE i to jest celowe: przebieg ma dojść do końca
# i pokazać WSZYSTKIE nieudane zestawy, a nie zatrzymać się na pierwszym.
set -uo pipefail

TUTAJ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KORZEN="$(cd "$TUTAJ/../../.." && pwd)"

BEZ_HTTP=0
TYLKO_STATYCZNE=0
for arg in "$@"; do
  case "$arg" in
    --bez-http)        BEZ_HTTP=1 ;;
    --tylko-statyczne) TYLKO_STATYCZNE=1 ;;
    -h|--help)
      sed -n '2,32p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *)
      echo "Nieznany argument: $arg (zobacz --help)" >&2
      exit 2 ;;
  esac
done

# Gniazdo atrapy Redisa. Nazwa z PID-em, żeby dwa równoległe przebiegi
# (np. dwa okna terminala) nie zabierały sobie gniazda.
SOCK="${TMPDIR:-/tmp}/ca_uruchom_$$.sock"
REDIS_PID=""

ZIELONE=0
CZERWONE=0
POMINIETE=0
ASERCJE_OK=0
ASERCJE_BLAD=0
NIEUDANE=()
NIEUDANE_POLECENIA=()
LISTA_POMINIETYCH=()

sprzataj() {
  if [ -n "$REDIS_PID" ] && kill -0 "$REDIS_PID" 2>/dev/null; then
    kill "$REDIS_PID" 2>/dev/null || true
    wait "$REDIS_PID" 2>/dev/null || true
  fi
  rm -f "$SOCK"
}
trap sprzataj EXIT INT TERM

# --------------------------------------------------------------------------

naglowek() {
  echo ""
  echo "──────────────────────────────────────────────────────────────"
  echo "  $1"
  echo "──────────────────────────────────────────────────────────────"
}

# Uruchomienie jednego zestawu.
#   $1 — etykieta w podsumowaniu
#   reszta — polecenie
#
# Liczby asercji wyciągamy z ostatniej linii („=== OK: N, BŁĘDÓW: M ==="),
# którą wypisują wszystkie zestawy tego projektu. Zestaw bez takiej linii nadal
# się liczy — rozstrzyga wtedy kod wyjścia.
zestaw() {
  local etykieta="$1"; shift
  local wyjscie kod podsumowanie ok blad
  # Polecenie zapamiętujemy PRZED uruchomieniem, żeby podpowiedź powtórzenia
  # niosła prawdziwą ścieżkę. Składanie jej z samej etykiety dawało odsyłacz do
  # `integracja/` także dla testów statycznych, które leżą piętro wyżej —
  # czyli instrukcję do pliku, którego tam nie ma.
  local polecenie="$*"

  printf '  %-28s ' "$etykieta"
  wyjscie=$("$@" 2>&1)
  kod=$?

  podsumowanie=$(printf '%s' "$wyjscie" | grep -oE '=== OK: [0-9]+, BŁĘDÓW: [0-9]+ ===' | tail -1)
  ok=$(printf '%s' "$podsumowanie"   | grep -oE 'OK: [0-9]+'      | grep -oE '[0-9]+' || echo 0)
  blad=$(printf '%s' "$podsumowanie" | grep -oE 'BŁĘDÓW: [0-9]+'  | grep -oE '[0-9]+' || echo 0)
  ASERCJE_OK=$(( ASERCJE_OK + ${ok:-0} ))
  ASERCJE_BLAD=$(( ASERCJE_BLAD + ${blad:-0} ))

  if [ "$kod" -eq 0 ]; then
    ZIELONE=$(( ZIELONE + 1 ))
    # Pytest ma własny format podsumowania — pokazujemy jego ostatnią linię
    # zamiast „(bez podsumowania)". Linie SKIPPED z `-rs` idą pod spodem:
    # pominięcie ma być widoczne w każdym przebiegu, nie tylko przy awarii.
    if [ -z "$podsumowanie" ]; then
      podsumowanie=$(printf '%s' "$wyjscie" | grep -E '[0-9]+ (passed|failed)' | tail -1)
    fi
    printf 'OK    %s\n' "${podsumowanie:-(bez podsumowania)}"
    printf '%s' "$wyjscie" | grep -E '^SKIPPED' | sed 's/^SKIPPED/        pominięto:/' | head -5
  else
    CZERWONE=$(( CZERWONE + 1 ))
    NIEUDANE+=("$etykieta")
    NIEUDANE_POLECENIA+=("$polecenie")
    printf 'BŁĄD  (kod %s) %s\n' "$kod" "${podsumowanie:-}"
    # Same linie błędów — pełne wyjście przy dwudziestu zestawach jest
    # nieczytelne, a to, czego szukamy, zaczyna się od „BŁĄD".
    printf '%s' "$wyjscie" | grep -E '^\s*(BŁĄD|Błąd|PHP Fatal|Fatal error)' | sed 's/^/      /' | head -15
  fi
}

pomin() {
  local etykieta="$1" powod="$2"
  POMINIETE=$(( POMINIETE + 1 ))
  LISTA_POMINIETYCH+=("$etykieta — $powod")
  printf '  %-28s POMINIĘTY  (%s)\n' "$etykieta" "$powod"
}

# --------------------------------------------------------------------------
naglowek "0. Testy statyczne (te chodzą w CI)"

for plik in "$KORZEN"/app/tests/test_*.php; do
  [ -f "$plik" ] || continue
  zestaw "$(basename "$plik" .php)" php "$plik"
done

if [ "$TYLKO_STATYCZNE" -eq 1 ]; then
  CZAS_KONIEC=1
else

# --------------------------------------------------------------------------
naglowek "1. Modele na SQLite (bez zależności)"

for nazwa in test_4a test_4b test_4c test_7 test_indeks test_kluby_templaty \
             test_konfigurator test_diff_templatu test_mapowania test_powiadomienia \
             test_remember test_xg; do
  [ -f "$TUTAJ/$nazwa.php" ] || continue
  zestaw "$nazwa" php "$TUTAJ/$nazwa.php"
done

# --------------------------------------------------------------------------
naglowek "2. Zestawy wymagające atrapy Redisa"

# Limiter logowania jest „fail closed": bez Redisa `Auth::attempt()` odmawia
# niezależnie od hasła. Te zestawy mają własną bramkę i odmawiają startu
# bez gniazda — podajemy im jedno, wspólne.
php "$TUTAJ/fake_redis.php" "$SOCK" >/dev/null 2>&1 &
REDIS_PID=$!

for _ in $(seq 1 50); do
  [ -S "$SOCK" ] && break
  sleep 0.1
done

if [ -S "$SOCK" ]; then
  for nazwa in test_etap3 test_konta; do
    [ -f "$TUTAJ/$nazwa.php" ] || continue
    zestaw "$nazwa" php "$TUTAJ/$nazwa.php" "$SOCK"
  done
else
  pomin "test_etap3" "atrapa Redisa nie wystartowała"
  pomin "test_konta" "atrapa Redisa nie wystartowała"
fi

# --------------------------------------------------------------------------
naglowek "3. Poczta i silnik Pythona"

if [ -f "$TUTAJ/test_smtp.php" ]; then
  zestaw "test_smtp" php "$TUTAJ/test_smtp.php"
fi

PYTHON="$KORZEN/venv/bin/python"
if [ -x "$PYTHON" ]; then
  # PYTEST SILNIKA — dotąd tego tu NIE BYŁO, mimo że nagłówek obiecuje „cały
  # zestaw testów". Sto siedemdziesiąt testów silnika chodziło wyłącznie ręcznie,
  # więc przebieg runnera mógł być zielony przy zepsutym silniku.
  #
  # `-rs` NIE JEST OZDOBNIKIEM: pytest melduje pominięty MODUŁ jako jedno
  # „1 skipped", bez powodu. Tak zniknęła bramka pakowania (`tomllib` wymaga
  # Pythona 3.11, venv ma 3.9) — siedem testów naraz, a w podsumowaniu jedna
  # niewinna liczba. Rozjazd wersji w `pyproject.toml` dojechał przez to do
  # wdrożenia. Z `-rs` powód pominięcia stoi w wyjściu każdego przebiegu.
  zestaw "pytest silnika" env PYTHONPATH="$KORZEN/engine" \
      "$PYTHON" -m pytest "$KORZEN/engine/tests" -q -rs

  # Paczka bywa w venv niezainstalowana — silnik dostaje PYTHONPATH na engine/,
  # tak samo jak robi to sam test.
  zestaw "test_kolejka" env PYTHONPATH="$KORZEN/engine" php "$TUTAJ/test_kolejka.php"
else
  pomin "pytest silnika" "brak $PYTHON"
  pomin "test_kolejka" "brak $PYTHON"
fi

# --------------------------------------------------------------------------
naglowek "4. Przeloty HTTP (każdy podnosi własny serwer)"

if [ "$BEZ_HTTP" -eq 1 ]; then
  for nazwa in test_sesja_http test_haslo_http test_klub_hub_http test_mapowania_http \
               test_konfigurator_http test_import_n1_http test_przelicz_http \
               test_wskaznik_http test_rewizja_http test_meta_sezon_http \
               test_hasla_indeksu_http; do
    pomin "$nazwa" "--bez-http"
  done
else
  # KOLEJNO, NIGDY RÓWNOLEGLE: każdy zestaw podnosi wbudowany serwer PHP na
  # stałym porcie (8946, 8947, 8951+8952, 8961, 8971, 8981, 8991, 8996, 9001, 9006, 9011). Dwa naraz biłyby się o port,
  # a objawem byłby losowo czerwony zestaw bez związku z kodem.
  for nazwa in test_sesja_http test_haslo_http test_klub_hub_http; do
    [ -f "$TUTAJ/$nazwa.php" ] || continue
    zestaw "$nazwa" php "$TUTAJ/$nazwa.php"
  done

  # Te dwa potrzebują jeszcze silnika Pythona.
  if [ -x "$PYTHON" ]; then
    for nazwa in test_mapowania_http test_konfigurator_http test_import_n1_http \
                 test_przelicz_http test_wskaznik_http test_rewizja_http \
                 test_meta_sezon_http test_hasla_indeksu_http; do
      [ -f "$TUTAJ/$nazwa.php" ] || continue
      zestaw "$nazwa" env PYTHONPATH="$KORZEN/engine" php "$TUTAJ/$nazwa.php"
    done
  else
    pomin "test_mapowania_http" "brak $PYTHON"
    pomin "test_konfigurator_http" "brak $PYTHON"
    pomin "test_import_n1_http" "brak $PYTHON"
    pomin "test_przelicz_http" "brak $PYTHON"
    pomin "test_wskaznik_http" "brak $PYTHON"
    pomin "test_rewizja_http" "brak $PYTHON"
    pomin "test_meta_sezon_http" "brak $PYTHON"
    pomin "test_hasla_indeksu_http" "brak $PYTHON"
  fi
fi

# --------------------------------------------------------------------------
naglowek "5. Skrypt chmurek (atrapa DOM)"

if command -v node >/dev/null 2>&1; then
  zestaw "test_chmurki.js" node "$TUTAJ/test_chmurki.js"
else
  pomin "test_chmurki.js" "brak node"
fi

fi   # koniec --tylko-statyczne

# --------------------------------------------------------------------------
naglowek "WYNIK ZBIORCZY"

printf '  zestawy zielone:   %d\n' "$ZIELONE"
printf '  zestawy czerwone:  %d\n' "$CZERWONE"
printf '  zestawy pominięte: %d\n' "$POMINIETE"
printf '  asercje:           %d OK, %d błędów\n' "$ASERCJE_OK" "$ASERCJE_BLAD"

if [ "${#LISTA_POMINIETYCH[@]}" -gt 0 ]; then
  echo ""
  echo "  POMINIĘTE (nie są zielone — po prostu się nie wykonały):"
  for p in "${LISTA_POMINIETYCH[@]}"; do
    echo "    · $p"
  done
fi

if [ "${#NIEUDANE[@]}" -gt 0 ]; then
  echo ""
  echo "  NIEUDANE — powtórz pojedynczo, żeby zobaczyć pełne wyjście:"
  for i in "${!NIEUDANE[@]}"; do
    printf '    · %-26s %s\n' "${NIEUDANE[$i]}" "${NIEUDANE_POLECENIA[$i]}"
  done
  exit 1
fi

echo ""
echo "  Wszystko, co się wykonało, jest zielone."
exit 0
