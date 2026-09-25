"""Punkt wejścia silnika. Kontrakt opisany w docs/KONTRAKT_CLI.md.

ZASADA: stdout jest zarezerwowany na JSON. Wszystko inne idzie na stderr.
"""

import argparse
import json
import sys

from . import (__version__, canon, coverage, direction as direction_mod,
               events as events_mod, metrics, render, report_template)
from .errors import EngineError, MissingColumns
from .sources.livetag import parse


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="coachanalyze", description="Silnik CoachAnalyze")
    p.add_argument("--version", action="version", version=__version__)
    sub = p.add_subparsers(dest="command", required=True)

    b = sub.add_parser("build", help="Pełne przetworzenie: parsowanie, metryki, render HTML")
    b.add_argument("--csv", required=True)
    b.add_argument("--json", dest="json_path")
    b.add_argument("--config", required=True)
    # Templat raportu klubu (Sesja 5). OPCJONALNY — bez niego pipeline zachowuje
    # sie dokladnie tak, jak przed ta sesja, co do bajtu w wyjsciu renderu.
    b.add_argument("--template", dest="template_path")
    # GENERACJA SZABLONU HTML — INNA RZECZ NIZ `--template` POWYZEJ.
    #
    # `--template` to templat raportu KLUBU: zmienne, bindingi kanoniczne i sekcje
    # z konfiguratora, czyli CO liczymy i co pokazujemy. `--html-template` to plik
    # szablonu, czyli JAK to wyglada. Dwie nazwy blisko siebie sa ryzykiem i dlatego
    # obie sa opisane w docs/KONTRAKT_CLI.md obok siebie.
    #
    # Bez tego parametru obowiazuje `CA_HTML_TEMPLATE`, a bez niej `v17` — szablon,
    # ktorego wyjscia pilnuje test zloty.
    b.add_argument(
        "--html-template", dest="html_template", metavar="v17|v21|ŚCIEŻKA",
        help="generacja szablonu HTML albo ścieżka do pliku (domyślnie v17)",
    )
    b.add_argument("--out-html", required=True)
    b.add_argument("--out-meta", required=True)
    b.add_argument("--out-canon")
    b.add_argument("--out-metrics")
    # TABELA ZDARZEN (sesja 2 pivotu). OPCJONALNY — bez niego pipeline zachowuje
    # sie dokladnie jak dotad. Zapis idzie po SUROWYCH nazwach tagow, tak jak
    # liczy szablon raportu; model kanoniczny jest tu nieuzywany (events.py).
    b.add_argument("--out-events")

    i = sub.add_parser("inspect", help="Sam raport pokrycia, bez renderu")
    i.add_argument("--csv", required=True)
    i.add_argument("--json", dest="json_path")
    i.add_argument("--out-meta")

    g = sub.add_parser("xg-grid", help="Siatka xG dla warstwy PHP (interaktywne boisko)")
    g.add_argument("--out", required=True)
    g.add_argument("--step", type=float, default=1.0)

    return p


def write_meta(path, payload):
    """meta.json trafia na dysk i na stdout. Stdout jest zarezerwowany na JSON."""
    if path:
        with open(path, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2)
    print(json.dumps(payload, ensure_ascii=False))


def load_config(path):
    if not path:
        return {}
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def write_canon(path, canon_result, config, expected_count):
    """Zdarzenia kanoniczne do wstawienia w `events_canonical`.

    Niezmiennik: liczba rekordów == liczba wierszy eksportu. Sprawdzany, a nie
    zakładany — cicha utrata zdarzenia przy imporcie do archiwum ujawniłaby się
    dopiero przy porównaniu sezonowym, miesiące później.
    """
    records = canon.to_records(canon_result["events"], match_id=config.get("match_id"))
    if len(records) != expected_count:
        raise EngineError(
            "Liczba zdarzeń kanonicznych ({}) różni się od liczby wierszy eksportu ({})".format(
                len(records), expected_count
            )
        )

    payload = {
        "match_id": config.get("match_id"),
        "engine_version": __version__,
        "count": len(records),
        "events": records,
    }
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
    return payload


