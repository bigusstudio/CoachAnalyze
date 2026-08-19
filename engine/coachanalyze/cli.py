"""Punkt wejścia silnika. Kontrakt opisany w docs/KONTRAKT_CLI.md.

ZASADA: stdout jest zarezerwowany na JSON. Wszystko inne idzie na stderr.
"""

import argparse
import json
import sys

from . import __version__, canon, coverage, metrics, render, report_template
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
    b.add_argument("--out-html", required=True)
    b.add_argument("--out-meta", required=True)
    b.add_argument("--out-canon")
    b.add_argument("--out-metrics")

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
    """
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
        config = dict(config, sections=sekcje)

    canon_result = canon.build(
        frame,
        mapping_profile=config.get("mapping_profile"),
        teams=config.get("teams"),
        report_template=templat,
        # Model xG — OPT-IN z konfiguracji (M3). Domyślnie wyłączony: sam fakt
        # istnienia modelu nie może zmienić liczby w żadnym raporcie.
        xg_model=bool((config.get("options") or {}).get("xg_model")),
    )
    meta = coverage.build_meta(
        frame, canon_result, config=config,
        has_json=bool(args.json_path), palette=palette,
    )
    metrics_pack = metrics.build(canon_result, meta=meta)

    # Kolejność celowa: artefakty danych powstają PRZED renderem. Brak szablonu
    # nie może kasować wyniku parsowania i modelu kanonicznego.
    if args.out_canon:
        write_canon(args.out_canon, canon_result, config, len(frame["events"]))
    if args.out_metrics:
        write_json(args.out_metrics, metrics_pack)

    # COVERAGE TEMPLAT x EKSPORT. Sekcja wlaczona w templacie, ale bez danych
    # w TYM eksporcie, znika z HTML-a i zostaje z powodem w raporcie pokrycia.
    # Decyzje podejmuje `build_sections` (jedno miejsce), render tylko wykonuje.
    #
    # Robimy to WYLACZNIE przy templacie: bez niego wyjscie ma byc bajt w bajt
    # takie jak dotad, na czym stoi test zloty.
    if templat is not None:
        config = dict(
            config,
            drop_sections=[s["id"] for s in meta["sections_unavailable"]],
        )

    html, report = render.render(
        frame, palette=palette, metrics=metrics_pack,
        canon_result=canon_result, config=config,
    )
    render.write(args.out_html, html)
    log_render(report)

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
