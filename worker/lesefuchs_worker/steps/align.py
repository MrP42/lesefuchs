"""Schritt 6: Forced Alignment (06_alignment.json + 06_chapter_audio/).

Zwei Teilaufgaben:
  1. Absatz-WAVs je Kapitel mit Pausen zu EINEM Kapitel-WAV konkatenieren;
     der Absatz-Offset in der Kapitel-Zeitachse wird festgehalten.
  2. WhisperX-Alignment JE ABSATZ (robust + resume-freundlich); die
     Wort-Timestamps werden um den Absatz-Offset verschoben.

Wörter, denen WhisperX keine Zeiten gibt (Zahlen, Einzelzeichen), werden
linear zwischen den Nachbarn interpoliert — der Player braucht für JEDES
Wort t0/t1 (Konzept §4.4).
"""
from __future__ import annotations

import wave
from pathlib import Path

from ..gpu import pipeline_gpu
from ..job import Job

ARTIFACT = "06_alignment.json"
AUDIO_DIR = "06_chapter_audio"


def run(job: Job, force: bool = False, aligner=None) -> None:
    input_hash = job.hash_of("04_synthesis.json")
    if not force and job.step_done("align", input_hash):
        print("  align: unverändert, übersprungen")
        return

    # WhisperX belegt (bei align_device=cuda) die GPU — gleiches Lock.
    with pipeline_gpu(job.settings, holder="align", release_llm=True):
        _align_all(job, aligner, input_hash)


def _align_all(job: Job, aligner, input_hash: str) -> None:
    settings = job.settings
    aligner = aligner or make_aligner(settings)
    synthesis = job.read_json("04_synthesis.json")["entries"]
    pause_ms = settings.pause_between_paragraphs_ms

    chapters: dict[str, list[dict]] = {}
    for entry in synthesis:
        chapters.setdefault(entry["chapter"], []).append(entry)

    out_chapters = []
    for chapter_id, entries in chapters.items():
        entries.sort(key=lambda e: e["para_index"])
        chapter_wav = job.path(f"{AUDIO_DIR}/{chapter_id}.wav")
        offsets = concat_wavs(
            [job.path(e["wav"]) for e in entries], chapter_wav, pause_ms
        )

        paragraphs = []
        for entry, offset_ms in zip(entries, offsets):
            expected = entry["text"].split()
            aligned = aligner(job.path(entry["wav"]), entry["text"])
            words = merge_word_timings(expected, aligned, entry["duration_ms"])
            for w in words:
                w["t0"] += offset_ms
                w["t1"] += offset_ms
            paragraphs.append({
                "para_index": entry["para_index"],
                "offset_ms": offset_ms,
                "words": words,
            })
            print(f"  align: {entry['key']} ({len(words)} Wörter)")

        out_chapters.append({
            "id": chapter_id,
            "wav": f"{AUDIO_DIR}/{chapter_id}.wav",
            "duration_ms": wav_duration_ms(chapter_wav),
            "paragraphs": paragraphs,
        })

    job.write_json(ARTIFACT, {"chapters": out_chapters})
    job.mark_step("align", input_hash, chapters=len(out_chapters))
    print(f"  align: {len(out_chapters)} Kapitel ausgerichtet")


# ---- testbare Bausteine --------------------------------------------------

def concat_wavs(paths: list[Path], target: Path, pause_ms: int) -> list[int]:
    """Konkateniert Mono-WAVs mit Pausen. Liefert Offset (ms) je Eingabedatei."""
    target.parent.mkdir(parents=True, exist_ok=True)
    offsets: list[int] = []
    out: wave.Wave_write | None = None
    rate = 1
    width = 2
    try:
        position_frames = 0
        for path in paths:
            with wave.open(str(path), "rb") as src:
                if out is None:
                    rate = src.getframerate()
                    width = src.getsampwidth()
                    out = wave.open(str(target), "wb")
                    out.setnchannels(1)
                    out.setsampwidth(width)
                    out.setframerate(rate)
                elif src.getframerate() != rate or src.getsampwidth() != width:
                    raise RuntimeError(f"WAV-Format weicht ab: {path}")
                offsets.append(round(position_frames / rate * 1000))
                frames = src.readframes(src.getnframes())
                out.writeframes(frames)
                position_frames += src.getnframes()
            pause_frames = int(rate * pause_ms / 1000)
            out.writeframes(b"\x00" * (pause_frames * width))
            position_frames += pause_frames
    finally:
        if out is not None:
            out.close()
    return offsets


