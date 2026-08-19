"""Schritt 3: Deterministische Normalisierung (03_normalized.json).

Konzept §4.5: Zahlen, Daten, Abkürzungen NICHT dem LLM überlassen —
deterministisch expandieren. Läuft nach `optimize` (liest 02, sonst 01).

Regeln:
  1. Abkürzungstabelle (Regex-Pass, kein src-Mapping — reine Sprechform)
  2. Datumsangaben  "3. Mai"        → "dritter Mai"
  3. Einheiten      "12 km"         → "12 Kilometer"   (Zahl folgt in Regel 4)
  4. Zahl-Tokens    "12" → "zwölf", "3,5" → "drei Komma fünf",
                    Jahreszahlen 1100–1999 → "neunzehnhundert…"
     → src-Mapping: erster gesprochener Ersatz-Token trägt das Original
       (Konzept §4.4: Anzeige "12", gelesen "zwölf").

Ausgabeformat je Absatz: {"text": str, "src": {spoken_word_index: original}}
"""
from __future__ import annotations

import re

from num2words import num2words

from ..job import Job

ARTIFACT = "03_normalized.json"

# Sprechformen häufiger Abkürzungen (Wortgrenzen beachtet, Punkt inklusive)
ABBREVIATIONS: list[tuple[str, str]] = [
    (r"z\.\s?B\.", "zum Beispiel"),
    (r"u\.\s?a\.", "unter anderem"),
    (r"d\.\s?h\.", "das heißt"),
    (r"o\.\s?ä\.", "oder ähnliches"),
    (r"u\.\s?v\.\s?m\.", "und vieles mehr"),
    (r"\busw\.", "und so weiter"),
    (r"\betc\.", "et cetera"),
    (r"\bbzw\.", "beziehungsweise"),
    (r"\bAbs\.", "Absatz"),
    (r"\bca\.", "circa"),
    (r"\bevtl\.", "eventuell"),
    (r"\bggf\.", "gegebenenfalls"),
    (r"\bNr\.", "Nummer"),
    (r"\bDr\.", "Doktor"),
    (r"\bProf\.", "Professor"),
    (r"\b([A-ZÄÖÜ][a-zäöüß]*[Ss]tr|Str)\.", r"\1aße"),  # Str. und Waldstr.
    (r"\bMio\.", "Millionen"),
    (r"\bMrd\.", "Milliarden"),
    (r"\s%", " Prozent"),
    (r"(?<=\d)\s?€", " Euro"),
    (r"&", "und"),
]

UNITS: dict[str, str] = {
    "km": "Kilometer", "m": "Meter", "cm": "Zentimeter", "mm": "Millimeter",
    "kg": "Kilogramm", "g": "Gramm", "t": "Tonnen",
    "l": "Liter", "ml": "Milliliter",
    "min": "Minuten", "h": "Stunden", "s": "Sekunden",
}

MONTHS = ("Januar|Februar|März|April|Mai|Juni|Juli|August|"
          "September|Oktober|November|Dezember")


def run(job: Job, force: bool = False) -> None:
    source = "02_optimized.json" if job.path("02_optimized.json").exists() else "01_paragraphs.json"
    input_hash = job.hash_of(source)
    if not force and job.step_done("normalize", input_hash):
        print("  normalize: unverändert, übersprungen")
        return

    doc = job.read_json(source)
    out_chapters = []
    replaced = 0
    for chapter in doc["chapters"]:
        paragraphs = []
        for para in chapter["paragraphs"]:
            text = para["text"] if isinstance(para, dict) else para
            normalized, srcmap = normalize_paragraph(text)
            replaced += len(srcmap)
            paragraphs.append({"text": normalized, "src": {str(k): v for k, v in srcmap.items()}})
        out_chapters.append({"id": chapter["id"], "title": chapter["title"], "paragraphs": paragraphs})

    job.write_json(ARTIFACT, {"title": doc["title"], "chapters": out_chapters})
    job.mark_step("normalize", input_hash, source=source, number_replacements=replaced)
    print(f"  normalize: {replaced} Zahl-Ersetzungen (Quelle: {source})")


# ---- reine Funktionen (einzeln testbar) ---------------------------------

def normalize_paragraph(text: str) -> tuple[str, dict[int, str]]:
    """Normalisiert einen Absatz. Liefert (Text, {Wortindex: Original})."""
    text = expand_abbreviations(text)
    text = expand_dates(text)
    text = expand_units(text)
    return expand_numbers(text)


def expand_abbreviations(text: str) -> str:
    for pattern, spoken in ABBREVIATIONS:
        text = re.sub(pattern, spoken, text)
    return re.sub(r"\s+", " ", text).strip()


def expand_dates(text: str) -> str:
    """"am 3. Mai" → "am dritten Mai"; "Der 3. Mai" → "Der dritte Mai"."""
    def repl(m: re.Match) -> str:
        day = int(m.group(1))
        # Dativ ("am dritten") nach Präposition, sonst Nominativ ("der dritte")
        prev = text[: m.start()].rstrip().rsplit(" ", 1)[-1].lower() if m.start() else ""
        dative = prev in {"am", "vom", "zum", "seit", "ab", "bis"}
        word = num2words(day, lang="de", to="ordinal")
        word += "n" if dative else ""
        return f"{word} {m.group(2)}"

    return re.sub(rf"\b(\d{{1,2}})\.\s+({MONTHS})\b", repl, text)


def expand_units(text: str) -> str:
    def repl(m: re.Match) -> str:
        return f"{m.group(1)} {UNITS[m.group(2)]}"

    pattern = r"\b(\d+(?:,\d+)?)\s?(" + "|".join(UNITS) + r")\b"
    return re.sub(pattern, repl, text)


def expand_numbers(text: str) -> tuple[str, dict[int, str]]:
    """Ersetzt Zahl-Tokens durch Zahlwörter; src-Map auf Wortindex des Ergebnisses."""
    out_words: list[str] = []
    srcmap: dict[int, str] = {}
    for token in text.split(" "):
        m = re.match(r"^(\D*?)(\d+(?:\.\d{3})*(?:,\d+)?)(\D*)$", token)
        if not m:
            out_words.append(token)
            continue
        prefix, number, suffix = m.groups()
        spoken = speak_number(number)
        words = spoken.split(" ")
        # Original nur am ersten gesprochenen Wort verankern (Anzeige-Token)
        srcmap[len(out_words)] = number
        words[0] = prefix + words[0]
        words[-1] = words[-1] + suffix
        out_words.extend(words)
    return " ".join(out_words), srcmap


def speak_number(number: str) -> str:
    """"12" → "zwölf", "1.250" → "eintausendzweihundertfünfzig",
    "3,5" → "drei Komma fünf", 1100–1999 → "…hundert…" (Jahreszahl-Lesart)."""
    had_separator = "." in number
    plain = number.replace(".", "")
    if "," in plain:
        integer, decimals = plain.split(",", 1)
        digits = " ".join(num2words(int(d), lang="de") for d in decimals)
        return f"{num2words(int(integer), lang='de')} Komma {digits}"
    value = int(plain)
    # Jahreszahl-Lesart nur ohne Tausenderpunkt ("1.250" ist eine Menge)
    if not had_separator and 1100 <= value <= 1999:
        hundreds, rest = divmod(value, 100)
        spoken = num2words(hundreds, lang="de") + "hundert"
        if rest:
            spoken += num2words(rest, lang="de")
        return spoken
    return num2words(value, lang="de")
