"""Schritt 1: TXT/MD einlesen → Kapitel + Absätze (01_paragraphs.json).

Markdown-Konventionen (bewusst schlicht):
  - `# Überschrift`  → Buchtitel (überschreibt Dateinamen, nicht --title)
  - `## Überschrift` → Kapitelgrenze mit Kapiteltitel
  - Leerzeile        → Absatzgrenze
  - `**fett**`, `*kursiv*`, `_kursiv_`, Inline-`Code` → Auszeichnung entfernt
Ohne `##`-Überschriften entsteht genau ein Kapitel.
"""
from __future__ import annotations

import re

from ..job import Job

ARTIFACT = "01_paragraphs.json"


def run(job: Job, force: bool = False) -> None:
    input_hash = job.hash_of(job.state["source_file"])
    if not force and job.step_done("ingest", input_hash):
        print("  ingest: unverändert, übersprungen")
        return

    text = job.source_path().read_text(encoding="utf-8-sig")
    parsed = parse_document(text)

    if parsed["title"] and "title" not in job.state.get("explicit", {}):
        # `# Titel` aus der Datei gewinnt gegen den Dateinamen-Default,
        # aber nicht gegen ein explizites --title (das setzt Job.create).
        if job.state.get("title") == job.source_path().stem:
            job.state["title"] = parsed["title"]

    job.write_json(ARTIFACT, {
        "title": job.state["title"],
        "chapters": parsed["chapters"],
    })
    total = sum(len(c["paragraphs"]) for c in parsed["chapters"])
    job.mark_step("ingest", input_hash, chapters=len(parsed["chapters"]), paragraphs=total)
    print(f"  ingest: {len(parsed['chapters'])} Kapitel, {total} Absätze")


def parse_document(text: str) -> dict:
    """Zerlegt Markdown/Plaintext in Titel + Kapitel mit Absätzen."""
    title: str | None = None
    chapters: list[dict] = []
    current: dict | None = None
    buffer: list[str] = []

    def flush_paragraph() -> None:
        nonlocal buffer
        if buffer:
            para = _clean_inline(" ".join(buffer).strip())
            if para:
                _ensure_chapter()["paragraphs"].append(para)
            buffer = []

    def _ensure_chapter() -> dict:
        nonlocal current
        if current is None:
            current = {"title": None, "paragraphs": []}
            chapters.append(current)
        return current

    for raw_line in text.splitlines():
        line = raw_line.rstrip()
        h1 = re.match(r"^#\s+(.+)$", line)
        h2 = re.match(r"^##\s+(.+)$", line)
        if h1 and not h2:
            flush_paragraph()
            if title is None:
                title = _clean_inline(h1.group(1).strip())
            continue
        if h2:
            flush_paragraph()
            current = {"title": _clean_inline(h2.group(1).strip()), "paragraphs": []}
            chapters.append(current)
            continue
        if line.strip() == "":
            flush_paragraph()
            continue
        buffer.append(line.strip())
    flush_paragraph()

    chapters = [c for c in chapters if c["paragraphs"]]
    for i, chapter in enumerate(chapters, start=1):
        chapter["id"] = f"ch{i:02d}"
        if not chapter["title"]:
            chapter["title"] = f"Kapitel {i}"

    return {"title": title, "chapters": chapters}


def _clean_inline(text: str) -> str:
    """Entfernt einfache Markdown-Auszeichnung, behält den Wortlaut."""
    text = re.sub(r"`(.+?)`", r"\1", text)  # zuerst: Backticks dürfen die Wortgrenzen-Prüfung der _-Regel nicht verfälschen
    text = re.sub(r"\*\*(.+?)\*\*", r"\1", text)
    text = re.sub(r"__(.+?)__", r"\1", text)
    text = re.sub(r"\*(.+?)\*", r"\1", text)
    text = re.sub(r"(?<![\wäöüÄÖÜß])_(.+?)_(?![\wäöüÄÖÜß])", r"\1", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()
