# Golden layout — układ panelu CoachAnalyze

Stan po etapach W0–W3 (2026-09-27). Dokument opisuje **jak panel jest poukładany**
i dlaczego — zanim dołożysz ekran, sprawdź, gdzie jest jego rodzic i czy nie łamie
reguły powrotu.

---

## 1. Hierarchia

```
Pulpit ─┬─ Sezon ── Mecz (karta) ── Raport
        │
        └─ (poza hierarchią) Ustawienia klubu · Drużyna · Raporty · Linki · narzędzia
```

- **Pulpit** (`/pulpit`) — punkt wyjścia. Klub bieżący i sezon w jednej linii szyny.
- **Sezon** (`/sezon`) — tabela meczów sezonu: k., rywal, data, miejsce, wynik, xG,
  strzały, SBZ, pressing, stan raportu + SUMA.
- **Mecz** (`/mecze/{id}`) — karta meczu, zakładki `?zakladka=`: Dane, Skład, Pliki;
  [op]: Pokrycie, Wersje, Zadania.
- **Raport** (`/raport/{id}`) — samowystarczalny HTML v21 (bez JS panelu).
  Klips „← CA" wraca na `/pulpit` (link publiczny: strona sprzedażowa).

Ekrany poza hierarchią (ustawiane raz, nie przy każdym meczu) mają za rodzica Pulpit.

## 2. Reguła powrotu

**Każdy ekran ma jednego rodzica.** „Wróć" prowadzi tam, skąd przyszedłeś
(`?powrot=`), a bez parametru — do rodzica. Implementacja: `app/src/Powrot.php`.

- `powrot` przepuszczamy wyłącznie jako ścieżkę względną w aplikacji (bez schematu,
  hosta, `//`, `\`, znaków sterujących) — inaczej to przekierowanie phishingowe.
- „Wróć" **nigdy** nie prowadzi do `/kluby` (lista administracyjna) ani `/zadania`
  (strona techniczna [op]).
- Raport: cel klipsa i tryb odbiorcy wstawia PHP przy serwowaniu
  (`Powrot::wypelnijRaport`, znaczniki `__POWROT_URL__`, `__TRYB__`, `__KARTA_URL__`).

## 3. Role

| Rola w bazie | UI | Zakres |
|---|---|---|
| `admin` | Administrator | wszystko, w tym [op] |
| `operator` | Analityk | kluby z `clubs.owner_id` = jego id; import, karty meczów, Ustawienia klubu |
| `viewer` | Trener | dokładnie jeden klub (`users.club_id`), tylko odczyt |

Cudzy zasób = **404**, tak samo jak nieistniejący (`Zakres` + `straznikZakresu()`).
Konta klubowe nie widzą słów technicznych: silnik, templat, szablon vN, kolejka
(zadań), dysk, pojęcie kanoniczne, przelicz, wygeneruj ponownie, kod klubu.

Raport w trybach (`data-tryb`): `op` — wszystko, w tym zakładka Pokrycie i stopka
„templat vN"; `analityk` — bez [op]; `trener` — bez baneru „Wlicz w Słowniku";
`publiczny` — bez elementów panelu (LINK, baner).

## 4. Szyna

```
PULPIT
KLUB        Sezon · Drużyna (Zawodnicy) · Raporty · Wgraj eksport* · Linki · Ustawienia klubu*
NARZĘDZIA   Kalendarz · Indeks · Notatki · Kalkulator xG
[op] ADMINISTRACJA   Kluby · Użytkownicy · Zadania        + stopka: silnik, szablon, kolejka, dysk
```
`*` — bez trenera. Przełącznik klubu tylko przy więcej niż jednym klubie w zasięgu.

## 5. Ścieżka importu (W3)

```
Wgraj (/import) ──► Przygotuj (/import/{id}/przygotuj) ──► Postęp (/import/{id}/postep) ──► Raport
  plik CSV [+JSON]     rywal · data* · wynik (opc.)         „Czytam plik → Buduję raport"
```
- Bez ekranu różnic i bez kreatora mapowań na drodze analityka (to [op]).
- Import **nie zapisuje słownika klubu** — nowe tagi trafiają do „Nierozpoznanych"
  i na baner raportu. Zapis słownika: Ustawienia klubu → Słownik klubu.
- Administrator po wgraniu trafia na stronę zadania (mechanika w tle).

## 6. Lista ekranów

| Ekran | Adres | Rodzic | Role |
|---|---|---|---|
| Pulpit | `/pulpit` | — | wszystkie |
| Sezon | `/sezon` | Pulpit | wszystkie |
| Karta meczu | `/mecze/{id}` | Sezon | wszystkie (zakładki [op] — admin) |
| Raport | `/raport/{id}` | Karta meczu / Pulpit | wszystkie |
| Raport publiczny | `/r/{club_key}/{token}` | — | bez logowania |
| Wgraj eksport | `/import` | Pulpit | admin, analityk |
| Przygotuj raport | `/import/{id}/przygotuj` | Wgraj | admin, analityk |
| Postęp | `/import/{id}/postep` | Pulpit | admin, analityk |
| Drużyna / Zawodnicy | `/druzyna`, `/zawodnicy` | Pulpit | wszystkie |
| Raporty | `/raporty` | Pulpit | wszystkie |
| Linki | `/linki` | Pulpit | admin, analityk |
| Ustawienia klubu | `/klub/ustawienia?zakladka=uklad\|slownik` | Pulpit | admin, analityk |
| Ustawienia → Zaawansowane | `/klub/ustawienia?zakladka=zaawansowane` | Ustawienia | admin |
| Sezony | `/sezony` | Ustawienia klubu | admin, analityk |
| Kalendarz, Indeks, Notatki, Kalkulator xG | `/kalendarz`, `/indeks`, `/notatki`, `/xg` | Pulpit | wszystkie |
| Kluby, Użytkownicy, Zadania | `/kluby`, `/uzytkownicy`, `/zadania` | Pulpit | admin |
| Ekran różnic, mapowania, pokrycie importu | `/import/{id}/diff`, `/import/{id}/mapowanie`, `/import/{id}` | Karta meczu | admin (pokrycie też analityk) |
| Historia wersji, przeliczenie klubu | `/klub/{id}/templaty`, `/klub/{id}/przelicz` | Zaawansowane | admin |

## 7. Ustawienia klubu (W3)

- **Układ raportu** — kolejność (strzałki), Ukryj/Pokaż; numery 01–NN bez luk;
  Przegląd zawsze pierwszy i widoczny; „Inne zdarzenia" tylko przy nierozpoznanych tagach.
- **Słownik klubu** — Wliczane (tag → nazwa w raporcie → sekcje, kontynuacje)
  i Nierozpoznane (z liczbą zdarzeń) z „Wlicz jako… nowa zmienna / kontynuacja X".
  Tag wbudowany szablonu (`VARS`, np. SKUTECZNY) liczy się sam i nie jest „nierozpoznany".
- **[op] Zaawansowane** — historia wersji z „Przywróć", zmienne martwe, konfigurator
  (typ, barwa, kanon), mapowania, szerokości kafli, dane klubu.
- Każdy zapis = nowa wersja templatu + odświeżenie raportów klubu w tle + chmurka.

## 8. JavaScript w panelu

Jeden plik (`app/public/assets/powiadomienia.js`), trzy zadania: chmurki, wskaźnik
pracy, walidacja pliku na ekranie Wgraj. Szczegóły i granice — `CLAUDE.md` §9.
Raport nie ma i nie będzie miał skryptu panelu.
