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

# Źródło prawdy dla sekretów. Kopia w katalogu domeny powstaje dopiero przy
# synchronizacji, a zrzut bazy robimy PRZED nią — czytamy więc z oryginału.
ENV_ZRODLO="$BASE/shared/.env"

# Wartość pojedynczego klucza z `.env`.
#
# Zdejmujemy cudzysłowy, gdy otaczają CAŁĄ wartość — tak samo jak parser w PHP
# (app/src/Config.php). Hasło do bazy bywa cytowane, a `mysqldump` z hasłem
# w cudzysłowach po prostu nie zaloguje się, nie mówiąc dlaczego.
#
# `tail -1`, bo przy powtórzonym kluczu obowiązuje ostatni wpis — znowu tak,
# jak robi to parser PHP. Rozjazd między tymi dwoma odczytami byłby błędem,
# który ujawnia się wyłącznie na produkcji.
wartosc_env() {
  local klucz="$1" plik="${2:-$ENV_ZRODLO}" wartosc
  wartosc=$(grep -E "^${klucz}=" "$plik" 2>/dev/null | tail -1 | cut -d= -f2- || true)
  wartosc="${wartosc%\"}"; wartosc="${wartosc#\"}"
  wartosc="${wartosc%\'}"; wartosc="${wartosc#\'}"
  printf '%s' "$wartosc"
}

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

echo "==> Zrzut bazy przed wdrożeniem"
mkdir -p "$BASE/shared/backups"

# NAZWA BAZY MUSI BYĆ PODANA JAWNIE.
#
# `mysqldump` bez nazwy bazy nie wie, co zrzucić: `~/.my.cnf` przechowuje
# dane logowania, a nie wybór bazy. Wpis `database=` został z niego świadomie
# usunięty, bo psuł samo `mysqldump` (klient traktuje go jako pierwszy argument
# pozycyjny i myli z nazwą tabeli).
#
# Objaw był mylący: zrzut „pomijał się" z komunikatem o `~/.my.cnf`, choć ten
# sam `mysqldump` z nazwą bazy działał ręcznie bez zarzutu.
DB_NAME=$(wartosc_env DB_NAME)

if [ -z "$DB_NAME" ]; then
  echo "    !!! BŁĄD: brak DB_NAME w $ENV_ZRODLO — nie wiem, którą bazę zrzucić"
  exit 1
fi

ZRZUT="$BASE/shared/backups/pre-$(date +%Y%m%d-%H%M%S).sql"

# Błąd `mysqldump` NIE JEST tu pomijalny i nie idzie do /dev/null.
#
# Dotąd `2>/dev/null || echo` gubiło prawdziwą przyczynę i zostawiało plik
# `pre-*.sql` — pusty albo urwany w połowie. Taki plik wygląda w katalogu kopii
# dokładnie jak kopia i zostaje odkryty dopiero przy próbie odtworzenia bazy,
# czyli w najgorszym możliwym momencie.
# Zapis do pliku tymczasowego, przeniesienie dopiero po powodzeniu: `>` obcina
# plik docelowy, zanim `mysqldump` cokolwiek wypisze, więc nieudany przebieg
# zostawiałby urwany plik wyglądający jak kopia.
ZRZUT_TMP=$(mktemp "$BASE/shared/backups/.pre-XXXXXX")
trap 'rm -f "$ZRZUT_TMP" "$ZRZUT_TMP.blad"' EXIT

if mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
     "$DB_NAME" > "$ZRZUT_TMP" 2> "$ZRZUT_TMP.blad"; then
  mv -f "$ZRZUT_TMP" "$ZRZUT"
  rm -f "$ZRZUT_TMP.blad"
  trap - EXIT
  echo "    baza $DB_NAME -> $(basename "$ZRZUT") ($(wc -c < "$ZRZUT" | tr -d ' ') B)"
else
  echo "    !!! BŁĄD: nie udało się zrzucić bazy $DB_NAME"
  sed 's/^/        /' "$ZRZUT_TMP.blad" >&2 || true
  exit 1
fi

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

# Czytamy z KOPII w katalogu domeny, nie ze źródła: sprawdzamy stan, z którym
# faktycznie wystartuje PHP-FPM, a nie ten, który zamierzaliśmy wdrożyć.
STORAGE_PATH=$(wartosc_env STORAGE_PATH "$WEB/.env")
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

