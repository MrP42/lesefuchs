package de.lesefuchs.spike

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
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
import androidx.media3.exoplayer.ExoPlayer
import de.lesefuchs.spike.pkg.Lesepaket
import de.lesefuchs.spike.pkg.LesepaketLoader
import de.lesefuchs.spike.ui.PlayerScreen
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Spike-Player: lädt das erste .lesepaket aus der Inbox und spielt es mit
 * Satz- und Wort-Highlight ab (Abnahmekriterium: synchron, ohne Drift).
 */
class MainActivity : ComponentActivity() {

    private var player: ExoPlayer? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val exo = ExoPlayer.Builder(this).build()
        player = exo

        var status by mutableStateOf("Suche .lesepaket …")
        var paket by mutableStateOf<Lesepaket?>(null)

        setContent {
            MaterialTheme {
                val p = paket
                if (p != null) {
                    PlayerScreen(p, exo, onOpenSpike = {
                        startActivity(Intent(this, SpikeActivity::class.java))
                    })
                } else {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        Text(status, Modifier.padding(32.dp))
                    }
                }
            }
        }

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
                onSuccess = { paket = it },
                onFailure = { status = it.message ?: "Fehler beim Laden" },
            )
        }
    }

    override fun onDestroy() {
        player?.release()
        player = null
        super.onDestroy()
    }
}
