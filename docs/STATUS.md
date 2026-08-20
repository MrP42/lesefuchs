# Stand

**Stichtag:** 19.08.2026 · **Version:** 0.1.0 (unveröffentlicht)

## Etappen

| Etappe | Inhalt | Stand |
|---|---|---|
| **1** | Render-Worker + Android-Spike | **fertig**, Abnahme am Gerät offen |
| 2 | Backend (FastAPI + PostgreSQL auf IONOS VPS), Profile, Verteilung | nicht begonnen |
| 3 | Vollständige Tablet-App (Profile, Bibliothek, Kamera-Scan) | nicht begonnen |

Etappe 2 startet erst, wenn die Abnahme aus `android/ABNAHME.md` bestanden ist.

## Was nachweislich funktioniert

| Prüfung | Ergebnis | Wo gemessen |
|---|---|---|
| Pipeline Ende-zu-Ende | Textdatei → `.lesepaket` (2 Kapitel, 611 Wörter, 247 s) | PC, echte Dienste |
| Qualitätsprüfung der Vertonung | 17 von 17 Absätzen ohne Beanstandung | PC |
| Wiederaufnahme nach Abbruch | vertonte Absätze überleben Fehler in späteren Schritten | PC, real erzwungen |
| Entladen des Sprachmodells vor Vertonung | 8289 → 2199 MiB VRAM, Ollama meldet nichts mehr geladen | PC |
| Paket laden und anzeigen | 611 Wörter, Text in Andika gesetzt | Emulator |
| **Synchronität des Highlights** | **424 Messpunkte, max. Abweichung 0 ms, 0 Fehltreffer** | Emulator |
| Antippen eines Wortes | springt an die Stelle, Satz- und Wort-Highlight folgen | Emulator |
| Texterkennung | 99,6 % Zeichengenauigkeit auf der Testseite | Emulator |
| Automatische Tests | 57 grün, ohne externe Dienste | PC |

## Was nur auf dem Fire Tablet zu klären ist

Der Emulator (x86_64, Android 15, mit Google-Play-Diensten) taugt für diese
Punkte ausdrücklich **nicht**:

- Texterkennung **ohne** Google-Play-Dienste — der eigentliche Nachweis.
- Tatsächliche Geschwindigkeit der Sprachsynthese auf dem MediaTek-Chip;
  davon hängt ab, ob das mittlere oder das kleine Sprachmodell nötig ist.
- Gerätesperre (`startLockTask`) unter Fire OS.
- Der gerätespezifische Vorlauf des Highlights (Audio-Puffer-Latenz).
- Flüssige Wiedergabe auf 3–4 GB RAM.
- Reaktion des Kindes.

## Bekannte Einschränkungen

- Fish-Speech steht unter einer Forschungslizenz — private Nutzung ist gedeckt,
  eine kommerzielle nicht. Für eine Weitergabe müsste auf Piper gewechselt werden.
- Das Piper-Sprachmodell (78 MB) liegt nicht im Repo und muss vor dem
  Android-Build einmalig abgelegt werden.
- Die Ausrichtung läuft derzeit auf der CPU (PyTorch ohne CUDA installiert);
  für ein 4-Minuten-Buch dauert sie rund zwei Minuten.
