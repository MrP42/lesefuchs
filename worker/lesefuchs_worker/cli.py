"""CLI des Lesefuchs-Workers (argparse, kein zusätzliches Framework).

  lesefuchs-worker run --input examples/beispiel.md [--title "…"] [--from align] [--force]
  lesefuchs-worker step synthesize --job beispiel-a1b2c3d4 [--force]
  lesefuchs-worker jobs
"""
from __future__ import annotations

import argparse
import importlib
import json
import sys
from pathlib import Path

from .config import get_settings
from .job import STEP_ORDER, Job


def _step_module(name: str):
    return importlib.import_module(f"lesefuchs_worker.steps.{name}")


def cmd_run(args: argparse.Namespace) -> int:
    settings = get_settings()
    input_path = Path(args.input)
    if not input_path.is_file():
        print(f"Eingabedatei nicht gefunden: {input_path}", file=sys.stderr)
        return 2
    job = Job.create(input_path, settings, title=args.title)
    print(f"Job: {job.job_id}  ({job.dir})")

    start_index = STEP_ORDER.index(args.from_step) if args.from_step else 0
    for step in STEP_ORDER[start_index:]:
        print(f"→ {step} …", flush=True)
        _step_module(step).run(job, force=args.force)
    print(f"Fertig. Paket in {settings.out_dir}/")
    return 0


def cmd_step(args: argparse.Namespace) -> int:
    settings = get_settings()
    job = Job.load(args.job, settings)
    _step_module(args.name).run(job, force=args.force)
    return 0


def cmd_jobs(args: argparse.Namespace) -> int:
    settings = get_settings()
    if not settings.work_dir.is_dir():
        print("(keine Jobs)")
        return 0
    for state_file in sorted(settings.work_dir.glob("*/state.json")):
        state = json.loads(state_file.read_text(encoding="utf-8"))
        done = [s for s in STEP_ORDER if state.get("steps", {}).get(s, {}).get("status") == "done"]
        last = done[-1] if done else "-"
        print(f"{state['job_id']:<40} {len(done)}/{len(STEP_ORDER)} Schritte  zuletzt: {last}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="lesefuchs-worker", description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    p_run = sub.add_parser("run", help="komplette Pipeline ausführen")
    p_run.add_argument("--input", required=True, help="TXT- oder MD-Datei")
    p_run.add_argument("--title", default=None, help="Buchtitel (Default: Dateiname)")
    p_run.add_argument("--from", dest="from_step", choices=STEP_ORDER, default=None,
                       help="ab diesem Schritt (frühere Ergebnisse werden benutzt)")
    p_run.add_argument("--force", action="store_true", help="Schritte neu rechnen, Cache ignorieren")
    p_run.set_defaults(func=cmd_run)

    p_step = sub.add_parser("step", help="einzelnen Schritt ausführen")
    p_step.add_argument("name", choices=STEP_ORDER)
    p_step.add_argument("--job", required=True, help="Job-ID (siehe `jobs`)")
    p_step.add_argument("--force", action="store_true")
    p_step.set_defaults(func=cmd_step)

    p_jobs = sub.add_parser("jobs", help="vorhandene Jobs auflisten")
    p_jobs.set_defaults(func=cmd_jobs)

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
