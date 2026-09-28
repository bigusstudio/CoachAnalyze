"""Warstwa 1 raportu: „Wszystkie tagi z pliku" — eksport pokazany w całości, 1:1.

═══════════════════════════════════════════════════════════════════════════════
ZASADA ZEROWA (W7): POKAZUJEMY WSZYSTKO, CO JEST W PLIKU.

Ta sekcja nie zależy od Słownika ani od templatu klubu i jest w KAŻDYM raporcie
(generacja v21). Znaczenia (`znaczenie.py`) i sekcje analityczne to warstwa 2 —
mogą czegoś nie rozumieć, ale nie mogą niczego schować, bo wszystko jest tutaj:

- każdy tag POD NAZWĄ Z PLIKU, z liczbą na drużynę z kolumny `team`
  („bez drużyny" osobno — pułapka 5) i na połowę,
- każda etykieta i każdy zawodnik z liczbą wystąpień,
- pozycje (mapa per tag), wektor przy `pos_target_*`,
- pary z aktywacji tagów (`A → B`) z liczbą par,
- tagi `cm` (tryb ciągły) jako przedziały: suma czasu i udział w nagraniu,
- xG przy każdym tagu, który ma xG w komentarzu.

DRUŻYNY Z KOLUMNY `team`, NIE Z NAZWY TAGU I NIE Z KONFIGURACJI. Trzecia
drużyna (np. sparingowy rywal z innego meczu w tym samym pliku) stoi tu pod
własną nazwą i nie jest dołączana do żadnej strony.
═══════════════════════════════════════════════════════════════════════════════

Liczy wyłącznie silnik (D5, CLAUDE.md §4); szablon tylko wyświetla.
"""

# Tolerancja przy parowaniu aktywacji: `begin` taga aktywowanego wypada w oknie
# taga aktywującego. Sekunda zapasu na zaokrąglenia czasu wideo.
TOLERANCJA_PARY_S = 1.0


def _zaokr(v, n=2):
    return None if v is None else round(v, n)


def _suma_przedzialow(przedzialy):
    """Suma długości SUMY przedziałów — nakładające się liczymy raz."""
    suma, koniec_poprz = 0.0, None
    for b, e in sorted(przedzialy):
        if e <= b:
            continue
        if koniec_poprz is None or b >= koniec_poprz:
            suma += e - b
            koniec_poprz = e
        elif e > koniec_poprz:
            suma += e - koniec_poprz
            koniec_poprz = e
    return suma


def _licz(para_lista):
    wynik = {}
    for k in para_lista:
        wynik[k] = wynik.get(k, 0) + 1
    return sorted(wynik.items(), key=lambda kv: (-kv[1], kv[0]))


def pary_aktywacji(events, projekt):
    """[{a, b, n, z}] — `a` aktywuje `b` (definicja z pliku projektu).

    `n` — ile zdarzeń `a` ma zdarzenie `b` zaczynające się w oknie `a`
    (`begin` `b` w [`begin` a − 1 s, `end` a + 1 s]); `z` — ile jest zdarzeń `a`.
    Liczy się para, nie definicja: analityk mógł zdefiniować aktywację
    i nie kliknąć drugiego taga.
    """
    po_tagu = {}
    for e in events:
        if e.get("b") is not None:
            po_tagu.setdefault(e.get("tag"), []).append(e)
    wynik = []
    for t in (projekt or {}).get("tagi") or []:
        for b_nazwa in t.get("aktywuje") or ():
            zrodla = po_tagu.get(t["nazwa"], [])
            cele = sorted(e["b"] for e in po_tagu.get(b_nazwa, []))
            n = 0
            for a in zrodla:
                koniec = a["e"] if a.get("e") is not None else a["b"]
                if any(a["b"] - TOLERANCJA_PARY_S <= c <= koniec + TOLERANCJA_PARY_S for c in cele):
                    n += 1
            wynik.append({"a": t["nazwa"], "b": b_nazwa, "n": n, "z": len(zrodla)})
    return wynik


