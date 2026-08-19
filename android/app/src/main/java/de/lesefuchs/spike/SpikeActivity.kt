package de.lesefuchs.spike

import android.app.ActivityManager
import android.graphics.Bitmap
import android.os.Bundle
import android.os.Debug
import android.os.Environment
import android.util.Log
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import java.io.File

/**
 * Technik-Spike (Konzept §13 / M0): drei Beweise auf echtem Fire-OS-Gerät.
 *   1. ML Kit BUNDLED:   Foto → erkannter Text (ohne Play Services)
 *   2. sherpa-onnx+Piper: 200 Zeichen → WAV, Latenz + RAM geloggt
 *   3. startLockTask():   lässt Fire OS das Pinning zu?
 * Alle Ergebnisse erscheinen im Log-Feld UND unter Logcat-Tag "LesefuchsSpike".
 */
class SpikeActivity : ComponentActivity() {

    private var log by mutableStateOf("Bereit.\n")

    private fun addLog(line: String) {
        Log.i(TAG, line)
        log += line + "\n"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme {
                val scroll = rememberScrollState()
                Column(Modifier.fillMaxSize().padding(24.dp).verticalScroll(scroll)) {

                    Text("Technik-Spike", style = MaterialTheme.typography.titleLarge)

                    // --- 1. ML Kit OCR --------------------------------------
                    val takePicture = rememberLauncherForActivityResult(
                        ActivityResultContracts.TakePicturePreview()
                    ) { bitmap -> if (bitmap != null) runOcr(bitmap) else addLog("OCR: kein Foto") }

                    SpikeCard("1 · ML Kit bundled: Foto → Text") {
                        Button(onClick = { takePicture.launch(null) }) { Text("Foto aufnehmen") }
                    }

                    // --- 2. sherpa-onnx + Piper -----------------------------
                    SpikeCard("2 · sherpa-onnx + Piper de_DE (200 Zeichen)") {
                        Button(onClick = { runTts() }) { Text("Synthese starten") }
                    }

                    // --- 3. Lock Task ---------------------------------------
                    SpikeCard("3 · startLockTask() auf Fire OS") {
                        Button(onClick = { runLockTask() }) { Text("Lock Task versuchen") }
                        Button(onClick = { runCatching { stopLockTask() }
                            .onSuccess { addLog("LockTask: gestoppt") }
                            .onFailure { addLog("LockTask stop: ${it.message}") } }) { Text("Beenden") }
                    }

                    Text(log, style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.padding(top = 16.dp))
                }
            }
        }
    }

    @Composable
    private fun SpikeCard(title: String, content: @Composable () -> Unit) {
        Card(Modifier.fillMaxWidth().padding(vertical = 8.dp)) {
            Column(Modifier.padding(16.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                content()
            }
        }
    }

    // --- Spike 1: OCR ------------------------------------------------------

    private fun runOcr(bitmap: Bitmap) {
        val start = System.nanoTime()
        val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
        recognizer.process(InputImage.fromBitmap(bitmap, 0))
            .addOnSuccessListener { result ->
                val ms = (System.nanoTime() - start) / 1_000_000
                addLog("OCR: ${ms} ms, ${result.textBlocks.size} Blöcke, " +
                        "${result.text.length} Zeichen")
                addLog("OCR-Text: " + result.text.take(300).replace('\n', ' '))
            }
            .addOnFailureListener { addLog("OCR FEHLER: ${it.message}") }
    }

    // --- Spike 2: sherpa-onnx TTS -----------------------------------------

    private fun runTts() {
        // Vorrang: /sdcard-Override (Modelltausch ohne Rebuild); sonst das im
        // APK gebündelte Modell (assets/piper-de → einmalig nach filesDir
        // kopiert — espeak-ng liest echte Dateien, keine Assets).
        val override = File(Environment.getExternalStorageDirectory(), "Lesefuchs/models/piper-de")
        val modelDir = if (File(override, "model.onnx").isFile) {
            addLog("TTS: nutze /sdcard-Override")
            override
        } else {
            unpackBundledModel() ?: return
        }
        val model = File(modelDir, "model.onnx")
        val tokens = File(modelDir, "tokens.txt")
        val espeakData = File(modelDir, "espeak-ng-data")
        if (!model.isFile || !tokens.isFile) {
            addLog("TTS: Modell unvollständig unter ${modelDir.absolutePath}")
            return
        }
        val text = ("Der kleine Fuchs lief durch den Wald und zählte die Sterne. " +
                "Eins, zwei, drei — immer weiter, bis der Morgen kam und die Sonne " +
                "über den Bäumen stand. Dann schlief er zufrieden ein.").take(200)
        Thread {
            try {
                val ramBefore = Debug.getNativeHeapAllocatedSize() / 1_048_576
                val t0 = System.nanoTime()
                // Reflektionsfrei: direkte sherpa-onnx-API (com.k2fsa.sherpa.onnx)
                val config = com.k2fsa.sherpa.onnx.OfflineTtsConfig(
                    model = com.k2fsa.sherpa.onnx.OfflineTtsModelConfig(
                        vits = com.k2fsa.sherpa.onnx.OfflineTtsVitsModelConfig(
                            model = model.absolutePath,
                            tokens = tokens.absolutePath,
                            dataDir = espeakData.absolutePath,
                        ),
                        numThreads = 2,
                    ),
                )
                val tts = com.k2fsa.sherpa.onnx.OfflineTts(config = config)
                val loadMs = (System.nanoTime() - t0) / 1_000_000

                val t1 = System.nanoTime()
                val audio = tts.generate(text = text, sid = 0, speed = 1.0f)
                val synthMs = (System.nanoTime() - t1) / 1_000_000
                val ramAfter = Debug.getNativeHeapAllocatedSize() / 1_048_576
                val mem = ActivityManager.MemoryInfo().also {
                    (getSystemService(ACTIVITY_SERVICE) as ActivityManager).getMemoryInfo(it)
                }

                val durationMs = audio.samples.size * 1000L / audio.sampleRate
                runOnUiThread {
                    addLog("TTS: Laden ${loadMs} ms, Synthese ${synthMs} ms für " +
                            "${durationMs} ms Audio (RTF ${"%.2f".format(synthMs.toFloat() / durationMs)})")
                    addLog("TTS RAM: nativ ${ramBefore}→${ramAfter} MB, " +
                            "Gerät frei ${mem.availMem / 1_048_576} MB")
                }
                tts.release()
            } catch (t: Throwable) {
                runOnUiThread { addLog("TTS FEHLER: ${t::class.simpleName}: ${t.message}") }
            }
        }.start()
    }

    /** Kopiert assets/piper-de einmalig nach filesDir (Marker: .complete). */
    private fun unpackBundledModel(): File? {
        val target = File(filesDir, "piper-de")
        val marker = File(target, ".complete")
        if (marker.isFile) return target
        return try {
            val t0 = System.nanoTime()
            copyAssetDir("piper-de", target)
            marker.writeText("ok")
            addLog("TTS: gebündeltes Modell entpackt (${(System.nanoTime() - t0) / 1_000_000} ms)")
            target
        } catch (t: Throwable) {
            addLog("TTS: Modell-Entpacken fehlgeschlagen: ${t.message}")
            null
        }
    }

    private fun copyAssetDir(assetPath: String, target: File) {
        val children = assets.list(assetPath) ?: emptyArray()
        if (children.isEmpty()) {
            target.parentFile?.mkdirs()
            assets.open(assetPath).use { input ->
                target.outputStream().use { input.copyTo(it) }
            }
        } else {
            target.mkdirs()
            children.forEach { copyAssetDir("$assetPath/$it", File(target, it)) }
        }
    }

    // --- Spike 3: Lock Task ------------------------------------------------

    private fun runLockTask() {
        try {
            val am = getSystemService(ACTIVITY_SERVICE) as ActivityManager
            addLog("LockTask: Modus vorher = ${am.lockTaskModeState}")
            startLockTask()
            addLog("LockTask: startLockTask() ausgeführt, Modus = ${am.lockTaskModeState} " +
                    "(1 = LOCKED, 2 = PINNED)")
        } catch (t: Throwable) {
            addLog("LockTask FEHLER: ${t::class.simpleName}: ${t.message}")
        }
    }

    companion object { private const val TAG = "LesefuchsSpike" }
}
