from lesefuchs_worker.steps import ingest, normalize, synthesize, verify
from lesefuchs_worker.steps.verify import (
    count_syllables,
    detect_end_repetition,
    detect_truncation,
    normalize_tokens,
    word_error_rate,
)


def test_normalize_tokens():
    assert normalize_tokens("Der Fuchs, sagte: „Hallo!“") == ["der", "fuchs", "sagte", "hallo"]


def test_normalize_tokens_expands_digits():
    # Whisper schreibt „einhundert" als „100" — beides muss gleich normalisieren
    assert normalize_tokens("in 100 Jahren") == ["in", "einhundert", "jahren"]
    assert normalize_tokens("in einhundert Jahren") == ["in", "einhundert", "jahren"]


def test_fused_compounds_do_not_count_as_errors():
    assert word_error_rate("sagte der Dachs leise", "sagte der Dachsleise") == 0.0
    assert word_error_rate("Genau so wie heute", "genauso wie heute") == 0.0
    # echte Abweichung bleibt ein Fehler
    assert word_error_rate("sagte der Dachs leise", "sagte der Fuchs leise") == 0.25


def test_wer_identical_is_zero():
    assert word_error_rate("Der kleine Fuchs.", "der kleine Fuchs") == 0.0


def test_wer_counts_substitutions_and_insertions():
    assert word_error_rate("eins zwei drei vier", "eins zwo drei vier") == 0.25
    assert word_error_rate("eins zwei", "eins zwei drei") == 0.5
    assert word_error_rate("", "") == 0.0


def test_count_syllables_vowel_groups():
    assert count_syllables("Kokosnuss") == 3
    assert count_syllables("Der kleine Drache") == 1 + 2 + 2
    assert count_syllables("Baum") == 1          # Vokalgruppe "au"


def test_end_repetition_detected():
    base = ["der", "fuchs", "lief", "durch", "den", "wald"]
    assert detect_end_repetition(base + ["durch", "den", "wald"]) is True
    assert detect_end_repetition(base) is False
    # zweimal dasselbe WORT ist normal (n=1 < 3)
    assert detect_end_repetition(["sehr", "sehr", "gut", "gut"]) is False


def test_truncation_last_word_mismatch():
    assert detect_truncation("Der Fuchs rennt schnell.", "Der Fuchs rennt",
                             5000, 180, 0.6) == "last_word_mismatch"
    assert detect_truncation("Der Fuchs rennt.", "der fuchs rennt",
                             5000, 180, 0.6) is None


def test_truncation_duration():
    # 12 Silben × 180 ms = 2160 ms Erwartung; 1000 ms < 60 % davon
    text = "Der kleine Fuchs lief immer weiter durch den dunklen Wald."
    reason = detect_truncation(text, text.lower().rstrip("."), 1000, 180, 0.6)
    assert reason is not None and reason.startswith("duration_")
    assert detect_truncation(text, text.lower().rstrip("."), 2000, 180, 0.6) is None


def _prepare(job, monkeypatch):
    ingest.run(job)
    normalize.run(job)
    import io
    import wave as w

    def fake_synth(settings, text, seed):
        buf = io.BytesIO()
        with w.open(buf, "wb") as f:
            f.setnchannels(1)
            f.setsampwidth(2)
            f.setframerate(44100)
            # ~1 s (über der Trunkierungs-Erwartung); Seed variiert die Länge,
            # damit Neu-Synthese erkennbar ist
            f.writeframes(b"\x00\x00" * (44100 + seed))
        return buf.getvalue()

    monkeypatch.setattr(synthesize, "fish_available", lambda url: True)
    monkeypatch.setattr(synthesize, "synthesize_paragraph", fake_synth)
    synthesize.run(job)
    return fake_synth


def test_verify_ok_without_resynthesis(job, monkeypatch):
    _prepare(job, monkeypatch)
    entries = job.read_json("04_synthesis.json")["entries"]
    perfect = {e["key"]: e["text"] for e in entries}
    by_path = {job.path(e["wav"]): e["key"] for e in entries}

    verify.run(job, transcriber=lambda p: perfect[by_path[p]])
    results = job.read_json("05_verify.json")["results"]
    assert all(r["ok"] for r in results)
    assert all(len(r["attempts"]) == 1 for r in results)


def test_verify_resynthesizes_bad_paragraph(job, monkeypatch):
    _prepare(job, monkeypatch)
    entries = job.read_json("04_synthesis.json")["entries"]
    bad_key = entries[0]["key"]
    good = {e["key"]: e["text"] for e in entries}
    calls = {"n": 0}

    def transcriber(path):
        # Erster Versuch des ersten Absatzes ist Murks, danach perfekt
        for e in job.read_json("04_synthesis.json")["entries"]:
            if str(path).startswith(str(job.path(e["wav"]).with_suffix(""))):
                if e["key"] == bad_key and calls["n"] == 0:
                    calls["n"] += 1
                    return "völlig falscher Text hier"
                return good[e["key"]]
        raise AssertionError(f"unbekannter Pfad {path}")

    old_seed = entries[0]["seed"]
    verify.run(job, transcriber=transcriber)

    results = {r["key"]: r for r in job.read_json("05_verify.json")["results"]}
    assert results[bad_key]["ok"]
    assert len(results[bad_key]["attempts"]) == 2

    new_entries = {e["key"]: e for e in job.read_json("04_synthesis.json")["entries"]}
    assert new_entries[bad_key]["seed"] != old_seed  # neu vertont mit anderem Seed

    # Resume: ändert sich ein ANDERER Absatz, wird nur dieser neu synthetisiert —
    # die geprüfte Fassung (anderer Seed) bleibt unangetastet.
    synth_calls = []

    def counting_synth(settings, text, seed):
        synth_calls.append(text)
        import io
        import wave as w
        buf = io.BytesIO()
        with w.open(buf, "wb") as f:
            f.setnchannels(1)
            f.setsampwidth(2)
            f.setframerate(44100)
            f.writeframes(b"\x00\x00" * 4410)
        return buf.getvalue()

    monkeypatch.setattr(synthesize, "synthesize_paragraph", counting_synth)
    doc = job.read_json("03_normalized.json")
    doc["chapters"][0]["paragraphs"][1]["text"] = "Frischer zweiter Absatz."
    job.write_json("03_normalized.json", doc)
    synthesize.run(job)
    assert synth_calls == ["Frischer zweiter Absatz."]
    kept = {e["key"]: e for e in job.read_json("04_synthesis.json")["entries"]}
    assert kept[bad_key]["seed"] == new_entries[bad_key]["seed"]
