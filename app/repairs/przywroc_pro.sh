#!/usr/bin/env bash
#
# Odtworzenie stanu wersji `pro` (tag pro-1.0) NA BAZIE PRÓBNEJ.
#
#   bash app/repairs/przywroc_pro.sh <katalog-na-uploady> [--sprawdz-tylko]
#
# ╔══════════════════════════════════════════════════════════════════════════╗
# ║ TEN SKRYPT NIGDY NIE DOTYKA PRODUKCJI.                                   ║
# ║                                                                          ║
# ║ Nie dlatego, że „raczej nie powinien" — dlatego, że nazwa bazy docelowej  ║
# ║ jest STAŁĄ w kodzie, a nie parametrem, i nie da się jej podać z zewnątrz. ║
# ║ Dodatkowo skrypt odmawia startu, gdy ta nazwa zgadza się z `DB_NAME`      ║
# ║ z `shared/.env`, czyli z bazą, której używa działająca aplikacja.         ║
# ║                                                                          ║
# ║ Powód: `serwer400227_caproba` i `serwer400227_coachanalyze` różnią się    ║
# ║ w poleceniu jednym słowem, a `mysql` bez `--defaults-group-suffix`        ║
# ║ idzie na produkcję. Pomyłka tutaj kasuje 23 mecze i 24 raporty klienta.   ║
# ╚══════════════════════════════════════════════════════════════════════════╝
#
# PO CO TO ISTNIEJE. Pivot „viewer" usypia część wersji `pro` (silnik kanoniczny,
# wizard AI mapowań — docs/STAN_PIVOTU.md). Powrót do `pro` jest realną opcją tylko
# wtedy, gdy ktoś go kiedyś wykonał i wie, że działa. Procedura opisana w dokumencie,
# ale nigdy nieprzećwiczona, jest życzeniem, nie planem awaryjnym.
#
# CZEGO TEN SKRYPT NIE ROBI. Nie przestawia repozytorium (`git checkout pro`), nie
# wdraża kodu (`deploy.sh`) i nie generuje raportu. To są kroki na produkcji albo
# w katalogu domeny i należą do człowieka — pełna procedura w `docs/STAN_PIVOTU.md`.
# Tutaj jest wyłącznie część, którą da się wykonać na boku: dane i pliki.
#
set -euo pipefail

# ─────────────────────────────────────────────────────────── stałe, nie parametry
#
# NAZWA BAZY JEST STAŁĄ. Jako parametr byłaby zaproszeniem do wklejenia nazwy
# produkcyjnej „na chwilę, żeby sprawdzić".
BAZA_PROBNA="serwer400227_caproba"
SUFIKS_MYSQL="caproba"

BASE="${CA_BASE:-$HOME/CoachAnalyze}"
ARCHIWUM="${CA_ARCHIWUM:-$BASE/archiwum/pro-1.0}"
REPO="${CA_REPO:-$BASE/repo}"
TAG_PRO="pro-1.0"

# ─────────────────────────────────────────────────────────────────── argumenty
KATALOG_UPLOADOW=""
SPRAWDZ_TYLKO=0

for arg in "$@"; do
  case "$arg" in
    --sprawdz-tylko) SPRAWDZ_TYLKO=1 ;;
    -h|--help)
      sed -n '2,36p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    -*)
      echo "Nieznany argument: $arg (zobacz --help)" >&2; exit 2 ;;
    *)
      if [ -n "$KATALOG_UPLOADOW" ]; then
        echo "Podano dwa katalogi docelowe: '$KATALOG_UPLOADOW' i '$arg'" >&2; exit 2
      fi
      KATALOG_UPLOADOW="$arg" ;;
  esac
done

if [ -z "$KATALOG_UPLOADOW" ]; then
  echo "Brak katalogu na uploady." >&2
  echo "Użycie: bash app/repairs/przywroc_pro.sh <katalog-na-uploady> [--sprawdz-tylko]" >&2
  echo "Przykład: bash app/repairs/przywroc_pro.sh ~/tmp/pro-1.0-uploads" >&2
  exit 2
