# Lesefuchs Android-Spike

Beweis-APK (kein Produkt): Player mit Satz-/Wort-Highlight + Technik-Spike
(ML Kit bundled, sherpa-onnx, Lock Task). `minSdk 28`, `targetSdk 34`,
nur `arm64-v8a`, keine GMS-Abhängigkeiten.

## Bauen (auf einer Maschine mit Android-Toolchain)

Auf dieser Entwicklungsmaschine sind **kein JDK und kein Android-SDK**
installiert — der Build braucht einmalig:

1. Android Studio (bringt JDK 17 + SDK 34) — oder `sdkmanager` + Temurin 17.
2. Im Ordner `android/`: `gradle wrapper` ausführen (erzeugt gradlew;
   `gradle/wrapper/gradle-wrapper.properties` ist vorkonfiguriert auf 8.9),
   danach `./gradlew :app:assembleDebug`.
3. APK: `app/build/outputs/apk/debug/app-debug.apk`.

Fällt die sherpa-onnx-Maven-Koordinate im Build durch, das AAR von
https://github.com/k2-fsa/sherpa-onnx/releases nach `app/libs/` legen und in
`app/build.gradle.kts` die Abhängigkeit auf `files("libs/…aar")` umstellen.

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

Von https://github.com/k2-fsa/sherpa-onnx/releases (Asset
`vits-piper-de_DE-thorsten-medium.tar.bz2` o. ä.) entpacken und pushen:

```
/sdcard/Lesefuchs/models/piper-de/model.onnx
/sdcard/Lesefuchs/models/piper-de/tokens.txt
/sdcard/Lesefuchs/models/piper-de/espeak-ng-data/
```

## Abnahme (Konzept-Kriterium)

5-Minuten-Kapitel abspielen: Wort-Highlight sichtbar synchron, kein Ruckeln,
kein Drift zum Kapitelende. Lead-Offset im Player live verstellbar
(Default −60 ms). Logs des Technik-Spikes: `adb logcat -s LesefuchsSpike`.
