# Entscheidungen

Kurzbegründungen zu Weichenstellungen, damit sie später nicht neu diskutiert
werden. Die ausführliche Herleitung steht im Konzept.

| # | Entscheidung | Begründung |
|---|---|---|
| 1 | **Kein Sprachmodell auf dem Tablet** | Fire Tablets haben 3–4 GB RAM und keine NPU. Alles Rechenintensive läuft am PC, das Tablet bekommt fertige Pakete. |
| 2 | **Zwei Welten: PC erzeugt, Tablet spielt ab** | Ermöglicht Studioqualität bei millisekundengenauen Wortzeiten und trotzdem vollständige Offline-Nutzung. |
| 3 | **Wortzeiten vorberechnen statt live** | Forced Alignment liefert ±20 ms. Live-Synthese auf dem Tablet könnte das nie. |
| 4 | **Fließtext zuerst, Faksimile später** | Bei selbst gesetztem Text ist das Highlight pixelgenau, ohne Zuordnung zwischen Text und Seitenbild. |
| 5 | **Fish-Speech für die Vertonung, Piper auf dem Gerät** | Fish kann die Stimme eines Elternteils nachbilden; Piper ist frei lizenziert und läuft auf dem Tablet. |
| 6 | **Zahlen und Abkürzungen deterministisch auflösen** | Ein Sprachmodell erfindet dabei still Inhalte. Feste Tabellen und `num2words` sind nachvollziehbar und wiederholbar. |
| 7 | **Jede Vertonung wird zurücktranskribiert** | Sprachsynthese verschluckt oder wiederholt gelegentlich Wörter. Nur der Rückvergleich mit dem Soll-Text findet das. |
| 8 | **Vergleich vor Bewertung normalisieren** | Die Rücktranskription schreibt „einhundert" als „100". Ohne Angleichung werden korrekte Aufnahmen verworfen — real geschehen, fünf unnötige Neuvertonungen. |
| 9 | **Ein Lock für alle GPU-Schritte** | Liegen Sprachmodell und Sprachsynthese gleichzeitig im Speicher, lagert Windows aus und alles wird 10–20× langsamer, ohne Fehlermeldung. |
| 10 | **Opus mit 24 kHz** | Das Konzept nannte 22,05 kHz; Opus kennt nur 8/12/16/24/48 kHz. |
| 11 | **Nur `arm64-v8a` im APK** | Halbiert die Paketgröße; Fire Tablets brauchen nichts anderes. |
| 12 | **Texterkennung als gebündelte Variante** | Fire OS hat keine Google-Play-Dienste. Das Modell muss im Paket stecken. |
| 13 | **Eigene Profile statt Amazon Kids** | Zwei konkurrierende Profilsysteme wären für Eltern verwirrend. |
| 14 | **Update über GitHub Releases** | Ohne App-Store braucht es einen eigenen Weg; die Releases-Seite ist im Browser des Tablets direkt erreichbar. |
| 15 | **Backend erst nach der Geräteabnahme** | Ein Server für eine App, die auf der Zielhardware noch nicht bewiesen ist, wäre Arbeit auf Verdacht. |
