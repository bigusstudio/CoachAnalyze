# LiveTag Dashboard — Standalone (self-service) — dokument projektowy

**Wersja dokumentu:** 1.0 · **Data:** 2026-07-21 · **Autor kontekstu:** bigus.studio
**Punkt wyjścia:** build-pipeline v17 (`build_dashboard.py` + `dashboard_template.html`)
**Cel:** roadmapa README p. 7.3 — „Wersja z uploadem: dashboard przyjmujący CSV bez rebuildu (produkt powtarzalny)".

**Zakres tej iteracji (decyzje ustalone z użytkownikiem):**

- Efekt sesji: **dokument projektowy** (architektura), bez kodu produkcyjnego.
- Konta: **client-side, bez backendu** na start — profil lokalny w przeglądarce; realny backend jako świadomie zaplanowana kolejna faza.
- Uniwersalność: **na razie ten mecz** (wizualnie Hutnik/Pogoń, herby i kolory zaszyte); generalizacja na dowolne drużyny to osobny, opisany tu etap.

---

## 1. Skąd wychodzimy i dokąd idziemy

### 1.1 Stan obecny (build-pipeline)

Dziś proces jest dwuetapowy i wymaga Ciebie przy komputerze:

1. Eksport z LiveTag.Pro → `tagging.csv` + `tagging.json`.
2. `python3 build_dashboard.py tagging.csv tagging.json output.html` — Python parsuje dane, liczy przerwę, koryguje paletę i **wstrzykuje** JSON w placeholdery `/*__DATA__*/` i `/*__PAL__*/` w szablonie. Wynik: samowystarczalny HTML z danymi inline.

Cała logika **wizualizacji** żyje już w przeglądarce (JS w szablonie). W Pythonie siedzi tylko **warstwa wejścia**: parsowanie CSV/JSON, wykrycie przerwy, korekta jasności palety. To kluczowa obserwacja — przeniesienie tej warstwy do JS wystarcza, by zlikwidować rebuild.

### 1.2 Stan docelowy (standalone self-service)

Jeden plik `livetag_app.html`, który user otwiera w przeglądarce (lokalnie albo z hostingu statycznego). W nim:

- **loguje się** do lokalnego profilu (client-side),
- **wgrywa** własny eksport LiveTag (CSV + JSON) metodą drag-and-drop,
- aplikacja **parsuje dane w JS** (port `build_dashboard.py`), waliduje i pokazuje podsumowanie,
- user **zaznacza sekcje**, które chce zobaczyć,
- dashboard renderuje **tylko wybrane sekcje**, a wybór zapisuje się w profilu.

Zero rebuildu, zero Pythona, zero wysyłania danych na serwer. Różnica raport → produkt.

### 1.3 Czego ta wersja świadomie NIE robi

Żeby nie rozmyć zakresu: brak realnych kont online (hasło nie chroni danych — patrz §5.4), brak synchronizacji między urządzeniami, brak automatycznego wykrywania dowolnych drużyn (herby/kolory nadal Hutnik/Pogoń), brak warstwy klipów wideo (roadmapa p. 7.1). Każdy z tych punktów ma zaplanowaną ścieżkę w §10–11.

---

## 2. Architektura wysokopoziomowa

Aplikacja client-side, jednoplikowa, warstwowa. Dane płyną w jedną stronę: plik → parser → model → wybór sekcji → render.

```
┌──────────────────────────────────────────────────────────────┐
│  livetag_app.html  (samowystarczalny, hostowany statycznie)    │
│                                                                │
│  [1] Warstwa profilu (client-side auth)                        │
│      rejestracja / logowanie / preferencje  → IndexedDB        │
│                          │                                     │
│  [2] Warstwa importu     ▼                                     │
│      drop CSV+JSON → parser JS (port build_dashboard.py)       │
│      → walidacja → raport pokrycia danych                      │
│                          │                                     │
│  [3] Model danych        ▼                                     │
│      DATA {events[], half_split}   PAL {tags, labels}          │
│      + meta {coverage, teams, warnings}                        │
│                          │                                     │
│  [4] Rejestr + wybór sekcji ▼                                  │
│      SECTIONS[]  →  checkboxy  →  MC.enabledSections            │
│                          │                                     │
│  [5] Warstwa renderu     ▼                                     │
│      istniejący kod v17 (renderMetricMaps, osie, karty)        │
│      renderuje tylko sekcje włączone i mające dane             │
└──────────────────────────────────────────────────────────────┘
```

