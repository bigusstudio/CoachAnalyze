"""Punkt wejścia silnika. Kontrakt opisany w docs/KONTRAKT_CLI.md.

ZASADA: stdout jest zarezerwowany na JSON. Wszystko inne idzie na stderr.
"""

import argparse
import json
import sys

from . import __version__, canon, coverage
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
    b.add_argument("--out-html", required=True)
    b.add_argument("--out-meta", required=True)
    b.add_argument("--out-canon")

    i = sub.add_parser("inspect", help="Sam raport pokrycia, bez renderu")
    i.add_argument("--csv", required=True)
    i.add_argument("--json", dest="json_path")
    i.add_argument("--out-meta")

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


def cmd_build(args) -> int:
    """Pełne przetworzenie. Render jeszcze nie istnieje — patrz komentarz niżej."""
    config = load_config(args.config)
    frame = parse.prep_frame(args.csv)
    palette = parse.prep_palette(args.json_path) if args.json_path else None

    canon_result = canon.build(
        frame,
        mapping_profile=config.get("mapping_profile"),
        teams=config.get("teams"),
    )
    meta = coverage.build_meta(
        frame, canon_result, config=config,
        has_json=bool(args.json_path), palette=palette,
    )

    # Kolejność celowa: artefakty danych powstają PRZED renderem. Brak szablonu
    # nie może kasować wyniku parsowania i modelu kanonicznego.
    if args.out_canon:
        write_canon(args.out_canon, canon_result, config, len(frame["events"]))
    write_meta(args.out_meta, meta)

    # render.py czeka na implementację (szablon `engine/templates/dashboard_template.html`
    # już jest). Do tego czasu `build` kończy się kodem 4, mimo że dane są policzone.
    raise NotImplementedError("render — moduł render.py nie jest jeszcze zaimplementowany")


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


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    try:
        if args.command == "build":
            return cmd_build(args)
        if args.command == "inspect":
            return cmd_inspect(args)
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
