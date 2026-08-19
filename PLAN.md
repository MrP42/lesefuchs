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
2. Projektgerüst `worker/`: pyproject, pydantic-settings-Config, `.env.example`,
   Job-Verwaltung (`work/<job-id>/state.json`, Resume), CLI-Skelett (argparse)
3. Schritt `ingest`: TXT/MD → Kapitel/Absätze (`01_paragraphs.json`) + Tests
4. Schritt `normalize`: num2words(de), Abkürzungstabelle, deterministisch + Tests
5. Schritt `optimize`: Ollama `/api/chat`, Prompt aus Konzept §6.3a,
   Absatz-Erhalt erzwingen; ohne erreichbaren Ollama: Skip mit Warnung + Tests (gemockt)
6. Schritt `synthesize`: Fish-Speech `/v1/tts` je Absatz → WAV; Cache je Absatz-Hash
7. Schritt `verify`: faster-whisper-Transkription vs. Soll (WER-Schwelle aus
   Config); bei Überschreitung Re-Synthese mit anderem Seed (max N Versuche)
8. Schritt `align`: WhisperX Forced Alignment (de) je Absatz → Wort-Timestamps;
   Offsets beim Konkatenieren (Pausen zwischen Absätzen) verrechnen
9. Schritt `syllables`: pyphen de_DE, Dauer proportional (Vokalkerne doppelt) + Tests
10. Schritt `encode` + `package`: ffmpeg → `ch01.opus`; `manifest.json` +
    `content.json` exakt nach §4.3/§4.4 (REFLOW, `vis: null`), ZIP → `out/` + Tests
11. `examples/beispiel.md` (≈5 min Vorlesetext), `Makefile` mit `make demo`,
    README; Ende-zu-Ende-Lauf gegen lokale Dienste

### Android
12. Gradle-Projekt: Wrapper, Version Catalog, `app/`-Modul (Compose, Media3,
    kotlinx-serialization; nur arm64-v8a, keine GMS)
13. `LesepaketLoader`: ZIP/Ordner aus `/sdcard/Lesefuchs/inbox/` lesen,
    `content.json`/`manifest.json` parsen (Datenklassen nach §4.4)
14. `HighlightEngine` (Portierung Konzept §5.3): Token-Suche ab Cache-Index,
    Binärsuche bei Sprung, Lead-Offset
15. Player-Screen: Andika-Font, Satz-Highlight (sanft) + Wort-Highlight (kräftig),
    `withFrameNanos`-Loop, Wort-Tap → Seek, Play/Pause/±
16. Spike-Activity: ML Kit bundled (Foto via TakePicturePreview → Text),
    sherpa-onnx-TTS (Modelle von `/sdcard/Lesefuchs/models/`), Latenz/RAM-Log,
    `startLockTask()`-Ergebnis-Log
17. Abschluss: README (Build-/Sideload-Anleitung), offene Punkte konsolidieren

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
