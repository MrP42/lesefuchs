# Werkzeuge

## `git-hooks/post-commit` — automatische Sicherung

Schiebt jeden Commit sofort zum Remote (GitHub), damit nichts nur auf der
lokalen Platte liegt. Der Push läuft im Hintergrund; ein fehlendes Netz hält
das Committen nicht auf, der Versuch steht dann als Fehler in
`.git/autopush.log`.

Aktivieren (einmalig je Arbeitskopie, auch nach einem frischen Klon):

```bash
git config core.hooksPath tools/git-hooks
```

Prüfen:

```bash
git config core.hooksPath        # -> tools/git-hooks
tail -5 .git/autopush.log        # letzte Sicherungen
```

Abschalten: `git config --unset core.hooksPath`.

Wurde ein Push versäumt (kein Netz), holt ein einfaches `git push` alles nach.

## Ein Release veröffentlichen

Die Tablet-App wird über GitHub Releases verteilt — das ist zugleich der
Update-Weg der App (Konzept §8.3).

1. `CHANGELOG.md` ergänzen: den Abschnitt „Unveröffentlicht" in die neue
   Versionsnummer umbenennen.
2. Tag setzen und schieben:

```bash
git tag v0.1.1
git push origin v0.1.1
```

Der Workflow [`release.yml`](../.github/workflows/release.yml) baut daraufhin
die signierte APK (lädt das Piper-Sprachmodell selbst nach), hängt sie als
`lesefuchs-<version>.apk` an ein Release und schreibt eine kurze
Installationsanleitung in die Release-Notiz. Ohne Tag geht es auch:
*Actions → Release → Run workflow* mit Versionsangabe.

Die Versionsnummer wird an Gradle durchgereicht (`versionName`), der
`versionCode` daraus berechnet (`1.2.3` → `10203`), damit Android jede neue
Version als Aktualisierung erkennt.

### Signaturschlüssel

Alle Releases werden mit **demselben** Schlüssel signiert — sonst verweigert
Android die Installation über eine vorhandene App („App nicht installiert").

- Schlüssel und Passwörter liegen außerhalb des Repos unter
  `%USERPROFILE%\tools\lesefuchs-signing\` (`lesefuchs-release.jks`,
  `passwoerter.txt`).
- In GitHub liegen sie als Secrets `KEYSTORE_BASE64`, `KEYSTORE_PASSWORD`,
  `KEY_ALIAS`, `KEY_PASSWORD`.
- **Diesen Ordner sichern.** Geht der Schlüssel verloren, lässt sich keine
  bestehende Installation mehr aktualisieren — die App müsste dann auf jedem
  Tablet deinstalliert und neu eingerichtet werden.

Lokale Builds brauchen den Schlüssel nicht; ohne ihn wird mit dem
Debug-Schlüssel signiert (nicht für Releases geeignet).