Warstwy 3 i 5 to **istniejący, sprawdzony kod v17** — praktycznie bez zmian. Nowe są warstwy 1, 2 i 4. To zawęża ryzyko: nie ruszamy działającej wizualizacji, dokładamy wejście i sterowanie.

**Stack:** czysty HTML/CSS/JS, bez frameworka (zgodnie z zasadą v17: „zero zależności poza Google Fonts"). Jedyny nowy dopuszczony wyjątek to biblioteka parsująca CSV (§4.2) — wbudowana inline, nie z CDN, żeby plik pozostał samowystarczalny i działał offline.

---

## 3. Przepływ użytkownika (user flow)

Pierwsze wejście prowadzi przez ekran profilu; kolejne — od razu do importu, jeśli sesja lokalna trwa.

1. **Ekran startowy / logowania.** Brak profilu → „Utwórz profil" (nazwa + hasło). Jest profil → logowanie. (Znaczenie „hasła" — §5.4.)
2. **Ekran importu.** Dwa pola drop: `CSV` (tabela zdarzeń) i `JSON` (projekt LiveTag). CSV jest wymagany, JSON opcjonalny (bez niego oś czasu traci paletę LiveTag i wpada w fallback — §7, pkt oś czasu).
3. **Parsowanie i walidacja.** Po wgraniu aplikacja parsuje w JS i pokazuje **raport pokrycia**: liczba zdarzeń, liczba strzałów, wykryta przerwa (np. „45,5'"), wykryte drużyny, ostrzeżenia (np. „brak pozycji III STREFY — sekcja niedostępna", „literówka MASZA POŁOWA — zmapowano"). To bezpośrednio realizuje pułapki z README §2.
4. **Wybór sekcji.** Lista 7 sekcji jako checkboxy (§6). Sekcje bez danych w tym pliku są wyszarzone z podpowiedzią „dlaczego". Presety: „Wszystko", „Tylko strzały i xG", „Tylko osie czasu", plus zapamiętany wybór usera.
5. **Render.** „Pokaż dashboard" → renderują się wyłącznie zaznaczone i dostępne sekcje. Fragmentatory, filtry i tooltipy działają jak w v17.
6. **Powrót/zmiana.** Przycisk „Zmień dane / sekcje" wraca do kroku 2 lub 4 bez utraty profilu. Wybór sekcji zapisuje się automatycznie.

Stan „zalogowany + wgrany plik + wybrane sekcje" jest w całości odtwarzalny z IndexedDB, więc odświeżenie strony nie gubi pracy (§9).

---

## 4. Port warstwy wejścia: Python → JavaScript

To techniczne serce zmiany. `build_dashboard.py` ma cztery funkcje — każda ma bezpośredni odpowiednik w JS. Poniżej mapowanie z pułapkami.

### 4.1 `parse_xg` — xG z komentarza

Python bierze pierwszą liczbę z regexa i zamienia polski przecinek na kropkę. W JS identycznie:

```js
function parseXg(s){
  if(s==null) return null;
  const m = String(s).match(/([\d,.]+)/);
  return m ? parseFloat(m[1].replace(',', '.')) : null;
}
```

Zapisy niejednolite (`X 0,81`, `xG 0,09`, `x 0,14`) łapie ten sam wzorzec. Bez zmian semantycznych.

### 4.2 `prep_events` — parsowanie CSV (uwaga na pułapkę!)

**Najważniejszy punkt techniczny całego portu.** `pandas.read_csv` sam radzi sobie z polami cytowanymi. W JS musimy to odtworzyć, bo pole `labels` zawiera **wartości rozdzielone przecinkiem wewnątrz jednej komórki** (`"POZYCYJNIE, CELNY, KONTRATAK"`). Naiwny `split(',')` po całej linii rozjedzie kolumny. Dlatego:

- **Nie parsować CSV ręcznie przez `split(',')`.** Użyć parsera respektującego cudzysłowy. Rekomendacja: **PapaParse** (~7 KB zminifikowany) wklejony inline do pliku — obsługuje pola cytowane, nagłówki, puste wartości, i działa offline. Alternatywa (bez zależności): własny mini-parser ~40 linii z obsługą `"` i escapowania, ale PapaParse jest sprawdzony i tańszy w utrzymaniu.
- Po sparsowaniu: `labels_list = row.labels.split(',').map(trim).filter(nonempty)` — dopiero **na już wyodrębnionym polu**, nie na całej linii.

Reszta `prep_events` przenosi się 1:1:

- **Wykrycie przerwy** — największa luka w kodowaniu w środkowej ⅓ (`0.3·t_max < t < 0.7·t_max`), `half_split = (gap_start+gap_end)/2`. Port pętli po posortowanych `begin`.
- **`half`** = 1/2 względem `half_split`.
- **Clamp `begin` ujemnego do 0** (README pułapka 10) — dodać `Math.max(0, begin)` (w Pythonie było to niejawne założenie; w JS robimy jawnie).
- **Współrzędne** — przepisać `round(x,2)` z `pos_x_meters` itd.; brak wartości → `null`. Kierunkowa normalizacja jest już w danych (Hutnik lewa, Pogoń prawa) — nie lustrzymy (README pułapka 2).

Wynik: identyczny obiekt `{events:[...], half_split}` jak dziś w `/*__DATA__*/`.

### 4.3 `to_hex` + `prep_palette` — paleta z JSON z korektą jasności

`to_hex` (RGB 0–1 → hex z podbiciem luminancji < 0,22, czarna STRATA → jaśniejszy szary) to czysta arytmetyka — przepisuje się wprost:

```js
function toHex(colorStr, floor=0.22){
  let [r,g,b] = colorStr.split(/\s+/).map(Number);
  const L = 0.2126*r + 0.7152*g + 0.0722*b;
  if(L < floor){
    const t = L < 1 ? (floor - L)/(1 - L) : 0;
    if(L < 0.05) [r,g,b] = [r,g,b].map(x => x + (0.75 - x)*Math.max(t,0.35));
    else         [r,g,b] = [r,g,b].map(x => Math.min(1, x + (1-x)*t*1.4));
  }
  const h = x => Math.round(Math.min(1,x)*255).toString(16).padStart(2,'0');
  return ('#'+h(r)+h(g)+h(b)).toUpperCase();
}
```

`prep_palette` czyta `dependencies[]` z JSON, bierze wpisy `type∈{tag,label}` z niepustym `color`, buduje `{tags:{}, labels:{}}`. Port 1:1. Gdy user nie wgra JSON — paleta pusta, oś czasu używa koloru drużyny jako fallbacku (a nie palety LiveTag).

### 4.4 Placeholdery znikają

Dziś szablon ma `const DATA = /*__DATA__*/;` i `const PAL = /*__PAL__*/;` podmieniane przy buildzie. W wersji standalone stają się **zmiennymi runtime**:

```js
let DATA = null, PAL = null, META = null;   // puste do czasu importu
// po parsowaniu:  DATA = parseEvents(csvText); PAL = parsePalette(jsonText); renderAll();
```

Kod renderujący nie widzi różnicy — dostaje te same struktury, tyle że po imporcie zamiast po buildzie.

---

## 5. Model użytkownika (client-side)

### 5.1 Czym jest „rejestracja" bez backendu

„Zarejestrowany użytkownik" w tej fazie = **lokalny profil w przeglądarce**. Nie ma serwera, konta ani weryfikacji tożsamości. Profil to rekord w IndexedDB przeglądarki na tym konkretnym urządzeniu. To wystarcza, by:

- dać poczucie „mojego konta" i spersonalizować UI (nazwa, ostatni mecz),
- **trwale zapamiętać preferencje** (wybrane sekcje, presety, ostatnio wgrane dane),
- zbudować UX i strukturę danych identyczną z docelową wersją online, żeby migracja (§10) była płynna.

### 5.2 Co przechowujemy

```
Profile {
  id, displayName, createdAt,
  auth: { salt, passHash },              // patrz 5.4 — to wygoda, nie ochrona
  prefs: {
    enabledSections: [...],              // ostatni wybór sekcji
    presets: { ... },
    theme: 'analytics-dark'
  },
  lastDataset: {                         // opcjonalnie, żeby nie wgrywać co wejście
    importedAt, csvRaw?, jsonRaw?,        // albo już sparsowane DATA/PAL/META
    label: 'Hutnik vs Pogoń 2026-...'
  }
}
```

### 5.3 Gdzie: IndexedDB, nie localStorage

**IndexedDB**, bo dataset meczu (setki zdarzeń + surowe pliki) bywa większy niż limit ~5 MB localStorage i lepiej trzymać go jako strukturę niż jeden string. localStorage zostaje najwyżej na drobiazgi (np. „kto był ostatnio zalogowany").

> **Ważne rozróżnienie:** ograniczenie „żadnego localStorage/IndexedDB" dotyczy **artefaktów w claude.ai**, nie tego produktu. `livetag_app.html` to samodzielny plik, który hostujesz/otwierasz sam — w normalnej przeglądarce storage działa i jest tu właściwym narzędziem. (Gdybyś kiedyś chciał ten sam plik podglądać jako artefakt w claude.ai, storage tam nie zadziała — to jedyny kontekst, w którym trzeba by go wyłączyć.)

### 5.4 Uczciwie o „haśle" (granica bezpieczeństwa)

Hasło client-side **nie chroni danych** — ktoś z dostępem do przeglądarki może odczytać IndexedDB. Traktujemy je jako wygodę/rozdzielenie profili, nie zabezpieczenie. W UI warto to nazwać wprost („profil lokalny na tym urządzeniu"), żeby nie budować fałszywego poczucia prywatności wobec klubów. Techniczne minimum, które i tak wdrażamy (bo ułatwia migrację): hasło hashowane przez **Web Crypto `Subtle.digest`/PBKDF2** z solą — nigdy plain text. Realna ochrona (szyfrowanie danych, prawdziwe konta) przychodzi z backendem w §10.

Zaleta odwrotnej strony medalu, którą warto komunikować klubom: **dane meczu nie opuszczają komputera**. Dla analityka to często argument sprzedażowy, nie wada.

---

## 6. Rejestr sekcji i mechanizm wyboru

### 6.1 Sekcje jako rejestr, nie stały HTML

Dziś sekcje to sztywne `<section>` w szablonie. Żeby dało się je włączać/wyłączać, opisujemy je **deklaratywnie** — jeden rejestr steruje i checkboxami, i renderem, i dostępnością:

```js
const SECTIONS = [
  { id:'bilans',   title:'Bilans drużyn — porównanie fragmentów',
    mount:'#sec-bilans',  render: renderBilans,
    requires: () => true },                       // zawsze dostępna
  { id:'mapy',     title:'Mapy współczynników',
    mount:'#sec-mapy',    render: renderMetricMaps,
    requires: m => m.coverage.shots > 0 },
  { id:'tl-sbz',   title:'Oś czasu — zdobycie SBZ',
    mount:'#sec-tlsbz',   render: renderTL,
    requires: m => m.coverage.sbz > 0 },
  { id:'tl-iii',   title:'Oś czasu — zdobycie III strefy',
    mount:'#sec-tl3',     render: renderTL3,
    requires: m => m.coverage.iiiPos > 0 },        // pułapka: pierwszy eksport nie miał!
  { id:'tl-bilans',title:'Oś czasu — bilans meczu',
    mount:'#sec-tlm',     render: renderTLM,
    requires: () => true },
  { id:'duels',    title:'Pojedynki, straty, odbiory',
    mount:'#sec-duels',   render: renderDuels,
    requires: () => true },
  { id:'noteam',   title:'Zdarzenia bez przypisania drużyny',
    mount:'#sec-noteam',  render: renderCards,
    requires: () => true },
];
```

`MC.enabledSections` (Set id-ów) dokłada się do istniejącego globalnego stanu `MC`. Pętla renderująca:

```js
function renderAll(){
  for(const s of SECTIONS){
    const el = document.querySelector(s.mount);
    const available = s.requires(META);
    const on = MC.enabledSections.has(s.id) && available;
    el.hidden = !on;
    if(on) s.render();          // render tylko gdy widoczna — oszczędza czas i pułapki pustych danych
  }
}
```

### 6.2 Dostępność zależna od danych (kluczowe dla pułapek README)

`requires(META)` łączy wybór sekcji z **realnym pokryciem danych** wykrytym przy imporcie. To wprost adresuje README pułapkę 3: pierwszy eksport nie miał pozycji III STREFY. Zamiast renderować pustą mapę, sekcja „Oś czasu / mapa III strefy" jest w pickerze **wyszarzona** z podpowiedzią „ten eksport nie zawiera pozycji III STREFY (kolumny pos_* puste)". User nie trafia na pusty wykres i rozumie dlaczego.

Meta pokrycia liczy warstwa importu:

```
META.coverage = {
  events, shots, sbz, sbzWithVector, iiiPos, teamsPresent,
  hasJson, xgParsed
}
```

### 6.3 UI pickera

Panel z checkboxami zgrupowany: „Bilans i pojedynki", „Strzały / xG", „Osie czasu", „Bez drużyny". Nad nim presety (przyciski). Pod spodem licznik „5 z 7 sekcji · 2 niedostępne dla tego eksportu". Wybór trafia od razu do `MC.enabledSections`, `renderAll()` i zapisu w profilu (debounced).

---

## 7. Walidacja i obsługa pułapek danych (warstwa importu)

Warstwa importu to nie tylko parser — to **strażnik pułapek** z README §2. Każdą obsługujemy jawnie i (gdy trzeba) meldujemy w raporcie pokrycia:

| # | Pułapka (README) | Obsługa w imporcie |
|---|---|---|
| 1 | xG w `comment`, polski przecinek | `parseXg` — regex + `,`→`.` |
| 2 | Współrzędne w metrach, znormalizowane kierunkowo | Bez lustrzenia; skala 1 m = 10 px w renderze |
| 3 | III STREFA bywa bez pozycji | `coverage.iiiPos`; sekcja warunkowa (§6.2) |
| 4 | `players` puste | Brak warstwy indywidualnej — nie oferujemy sekcji zawodniczej |
| 5 | `team` tylko na 3 tagach (98 zdarzeń) | Grupa „bez przypisania drużyny" (sekcja `noteam`) |
| 6 | Semantyka ikon (kształt = wynik) | Bez zmian — glify `●○◆` w renderze |
| 7 | **Dokładne** dopasowanie etykiet po `', '` | Match `===` na `labels_list`, nigdy substring (CELNY⊂NIECELNY) |
| 8 | Czas = czas wideo, przerwa z luki | Detekcja `half_split`; pokazana w raporcie |
| 9 | Literówka `MASZA`→`NASZA` | Mapowanie przy imporcie; ostrzeżenie w raporcie |
| 10 | `begin` ujemny (bufor taga) | `Math.max(0, begin)` |

Dodatkowo walidacja formatu: sprawdzenie obecności wymaganych kolumn (`tag_name, begin, end, ...`); jeśli plik nie wygląda jak eksport LiveTag — czytelny komunikat zamiast cichej awarii (nauczka z README v13: podmiany bez asercji zniszczyły szablon — tu odpowiednikiem są twarde walidacje wejścia).

---

## 8. Refaktoryzacja szablonu (co konkretnie się zmienia)

Zmiany są chirurgiczne — szablon v17 zostaje, dokładamy „obudowę":

1. **Nowe ekrany przed dashboardem:** `#screen-auth`, `#screen-import`, `#screen-sections` (pokazywane/chowane; dashboard = `#screen-dashboard`).
2. **Sekcje dostają `id` i `mount`** oraz atrybut `hidden` sterowany rejestrem (§6.1). Treść sekcji bez zmian.
3. **`DATA`/`PAL` z `const` (build) na `let` (runtime)** ustawiane po imporcie (§4.4).
4. **Nowe moduły JS:** `auth.js`-logika (profil/IndexedDB), `import.js` (parser + walidacja + coverage), `sections.js` (rejestr + picker). Wszystko inline w jednym pliku, żeby zachować samowystarczalność.
5. **Herby i kolory** zostają zaszyte (base64 Hutnik/Pogoń, `--hut`/`--pog`) — zgodnie z decyzją „na razie ten mecz". Punkt wpięcia dla generalizacji oznaczamy komentarzem `/* TODO: teams-from-data */` (§11, etap G).

Nic w `renderMetricMaps`, `fragT`, `sbzShotOf`, `glyph`, `chartFrame` nie wymaga zmian logiki — najwyżej owinięcie w wywołania z rejestru.

---

## 9. Trwałość, prywatność, offline

- **Trwałość:** profil + ostatni dataset w IndexedDB → odświeżenie/zamknięcie karty nie gubi pracy; powrót od razu do ostatniego meczu i wyboru sekcji.
- **Prywatność:** dane meczu nigdy nie wychodzą z przeglądarki. Silny argument dla klubów (poufność taktyki). Warto zakomunikować wprost w UI.
- **Offline:** dzięki inline parserowi i braku CDN plik działa bez internetu (jedyny wyjątek to Google Fonts — można je też osadzić inline/lokalnie, by uzyskać pełny offline; drobny etap).
- **Eksport profilu:** opcja „pobierz profil jako plik" (JSON) → prosty backup i ręczne przeniesienie na inny komputer, zanim powstanie backend.

---

## 10. Ścieżka migracji do prawdziwych kont (kolejna faza)

Architektura jest tak ułożona, żeby backend **dokleić**, nie przepisać:

- **Auth:** zamiana lokalnego `Profile.auth` na dostawcę (rekomendacja: **Supabase Auth** — email/hasło lub magic link; hojny darmowy próg, gotowe SDK w JS). UI logowania praktycznie ten sam.
- **Storage:** `lastDataset` i `prefs` z IndexedDB → tabele/Storage w Supabase (Postgres + bucket na surowe pliki). Klucz: user_id.
- **Parser zostaje po stronie klienta** — dane parsujemy w przeglądarce jak dziś, na serwer trafia wynik (albo surowe pliki, jeśli klub się zgodzi). To utrzymuje niski koszt i prywatność jako opcję.
- **Synchronizacja urządzeń** pojawia się „za darmo", bo profil żyje w bazie.
- **Model danych z §5.2 jest celowo zgodny** z docelowym schematem — migracja to głównie zamiana warstwy zapisu (adapter `storage.get/set`), nie logiki aplikacji.

Rekomendacja stacku, gdy dojrzejesz do online: **hosting statyczny (Netlify/Vercel/Cloudflare Pages) + Supabase** (auth + Postgres + storage). Bez własnego serwera, koszt startowy ~zero.

---

## 11. Plan wdrożenia (etapy)

Kolejność minimalizuje ryzyko: najpierw parser (serce), potem obudowa, na końcu profile i generalizacja.

- **Etap A — Parser JS.** Port `build_dashboard.py` do JS (§4) + wybór parsera CSV. Kryterium akceptacji: dla referencyjnego `tagging.csv`+`.json` JS produkuje `DATA`/`PAL` **bit-w-bit** równe dzisiejszemu wyjściu Pythona (test porównawczy na 294 zdarzeniach, xG, half_split ≈ 45,5').
- **Etap B — Ekran importu + walidacja + coverage.** Drop CSV/JSON, raport pokrycia, obsługa pułapek §7. Kryterium: wgranie starego eksportu (bez III strefy, z „MASZA") daje poprawne ostrzeżenia i nie renderuje pustych sekcji.
- **Etap C — Rejestr i picker sekcji.** `SECTIONS[]`, checkboxy, `requires()`, presety. Kryterium: włączanie/wyłączanie każdej z 7 sekcji działa, niedostępne są wyszarzone z powodem.
- **Etap D — Profil client-side.** IndexedDB, rejestracja/logowanie, zapis preferencji i ostatniego datasetu, eksport profilu. Kryterium: po odświeżeniu wraca ostatni mecz i wybór sekcji.
- **Etap E — Dopieszczenie UX.** Presety, licznik sekcji, komunikaty prywatności, opcjonalny pełny offline (fonty inline).
- **Etap F — (opcjonalnie) Backend.** Supabase auth+storage wg §10.
- **Etap G — (opcjonalnie) Generalizacja drużyn.** Nazwy z danych, upload herbów, wybór kolorów w profilu; zdjęcie zaszycia Hutnik/Pogoń (punkt `TODO: teams-from-data`).

Etapy A–D dają w pełni używalny produkt self-service dla tego meczu. E dopieszcza. F–G to skok do powtarzalnego produktu online dla dowolnego klubu.

---

## 12. Ryzyka i decyzje otwarte

- **Parser CSV vs zasada „zero zależności".** Rekomendacja: PapaParse inline (sprawdzony, obsługuje cytowane `labels`). Decyzja otwarta: zaakceptować ~7 KB zależności inline czy pisać własny mini-parser. *Rekomendacja: PapaParse — koszt utrzymania własnego parsera CSV z cudzysłowami przewyższa 7 KB.*
- **Zgodność parsera JS z Pythonem.** Ryzyko subtelnych różnic (zaokrąglenia, `NaN`, puste pola). Mitygacja: test porównawczy z Etapu A jako bramka.
- **Fałszywe poczucie bezpieczeństwa z „hasła".** Mitygacja: jawny opis „profil lokalny" w UI (§5.4).
- **Rozjazd danych między eksportami LiveTag** (nowe/stare kolumny, literówki). Mitygacja: warstwa coverage + mapowania + widoczne ostrzeżenia; nowy eksport zawsze weryfikować (README pułapka 3 i 9).
- **Wielkość datasetu w IndexedDB.** Dla jednego meczu pomijalna; przy archiwum wielu meczów rozważyć limit/rotację lub od razu backend.
- **Google Fonts a offline.** Do rozstrzygnięcia w Etapie E: osadzić fonty lokalnie dla pełnego offline czy zostawić CDN.

---

## 13. Podsumowanie w jednym akapicie

Cała logika wizualizacji jest już w przeglądarce — do produktu self-service brakuje trzech rzeczy: **portu warstwy wejścia z Pythona do JS** (§4, z jedną realną pułapką: parser CSV respektujący cytowane pole `labels`), **rejestru sekcji sterującego wyborem i dostępnością** zależną od pokrycia danych (§6), oraz **lekkiej warstwy profilu w IndexedDB** (§5), świadomie bez realnego bezpieczeństwa, ale z modelem danych gotowym pod przyszły backend Supabase (§10). Warstwy renderu v17 nie ruszamy. Etapy A–D dają używalny produkt dla tego meczu; F–G otwierają powtarzalny produkt online dla dowolnego klubu.
