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
 *   1. filesDir/inbox/                     (App-eigen: „Beispiel laden"/Import)
 *   2. /sdcard/Android/data/<pkg>/files/inbox/   (adb push ohne Freigabe)
 *   3. /sdcard/Lesefuchs/inbox/            (braucht All-Files-Access)
 *   4. /sdcard/Download/                   (Browser-Download, braucht Zugriff)
 *
 * Das ZIP wird nach cacheDir/paket/<name> entpackt (Media3 braucht Dateien).
 */
class LesepaketLoader(private val context: Context) {

    private val json = Json { ignoreUnknownKeys = true }

    /**
     * Suchorte in dieser Reihenfolge. Der erste ist der einzige, der ohne
     * jede Berechtigung und ohne PC befüllt werden kann — dorthin legen die
     * Schaltflächen „Beispiel laden" und „Datei öffnen" ihre Pakete.
     */
    fun inboxDirs(): List<File> = listOf(
        File(context.filesDir, "inbox"),
        File(context.getExternalFilesDir(null), "inbox"),
        File(Environment.getExternalStorageDirectory(), "Lesefuchs/inbox"),
        File(Environment.getExternalStorageDirectory(), "Download"),
    )

    /** Zielordner für Importe (immer beschreibbar). */
    fun importDir(): File = File(context.filesDir, "inbox").apply { mkdirs() }

    /**
     * Alle gefundenen Pakete mit ihren Metadaten — Grundlage der Bibliothek.
     * Liest nur `manifest.json` aus dem ZIP, ohne es zu entpacken; auch bei
     * vielen Geschichten bleibt der Start dadurch schnell.
     */
    fun listPackages(): List<LibraryEntry> {
        val gesehen = mutableSetOf<String>()
        return inboxDirs()
            .flatMap { dir -> dir.listFiles { f -> f.extension == "lesepaket" }?.toList() ?: emptyList() }
            .filter { gesehen.add(it.name) }   // gleicher Dateiname nur einmal
            .mapNotNull { file ->
                runCatching {
                    ZipFile(file).use { zf ->
                        val entry = zf.getEntry("manifest.json") ?: return@use null
                        val manifest = json.decodeFromString<Manifest>(
                            zf.getInputStream(entry).bufferedReader(Charsets.UTF_8).readText()
                        )
                        LibraryEntry(file, manifest)
                    }
                }.onFailure { Log.i(TAG, "Paket unlesbar: ${file.name} (${it.message})") }
                    .getOrNull()
            }
            .sortedBy { it.manifest.title.lowercase() }
    }

    /** Erster Ordner der Suchreihenfolge, der ein Paket enthält, gewinnt. */
    fun findFirstPackage(): File? = inboxDirs()
        .asSequence()
        .mapNotNull { dir ->
            dir.listFiles { f -> f.extension == "lesepaket" }?.toList()
                ?.takeIf { it.isNotEmpty() }?.minByOrNull { it.name }
        }
        .firstOrNull()

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
