# Lesefuchs — Abnahme-Checkliste Fire Tablet (Etappe 1)

Gerät: ____________________ (Modell, Fire-OS-Version) · Datum: __________

**Reihenfolge einhalten** — scheitert ein Punkt, sind die folgenden sinnlos.
Abnahmekriterium gesamt: 5-Minuten-Kapitel läuft, Wort-Highlight sichtbar
synchron, kein Ruckeln, kein Drift bis zum Kapitelende.

---

## Emulator-Vorlauf (19.08.2026) — KEINE Abnahme

Gerät: **Pixel Tablet, Android 15 (SDK 35), x86_64** mit ARM64-Translation
(`ro.product.cpu.abilist = x86_64,arm64-v8a`), Google Play Services im Image.
Das ist in drei Punkten nicht das Zielgerät (Fire OS 8 = Android 11, arm64,
kein GMS) — die Tabelle trennt deshalb strikt.

| Prüfung | Emulator-Ergebnis | Für die Abnahme verwertbar? |
|---|---|---|
| Paket laden (`.lesepaket` → Player) | OK — 611 Tokens, 2 Kapitel, Text gesetzt in Andika | **ja** |
| **Highlight-Synchronität** (autom., Kap. 1) | **max_dev 0 ms, mean 0,0 ms, 0 Mismatches bei 424 Samples**; tail_gap 556 ms bei 106,5 s Audio | **ja** — prüft die Engine, nicht die Hardware |
| OCR-Genauigkeit auf `testdata/seite.png` | 99,6 % (570 Zeichen, 5 Blöcke, 1,65 s) | **ja** (Genauigkeit) |
| Wort-Tap-Seek | OK — Tap auf „alten" setzt Wort- und Satz-Highlight, scrollt hin | **ja** |
| Satz-/Wort-Highlight optisch | OK, durchgehender Satzbalken | **ja** |
| ML Kit **ohne GMS** | — | **nein**: Play Services im Image → 2a beweist hier nichts |
| TTS Latenz / RTF / RAM | lief (rtf 0,24, 277 MB) | **nein**: Host-CPU + ARM-Translation |
| LockTask | `status=FAIL reason=not_active` (Modus blieb 0) | **nein**: ohne Device-Owner erscheint ein Bestätigungsdialog, den im Autorun niemand bestätigt |
| Lead-Offset | — | **nein**: andere Audio-Puffer-Latenz |

**Fazit des Vorlaufs:** Engine, Paketformat, Player-Interaktion und OCR-Kette
sind bewiesen. Offen und ausschließlich am Fire Tablet zu klären: GMS-Freiheit
der OCR, reale TTS-Leistung auf MediaTek, LockTask unter Fire OS, Lead-Offset,
flüssige Wiedergabe auf schwacher Hardware, Kind-Reaktion.

---

> **Punkte 1 und 2 laufen automatisch:** `.\abnahme.ps1` installiert das APK,
> schiebt Testbild und Demo-Paket aufs Gerät, startet die SpikeActivity im
> Autorun-Modus und schreibt die Ergebniszeilen nach `abnahme-ergebnis.txt`.
> Werte von dort unten eintragen. Die Abschnitte darunter beschreiben denselben
> Ablauf von Hand — für den Fall, dass etwas hakt.
> Punkte 3–6 bleiben manuell (brauchen Auge und Ohr).

---

## 1 · Installation

```
adb install app/build/outputs/apk/debug/app-debug.apk
```
Vorher am Tablet: Einstellungen → Sicherheit & Datenschutz → Apps unbekannter
Herkunft zulassen.

- [ ] APK installiert sich auf Fire OS 8 ohne Fehler
- Ergebnis/Auffälligkeiten: ______________________________________________

## 2 · SpikeActivity

Automatisch (2a → 2b → 2c, beendet sich selbst):
```
adb shell am start -n de.lesefuchs.spike/.SpikeActivity \
    --ez autorun true --es ocr_image /sdcard/Lesefuchs/test/seite.png
adb logcat -d -s LesefuchsSpike | findstr SPIKE_RESULT
```
Manuell: App öffnen → „Technik-Spike", Logs mitlesen mit
`adb logcat -s LesefuchsSpike`

### 2a · ML Kit bundled (Testbild → Text)
Autorun nutzt `testdata/seite.png` (deutscher Fließtext, Umlaute, ß, Ziffern)
und vergleicht mit `seite.txt` → Zeichen-Genauigkeit. Manuelle Alternative:
Foto-Button in der UI.

Vorher in einem zweiten Terminal mitlaufen lassen und Ausgabe festhalten:
```
adb logcat | findstr /i "gms mlkit GoogleApi"
```
Ergebniszeile: `SPIKE_RESULT key=ocr status=… ms=… blocks=… chars=… accuracy=…`
- [ ] Ja / [ ] Nein — Text wird ohne Play Services erkannt
- Blöcke: ______  Zeichen: ______  Dauer (ms): ______  **Genauigkeit: ______**
- Relevante logcat-Zeilen (insb. GMS-Verfügbarkeitsfehler): _______________
  ________________________________________________________________________
