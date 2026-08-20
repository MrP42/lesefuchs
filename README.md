# Lesefuchs

**Vorlese- und Lese-Lern-App für Kinder — Inhalte entstehen zu Hause, das
Tablet spielt sie offline ab.**

Ein Kind hört eine Geschichte und sieht dabei jedes Wort in dem Moment
aufleuchten, in dem es gesprochen wird. Die Audiodateien und die dazugehörigen
Wort- und Silben-Zeiten werden vorher am PC erzeugt — mit lokaler
Sprachsynthese, ohne Cloud-Dienst. Auf dem Tablet läuft danach nur noch
Wiedergabe: schnell, stromsparend und ohne Netz.

Entwickelt von **Ingenieurbüro Wolff / Wolff Applied AI** für Amazon Fire
Tablets (Fire OS 8).

> **Stand:** Etappe 1 — Render-Worker und Android-Spike sind fertig und
> gemessen. Die Abnahme auf echter Hardware steht noch aus, das Backend
> (Etappe 2) ist noch nicht begonnen. Einzelheiten in
> [docs/STATUS.md](docs/STATUS.md).

---

## Aufbau des Repos

| Ordner | Inhalt |
|---|---|
| `worker/` | Render-Worker (Python): Text → optimierter Vorlesetext → Audio → geprüfte, wortgenaue `.lesepaket`-Datei |
| `android/` | Tablet-App (Kotlin/Compose): Player mit Wort-Highlight, Technik-Spike, Abnahme-Werkzeuge |
| `docs/` | Stand, Entscheidungen, Versionshistorie |
| `Lesefuchs_Konzept.md` | Technisches Gesamtkonzept (Architektur, Paketformat, Roadmap) |

Die beiden Teile hängen nur über das Dateiformat `.lesepaket` zusammen
(ZIP mit `manifest.json`, `content.json` und Opus-Audio, Konzept §4).

---

## Auf dem Tablet installieren

**Direkt vom Tablet, ohne PC:**

1. Am Tablet **Einstellungen → Sicherheit & Datenschutz → Apps unbekannter
   Herkunft** für den Silk-Browser erlauben.
2. Im Browser die Adresse
   **`github.com/MrP42/lesefuchs/releases/latest`** öffnen.
3. Unter *Assets* die Datei `lesefuchs-<version>.apk` antippen → herunterladen
   → **Öffnen** → installieren.

**Vom PC aus (Entwicklerweg):**

```
adb install -r lesefuchs-<version>.apk
```

**Systemvoraussetzungen:** Fire OS 8 (Android 11) oder neuer, `arm64-v8a`,
mindestens 3 GB RAM. Die App braucht keine Google-Dienste.

## Aktualisieren

Die App prüft beim Start, ob unter *Releases* eine neuere Version liegt, und
bietet den Download an. Nach dem Herunterladen öffnet sich der übliche
Android-Installationsdialog; einmalig muss dafür die Berechtigung „Apps
installieren" erteilt werden.

Ohne die automatische Prüfung geht es genauso wie bei der Erstinstallation:
Releases-Seite öffnen, neue APK laden, installieren. Die Installation
überschreibt die alte Version, **Inhalte und Fortschritt bleiben erhalten**.

## Inhalte aufs Tablet bringen

**Am einfachsten direkt in der App** — kein PC, keine Zusatzberechtigung:

- **„Beispielgeschichte laden"** holt eine fertige Geschichte aus dem
  aktuellen Release.
- **„Datei öffnen …"** übernimmt eine `.lesepaket`-Datei, die schon auf dem
  Tablet liegt — etwa nach einem Download im Browser. Der Systemdialog regelt
  den Zugriff, es sind keine Dateirechte nötig.

Beides landet im app-eigenen Ordner und steht sofort im Player.

**Für viele Inhalte oder vom PC aus** sucht die App zusätzlich in dieser
Reihenfolge:

