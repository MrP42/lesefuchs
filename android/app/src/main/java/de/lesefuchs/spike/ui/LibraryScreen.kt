package de.lesefuchs.spike.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import de.lesefuchs.spike.pkg.LibraryEntry
import kotlin.math.absoluteValue

/** Kachelfarben — je Titel stabil, damit ein Kind seine Geschichte wiedererkennt. */
private val TileColors = listOf(
    Color(0xFFFFB300), Color(0xFF7E57C2), Color(0xFF26A69A),
    Color(0xFFEF5350), Color(0xFF42A5F5), Color(0xFF9CCC65),
)

@Composable
fun LibraryScreen(
    entries: List<LibraryEntry>,
    busy: Boolean,
    progress: Float?,
    onOpen: (LibraryEntry) -> Unit,
    onLoadDemo: () -> Unit,
    onPickFile: () -> Unit,
    onOpenSpike: () -> Unit,
) {
    Column(Modifier.fillMaxSize().padding(24.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("🦊 Meine Geschichten", style = MaterialTheme.typography.headlineSmall)
            Text(
                "  ${entries.size}",
                style = MaterialTheme.typography.titleMedium,
                color = Color.Gray,
            )
            Box(Modifier.weight(1f))
            TextButton(onClick = onOpenSpike) { Text("Technik-Spike") }
        }

        if (busy) {
            LinearProgressIndicator(
                progress = { progress ?: 0f },
                modifier = Modifier.fillMaxWidth().padding(vertical = 24.dp),
            )
        }

        LazyVerticalGrid(
            columns = GridCells.Adaptive(minSize = 240.dp),
            modifier = Modifier.weight(1f).padding(top = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(20.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            items(entries) { entry -> StoryTile(entry, onOpen) }
        }

        Row(
            Modifier.fillMaxWidth().padding(top = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Button(onClick = onLoadDemo, enabled = !busy) { Text("Beispielgeschichte laden") }
            OutlinedButton(onClick = onPickFile, enabled = !busy) { Text("Datei öffnen …") }
        }
    }
}

@Composable
private fun StoryTile(entry: LibraryEntry, onOpen: (LibraryEntry) -> Unit) {
    val farbe = TileColors[entry.manifest.title.hashCode().absoluteValue % TileColors.size]
    Card(
        onClick = { onOpen(entry) },
        elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column {
            Box(
                Modifier
                    .fillMaxWidth()
                    .aspectRatio(1.6f)
                    .clip(RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp))
                    .background(farbe.copy(alpha = 0.22f)),
                contentAlignment = Alignment.Center,
            ) {
                Text("📖", fontSize = 56.sp)
            }
            Column(Modifier.padding(16.dp)) {
                Text(
                    entry.manifest.title,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    listOfNotNull(
                        entry.manifest.author,
                        "${entry.manifest.chapters.size} Kapitel",
                        "${entry.minutes} min",
                    ).joinToString(" · "),
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color.Gray,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }
        }
    }
}
