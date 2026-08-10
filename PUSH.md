# Jak wrzucić commit zerowy do repozytorium

Wykonać lokalnie, po rozpakowaniu archiwum.

```bash
cd CoachAnalyze

git init
git branch -M main
git add .
git commit -m "Commit zerowy: struktura, kontrakty, dokumentacja, CI"

git remote add origin git@github.com:bigusstudio/CoachAnalyze.git
git push -u origin main

# Gałąź staging
git checkout -b dev
git push -u origin dev
```

## Ustawienia repozytorium po pierwszym pushu

1. **Widoczność: private.** Historia zawiera całą wiedzę o formacie LiveTag.
2. **Ochrona `main`** — Settings → Branches: wymagany przechodzący status check `Testy silnika`,
   zakaz push bezpośrednio na `main`.
3. **Klucz deploy** — Settings → Deploy keys, read-only. Klucz generujesz na serwerze:
   ```bash
   ssh-keygen -t ed25519 -C "coachanalyze-deploy" -f ~/.ssh/coachanalyze_deploy
   cat ~/.ssh/coachanalyze_deploy.pub    # ten tekst wklejasz w Deploy keys
   ```
4. **Collaborator** — dostęp `write` dla osób pracujących nad projektem.

## Czego NIE wrzucać

- `.env` z sekretami (jest w `.gitignore`, ale warto sprawdzić przed pierwszym pushem)
- Eksportów CSV/JSON z meczów — to dane taktyczne klienta.
  Zestaw złoty trzyma w repo wyłącznie skróty, pliki leżą lokalnie i w `storage/golden/` na serwerze.

Kontrola przed pushem:

```bash
git status --porcelain --ignored | grep -E '\.(csv|env)$'   # powinno pokazać je jako ignorowane
```