fi

BLAD=0
krok()  { echo ""; echo "==> $1"; }
ok()    { printf '    %-52s OK\n' "$1"; }
zle()   { echo "    !!! BŁĄD: $1"; BLAD=1; }
uwaga() { echo "    UWAGA: $1"; }

# ══════════════════════════════════════════════════════ 1. ASERCJE BEZPIECZEŃSTWA
#
# Wszystkie PRZED jakimkolwiek pytaniem o zgodę. Pytanie zadane przed sprawdzeniem
# warunków uczy klikać „tak" — a potem skrypt i tak przerywa na czymś innym.

krok "1. Asercje bezpieczeństwa (przed czymkolwiek destrukcyjnym)"

# 1a. Baza docelowa NIE MOŻE być bazą aplikacji.
#
# Czytamy `DB_NAME` ze źródła prawdy dla sekretów. Gdyby ktoś kiedyś przestawił
# produkcję na `caproba` — albo odwrotnie — ta asercja to złapie, zanim zrobi to
# pierwszy klient, któremu zniknie raport.
ENV_ZRODLO="$BASE/shared/.env"
if [ -f "$ENV_ZRODLO" ]; then
  DB_APLIKACJI=$(grep -E '^DB_NAME=' "$ENV_ZRODLO" 2>/dev/null | tail -1 | cut -d= -f2- || true)
  DB_APLIKACJI="${DB_APLIKACJI%\"}"; DB_APLIKACJI="${DB_APLIKACJI#\"}"
  DB_APLIKACJI="${DB_APLIKACJI%\'}"; DB_APLIKACJI="${DB_APLIKACJI#\'}"

  if [ -z "$DB_APLIKACJI" ]; then
    uwaga "brak DB_NAME w $ENV_ZRODLO — nie mam z czym porównać bazy docelowej"
  elif [ "$DB_APLIKACJI" = "$BAZA_PROBNA" ]; then
    zle "DB_NAME w shared/.env to '$DB_APLIKACJI' — czyli baza docelowa JEST bazą aplikacji."
    echo "        Ten skrypt nie uruchomi się, dopóki to się nie rozjedzie."
  else
    ok "baza aplikacji ($DB_APLIKACJI) to nie baza docelowa"
  fi
else
  uwaga "nie widzę $ENV_ZRODLO — pomijam porównanie z bazą aplikacji"
fi

# 1b. Połączenie idzie na `caproba` i nigdzie indziej.
#
# Nazwę bazy podajemy JAWNIE przy każdym wywołaniu `mysql` — `~/.my.cnf` trzyma
# dane logowania, nie wybór bazy (ten sam powód, dla którego `deploy.sh` podaje
# `DB_NAME` do `mysqldump`).
mysql_probna() { mysql --defaults-group-suffix="$SUFIKS_MYSQL" "$BAZA_PROBNA" "$@"; }

if ! command -v mysql >/dev/null 2>&1; then
  zle "brak polecenia mysql"
elif GDZIE=$(mysql_probna -N -B -e 'SELECT DATABASE()' 2>/dev/null); then
  if [ "$GDZIE" = "$BAZA_PROBNA" ]; then
    ok "połączenie na $BAZA_PROBNA"
  else
    zle "połączenie wylądowało na '$GDZIE', a nie na '$BAZA_PROBNA'"
  fi
else
  zle "nie mogę połączyć się z $BAZA_PROBNA (sekcja [client$SUFIKS_MYSQL] w ~/.my.cnf)"
fi

# 1c. Tag `pro-1.0` istnieje.
#
# Archiwum plików bez punktu w historii kodu jest bezużyteczne: dane odtworzysz,
# ale nie będziesz wiedział, który kod je rozumiał.
if [ ! -d "$REPO/.git" ]; then
  zle "nie widzę repozytorium w $REPO"
