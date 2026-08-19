"""Erzeugt das OCR-Testbild für den Spike (2a) — einmalig ausgeführt.

Rendert seite.txt als Buchseite nach seite.png (1600 px lange Kante, Andika,
schwarz auf weiß). Deutscher Fließtext mit Umlauten, ß, Anführungszeichen und
Ziffern — genau die Zeichenklassen, an denen OCR auf Fire-Tablets scheitert.

Aufruf (Pillow steckt transitiv im Worker-venv):
    ../../worker/.venv/Scripts/python make_testpage.py
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

HERE = Path(__file__).parent
FONT = HERE.parent / "app/src/main/res/font/andika_regular.ttf"
WIDTH = 1200
MARGIN = 90
# Erstlesebuch-Typografie: große Schrift, weiter Zeilenabstand. Höhe ergibt
# sich aus dem Text (lange Kante landet dadurch bei ~1600 px).
FONT_SIZE = 44
LINE_HEIGHT = 74


def main() -> None:
    text = (HERE / "seite.txt").read_text(encoding="utf-8")
    font = ImageFont.truetype(str(FONT), FONT_SIZE)
    measure = ImageDraw.Draw(Image.new("RGB", (1, 1)))

    blocks = [wrap(p.replace("\n", " "), font, WIDTH - 2 * MARGIN, measure)
              for p in text.strip().split("\n\n")]
    height = MARGIN * 2 + sum(len(b) * LINE_HEIGHT + LINE_HEIGHT // 2 for b in blocks)

    img = Image.new("RGB", (WIDTH, height), "white")
    draw = ImageDraw.Draw(img)
    y = MARGIN
    for block in blocks:
        for line in block:
            draw.text((MARGIN, y), line, font=font, fill="black")
            y += LINE_HEIGHT
        y += LINE_HEIGHT // 2

    img.save(HERE / "seite.png", optimize=True)
    print(f"seite.png geschrieben ({WIDTH}x{height})")


def wrap(paragraph: str, font, max_width: int, draw) -> list[str]:
    lines, current = [], ""
    for word in paragraph.split():
        probe = f"{current} {word}".strip()
        if draw.textlength(probe, font=font) <= max_width:
            current = probe
        else:
            lines.append(current)
            current = word
    if current:
        lines.append(current)
    return lines


if __name__ == "__main__":
    main()
