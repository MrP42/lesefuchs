"""Job-Verwaltung: work/<job-id>/ mit state.json — Wiederaufnahme nach Abbruch.

Jeder Pipeline-Schritt liest definierte Eingabe-Artefakte und schreibt genau
ein Ausgabe-Artefakt (JSON oder Audio). state.json hält je Schritt Status und
Eingabe-Hash; ein Schritt wird übersprungen, wenn er bereits mit identischem
Eingabe-Hash abgeschlossen wurde (kein --force).
"""
from __future__ import annotations

import hashlib
import json
import re
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from .config import Settings

STEP_ORDER = [
    "ingest",
    "optimize",
    "normalize",
    "synthesize",
    "verify",
    "align",
    "syllables",
    "encode",
    "package",
]


def make_job_id(input_path: Path) -> str:
    """Stabile Job-ID aus Dateiname + Inhalts-Hash (8 hex)."""
    content = input_path.read_bytes()
    digest = hashlib.sha256(content).hexdigest()[:8]
    stem = re.sub(r"[^a-z0-9]+", "-", input_path.stem.lower()).strip("-") or "job"
    return f"{stem}-{digest}"


@dataclass
class Job:
    job_id: str
    settings: Settings
    state: dict[str, Any] = field(default_factory=dict)

    @property
    def dir(self) -> Path:
        return self.settings.work_dir / self.job_id

    @property
    def state_path(self) -> Path:
        return self.dir / "state.json"

    # ---- Lebenszyklus ----------------------------------------------------

    @classmethod
    def create(cls, input_path: Path, settings: Settings, title: str | None = None) -> "Job":
        job = cls(make_job_id(input_path), settings)
        job.dir.mkdir(parents=True, exist_ok=True)
        if job.state_path.exists():
            job.state = json.loads(job.state_path.read_text(encoding="utf-8"))
        else:
            job.state = {
                "job_id": job.job_id,
                "input_name": input_path.name,
                "created_at": _now(),
                "steps": {},
            }
        # Quelle immer aktuell in den Job kopieren (Grundlage aller Hashes)
        source = job.dir / "00_source" / input_path.name
        source.parent.mkdir(exist_ok=True)
        source.write_bytes(input_path.read_bytes())
        job.state["source_file"] = str(source.relative_to(job.dir))
        if title:
            job.state["title"] = title
        job.state.setdefault("title", input_path.stem)
        job.save()
        return job

    @classmethod
    def load(cls, job_id: str, settings: Settings) -> "Job":
        job = cls(job_id, settings)
        if not job.state_path.exists():
            raise FileNotFoundError(f"Job nicht gefunden: {job.dir}")
        job.state = json.loads(job.state_path.read_text(encoding="utf-8"))
        return job

    def save(self) -> None:
        self.state_path.write_text(
            json.dumps(self.state, ensure_ascii=False, indent=2), encoding="utf-8"
        )

    # ---- Artefakte -------------------------------------------------------

    def path(self, name: str) -> Path:
        return self.dir / name

    def write_json(self, name: str, data: Any) -> Path:
        p = self.path(name)
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
        return p

    def read_json(self, name: str) -> Any:
        return json.loads(self.path(name).read_text(encoding="utf-8"))

    def source_path(self) -> Path:
        return self.dir / self.state["source_file"]

    # ---- Schritt-Status (Resume) ----------------------------------------

    def step_done(self, step: str, input_hash: str) -> bool:
        info = self.state["steps"].get(step)
        return bool(info and info.get("status") == "done" and info.get("input_hash") == input_hash)

    def mark_step(self, step: str, input_hash: str, **meta: Any) -> None:
        self.state["steps"][step] = {
            "status": "done",
            "input_hash": input_hash,
            "finished_at": _now(),
            **meta,
        }
        self.save()

    def hash_of(self, *artifacts: str) -> str:
        """Eingabe-Hash eines Schritts über die Inhalte seiner Eingabe-Artefakte."""
        h = hashlib.sha256()
        for name in artifacts:
            p = self.path(name)
            h.update(name.encode())
            if p.exists():
                h.update(p.read_bytes())
        return h.hexdigest()[:16]


def _now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%S")