elif git -C "$REPO" rev-parse --verify --quiet "refs/tags/$TAG_PRO" >/dev/null; then
  ok "tag $TAG_PRO istnieje ($(git -C "$REPO" rev-parse --short "$TAG_PRO"))"
else
  zle "brak tagu $TAG_PRO w $REPO — bez niego nie ma do czego wracać"
fi

# ═════════════════════════════════════════════════════════════ 2. ARCHIWUM I SUMY

krok "2. Archiwum i sumy kontrolne"

if [ ! -d "$ARCHIWUM" ]; then
  zle "brak katalogu archiwum: $ARCHIWUM"
  echo ""
  echo "==> PRZERWANE — nie ma czego odtwarzać."
  exit 1
fi

# Plik sum. Jeden, z datą w nazwie — szukamy wzorcem, żeby data nie była wpisana
# w skrypcie na sztywno i nie rozjechała się przy kolejnym archiwum.
PLIK_SUM=$(ls -1 "$ARCHIWUM"/SUMY_*.txt 2>/dev/null | sort | tail -1 || true)
if [ -z "$PLIK_SUM" ]; then
  zle "brak pliku SUMY_*.txt w $ARCHIWUM — nie ma z czym porównać zrzutu"
else
  ok "plik sum: $(basename "$PLIK_SUM")"
fi

ZRZUT=$(ls -1 "$ARCHIWUM"/prod_*.sql.gz 2>/dev/null | sort | tail -1 || true)
TAR_APP=$(ls -1 "$ARCHIWUM"/app_public_html_*.tgz 2>/dev/null | sort | tail -1 || true)

if [ -z "$ZRZUT" ]; then
  zle "brak zrzutu prod_*.sql.gz w $ARCHIWUM"
else
  ok "zrzut bazy: $(basename "$ZRZUT") ($(wc -c < "$ZRZUT" | tr -d ' ') B)"
fi

if [ -z "$TAR_APP" ]; then
  zle "brak archiwum aplikacji app_public_html_*.tgz w $ARCHIWUM"
else
  ok "archiwum aplikacji: $(basename "$TAR_APP")"
fi

# Skrót pliku. `sha256sum` na Linuksie, `shasum -a 256` na macOS — skrypt bywa
# czytany i uruchamiany na obu, a różnica w nazwie polecenia nie jest powodem,
# żeby kontrola sum „się nie wykonała".
suma_pliku() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | cut -d' ' -f1
  else
    return 1
  fi
}

# Oczekiwana suma dla pliku o danej nazwie. Bierzemy po BASENAME, bo plik sum
# mógł powstać z innym prefiksem ścieżki niż ten, pod którym archiwum leży dzisiaj.
suma_z_pliku() {
  local nazwa="$1" linia
  linia=$(grep -E "[[:space:]]\*?(\./)?([^[:space:]]*/)?${nazwa}\$" "$PLIK_SUM" 2>/dev/null | head -1 || true)
  [ -n "$linia" ] || return 1
  printf '%s' "$linia" | awk '{print $1}'
}

sprawdz_sume() {
  local plik="$1" nazwa oczekiwana policzona
  nazwa=$(basename "$plik")

  if ! oczekiwana=$(suma_z_pliku "$nazwa"); then
    zle "w $(basename "$PLIK_SUM") nie ma wpisu dla $nazwa"
    return
  fi
  if ! policzona=$(suma_pliku "$plik"); then
    uwaga "brak sha256sum i shasum — nie mogę sprawdzić sumy $nazwa"
    return
  fi
  if [ "$oczekiwana" = "$policzona" ]; then
    ok "suma zgodna: $nazwa"
  else
    zle "SUMA SIĘ NIE ZGADZA dla $nazwa"
    echo "        w SUMY: $oczekiwana"
    echo "        liczona: $policzona"
    echo "        Archiwum jest uszkodzone albo podmienione. NIE ODTWARZAJ z tego pliku."
  fi
}