def ustal_kierunek(frame, config, templat):
    """(kierunek, ramka do renderu, czy odbito).

    Kierunek liczy się RAZ, na oryginalnej ramce, i ta sama odpowiedź obsługuje
    render i tabelę `events`. Policzony osobno w dwóch miejscach mógłby się
    rozjechać przy zmianie progu albo profilu — a rozjazd wyszedłby jako mapa
    odbita względem tabeli, czyli najtrudniejszy możliwy objaw.

    ODBIJAMY, GDY TENANT ATAKUJE W LEWO. Na mapach tenant atakuje zawsze w prawo
    (docs/STAN_PIVOTU.md §7.7 c); dane bez pozycji nie dają kierunku i wtedy nie
    odbijamy niczego — brak odpowiedzi jest lepszy niż odpowiedź zmyślona.

    MODEL KANONICZNY I METRYKI DOSTAJĄ RAMKĘ ORYGINALNĄ. Archiwum ma zapisywać
    to, co było w eksporcie; odbicie jest decyzją prezentacji i wraca w `meta`.
    """
    # ROZWIĄZANIE PROFILU I DOPASOWANIA DRUŻYN JAK W `canon.build` — ten sam
    # porządek pierwszeństwa (templat wygrywa z profilem kreatora) i ta sama
    # funkcja dopasowania nazw. Druga, „prawie taka sama" ścieżka dałaby kierunek
    # liczony na innym podziale drużyn niż reszta raportu.
    z_templatu = report_template.mapping_profile(templat)
    profil = canon.resolve_profile(
        z_templatu if z_templatu is not None else config.get("mapping_profile")
    )
    lookup = canon.build_team_lookup(
        config.get("teams"), report_template.team_markers(templat)
    )

    kierunek = direction_mod.wykryj(frame, tag_rules=profil["tags"], lookup=lookup)
    strona_tenanta = render.tenant_side(config)

    # ZMIANA STRON PO PRZERWIE WSTRZYMUJE ODBICIE. Mecz, w ktorym druzyny
    # zamienily polowy, nie ma JEDNEGO kierunku — mediana liczona przez obie
    # polowy miesza dwa przeciwne rozklady i laduje kolo srodka boiska. Odbicie
    # oparte na takiej liczbie przestawiloby mapy bez powodu; mowimy wiec, co
    # widac (KIERUNEK_ZMIANA_POLOWY plus baner nad raportem) i zostawiamy plik,
    # jaki jest.
    bez_normalizacji = direction_mod.KOD_ZMIANA_POLOWY in (kierunek.get("warnings") or ())
    odbic = kierunek.get(strona_tenanta) == "left" and not bez_normalizacji
    return kierunek, (direction_mod.odbij_ramke(frame) if odbic else frame), odbic


def write_events(path, frame, config):
    """Wiersze tabeli `events` — artefakt dla warstwy PHP.

    Powstaje PRZED renderem, tak samo jak `--out-canon`: awaria szablonu nie może
    kasować wyniku parsowania.

    Zdarzenia BEZ CZASU są pomijane (kolumna `t_ms` jest `NOT NULL`), a ich liczba
    wraca w `skipped_no_time` i na stderr. Cicha utrata zdarzenia przy imporcie
    ujawniłaby się dopiero przy porównaniu z raportem, miesiące później.
    """
    wynik = events_mod.build(frame, config=config, players=frame.get("players"))

    if wynik["skipped_no_time"]:
        print(
            "UWAGA: {} zdarzeń bez czytelnego czasu — pominięte w tabeli events".format(
                wynik["skipped_no_time"]
            ),
            file=sys.stderr,
        )

    payload = {
        "match_id": config.get("match_id"),
        "engine_version": __version__,
        "count": len(wynik["events"]),
        "skipped_no_time": wynik["skipped_no_time"],
        "events": wynik["events"],
    }
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
    return payload


