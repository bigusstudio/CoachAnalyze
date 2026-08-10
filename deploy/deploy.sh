#!/usr/bin/env bash
# Wdrożenie na lh.pl. Uruchamiane po SSH z katalogu projektu.
# Wycofanie: ln -sfn releases/<poprzednie> current
set -euo pipefail

BASE="${BASE:-$HOME/CoachAnalyze}"
BRANCH="${1:-main}"
STAMP=$(date +%Y%m%d-%H%M%S)
RELEASE="$BASE/releases/$STAMP"

echo "==> Wydanie $STAMP z gałęzi $BRANCH"

mkdir -p "$BASE/releases"
git -C "$BASE/repo" fetch --all --quiet
git -C "$BASE/repo" checkout "$BRANCH" --quiet
git -C "$BASE/repo" pull --ff-only --quiet
cp -a "$BASE/repo" "$RELEASE"

# Katalogi współdzielone między wydaniami — nigdy w repozytorium
ln -sfn "$BASE/shared/.env"    "$RELEASE/.env"
ln -sfn "$BASE/shared/storage" "$RELEASE/storage"

echo "==> Środowisko Python"
if [ ! -d "$BASE/venv" ]; then
  python3.11 -m venv "$BASE/venv"
fi
"$BASE/venv/bin/python" -m pip install -q -e "$RELEASE/engine"   # przez interpreter: pip w bin/ nie ma prawa wykonywania

echo "==> Zrzut bazy przed migracjami (obowiązkowy — migracje nie cofają się same)"
"$BASE/repo/deploy/backup_db.sh" "pre-$STAMP"

echo "==> Migracje"
php "$RELEASE/app/bin/migrate.php"

echo "==> Test złoty przed przełączeniem"
"$BASE/venv/bin/python" -m pytest "$RELEASE/engine/tests" -q

echo "==> Przełączenie symlinku"
ln -sfn "$RELEASE" "$BASE/current"

echo "==> Sprzątanie starych wydań (zostawiamy 5)"
ls -1dt "$BASE"/releases/*/ | tail -n +6 | xargs -r rm -rf

echo "==> Gotowe: $(cd "$RELEASE" && git rev-parse --short HEAD)"
