# Lesefuchs — Abnahme-Checkliste Fire Tablet (Etappe 1)

Gerät: ____________________ (Modell, Fire-OS-Version) · Datum: __________

**Reihenfolge einhalten** — scheitert ein Punkt, sind die folgenden sinnlos.
Abnahmekriterium gesamt: 5-Minuten-Kapitel läuft, Wort-Highlight sichtbar
synchron, kein Ruckeln, kein Drift bis zum Kapitelende.

---

## 1 · Installation

```
adb install app/build/outputs/apk/debug/app-debug.apk
```
Vorher am Tablet: Einstellungen → Sicherheit & Datenschutz → Apps unbekannter
Herkunft zulassen.

- [ ] APK installiert sich auf Fire OS 8 ohne Fehler
- Ergebnis/Auffälligkeiten: ______________________________________________

## 2 · SpikeActivity (App öffnen → „Technik-Spike")

Logs parallel mitlesen: `adb logcat -s LesefuchsSpike`

### 2a · ML Kit bundled (Foto → Text)
Gedruckten Text (Buchseite) fotografieren.
- [ ] Ja / [ ] Nein — Text wird ohne Play Services erkannt
- Erkannte Zeichen/Blöcke, OCR-Dauer (ms): ________________________________
- Auffälligkeiten: _______________________________________________________

### 2b · sherpa-onnx + Piper (200 Zeichen)
Vorher Modell pushen (siehe README, `/sdcard/Lesefuchs/models/piper-de/`).
- [ ] Synthese liefert Audio-Werte
- Laden (ms): ________  Synthese (ms): ________  RTF: ________
- RAM nativ vorher→nachher (MB): ________  Gerät frei (MB): ________
- Auffälligkeiten: _______________________________________________________

### 2c · startLockTask()
- [ ] Ja / [ ] Nein — Fire OS erlaubt Pinning (Modus 1 = LOCKED, 2 = PINNED)
- Geloggter Modus: ________  Auffälligkeiten: _____________________________

## 3 · Player: Kapitel 1 vollständig hören

```
adb shell appops set de.lesefuchs.spike MANAGE_EXTERNAL_STORAGE allow
adb shell mkdir -p /sdcard/Lesefuchs/inbox
adb push worker/out/finn-fuchs-und-der-sternenwald_v1.lesepaket /sdcard/Lesefuchs/inbox/
```
App neu starten → Paket lädt automatisch → ▶ Vorlesen → Kapitel 1 bis zum
Ende anhören (≈ 2 min), dabei aufs Highlight achten.

- [ ] Wiedergabe läuft flüssig (kein Ruckeln, keine Aussetzer)
- [ ] Wort-Highlight läuft sichtbar mit
- [ ] **Kein Drift am Kapitelende** (letztes Wort leuchtet, während es gesprochen wird)
- Ergebnis/Auffälligkeiten: ______________________________________________

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