def write_json(path, payload, indent=1):
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=indent)
    return payload


def log_render(report):
    """Raport renderu na stderr. Stdout jest zarezerwowany na `meta.json`.

    Cztery rzeczy, których nie widać po samym kodzie wyjścia:
    - `unresolved_placeholders` — znacznik szablonu, którego render nie wypełnił.
      Po poprawnym renderze pusto; niepusto znaczy uszkodzony szablon.
    - `teams_defaulted` — nazwa drużyny podstawiona zapasowo, bo nie było jej ani
      w konfiguracji, ani w danych. Raport wychodzi z „Drużyna A/B" w nagłówku.
    - `crests_generated` — herb wygenerowany (biały krążek z literą) zamiast wczytanego
      z pliku. Raport dla klubu powinien nieść jego herb.
    - `tag_mismatch` — szablon liczy po nazwie tagu, archiwum po pojęciu kanonicznym.
      Rozjazd znaczy, że coach i porównanie sezonowe zobaczą inne liczby.

    Generacja szablonu idzie do logu ZAWSZE, nie tylko przy nietypowej: pytanie
    „dlaczego raport z marca wygląda inaczej" (CLAUDE.md §7) ma mieć odpowiedź
    w logu, a nie w zgadywaniu, co wtedy było w `CA_HTML_TEMPLATE`.
    """
    print(
        "szablon HTML: {} ({})".format(
            report.get("template_generation") or "spoza zestawu", report["template"]
        ),
        file=sys.stderr,
    )
    if report.get("mirrored"):
        print(
            "kierunek: tenant atakował w lewo — współrzędne odbite (x' = 105 - x)",
            file=sys.stderr,
        )
    if report.get("tenant_side") == "them":
        print(
            "UWAGA: klub-tenant stoi po stronie 'them' konfiguracji — lewy slot "
            "raportu dostał drużynę 'them' (mecz scoutingowy)",
            file=sys.stderr,
        )
    if report["unresolved_placeholders"]:
        print(
            "UWAGA: szablon zawiera nierozwiązane znaczniki: "
            + ", ".join(report["unresolved_placeholders"]),
            file=sys.stderr,
        )
    for side in report["teams_defaulted"]:
        print(
            "UWAGA: brak nazwy drużyny '{}' w konfiguracji i w danych — "
            "podstawiono '{}'".format(side, report["teams"][side]),
            file=sys.stderr,
        )
    if report["missing_slots"]:
        print(
            "UWAGA: szablon niesie tylko część znaczników swojej grupy — brakuje: "
            + ", ".join(report["missing_slots"]),
            file=sys.stderr,
        )
    for side in report["crests_generated"]:
        print(
            "UWAGA: brak herbu drużyny '{}' w konfiguracji — wstawiono zastępczy".format(side),
            file=sys.stderr,
        )
    for mismatch in report["tag_mismatch"]:
        print(
            "UWAGA: rozjazd raport/archiwum dla '{}' — szablon liczy {} po tagu '{}', "
            "model kanoniczny {}".format(
                mismatch["concept"], mismatch["template_count"],
                mismatch["template_tag"], mismatch["metrics_count"],
            ),
            file=sys.stderr,
        )