echo "==> Kontrola wymuszenia HTTPS"
#
# POWÓD ISTNIENIA TEJ KONTROLI: przekierowanie niesie `app/public/.htaccess`,
# a przejście 2 rsync NADPISUJE ten plik wersją z repozytorium. Reguła włączona
# ręcznie na serwerze przeżywa więc dokładnie do najbliższego wdrożenia — i to
# wdrożenie melduje „Gotowe", bo nic tego nie sprawdzało.
#
# Skutek wyłączenia jest cichy i całkowity: panel wraca na HTTP, a ciasteczko
# sesji ma flagę `Secure` (bierze ją z APP_URL), więc przestaje wracać. Token
# CSRF nie ma się gdzie zapisać i logowanie odbija KAŻDĄ próbę. Aplikacja nazwie
# przyczynę na ekranie („Strona została otwarta bez szyfrowania…"), ale nikt nie
# będzie mógł wejść do panelu.
#
# Odpowiedź czyta `curl`, nie awk: `%{redirect_url}` bierze się z nagłówka
# `Location` bez parsowania tekstu, więc nie zależy od pisowni nagłówka
# ani od zakończeń linii.
HTTPS_PROBKA=$(curl -sI -o /dev/null \
  -w '%{http_code} %{redirect_url}' \
  --max-time 15 "http://app.coachanalyze.pl/login" || echo "000 ")
