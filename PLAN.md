# Lesefuchs — Etappe 1: Render-Worker + Android-Spike

Auftrag: technischen Kern beweisen. Kein Backend (Etappe 2), kein Faksimile.
Konzept-Referenz: `Lesefuchs_Konzept.md` §4 (Paketformat), §5.3 (Sync-Engine),
§6.3a (Prompt), §13 (Spike-Ziele).

## Artefakte

- **A `worker/`** — Python-CLI: TXT/MD → Ollama-Optimierung → Normalisierung
  (num2words de, Abkürzungen) → Fish-Speech (WAV je Absatz) →
  Rücktranskriptions-Check (Whisper) → WhisperX-Alignment → pyphen-Silben →
  Opus 22.05 kHz mono 24 kbps → `.lesepaket` nach `./out/`.
- **B/C `android/`** — Spike-APK (Kotlin + Compose, minSdk 28, targetSdk 34,
  nur arm64-v8a, GMS-frei): Player-Screen (Andika, Media3, Satz-+Wort-Highlight
  via `withFrameNanos`, Lead −60 ms, Wort-Tap = Seek) + Spike-Activity
  (ML Kit bundled OCR, sherpa-onnx+Piper, `startLockTask()`).

## Schrittliste (je Schritt ≤ 15 min, je Schritt ein Commit)

### Worker
1. ✅ Aufräumen (alter `server/`-Stand entfernen) + PLAN.md
2. ✅ Projektgerüst `worker/` (cf33d4d)
3. ✅ `ingest` + Tests (e29c778)
4. ✅ `normalize` + Tests (895118e)
5. ✅ `optimize` (Ollama, Absatz-Erhalt, Offline-Fallback) + Tests (e6369e8)
6. ✅ `synthesize` (Fish-Speech, Cache) + Tests (4f494f0)
7. ✅ `verify` (faster-whisper, WER, Re-Synthese) + Tests (0263b1f)
8. ✅ `align` (Kapitel-Konkatenation, WhisperX, Interpolation) + Tests (5e57916)
9. ✅ `syllables` (pyphen, Vokalgewichtung) + Tests (998cf62)
10. ✅ `encode` + `package` (§4.2–4.4) + E2E-Test (ed0e81a)
11. ✅ Beispiel, Makefile, README (a356498); Demo-Lauf gegen Ollama
    (gemma4:e4b) + Fish-Speech gestartet

### Android
12. ✅ Gradle-Projekt + Andika (325de4c)
13. ✅ `LesepaketLoader` + Datenklassen §4.4 (fd9a4cd)
14. ✅ `HighlightEngine` §5.3 (fd9a4cd)
15. ✅ Player-Screen (fd9a4cd)
16. ✅ SpikeActivity (214ad9e)
17. ✅ README Build/Sideload; offene Punkte unten

## Entscheidungen (nach Konzept-Empfehlung, nicht blockierend)

- **Audio-Layout:** Ein Kapitel = eine Opus-Datei. Absatz-WAVs werden mit
  350 ms Pause konkateniert; Alignment läuft je Absatz, Offsets werden addiert
  (robuster + resume-fähig gegenüber Ganz-Kapitel-Alignment).
- **Verify-Metrik:** WER auf normalisierten Tokens (lowercase, ohne
  Interpunktion), Schwelle default 0.15, max 3 Synthese-Versuche (Seed-Variation).
- **Whisper-Modelle:** Rücktranskription `faster-whisper` (small, de);
  Alignment WhisperX wav2vec2-de. Beides lazy-geladen, nur bei Bedarf.
- **CLI:** argparse (kein Typer/Click — „keine zusätzlichen Frameworks").
  Tests mit pytest (Testwerkzeug, kein Framework im Sinne des Verbots).
- **Fish-Speech-Aufruf:** wie in `local-voice-project` verifiziert:
  `POST /v1/tts` `{text, format:"wav", seed, reference_id?, use_memory_cache:"on"}`,
  Antwortvalidierung über RIFF-Magic. Stimme über `.env` (`FISH_REFERENCE_ID`).
- **OCR-Foto im Spike:** `TakePicturePreview` (Systemkamera, Bitmap) statt
  CameraX — minimaler Beweis „Foto → Text", eine Abhängigkeit weniger.
  CameraX kommt erst in der echten App (Konzept §5.1).

## Offene Punkte

- **Kein JDK / Android-SDK auf dieser Maschine** → `android/` wird vollständig
  ausgearbeitet (inkl. Gradle-Wrapper-Konfiguration), der APK-Build selbst
  braucht eine Maschine mit Android Studio bzw. SDK + JDK 17. Anleitung im README.
- sherpa-onnx-Maven-Koordinate ist zu verifizieren (`com.k2fsa.sherpa.onnx:sherpa-onnx`);
  Fallback: AAR von GitHub-Releases in `app/libs/`. Der TTS-Spike-Code kapselt
  das hinter einer Klasse und loggt sauber, wenn die Lib fehlt.
- Andika-TTF muss beschafft werden (SIL OFL); falls hier kein Download möglich,
  lädt der Player mit System-Fallback und README-Hinweis.
- Fish-Speech-Lizenz: Research License — private Nutzung ok (bereits in
  Etappen-Planung dokumentiert).
- Abnahme „läuft auf dem Tablet" erfordert Gerät + gebautes APK → manueller
  Schritt nach dieser Etappe.
