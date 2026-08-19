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

## Entscheidungen

- **Opus 24 kHz** statt der im Konzept genannten 22,05 kHz — Opus kennt nur
  8/12/16/24/48 kHz.
- Jahreszahlen 1100–1999 werden als „…hundert…" gelesen; Zahlen mit
  Tausenderpunkt immer als Kardinalzahl.
- `verify` behält bei anhaltender Abweichung die beste Fassung und warnt
  (Paketbau wird nicht blockiert); neu vertonte Absätze überleben Resume.
