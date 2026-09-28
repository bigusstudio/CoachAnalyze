"""Plik projektu LiveTag (eksport JSON) -> definicje tagów, drużyny, zawodnicy.

═══════════════════════════════════════════════════════════════════════════════
PO CO TEN MODUŁ (W7, metoda importu v3).

Do W7 silnik czytał z JSON wyłącznie paletę (`parse.prep_palette`). Plik niesie
więcej faktów, bez których warstwa 1 raportu nie pokaże eksportu w całości:

- `params.cm` — tag jest PRZEDZIAŁEM (tryb ciągły), a nie chwilą: posiadanie
  mierzy się czasem, nie liczbą kliknięć,
- `params.activation_tags` / `params.deactivation_tags` — powiązania tagów
  zapisane przez analityka (A aktywuje B), jako UUID,
- `uuid` tagu — stały identyfikator, który przeżywa zmianę nazwy tagu; na nim
  stoi rozpoznanie profilu analityka (docs/KONTRAKT_CLI.md, profil).

UUID W PLIKU, NAZWY NA ZEWNĄTRZ. Powiązania są zapisane jako UUID, a człowiek
czyta nazwy — zamieniamy je tutaj, raz. UUID bez definicji w tym samym pliku
nie znika po cichu: trafia do `nieznane_uuid` i dalej do anomalii importu.
═══════════════════════════════════════════════════════════════════════════════

Tylko biblioteka standardowa (CLAUDE.md §3a).
"""

import json


def _liczba(wartosc):
    try:
        return float(wartosc)
    except (TypeError, ValueError):
        return None


def wczytaj(json_path):
    """Plik projektu -> słownik faktów. Brak ścieżki = `None` (JSON jest opcjonalny).

    Kształt:
        {
          "wersja_livetag": "1.12.21" | None,
          "tagi": [ {uuid, nazwa, cm, aktywuje: [nazwy], dezaktywuje: [nazwy],
                     time_before, time_after} ],   # kolejność z pliku
          "po_nazwie": {nazwa: wpis},                # pierwsze wystąpienie wygrywa
          "druzyny": [nazwy], "etykiety": [nazwy], "zawodnicy": [nazwy],
          "nieznane_uuid": [uuid],
        }
    """
    if not json_path:
        return None
    with open(json_path, encoding="utf-8") as fh:
        dane = json.load(fh)
    return z_danych(dane)


def z_danych(dane):
    """Jak `wczytaj`, ale na już wczytanym obiekcie — do testów i narzędzi."""
    deps = [d for d in (dane or {}).get("dependencies") or [] if isinstance(d, dict)]

    surowe_tagi = []
    nazwa_uuid = {}
    druzyny, etykiety, zawodnicy = [], [], []
    for dep in deps:
        typ, d = dep.get("type"), dep.get("data") or {}
        nazwa = d.get("name")
        if typ == "tag" and d.get("uuid"):
            surowe_tagi.append(d)
            nazwa_uuid.setdefault(d["uuid"], nazwa)
        elif typ == "team" and nazwa:
            druzyny.append(nazwa)
        elif typ == "label" and nazwa:
            etykiety.append(nazwa)
        elif typ == "player" and nazwa:
            zawodnicy.append(nazwa)

    nieznane = []

    def nazwy(uuids):
        out = []
        for u in uuids if isinstance(uuids, list) else []:
            n = nazwa_uuid.get(u)
            if n is None:
                if u not in nieznane:
                    nieznane.append(u)
                continue
            if n not in out:
                out.append(n)
        return out

    tagi, po_nazwie = [], {}
    for d in surowe_tagi:
        params = d.get("params") or {}
        wpis = {
            "uuid": d["uuid"],
            "nazwa": d.get("name"),
            # `cm` bywa nieobecne w starszych plikach — brak to NIE „fałsz",
            # tylko brak informacji; warstwa 1 pokaże wtedy liczbę, nie czas.
            "cm": params.get("cm") if isinstance(params.get("cm"), bool) else None,
            "aktywuje": nazwy(params.get("activation_tags")),
            "dezaktywuje": nazwy(params.get("deactivation_tags")),
            "time_before": _liczba(d.get("time_before")),
            "time_after": _liczba(d.get("time_after")),
        }
        tagi.append(wpis)
        # Pierwsze wystąpienie wygrywa — ta sama zasada co w palecie
        # (`parse.prep_palette`, ZGODNOŚĆ: setdefault).
        po_nazwie.setdefault(wpis["nazwa"], wpis)

    return {
        "wersja_livetag": ((dane or {}).get("software") or {}).get("version"),
        "tagi": tagi,
        "po_nazwie": po_nazwie,
        "druzyny": druzyny,
        "etykiety": etykiety,
        "zawodnicy": zawodnicy,
        "nieznane_uuid": nieznane,
    }


def uuid_tagow(projekt):
    """Zbiór UUID tagów — odcisk profilu analityka (W7 D)."""
    return sorted({t["uuid"] for t in (projekt or {}).get("tagi") or [] if t.get("uuid")})
