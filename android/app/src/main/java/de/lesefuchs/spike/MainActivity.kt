package de.lesefuchs.spike

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.util.Log
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.lifecycleScope
import androidx.media3.common.MediaItem
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import de.lesefuchs.spike.pkg.ContentFetcher
import de.lesefuchs.spike.pkg.Lesepaket
import de.lesefuchs.spike.pkg.LibraryEntry
import de.lesefuchs.spike.pkg.LesepaketLoader
import de.lesefuchs.spike.sync.SyncSelfCheck
import de.lesefuchs.spike.ui.EmptyState
import de.lesefuchs.spike.ui.LibraryScreen
import de.lesefuchs.spike.ui.PlayerScreen
import de.lesefuchs.spike.ui.UpdateBanner
import de.lesefuchs.spike.update.UpdateChecker
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Spike-Player: lädt das erste .lesepaket aus der Inbox und spielt es mit
 * Satz- und Wort-Highlight ab (Abnahmekriterium: synchron, ohne Drift).
 */
class MainActivity : ComponentActivity() {

    private var player: ExoPlayer? = null
    private var status by mutableStateOf("Suche .lesepaket …")
    private var paket by mutableStateOf<Lesepaket?>(null)
    private var bibliothek by mutableStateOf<List<LibraryEntry>>(emptyList())
    private var update by mutableStateOf<UpdateChecker.Available?>(null)
    private var updateProgress by mutableStateOf<Float?>(null)
    private var busy by mutableStateOf(false)
    private var contentProgress by mutableStateOf<Float?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val exo = ExoPlayer.Builder(this).build()
        player = exo

        setContent {
            MaterialTheme {
                Column(Modifier.fillMaxSize()) {
                    update?.let { verfuegbar ->
                        UpdateBanner(
                            version = verfuegbar.version,
                            sizeBytes = verfuegbar.sizeBytes,
                            progress = updateProgress,
                            onInstall = { startUpdate(verfuegbar) },
                            onLater = { update = null },
                            onNever = {
                                UpdateChecker.setEnabled(this@MainActivity, false)
                                update = null
                            },
                        )
                    }
                    val p = paket
                    val spike = { startActivity(Intent(this@MainActivity, SpikeActivity::class.java)) }
                    if (p != null) {
                        PlayerScreen(p, exo, onBack = { paket = null }, onOpenSpike = spike)
                    } else if (bibliothek.isNotEmpty()) {
                        val picker = rememberLauncherForActivityResult(
                            ActivityResultContracts.OpenDocument()
                        ) { uri -> if (uri != null) importPackage(uri) }
                        LibraryScreen(
                            entries = bibliothek,
                            busy = busy,
                            progress = contentProgress,
                            onOpen = { eintrag -> openEntry(eintrag) },
                            onLoadDemo = { loadDemo() },
                            onPickFile = { picker.launch(arrayOf("*/*")) },
                            onOpenSpike = spike,
                        )
                    } else {
                        val picker = rememberLauncherForActivityResult(
                            ActivityResultContracts.OpenDocument()
                        ) { uri -> if (uri != null) importPackage(uri) }
                        EmptyState(
                            message = status,
                            busy = busy,
                            progress = contentProgress,
                            onLoadDemo = { loadDemo() },
                            onPickFile = {
                                // Kein MIME-Typ fuer .lesepaket registriert -> alles zulassen
                                picker.launch(arrayOf("*/*"))
                            },
                        )
                    }
                }
            }
        }

        // Update-Prüfung nur, wenn nicht gerade eine Selbstprüfung läuft
        if (!intent.getBooleanExtra("selfcheck", false)) checkForUpdate()

