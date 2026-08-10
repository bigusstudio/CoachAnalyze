"""Punkt wejścia silnika. Kontrakt opisany w docs/KONTRAKT_CLI.md.

ZASADA: stdout jest zarezerwowany na JSON. Wszystko inne idzie na stderr.
"""

import argparse
import json
import sys

from . import __version__
from .errors import EngineError


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


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    try:
        if args.command == "build":
            raise NotImplementedError("build — do implementacji w Etapie 2 (refaktor silnika)")
        if args.command == "inspect":
            raise NotImplementedError("inspect — do implementacji w Etapie 2")
    except EngineError as exc:
        payload = {"ok": False, "code": exc.code, "msg": str(exc), "engine_version": __version__}
        if getattr(args, "out_meta", None):
            with open(args.out_meta, "w", encoding="utf-8") as fh:
                json.dump(payload, fh, ensure_ascii=False, indent=2)
        print(json.dumps(payload, ensure_ascii=False))
        return exc.exit_code
    except Exception:  # noqa: BLE001 — traceback do logu, nigdy do przeglądarki
        import traceback
        traceback.print_exc(file=sys.stderr)
        print(json.dumps({"ok": False, "code": "INTERNAL"}, ensure_ascii=False))
        return 4
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
