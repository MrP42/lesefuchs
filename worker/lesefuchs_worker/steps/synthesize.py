"""Schritt 4: TTS über Fish-Speech, Absatz für Absatz (04_synthesis.json + 04_audio/).

Protokoll wie im local-voice-Projekt verifiziert:
  POST {fish_url}/v1/tts
  {"text": …, "format": "wav", "seed": …, "streaming": false,
   "reference_id": …, "use_memory_cache": "on"}   (letzte zwei nur mit Stimme)
Antwortvalidierung über RIFF-Magic + Mindestgröße.

Cache: je Absatz ein WAV unter 04_audio/<kapitel>/p<idx>.wav; ein Absatz wird
nur neu synthetisiert, wenn Text/Seed/Stimme sich geändert haben (Resume!).
"""
from __future__ import annotations

import hashlib
import wave
from pathlib import Path

import requests

from ..config import Settings
from ..job import Job

ARTIFACT = "04_synthesis.json"
AUDIO_DIR = "04_audio"


def run(job: Job, force: bool = False) -> None:
    input_hash = job.hash_of("03_normalized.json")
    if not force and job.step_done("synthesize", input_hash):
        print("  synthesize: unverändert, übersprungen")
        return

    settings = job.settings
    if not fish_available(settings.fish_url):
        raise RuntimeError(
            f"Fish-Speech unter {settings.fish_url} nicht erreichbar — "
            "Server starten (C:\\AI\\fish-speech\\start-fish-speech.ps1) und erneut ausführen. "
            "Bereits synthetisierte Absätze bleiben erhalten."
        )

    doc = job.read_json("03_normalized.json")
    previous = {e["key"]: e for e in _existing_entries(job)}
    entries = []
    synthesized = 0
    reused = 0

    for chapter in doc["chapters"]:
        for idx, para in enumerate(chapter["paragraphs"]):
            text = para["text"]
            key = f"{chapter['id']}/p{idx:03d}"
            wav_rel = f"{AUDIO_DIR}/{chapter['id']}/p{idx:03d}.wav"
            wav_path = job.path(wav_rel)

            # Wiederverwendung gegen den Hash der VORHANDENEN Aufnahme (mit
            # deren Seed) prüfen — so überlebt eine vom verify-Schritt mit
            # anderem Seed erzeugte, geprüfte Aufnahme jeden weiteren Lauf.
            prev = previous.get(key)
            if (not force and prev and wav_path.is_file()
                    and prev["hash"] == paragraph_hash(text, prev["seed"], settings.fish_reference_id)):
                entries.append(prev)
                reused += 1
                continue
            content_hash = paragraph_hash(text, settings.fish_seed, settings.fish_reference_id)

            audio = synthesize_paragraph(settings, text, settings.fish_seed)
            wav_path.parent.mkdir(parents=True, exist_ok=True)
            wav_path.write_bytes(audio)
            entries.append({
                "key": key,
                "chapter": chapter["id"],
                "para_index": idx,
                "text": text,
                "wav": wav_rel,
                "hash": content_hash,
                "seed": settings.fish_seed,
                "duration_ms": wav_duration_ms(wav_path),
            })
            synthesized += 1
            print(f"  synthesize: {key} ({len(audio) // 1024} KiB)")

    job.write_json(ARTIFACT, {"entries": entries})
    job.mark_step("synthesize", input_hash, synthesized=synthesized, reused=reused)
    print(f"  synthesize: {synthesized} neu, {reused} aus Cache")


def _existing_entries(job: Job) -> list[dict]:
    if job.path(ARTIFACT).exists():
        return job.read_json(ARTIFACT).get("entries", [])
    return []


# ---- testbare Bausteine --------------------------------------------------

def paragraph_hash(text: str, seed: int, reference_id: str) -> str:
    payload = f"{text}|{seed}|{reference_id}"
    return hashlib.sha256(payload.encode()).hexdigest()[:16]


def looks_like_wav(data: bytes) -> bool:
    return len(data) > 1024 and data[:4] == b"RIFF"


def fish_available(url: str) -> bool:
    try:
        return requests.get(f"{url}/v1/health", timeout=5).status_code == 200
    except requests.RequestException:
        return False


def synthesize_paragraph(settings: Settings, text: str, seed: int) -> bytes:
    """Ein Absatz → WAV-Bytes. Wirft RuntimeError bei ungültiger Antwort."""
    payload: dict = {"text": text, "format": "wav", "seed": seed, "streaming": False}
    if settings.fish_reference_id:
        payload["reference_id"] = settings.fish_reference_id
        payload["use_memory_cache"] = "on"
    resp = requests.post(
        f"{settings.fish_url}/v1/tts", json=payload, timeout=settings.fish_timeout_s
    )
    if resp.status_code != 200:
        raise RuntimeError(f"Fish-Speech HTTP {resp.status_code}: {resp.text[:200]}")
    if not looks_like_wav(resp.content):
        raise RuntimeError("Fish-Speech-Antwort ist kein WAV (RIFF-Magic fehlt)")
    return resp.content


def wav_duration_ms(path: Path) -> int:
    with wave.open(str(path), "rb") as w:
        return round(w.getnframes() / w.getframerate() * 1000)