def zbuduj(frame, frame_widok=None, projekt=None, znaczenia=None):
    """Dane sekcji „Wszystkie tagi z pliku". Wejście: ramka ORYGINALNA.

    `frame_widok` — ta sama ramka po odbiciu kierunku (render): pozycje na mapie
    idą w układzie raportu, żeby mapa warstwy 1 zgadzała się z mapami warstwy 2.
    Liczby i drużyny zawsze z ramki oryginalnej (z pliku).
    """
    events = frame.get("events") or []
    widok = (frame_widok or frame).get("events") or events
    zawodnicy = frame.get("players") or []
    projekt = projekt or {}
    znaczenia = znaczenia or {}
    po_nazwie = projekt.get("po_nazwie") or {}

    druzyny = [n for n, _ in _licz(e["team"] for e in events if e.get("team") is not None)]
    indeks_druzyny = {n: i for i, n in enumerate(druzyny)}

    begins = [e["b"] for e in events if e.get("b") is not None]
    ends = [e["e"] for e in events if e.get("e") is not None]
    nagranie = (max(ends or begins) - min(begins)) if begins else 0.0

    tagi = {}
    for i, e in enumerate(events):
        tag = e.get("tag")
        wpis = tagi.get(tag)
        if wpis is None:
            definicja = po_nazwie.get(tag) or {}
            z = znaczenia.get(tag) or {}
            wpis = tagi[tag] = {
                "nazwa": tag,
                "n": 0,
                "d": [0] * len(druzyny),
                "bez": 0,
                "p": [0, 0],
                "etykiety": {},
                "xg_n": 0,
                "xg_suma": 0.0,
                "pozycje": [],
                "cm": definicja.get("cm"),
                "_przedzialy": [],
                "znaczenie": {
                    "pojecie": z.get("pojecie"),
                    "zrodlo": z.get("zrodlo"),
                    "strona": z.get("strona"),
                    "klucz": z.get("klucz"),
                } if z.get("pojecie") or z.get("strona") else None,
            }
        wpis["n"] += 1
        if e.get("team") is None:
            wpis["bez"] += 1
        else:
            wpis["d"][indeks_druzyny[e["team"]]] += 1
        wpis["p"][0 if e.get("half") == 1 else 1] += 1
        for et in e.get("labels") or ():
            wpis["etykiety"][et] = wpis["etykiety"].get(et, 0) + 1
        if e.get("xg") is not None:
            wpis["xg_n"] += 1
            wpis["xg_suma"] += e["xg"]
        w = widok[i] if i < len(widok) else e
        if w.get("x") is not None and w.get("y") is not None:
            wpis["pozycje"].append([
                w["x"], w["y"], w.get("tx"), w.get("ty"), e.get("half"),
                indeks_druzyny.get(e.get("team"), -1),
            ])
        if e.get("b") is not None and e.get("e") is not None:
            wpis["_przedzialy"].append((e["b"], e["e"]))

    lista = sorted(tagi.values(), key=lambda t: (-t["n"], str(t["nazwa"])))
    for t in lista:
        przedzialy = t.pop("_przedzialy")
        t["etykiety"] = sorted(t["etykiety"].items(), key=lambda kv: (-kv[1], kv[0]))
        t["xg_suma"] = _zaokr(t["xg_suma"]) if t["xg_n"] else None
        if t["cm"]:
            czas = _suma_przedzialow(przedzialy)
            t["czas_s"] = _zaokr(czas, 1)
            t["proc_nagrania"] = _zaokr(100.0 * czas / nagranie, 1) if nagranie > 0 else None

    etykiety = _licz(et for e in events for et in (e.get("labels") or ()))
    znane_etykiety = {n for n, _ in etykiety}

    return {
        "zdarzen": len(events),
        "druzyny": [[n, sum(1 for e in events if e.get("team") == n)] for n in druzyny],
        "bez_druzyny": sum(1 for e in events if e.get("team") is None),
        "tagi": lista,
        "etykiety": etykiety,
        # Etykiety zdefiniowane w projekcie, których w zdarzeniach nie ma —
        # osobno, bo „0" w tabeli liczników wyglądałoby na zgubione dane.
        "etykiety_bez_zdarzen": [n for n in projekt.get("etykiety") or [] if n not in znane_etykiety],
        "zawodnicy": _licz(p for p in zawodnicy if p),
        "tagi_bez_zdarzen": [
            t["nazwa"] for t in projekt.get("tagi") or [] if t.get("nazwa") not in tagi
        ],
        "pary": pary_aktywacji(events, projekt),
        "dezaktywacje": [
            {"a": t["nazwa"], "b": b} for t in projekt.get("tagi") or [] for b in t.get("dezaktywuje") or ()
        ],
        "nagranie_s": _zaokr(nagranie, 1),
        "xg_n": sum(t["xg_n"] for t in lista),
        "xg_suma": _zaokr(sum(e["xg"] for e in events if e.get("xg") is not None)),
        "ma_projekt": bool(projekt),
    }
