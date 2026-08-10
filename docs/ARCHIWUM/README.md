# ARCHIWUM

Dokumenty nieaktualne, zachowane dla kontekstu. **Nie realizować.**

## `2026-07-21_standalone_projekt.md`

Plan wersji client-side. Nieaktualny w warstwie architektury: zakładał port parsera z Pythona
do JavaScriptu i profile użytkownika w IndexedDB. Decyzja D1 (Python zostaje na serwerze) to uchyliła —
port był najbardziej ryzykowną pozycją we wszystkich wcześniejszych wersjach planu.

**Co z niego zostaje jako materiał wsadowy:**
- §6 — rejestr sekcji z deklaracją wymagań wobec danych → trafia do modułu M5 (kreator raportów),
  ale zadeklarowany po stronie Pythona, nie JS
- §7 — tabela pułapek eksportu → przeniesiona do `CLAUDE.md` §3 i `docs/FORMAT_LIVETAG.md`
- §4 — mapowanie funkcji parsera → wskazówka przy refaktorze, nie plan implementacji
