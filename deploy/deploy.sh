#!/usr/bin/env bash
# Wdrożenie CoachAnalyze na lh.pl.
#
# UKŁAD WYMUSZONY PRZEZ open_basedir (patrz docs/OGRANICZENIA_HOSTINGU.md):
#   PHP-FPM widzi WYŁĄCZNIE katalog domeny, więc aplikacja webowa musi tam mieszkać.
#   Silnik Pythona i repozytorium zostają poza nim — CLI i proc_open nie podlegają
#   temu ograniczeniu, co zostało zweryfikowane na serwerze.
#
#   ~/CoachAnalyze/repo                        <- źródło (git)
#   ~/CoachAnalyze/venv                        <- silnik Pythona
#   ~/CoachAnalyze/shared/.env                 <- sekrety (źródło prawdy)
#   ~/tmp/                                     <- log aplikacji (jest w open_basedir)
#   ~/public_html/app.coachanalyze.pl/         <- SYNCHRONIZOWANY katalog webowy
#       ├── .env                               <- KOPIA, nie dowiązanie
#       └── storage/                           <- PRAWDZIWY katalog, nie dowiązanie
#
# DOWIĄZANIA POZA open_basedir SĄ DLA FPM NIEWIDOCZNE — także przy zapisie.
# Dlatego `.env` jest kopiowany, a `storage/` jest zwykłym katalogiem w drzewie
# domeny. Oba są wykluczone z rsync, więc przeżywają wdrożenie.
#
# Wycofanie: git -C ~/CoachAnalyze/repo checkout <commit> && bash deploy/deploy.sh
set -euo pipefail

# Ścieżki dają się nadpisać, żeby kontrolę wdrożenia można było uruchomić
# na kopii układu katalogów — inaczej jedyną drogą sprawdzenia, czy działa,
# byłoby wdrożenie na produkcję.
BASE="${CA_BASE:-$HOME/CoachAnalyze}"
WEB="${CA_WEB:-$HOME/public_html/app.coachanalyze.pl}"
BRANCH="${1:-main}"

# `--tylko-kontrola` pomija pobranie zmian i synchronizację, uruchamia same
# sprawdzenia. Przydaje się po ręcznej zmianie w `.env` na serwerze.
TYLKO_KONTROLA=0
if [ "${1:-}" = "--tylko-kontrola" ]; then
  TYLKO_KONTROLA=1
  BRANCH="${2:-main}"
fi

if [ "$TYLKO_KONTROLA" -eq 1 ]; then
  echo "==> Sama kontrola wdrożenia (bez synchronizacji)"
else
  echo "==> Wdrożenie gałęzi $BRANCH"
fi

if [ "$TYLKO_KONTROLA" -eq 0 ]; then

echo "==> Pobranie zmian"
git -C "$BASE/repo" fetch --all --quiet
git -C "$BASE/repo" checkout "$BRANCH" --quiet
git -C "$BASE/repo" pull --ff-only --quiet
REV=$(git -C "$BASE/repo" rev-parse --short HEAD)

echo "==> Silnik Pythona"
"$BASE/venv/bin/python" -m pip install -q -e "$BASE/repo/engine"

echo "==> Test złoty przed publikacją (czerwony = brak wdrożenia)"
"$BASE/venv/bin/python" -m pytest "$BASE/repo/engine/tests" -q

echo "==> Zrzut bazy przed migracjami"
mkdir -p "$BASE/shared/backups"
mysqldump --single-transaction > "$BASE/shared/backups/pre-$(date +%Y%m%d-%H%M%S).sql" 2>/dev/null \
  || echo "    (pominięto — sprawdź ~/.my.cnf)"

echo "==> Synchronizacja katalogu webowego"
mkdir -p "$WEB"
# Przejście 1: kod aplikacji do podkatalogu app/.
rsync -a --delete \
  --exclude='.env' --exclude='storage/' --exclude='.git/' \
  "$BASE/repo/app/" "$WEB/app/"

# Przejście 2: zawartość public/ na poziom katalogu domeny.
#
# WYKLUCZENIA SĄ OBOWIĄZKOWE I NIE WOLNO ICH USUWAĆ.
# Źródłem jest `app/public/`, a celem katalog domeny, w którym leżą już `app/`,
# `.env` i `storage/`. Bez `--exclude` `--delete` uznaje je za nadmiarowe
# i KASUJE — razem z całym kodem aplikacji skopiowanym przed chwilą.
#
# Ten błąd wystąpił już dwa razy: naprawiony w 01b1dc7, cofnięty przy okazji
# niepowiązanej zmiany w 73eecb7. Pilnuje go teraz app/tests/test_layout.php.
rsync -a --delete \
  --exclude='app/' --exclude='.env' --exclude='storage/' \
  "$BASE/repo/app/public/" "$WEB/"

