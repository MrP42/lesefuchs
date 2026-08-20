package de.lesefuchs.spike.voice

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioTrack
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import android.util.Log
import java.io.File
import java.util.Locale
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/**
 * Vorlesen ohne vorbereitetes Audio — mit der in der App gewählten Stimme.
 *
 * Zwei Wege, beide vollständig auf dem Gerät:
 *  - Piper über sherpa-onnx (neuronale Stimme, klingt wie im fertigen Paket)
 *  - die Vorlesestimme des Geräts (kein Download nötig)
 *
 * Zeitgenauigkeit: Anders als bei vorgerenderten Paketen gibt es hier keine
 * gemessenen Wortzeiten. Die Satzdauer ist aber bekannt und wird nach
 * Silbenzahl auf die Wörter verteilt (Konzept §5.5) — das trägt ein
 * mitlaufendes Wort-Highlight, ist aber nicht millisekundengenau.
 */
class LiveSpeaker(private val context: Context) {

    interface Callback {
        /** Ein Satz beginnt: geschätzte Wortdauern in ms, Reihenfolge wie im Satz. */
        fun onSentenceStart(index: Int, wortDauern: LongArray)
        fun onFinished()
        fun onError(meldung: String)
    }

    private var tts: TextToSpeech? = null
    private var piper: com.k2fsa.sherpa.onnx.OfflineTts? = null
    private var track: AudioTrack? = null

    @Volatile private var abbrechen = false

    /** Bereitet die Stimme vor. Blockierend (Modell laden dauert). */
    fun vorbereiten(voice: Voice): Boolean = when (voice.kind) {
        Voice.Kind.SYSTEM -> vorbereitenSystem()
        Voice.Kind.PIPER -> vorbereitenPiper(voice)
    }

    private fun vorbereitenSystem(): Boolean {
        if (tts != null) return true
        val fertig = CountDownLatch(1)
        var ok = false
        tts = TextToSpeech(context) { status ->
            ok = status == TextToSpeech.SUCCESS
            fertig.countDown()
        }
        fertig.await(10, TimeUnit.SECONDS)
        if (ok) tts?.language = Locale.GERMAN else Log.i(TAG, "Geraetestimme nicht verfuegbar")
        return ok
    }

    private fun vorbereitenPiper(voice: Voice): Boolean {
        val ordner = VoiceManager.modellOrdner(context, voice) ?: return false
        val espeak = VoiceManager.espeakOrdner(context) ?: return false
        return try {
            val config = com.k2fsa.sherpa.onnx.OfflineTtsConfig(
                model = com.k2fsa.sherpa.onnx.OfflineTtsModelConfig(
                    vits = com.k2fsa.sherpa.onnx.OfflineTtsVitsModelConfig(
                        model = File(ordner, "model.onnx").absolutePath,
                        tokens = File(ordner, "tokens.txt").absolutePath,
                        dataDir = espeak.absolutePath,
                    ),
                    numThreads = 2,
                ),
            )
            piper = com.k2fsa.sherpa.onnx.OfflineTts(config = config)
            true
        } catch (t: Throwable) {
            Log.i(TAG, "Piper-Stimme laden fehlgeschlagen (" + voice.id + "): " + t.message)
            false
        }
    }

    /** Spricht die Sätze nacheinander. Blockierend — im Hintergrund aufrufen. */
    fun sprich(saetze: List<List<String>>, tempo: Float, callback: Callback) {
        abbrechen = false
        try {
            saetze.forEachIndexed { index, woerter ->
                if (abbrechen) return
                val text = woerter.joinToString(" ")
                val ok = if (piper != null) sprichPiper(text, tempo, woerter, index, callback)
                else sprichSystem(text, tempo, woerter, index, callback)
                if (!ok) return
            }
            if (!abbrechen) callback.onFinished()
        } catch (t: Throwable) {
            callback.onError(t.message ?: "Unbekannter Fehler")
        }
    }

    private fun sprichPiper(
        text: String, tempo: Float, woerter: List<String>, index: Int, callback: Callback,
    ): Boolean {
        val engine = piper ?: return false
        val audio = engine.generate(text = text, sid = 0, speed = tempo)
        if (abbrechen) return false
        val dauerMs = audio.samples.size * 1000L / audio.sampleRate
        callback.onSentenceStart(index, verteileDauer(woerter, dauerMs))
        spiele(audio.samples, audio.sampleRate)
        return !abbrechen
    }

    private fun sprichSystem(
        text: String, tempo: Float, woerter: List<String>, index: Int, callback: Callback,
    ): Boolean {
        val engine = tts ?: return false
        // Die Geraetestimme meldet keine verlaessliche Dauer -> aus Silben schaetzen
        val geschaetzt = woerter.sumOf { silben(it).toLong() } * (MS_JE_SILBE / tempo).toLong()
        callback.onSentenceStart(index, verteileDauer(woerter, geschaetzt))
        engine.setSpeechRate(tempo)
        val fertig = CountDownLatch(1)
        engine.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) {}
            override fun onDone(utteranceId: String?) = fertig.countDown()
            @Deprecated("Pflichtmethode der Basisklasse")
            override fun onError(utteranceId: String?) = fertig.countDown()
        })
        engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, "satz" + index)
        fertig.await(60, TimeUnit.SECONDS)
        return !abbrechen
    }

    private fun spiele(samples: FloatArray, rate: Int) {
        val pcm = ShortArray(samples.size) {
            (samples[it].coerceIn(-1f, 1f) * 32767).toInt().toShort()
        }
        val minPuffer = AudioTrack.getMinBufferSize(
            rate, AudioFormat.CHANNEL_OUT_MONO, AudioFormat.ENCODING_PCM_16BIT
        ).coerceAtLeast(pcm.size * 2)
        val at = AudioTrack.Builder()
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_MEDIA)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build()
            )
            .setAudioFormat(
                AudioFormat.Builder()
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                    .setSampleRate(rate)
                    .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                    .build()
            )
            .setBufferSizeInBytes(minPuffer)
            .build()
        track = at
        at.play()
        at.write(pcm, 0, pcm.size)
        at.stop()
        at.release()
        track = null
    }

    fun stoppen() {
        abbrechen = true
        runCatching { tts?.stop() }
        runCatching { track?.pause() }
        runCatching { track?.flush() }
    }

    fun freigeben() {
        stoppen()
        runCatching { tts?.shutdown() }
        tts = null
        runCatching { piper?.release() }
        piper = null
    }

    companion object {
        private const val TAG = "LesefuchsStimme"
        private const val MS_JE_SILBE = 210f

        /** Verteilt eine Satzdauer nach Silbenzahl auf die Wörter (Konzept §5.5). */
        fun verteileDauer(woerter: List<String>, gesamtMs: Long): LongArray {
            if (woerter.isEmpty()) return LongArray(0)
            val gewichte = woerter.map { silben(it) }
            val summe = gewichte.sum().coerceAtLeast(1)
            var rest = gesamtMs
            return LongArray(woerter.size) { i ->
                if (i == woerter.lastIndex) rest
                else (gesamtMs * gewichte[i] / summe).also { rest -= it }
            }
        }

        /** Silbenzahl über Vokalgruppen — für Deutsch ~95 % treffsicher. */
        fun silben(wort: String): Int =
            Regex("[aeiouyäöü]+", RegexOption.IGNORE_CASE).findAll(wort).count().coerceAtLeast(1)
    }
}
