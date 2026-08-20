package de.lesefuchs.spike

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.util.Log
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
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
import de.lesefuchs.spike.pkg.Lesepaket
import de.lesefuchs.spike.pkg.LesepaketLoader
import de.lesefuchs.spike.sync.SyncSelfCheck
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
    private var update by mutableStateOf<UpdateChecker.Available?>(null)
    private var updateProgress by mutableStateOf<Float?>(null)

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
                    if (p != null) {
                        PlayerScreen(p, exo, onOpenSpike = {
                            startActivity(Intent(this@MainActivity, SpikeActivity::class.java))
                        })
                    } else {
                        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                            Text(status, Modifier.padding(32.dp))
                        }
                    }
                }
            }
        }

        // Update-Prüfung nur, wenn nicht gerade eine Selbstprüfung läuft
        if (!intent.getBooleanExtra("selfcheck", false)) checkForUpdate()

        lifecycleScope.launch {
            val loader = LesepaketLoader(this@MainActivity)
            val result = withContext(Dispatchers.IO) {
                runCatching {
                    val zip = loader.findFirstPackage()
                        ?: error(
                            "Kein Paket gefunden. Ablegen unter:\n" +
                                loader.inboxDirs().joinToString("\n") { it.absolutePath }
                        )
                    loader.load(zip)
                }
            }
            result.fold(
                onSuccess = { loaded ->
                    paket = loaded
                    if (intent.getBooleanExtra("selfcheck", false)) {
                        status = "Selbstprüfung läuft …"
                        runSyncSelfCheck(
                            loaded, exo,
                            chapterIndex = intent.getIntExtra("selfcheck_chapter", 0),
                            speed = intent.getFloatExtra("selfcheck_speed", 1.0f),
                        )
                    }
                },
                onFailure = {
                    status = it.message ?: "Fehler beim Laden"
                    if (intent.getBooleanExtra("selfcheck", false)) {
                        Log.i(TAG, "SPIKE_RESULT key=sync status=FAIL reason=no_package")
                        finish()
                    }
                },
            )
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
