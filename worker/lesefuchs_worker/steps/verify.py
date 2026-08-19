"""Schritt 5: Rücktranskriptions-Check (05_verify.json).

Jedes Absatz-WAV wird mit faster-whisper transkribiert und gegen den
Soll-Text abgeglichen (WER auf normalisierten Tokens). Liegt die WER über
der Schwelle, wird mit variiertem Seed neu synthetisiert (max N Versuche);
behalten wird der Versuch mit der niedrigsten WER. 04_synthesis.json wird
dabei aktualisiert (WAV, Seed, Hash), damit das Alignment auf der final
geprüften Aufnahme läuft und Resume die geprüfte Fassung behält.
"""
from __future__ import annotations

import re

from ..job import Job
from . import synthesize as synth_step

ARTIFACT = "05_verify.json"


def run(job: Job, force: bool = False, transcriber=None) -> None:
    input_hash = job.hash_of("04_synthesis.json")
    if not force and job.step_done("verify", input_hash):
        print("  verify: unverändert, übersprungen")
        return

    settings = job.settings
    transcriber = transcriber or make_transcriber(settings)

    synthesis = job.read_json("04_synthesis.json")
    results = []
    resynthesized = 0

    for entry in synthesis["entries"]:
        target = entry["text"]
        wav_path = job.path(entry["wav"])
        transcript = transcriber(wav_path)
        wer = word_error_rate(target, transcript)
        attempts = [{"seed": entry["seed"], "wer": wer, "transcript": transcript}]

        best = {"wer": wer, "audio": None, "seed": entry["seed"], "transcript": transcript}
        attempt = 1
        while best["wer"] > settings.verify_wer_threshold and attempt < settings.verify_max_attempts:
            attempt += 1
            new_seed = settings.fish_seed + attempt * 1000 + entry["para_index"]
            print(f"  verify: {entry['key']} WER {best['wer']:.2f} > "
                  f"{settings.verify_wer_threshold} — Re-Synthese (Seed {new_seed})")
            audio = synth_step.synthesize_paragraph(settings, target, new_seed)
            tmp = wav_path.with_suffix(f".try{attempt}.wav")
            tmp.write_bytes(audio)
            transcript = transcriber(tmp)
            wer = word_error_rate(target, transcript)
            attempts.append({"seed": new_seed, "wer": wer, "transcript": transcript})
            if wer < best["wer"]:
                best = {"wer": wer, "audio": audio, "seed": new_seed, "transcript": transcript}

        if best["audio"] is not None:
            wav_path.write_bytes(best["audio"])
            entry["seed"] = best["seed"]
            entry["hash"] = synth_step.paragraph_hash(target, best["seed"], settings.fish_reference_id)
            entry["duration_ms"] = synth_step.wav_duration_ms(wav_path)
            resynthesized += 1
        for tmp in wav_path.parent.glob(wav_path.stem + ".try*.wav"):
            tmp.unlink()

        results.append({
            "key": entry["key"],
            "wer": best["wer"],
            "ok": best["wer"] <= settings.verify_wer_threshold,
            "attempts": attempts,
        })

    job.write_json("04_synthesis.json", synthesis)
    job.write_json(ARTIFACT, {"results": results})
    failed = [r["key"] for r in results if not r["ok"]]
    # Neuen Stand von 04 als Eingabe-Hash verbuchen (verify hat 04 verändert)
    job.mark_step("verify", job.hash_of("04_synthesis.json"),
                  resynthesized=resynthesized, failed=failed)
    if failed:
        print(f"  verify: WARNUNG — über Schwelle geblieben: {', '.join(failed)} (beste Fassung behalten)")
    print(f"  verify: {len(results)} Absätze geprüft, {resynthesized} neu vertont")


# ---- testbare Bausteine --------------------------------------------------

def normalize_tokens(text: str) -> list[str]:
    """Kleinbuchstaben, ohne Interpunktion — Basis des WER-Vergleichs."""
    return re.findall(r"[\wäöüß]+", text.lower())


def word_error_rate(target: str, transcript: str) -> float:
    """Levenshtein-Distanz auf Wortebene / Länge des Soll-Texts."""
    ref = normalize_tokens(target)
    hyp = normalize_tokens(transcript)
    if not ref:
        return 0.0 if not hyp else 1.0
    prev = list(range(len(hyp) + 1))
    for i, r in enumerate(ref, 1):
        curr = [i] + [0] * len(hyp)
        for j, h in enumerate(hyp, 1):
            curr[j] = min(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + (r != h))
        prev = curr
    return prev[-1] / len(ref)


def make_transcriber(settings):
    """Lazy faster-whisper; klare Anleitung, wenn nicht installiert."""
    try:
        from faster_whisper import WhisperModel
    except ImportError as e:
        raise RuntimeError(
            "faster-whisper fehlt — installieren mit: pip install -e .[verify]"
        ) from e

    model = WhisperModel(settings.verify_model, device="auto", compute_type="auto")

    def transcribe(wav_path) -> str:
        segments, _info = model.transcribe(str(wav_path), language="de", beam_size=5)
        return " ".join(seg.text.strip() for seg in segments)

    return transcribe
