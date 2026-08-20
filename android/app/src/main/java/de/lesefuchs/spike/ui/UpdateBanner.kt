package de.lesefuchs.spike.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

private val BannerBg = Color(0xFFE8F0FE)

/**
 * Hinweis auf eine neue Version (Konzept §8.3). Bewusst schlicht und über dem
 * Inhalt, damit ein Kind ihn nicht mit dem Vorlesen verwechselt.
 */
@Composable
fun UpdateBanner(
    version: String,
    sizeBytes: Long,
    progress: Float?,
    onInstall: () -> Unit,
    onLater: () -> Unit,
    onNever: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .background(BannerBg)
            .padding(horizontal = 24.dp, vertical = 12.dp)
    ) {
        if (progress != null) {
            Text("Version $version wird geladen … ${(progress * 100).toInt()} %",
                style = MaterialTheme.typography.bodyLarge)
            LinearProgressIndicator(
                progress = { progress },
                modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
            )
        } else {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    "Neue Version $version verfügbar (${sizeBytes / 1_048_576} MB)",
                    style = MaterialTheme.typography.bodyLarge,
                    modifier = Modifier.weight(1f),
                )
                TextButton(onClick = onNever) { Text("Nicht mehr prüfen") }
                TextButton(onClick = onLater) { Text("Später") }
                Button(onClick = onInstall) { Text("Aktualisieren") }
            }
        }
    }
}
