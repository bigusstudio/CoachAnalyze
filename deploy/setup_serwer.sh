#!/usr/bin/env bash
# Pierwsze uruchomienie na lh.pl. Idempotentne — można puścić ponownie.
# Uruchamiać PO wypchnięciu commitu zerowego na GitHuba.
set -euo pipefail

BASE="$HOME/CoachAnalyze"
echo "==> Katalogi"
mkdir -p "$BASE"/{releases,shared/logs,shared/storage/{uploads,reports,crests,golden}}
chmod 700 "$BASE/shared/storage"

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
