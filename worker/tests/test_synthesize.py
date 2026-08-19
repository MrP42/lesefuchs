import pytest

from lesefuchs_worker.steps import ingest, normalize, synthesize
from tests.conftest import write_silence_wav


def test_looks_like_wav():
    assert synthesize.looks_like_wav(b"RIFF" + b"\x00" * 2000)
    assert not synthesize.looks_like_wav(b"RIFF")          # zu klein
    assert not synthesize.looks_like_wav(b"OggS" + b"\x00" * 2000)


def test_paragraph_hash_depends_on_text_seed_voice():
    h1 = synthesize.paragraph_hash("Hallo", 42, "")
    assert h1 == synthesize.paragraph_hash("Hallo", 42, "")
    assert h1 != synthesize.paragraph_hash("Hallo", 99, "")
    assert h1 != synthesize.paragraph_hash("Hallo", 42, "papa")


def test_wav_duration(tmp_path):
    wav = write_silence_wav(tmp_path / "t.wav", ms=750)
    assert abs(synthesize.wav_duration_ms(wav) - 750) <= 1


def test_run_caches_unchanged_paragraphs(job, monkeypatch):
    ingest.run(job)
    normalize.run(job)

    calls = []

    def fake_synth(settings, text, seed):
        calls.append(text)
        import io
        import wave as w
        buf = io.BytesIO()
        with w.open(buf, "wb") as f:
            f.setnchannels(1)
            f.setsampwidth(2)
            f.setframerate(44100)
            f.writeframes(b"\x00\x00" * 4410)
        return buf.getvalue()

    monkeypatch.setattr(synthesize, "fish_available", lambda url: True)
    monkeypatch.setattr(synthesize, "synthesize_paragraph", fake_synth)

    synthesize.run(job)
    assert len(calls) == 2  # zwei Absätze

    # Zweiter Lauf: Schritt-Status greift (kein neuer Aufruf)
    synthesize.run(job)
    assert len(calls) == 2

    # Ein Absatz geändert → nur dieser wird neu synthetisiert
    doc = job.read_json("03_normalized.json")
    doc["chapters"][0]["paragraphs"][1]["text"] = "Ganz neuer Text."
    job.write_json("03_normalized.json", doc)
    synthesize.run(job)
    assert calls[2:] == ["Ganz neuer Text."]

    entries = job.read_json("04_synthesis.json")["entries"]
    assert len(entries) == 2
    assert all(job.path(e["wav"]).is_file() for e in entries)
    assert all(e["duration_ms"] > 0 for e in entries)


def test_run_fails_clearly_without_fish(job, monkeypatch):
    ingest.run(job)
    normalize.run(job)
    monkeypatch.setattr(synthesize, "fish_available", lambda url: False)
    with pytest.raises(RuntimeError, match="nicht erreichbar"):
        synthesize.run(job)