```
<App-Ordner>/inbox/                                    (Import, s. o.)
/sdcard/Android/data/de.lesefuchs.spike/files/inbox/   (adb push, ohne Rechte)
/sdcard/Lesefuchs/inbox/                               (braucht Dateizugriff)
/sdcard/Download/                                      (braucht Dateizugriff)
```

Übertragen per USB (MTP), microSD oder `adb push`.

---

## Ein Hörbuch erzeugen (PC)

Der Worker macht aus einer Text- oder Markdown-Datei ein fertiges Paket.
Gebraucht werden: Python 3.12, `ffmpeg` und — für Vertonung und Ausrichtung —
ein lokaler [Fish-Speech](https://github.com/fishaudio/fish-speech)-Server
sowie optional [Ollama](https://ollama.com).

```bash
cd worker
make setup        # virtuelle Umgebung + Kernpakete
make setup-ml     # zusätzlich faster-whisper und whisperx (lädt PyTorch)
make demo         # Beispielgeschichte vertonen -> out/*.lesepaket
```

Eigener Text:

```bash
.venv/Scripts/python -m lesefuchs_worker run --input meine-geschichte.md
```

Die Pipeline läuft in neun Schritten und merkt sich jeden Zwischenstand unter
`worker/work/<job>/`: Ein Abbruch kostet keine erneute Vertonung. Alle
Einstellungen (Stimme, Modell, Prüfschwellen) stehen in `worker/.env` —
Vorlage ist `worker/.env.example`. Einzelheiten: [worker/README.md](worker/README.md).

## Selbst bauen und testen

```bash
cd worker && .venv/Scripts/python -m pytest -q     # 57 Tests, ohne externe Dienste
cd android && gradlew.bat :app:assembleDebug       # APK, JDK 17 + Android SDK 34
```

Das Piper-Sprachmodell liegt wegen seiner Größe nicht im Repo und muss vor dem
Android-Build einmalig abgelegt werden — Bezugsquelle steht in
[android/README.md](android/README.md).

---

## Datenschutz

Die App enthält keine Konten, keine Werbung, keine Analyse-Dienste und keine
Google-Play-Dienste. Sie verbindet sich ausschließlich dann mit dem Netz, wenn
sie beim Start nach einer neuen Version sucht (GitHub); diese Prüfung lässt
sich abschalten. Alle Inhalte, Aufnahmen und Fortschrittsdaten bleiben auf dem
Gerät.

Erzeugung und Vertonung laufen vollständig auf dem eigenen Rechner — es wird
kein Text und kein Audio an einen Cloud-Dienst gesendet.

## Herkunft und Lizenz

Eigenentwicklung von Patrick Wolff (Ingenieurbüro Wolff / Wolff Applied AI).
Der Quelltext ist einsehbar, aber bislang unter keine Open-Source-Lizenz
gestellt — für eine Nachnutzung bitte anfragen.

Mitverwendete Bestandteile mit eigenen Bedingungen:

| Bestandteil | Lizenz |
|---|---|
| [Andika](https://software.sil.org/andika/) (SIL) | OFL |
| [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx) | Apache-2.0 |
| Piper-Stimme `de_DE-thorsten-medium` ([Thorsten-Voice](https://github.com/thorstenMueller/Thorsten-Voice)) | CC0 |
| [ML Kit Text Recognition](https://developers.google.com/ml-kit) (bundled) | Google-Bedingungen |
| [Fish-Speech](https://github.com/fishaudio/fish-speech) (nur PC-seitig) | **Research License — nicht für kommerzielle Nutzung** |

Für importierte oder eingescannte Bücher gilt das Recht auf Privatkopie
(§ 53 UrhG), solange die erzeugten Pakete den eigenen Haushalt nicht verlassen.

---

## Automatische Sicherung

Jeder Commit wird sofort nach GitHub geschoben — ein versionierter
`post-commit`-Hook erledigt das im Hintergrund. Nach einem frischen Klon
einmalig aktivieren:

```bash
git config core.hooksPath tools/git-hooks
```

Einzelheiten und Fehlersuche: [tools/README.md](tools/README.md).
