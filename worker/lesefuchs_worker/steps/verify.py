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
        defects = paragraph_defects(target, transcript, entry["duration_ms"], settings)
        attempts = [{"seed": entry["seed"], "wer": wer, "defects": defects,
                     "transcript": transcript}]

        best = {"wer": wer, "defects": defects, "audio": None, "seed": entry["seed"]}
        attempt = 1
        while ((best["wer"] > settings.verify_wer_threshold or best["defects"])
               and attempt < settings.verify_max_attempts):
            attempt += 1
            new_seed = settings.fish_seed + attempt * 1000 + entry["para_index"]
            reason = best["defects"] or [f"WER {best['wer']:.2f}"]
            print(f"  verify: {entry['key']} auffällig ({', '.join(map(str, reason))}) "
                  f"— Re-Synthese (Seed {new_seed})")
            audio = synth_step.synthesize_paragraph(settings, target, new_seed)
            tmp = wav_path.with_suffix(f".try{attempt}.wav")
            tmp.write_bytes(audio)
            transcript = transcriber(tmp)
            wer = word_error_rate(target, transcript)
            defects = paragraph_defects(target, transcript, synth_step.wav_duration_ms(tmp), settings)
            attempts.append({"seed": new_seed, "wer": wer, "defects": defects,
                             "transcript": transcript})
            # besser = erst weniger Fehlermodi, dann niedrigere WER
            if (len(defects), wer) < (len(best["defects"]), best["wer"]):
                best = {"wer": wer, "defects": defects, "audio": audio, "seed": new_seed}

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
            "defects": best["defects"],
            "ok": best["wer"] <= settings.verify_wer_threshold and not best["defects"],
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

def count_syllables(text: str) -> int:
    """Silbenzählung über Vokalgruppen (Konzept §5.5: ~95 % treffsicher)."""
    return sum(len(re.findall(r"[aeiouyäöü]+", w)) or 1 for w in normalize_tokens(text))


def detect_end_repetition(tokens: list[str], min_n: int = 3) -> bool:
    """True, wenn eine identische Wortfolge (n ≥ min_n) doppelt am Ende steht —
    typischer TTS-Fehlermodus (Schleife am Absatzende)."""
    for n in range(min_n, len(tokens) // 2 + 1):
        if tokens[-n:] == tokens[-2 * n:-n]:
            return True
    return False


def detect_truncation(target: str, transcript: str, duration_ms: int,
                      ms_per_syllable: int, min_ratio: float) -> str | None:
    """Liefert den Trunkierungs-Grund oder None.
    a) Letztes transkribiertes Wort ≠ letztes Soll-Wort.
    b) Audiodauer unter min_ratio der silbenbasierten Erwartung."""
    ref = normalize_tokens(target)
    hyp = normalize_tokens(transcript)
    if ref and (not hyp or hyp[-1] != ref[-1]):
        return "last_word_mismatch"
    expected_ms = count_syllables(target) * ms_per_syllable
    if expected_ms > 0 and duration_ms < expected_ms * min_ratio:
        return f"duration_{duration_ms}ms_lt_{min_ratio:.0%}_of_{expected_ms}ms"
    return None


def paragraph_defects(target: str, transcript: str, duration_ms: int, settings) -> list[str]:
    """WER-unabhängige Fehlermodi eines Absatzes (leer = unauffällig)."""
    defects = []
    if detect_end_repetition(normalize_tokens(transcript)):
        defects.append("end_repetition")
    truncation = detect_truncation(
        target, transcript, duration_ms,
        settings.verify_ms_per_syllable, settings.verify_min_duration_ratio,
    )
    if truncation is not None:
        defects.append(f"truncation:{truncation}")
    return defects


def normalize_tokens(text: str) -> list[str]:
    """Kleinbuchstaben, ohne Interpunktion; Ziffern-Tokens werden als
    Zahlwörter expandiert („100" → „einhundert") — Whisper transkribiert
    gesprochene Zahlen als Ziffern (inverse Normalisierung), das darf nicht
    als Fehler zählen."""
    from .normalize import speak_number

    tokens = []
    for tok in re.findall(r"[\wäöüß]+", text.lower()):
        if tok.isdigit():
            tokens.extend(speak_number(tok).lower().split())
        else:
            tokens.append(tok)
    return tokens


def split_fused_compounds(ref: list[str], hyp: list[str]) -> list[str]:
    """Teilt Transkript-Tokens auf, die exakt zwei aufeinanderfolgende
    Soll-Tokens zusammenschreiben („dachsleise" → „dachs leise",
    „genauso" → „genau so") — Whisper-Schreibvarianten, keine Sprechfehler."""
    pairs = {ref[i] + ref[i + 1]: (ref[i], ref[i + 1]) for i in range(len(ref) - 1)}
    out: list[str] = []
    for tok in hyp:
        if tok in pairs:
            out.extend(pairs[tok])
        else:
            out.append(tok)
    return out


def word_error_rate(target: str, transcript: str) -> float:
    """Levenshtein-Distanz auf Wortebene / Länge des Soll-Texts."""
    ref = normalize_tokens(target)
    hyp = split_fused_compounds(ref, normalize_tokens(transcript))
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
