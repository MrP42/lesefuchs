package de.lesefuchs.spike.sync

import de.lesefuchs.spike.pkg.Content
import de.lesefuchs.spike.pkg.Sentence
import de.lesefuchs.spike.pkg.Syllable
import de.lesefuchs.spike.pkg.Token

/**
 * Sync-Engine (Konzept §5.3): Audioposition → aktiver Satz/Wort/Silbe.
 *
 * Lineare Suche ab dem zuletzt gefundenen Index (Normalfall: nächstes Wort),
 * Binärsuche bei Sprüngen (Seek, Wort-Tap). In Pausen zwischen Wörtern bleibt
 * der Cursor auf dem letzten Wort stehen (Konzept §4.4).
 */
class HighlightEngine(private val content: Content) {

    data class HighlightState(
        val sentence: Sentence,
        val token: Token,
        val syllable: Syllable?,
        val cursorFraction: Float,
        /** false in Pausen: posMs liegt hinter t1 des Tokens. */
        val tokenActive: Boolean,
    )

    private var cachedIdx = 0

    fun stateAt(posMs: Long): HighlightState? {
        val tokens = content.tokens
        if (tokens.isEmpty()) return null

        val idx = findToken(posMs)
        val tok = tokens[idx]
        val active = posMs >= tok.t0 && posMs < tok.t1
        val syl = if (active) tok.syl.firstOrNull { posMs >= it.t0 && posMs < it.t1 } else null

        val frac = syl?.let {
            val len = (it.t1 - it.t0).coerceAtLeast(1)
            ((posMs - it.t0).toFloat() / len).coerceIn(0f, 1f)
        } ?: if (active) 0f else 1f

        return HighlightState(
            sentence = content.sentences[tok.sent],
            token = tok,
            syllable = syl,
            cursorFraction = frac,
            tokenActive = active,
        )
    }

    /** Index des letzten Tokens mit t0 <= posMs (0, wenn davor). */
    private fun findToken(posMs: Long): Int {
        val tokens = content.tokens
        var idx = cachedIdx.coerceIn(0, tokens.size - 1)

        if (posMs >= tokens[idx].t0) {
            // vorwärts: Normalfall ist "gleiches oder nächstes Wort"
            var steps = 0
            while (idx + 1 < tokens.size && tokens[idx + 1].t0 <= posMs) {
                idx++
                if (++steps > LINEAR_LIMIT) { idx = binarySearch(posMs); break }
            }
        } else {
            // rückwärts gesprungen
            idx = binarySearch(posMs)
        }
        cachedIdx = idx
        return idx
    }

    private fun binarySearch(posMs: Long): Int {
        val tokens = content.tokens
        var lo = 0
        var hi = tokens.size - 1
        while (lo < hi) {
            val mid = (lo + hi + 1) / 2
            if (tokens[mid].t0 <= posMs) lo = mid else hi = mid - 1
        }
        return lo
    }

    companion object { private const val LINEAR_LIMIT = 32 }
}