        lifecycleScope.launch {
            refreshLibrary()
            if (intent.getBooleanExtra("selfcheck", false)) {
                val erste = bibliothek.firstOrNull()
                if (erste == null) {
                    Log.i(TAG, "SPIKE_RESULT key=sync status=FAIL reason=no_package")
                    finish()
                } else {
                    val geladen = withContext(Dispatchers.IO) {
                        runCatching { LesepaketLoader(this@MainActivity).load(erste.file) }.getOrNull()
                    }
                    if (geladen == null) {
                        Log.i(TAG, "SPIKE_RESULT key=sync status=FAIL reason=package_unreadable")
                        finish()
                    } else {
                        paket = geladen
                        status = "Selbstprüfung läuft …"
                        runSyncSelfCheck(
                            geladen, exo,
                            chapterIndex = intent.getIntExtra("selfcheck_chapter", 0),
                            speed = intent.getFloatExtra("selfcheck_speed", 1.0f),
                        )
                    }
                }
            }
        }
    }

    /** Liest alle Pakete neu ein (Bibliothek). */
    private suspend fun refreshLibrary() {
        bibliothek = withContext(Dispatchers.IO) { LesepaketLoader(this@MainActivity).listPackages() }
        if (bibliothek.isEmpty()) status = "Tippe unten auf „Beispielgeschichte laden“."
    }

    /** Öffnet eine Geschichte aus der Bibliothek. */
    private fun openEntry(entry: LibraryEntry) {
        busy = true
        status = "„${entry.manifest.title}“ wird geöffnet …"
        lifecycleScope.launch {
            val geladen = withContext(Dispatchers.IO) {
                runCatching { LesepaketLoader(this@MainActivity).load(entry.file) }.getOrNull()
            }
            busy = false
            if (geladen != null) paket = geladen
            else status = "Diese Geschichte ließ sich nicht öffnen."
        }
    }

    /**
     * Abnahme Punkt 4, automatisiert: spielt ein Kapitel und vergleicht alle
     * 250 ms den von der HighlightEngine gelieferten Token gegen eine
     * unabhängige Referenzsuche über content.json (rohe Player-Position, ohne
     * Lead-Offset). Ergebnis als eine SPIKE_RESULT-Zeile, danach beenden.
     */
    private fun runSyncSelfCheck(paket: Lesepaket, player: ExoPlayer,
                                 chapterIndex: Int, speed: Float) {
        val chapter = paket.manifest.chapters.getOrNull(chapterIndex)
        if (chapter == null) {
            Log.i(TAG, "SPIKE_RESULT key=sync status=FAIL reason=no_chapter index=$chapterIndex")
            finish()
            return
        }
        val tokens = paket.content.tokens.subList(chapter.tokenStart, chapter.tokenEnd + 1)
        val check = SyncSelfCheck(paket.content, tokens)
        val lastTokenEnd = tokens.last().t1

        lifecycleScope.launch {
            player.setMediaItem(MediaItem.fromUri(Uri.fromFile(paket.audioFiles.getValue(chapter.id))))
            player.setPlaybackSpeed(speed)
            player.prepare()
            player.play()

            var audioDurationMs = 0L
            var stallCount = 0
            var lastPos = -1L
            while (true) {
                delay(250)
                val pos = player.currentPosition
                if (player.duration > 0) audioDurationMs = player.duration
                if (player.playbackState == Player.STATE_ENDED) break
                if (pos == lastPos && player.playbackState == Player.STATE_READY) {
                    if (++stallCount > 20) break   // 5 s ohne Fortschritt
                } else {
                    stallCount = 0
                }
                lastPos = pos
                if (pos > 0) check.sample(pos)
                if (audioDurationMs > 0 && pos >= audioDurationMs - 100) break
            }
            player.pause()

            val ok = check.mismatches == 0 && check.maxDeviationMs < 100 && check.samples > 10
            Log.i(TAG, "SPIKE_RESULT key=sync status=${if (ok) "OK" else "FAIL"} " +
                    "max_dev_ms=${check.maxDeviationMs} " +
                    "mean_dev_ms=${String.format("%.1f", check.meanDeviationMs)} " +
                    "samples=${check.samples} mismatches=${check.mismatches} " +
                    "pause_samples=${check.pauseSamples} max_pause_ms=${check.maxPauseMs} " +
                    "last_token_end_ms=$lastTokenEnd audio_ms=$audioDurationMs " +
                    "tail_gap_ms=${audioDurationMs - lastTokenEnd} speed=$speed")
            check.mismatchExamples().forEach { Log.i(TAG, "SYNC_MISMATCH $it") }
            status = "Selbstprüfung fertig."
            delay(500)
            finish()
        }
    }

    /** Laedt die Beispielgeschichte aus dem GitHub-Release (ohne PC, ohne Rechte). */
    private fun loadDemo() {
        busy = true
        contentProgress = 0f
        status = "Beispielgeschichte wird geladen …"
        lifecycleScope.launch {
            val file = withContext(Dispatchers.IO) {
                ContentFetcher.fetchDemo(this@MainActivity) { p ->
                    runOnUiThread { contentProgress = p }
                }
            }
            openOrReport(file, "Beispiel konnte nicht geladen werden — Internetverbindung prüfen.")
        }
    }

    /** Uebernimmt ein .lesepaket aus dem System-Dateidialog. */
    private fun importPackage(uri: android.net.Uri) {
        busy = true
        contentProgress = null
        status = "Datei wird übernommen …"
        lifecycleScope.launch {
            val file = withContext(Dispatchers.IO) { ContentFetcher.importFrom(this@MainActivity, uri) }
            openOrReport(file, "Diese Datei ließ sich nicht öffnen. Es muss eine .lesepaket-Datei sein.")
        }
    }

    private suspend fun openOrReport(file: java.io.File?, fehler: String) {
        val geladen = file?.let {
            withContext(Dispatchers.IO) {
                runCatching { LesepaketLoader(this@MainActivity).load(it) }.getOrNull()
            }
        }
        busy = false
        contentProgress = null
        if (geladen != null) {
            refreshLibrary()
            paket = geladen          // frisch geholte Geschichte gleich öffnen
        } else {
            file?.delete()
            status = fehler
        }
    }

    /** Fragt im Hintergrund die Releases-Seite ab (Konzept §8.3). */
    private fun checkForUpdate() {
        lifecycleScope.launch {
            val found = withContext(Dispatchers.IO) { UpdateChecker.check(this@MainActivity) }
            if (found != null) update = found
        }
    }

    private fun startUpdate(available: UpdateChecker.Available) {
        lifecycleScope.launch {
            updateProgress = 0f
            val apk = withContext(Dispatchers.IO) {
                UpdateChecker.download(this@MainActivity, available) { p ->
                    runOnUiThread { updateProgress = p }
                }
            }
            updateProgress = null
            if (apk != null) {
                UpdateChecker.install(this@MainActivity, apk)
            } else {
                status = "Download fehlgeschlagen — bitte über die Releases-Seite laden."
                update = null
            }
        }
    }

    override fun onDestroy() {
        player?.release()
        player = null
        super.onDestroy()
    }

    companion object { private const val TAG = "LesefuchsSpike" }
}
