import wave

import pytest

from lesefuchs_worker.config import Settings
from lesefuchs_worker.job import Job


@pytest.fixture
def settings(tmp_path):
    return Settings(work_dir=tmp_path / "work", out_dir=tmp_path / "out",
                    _env_file=None)


@pytest.fixture
def job(tmp_path, settings):
    src = tmp_path / "buch.md"
    src.write_text("# Testbuch\n\nErster Absatz mit 12 Wörtern.\n\nZweiter Absatz.\n",
                   encoding="utf-8")
    return Job.create(src, settings)


def write_silence_wav(path, ms=500, rate=44100):
    """Erzeugt ein stilles Mono-WAV der gewünschten Länge."""
    path.parent.mkdir(parents=True, exist_ok=True)
    with wave.open(str(path), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(b"\x00\x00" * int(rate * ms / 1000))
    return path
