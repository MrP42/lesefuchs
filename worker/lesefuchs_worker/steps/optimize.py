"""Schritt 2: LLM-Vorlese-Optimierung über Ollama (02_optimized.json).

Prompt nach Konzept §6.3a. Schutzregeln:
  - Absatz-Erhalt wird erzwungen: Antwort muss dieselbe Absatzzahl haben,
    sonst Fallback auf Einzelabsatz-Aufrufe; bei erneutem Fehlschlag bleibt
    der Originalabsatz stehen (kein stilles Umschreiben, Konzept §10).
  - Ollama nicht erreichbar → Schritt wird MIT WARNUNG übersprungen
    (02 = Kopie von 01); die Pipeline blockiert nicht.
"""
from __future__ import annotations

import requests

from ..job import Job

ARTIFACT = "02_optimized.json"

MAX_WORDS_BY_LEVEL = {1: 8, 2: 12, 3: 16}

PROMPT_TEMPLATE = """# Rolle
Du bereitest Text für die Sprachsynthese in einer Kinder-Vorlese-App auf.

# Aufgabe
Überarbeite den Eingabetext so, dass er von einer TTS-Engine natürlich
vorgelesen werden kann. Der Inhalt bleibt inhaltlich identisch.

# Regeln
1. Entferne: Kopf- und Fußzeilen, Seitenzahlen, Fußnotenmarken,
   Trennstriche am Zeilenende (füge die Wörter zusammen).
2. Löse Abkürzungen auf: "z. B." → "zum Beispiel", "u. a." → "unter anderem".
3. Setze fehlende Satzzeichen, wenn OCR sie verschluckt hat.
4. Teile Sätze mit mehr als {max_woerter} Wörtern an natürlichen Stellen.
5. Direkte Rede bleibt wörtlich erhalten. Eigennamen bleiben unverändert.
6. Füge NICHTS hinzu, was nicht im Original steht. Kürze NICHTS weg.
7. Zahlen NICHT ausschreiben — das erledigt ein nachgelagerter Schritt.
8. Behalte die Absatzeinteilung exakt bei: gleich viele Absätze wie die Eingabe.

# Ausgabeformat
Nur der überarbeitete Text. Kein Kommentar, kein Markdown, keine Backticks.
Absätze durch Leerzeile getrennt.

# Lesestufe
{lesestufe}  (1 = Vorschule, 2 = 1./2. Klasse, 3 = 3./4. Klasse)

# Eingabetext
{text}"""


def run(job: Job, force: bool = False) -> None:
    input_hash = job.hash_of("01_paragraphs.json")
    if not force and job.step_done("optimize", input_hash):
        print("  optimize: unverändert, übersprungen")
        return

    doc = job.read_json("01_paragraphs.json")
    settings = job.settings

    if not ollama_available(settings.ollama_url):
        print(f"  optimize: WARNUNG — Ollama unter {settings.ollama_url} nicht erreichbar, "
              "Schritt übersprungen (Original wird weiterverwendet)")
        job.write_json(ARTIFACT, doc)
        job.mark_step("optimize", input_hash, skipped="ollama_unreachable")
        return

    chat = make_chat(settings)
    out_chapters = []
    changed = 0
    for chapter in doc["chapters"]:
        originals = [p["text"] if isinstance(p, dict) else p for p in chapter["paragraphs"]]
        optimized = optimize_paragraphs(originals, chat, settings.reading_level)
        changed += sum(1 for a, b in zip(originals, optimized) if a != b)
        out_chapters.append({"id": chapter["id"], "title": chapter["title"], "paragraphs": optimized})

    job.write_json(ARTIFACT, {"title": doc["title"], "chapters": out_chapters})
    job.mark_step("optimize", input_hash, model=settings.ollama_model, changed_paragraphs=changed)
    print(f"  optimize: {changed} Absätze überarbeitet ({settings.ollama_model})")


# ---- testbare Bausteine --------------------------------------------------

def build_prompt(text: str, reading_level: int) -> str:
    return PROMPT_TEMPLATE.format(
        max_woerter=MAX_WORDS_BY_LEVEL.get(reading_level, 12),
        lesestufe=reading_level,
        text=text,
    )


def split_into_paragraphs(answer: str) -> list[str]:
    parts = [p.strip().replace("\n", " ") for p in answer.strip().split("\n\n")]
    return [p for p in parts if p]


def optimize_paragraphs(paragraphs: list[str], chat, reading_level: int) -> list[str]:
    """Kapitelweise optimieren; bei Absatzzahl-Abweichung Einzelabsatz-Fallback."""
    joined = "\n\n".join(paragraphs)
    answer = chat(build_prompt(joined, reading_level))
    if answer is not None:
        result = split_into_paragraphs(answer)
        if len(result) == len(paragraphs):
            return result

    # Fallback: Absatz für Absatz; Fehlschlag → Original behalten
    out = []
    for para in paragraphs:
        answer = chat(build_prompt(para, reading_level))
        parts = split_into_paragraphs(answer) if answer is not None else []
        out.append(" ".join(parts) if parts else para)
    return out


def ollama_available(url: str) -> bool:
    try:
        return requests.get(f"{url}/api/tags", timeout=3).status_code == 200
    except requests.RequestException:
        return False


def make_chat(settings):
    """Liefert chat(prompt) -> Antworttext | None (bei Fehler)."""
    def chat(prompt: str) -> str | None:
        try:
            resp = requests.post(
                f"{settings.ollama_url}/api/chat",
                json={
                    "model": settings.ollama_model,
                    "messages": [{"role": "user", "content": prompt}],
                    "stream": False,
                    "options": {"temperature": 0.2},
                },
                timeout=settings.ollama_timeout_s,
            )
            resp.raise_for_status()
            return resp.json()["message"]["content"]
        except (requests.RequestException, KeyError, ValueError) as e:
            print(f"  optimize: Ollama-Fehler: {e}")
            return None

    return chat
