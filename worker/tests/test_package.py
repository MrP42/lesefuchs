import json
import shutil
import zipfile

import pytest

from lesefuchs_worker.steps import align, encode, ingest, normalize, package, syllables, synthesize
from lesefuchs_worker.steps.package import build_content


def _syl(w, t0, t1):
    return {"w": w, "t0": t0, "t1": t1, "syl": [{"s": w, "t0": t0, "t1": t1}]}


def test_build_content_sentences_and_src():
    syl_doc = {"chapters": [{
        "id": "ch01",
        "paragraphs": [{
            "para_index": 0,
            "offset_ms": 0,
            "words": [_syl("Der", 0, 100), _syl("Fuchs", 100, 300), _syl("rennt.", 300, 600),
                      _syl("Er", 700, 800), _syl("hat", 800, 900), _syl("zwölf", 900, 1200),
                      _syl("Beeren!", 1200, 1500)],
        }],
    }]}
    srcmaps = {("ch01", 0): {5: "12"}}
    content = build_content(syl_doc, srcmaps)

    assert len(content["tokens"]) == 7
    assert len(content["sentences"]) == 2
    s0, s1 = content["sentences"]
    assert (s0["tokenStart"], s0["tokenEnd"]) == (0, 2)
    assert (s1["tokenStart"], s1["tokenEnd"]) == (3, 6)
    assert s0["t0"] == 0 and s0["t1"] == 600
    assert content["tokens"][5]["src"] == "12"
    assert "src" not in content["tokens"][0]
    assert all(t["vis"] is None for t in content["tokens"])
    assert content["tokens"][4]["sent"] == 1
    assert content["paragraphs"] == [
        {"i": 0, "page": 1, "sentenceStart": 0, "sentenceEnd": 1}
    ]


def test_paragraph_without_final_punctuation_closes_sentence():
    syl_doc = {"chapters": [{
        "id": "ch01",
        "paragraphs": [{"para_index": 0, "offset_ms": 0,
                        "words": [_syl("Offenes", 0, 100), _syl("Ende", 100, 200)]}],
    }]}
    content = build_content(syl_doc, {})
    assert len(content["sentences"]) == 1
    assert content["sentences"][0]["tokenEnd"] == 1


@pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg fehlt")
def test_full_pipeline_to_package(job, monkeypatch):
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
            f.writeframes(b"\x00\x00" * 22050)  # 0,5 s
        return buf.getvalue()

    monkeypatch.setattr(synthesize, "fish_available", lambda url: True)
    monkeypatch.setattr(synthesize, "synthesize_paragraph", fake_synth)
    synthesize.run(job)

    align.run(job, aligner=lambda wav, text: [
        {"word": word, "start": i * 0.05, "end": i * 0.05 + 0.04}
        for i, word in enumerate(text.split())
    ])
    syllables.run(job)
    encode.run(job)
    package.run(job)

    pakete = list(job.settings.out_dir.glob("*.lesepaket"))
    assert len(pakete) == 1
    with zipfile.ZipFile(pakete[0]) as zf:
        names = set(zf.namelist())
        assert {"manifest.json", "content.json", "audio/ch01.opus"} <= names
        manifest = json.loads(zf.read("manifest.json"))
        content = json.loads(zf.read("content.json"))

    assert manifest["schema"] == "lesefuchs/1.0"
    assert manifest["type"] == "REFLOW"
    assert manifest["title"] == "Testbuch"
    ch = manifest["chapters"][0]
    assert ch["audio"] == "audio/ch01.opus"
    assert ch["tokenStart"] == 0
    assert ch["tokenEnd"] == len(content["tokens"]) - 1
    assert manifest["durationMs"] > 0
    # Prüfsumme stimmt mit content.json überein
    import hashlib
    payload = json.dumps(content, ensure_ascii=False).encode()
    assert manifest["checksums"]["content.json"] == "sha256:" + hashlib.sha256(payload).hexdigest()
    # jedes Token vollständig (§4.4)
    for t in content["tokens"]:
        assert {"i", "w", "t0", "t1", "sent", "para", "syl", "vis"} <= set(t)
        assert t["syl"][0]["t0"] == t["t0"]
        assert t["syl"][-1]["t1"] == t["t1"]
