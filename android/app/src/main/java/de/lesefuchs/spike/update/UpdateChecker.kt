package de.lesefuchs.spike.update

import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.content.FileProvider
import de.lesefuchs.spike.BuildConfig
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * Aktualisierung über GitHub Releases (Konzept §8.3 — es gibt keinen App-Store).
 *
 * Ablauf: neuestes Release abfragen → Version vergleichen → APK herunterladen →
 * Android-Installationsdialog öffnen. Der Nutzer bestätigt die Installation
 * selbst; die App installiert nichts still im Hintergrund.
 *
 * Netzwerk wird ausschließlich hierfür verwendet. Abschaltbar über
 * [setEnabled] — dann fasst die App das Netz gar nicht mehr an.
 */
object UpdateChecker {

    private const val TAG = "LesefuchsUpdate"
    private const val API = "https://api.github.com/repos/MrP42/lesefuchs/releases/latest"
    const val RELEASES_PAGE = "https://github.com/MrP42/lesefuchs/releases/latest"
    private const val PREFS = "lesefuchs"
    private const val KEY_ENABLED = "update_check_enabled"

    private val json = Json { ignoreUnknownKeys = true }

    data class Available(val version: String, val downloadUrl: String, val sizeBytes: Long)

    @Serializable
    private data class Release(
        @SerialName("tag_name") val tagName: String = "",
        val draft: Boolean = false,
        val prerelease: Boolean = false,
        val assets: List<Asset> = emptyList(),
    )

    @Serializable
    private data class Asset(
        val name: String = "",
        val size: Long = 0,
        @SerialName("browser_download_url") val url: String = "",
    )

    fun isEnabled(context: Context): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(KEY_ENABLED, true)

    fun setEnabled(context: Context, enabled: Boolean) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putBoolean(KEY_ENABLED, enabled).apply()
    }

    /** Blockierend — aus einem Hintergrund-Thread aufrufen. null = kein Update. */
    fun check(context: Context, currentVersion: String = BuildConfig.VERSION_NAME): Available? {
        if (!isEnabled(context)) return null
        return try {
            val body = fetch(API) ?: return null
            val release = json.decodeFromString<Release>(body)
            if (release.draft || release.prerelease) return null

            val version = release.tagName.removePrefix("v")
            if (!isNewer(version, currentVersion)) {
                Log.i(TAG, "aktuell (installiert $currentVersion, angeboten $version)")
                return null
            }
            val apk = release.assets.firstOrNull { it.name.endsWith(".apk") } ?: return null
            Log.i(TAG, "Update verfügbar: $version (${apk.size / 1_048_576} MB)")
            Available(version, apk.url, apk.size)
        } catch (t: Throwable) {
            Log.i(TAG, "Prüfung fehlgeschlagen: ${t.message}")
            null
        }
    }

    /** Lädt die APK nach cacheDir. Fortschritt 0..1. Blockierend. */
    fun download(context: Context, update: Available, onProgress: (Float) -> Unit = {}): File? {
        return try {
            val target = File(context.cacheDir, "updates").apply { mkdirs() }
                .resolve("lesefuchs-${update.version}.apk")
            if (target.isFile && target.length() == update.sizeBytes) return target

            (URL(update.downloadUrl).openConnection() as HttpURLConnection).apply {
                instanceFollowRedirects = true
                connectTimeout = 15_000
                readTimeout = 60_000
            }.use { conn ->
                conn.inputStream.use { input ->
                    target.outputStream().use { out ->
                        val buffer = ByteArray(64 * 1024)
                        var total = 0L
                        while (true) {
                            val read = input.read(buffer)
                            if (read < 0) break
                            out.write(buffer, 0, read)
                            total += read
                            if (update.sizeBytes > 0) onProgress(total.toFloat() / update.sizeBytes)
                        }
                    }
                }
            }
            Log.i(TAG, "heruntergeladen: ${target.length() / 1_048_576} MB")
            target
        } catch (t: Throwable) {
            Log.i(TAG, "Download fehlgeschlagen: ${t.message}")
            null
        }
    }

    /** Öffnet den Android-Installationsdialog für die heruntergeladene APK. */
    fun install(context: Context, apk: File) {
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.updates", apk)
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
    }

    // ---- reine Logik ----------------------------------------------------

    /** Vergleich nach Zahlenfolge: "0.2.0" ist neuer als "0.1.9". */
    fun isNewer(candidate: String, current: String): Boolean {
        val a = candidate.split('.', '-').mapNotNull { it.toIntOrNull() }
        val b = current.split('.', '-').mapNotNull { it.toIntOrNull() }
        for (i in 0 until maxOf(a.size, b.size)) {
            val x = a.getOrElse(i) { 0 }
            val y = b.getOrElse(i) { 0 }
            if (x != y) return x > y
        }
        return false
    }

    private fun fetch(url: String): String? {
        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            setRequestProperty("Accept", "application/vnd.github+json")
            setRequestProperty("User-Agent", "Lesefuchs")
            connectTimeout = 10_000
            readTimeout = 15_000
        }
        return conn.use { if (it.responseCode == 200) it.inputStream.bufferedReader().readText() else null }
    }

    private inline fun <T> HttpURLConnection.use(block: (HttpURLConnection) -> T): T =
        try { block(this) } finally { disconnect() }
}
