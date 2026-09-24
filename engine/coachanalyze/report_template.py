"""Templat raportu klubu jako wejście pipeline'u (Sesja 5 przebudowy).

Templat powstaje w konfiguratorze (Sesje 3+4) i leży w bazie jako JSON
w `club_report_templates.config`. PHP serializuje AKTUALNĄ wersję (MAX version)
do pliku roboczego zadania i podaje ścieżkę przez `--template`.

Kształt configu: docs/PRZEBUDOWA_KLUB_SESJE.md, Sesja 4 pkt 4.

────────────────────────────────────────────────────────────────────────────
ZASADA NADRZĘDNA TEGO MODUŁU: BRAK TEMPLATU = ZEROWA ZMIANA.

`--template` jest opcjonalny. Bez niego cały pipeline zachowuje się dokładnie
tak, jak przed Sesją 5 — co do bajtu w wyjściu renderu. Pilnuje tego test złoty,
który porównuje raport z wzorcem produkcyjnym LINIA W LINIĘ.

Każda funkcja tutaj musi więc dawać się pominąć: przy `None` zwraca wejście
nietknięte, a nie „rozsądną wartość domyślną".
────────────────────────────────────────────────────────────────────────────
"""

import json


def load(path):
    """Config templatu z pliku. `None` przy braku ścieżki — to poprawny stan."""
    if not path:
        return None
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def sections_enabled(template):
    """Sekcje włączone w templacie albo `None`, gdy templatu nie ma.

    `None` znaczy „bez ograniczenia" i trafia do `build_sections` jako brak
    listy — czyli wszystkie sekcje, jak dotychczas. Pusta lista to co innego:
    templat, który świadomie nie włącza żadnej sekcji, i tak ma zostać
    potraktowany.
    """
    if not template:
        return None
    sekcje = template.get("sections_enabled")
    return list(sekcje) if isinstance(sekcje, list) else None


def team_markers(template):
    """Napisy oznaczające „naszą" drużynę w kolumnie `team` eksportu.

    Pułapka 9: część eksportów klienta ma literówkę `MASZA` zamiast `NASZA`.
    Konfigurator zapisuje oba warianty w `team_us_rule.markers`, więc korekta
    jest DANĄ TEMPLATU, nie regułą wpisaną w silnik — kolejny klub może mieć
    własną literówkę i nie wymaga to zmiany kodu.

    @return list[str] — pusta, gdy templatu nie ma
    """
    if not template:
        return []
    regula = template.get("team_us_rule") or {}
    markery = regula.get("markers")
    return [str(m) for m in markery if str(m).strip()] if isinstance(markery, list) else []


def mapping_profile(template):
    """Templat przetłumaczony na profil mapowań, który rozumie `canon.build()`.

    PO CO TŁUMACZENIE, A NIE DRUGA ŚCIEŻKA W `canon`: warstwa kanoniczna ma
    dokładnie jedno miejsce, w którym tag staje się pojęciem. Dołożenie tam
    drugiego źródła reguł znaczyłoby dwa zestawy warunków rozstrzygające o tej
    samej rzeczy — a wtedy pytanie „czemu ten tag policzył się inaczej" ma dwie
    możliwe odpowiedzi i trzeba sprawdzić obie.

    Templat wygrywa z profilem kreatora, bo jest nowszy i jawnie zatwierdzony
    przez człowieka w konfiguratorze.

    Zmienne `canon: null` NIE trafiają do reguł jako brak wpisu, tylko jako
    reguła z `concept: None`. Różnica jest istotna: brak wpisu znaczy „silnik
    tego tagu nie zna" i ląduje w `unmapped_tags`, a jawne `None` znaczy
    „człowiek zdecydował, że to zmienna niestandardowa". Pierwsze jest usterką
    do naprawienia, drugie decyzją do uszanowania.

    @return dict|None w kształcie `config.mapping_profile`
    """
    if not template:
        return None

    zmienne = template.get("variables")
    if not isinstance(zmienne, list):
        return None

    reguly = []
    for z in zmienne:
        if not isinstance(z, dict):
            continue
        zrodlo = z.get("source") or {}
        raw = str(zrodlo.get("raw") or "")
        if not raw:
            continue

        typ = zrodlo.get("type")
        canon_value = z.get("canon")

        if typ == "label":
            reguly.append({"match": {"label": raw}, "qualifier": canon_value})
        else:
            reguly.append({"match": {"tag": raw}, "concept": canon_value})

    if not reguly:
        return None

    return {
        # Wersję niesie PHP w `config.template_version`; tutaj zaznaczamy tylko
        # pochodzenie, żeby `--out-metrics` mówiło, skąd wzięły się reguły.
        "version": template.get("schema_version"),
        "source": "report_template",
        "rules": reguly,
    }


