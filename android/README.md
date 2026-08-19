# Lesefuchs Android-Spike

Beweis-APK (kein Produkt): Player mit Satz-/Wort-Highlight + Technik-Spike
(ML Kit bundled, sherpa-onnx, Lock Task). `minSdk 28`, `targetSdk 34`,
nur `arm64-v8a`, keine GMS-Abhängigkeiten.

## Bauen

Toolchain liegt lokal unter `C:\Users\wolff\tools\` (Temurin JDK 17.0.12,
Gradle 8.9, Android SDK 34 + Build-Tools 34.0.0 in `tools\android\sdk`;
`local.properties` zeigt darauf). Build:

```
set JAVA_HOME=C:\Users\wolff\tools\jdk-17.0.12+7
cd android && gradlew.bat :app:assembleDebug
```

APK: `app/build/outputs/apk/debug/app-debug.apk` — **77,4 MB**, ausschließlich
`lib/arm64-v8a/` (größte Posten: onnxruntime 21,7 MB, ML-Kit-OCR-Modell
11,1 MB, sherpa-onnx 9,7 MB).

**sherpa-onnx:** gepinntes AAR **v1.13.6** aus den GitHub-Releases in
`android/libs/sherpa-onnx-1.13.6.aar` (kein Maven-Artefakt), eingebunden über
`implementation(files(rootProject.file("libs/…")))`.

**GMS-Status:** Es ist keine `com.google.android.gms:*`-Abhängigkeit
deklariert. `com.google.mlkit:text-recognition` (bundled) zieht transitiv
`play-services-base/basement/tasks` als **eingebettete Bibliotheken** — das
ist bei ML Kit unvermeidbar und bedeutet KEINE Abhängigkeit von der
Play-Services-App auf dem Gerät: das OCR-Modell steckt im APK
(`libmlkit_google_ocr_pipeline.so`). Endgültiger Nachweis = Spike 2a auf dem
Fire Tablet. Nachprüfen: `gradlew.bat :app:dependencies`.

## Sideload auf Fire Tablet (Fire OS 8)

```
Einstellungen → Sicherheit & Datenschutz → Apps unbekannter Herkunft: zulassen
adb install app-debug.apk
```

## Inhalte aufs Tablet

```
# ohne Freigaben (App-eigener Ordner):
adb push out/finn-fuchs-und-der-sternenwald_v1.lesepaket \
    /sdcard/Android/data/de.lesefuchs.spike/files/inbox/

# oder /sdcard/Lesefuchs/inbox/ — dafür einmalig All-Files-Access erteilen:
adb shell appops set de.lesefuchs.spike MANAGE_EXTERNAL_STORAGE allow
```

Die App lädt beim Start das erste gefundene `.lesepaket` (keine Import-UI).

## Piper-Modell für Spike 2 (sherpa-onnx)

**Im APK gebündelt** (`app/src/main/assets/piper-de/`, beim ersten TTS-Start
nach `filesDir` entpackt): **`de_DE-thorsten-medium`** — Referenzstimme des
Konzepts (§4.3) und obere Performance-Grenze für die Spike-Messung (läuft
medium auf dem MediaTek, läuft x_low erst recht).

- Quelle: https://github.com/k2-fsa/sherpa-onnx/releases/download/tts-models/vits-piper-de_DE-thorsten-medium.tar.bz2
  (sherpa-onnx-Konvertierung der Piper-Stimme; Thorsten-Voice, Lizenz CC0,
  22,05 kHz, Details `assets/piper-de/MODEL_CARD`)
- Die Modell-Dateien sind **nicht im Git** (78 MB; `.gitignore`) — vor einem
  Build einmalig obiges Archiv entpacken nach `app/src/main/assets/piper-de/`
  als `model.onnx`, `tokens.txt`, `espeak-ng-data/`, `MODEL_CARD`.
- Override ohne Rebuild: gleiche Dateien nach
  `/sdcard/Lesefuchs/models/piper-de/` pushen (hat Vorrang).

## Automatisierter Spike (ohne Bedienung)

`.\abnahme.ps1` (Gerät per USB, Entwickleroptionen an) macht Punkt 1 und 2
der Abnahme selbstständig: installieren, Testdaten pushen, SpikeActivity im
Autorun starten, Ergebnisse aus dem Logcat ziehen → `abnahme-ergebnis.txt`.

Direkt per adb geht es auch:
```
adb shell am start -n de.lesefuchs.spike/.SpikeActivity \
    --ez autorun true --es ocr_image /sdcard/Lesefuchs/test/seite.png
adb logcat -d -s LesefuchsSpike | findstr SPIKE_RESULT
```
Je Test genau eine Zeile, maschinenlesbar:
```
SPIKE_RESULT key=ocr      status=OK ms=… blocks=… chars=… accuracy=…
SPIKE_RESULT key=tts      status=OK load_ms=… synth_ms=… rtf=… ram_after_mb=…
SPIKE_RESULT key=locktask status=OK mode_name=LOCKED
```
Das OCR-Testbild (`testdata/seite.png`, deutscher Fließtext mit Umlauten und
Ziffern) wird gegen `testdata/seite.txt` verglichen — daher `accuracy` statt
nur „erkannt". Neu erzeugen: `testdata/make_testpage.py`.

## Abnahme (Konzept-Kriterium)

5-Minuten-Kapitel abspielen: Wort-Highlight sichtbar synchron, kein Ruckeln,
kein Drift zum Kapitelende. Lead-Offset im Player live verstellbar
(Default −60 ms). Logs des Technik-Spikes: `adb logcat -s LesefuchsSpike`.
