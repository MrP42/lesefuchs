"""Lock-Semantik der GPU-Serialisierung — ohne echte GPU prüfbar."""
import subprocess
import sys
import textwrap
import time
from pathlib import Path

import pytest

from lesefuchs_worker.gpu import GpuBusyError, gpu_lock, pipeline_gpu


def test_lock_is_reusable_sequentially(tmp_path):
    lock = tmp_path / ".gpu.lock"
    for _ in range(3):
        with gpu_lock(lock, holder="a", timeout_s=1):
            pass  # nach dem Block muss das Lock wieder frei sein


def test_second_process_blocks_until_first_releases(tmp_path):
    """Kernfall: zwei Prozesse (= zwei Jobs) dürfen die GPU nicht teilen."""
    lock = tmp_path / ".gpu.lock"
    marker = tmp_path / "second_started.txt"
    repo = str(Path(__file__).resolve().parents[1])

    holder = subprocess.Popen([
        sys.executable, "-c", textwrap.dedent(f"""
            import sys, time
            sys.path.insert(0, {repo!r})
            from pathlib import Path
            from lesefuchs_worker.gpu import gpu_lock
            with gpu_lock(Path({str(lock)!r}), holder="halter", timeout_s=30):
                print("HELD", flush=True)
                time.sleep(3)
        """),
    ], stdout=subprocess.PIPE, text=True)
    try:
        assert holder.stdout.readline().strip() == "HELD"

        # Zweiter Prozess muss warten, bis der erste fertig ist
        t0 = time.monotonic()
        waiter = subprocess.run([
            sys.executable, "-c", textwrap.dedent(f"""
                import sys
                sys.path.insert(0, {repo!r})
                from pathlib import Path
                from lesefuchs_worker.gpu import gpu_lock
                with gpu_lock(Path({str(lock)!r}), holder="warter", timeout_s=30, poll_s=0.2):
                    Path({str(marker)!r}).write_text("ok")
            """),
        ], capture_output=True, text=True, timeout=60)
        waited = time.monotonic() - t0
    finally:
        holder.wait(timeout=30)

    assert waiter.returncode == 0, waiter.stderr
    assert marker.is_file()
    assert waited >= 2.0, f"Zweiter Prozess lief zu früh los ({waited:.1f}s) — Lock greift nicht"


def test_timeout_raises_gpu_busy(tmp_path):
    lock = tmp_path / ".gpu.lock"
    repo = str(Path(__file__).resolve().parents[1])
    holder = subprocess.Popen([
        sys.executable, "-c", textwrap.dedent(f"""
            import sys, time
            sys.path.insert(0, {repo!r})
            from pathlib import Path
            from lesefuchs_worker.gpu import gpu_lock
            with gpu_lock(Path({str(lock)!r}), holder="dauerlaeufer", timeout_s=30):
                print("HELD", flush=True)
                time.sleep(5)
        """),
    ], stdout=subprocess.PIPE, text=True)
    try:
        assert holder.stdout.readline().strip() == "HELD"
        with pytest.raises(GpuBusyError, match="belegt"):
            with gpu_lock(lock, holder="ungeduldig", timeout_s=1, poll_s=0.2):
                pass
    finally:
        holder.wait(timeout=30)


def test_lock_released_when_holder_crashes(tmp_path):
    """Stirbt ein Prozess mitten im Schritt, darf kein verwaistes Lock bleiben."""
    lock = tmp_path / ".gpu.lock"
    repo = str(Path(__file__).resolve().parents[1])
    crasher = subprocess.Popen([
        sys.executable, "-c", textwrap.dedent(f"""
            import sys, os
            sys.path.insert(0, {repo!r})
            from pathlib import Path
            from lesefuchs_worker.gpu import gpu_lock
            with gpu_lock(Path({str(lock)!r}), holder="absturz", timeout_s=30):
                print("HELD", flush=True)
                os._exit(1)   # harter Abbruch, kein finally
        """),
    ], stdout=subprocess.PIPE, text=True)
    assert crasher.stdout.readline().strip() == "HELD"
    crasher.wait(timeout=30)

    with gpu_lock(lock, holder="nachfolger", timeout_s=5, poll_s=0.2):
        pass  # muss ohne GpuBusyError durchlaufen


def test_pipeline_gpu_unloads_llm_before_tts(tmp_path, monkeypatch, settings):
    """release_llm=True muss den Unload VOR dem Schritt auslösen (WDDM-Falle)."""
    calls = []
    monkeypatch.setattr("lesefuchs_worker.gpu.unload_ollama_model",
                        lambda url, model, **kw: calls.append(("unload", url, model)) or True)

    s = settings.model_copy(update={"work_dir": tmp_path})
    with pipeline_gpu(s, holder="synthesize", release_llm=True):
        calls.append(("step",))
    assert [c[0] for c in calls] == ["unload", "step"]

    calls.clear()
    with pipeline_gpu(s, holder="optimize", release_llm=False):
        calls.append(("step",))
    assert [c[0] for c in calls] == ["step"]


def test_lock_file_lives_in_work_dir(tmp_path, settings):
    s = settings.model_copy(update={"work_dir": tmp_path / "work"})
    with pipeline_gpu(s, holder="align", release_llm=False):
        assert (tmp_path / "work" / ".gpu.lock").is_file()
