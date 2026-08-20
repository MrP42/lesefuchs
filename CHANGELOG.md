# Versionshistorie

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).
Die App-Version im APK (`versionName`) entspricht der jeweiligen Marke hier.

## [Unveröffentlicht]

### Hinzugefügt
- Vollständiges Repository mit README, Versionshistorie und Anleitungen zu
  Installation und Aktualisierung; automatische Sicherung nach GitHub.
- Installation und Update direkt vom Tablet über GitHub Releases
  (Konzept §8.3).

---

## [0.1.0] — 2026-08-19 — Etappe 1: Render-Worker und Android-Spike

Erste vollständige Kette: aus einer Textdatei entsteht ein vertontes Paket mit
wortgenauen Zeiten, und ein Tablet spielt es mit mitlaufendem Highlight ab.

### Render-Worker (`worker/`)

- **Neunstufige Pipeline**, jeder Schritt einzeln aufrufbar und wiederaufnehmbar
  (`work/<job>/`): `ingest` → `optimize` → `normalize` → `synthesize` →
  `verify` → `align` → `syllables` → `encode` → `package`.
- **Textaufbereitung:** Markdown/Text in Kapitel und Absätze; Vorlese-Optimierung
  über Ollama mit erzwungenem Absatz-Erhalt und Diff-Protokoll (`llm_diff.txt`);
  Zahlen, Daten, Einheiten und Abkürzungen werden deterministisch aufgelöst
  (`num2words`, eigene Tabelle) — nicht vom Sprachmodell.
- **Vertonung** über einen lokalen Fish-Speech-Server, Absatz für Absatz mit
  Zwischenspeicher je Absatz-Hash.
- **Qualitätsprüfung:** Rücktranskription mit faster-whisper, Wortfehlerrate
  gegen den Soll-Text (Schwelle 0,05), zusätzlich Erkennung von Wiederholungs-
  schleifen und abgeschnittenen Absätzen; auffällige Absätze werden mit
  verändertem Zufallswert neu vertont.
- **Zeiten:** Forced Alignment (WhisperX) je Absatz mit Offset-Verrechnung,
  fehlende Wortzeiten werden interpoliert; Silben über `pyphen` mit
  vokalgewichteter Dauerverteilung.
- **Ausgabe:** Opus 24 kHz mono / 24 kbit/s, Paket nach Konzept §4.2–4.4
  (`manifest.json`, `content.json`, Prüfsummen).
- **GPU-Serialisierung:** ein prozessübergreifendes Lock für alle GPU-Schritte,
  aktives Entladen des Sprachmodells vor Vertonung und Ausrichtung, sprechende
  Fehlermeldung mit VRAM-Stand bei Zeitüberschreitung.
- 57 automatische Tests, ohne externe Dienste lauffähig.

### Tablet-App (`android/`)

- **Player** (Kotlin/Compose, Media3): gesetzter Text in Andika, Satz- und
  Wort-Highlight synchron zur Wiedergabe (`withFrameNanos`), Antippen eines
  Wortes springt an die zugehörige Stelle, einstellbarer Vorlauf des Highlights.
- **Sync-Engine** nach Konzept §5.3 mit Zwischenspeicher-Index und Binärsuche
  bei Sprüngen.
- **Technik-Spike**: Texterkennung (ML Kit, im Paket gebündelt), Sprachsynthese
  auf dem Gerät (sherpa-onnx mit Piper `de_DE-thorsten-medium`), Gerätesperre
  (`startLockTask`) — per Intent automatisch ausführbar, Ergebnisse als
  maschinenlesbare Protokollzeilen.
- **Selbstprüfung der Synchronität**: vergleicht während der Wiedergabe alle
  250 ms die Engine gegen eine unabhängige Referenzsuche.
- Nur `arm64-v8a`, `minSdk 28`, keine Google-Play-Dienste als Abhängigkeit.
- Abnahme-Werkzeuge: `abnahme.ps1` und `ABNAHME.md` für den Gerätetest.

### Gemessen

- Demo-Paket: 2 Kapitel, 611 Wörter, 951 Silben, 247 s Audio, 726 KiB.
- Alle 17 Absätze bestehen die Qualitätsprüfung ohne Beanstandung.
- Synchronität im Emulator: 424 Messpunkte über ein ganzes Kapitel,
  maximale Abweichung 0 ms, keine Fehltreffer.
- Texterkennung auf der Testseite: 99,6 % Zeichengenauigkeit.

### Korrigiert

- Konzept §4.2/§4.5: Opus beherrscht keine 22,05 kHz — korrekt sind 24 kHz.
- Wortfehlerrate wird gegen Schreibvarianten der Rücktranskription
  unempfindlich gemacht (Ziffern, Zusammenschreibung); dadurch entfielen fünf
  unnötige Neuvertonungen.
- Satz-Highlight erscheint als durchgehender Balken statt gestreift.

### Offen

- Abnahme auf echter Fire-Hardware (siehe `android/ABNAHME.md`).
- Backend und Mehrgerätebetrieb (Etappe 2).
