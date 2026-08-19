"""Schritt 7: Silbentrennung + Silben-Timings (07_syllables.json).

pyphen mit deutschen Trennmustern (de_DE). Die Wortdauer wird proportional
auf die Silben verteilt, gewichtet nach Zeichenzahl, Vokalkerne zählen
doppelt (Konzept §4.5). Interpunktion am Wortrand gehört zu keiner Silbe.
"""
from __future__ import annotations

import re

import pyphen

from ..job import Job

ARTIFACT = "07_syllables.json"

_dic = pyphen.Pyphen(lang="de_DE")
VOWELS = set("aeiouyäöü")


def run(job: Job, force: bool = False) -> None:
    input_hash = job.hash_of("06_alignment.json")
    if not force and job.step_done("syllables", input_hash):
        print("  syllables: unverändert, übersprungen")
        return

    doc = job.read_json("06_alignment.json")
    total = 0
    for chapter in doc["chapters"]:
        for para in chapter["paragraphs"]:
            for word in para["words"]:
                word["syl"] = syllable_timings(word["w"], word["t0"], word["t1"])
                total += len(word["syl"])

    job.write_json(ARTIFACT, doc)
    job.mark_step("syllables", input_hash, syllables=total)
    print(f"  syllables: {total} Silben")


# ---- testbare Bausteine --------------------------------------------------

def core_word(token: str) -> str:
    """Wort ohne Rand-Interpunktion („Bäume!“ → Bäume)."""
    return re.sub(r"^[^\wäöüß]+|[^\wäöüß]+$", "", token, flags=re.IGNORECASE)


def syllabify(token: str) -> list[str]:
    core = core_word(token)
    if not core:
        return [token]
    return _dic.inserted(core).split("-")


def syllable_weight(syl: str) -> int:
    """Zeichenzahl, Vokale doppelt — Näherung für die Sprechdauer."""
    return len(syl) + sum(1 for c in syl.lower() if c in VOWELS)


def syllable_timings(token: str, t0: int, t1: int) -> list[dict]:
    syls = syllabify(token)
    weights = [syllable_weight(s) for s in syls]
    total = sum(weights) or 1
    span = max(t1 - t0, 0)
    out = []
    cursor = float(t0)
    for syl, w in zip(syls, weights):
        width = span * w / total
        out.append({"s": syl, "t0": round(cursor), "t1": round(cursor + width)})
        cursor += width
    if out:
        out[-1]["t1"] = t1  # Rundungsrest ans letzte Silbenende
    return out
