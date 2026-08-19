"""Schritt 9: Paketbau (.lesepaket nach Konzept §4.2–4.4) → out/.

manifest.json  — Metadaten + Kapitelliste (tokenStart/tokenEnd)
content.json   — tokens (Wort + Silben-Timings, vis: null, src), sentences,
                 paragraphs; Zeiten relativ zum Kapitel-Audio.
audio/chNN.opus

Typ REFLOW: keine pages/, kein Faksimile; `page` in sentences/paragraphs
trägt die Kapitelnummer (1-basiert), `vis` bleibt null.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
import uuid
import zipfile

from ..job import Job

SENTENCE_END = re.compile(r"[.!?…]+[\"'“”«»)]*$")


def run(job: Job, force: bool = False) -> None:
    input_hash = job.hash_of("07_syllables.json", "08_encode.json")
    if not force and job.step_done("package", input_hash):
        print("  package: unverändert, übersprungen")
        return

    settings = job.settings
    syl_doc = job.read_json("07_syllables.json")
    norm_doc = job.read_json("03_normalized.json")
    encode_doc = job.read_json("08_encode.json")
    opus_by_chapter = {e["chapter"]: e for e in encode_doc["entries"]}
    titles = {c["id"]: c["title"] for c in norm_doc["chapters"]}
    srcmaps = {
        (c["id"], idx): {int(k): v for k, v in p.get("src", {}).items()}
        for c in norm_doc["chapters"]
        for idx, p in enumerate(c["paragraphs"])
    }

    content = build_content(syl_doc, srcmaps)
    manifest = build_manifest(job, syl_doc, content, opus_by_chapter, titles)

    out_dir = settings.out_dir
    out_dir.mkdir(parents=True, exist_ok=True)
    slug = re.sub(r"[^a-z0-9]+", "-", job.state["title"].lower()).strip("-") or "paket"
    target = out_dir / f"{slug}_v{manifest['packageVersion']}.lesepaket"

    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2))
        zf.writestr("content.json", json.dumps(content, ensure_ascii=False))
        for entry in encode_doc["entries"]:
            zf.write(job.path(entry["opus"]), f"audio/{entry['chapter']}.opus")

    job.mark_step("package", input_hash, package=str(target),
                  tokens=len(content["tokens"]), sentences=len(content["sentences"]))
    print(f"  package: {target}  ({len(content['tokens'])} Tokens, "
          f"{len(content['sentences'])} Sätze, {target.stat().st_size // 1024} KiB)")


# ---- testbare Bausteine --------------------------------------------------

def build_content(syl_doc: dict, srcmaps: dict) -> dict:
    """Alignment+Silben-Dokument → content.json (§4.4)."""
    tokens: list[dict] = []
    sentences: list[dict] = []
    paragraphs: list[dict] = []

    for ch_num, chapter in enumerate(syl_doc["chapters"], start=1):
        for para in chapter["paragraphs"]:
            para_i = len(paragraphs)
            sentence_start = len(sentences)
            srcmap = srcmaps.get((chapter["id"], para["para_index"]), {})

            current_start: int | None = None
            for word_idx, word in enumerate(para["words"]):
                i = len(tokens)
                if current_start is None:
                    current_start = i
                token = {
                    "i": i,
                    "w": word["w"],
                    "t0": word["t0"],
                    "t1": word["t1"],
                    "sent": len(sentences),
                    "para": para_i,
                    "syl": word["syl"],
                    "vis": None,
                }
                if word_idx in srcmap:
                    token["src"] = srcmap[word_idx]
                tokens.append(token)

                if SENTENCE_END.search(word["w"]):
                    sentences.append(_sentence(tokens, current_start, ch_num))
                    current_start = None

            if current_start is not None:  # Absatz ohne Schluss-Interpunktion
                sentences.append(_sentence(tokens, current_start, ch_num))

            paragraphs.append({
                "i": para_i,
                "page": ch_num,
                "sentenceStart": sentence_start,
                "sentenceEnd": len(sentences) - 1,
            })

    return {"tokens": tokens, "sentences": sentences, "paragraphs": paragraphs}


def _sentence(tokens: list[dict], start: int, page: int) -> dict:
    return {
        "i": tokens[start]["sent"],
        "t0": tokens[start]["t0"],
        "t1": tokens[-1]["t1"],
        "tokenStart": start,
        "tokenEnd": len(tokens) - 1,
        "page": page,
    }


def build_manifest(job: Job, syl_doc: dict, content: dict,
                   opus_by_chapter: dict, titles: dict) -> dict:
    settings = job.settings
    chapters = []
    for ch_num, chapter in enumerate(syl_doc["chapters"], start=1):
        token_ids = [t["i"] for t in content["tokens"]
                     if content["paragraphs"][t["para"]]["page"] == ch_num]
        chapters.append({
            "id": chapter["id"],
            "title": titles.get(chapter["id"], f"Kapitel {ch_num}"),
            "audio": f"audio/{chapter['id']}.opus",
            "firstPage": ch_num,
            "lastPage": ch_num,
            "tokenStart": token_ids[0] if token_ids else 0,
            "tokenEnd": token_ids[-1] if token_ids else 0,
        })

    content_bytes = json.dumps(content, ensure_ascii=False).encode()
    duration = sum(e["duration_ms"] for e in opus_by_chapter.values())
    voice = settings.voice_label + (f":{settings.fish_reference_id}" if settings.fish_reference_id else "")

    return {
        "schema": "lesefuchs/1.0",
        "id": str(uuid.uuid5(uuid.NAMESPACE_URL, f"lesefuchs:{job.job_id}")),
        "title": job.state["title"],
        "author": None,
        "type": "REFLOW",
        "language": settings.language,
        "readingLevel": settings.reading_level,
        "pageCount": len(chapters),
        "durationMs": duration,
        "voice": voice,
        "createdAt": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "packageVersion": 1,
        "checksums": {"content.json": "sha256:" + hashlib.sha256(content_bytes).hexdigest()},
        "chapters": chapters,
    }