echo "==> Zasoby współdzielone (KOPIA, nie dowiązanie)"
#
# ŻADNYCH DOWIĄZAŃ DO CELÓW POZA open_basedir.
# `open_basedir` sprawdza ścieżkę PO ROZWINIĘCIU dowiązania, więc symlink
# z katalogu domeny do `~/CoachAnalyze/shared/` jest dla PHP-FPM niewidoczny —
# zarówno przy odczycie, jak i przy zapisie. Wersja z `ln -s` „działała"
# wyłącznie z CLI i sypała się dopiero w przeglądarce.
# Szczegóły: docs/OGRANICZENIA_HOSTINGU.md.

# .env — zwykły plik w katalogu domeny. Źródłem prawdy zostaje shared/.env,
# tutaj trafia jego kopia przy każdym wdrożeniu.
cp -f "$BASE/shared/.env" "$WEB/.env"
chmod 640 "$WEB/.env"

# storage/ — prawdziwy katalog w katalogu domeny, NIE dowiązanie. FPM musi móc
# tu zapisywać (upload eksportu), a przez dowiązanie nie może.
# Katalog jest wykluczony z obu przejść rsync, więc dane przeżywają wdrożenie.
mkdir -p "$WEB/storage/uploads" "$WEB/storage/reports" "$WEB/storage/crests" "$WEB/storage/jobs"
chmod 750 "$WEB/storage"
[ -f "$WEB/storage/.htaccess" ] || cp "$BASE/repo/storage/.htaccess" "$WEB/storage/.htaccess"

# Log aplikacji musi leżeć w katalogu z listy open_basedir — inaczej aplikacja
# nie ma gdzie zapisać przyczyny błędu i diagnostyka jest niema.
mkdir -p "$HOME/tmp"

if [ -d "$BASE/shared/storage" ] && [ -n "$(ls -A "$BASE/shared/storage" 2>/dev/null || true)" ]; then
  echo "    UWAGA: ~/CoachAnalyze/shared/storage nie jest już używane przez aplikację."
  echo "           Przenieś dane do $WEB/storage i obejmij ten katalog kopią zapasową."
fi

fi   # koniec kroków pomijanych przy --tylko-kontrola

echo "==> Kontrola wdrożenia"
FAIL=0

# 1. .env musi być ZWYKŁYM PLIKIEM. Dowiązanie = FPM go nie przeczyta i aplikacja
#    zgłosi „Logowanie jest chwilowo niedostępne" bez wskazania przyczyny.
if [ -L "$WEB/.env" ]; then
  echo "    !!! BŁĄD: $WEB/.env jest dowiązaniem — FPM go nie odczyta"
  FAIL=1
elif [ -f "$WEB/.env" ]; then
  echo "    .env: zwykły plik                      OK"
else
  echo "    !!! BŁĄD: brak $WEB/.env"
  FAIL=1
fi

# 2. STORAGE_PATH — sprawdzamy ŚCIEŻKĘ Z KONFIGURACJI, nie katalog utworzony
#    przed chwilą przez ten skrypt.
#
#    POWÓD: poprzednia wersja sprawdzała `$WEB/storage`, czyli katalog, który
#    sama tworzyła. Kontrola przechodziła zawsze, a aplikacja czytała
#    STORAGE_PATH z `.env` i szła zupełnie gdzie indziej — do `shared/storage`,
#    poza `open_basedir`. Upload padał, a wdrożenie meldowało „OK".
#
#    Sprawdzamy WARTOŚĆ, której faktycznie użyje aplikacja, i to, czy leży ona
#    wewnątrz katalogu domeny — bo tylko tam sięga PHP-FPM.
#
#    Wybór kontroli zamiast podmiany: gdyby deploy nadpisywał STORAGE_PATH przy
#    kopiowaniu, produkcja miałaby inną konfigurację niż źródło prawdy i pierwsza
#    osoba szukająca „dlaczego ta ścieżka jest inna" straciłaby godzinę. Lepiej
#    zatrzymać wdrożenie i kazać poprawić `shared/.env` raz.

