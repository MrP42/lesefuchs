# Beispielinhalt

`finn-fuchs-und-der-sternenwald_v1.lesepaket` — die Geschichte, die
„Beispielgeschichte laden" in der App holt. Sie hängt an jedem Release, damit
ein frisch eingerichtetes Tablet ohne PC sofort etwas zu hören hat.

Erzeugt mit dem Render-Worker aus `worker/examples/beispiel.md`
(2 Kapitel, 611 Wörter, 247 s, Opus 24 kHz). Neu erzeugen:

```bash
cd worker && make demo
cp out/*.lesepaket ../content/
```
