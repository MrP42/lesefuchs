"""Schritt 8: Opus-Encoding der Kapitel-WAVs (08_encode.json + 08_opus/).

ffmpeg-Aufruf nach Konzept §4.5: libopus, 24 kbps, 22.05 kHz, mono.
"""
from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

from ..config import Settings
from ..job import Job

ARTIFACT = "08_encode.json"
OPUS_DIR = "08_opus"


def run(job: Job, force: bool = False) -> None:
    input_hash = job.hash_of("06_alignment.json")
    if not force and job.step_done("encode", input_hash):
        print("  encode: unverändert, übersprungen")
        return

    if shutil.which("ffmpeg") is None:
        raise RuntimeError("ffmpeg nicht im PATH — bitte installieren (winget install Gyan.FFmpeg)")

    doc = job.read_json("06_alignment.json")
    entries = []
    for chapter in doc["chapters"]:
        wav = job.path(chapter["wav"])
        opus_rel = f"{OPUS_DIR}/{chapter['id']}.opus"
        opus = job.path(opus_rel)
        opus.parent.mkdir(parents=True, exist_ok=True)
        cmd = build_ffmpeg_cmd(wav, opus, job.settings)
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"ffmpeg fehlgeschlagen für {wav.name}: {result.stderr[-400:]}")
        entries.append({
            "chapter": chapter["id"],
            "opus": opus_rel,
            "duration_ms": chapter["duration_ms"],
            "size_bytes": opus.stat().st_size,
        })
        print(f"  encode: {chapter['id']} → {opus.stat().st_size // 1024} KiB")

    job.write_json(ARTIFACT, {"entries": entries})
    job.mark_step("encode", input_hash, chapters=len(entries))


def build_ffmpeg_cmd(wav: Path, opus: Path, settings: Settings) -> list[str]:
    return [
        "ffmpeg", "-y", "-loglevel", "error",
        "-i", str(wav),
        "-c:a", "libopus",
        "-b:a", settings.opus_bitrate,
        "-ar", str(settings.opus_sample_rate),
        "-ac", "1",
        str(opus),
    ]
