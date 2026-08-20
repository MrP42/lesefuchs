package de.lesefuchs.spike.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import de.lesefuchs.spike.voice.Voice

/**
 * Stimmenauswahl — bewusst wie ein Bilderbuch aufgebaut: große Kacheln,
 * ein Tipp genügt, kein Menü, keine Kleinschrift (Konzept §5.2).
 * Noch nicht geladene Stimmen zeigen ihre Größe; das Laden läuft danach
 * sichtbar in derselben Kachel.
 */
@Composable
fun VoiceScreen(
    stimmen: List<Voice>,
    gewaehlt: String,
    bereit: (Voice) -> Boolean,
    ladend: String?,
    fortschritt: Float?,
    onWaehlen: (Voice) -> Unit,
    onZurueck: () -> Unit,
) {
    Column(Modifier.fillMaxSize().padding(24.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            BigIconButton(symbol = "‹", beschriftung = "Zurück", onClick = onZurueck)
            Text(
                "Welche Stimme soll vorlesen?",
                style = MaterialTheme.typography.headlineSmall,
                modifier = Modifier.padding(start = 20.dp),
            )
        }

        LazyVerticalGrid(
            columns = GridCells.Adaptive(minSize = 260.dp),
            modifier = Modifier.weight(1f).padding(top = 20.dp),
            horizontalArrangement = Arrangement.spacedBy(20.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            items(stimmen) { stimme ->
                VoiceTile(
                    stimme = stimme,
                    aktiv = stimme.id == gewaehlt,
                    bereit = bereit(stimme),
                    ladend = ladend == stimme.id,
                    fortschritt = fortschritt,
                    onClick = { onWaehlen(stimme) },
                )
            }
        }
    }
}

@Composable
private fun VoiceTile(
    stimme: Voice,
    aktiv: Boolean,
    bereit: Boolean,
    ladend: Boolean,
    fortschritt: Float?,
    onClick: () -> Unit,
) {
    val rahmen = if (aktiv) Color(0xFF7E57C2) else Color(0x22000000)
    Card(
        onClick = onClick,
        enabled = !ladend,
        elevation = CardDefaults.cardElevation(defaultElevation = if (aktiv) 6.dp else 2.dp),
        modifier = Modifier
            .fillMaxWidth()
            .height(150.dp)
            .border(if (aktiv) 4.dp else 1.dp, rahmen, RoundedCornerShape(12.dp)),
    ) {
        Row(
            Modifier.fillMaxSize().padding(20.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier.size(72.dp).background(rahmen.copy(alpha = 0.15f), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                when {
                    ladend -> CircularProgressIndicator(
                        progress = { fortschritt ?: 0f },
                        modifier = Modifier.size(48.dp),
                    )
                    aktiv -> Text("✓", fontSize = 40.sp)
                    else -> Text(if (stimme.kind == Voice.Kind.SYSTEM) "📢" else "🗣", fontSize = 36.sp)
                }
            }
            Column(Modifier.padding(start = 20.dp)) {
                Text(
                    stimme.name,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    stimme.beschreibung,
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color.Gray,
                    modifier = Modifier.padding(top = 4.dp),
                )
                if (ladend) {
                    Text(
                        "wird geladen …",
                        style = MaterialTheme.typography.bodyMedium,
                        color = Color(0xFF7E57C2),
                        modifier = Modifier.padding(top = 6.dp),
                    )
                } else if (!bereit) {
                    Text(
                        "einmal laden · ${stimme.downloadMb} MB",
                        style = MaterialTheme.typography.bodyMedium,
                        color = Color(0xFF7E57C2),
                        modifier = Modifier.padding(top = 6.dp),
                    )
                }
            }
        }
    }
}

/** Große, runde Schaltfläche — Mindestgröße für Kinderhände (Konzept §5.2). */
@Composable
fun BigIconButton(
    symbol: String,
    beschriftung: String,
    onClick: () -> Unit,
    farbe: Color = Color(0xFF7E57C2),
    aktiv: Boolean = true,
) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Card(
            onClick = onClick,
            enabled = aktiv,
            elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
            colors = CardDefaults.cardColors(
                containerColor = if (aktiv) farbe.copy(alpha = 0.14f) else Color(0x11000000)
            ),
            modifier = Modifier.size(84.dp),
        ) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(symbol, fontSize = 36.sp)
            }
        }
        Text(
            beschriftung,
            style = MaterialTheme.typography.bodyMedium,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = 6.dp),
        )
    }
}
