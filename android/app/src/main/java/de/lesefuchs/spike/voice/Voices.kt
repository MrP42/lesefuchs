package de.lesefuchs.spike.voice

import android.content.Context
import java.io.File

/**
 * Die zehn Stimmen, zwischen denen in der App umgeschaltet werden kann.
 *
 * Zwei Arten:
 *  - [Kind.SYSTEM]: die Vorlesestimme des Geräts (Fire OS). Immer vorhanden,
 *    kein Download, keine externe KI.
 *  - [Kind.PIPER]: lokale neuronale Stimmen (sherpa-onnx). „thorsten" steckt
 *    im APK; die übrigen werden bei Bedarf einmalig heruntergeladen
 *    (~45 MB je Stimme, die gemeinsamen Sprachdaten liegen bereits im APK).
 *
 * Alles läuft auf dem Gerät — es wird zu keinem Zeitpunkt Text an einen
 * Dienst gesendet.
 */
data class Voice(
    val id: String,
    val name: String,
    val beschreibung: String,
    val kind: Kind,
    /** Nur PIPER: Dateiname des ZIPs im Stimmen-Release. */
    val asset: String? = null,
    /** Ungefähre Downloadgröße in MB (0 = nichts zu laden). */
    val downloadMb: Int = 0,
) {
    enum class Kind { SYSTEM, PIPER }

    val eingebaut: Boolean get() = id == BUNDLED_ID || kind == Kind.SYSTEM

    companion object { const val BUNDLED_ID = "thorsten" }
}

object VoiceRegistry {

    private const val RELEASE =
        "https://github.com/MrP42/lesefuchs/releases/download/stimmen-v1"

    val alle: List<Voice> = listOf(
        Voice("system", "Gerätestimme", "Die Vorlesestimme des Tablets — sofort verfügbar",
            Voice.Kind.SYSTEM),
        Voice(Voice.BUNDLED_ID, "Thorsten", "Ruhig und deutlich, in der App enthalten",
            Voice.Kind.PIPER),
        Voice("eva_k", "Eva", "Hell und freundlich, besonders sparsam", Voice.Kind.PIPER,
            "stimme-eva_k.zip", 17),
        Voice("kerstin", "Kerstin", "Warm, ruhiges Tempo", Voice.Kind.PIPER,
            "stimme-kerstin.zip", 55),
        Voice("ramona", "Ramona", "Klar und lebendig", Voice.Kind.PIPER,
            "stimme-ramona.zip", 55),
        Voice("karlsson", "Karlsson", "Tiefer, gemütlich", Voice.Kind.PIPER,
            "stimme-karlsson.zip", 55),
        Voice("pavoque", "Pavoque", "Kräftig, gut verständlich", Voice.Kind.PIPER,
            "stimme-pavoque.zip", 55),
        Voice("thorsten_low", "Thorsten leicht", "Wie Thorsten, schneller auf schwachen Geräten",
            Voice.Kind.PIPER, "stimme-thorsten_low.zip", 55),
        Voice("thorsten_emotional", "Thorsten lebhaft", "Mit mehr Ausdruck — gut für Geschichten",
            Voice.Kind.PIPER, "stimme-thorsten_emotional.zip", 67),
        Voice("miro", "Miro", "Jung und frisch", Voice.Kind.PIPER, "stimme-miro.zip", 55),
        Voice("dii", "Dii", "Sanft, gut zum Einschlafen", Voice.Kind.PIPER, "stimme-dii.zip", 55),
    )

    fun byId(id: String): Voice = alle.firstOrNull { it.id == id } ?: alle.first()

    fun downloadUrl(voice: Voice): String? = voice.asset?.let { "$RELEASE/$it" }

    /** Ordner einer heruntergeladenen Stimme. */
    fun dir(context: Context, voice: Voice): File =
        File(File(context.filesDir, "stimmen"), voice.id)

    /** Ist die Stimme einsatzbereit? */
    fun istBereit(context: Context, voice: Voice): Boolean = when {
        voice.kind == Voice.Kind.SYSTEM -> true
        voice.id == Voice.BUNDLED_ID -> true            // im APK
        else -> File(dir(context, voice), "model.onnx").isFile
    }
}
