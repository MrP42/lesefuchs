from lesefuchs_worker.steps.syllables import (
    core_word,
    syllabify,
    syllable_timings,
    syllable_weight,
)


def test_core_word_strips_punctuation():
    assert core_word("„Bäume!“") == "Bäume"
    assert core_word("Fuchs,") == "Fuchs"
    assert core_word("...") == ""


def test_syllabify_german():
    assert syllabify("Kokosnuss") == ["Ko", "kos", "nuss"]
    assert syllabify("Drache") == ["Dra", "che"]
    assert syllabify("Haus") == ["Haus"]
    assert syllabify("Bäume,") == ["Bäu", "me"]


def test_weight_vowels_double():
    assert syllable_weight("Ko") == 3      # 2 Zeichen + 1 Vokal
    assert syllable_weight("nuss") == 5    # 4 Zeichen + 1 Vokal
    assert syllable_weight("Bäu") == 5     # 3 Zeichen + 2 Vokale (ä, u)


def test_timings_cover_word_exactly():
    syls = syllable_timings("Kokosnuss", 41250, 41980)
    assert [s["s"] for s in syls] == ["Ko", "kos", "nuss"]
    assert syls[0]["t0"] == 41250
    assert syls[-1]["t1"] == 41980
    # lückenlos
    for a, b in zip(syls, syls[1:]):
        assert a["t1"] == b["t0"]
    # längere Silbe dauert länger
    assert (syls[2]["t1"] - syls[2]["t0"]) > (syls[0]["t1"] - syls[0]["t0"])


def test_single_syllable_word():
    assert syllable_timings("Haus", 0, 400) == [{"s": "Haus", "t0": 0, "t1": 400}]