def variables_by_source(template):
    """Zmienne templatu w indeksie `(typ, raw)` — do stopki i raportu pokrycia.

    @return dict[(str, str), dict]
    """
    out = {}
    if not template:
        return out
    for z in template.get("variables") or []:
        if not isinstance(z, dict):
            continue
        zrodlo = z.get("source") or {}
        raw = str(zrodlo.get("raw") or "")
        if raw:
            out[(str(zrodlo.get("type") or "tag"), raw)] = z
    return out


def tags_without_concept(template):
    """Surowe nazwy tagów zmiennych templatu BEZ pojęcia kanonicznego.

    ═══════════════════════════════════════════════════════════════════════════
    ŹRÓDŁO `null` MA ZNACZENIE i dlatego ta funkcja w ogóle istnieje.

    Regułę z jawnym `concept: None` potrafią dać DWIE różne decyzje człowieka:

    - zmienna templatu bez pojęcia — „nie wiem, jak to nazwać kanonicznie,
      ale POKAŻ to w raporcie" (od sesji 1 pivotu stan normalny),
    - `NIE_ANALIZUJ` z kreatora mapowań — „ten tag mnie NIE INTERESUJE".

    W profilu mapowań wyglądają identycznie. Po kształcie reguły nie da się ich
    rozróżnić, a traktowanie ich tak samo znaczyłoby, że xG z tagu świadomie
    wyłączonego z analizy wchodzi do sumy.
    ═══════════════════════════════════════════════════════════════════════════

    Zwraca zbiór nazw, nie listę: to wyłącznie sprawdzanie przynależności.
    """
    nazwy = set()
    for zmienna in (template or {}).get("variables") or []:
        if not isinstance(zmienna, dict) or zmienna.get("canon") is not None:
            continue
        zrodlo = zmienna.get("source") or {}
        raw = zrodlo.get("raw")
        if raw:
            nazwy.add(str(raw))
    return nazwy


def tags_by_section(template):
    """{sekcja: {surowe nazwy tagów}} — zmienne przypisane do każdej sekcji.

    Służy liczeniu DOSTĘPNOŚCI SEKCJI po surowych tagach, a nie po pojęciach
    kanonicznych (`coverage.build_sections`). Powód jest z odbioru sesji 1:
    templat, w którym `STRZAŁ` i `ZDOBYCIE SBZ` mają `canon: null`, dawał raport
    BEZ map i BEZ osi SBZ — bo dostępność liczyła się z `coverage["shots"]`
    i `coverage["sbz"]`, czyli z pojęć, których te zmienne świadomie nie miały.
    Uśpiona warstwa egzekwowała wycofaną regułę.

    Bierzemy WYŁĄCZNIE tagi. Etykieta (`CELNY`) jest uszczegółowieniem zdarzenia,
    a nie zdarzeniem — sekcja „ma dane" wtedy, gdy ma wiersze, nie gdy ma przymiotniki.
    """
    mapa = {}
    for zmienna in (template or {}).get("variables") or []:
        if not isinstance(zmienna, dict):
            continue
        zrodlo = zmienna.get("source") or {}
        if zrodlo.get("type") not in (None, "tag"):
            continue
        raw = zrodlo.get("raw")
        if not raw:
            continue
        for sekcja in zmienna.get("sections") or ():
            mapa.setdefault(str(sekcja), set()).add(str(raw))
    return mapa


def generic_variables(template):
    """Zmienne BEZ pojęcia kanonicznego. Służy RAPORTOWI POKRYCIA.

    Zbieramy je, żeby raport pokrycia mógł powiedzieć, ile ich jest i które to są.

    ZMIANA W SESJI 1 PIVOTU (2026-09-24), docs/STAN_PIVOTU.md §2.3: takie zmienne
    **nie są już blokowane w konfiguratorze**. Do tej sesji konfigurator wpuszczał
    je wyłącznie do bilansu i na oś czasu; pojęcie kanoniczne jest odtąd opcjonalne
    i nie ogranicza sekcji, bo raport liczy po surowej nazwie tagu.

    Funkcja i tak nigdy tego nie egzekwowała — liczyła, nie zakazywała. Zmienia się
    wyłącznie ten opis; logika i wyjście zostają bez zmiany.

    @return list[dict]
    """
    if not template:
        return []
    return [
        z for z in (template.get("variables") or [])
        if isinstance(z, dict) and z.get("canon") is None
    ]