def cmd_build(args) -> int:
    """Pełne przetworzenie: parsowanie, model kanoniczny, metryki, render HTML."""
    config = load_config(args.config)
    templat = report_template.load(getattr(args, "template_path", None))
    frame = parse.prep_frame(args.csv)
    palette = parse.prep_palette(args.json_path) if args.json_path else None

    # SEKCJE Z TEMPLATU nadpisuja te z konfiguracji zadania. `build_sections`
    # i tak dolozy powod do kazdej, ktorej dane nie pozwalaja narysowac —
    # coverage templat x eksport nie jest wiec osobnym mechanizmem, tylko tym
    # samym, ktoremu podajemy inna liste zyczen.
    sekcje = report_template.sections_enabled(templat)
    if sekcje is not None:
        # Sekcje dolozone w 4a/4b wracaja do stanu domyslnego, a nie do
        # „wylaczona" — templat sprzed ich istnienia nie mial jak ich wymienic.
        config = dict(config, sections=coverage.sekcje_z_templatu(sekcje, templat))

    canon_result = canon.build(
        frame,
        mapping_profile=config.get("mapping_profile"),
        teams=config.get("teams"),
        report_template=templat,
        # Model xG — OPT-IN z konfiguracji (M3). Domyślnie wyłączony: sam fakt
        # istnienia modelu nie może zmienić liczby w żadnym raporcie.
        xg_model=bool((config.get("options") or {}).get("xg_model")),
    )
    # KIERUNEK ATAKU Z DANYCH (sesja 4a). Liczony PRZED zapisem artefaktów,
    # bo tabela `events` i render mają dostać tę samą, odbitą ramkę.
    kierunek, frame_widok, odbito = ustal_kierunek(frame, config, templat)

    meta = coverage.build_meta(
        frame, canon_result, config=config,
        has_json=bool(args.json_path), palette=palette,
        # Dostepnosc sekcji z SUROWYCH TAGOW templatu, nie z pojec (sesja 1b).
        report_template=templat,
        direction=kierunek, mirrored=odbito,
    )
    metrics_pack = metrics.build(canon_result, meta=meta)

    # Kolejność celowa: artefakty danych powstają PRZED renderem. Brak szablonu
    # nie może kasować wyniku parsowania i modelu kanonicznego.
    if args.out_canon:
        write_canon(args.out_canon, canon_result, config, len(frame["events"]))
    if args.out_metrics:
        write_json(args.out_metrics, metrics_pack)
    if getattr(args, "out_events", None):
        # RAMKA PO ODBICIU. Tabela `events` ma JEDEN układ współrzędnych —
        # tenant atakuje w prawo — żeby porównanie sezonowe nie sumowało map
        # z dwóch przeciwnych stron boiska (docs/STAN_PIVOTU.md §7.7 c).
        write_events(args.out_events, frame_widok, config)

    # CO ZNIKA Z RAPORTU — dwie rozne rzeczy, jedna lista.
    #
    # 1. SEKCJA BEZ DANYCH w tym eksporcie. Decyzje podejmuje `build_sections`
    #    (jedno miejsce), render tylko wykonuje; powod wraca w raporcie pokrycia.
    # 2. SEKCJA NIEWYBRANA — obecna w szablonie v21, ale spoza zestawu tego
    #    raportu. Kafle z sesji 4b (donuty, okazje, zawodnicy, siatka) sa
    #    dostepne, a nie domyslne: doklada je kreator sekcji, nie sam fakt,
    #    ze szablon je niesie.
    #
    # BEZ WPLYWU NA v17: `drop_sections` wycina sekcje po identyfikatorze `<section>`
    # i pomija ten, ktorego w HTML-u nie ma. v17 nie ma zadnej z sekcji v21, wiec
    # wyjscie zostaje bajt w bajt takie jak dotad — na czym stoi test zloty.
    wybrane = config.get("sections") or list(coverage.DOMYSLNE_SEKCJE)
    config = dict(config, drop_sections=(
        [s["id"] for s in meta["sections_unavailable"]]
        + [s for s in coverage.ALL_SECTIONS if s not in wybrane]
    ))

    html, report = render.render(
        frame_widok, palette=palette, metrics=metrics_pack,
        canon_result=canon_result, config=config,
        template_path=getattr(args, "html_template", None),
        direction=kierunek, mirrored=odbito,
        # Progi faktow Przegladu: globalne z pliku, nadpisane przez templat klubu.
        report_template=templat,
    )
    render.write(args.out_html, html)
    log_render(report)

    # OSTRZEŻENIE ZAMIAST WYJĄTKU. Szablon, któremu przy edycji zniknął jeden
    # znacznik z grupy, nie zatrzymuje renderu — raport ma powstać zawsze
    # (docs/RUNBOOK.md). Brak trafia do `meta.warnings`, czyli tam, gdzie operator
    # ogląda resztę ubytków w tym raporcie, a nie do logu, który czyta wykonawca.
    #
    # Dopisujemy PO renderze i przed `write_meta` — `meta.json` powstaje na samym
    # końcu (patrz niżej), więc ostrzeżenie zdąży do pliku i na stdout.
    if report["missing_slots"]:
        meta.setdefault("warnings", []).append({
            "code": "BRAKUJACY_ZNACZNIK",
            "msg": (
                "Szablon nie zawiera części znaczników swojej grupy — pominięte: "
                + ", ".join(report["missing_slots"])
            ),
            "count": len(report["missing_slots"]),
            "placeholders": report["missing_slots"],
        })

    # `meta.json` na SAM KONIEC i tylko przy powodzeniu. Zapisane przed renderem
    # zostawiałoby na dysku `ok: true` bez raportu, a `main` dopisałby drugi obiekt
    # JSON na stdout — PHP czyta stamtąd dokładnie jeden.
    write_meta(args.out_meta, meta)
    return 0


