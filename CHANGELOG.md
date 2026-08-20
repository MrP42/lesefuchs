# Versionshistorie

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).
Die App-Version im APK (`versionName`) entspricht der jeweiligen Marke hier.

## [Unveröffentlicht]

Noch keine Änderungen seit 0.2.0.

---

## [0.2.0] — 2026-08-20 — Bücherregal, elf Stimmen, Vorlesen ohne Vorbereitung

### Hinzugefügt
- **Bücherregal**: Alle Geschichten erscheinen als große Kacheln mit Titel,
  Kapitelzahl und Dauer; ein Tipp öffnet sie, „Regal" führt zurück. Bisher
  wurde stumm die erste gefundene Datei geöffnet.
- **Elf Stimmen zur Auswahl**: die Vorlesestimme des Geräts, die in der App
  enthaltene Stimme (Thorsten) und neun weitere, die bei Bedarf einmalig
  geladen werden (17–67 MB). Auswahl über große Kacheln mit Hörprobe-Namen
  und Größenangabe.
- **Vorlesen ohne vorbereitetes Audio**: Umschalter „Aufnahme ↔ Stimme".
  Im Stimme-Modus liest die gewählte Stimme direkt vor — ohne Server, ohne
  GPU, ohne Internet. Das Wort-Highlight läuft mit, die Wortdauern werden
  dabei aus der Silbenzahl geschätzt (Konzept §5.5) und sind daher nicht
  millisekundengenau wie bei einer Aufnahme.
- Antippen eines Wortes lässt im Stimme-Modus ab diesem Satz weiterlesen.

### Geändert
- **Kindgerechte Bedienung**: wenige große Tasten (84 dp) mit Beschriftung,
  Kapitel als große Zahlen, größere Schrift im Text. Technische Einstellungen
  (Vorlauf des Highlights, Technik-Spike) stecken hinter einem unauffälligen
  Zahnrad für Eltern statt in der Kinderansicht.

### Infrastruktur
- Eigenes Release `stimmen-v1` mit den Stimmen, App-tauglich als ZIP umgepackt
  (die gemeinsamen Sprachdaten stecken im APK, das spart je Stimme ~19 MB).

---

## [0.1.1] — 2026-08-20 — Inhalte ohne PC

Nach der ersten Installation auf einem echten Fire Tablet zeigte sich: Die App
lief, blieb aber leer — an die Inbox-Ordner kommt man ohne PC praktisch nicht
heran. Zwei neue Wege beheben das.

### Hinzugefügt
- **„Beispielgeschichte laden"** holt eine fertige Geschichte aus dem
  aktuellen Release — ein Fingertipp, ohne PC und ohne Zusatzberechtigung.
- **„Datei öffnen …"** übernimmt ein `.lesepaket` über den System-Dateidialog,
  etwa nach einem Download im Browser. Auch hier sind keine Dateirechte nötig.
- Die Suche nach Inhalten umfasst jetzt zusätzlich den app-eigenen
  Import-Ordner und `/sdcard/Download`.
- Das Demo-Paket hängt als Asset an jedem Release.

### Geändert
- Startbildschirm ohne Inhalte erklärt jetzt die nächsten Schritte, statt
  Dateipfade aufzuzählen.

### Sicherheit
- Beim Datei-Import wird der von einer fremden App gelieferte Anzeigename auf
  einen unverfänglichen Dateinamen reduziert und das Ziel gegen ein Ausbrechen
  aus dem Import-Ordner geprüft.

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

### Verteilung

- Repository unter https://github.com/MrP42/lesefuchs mit Anleitungen zu
  Installation, Aktualisierung und Release; jeder Commit wird automatisch
  gesichert.
- **Installation und Update direkt vom Tablet** über GitHub Releases
  (Konzept §8.3): Die App vergleicht beim Start ihre Version mit dem neuesten
  Release, lädt die neue Fassung herunter und öffnet den Installationsdialog.
  Abschaltbar; ohne diese Prüfung nutzt die App kein Netz.
- Signierte Release-Builds über GitHub Actions (Tag `v*`), Sprachmodell wird
  im Lauf nachgeladen.

### Gemessen

- Demo-Paket: 2 Kapitel, 611 Wörter, 951 Silben, 247 s Audio, 726 KiB.
- Alle 17 Absätze bestehen die Qualitätsprüfung ohne Beanstandung.
- Synchronität im Emulator: 424 Messpunkte über ein ganzes Kapitel,
  maximale Abweichung 0 ms, keine Fehltreffer.
- Texterkennung auf der Testseite: 99,6 % Zeichengenauigkeit.
- Aktualisierung Ende-zu-Ende geprüft: Version 0.0.9 erkennt Release 0.1.0,
  lädt 132 MB und öffnet den Installationsdialog; die signierte Release-APK
  installiert sich sauber.

### Korrigiert

- Konzept §4.2/§4.5: Opus beherrscht keine 22,05 kHz — korrekt sind 24 kHz.
- Wortfehlerrate wird gegen Schreibvarianten der Rücktranskription
  unempfindlich gemacht (Ziffern, Zusammenschreibung); dadurch entfielen fünf
  unnötige Neuvertonungen.
- Satz-Highlight erscheint als durchgehender Balken statt gestreift.

### Offen

- Abnahme auf echter Fire-Hardware (siehe `android/ABNAHME.md`).
- Backend und Mehrgerätebetrieb (Etappe 2).
