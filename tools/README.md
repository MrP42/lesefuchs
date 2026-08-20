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
