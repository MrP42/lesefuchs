package de.lesefuchs.spike.pkg

import android.content.Context
import android.os.Environment
import android.util.Log
import kotlinx.serialization.json.Json
import java.io.File
import java.util.zip.ZipFile

/**
 * Lädt das erste .lesepaket aus der Inbox (kein UI, Spike-Umfang).
 *
 * Suchreihenfolge:
 *   1. /sdcard/Lesefuchs/inbox/            (braucht All-Files-Access, README)
 *   2. /sdcard/Android/data/<pkg>/files/inbox/   (adb push ohne Freigabe)
 *
 * Das ZIP wird nach cacheDir/paket/<name> entpackt (Media3 braucht Dateien).
 */
class LesepaketLoader(private val context: Context) {

    private val json = Json { ignoreUnknownKeys = true }

    fun inboxDirs(): List<File> = listOf(
        File(Environment.getExternalStorageDirectory(), "Lesefuchs/inbox"),
        File(context.getExternalFilesDir(null), "inbox"),
    )

    fun findFirstPackage(): File? = inboxDirs()
        .flatMap { dir -> dir.listFiles { f -> f.extension == "lesepaket" }?.toList() ?: emptyList() }
        .minByOrNull { it.name }

    fun load(zipFile: File): Lesepaket {
        val target = File(context.cacheDir, "paket/${zipFile.nameWithoutExtension}")
        if (!File(target, "manifest.json").isFile) {
            target.deleteRecursively()
            target.mkdirs()
            unzip(zipFile, target)
        }

        val manifest = json.decodeFromString<Manifest>(
            File(target, "manifest.json").readText(Charsets.UTF_8)
        )
        val content = json.decodeFromString<Content>(
            File(target, "content.json").readText(Charsets.UTF_8)
        )
        val audio = manifest.chapters.associate { ch ->
            ch.id to File(target, ch.audio).also {
                require(it.isFile) { "Audio fehlt im Paket: ${ch.audio}" }
            }
        }
        Log.i(TAG, "Paket geladen: ${manifest.title} — ${content.tokens.size} Tokens, " +
                "${manifest.chapters.size} Kapitel")
        return Lesepaket(manifest, content, audio)
    }

    private fun unzip(zip: File, target: File) {
        ZipFile(zip).use { zf ->
            zf.entries().asSequence().forEach { entry ->
                val out = File(target, entry.name)
                // Zip-Slip-Schutz
                require(out.canonicalPath.startsWith(target.canonicalPath + File.separator)) {
                    "Unzulässiger Pfad im Paket: ${entry.name}"
                }
                if (entry.isDirectory) {
                    out.mkdirs()
                } else {
                    out.parentFile?.mkdirs()
                    zf.getInputStream(entry).use { input ->
                        out.outputStream().use { input.copyTo(it) }
                    }
                }
            }
        }
    }

    companion object { private const val TAG = "LesepaketLoader" }
}