# Fizyczna ścieżka katalogu, z rozwinięciem dowiązań. `readlink -f` nie jest
# przenośne, `cd + pwd -P` jest.
rozwin_katalog() { ( cd "$1" 2>/dev/null && pwd -P ) || return 1; }

STORAGE_PATH=$(grep -E '^STORAGE_PATH=' "$WEB/.env" 2>/dev/null | tail -1 | cut -d= -f2- || true)
WEB_REAL=$(rozwin_katalog "$WEB" || echo "$WEB")

if [ -z "$STORAGE_PATH" ]; then
  echo "    !!! BŁĄD: brak STORAGE_PATH w .env"
  FAIL=1
elif [ ! -d "$STORAGE_PATH" ]; then
  echo "    !!! BŁĄD: STORAGE_PATH ($STORAGE_PATH) nie jest katalogiem"
  FAIL=1
else
  STORAGE_REAL=$(rozwin_katalog "$STORAGE_PATH" || echo "")

  if [ -z "$STORAGE_REAL" ]; then
    echo "    !!! BŁĄD: nie mogę rozwinąć STORAGE_PATH ($STORAGE_PATH)"
    FAIL=1
  elif [ "${STORAGE_REAL#"$WEB_REAL"/}" = "$STORAGE_REAL" ]; then
    # Ścieżka po rozwinięciu NIE zaczyna się od katalogu domeny — czyli leży
    # poza open_basedir i FPM jej nie dosięgnie, choćby katalog istniał.
    echo "    !!! BŁĄD: STORAGE_PATH leży poza katalogiem domeny"
    echo "        w .env:    $STORAGE_PATH"
    echo "        po rozwinięciu: $STORAGE_REAL"
    echo "        wymagane:  wewnątrz $WEB_REAL"
    echo "        Popraw STORAGE_PATH w $BASE/shared/.env na $WEB/storage"
    FAIL=1
  # Zapisywalność sprawdzamy PRÓBĄ ZAPISU, nie testem `-w`: na tym hostingu
  # testy dostępu potrafią kłamać na ścieżkach spoza open_basedir.
  elif touch "$STORAGE_REAL/.probe" 2>/dev/null && rm -f "$STORAGE_REAL/.probe"; then
    echo "    STORAGE_PATH wewnątrz domeny i zapisywalny   OK"
  else
    echo "    !!! BŁĄD: STORAGE_PATH ($STORAGE_REAL) niezapisywalny"
    FAIL=1
  fi
fi

# 3. LOG_PATH musi wskazywać katalog, w którym da się pisać. Bez tego przyczyna
#    awarii nie ma gdzie trafić — dokładnie to kosztowało godzinę diagnostyki.
LOG_PATH=$(grep -E '^LOG_PATH=' "$WEB/.env" 2>/dev/null | tail -1 | cut -d= -f2- || true)
if [ -z "$LOG_PATH" ]; then
  echo "    !!! BŁĄD: brak LOG_PATH w .env"
  FAIL=1
else
  LOG_DIR=$(dirname "$LOG_PATH")
  mkdir -p "$LOG_DIR" 2>/dev/null || true
  # Sam `touch`, bez `-w`: test dostępu kłamie na ścieżkach spoza open_basedir.
  if touch "$LOG_PATH" 2>/dev/null; then
    echo "    LOG_PATH zapisywalny: $LOG_DIR  OK"
  else
    echo "    !!! BŁĄD: LOG_PATH ($LOG_PATH) niezapisywalny albo poza open_basedir"
    FAIL=1
  fi
fi

echo "==> Kontrola ochrony (te adresy MUSZĄ zwrócić 403)"
for p in app/src/bootstrap.php .env storage/ storage/uploads/; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "http://app.coachanalyze.pl/$p" || echo "000")
  printf "    /%-24s -> %s\n" "$p" "$code"
  if [ "$code" != "403" ] && [ "$code" != "404" ]; then
    echo "    !!! BŁĄD: plik dostępny publicznie"
    FAIL=1
  fi
done

if [ "$FAIL" -ne 0 ]; then
  echo "==> WDROŻENIE Z BŁĘDAMI (${REV:-kontrola}) — popraw powyższe zanim uznasz je za zakończone"
  exit 1
fi

echo "==> Gotowe: ${REV:-kontrola}"
