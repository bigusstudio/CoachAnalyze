#!/usr/bin/env python3
"""Karta dowodowa eksportu LiveTag: same fakty o tagach, bez interpretacji.

    python3 engine/tools/karta_dowodowa.py EKSPORT.csv EKSPORT.json > karta.json
    python3 engine/tools/karta_dowodowa.py --korpus KATALOG [KATALOG ...] > korpus.json

NARZĘDZIE TESTU W7-T, NIE CZĘŚĆ SILNIKA. Karta jest wejściem ślepego mappera:
model językowy dostaje wyłącznie ją i ma z niej zgadnąć znaczenie tagów.
Dlatego karta NIE ZAWIERA niczego, co zdradza odpowiedź (Słownik, canon.py),
ani niczego poufnego:

- nazwisk zawodników — jest tylko LICZBA zdarzeń z zawodnikiem,
- treści komentarzy — jest tylko ich KSZTAŁT („xG 0,55" -> „<litery> <liczba>"),
  a wszystko, co nie jest literami i liczbą, zamienia się w „<tekst>",
- pojedynczych zdarzeń — są rozkłady i liczności (CLAUDE.md §5: do modelu idą
  nazwy tagów i policzone liczby, nigdy surowe zdarzenia).

Tryb --korpus paruje CSV z JSON po zawartości (nazwy tagów z JSON muszą pokryć
`tag_name` z CSV), przy remisie po czasie modyfikacji pliku, usuwa duplikaty po
sha256 CSV i grupuje pliki w profile po podobieństwie Jaccarda zbiorów uuid tagów.

Tylko biblioteka standardowa (CLAUDE.md §3a).
"""
import argparse
import collections
import hashlib
import json
import pathlib
import re
import statistics
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from coachanalyze.sources.livetag.parse import (  # noqa: E402
    PLAYER_COLUMNS, is_na, read_rows, split_labels, to_float,
)

TOP_ETYKIET = 12
PROG_PROFILU = 0.8
BRAK = "brak"

# Kształt komentarza: opcjonalny krótki przedrostek literowy i jedna liczba.
# Wszystko inne to „<tekst>" — dłuższe słowa mogą być nazwiskiem albo taktyką.
_LICZBA = r"\d+(?:[.,]\d+)?|[.,]\d+"
_KSZTALT = re.compile(
    r"^\s*(?P<lit>[^\W\d_]{1,4})?\s*(?P<sep>[:=])?\s*(?P<num>" + _LICZBA + r")\s*$"
)


def ksztalt_komentarza(tekst):
    """Komentarz -> (kształt, liczba albo None). Treść nie wychodzi poza funkcję."""
    m = _KSZTALT.match(tekst)
    if not m:
        return "<tekst>", None
    czesci = []
    if m.group("lit"):
        czesci.append("<litery>")
    if m.group("sep"):
        czesci.append(m.group("sep"))
    liczba = m.group("num")
    czesci.append("<liczba z przecinkiem>" if "," in liczba else "<liczba>")
    return " ".join(czesci), float(liczba.replace(",", "."))


