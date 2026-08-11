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
#   ~/CoachAnalyze/shared/.env                 <- sekrety
#   ~/CoachAnalyze/shared/storage              <- dane (uploady, raporty)
#   ~/public_html/app.coachanalyze.pl/         <- SYNCHRONIZOWANY katalog webowy
#
# Wycofanie: git -C ~/CoachAnalyze/repo checkout <commit> && bash deploy/deploy.sh
set -euo pipefail

BASE="$HOME/CoachAnalyze"
WEB="$HOME/public_html/app.coachanalyze.pl"
BRANCH="${1:-main}"

echo "==> Wdrożenie gałęzi $BRANCH"

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

echo "==> Dowiązania do zasobów współdzielonych"
ln -sfn "$BASE/shared/.env"     "$WEB/.env"
ln -sfn "$BASE/shared/storage"  "$WEB/storage"

echo "==> Kontrola ochrony (te adresy MUSZĄ zwrócić 403)"
for p in app/src/bootstrap.php .env storage/; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "http://app.coachanalyze.pl/$p" || echo "000")
  printf "    /%-24s -> %s\n" "$p" "$code"
  [ "$code" = "403" ] || [ "$code" = "404" ] || echo "    !!! OSTRZEŻENIE: plik dostępny publicznie"
done

echo "==> Gotowe: $REV"
