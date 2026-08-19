# Lesefuchs Worker — Kurzbefehle (GNU make; unter Windows via Git Bash)
PY := .venv/Scripts/python

.PHONY: setup setup-ml test demo clean

setup:            ## venv + Kern-Abhängigkeiten
	py -3.12 -m venv .venv
	$(PY) -m pip install -e .[dev]

setup-ml:         ## zusätzlich verify (faster-whisper) + align (whisperx, zieht torch)
	$(PY) -m pip install -e .[verify,align]

test:
	$(PY) -m pytest -q

demo:             ## 5-Minuten-Beispielpaket bauen (braucht Ollama + Fish-Speech + setup-ml)
	$(PY) -m lesefuchs_worker run --input examples/beispiel.md

clean:
	rm -rf work out