def sha256_pliku(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for blok in iter(lambda: fh.read(1 << 16), b""):
            h.update(blok)
    return h.hexdigest()


def sha256_zdarzen(rows):
    """Skrót niezależny od kolejności wierszy.

    Ten sam mecz wyeksportowany dwa razy potrafi mieć inną kolejność wierszy,
    więc inne sha256 pliku przy identycznym zbiorze zdarzeń.
    """
    h = hashlib.sha256()
    for linia in sorted(json.dumps(list(r.values()), ensure_ascii=False) for r in rows):
        h.update(linia.encode("utf-8") + b"\n")
    return h.hexdigest()


def _zaokr(v):
    return None if v is None else round(v, 2)


def _rozklad(wartosci):
    if not wartosci:
        return None
    return {
        "min": _zaokr(min(wartosci)),
        "mediana": _zaokr(statistics.median(wartosci)),
        "max": _zaokr(max(wartosci)),
    }


def wczytaj_json(json_path):
    """Definicje tagów z eksportu JSON: uuid -> {name, params, time_*}, drużyny, ikony."""
    with open(json_path, encoding="utf-8") as fh:
        dane = json.load(fh)
    tagi, druzyny, etykiety = {}, [], {}
    tagging = []
    for dep in dane.get("dependencies", []):
        typ, d = dep.get("type"), dep.get("data") or {}
        if typ == "tag":
            tagi[d["uuid"]] = d
        elif typ == "team":
            druzyny.append(d.get("name"))
        elif typ == "label":
            etykiety[d["uuid"]] = d.get("name")
        elif typ == "tagging":
            tagging.append(d)
    # Kształt znacznika na boisku (pułapka 6) — z wierszy taggingu, per tag.
    ikony = collections.defaultdict(collections.Counter)
    for t in tagging:
        for wiersz in t.get("rows", []):
            for e in wiersz.get("entities", []):
                ikona = ((e.get("location") or {}).get("pitch_view") or {}).get("icon_name")
                ikony[wiersz.get("tag_uuid")][ikona or BRAK] += 1
    return {
        "tagi": tagi,
        "druzyny": druzyny,
        "etykiety": etykiety,
        "ikony": ikony,
        "wersja_livetag": (dane.get("software") or {}).get("version"),
    }


def fingerprint(json_info):
    return sorted(json_info["tagi"])


def jaccard(a, b):
    a, b = set(a), set(b)
    return round(len(a & b) / len(a | b), 3) if (a or b) else 1.0


def karta(csv_path, json_path):
    rows, headers = read_rows(csv_path)
    info = wczytaj_json(json_path)
    kol_zaw = next((c for c in PLAYER_COLUMNS if c in headers), None)
    po_nazwie = {d["name"]: u for u, d in info["tagi"].items()}

    zdarzenia = collections.defaultdict(list)
    for r in rows:
        zdarzenia[r["tag_name"]].append(r)

    druzyny_csv = collections.Counter(
        r["team"].strip() for r in rows if not is_na(r.get("team"))
    )

    wynik_tagi = []
    nazwy = list(dict.fromkeys(
        [d["name"] for d in info["tagi"].values()] + list(zdarzenia)
    ))
    for nazwa in nazwy:
        ev = zdarzenia.get(nazwa, [])
        uuid = po_nazwie.get(nazwa)
        d = info["tagi"].get(uuid, {})
        params = d.get("params") or {}

        def nazwy_tagow(uuids):
            return [info["tagi"].get(u, {}).get("name", "<nieznany uuid>") for u in uuids or []]

        xs = [v for v in (to_float(r.get("pos_x_meters")) for r in ev) if v is not None]
        czasy = []
        for r in ev:
            b, e = to_float(r.get("begin")), to_float(r.get("end"))
            if b is not None and e is not None:
                czasy.append(e - max(0.0, b))
        etykiety = collections.Counter()
        for r in ev:
            etykiety.update(split_labels(r.get("labels")) if not is_na(r.get("labels")) else [])
        ksztalty, liczby_kom = collections.Counter(), []
        for r in ev:
            k = r.get("comment")
            if is_na(k) or not k.strip():
                continue
            ksz, liczba = ksztalt_komentarza(k)
            ksztalty[ksz] += 1
            if liczba is not None:
                liczby_kom.append(liczba)
        druz = collections.Counter(
            BRAK if is_na(r.get("team")) or not r["team"].strip() else r["team"].strip()
            for r in ev
        )
        wynik_tagi.append({
            "nazwa": nazwa,
            "uuid": uuid,
            "n": len(ev),
            "druzyny": dict(druz.most_common()),
            "z_pos_x": len(xs),
            "z_pos_target": sum(1 for r in ev if to_float(r.get("pos_target_x_meters")) is not None),
            "pos_x": _rozklad(xs),
            "z_zawodnikiem": sum(1 for r in ev if kol_zaw and not is_na(r.get(kol_zaw))),
            "czas_trwania_mediana_s": _zaokr(statistics.median(czasy)) if czasy else None,
            "etykiety_top": dict(etykiety.most_common(TOP_ETYKIET)),
            "etykiety_roznych": len(etykiety),
            "komentarze": sum(ksztalty.values()),
            "ksztalty_komentarzy": dict(ksztalty.most_common()),
            "liczby_z_komentarzy": _rozklad(liczby_kom),
            "znaczniki_na_boisku": dict(info["ikony"].get(uuid, {})) if uuid else {},
            "cm": params.get("cm"),
            "aktywowany_przez": nazwy_tagow(params.get("activation_tags")),
            "dezaktywowany_przez": nazwy_tagow(params.get("deactivation_tags")),
            "time_before": d.get("time_before"),
            "time_after": d.get("time_after"),
            "tylko_w_json": uuid is not None and not ev,
            "tylko_w_csv": uuid is None,
        })

    return {
        "plik": {
            "sha256_csv": sha256_pliku(csv_path),
            "sha256_zdarzen": sha256_zdarzen(rows),
            "druzyny_csv": dict(druzyny_csv.most_common()),
            "druzyny_json": info["druzyny"],
            "zdarzen": len(rows),
            "wersja_livetag": info["wersja_livetag"],
            "kolumny": headers,
        },
        "fingerprint": fingerprint(info),
        "tagi": wynik_tagi,
    }


# ─── tryb korpusu ──────────────────────────────────────────────────────────


def _nazwy_csv(path):
    rows, _ = read_rows(path)
    return set(r["tag_name"] for r in rows)


def paruj(katalogi):
    """Paruje CSV z JSON: nazwy tagów z JSON ⊇ tag_name z CSV, remis -> najbliższy mtime."""
    csvs, jsons = [], []
    for k in katalogi:
        for p in sorted(pathlib.Path(k).rglob("*")):
            if p.suffix.lower() == ".csv":
                csvs.append(p)
            elif p.suffix.lower() == ".json":
                jsons.append(p)
    info_j = {}
    for j in jsons:
        try:
            info_j[j] = wczytaj_json(j)
        except (ValueError, KeyError):
            continue
    pary, bez_pary = [], []
    for c in csvs:
        nazwy = _nazwy_csv(c)
        kandydaci = [
            j for j, inf in info_j.items()
            if nazwy <= {d["name"] for d in inf["tagi"].values()}
        ]
        if not kandydaci:
            bez_pary.append(str(c))
            continue
        # Najpierw ten sam katalog, potem najbliższy czas zapisu.
        mt = c.stat().st_mtime
        kandydaci.sort(key=lambda j: (j.parent != c.parent, abs(j.stat().st_mtime - mt)))
        pary.append((c, kandydaci[0], mt - kandydaci[0].stat().st_mtime))
    return pary, bez_pary, info_j


def korpus(katalogi):
    pary, bez_pary, info_j = paruj(katalogi)
    unikalne = {}
    duplikaty = collections.defaultdict(list)
    sha_plikow = set()
    for c, j, dt in pary:
        sha_pliku = sha256_pliku(c)
        sha_plikow.add(sha_pliku)
        # Deduplikacja po treści, nie po bajtach — patrz sha256_zdarzen.
        sha = sha256_zdarzen(read_rows(c)[0])
        duplikaty[sha].append({
            "csv": str(c), "json": str(j), "sha256_csv": sha_pliku,
            "roznica_mtime_s": round(dt, 1),
        })
        unikalne.setdefault(sha, (c, j))
    fp = {sha: fingerprint(info_j[j]) for sha, (c, j) in unikalne.items()}
    shas = list(unikalne)
    podobienstwo = {a: {b: jaccard(fp[a], fp[b]) for b in shas if b != a} for a in shas}

    # Profile: składowe spójne grafu „Jaccard >= próg".
    profil_id, profile = {}, []
    for a in shas:
        if a in profil_id:
            continue
        stos, grupa = [a], []
        profil_id[a] = len(profile)
        while stos:
            x = stos.pop()
            grupa.append(x)
            for y in shas:
                if y not in profil_id and podobienstwo[x][y] >= PROG_PROFILU:
                    profil_id[y] = len(profile)
                    stos.append(y)
        profile.append(sorted(grupa))

    return {
        "par": len(pary),
        "csv_bez_pary": bez_pary,
        "unikalnych_plikow_sha256": len(sha_plikow),
        "unikalnych_meczow": len(unikalne),
        "mecze": {
            sha: {
                "csv": str(c), "json": str(j), "profil": profil_id[sha],
                "wystapienia": duplikaty[sha],
            }
            for sha, (c, j) in unikalne.items()
        },
        "profile": profile,
        "jaccard": podobienstwo,
    }


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("pliki", nargs="*", help="EKSPORT.csv EKSPORT.json")
    ap.add_argument("--korpus", nargs="+", metavar="KATALOG")
    ap.add_argument("--porownaj", nargs="*", default=[], metavar="JSON",
                    help="inne eksporty JSON do podobieństwa Jaccarda fingerprintu")
    a = ap.parse_args(argv)
    if a.korpus:
        wynik = korpus(a.korpus)
    else:
        if len(a.pliki) != 2:
            ap.error("podaj parę: EKSPORT.csv EKSPORT.json")
        wynik = karta(*a.pliki)
        wynik["jaccard"] = {
            p: jaccard(wynik["fingerprint"], fingerprint(wczytaj_json(p))) for p in a.porownaj
        }
    json.dump(wynik, sys.stdout, ensure_ascii=False, indent=1)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
