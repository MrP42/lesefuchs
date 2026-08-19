# Lesefuchs Render-Worker

Headless-Pipeline: TXT/MD → optimierter Vorlesetext → Fish-Speech-Audio →
geprüfte, wortgenau ausgerichtete `.lesepaket`-Datei (Konzept §4.2–4.4).

## Pipeline

| # | Schritt      | Artefakt                      | extern nötig |
|---|--------------|-------------------------------|--------------|
| 1 | `ingest`     | `01_paragraphs.json`          | — |
| 2 | `optimize`   | `02_optimized.json`           | Ollama (optional, sonst Skip mit Warnung) |
| 3 | `normalize`  | `03_normalized.json`          | — |
| 4 | `synthesize` | `04_synthesis.json` + WAVs    | Fish-Speech (`C:\AI\fish-speech`) |
| 5 | `verify`     | `05_verify.json`              | faster-whisper (`.[verify]`) |
| 6 | `align`      | `06_alignment.json` + Kapitel-WAV | whisperx (`.[align]`) |
| 7 | `syllables`  | `07_syllables.json`           | — |
| 8 | `encode`     | `08_encode.json` + Opus       | ffmpeg im PATH |
| 9 | `package`    | `out/<titel>_v1.lesepaket`    | — |

Alle Zwischenstände liegen in `work/<job-id>/` — ein abgebrochener Lauf wird
mit demselben Befehl fortgesetzt; bereits synthetisierte/verifizierte Absätze
werden nicht neu vertont (auch nicht bei Alignment-Fehlern).

## Nutzung

```bash
make setup          # venv + Kernpakete
make setup-ml       # + faster-whisper & whisperx (zieht torch)
make test           # 43 Unit-Tests, ohne externe Dienste

# Dienste starten: Ollama (Port 11434) und Fish-Speech (Port 8080), dann:
make demo           # erzeugt out/finn-fuchs-und-der-sternenwald_v1.lesepaket

# einzelne Schritte:
.venv/Scripts/python -m lesefuchs_worker jobs
.venv/Scripts/python -m lesefuchs_worker step align --job <job-id> [--force]
.venv/Scripts/python -m lesefuchs_worker run --input x.md --from align
```

Konfiguration: `.env` (Vorlage `.env.example`), Prefix `LF_` —
Fish-URL/Stimme/Seed, Ollama-Modell, WER-Schwelle, Opus-Parameter.

## Bekannte Fallstricke

**GPU-Doppelbelegung (WDDM-Falle).** Am 19.08.2026 lief Fish-Speech in den
600-s-Timeout, weil Ollama parallel im VRAM lag: Windows lagert unter WDDM
aus, statt einen Fehler zu werfen — die Synthese wurde 10–20× langsamer
(gemessen 24,6 s vs. > 570 s für dieselbe Passage). Der Worker schließt das
jetzt strukturell:

- **Ein Lock für alle GPU-Schritte** (`work/.gpu.lock`, siehe `gpu.py`):
  `optimize`, `synthesize`, `verify` und `align` halten es exklusiv. Auch zwei
  gleichzeitig gestartete Jobs serialisieren dadurch. Das Lock hängt am
  Dateideskriptor — stirbt ein Prozess, gibt das Betriebssystem es frei.
- **LLM-Unload vor jedem TTS-/Whisper-Schritt:** Ollama bekommt
  `keep_alive: 0`; anschließend wird über `/api/ps` verifiziert, dass kein
  Modell mehr geladen ist, erst dann startet Fish.
- **Fish-Timeout** meldet nicht mehr nur „read timeout", sondern VRAM-Stand,
  belegende GPU-Prozesse und die Abhilfe.

Fremde GPU-Last (ComfyUI, Spiele, ein zweites Ollama) kennt das Lock nicht —
bei Timeout die Meldung lesen, Prozess beenden, Schritt erneut starten. Alle
bereits vertonten Absätze bleiben erhalten.

## Entscheidungen

- **Opus 24 kHz** statt der im Konzept genannten 22,05 kHz — Opus kennt nur
  8/12/16/24/48 kHz.
- Jahreszahlen 1100–1999 werden als „…hundert…" gelesen; Zahlen mit
  Tausenderpunkt immer als Kardinalzahl.
- `verify` behält bei anhaltender Abweichung die beste Fassung und warnt
  (Paketbau wird nicht blockiert); neu vertonte Absätze überleben Resume.
