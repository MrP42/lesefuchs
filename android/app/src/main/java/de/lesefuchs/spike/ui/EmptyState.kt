package de.lesefuchs.spike.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Startbildschirm ohne Inhalte. Beide Wege kommen ohne PC und ohne
 * Zusatzberechtigung aus — auf Fire OS ist das der entscheidende Punkt.
 */
@Composable
fun EmptyState(
    message: String,
    busy: Boolean,
    progress: Float?,
    onLoadDemo: () -> Unit,
    onPickFile: () -> Unit,
) {
    Column(
        Modifier.fillMaxSize().padding(48.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text("🦊", fontSize = 64.sp)
        Text(
            "Noch keine Geschichte da",
            style = MaterialTheme.typography.headlineSmall,
            modifier = Modifier.padding(top = 16.dp),
        )
        Text(
            message,
            style = MaterialTheme.typography.bodyLarge,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = 12.dp),
        )

        if (busy) {
            LinearProgressIndicator(
                progress = { progress ?: 0f },
                modifier = Modifier.padding(top = 32.dp).width(360.dp),
            )
        } else {
            Row(
                Modifier.fillMaxWidth().padding(top = 32.dp),
                horizontalArrangement = Arrangement.Center,
            ) {
                Button(onClick = onLoadDemo, modifier = Modifier.padding(end = 16.dp)) {
                    Text("Beispielgeschichte laden", fontSize = 18.sp)
                }
                OutlinedButton(onClick = onPickFile) {
                    Text("Datei öffnen …", fontSize = 18.sp)
                }
            }
            Text(
                "„Beispielgeschichte laden\" holt eine fertige Geschichte aus dem Internet. " +
                    "„Datei öffnen\" nimmt ein .lesepaket, das schon auf dem Tablet liegt " +
                    "(z. B. im Ordner Downloads).",
                style = MaterialTheme.typography.bodyMedium,
                textAlign = TextAlign.Center,
                modifier = Modifier.padding(top = 20.dp),
            )
        }
    }
}
