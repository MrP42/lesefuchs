from lesefuchs_worker.steps.ingest import parse_document


def test_plaintext_single_chapter():
    doc = parse_document("Erster Absatz.\n\nZweiter Absatz\nmit Umbruch.\n")
    assert doc["title"] is None
    assert len(doc["chapters"]) == 1
    ch = doc["chapters"][0]
    assert ch["id"] == "ch01"
    assert ch["title"] == "Kapitel 1"
    assert ch["paragraphs"] == ["Erster Absatz.", "Zweiter Absatz mit Umbruch."]


def test_markdown_title_and_chapters():
    doc = parse_document(
        "# Der kleine Drache\n\n"
        "## Aufbruch\n\nAbsatz eins.\n\nAbsatz zwei.\n\n"
        "## Heimkehr\n\nAbsatz drei.\n"
    )
    assert doc["title"] == "Der kleine Drache"
    assert [c["title"] for c in doc["chapters"]] == ["Aufbruch", "Heimkehr"]
    assert doc["chapters"][0]["id"] == "ch01"
    assert doc["chapters"][1]["id"] == "ch02"
    assert doc["chapters"][1]["paragraphs"] == ["Absatz drei."]


def test_inline_markup_removed():
    doc = parse_document("Der **mutige** Fuchs las *leise* `Wörter`_._\n")
    assert doc["chapters"][0]["paragraphs"] == ["Der mutige Fuchs las leise Wörter_._"]


def test_underscore_inside_word_kept():
    doc = parse_document("Datei_name bleibt, _kursiv_ nicht.\n")
    assert doc["chapters"][0]["paragraphs"] == ["Datei_name bleibt, kursiv nicht."]


def test_empty_chapters_dropped():
    doc = parse_document("## Leer\n\n## Voll\n\nText.\n")
    assert [c["title"] for c in doc["chapters"]] == ["Voll"]