if [ -n "$PLIK_SUM" ]; then
  [ -n "$ZRZUT" ]   && sprawdz_sume "$ZRZUT"
  [ -n "$TAR_APP" ] && sprawdz_sume "$TAR_APP"
fi

# Zrzut musi dać się rozpakować. Gzip urwany w połowie wygląda w katalogu
# dokładnie jak kopia — i odkrywa się przy odtwarzaniu, czyli najpóźniej jak można.
if [ -n "$ZRZUT" ]; then
  if gzip -t "$ZRZUT" 2>/dev/null; then
    ok "zrzut nie jest urwany (gzip -t)"
  else
    zle "gzip -t odrzuca $(basename "$ZRZUT") — plik jest uszkodzony"
  fi
fi

# ══════════════════════════════════════════════════ 3. KATALOG NA UPLOADY I TAR

krok "3. Katalog docelowy na uploady"

# Katalog docelowy NIE MOŻE być katalogiem domeny. Rozpakowanie archiwum aplikacji
# do działającego katalogu webowego cofnęłoby produkcję do stanu sprzed pivotu —
# a to jest dokładnie ta operacja, której ten skrypt ma NIE robić.
KAT_DOMENY="${CA_WEB:-$HOME/public_html/app.coachanalyze.pl}"
rozwin() { ( cd "$1" 2>/dev/null && pwd -P ) || printf '%s' "$1"; }

DOMENA_REAL=$(rozwin "$KAT_DOMENY")

w_domenie() {
  [ "$1" = "$DOMENA_REAL" ] || [ "${1#"$DOMENA_REAL"/}" != "$1" ] \
    || [ "$1" = "$KAT_DOMENY" ] || [ "${1#"$KAT_DOMENY"/}" != "$1" ]
}

# SPRAWDZENIE PRZED `mkdir`, nie po nim. Tworzenie katalogu, o którym zaraz powiemy,
# że jest w złym miejscu, zostawia po sobie śmieć dokładnie tam, gdzie nie chcieliśmy
# niczego dotykać — a przy katalogu domeny „nic nie dotknięto" ma być prawdą dosłowną.
if w_domenie "$KATALOG_UPLOADOW"; then
  zle "katalog docelowy leży w katalogu domeny ($DOMENA_REAL) — to byłby powrót NA PRODUKCJI"
  CEL_REAL="$KATALOG_UPLOADOW"
else
  mkdir -p "$KATALOG_UPLOADOW"
  CEL_REAL=$(rozwin "$KATALOG_UPLOADOW")

  # Drugie sprawdzenie, tym razem po rozwinięciu dowiązań: katalog podany pod
  # niewinną nazwą może wskazywać w drzewo domeny.
  if w_domenie "$CEL_REAL"; then
    zle "katalog docelowy wskazuje (po rozwinięciu) do katalogu domeny: $CEL_REAL"
  else
    ok "katalog docelowy poza katalogiem domeny: $CEL_REAL"
  fi
fi

if [ -n "$(ls -A "$CEL_REAL" 2>/dev/null || true)" ]; then
  uwaga "katalog docelowy nie jest pusty — rozpakowanie NADPISZE pliki o tych samych nazwach"
fi

# Czy uploady w ogóle są w tym archiwum. Samo ISTNIENIE, bez ustalania prefiksu —
# ścieżkę wewnątrz tarballa znajdziemy po rozpakowaniu (punkt 6), a nie przed.
#
# POWÓD: tarball spakowano z nieznanego nam poziomu, a `--strip-components`
# wyliczone z listy plików zależy od tego, czy `tar` liczy wiodące `./` jako
# składnik ścieżki — a to różni się między wersjami. Pomyłka o jeden rozsypałaby
# pliki po katalogu docelowym zamiast zatrzymać skrypt. Tego nie da się sprawdzić
# inaczej niż na prawdziwym archiwum, więc nie zgadujemy.
if [ -n "$TAR_APP" ]; then
  if tar -tzf "$TAR_APP" 2>/dev/null | grep -qE '(^|/)storage/uploads/'; then
    ok "archiwum zawiera storage/uploads/"
  else
    zle "w $(basename "$TAR_APP") nie widzę ścieżki storage/uploads/"
  fi
