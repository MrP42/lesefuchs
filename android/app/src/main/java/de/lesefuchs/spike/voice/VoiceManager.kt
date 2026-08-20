package de.lesefuchs.spike.voice

import android.content.Context
import android.util.Log
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.util.zip.ZipFile

/** Herunterladen, Entpacken und Merken der gewählten Stimme. */
object VoiceManager {

    private const val TAG = "LesefuchsStimme"
    private const val PREFS = "lesefuchs"
    private const val KEY_VOICE = "gewaehlte_stimme"

    fun gewaehlte(context: Context): Voice =
        VoiceRegistry.byId(
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .getString(KEY_VOICE, Voice.BUNDLED_ID) ?: Voice.BUNDLED_ID
        )

    fun waehle(context: Context, voice: Voice) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putString(KEY_VOICE, voice.id).apply()
    }

    /** Lädt und entpackt eine Stimme. Blockierend; true bei Erfolg. */
    fun installiere(context: Context, voice: Voice, onProgress: (Float) -> Unit = {}): Boolean {
        if (VoiceRegistry.istBereit(context, voice)) return true
        val url = VoiceRegistry.downloadUrl(voice) ?: return false
        val ziel = VoiceRegistry.dir(context, voice).apply { mkdirs() }
        val zip = File(ziel, "download.zip")
        return try {
            (URL(url).openConnection() as HttpURLConnection).apply {
                instanceFollowRedirects = true
                connectTimeout = 15_000
                readTimeout = 60_000
            }.let { conn ->
                try {
                    val gesamt = conn.contentLengthLong
                    conn.inputStream.use { input ->
                        zip.outputStream().use { out ->
                            val puffer = ByteArray(64 * 1024)
                            var summe = 0L
                            while (true) {
                                val gelesen = input.read(puffer)
                                if (gelesen < 0) break
                                out.write(puffer, 0, gelesen)
                                summe += gelesen
                                if (gesamt > 0) onProgress(summe.toFloat() / gesamt)
                            }
                        }
                    }
                } finally { conn.disconnect() }
            }
            ZipFile(zip).use { zf ->
                zf.entries().asSequence().filterNot { it.isDirectory }.forEach { eintrag ->
                    val name = File(eintrag.name).name          // kein Pfad aus dem Archiv
                    val datei = File(ziel, name)
                    zf.getInputStream(eintrag).use { input ->
                        datei.outputStream().use { input.copyTo(it) }
                    }
                }
            }
            zip.delete()
            val ok = File(ziel, "model.onnx").isFile && File(ziel, "tokens.txt").isFile
            Log.i(TAG, "installiert: ${voice.id} -> $ok")
            if (!ok) ziel.deleteRecursively()
            ok
        } catch (t: Throwable) {
            Log.i(TAG, "Installation fehlgeschlagen (${voice.id}): ${t.message}")
            ziel.deleteRecursively()
            false
        }
    }

    fun entferne(context: Context, voice: Voice) {
        if (!voice.eingebaut) VoiceRegistry.dir(context, voice).deleteRecursively()
    }

    /** Ordner mit model.onnx/tokens.txt der Stimme (APK-Stimme wird ausgepackt). */
    fun modellOrdner(context: Context, voice: Voice): File? = when {
        voice.kind == Voice.Kind.SYSTEM -> null
        voice.id == Voice.BUNDLED_ID -> entpackteApkStimme(context)
        else -> VoiceRegistry.dir(context, voice).takeIf { File(it, "model.onnx").isFile }
    }

    /** Die gemeinsamen Sprachdaten (espeak) liegen im APK und gelten für alle Stimmen. */
    fun espeakOrdner(context: Context): File? =
        entpackteApkStimme(context)?.let { File(it, "espeak-ng-data") }?.takeIf { it.isDirectory }

    private fun entpackteApkStimme(context: Context): File? {
        val ziel = File(context.filesDir, "piper-de")
        if (File(ziel, ".complete").isFile) return ziel
        return try {
            kopiereAssets(context, "piper-de", ziel)
            File(ziel, ".complete").writeText("ok")
            ziel
        } catch (t: Throwable) {
            Log.i(TAG, "APK-Stimme entpacken fehlgeschlagen: ${t.message}")
            null
        }
    }

    private fun kopiereAssets(context: Context, pfad: String, ziel: File) {
        val kinder = context.assets.list(pfad) ?: emptyArray()
        if (kinder.isEmpty()) {
            ziel.parentFile?.mkdirs()
            context.assets.open(pfad).use { input -> ziel.outputStream().use { input.copyTo(it) } }
        } else {
            ziel.mkdirs()
            kinder.forEach { kopiereAssets(context, "$pfad/$it", File(ziel, it)) }
        }
    }
}
