import wave

from lesefuchs_worker.steps import ingest, normalize, synthesize, align
from lesefuchs_worker.steps.align import concat_wavs, merge_word_timings
from tests.conftest import write_silence_wav


def test_concat_offsets_and_duration(tmp_path):
    a = write_silence_wav(tmp_path / "a.wav", ms=1000)
    b = write_silence_wav(tmp_path / "b.wav", ms=500)
    target = tmp_path / "out" / "ch.wav"
    offsets = concat_wavs([a, b], target, pause_ms=350)
    assert offsets == [0, 1350]
    with wave.open(str(target), "rb") as w:
        total_ms = round(w.getnframes() / w.getframerate() * 1000)
    assert abs(total_ms - (1000 + 350 + 500 + 350)) <= 2


def test_merge_full_alignment():
    words = merge_word_timings(
        ["Der", "Fuchs"],
        [{"word": "Der", "start": 0.10, "end": 0.30},
         {"word": "Fuchs", "start": 0.35, "end": 0.80}],
        1000,
    )
    assert words == [
        {"w": "Der", "t0": 100, "t1": 300},
        {"w": "Fuchs", "t0": 350, "t1": 800},
    ]


def test_merge_interpolates_missing_middle():
    words = merge_word_timings(
        ["eins", "12", "drei"],
        [{"word": "eins", "start": 0.0, "end": 0.2},
         {"word": "12", "start": None, "end": None},
         {"word": "drei", "start": 0.6, "end": 0.9}],
        1000,
    )
    assert words[1] == {"w": "12", "t0": 200, "t1": 600}  # füllt die Lücke exakt


def test_merge_without_any_anchor_distributes_by_length():
    words = merge_word_timings(["ab", "abcdef"], [], 800)
    assert words[0]["t0"] == 0
    assert words[-1]["t1"] == 800
    # "abcdef" (6 Zeichen) bekommt das Dreifache von "ab" (2 Zeichen)
    assert (words[1]["t1"] - words[1]["t0"]) == 3 * (words[0]["t1"] - words[0]["t0"])


def test_merge_tail_gap_uses_paragraph_end():
    words = merge_word_timings(
        ["eins", "zwei"],
        [{"word": "eins", "start": 0.0, "end": 0.4}],
        1000,
    )
    assert words[1] == {"w": "zwei", "t0": 400, "t1": 1000}


def test_run_produces_chapter_audio_and_offsets(job, monkeypatch):
    ingest.run(job)
    normalize.run(job)

    import io

    def fake_synth(settings, text, seed):
        buf = io.BytesIO()
        with wave.open(buf, "wb") as f:
            f.setnchannels(1)
            f.setsampwidth(2)
            f.setframerate(44100)
            f.writeframes(b"\x00\x00" * 44100)  # 1 s
        return buf.getvalue()

    monkeypatch.setattr(synthesize, "fish_available", lambda url: True)
    monkeypatch.setattr(synthesize, "synthesize_paragraph", fake_synth)
    synthesize.run(job)

    def fake_aligner(wav_path, text):
        n = len(text.split())
        return [{"word": w, "start": i * 0.1, "end": i * 0.1 + 0.08}
                for i, w in enumerate(text.split())]

    align.run(job, aligner=fake_aligner)
    doc = job.read_json("06_alignment.json")
    assert len(doc["chapters"]) == 1
    ch = doc["chapters"][0]
    assert job.path(ch["wav"]).is_file()
    p0, p1 = ch["paragraphs"]
    assert p0["offset_ms"] == 0
    assert p1["offset_ms"] == 1000 + 350
    # Offsets in den Wortzeiten des zweiten Absatzes enthalten
    assert p1["words"][0]["t0"] == 1350