def cmd_inspect(args) -> int:
    """Sam raport pokrycia, bez renderu — ekran „co jest w tym pliku" (KONTRAKT_CLI.md).

    `inspect` nie dostaje konfiguracji, więc nie zna nazw ani barw klubów.
    Wykryte w danych nazwy drużyn wracają w `coverage.teams`, żeby PHP mogło
    zaproponować dopasowanie przy pierwszym imporcie.
    """
    frame = parse.prep_frame(args.csv)
    palette = parse.prep_palette(args.json_path) if args.json_path else None
    canon_result = canon.build(frame)
    meta = coverage.build_meta(
        frame,
        canon_result,
        has_json=bool(args.json_path),
        palette=palette,
    )
    write_meta(getattr(args, "out_meta", None), meta)
    return 0


def cmd_xg_grid(args) -> int:
    """Siatka xG (M3) — artefakt odczytywany przez PHP przy interaktywnym boisku.

    PHP nie liczy metryk (CLAUDE.md §4) i nie może uruchomić Pythona z warstwy
    żądań (disable_functions) — dostaje więc wartości POLICZONE TU, raz,
    dla środka każdej komórki siatki. Plik trafia do repo (app/src/data/)
    i jest odtwarzalny tą komendą przy każdej zmianie współczynników.
    """
    from . import xg as xg_mod

    payload = {"engine_version": __version__} | xg_mod.grid(step=args.step)
    write_json(args.out, payload, indent=None)
    print("zapisano {} (krok {} m)".format(args.out, args.step), file=sys.stderr)
    return 0


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    try:
        if args.command == "build":
            return cmd_build(args)
        if args.command == "inspect":
            return cmd_inspect(args)
        if args.command == "xg-grid":
            return cmd_xg_grid(args)
    except EngineError as exc:
        payload = {"ok": False, "code": exc.code, "msg": str(exc), "engine_version": __version__}
        if isinstance(exc, MissingColumns):
            payload["missing_columns"] = exc.columns
        write_meta(getattr(args, "out_meta", None), payload)
        return exc.exit_code
    except Exception:  # noqa: BLE001 — traceback do logu, nigdy do przeglądarki
        import traceback
        traceback.print_exc(file=sys.stderr)
        print(json.dumps({"ok": False, "code": "INTERNAL"}, ensure_ascii=False))
        return 4
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