HTTPS_KOD=${HTTPS_PROBKA%% *}
HTTPS_CEL=${HTTPS_PROBKA#* }

if [ "$HTTPS_KOD" = "301" ] && [ "${HTTPS_CEL#https://}" != "$HTTPS_CEL" ]; then
  echo "    HTTP -> $HTTPS_CEL (301)          OK"
else
  echo "    !!! BŁĄD: brak przekierowania HTTP -> HTTPS na /login"
  echo "        oczekiwano: 301 + Location: https://…"
  echo "        otrzymano:  ${HTTPS_KOD:-brak odpowiedzi} + Location: ${HTTPS_CEL:-brak}"
  echo "        Odkomentuj reguły 'RewriteCond %{HTTPS} !=on' w app/public/.htaccess"
  echo "        W REPOZYTORIUM, nie tylko w $WEB/.htaccess — rsync nadpisuje ten plik."
  FAIL=1
fi

echo "==> Kontrola nagłówków bezpieczeństwa"
#
# Nagłówki ustawia WYŁĄCZNIE `.htaccess` (patrz komentarz w `app/src/bootstrap.php`:
# `Header always set` i tak zastępowało wersję z PHP, więc dublowanie zostało
# usunięte). Zapasowego ustawienia po stronie aplikacji już nie ma, więc literówka
# w tym pliku albo hosting bez `mod_headers` zdejmują ochronę bez śladu w logu.
NAGLOWKI=$(curl -sI --max-time 15 "https://app.coachanalyze.pl/login" || echo "")

# Nazwa i wartość osobno. Po HTTP/2 `curl` wypisuje nazwy małymi literami, a
# liczba odstępów po dwukropku należy do serwera — dopasowanie przywiązane do
# jednego zapisu zapalałoby się na czerwono bez żadnej zmiany w konfiguracji.
#
# `Strict-Transport-Security` sprawdzamy razem z resztą, bo dzieli z nimi jedyne
# źródło (`.htaccess`) i jedyny tryb awarii: znika po cichu, bez śladu w logu.
# Wartość MUSI się zgadzać co do znaku — `max-age` podniesiony przypadkiem
# wydłuża okno, w którym awaria certyfikatu blokuje panel, a tego nie da się
# cofnąć wdrożeniem, tylko czekaniem.
for para in "X-Frame-Options=DENY" "Referrer-Policy=no-referrer" \
            "X-Content-Type-Options=nosniff" "Strict-Transport-Security=max-age=31536000"; do
  nazwa=${para%%=*}
  wartosc=${para#*=}
  if printf '%s' "$NAGLOWKI" | grep -qiE "^${nazwa}:[[:space:]]*${wartosc}[[:space:]]*$"; then
    printf '    %-34s %s\n' "$nazwa" "$wartosc   OK"
  else
    echo "    !!! BŁĄD: brak nagłówka '$nazwa: $wartosc'"
    echo "        otrzymano: $(printf '%s' "$NAGLOWKI" | grep -i "^${nazwa}:" | tr -d '\r' || echo 'nic')"
    echo "        Sprawdź blok <IfModule mod_headers.c> w app/public/.htaccess"
    FAIL=1
  fi
done

echo "==> Kontrola buforowania zasobów statycznych"
#
# Adresy `/assets/*` wychodzą z aplikacji z parametrem `?v=<mtime>`
# (`View::asset()`), a `.htaccess` buforuje bezterminowo WYŁĄCZNIE takie adresy.
# Obie strony tej reguły da się potwierdzić dopiero tutaj: wbudowany serwer PHP
# w testach integracyjnych nie czyta `.htaccess`, więc test statyczny sprawdza
# treść pliku, a nie odpowiedź serwera.
#
# Wartość `v` jest dowolna — reguła patrzy na WZORZEC parametru, nie na to, czy
# znacznik zgadza się z czasem pliku. Stąd `?v=kontrola` zamiast liczenia mtime.
#
# ASYMETRIA OCEN JEST CELOWA I NIE JEST NIEDOCIĄGNIĘCIEM:
#   - adres BEZ wersji odpowiadający `immutable` to BŁĄD. Przypina plik
#     w przeglądarkach na rok, a odkręcić to można wyłącznie zmianą nazwy pliku
#     — żadne wdrożenie tego nie cofnie.
#   - adres Z wersją bez `immutable` to tylko OSTRZEŻENIE. Świeżość gwarantuje
#     sam parametr `?v=`: po wdrożeniu przeglądarka dostaje nowy adres, dla
#     którego nie ma kopii. Tracimy oszczędność, nie poprawność — a to za mało,
#     żeby zatrzymać wdrożenie.
CACHE_WERSJA=$(curl -sI --max-time 15 "https://app.coachanalyze.pl/assets/app.css?v=kontrola" \
  | grep -i '^cache-control:' | tr -d '\r' || echo "")
CACHE_BEZ=$(curl -sI --max-time 15 "https://app.coachanalyze.pl/assets/app.css" \
  | grep -i '^cache-control:' | tr -d '\r' || echo "")

if printf '%s' "$CACHE_WERSJA" | grep -qi 'immutable'; then
  echo "    /assets/app.css?v=…   immutable          OK"
else
  echo "    UWAGA: /assets/app.css?v=… nie odpowiada 'immutable'"
  echo "        otrzymano: ${CACHE_WERSJA:-brak nagłówka Cache-Control}"
  echo "        Świeżość zapewnia sam ?v= — to strata oszczędności, nie poprawności."
  echo "        Sprawdź RewriteRule [E=CA_ZASOB_WERSJONOWANY:1] w app/public/.htaccess"
fi

if printf '%s' "$CACHE_BEZ" | grep -qi 'immutable'; then
  echo "    !!! BŁĄD: /assets/app.css BEZ wersji odpowiada 'immutable'"
  echo "        otrzymano: $CACHE_BEZ"
  echo "        To przypina plik w przeglądarkach na rok. Odkręcenie wymaga zmiany"
  echo "        nazwy pliku — wdrożeniem się tego nie cofnie."
  FAIL=1
elif printf '%s' "$CACHE_BEZ" | grep -qi 'must-revalidate'; then
  echo "    /assets/app.css (bez wersji)  must-revalidate   OK"
else
  echo "    UWAGA: /assets/app.css bez wersji nie ma 'must-revalidate'"
  echo "        otrzymano: ${CACHE_BEZ:-brak nagłówka Cache-Control}"
  echo "        Bez tego obowiązuje buforowanie heurystyczne — to był pierwotny błąd."
fi

if [ "$FAIL" -ne 0 ]; then
  echo "==> WDROŻENIE Z BŁĘDAMI (${REV:-kontrola}) — popraw powyższe zanim uznasz je za zakończone"
  exit 1
fi

echo "==> Gotowe: ${REV:-kontrola}"
