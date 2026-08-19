package de.lesefuchs.spike.sync

import de.lesefuchs.spike.pkg.Content
import de.lesefuchs.spike.pkg.Token

/**
 * Selbstprüfung der Highlight-Synchronität (Abnahme Punkt 4, automatisiert).
 *
 * Idee: Während der Wiedergabe wird alle 250 ms verglichen, welchen Token die
 * optimierte [HighlightEngine] (Cache-Index + Binärsuche bei Sprüngen) liefert
 * und welchen eine stumpfe Referenzsuche über `content.json` liefert. Damit ist
 * ein Engine-Fehler von der geräteabhängigen Audio-Puffer-Latenz getrennt:
 * Diese Prüfung misst NUR die Engine — die Puffer-Latenz bleibt der manuelle
 * Lead-Offset-Punkt der Abnahme.
 *
 * Gemessen wird ohne Lead-Offset (rohe Player-Position).
 */
class SyncSelfCheck(private val content: Content, private val tokens: List<Token>) {

    private val engine = HighlightEngine(content.copy(tokens = tokens))

    var samples = 0; private set
    var mismatches = 0; private set
    /** Samples, bei denen die Position in keinem Token liegt (Pausen). */
    var pauseSamples = 0; private set
    /** Größte Pause zwischen zwei Tokens, die dabei beobachtet wurde. */
    var maxPauseMs = 0L; private set
    private var deviationSum = 0L
    var maxDeviationMs = 0L; private set
    private val mismatchLog = mutableListOf<String>()

    val meanDeviationMs: Double get() = if (samples == 0) 0.0 else deviationSum.toDouble() / samples

    /** Ein Messpunkt. posMs = rohe Player-Position im Kapitel. */
    fun sample(posMs: Long) {
        samples++
        val expectedIdx = referenceIndex(posMs)
        val actual = engine.stateAt(posMs)?.token
        val expected = tokens.getOrNull(expectedIdx)

        if (expected == null || actual == null) {
            mismatches++
            return
        }
        // Abweichung = zeitlicher Abstand zwischen hervorgehobenem und
        // erwartetem Token (0, wenn identisch)
        val deviation = kotlin.math.abs(actual.t0 - expected.t0)
        deviationSum += deviation
        if (deviation > maxDeviationMs) maxDeviationMs = deviation
        if (actual.i != expected.i) {
            mismatches++
            if (mismatchLog.size < 5) {
                mismatchLog += "pos=${posMs} erwartet=${expected.i}(${expected.w}) " +
                        "engine=${actual.i}(${actual.w})"
            }
        }

        // Pausen: Position liegt hinter dem Ende des Tokens (kein Wort aktiv)
        if (posMs > expected.t1) {
            pauseSamples++
            val next = tokens.getOrNull(expectedIdx + 1)
            if (next != null) {
                val gap = next.t0 - expected.t1
                if (gap > maxPauseMs) maxPauseMs = gap
            }
        }
    }

    fun mismatchExamples(): List<String> = mismatchLog

    /** Referenz: stumpfe Suche, letzter Token mit t0 <= posMs (0, wenn davor). */
    private fun referenceIndex(posMs: Long): Int {
        var idx = 0
        for (i in tokens.indices) {
            if (tokens[i].t0 <= posMs) idx = i else break
        }
        return idx
    }
}