fi

if [ "$BLAD" -ne 0 ]; then
  echo ""
  echo "==> PRZERWANE NA ASERCJACH. Niczego nie zmieniono."
  exit 1
fi

if [ "$SPRAWDZ_TYLKO" -eq 1 ]; then
  echo ""
  echo "==> Wszystkie asercje przeszły. --sprawdz-tylko: kończę bez zmian."
  exit 0
fi

# ═════════════════════════════════════════════════════════════════ 4. ZGODA
#
# Pytanie zadawane PO asercjach i przed jedyną nieodwracalną operacją. Wymaga
# wpisania słowa, nie naciśnięcia Enter: „Enter = tak" to zgoda udzielona odruchem.

krok "4. Potwierdzenie"

LICZBA_TABEL=$(mysql_probna -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" 2>/dev/null || echo "?")

cat <<KOMUNIKAT

    Za chwilę:
      · USUNĘ wszystkie tabele z bazy $BAZA_PROBNA (jest ich teraz: $LICZBA_TABEL)
      · wczytam zrzut $(basename "$ZRZUT")
      · rozpakuję uploady do $CEL_REAL

    Baza $BAZA_PROBNA to ŚRODOWISKO PRÓBNE. Jej zawartość jest do nadpisania.
    Produkcja ($([ -n "${DB_APLIKACJI:-}" ] && echo "$DB_APLIKACJI" || echo "serwer400227_coachanalyze")) NIE JEST ruszana.

KOMUNIKAT

if [ ! -t 0 ]; then
  echo "    !!! BŁĄD: brak terminala — nie ma kto potwierdzić. Uruchom ręcznie w SSH." >&2
  exit 1
fi

printf '    Wpisz TAK, żeby kontynuować: '
read -r ODPOWIEDZ
if [ "$ODPOWIEDZ" != "TAK" ]; then
  echo ""
  echo "==> PRZERWANE na życzenie. Niczego nie zmieniono."
  exit 1
fi

# ══════════════════════════════════════════════════════════════ 5. IMPORT BAZY

krok "5. Import zrzutu do $BAZA_PROBNA"

# CZYŚCIMY TABELE, NIE BAZĘ. `DROP DATABASE` wymaga uprawnienia, którego konto
# na hostingu współdzielonym zwykle nie ma — a odtworzenie bazy po nieudanym
# `DROP` to już rozmowa z pomocą techniczną.
#
# `FOREIGN_KEY_CHECKS=0`, bo kolejność kasowania przy kluczach obcych jest
# nierozwiązywalna bez sortowania topologicznego, a tu nie ma czego chronić.
TABELE=$(mysql_probna -N -B -e \
  "SELECT GROUP_CONCAT(CONCAT('\`', table_name, '\`'))
     FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'" 2>/dev/null || true)

if [ -n "$TABELE" ] && [ "$TABELE" != "NULL" ]; then
  mysql_probna -e "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS $TABELE; SET FOREIGN_KEY_CHECKS = 1;"
  echo "    usunięto tabele: $LICZBA_TABEL"
else
  echo "    baza była pusta"
fi

# Potok bez `|| true`: błąd importu MUSI zatrzymać skrypt, a `pipefail` u góry
# sprawia, że awaria `gunzip` też się liczy. Baza z połową tabel wygląda jak
# działająca aż do pierwszego zapytania o brakującą.
gunzip -c "$ZRZUT" | mysql_probna
echo "    wczytano $(basename "$ZRZUT")"

# ═══════════════════════════════════════════════════════════════ 6. UPLOADY

krok "6. Uploady do $CEL_REAL"

# ROZPAKOWUJEMY CAŁOŚĆ DO KATALOGU TYMCZASOWEGO, potem przenosimy sam `storage/uploads`.
#
# Wygląda okrężnie i takie jest — ale nie zależy od tego, jak `tar` traktuje wiodące
# `./` przy `--strip-components` ani od dopasowywania nazw składników wzorcem.
# Kosztuje miejsce na dysku (archiwum to aplikacja plus uploady) i jedno przepisanie
# plików; kupuje pewność, że pliki wylądują tam, gdzie mają, albo nigdzie.
#
# Katalog tymczasowy sprzątamy zawsze, także przy przerwaniu.
TMP=$(mktemp -d "${TMPDIR:-/tmp}/ca-pro-XXXXXX")
trap 'rm -rf "$TMP"' EXIT INT TERM

tar -xzf "$TAR_APP" -C "$TMP"

ZRODLO_UPLOADOW=$(find "$TMP" -type d -path '*/storage/uploads' | head -1 || true)
if [ -z "$ZRODLO_UPLOADOW" ]; then
  echo "    !!! BŁĄD: po rozpakowaniu nie widzę katalogu storage/uploads" >&2
  echo "        Baza została już zaimportowana — uploady trzeba wgrać ręcznie z $TAR_APP" >&2
  exit 1
fi

echo "    źródło: ${ZRODLO_UPLOADOW#"$TMP"/}"
cp -a "$ZRODLO_UPLOADOW"/. "$CEL_REAL"/

LICZBA_PLIKOW=$(find "$CEL_REAL" -type f 2>/dev/null | wc -l | tr -d ' ')
echo "    skopiowano plików: $LICZBA_PLIKOW"

# ══════════════════════════════════════════════════════ 7. KONTROLA PO ODTWORZENIU
#
# Skrypt, który melduje sukces bez sprawdzenia wyniku, jest skryptem, po którym
# i tak trzeba wszystko sprawdzić ręcznie.

krok "7. Kontrola po odtworzeniu"

mysql_probna -e "
  SELECT 'kluby'   AS co, COUNT(*) AS ile FROM clubs
  UNION ALL SELECT 'mecze',    COUNT(*) FROM matches
  UNION ALL SELECT 'raporty',  COUNT(*) FROM reports
  UNION ALL SELECT 'importy',  COUNT(*) FROM imports;
"

echo ""
echo "    Oczekiwane dla archiwum z 2026-09-24: 10 klubów, 23 mecze, 24 raporty."
echo "    Rozjazd znaczy, że zrzut jest z innego dnia niż opisuje docs/STAN_PIVOTU.md."

# Uploady bez wierszy w bazie (i odwrotnie) to najczęstszy objaw archiwum
# złożonego z dwóch różnych momentów. Liczymy, nie zgadujemy.
BRAK_PLIKOW=$(mysql_probna -N -B -e \
  "SELECT COUNT(*) FROM imports WHERE csv_path IS NOT NULL AND csv_path <> ''" 2>/dev/null || echo "?")
echo "    wierszy w imports ze ścieżką CSV: $BRAK_PLIKOW · plików w $CEL_REAL: $LICZBA_PLIKOW"

cat <<KONIEC

==> Gotowe. Odtworzono DANE wersji pro na bazie próbnej.

    Co jeszcze należy do człowieka (docs/STAN_PIVOTU.md, „Procedura powrotu"):
      1. git -C $REPO checkout $TAG_PRO
      2. bash $REPO/deploy/deploy.sh          # wdraża kod do katalogu domeny
      3. wygenerowanie raportu z linii poleceń (docs/RUNBOOK.md)

    Kroki 1–2 działają NA PRODUKCJI i ten skrypt ich nie wykonuje świadomie.

KONIEC
