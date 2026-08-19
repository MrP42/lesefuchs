from lesefuchs_worker.steps.optimize import (
    build_llm_diff,
    build_prompt,
    optimize_paragraphs,
    split_into_paragraphs,
)


def test_llm_diff_only_changed_paragraphs():
    orig = {"chapters": [{"id": "ch01", "paragraphs": ["Gleich.", "Alt und krumm."]}]}
    opt = {"chapters": [{"id": "ch01", "paragraphs": [{"text": "Gleich."}, {"text": "Neu und gerade."}]}]}
    diff = build_llm_diff(orig, opt)
    assert "absatz001" in diff and "absatz000" not in diff
    assert "-Alt und krumm." in diff and "+Neu und gerade." in diff


def test_llm_diff_no_changes():
    doc = {"chapters": [{"id": "ch01", "paragraphs": ["Gleich."]}]}
    assert build_llm_diff(doc, doc) == "Keine Absätze verändert.\n"


def test_prompt_contains_rules_and_level():
    prompt = build_prompt("Hallo Welt", 1)
    assert "{" not in prompt.replace("{max_woerter}", "")  # alles gefüllt
    assert "mehr als 8 Wörtern" in prompt
    assert "# Eingabetext\nHallo Welt" in prompt


def test_split_paragraphs_strips_and_joins_linebreaks():
    assert split_into_paragraphs("Eins\nzwei.\n\n  Drei.  \n\n") == ["Eins zwei.", "Drei."]


def test_chapter_optimization_keeps_paragraph_count():
    def chat(prompt):
        return "Absatz eins neu.\n\nAbsatz zwei neu."

    out = optimize_paragraphs(["Absatz eins.", "Absatz zwei."], chat, 2)
    assert out == ["Absatz eins neu.", "Absatz zwei neu."]


def test_paragraph_count_mismatch_falls_back_to_single_calls():
    calls = []

    def chat(prompt):
        calls.append(prompt)
        if len(calls) == 1:
            return "Alles in einem Absatz."  # Kapitel-Aufruf: falsche Absatzzahl
        return "Einzeln optimiert."

    out = optimize_paragraphs(["A.", "B."], chat, 2)
    assert out == ["Einzeln optimiert.", "Einzeln optimiert."]
    assert len(calls) == 3  # 1 Kapitel + 2 Einzelabsätze


def test_chat_failure_keeps_original():
    out = optimize_paragraphs(["Original bleibt."], lambda p: None, 2)
    assert out == ["Original bleibt."]
