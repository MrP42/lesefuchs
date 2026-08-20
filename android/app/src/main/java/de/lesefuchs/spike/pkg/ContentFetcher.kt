package de.lesefuchs.spike.pkg

import android.content.Context
import android.net.Uri
import android.util.Log
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * Beschafft Lesepakete ohne PC:
 *  - [fetchDemo] lädt die Beispielgeschichte aus dem GitHub-Release,
 *  - [importFrom] übernimmt eine per Systemdialog gewählte Datei.
 *
 * Beides landet in einem app-eigenen Ordner, für den keine Berechtigung
 * nötig ist — das ist auf Fire OS der einzige verlässliche Weg.
 */
object ContentFetcher {

    private const val TAG = "LesefuchsInhalt"
    private const val API = "https://api.github.com/repos/MrP42/lesefuchs/releases/latest"

    private val json = Json { ignoreUnknownKeys = true }

    @Serializable
    private data class Release(val assets: List<Asset> = emptyList())

    @Serializable
    private data class Asset(
        val name: String = "",
        val size: Long = 0,
        @SerialName("browser_download_url") val url: String = "",
    )

    /** Lädt das erste .lesepaket des neuesten Releases. Blockierend. */
    fun fetchDemo(context: Context, onProgress: (Float) -> Unit = {}): File? = try {
        val conn = (URL(API).openConnection() as HttpURLConnection).apply {
            setRequestProperty("Accept", "application/vnd.github+json")
            setRequestProperty("User-Agent", "Lesefuchs")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        val body = try {
            if (conn.responseCode == 200) conn.inputStream.bufferedReader().readText() else null
        } finally { conn.disconnect() }

        val asset = body
            ?.let { json.decodeFromString<Release>(it) }
            ?.assets?.firstOrNull { it.name.endsWith(".lesepaket") }

        if (asset == null) {
            Log.i(TAG, "Kein Beispielpaket im Release gefunden")
            null
        } else {
            val target = File(LesepaketLoader(context).importDir(), asset.name)
            download(asset.url, target, asset.size, onProgress)
            Log.i(TAG, "Beispiel geladen: ${target.name} (${target.length() / 1024} KiB)")
            target
        }
    } catch (t: Throwable) {
        Log.i(TAG, "Beispiel laden fehlgeschlagen: ${t.message}")
        null
    }

    /** Kopiert eine über den Systemdialog gewählte Datei in den Import-Ordner. */
    fun importFrom(context: Context, uri: Uri): File? = try {
        val name = displayName(context, uri) ?: "import.lesepaket"
        val target = File(LesepaketLoader(context).importDir(), name)
        context.contentResolver.openInputStream(uri)?.use { input ->
            target.outputStream().use { input.copyTo(it) }
        } ?: error("Datei nicht lesbar")
        Log.i(TAG, "importiert: ${target.name} (${target.length() / 1024} KiB)")
        target
    } catch (t: Throwable) {
        Log.i(TAG, "Import fehlgeschlagen: ${t.message}")
        null
    }

    private fun download(url: String, target: File, size: Long, onProgress: (Float) -> Unit) {
        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            instanceFollowRedirects = true
            connectTimeout = 15_000
            readTimeout = 60_000
        }
        try {
            conn.inputStream.use { input ->
                target.outputStream().use { out ->
                    val buffer = ByteArray(64 * 1024)
                    var total = 0L
                    while (true) {
                        val read = input.read(buffer)
                        if (read < 0) break
                        out.write(buffer, 0, read)
                        total += read
                        if (size > 0) onProgress(total.toFloat() / size)
                    }
                }
            }
        } finally { conn.disconnect() }
    }

    private fun displayName(context: Context, uri: Uri): String? =
        context.contentResolver.query(uri, null, null, null, null)?.use { c ->
            val idx = c.getColumnIndex(android.provider.OpenableColumns.DISPLAY_NAME)
            if (idx >= 0 && c.moveToFirst()) c.getString(idx) else null
        }?.takeIf { it.isNotBlank() }
}