def merge_word_timings(expected_words: list[str], aligned: list[dict],
                       para_duration_ms: int) -> list[dict]:
    """Erwartete Wörter + (lückenhafte) Alignment-Zeiten → lückenlose t0/t1 in ms.

    aligned: [{"word": str, "start": s|None, "end": s|None}] in Sekunden,
    in Textreihenfolge (WhisperX liefert genau die übergebenen Wörter).
    Fehlende Zeiten werden linear zwischen bekannten Ankern interpoliert.
    """
    n = len(expected_words)
    t0 = [None] * n
    t1 = [None] * n
    for i in range(min(n, len(aligned))):
        a = aligned[i]
        if a.get("start") is not None and a.get("end") is not None:
            t0[i] = round(a["start"] * 1000)
            t1[i] = round(a["end"] * 1000)

    # Anker: bekannte Indizes; dazwischen linear nach Zeichenlänge verteilen
    anchors = [i for i in range(n) if t0[i] is not None]
    if not anchors:
        return _distribute_evenly(expected_words, 0, para_duration_ms)

    words: list[dict] = [None] * n  # type: ignore[list-item]
    for i in anchors:
        words[i] = {"w": expected_words[i], "t0": t0[i], "t1": t1[i]}

    # Lücken füllen (vor erstem Anker, zwischen Ankern, nach letztem Anker)
    segments: list[tuple[int, int, int, int]] = []  # (von, bis_exkl, start_ms, end_ms)
    if anchors[0] > 0:
        segments.append((0, anchors[0], 0, t0[anchors[0]]))
    for a, b in zip(anchors, anchors[1:]):
        if b - a > 1:
            segments.append((a + 1, b, t1[a], t0[b]))
    last = anchors[-1]
    if last < n - 1:
        segments.append((last + 1, n, t1[last], para_duration_ms))

    for von, bis, start_ms, end_ms in segments:
        filled = _distribute_evenly(expected_words[von:bis], start_ms, end_ms)
        for offset, w in enumerate(filled):
            words[von + offset] = w
    return words


def _distribute_evenly(word_list: list[str], start_ms: int, end_ms: int) -> list[dict]:
    """Verteilt eine Zeitspanne proportional zur Zeichenlänge auf Wörter."""
    total_chars = sum(max(len(w), 1) for w in word_list) or 1
    span = max(end_ms - start_ms, 0)
    out = []
    cursor = float(start_ms)
    for w in word_list:
        width = span * max(len(w), 1) / total_chars
        out.append({"w": w, "t0": round(cursor), "t1": round(cursor + width)})
        cursor += width
    return out


def wav_duration_ms(path: Path) -> int:
    with wave.open(str(path), "rb") as w:
        return round(w.getnframes() / w.getframerate() * 1000)


def make_aligner(settings):
    """Lazy WhisperX-Alignment (wav2vec2 de). aligner(wav, text) -> Wortliste."""
    try:
        import whisperx
    except ImportError as e:
        raise RuntimeError(
            "whisperx fehlt — installieren mit: pip install -e .[align]"
        ) from e

    device = settings.align_device
    if device == "cuda":
        import torch
        if not torch.cuda.is_available():
            print("  align: CUDA nicht verfügbar (CPU-Torch installiert?) — Fallback auf CPU")
            device = "cpu"
    model, metadata = whisperx.load_align_model(language_code="de", device=device)

    def align(wav_path: Path, text: str) -> list[dict]:
        audio = whisperx.load_audio(str(wav_path))
        duration = len(audio) / 16000
        result = whisperx.align(
            [{"text": text, "start": 0.0, "end": duration}],
            model, metadata, audio, device,
        )
        return [
            {"word": w.get("word", ""), "start": w.get("start"), "end": w.get("end")}
            for w in result["word_segments"]
        ]

    return align
