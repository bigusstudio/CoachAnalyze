#!/usr/bin/env bash
# Pierwsze uruchomienie na lh.pl. Idempotentne — można puścić ponownie.
# Uruchamiać PO wypchnięciu commitu zerowego na GitHuba.
set -euo pipefail

BASE="$HOME/CoachAnalyze"
DOMENA="${CA_DOMAIN_DIR:-$HOME/public_html/app.coachanalyze.pl}"

echo "==> Katalogi"
mkdir -p "$BASE"/{releases,shared}

# Pliki użytkownika MUSZĄ leżeć w drzewie domeny, nie w ~/CoachAnalyze/shared.
# PHP-FPM ma `open_basedir` ograniczony do katalogu domeny, a dowiązanie poza
# niego jest dla FPM niewidoczne także przy ZAPISIE — upload kończył się wtedy
# błędem bez wyjaśnienia. Ochronę przed serwowaniem daje `.htaccess`, nie
# położenie poza drzewem. Szczegóły: docs/OGRANICZENIA_HOSTINGU.md.
mkdir -p "$DOMENA"/storage/{uploads,reports,crests,golden}
chmod 700 "$DOMENA/storage"

# Log również wewnątrz open_basedir — ~/tmp jest na liście i dosięga go
# zarówno FPM, jak i cron. `shared/logs` byłoby dla panelu ślepe.
mkdir -p "$HOME/tmp"

if [ -d "$BASE/shared/storage" ]; then
  echo "    UWAGA: $BASE/shared/storage pochodzi ze starego układu i nie jest już używane."
  echo "    Przenieś zawartość do $DOMENA/storage, a katalog skasuj ręcznie."
fi

echo "==> Repozytorium"
if [ -d "$BASE/repo/.git" ]; then
  git -C "$BASE/repo" pull --ff-only
else
  git clone git@github.com:bigusstudio/CoachAnalyze.git "$BASE/repo"
fi

echo "==> Środowisko Pythona (bez zależności kompilowanych — noexec na /home)"
if [ ! -x "$BASE/venv/bin/python" ]; then
  python3.11 -m venv --without-pip "$BASE/venv"
  curl -sS https://bootstrap.pypa.io/get-pip.py -o /tmp/get-pip.py
  "$BASE/venv/bin/python" /tmp/get-pip.py
fi
"$BASE/venv/bin/python" -m pip install -q -e "$BASE/repo/engine"
"$BASE/venv/bin/python" -m coachanalyze --version

echo "==> Konfiguracja"
if [ ! -f "$BASE/shared/.env" ]; then
  cp "$BASE/repo/.env.example" "$BASE/shared/.env"
  chmod 600 "$BASE/shared/.env"
  echo "UWAGA: uzupełnij $BASE/shared/.env (DB_PASS)"
fi

echo "==> Gotowe. Następnie: migracje bazy i symlink katalogu publicznego."
