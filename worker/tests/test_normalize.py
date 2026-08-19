from lesefuchs_worker.steps.normalize import (
    expand_abbreviations,
    expand_dates,
    expand_numbers,
    expand_units,
    normalize_paragraph,
    speak_number,
)


def test_abbreviations():
    assert expand_abbreviations("Tiere, z. B. Füchse, u. a. nachts") == \
        "Tiere, zum Beispiel Füchse, unter anderem nachts"
    assert expand_abbreviations("Dr. Meier wohnt in der Waldstr. 3") == \
        "Doktor Meier wohnt in der Waldstraße 3"
    assert expand_abbreviations("50 % Rabatt") == "50 Prozent Rabatt"
    assert expand_abbreviations("Bücher etc. nach Abs. 3") == "Bücher et cetera nach Absatz 3"


def test_dates_dative_and_nominative():
    assert expand_dates("am 3. Mai beginnt es") == "am dritten Mai beginnt es"
    assert expand_dates("Der 1. Oktober war schön") == "Der erste Oktober war schön"


def test_units():
    assert expand_units("Der Weg ist 12 km lang") == "Der Weg ist 12 Kilometer lang"
    assert expand_units("2,5 l Wasser") == "2,5 Liter Wasser"


def test_numbers_with_srcmap():
    text, src = expand_numbers("Der Drache hat 12 Berge gesehen.")
    assert text == "Der Drache hat zwölf Berge gesehen."
    assert src == {3: "12"}


def test_number_with_punctuation_suffix():
    text, src = expand_numbers("Es waren 7.")
    assert text == "Es waren sieben."
    assert src == {2: "7"}


def test_decimal_number():
    assert speak_number("3,5") == "drei Komma fünf"
    assert speak_number("3,05") == "drei Komma null fünf"


def test_year_reading():
    assert speak_number("1984") == "neunzehnhundertvierundachtzig"
    assert speak_number("1200") == "zwölfhundert"
    assert speak_number("2024") == "zweitausendvierundzwanzig"


def test_thousands_separator():
    assert speak_number("1.250") == "eintausendzweihundertfünfzig"


def test_full_paragraph_deterministic():
    text, src = normalize_paragraph("Am 3. Mai lief der Fuchs 2 km, z. B. durch den Wald.")
    assert text == "Am dritten Mai lief der Fuchs zwei Kilometer, zum Beispiel durch den Wald."
    # "2" → "zwei" an Wortindex 6 (Am₀ dritten₁ Mai₂ lief₃ der₄ Fuchs₅ zwei₆)
    assert src == {6: "2"}
    # zweiter Lauf identisch (deterministisch)
    assert normalize_paragraph("Am 3. Mai lief der Fuchs 2 km, z. B. durch den Wald.") == (text, src)
