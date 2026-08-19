"""GPU-Serialisierung für die Pipeline.

Hintergrund (Vorfall 19.08.2026): Ollama und Fish-Speech lagen gleichzeitig
auf der RTX 4090. Windows lagert unter WDDM VRAM aus, statt einen Fehler zu
werfen — Fish wurde 10–20× langsamer und lief in den 600-s-Timeout. In einer
unbeaufsichtigten Pipeline (Etappe 2) darf das nicht vom Zufall abhängen.

Zwei Mechanismen:
  1. `gpu_lock()` — prozessübergreifendes Datei-Lock (work/.gpu.lock). Alle
     GPU-Schritte (optimize/synthesize/verify/align) halten es exklusiv; auch
     zwei parallel gestartete Jobs serialisieren dadurch. Das Lock hängt am
     Dateideskriptor: stirbt der Prozess, gibt das Betriebssystem es frei
     (keine verwaisten Locks).
  2. `unload_ollama_model()` — entlädt das LLM aktiv aus dem VRAM und wartet,
     bis Ollama es nicht mehr als geladen meldet; erst danach startet TTS.
"""
from __future__ import annotations

import contextlib
import os
import subprocess
import time
from pathlib import Path

import requests

if os.name == "nt":
    import msvcrt
else:
    import fcntl


class GpuBusyError(RuntimeError):
    """GPU blieb über die Wartezeit hinaus von einem anderen Schritt belegt."""


@contextlib.contextmanager
def gpu_lock(lock_path: Path, holder: str = "", timeout_s: float = 1800.0,
             poll_s: float = 2.0):
    """Exklusives, prozessübergreifendes Lock auf lock_path.

    holder landet in einer Info-Datei daneben (nur Diagnose, nicht Semantik).
    Wirft GpuBusyError, wenn das Lock nicht innerhalb timeout_s frei wird.
    """
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    info_path = lock_path.with_suffix(".lock.info")
    fd = os.open(str(lock_path), os.O_RDWR | os.O_CREAT)
    deadline = time.monotonic() + timeout_s
    waited_since: float | None = None
    try:
        while True:
            try:
                _acquire(fd)
                break
            except OSError:
                if waited_since is None:
                    waited_since = time.monotonic()
                    other = _read_info(info_path)
                    print(f"  GPU belegt{f' von {other}' if other else ''} — warte …")
                if time.monotonic() > deadline:
                    raise GpuBusyError(
                        f"GPU seit {timeout_s:.0f}s belegt"
                        f"{f' von {_read_info(info_path)}' if _read_info(info_path) else ''}. "
                        f"Läuft ein zweiter Job? Lock: {lock_path}"
                    )
                time.sleep(poll_s)

        if waited_since is not None:
            print(f"  GPU frei nach {time.monotonic() - waited_since:.0f}s")
        info_path.write_text(f"pid={os.getpid()} holder={holder} seit={time.strftime('%H:%M:%S')}",
                             encoding="utf-8")
        try:
            yield
        finally:
            with contextlib.suppress(OSError):
                info_path.unlink()
            _release(fd)
    finally:
        os.close(fd)


def _acquire(fd: int) -> None:
    """Nicht-blockierender Lock-Versuch; OSError, wenn belegt."""
    if os.name == "nt":
        msvcrt.locking(fd, msvcrt.LK_NBLCK, 1)
    else:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)


def _release(fd: int) -> None:
    with contextlib.suppress(OSError):
        if os.name == "nt":
            os.lseek(fd, 0, os.SEEK_SET)
            msvcrt.locking(fd, msvcrt.LK_UNLCK, 1)
        else:
            fcntl.flock(fd, fcntl.LOCK_UN)


def _read_info(info_path: Path) -> str:
    try:
        return info_path.read_text(encoding="utf-8").strip()
    except OSError:
        return ""


# ---- Ollama-Speicherfreigabe ---------------------------------------------

def loaded_ollama_models(url: str, timeout_s: float = 5.0) -> list[str]:
    """Aktuell im Speicher gehaltene Modelle (leer, wenn Ollama nicht läuft).

    Kurzer Connect-Timeout: auf Windows braucht ein abgelehnter Loopback-
    Verbindungsversuch sonst ~2 s (IPv6-Fallback) — bei jedem Schritt.
    """
    try:
        resp = requests.get(f"{url}/api/ps", timeout=(0.5, timeout_s))
        resp.raise_for_status()
        return [m.get("name", "") for m in resp.json().get("models", [])]
    except (requests.RequestException, ValueError):
        return []


def unload_ollama_model(url: str, model: str, wait_s: float = 30.0) -> bool:
    """Entlädt das Modell aus dem VRAM (keep_alive=0) und verifiziert es.

    Rückgabe: True, wenn danach kein Modell mehr geladen ist (oder Ollama
    gar nicht läuft). False, wenn Ollama es weiterhin als geladen meldet —
    der Aufrufer entscheidet, ob er trotzdem fortfährt.
    """
    if not loaded_ollama_models(url):
        return True
    print(f"  GPU: entlade Ollama-Modell {model} …")
    with contextlib.suppress(requests.RequestException):
        requests.post(
            f"{url}/api/generate",
            json={"model": model, "prompt": "", "keep_alive": 0, "stream": False},
            timeout=30,
        )
    deadline = time.monotonic() + wait_s
    while time.monotonic() < deadline:
        remaining = loaded_ollama_models(url)
        if not remaining:
            print("  GPU: Ollama-Speicher freigegeben")
            return True
        time.sleep(1.0)
    print(f"  GPU: WARNUNG — Ollama meldet weiterhin geladen: {loaded_ollama_models(url)}")
    return False


# ---- Pipeline-Fassade -----------------------------------------------------

@contextlib.contextmanager
def pipeline_gpu(settings, holder: str, release_llm: bool = False):
    """Lock für einen GPU-Schritt. release_llm=True entlädt vorher das
    Ollama-Modell — verpflichtend für alle Schritte, die Fish-Speech oder
    Whisper benutzen (siehe Modul-Docstring)."""
    with gpu_lock(Path(settings.work_dir) / ".gpu.lock", holder=holder,
                  timeout_s=settings.gpu_lock_timeout_s):
        if release_llm:
            unload_ollama_model(settings.ollama_url, settings.ollama_model)
        yield


# ---- Diagnose -------------------------------------------------------------

def vram_status() -> str:
    """VRAM-Belegung für Fehlermeldungen ('n/a', wenn nvidia-smi fehlt)."""
    try:
        out = subprocess.run(
            ["nvidia-smi", "--query-gpu=memory.used,memory.total", "--format=csv,noheader"],
            capture_output=True, text=True, timeout=10,
        )
        if out.returncode == 0 and out.stdout.strip():
            return out.stdout.strip().splitlines()[0].strip()
    except (OSError, subprocess.SubprocessError):
        pass
    return "n/a"


def gpu_processes() -> str:
    """Prozesse mit GPU-Speicher (Diagnose bei Timeout)."""
    try:
        out = subprocess.run(
            ["nvidia-smi", "--query-compute-apps=pid,process_name,used_memory",
             "--format=csv,noheader"],
            capture_output=True, text=True, timeout=10,
        )
        if out.returncode == 0 and out.stdout.strip():
            return "; ".join(line.strip() for line in out.stdout.strip().splitlines())
    except (OSError, subprocess.SubprocessError):
        pass
    return "n/a"