- Auffälligkeiten: _______________________________________________________

> Falls hier ein GMS-Verfügbarkeitsfehler die Erkennung verhindert, greift
> der im Konzept (§10) bereits eingeplante Fallback **Tesseract4Android**
> (`deu`, tessdata_best) — vermerken, NICHT vorab bauen.

### 2b · sherpa-onnx + Piper (200 Zeichen)
Modell ist **im APK gebündelt** (thorsten-medium) und wird beim ersten Lauf
nach `filesDir` entpackt — nichts zu pushen. Override möglich über
`/sdcard/Lesefuchs/models/piper-de/` (siehe README).
Ergebniszeile: `SPIKE_RESULT key=tts status=… load_ms=… synth_ms=… rtf=… …`
- [ ] Synthese liefert Audio-Werte
- Laden (ms): ________  Synthese (ms): ________  RTF: ________
- RAM nativ vorher→nachher (MB): ________  Gerät frei (MB): ________
- Auffälligkeiten: _______________________________________________________

> RTF > 1,0 heißt: langsamer als Echtzeit. Dann im Player-Pfad unkritisch
> (Audio ist vorgerendert), aber der Scan-Pfad braucht `x_low` statt `medium`
> — Konzept §10, Zeile „sherpa-onnx zu langsam auf MediaTek".

### 2c · startLockTask()
Ergebniszeile: `SPIKE_RESULT key=locktask status=OK mode_name=LOCKED|PINNED`
bzw. `status=FAIL reason=not_allowed` (Aufruf ging durch, Modus blieb NONE)
oder `status=FAIL reason=exception` (Fire OS hat die Funktion gesperrt).
- [ ] Ja / [ ] Nein — Fire OS erlaubt Pinning (Modus 1 = LOCKED, 2 = PINNED)
- Geloggter Modus/Grund: ________  Auffälligkeiten: _______________________

## 3 · Player: Kapitel 1 vollständig hören

Paket und Freigabe hat `abnahme.ps1` bereits erledigt; von Hand wäre es:
```
adb shell appops set de.lesefuchs.spike MANAGE_EXTERNAL_STORAGE allow
adb shell mkdir -p /sdcard/Lesefuchs/inbox
adb push worker/out/finn-fuchs-und-der-sternenwald_v1.lesepaket /sdcard/Lesefuchs/inbox/
```
App starten → Paket lädt automatisch → ▶ Vorlesen → Kapitel 1 bis zum
Ende anhören (≈ 1:45 min), dabei aufs Highlight achten.

- [ ] Wiedergabe läuft flüssig (kein Ruckeln, keine Aussetzer)
- [ ] Wort-Highlight läuft sichtbar mit
- [ ] **Kein Drift am Kapitelende** (letztes Wort leuchtet, während es gesprochen wird)
- Ergebnis/Auffälligkeiten: ______________________________________________

Die rechnerische Seite ist im Emulator bereits belegt (0 ms Abweichung über
424 Samples). Was hier zu prüfen bleibt, ist alles, was der Rechner nicht
messen kann: Ruckeln auf schwacher Hardware und der Sitz des Highlights zum
*gehörten* Ton. Automatisierte Gegenprobe auf dem Gerät:
```
adb shell am start -n de.lesefuchs.spike/.MainActivity --ez selfcheck true
adb logcat -d -s LesefuchsSpike | findstr key=sync
```
- Sync-Zeile vom Fire Tablet: ____________________________________________

## 4 · Lead-Offset kalibrieren

Der Default −60 ms ist eine Annahme; die reale Audio-Puffer-Latenz ist
geräteabhängig. Slider im Player verstellen, bis das Highlight subjektiv
exakt auf dem gesprochenen Wort sitzt (am besten bei langsamer Passage).

- Kalibrierter Wert: ________ ms
- [ ] Wert als gerätespezifischen Default in `android/README.md` eingetragen
- Auffälligkeiten: _______________________________________________________

## 5 · Wort-Tap-Seek

Beliebige Wörter antippen (auch in anderem Absatz / nach Scroll).
- [ ] Audio springt hörbar an die Position des angetippten Worts
- [ ] Highlight setzt dort korrekt wieder auf
- Auffälligkeiten: _______________________________________________________

## 6 · Kind-Beobachtung

Ein Kind zuschauen/zuhören lassen (ohne Anleitung).
- Versteht es, dass das leuchtende Wort das gesprochene ist? __________
- Versucht es zu tippen / mitzulesen / wegzutippen? ________________________
- Stimme angenehm? Tempo passend (Lesestufe)? _____________________________
- Sonstige Beobachtungen: ________________________________________________

---

## Gesamtergebnis

- [ ] **ABGENOMMEN** — Etappe 2 (FastAPI-Backend) kann starten
- [ ] Nacharbeiten nötig: _________________________________________________
