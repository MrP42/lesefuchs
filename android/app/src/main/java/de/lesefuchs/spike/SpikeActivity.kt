package de.lesefuchs.spike

import android.app.ActivityManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
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
 *   1. ML Kit BUNDLED:    Foto/Testbild → erkannter Text (ohne Play Services)
 *   2. sherpa-onnx+Piper: 200 Zeichen → WAV, Latenz + RAM geloggt
 *   3. startLockTask():   lässt Fire OS das Pinning zu?
 *
 * Zwei Betriebsarten:
 *   • manuell — Buttons in der UI
 *   • autorun — 2a → 2b → 2c ohne Bedienung, je eine maschinenlesbare Zeile
 *     `SPIKE_RESULT key=… status=OK|FAIL …` unter Logcat-Tag "LesefuchsSpike",
 *     danach beendet sich die Activity:
 *
 *     adb shell am start -n de.lesefuchs.spike/.SpikeActivity \
 *         --ez autorun true --es ocr_image /sdcard/Lesefuchs/test/seite.png
 */
class SpikeActivity : ComponentActivity() {

    private var log by mutableStateOf("Bereit.\n")

    private fun addLog(line: String) {
        Log.i(TAG, line)
        log += line + "\n"
    }

    /** Eine Ergebniszeile je Test — maschinenlesbar für abnahme.ps1. */
    private fun spikeResult(key: String, ok: Boolean, vararg fields: Pair<String, Any?>) {
        val rest = fields.filter { it.second != null }
            .joinToString(" ") { (k, v) -> "$k=${v.toString().replace(' ', '_')}" }
        addLog("SPIKE_RESULT key=$key status=${if (ok) "OK" else "FAIL"}${if (rest.isEmpty()) "" else " $rest"}")
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val autorun = intent.getBooleanExtra("autorun", false)
        val ocrImage = intent.getStringExtra("ocr_image")
        val ocrExpected = intent.getStringExtra("ocr_expected")

        setContent {
            MaterialTheme {
                val scroll = rememberScrollState()
                Column(Modifier.fillMaxSize().padding(24.dp).verticalScroll(scroll)) {

                    Text(if (autorun) "Technik-Spike (Autorun)" else "Technik-Spike",
                        style = MaterialTheme.typography.titleLarge)

                    if (!autorun) {
                        val takePicture = rememberLauncherForActivityResult(
                            ActivityResultContracts.TakePicturePreview()
                        ) { bitmap ->
                            if (bitmap != null) runOcr(bitmap, null) {} else addLog("OCR: kein Foto")
                        }

                        SpikeCard("1 · ML Kit bundled: Foto → Text") {
                            Button(onClick = { takePicture.launch(null) }) { Text("Foto aufnehmen") }
                        }
                        SpikeCard("2 · sherpa-onnx + Piper de_DE (200 Zeichen)") {
                            Button(onClick = { runTts {} }) { Text("Synthese starten") }
                        }
                        SpikeCard("3 · startLockTask() auf Fire OS") {
                            Button(onClick = { runLockTask(stopAfter = false) }) { Text("Lock Task versuchen") }
                            Button(onClick = {
                                runCatching { stopLockTask() }
                                    .onSuccess { addLog("LockTask: gestoppt") }
                                    .onFailure { addLog("LockTask stop: ${it.message}") }
                            }) { Text("Beenden") }
                        }
                    }

                    Text(log, style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.padding(top = 16.dp))
                }
            }
        }

        if (autorun) startAutorun(ocrImage, ocrExpected)
    }

    /** 2a → 2b → 2c nacheinander, dann beenden. */
    private fun startAutorun(ocrImage: String?, ocrExpected: String?) {
        addLog("SPIKE_RUN start device=${android.os.Build.MODEL} sdk=${android.os.Build.VERSION.SDK_INT}")
        runOcrFromFile(ocrImage, ocrExpected) {
            runTts {
                runLockTask(stopAfter = true)
                addLog("SPIKE_RUN done")
                window.decorView.postDelayed({ finish() }, 1500)
            }
        }
    }

    // --- Spike 1: OCR ------------------------------------------------------

    private fun runOcrFromFile(path: String?, expectedPath: String?, onDone: () -> Unit) {
        if (path == null) {
            spikeResult("ocr", false, "reason" to "no_ocr_image_extra")
            onDone()
            return
        }
        val file = File(path)
        val bitmap = if (file.isFile) BitmapFactory.decodeFile(file.absolutePath) else null
        if (bitmap == null) {
            spikeResult("ocr", false, "reason" to "image_unreadable", "path" to path)
            onDone()
            return
        }
        // Erwartungstext: explizites Extra, sonst gleicher Name mit .txt
        val expectedFile = File(expectedPath ?: path.replaceAfterLast('.', "txt"))
        val expected = if (expectedFile.isFile) expectedFile.readText(Charsets.UTF_8) else null
        runOcr(bitmap, expected, onDone)
    }

    private fun runOcr(bitmap: Bitmap, expected: String?, onDone: () -> Unit) {
        val start = System.nanoTime()
        val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
        recognizer.process(InputImage.fromBitmap(bitmap, 0))
            .addOnSuccessListener { result ->
                val ms = (System.nanoTime() - start) / 1_000_000
                val accuracy = expected?.let { charAccuracy(it, result.text) }
                spikeResult(
                    "ocr", result.text.isNotBlank(),
                    "ms" to ms,
                    "blocks" to result.textBlocks.size,
                    "chars" to result.text.length,
                    "accuracy" to accuracy?.let { String.format("%.3f", it) },
                )
                addLog("OCR-Text: " + result.text.take(300).replace('\n', ' '))
                onDone()
            }
            .addOnFailureListener { e ->
                // Genau hier schlägt ein fehlendes GMS zu (siehe ABNAHME.md 2a)
                spikeResult("ocr", false, "reason" to "exception",
                    "detail" to "${e::class.simpleName}:${e.message}")
                onDone()
            }
    }

    /** 1 − Levenshtein/Länge auf normalisiertem Text (Whitespace vereinheitlicht). */
    private fun charAccuracy(expected: String, actual: String): Double {
        val a = expected.replace(Regex("\\s+"), " ").trim()
        val b = actual.replace(Regex("\\s+"), " ").trim()
        if (a.isEmpty()) return if (b.isEmpty()) 1.0 else 0.0
        var prev = IntArray(b.length + 1) { it }
        for (i in 1..a.length) {
            val curr = IntArray(b.length + 1)
            curr[0] = i
            for (j in 1..b.length) {
                curr[j] = minOf(
                    prev[j] + 1,
                    curr[j - 1] + 1,
                    prev[j - 1] + if (a[i - 1] == b[j - 1]) 0 else 1,
                )
            }
            prev = curr
        }
        return (1.0 - prev[b.length].toDouble() / a.length).coerceIn(0.0, 1.0)
    }

    // --- Spike 2: sherpa-onnx TTS -----------------------------------------

    private fun runTts(onDone: () -> Unit) {
        val override = File(Environment.getExternalStorageDirectory(), "Lesefuchs/models/piper-de")
        val modelDir = if (File(override, "model.onnx").isFile) {
            addLog("TTS: nutze /sdcard-Override")
            override
        } else {
            unpackBundledModel()
        }
        if (modelDir == null) {
            spikeResult("tts", false, "reason" to "model_unpack_failed")
            onDone()
            return
        }
        val model = File(modelDir, "model.onnx")
        val tokens = File(modelDir, "tokens.txt")
        val espeakData = File(modelDir, "espeak-ng-data")
        if (!model.isFile || !tokens.isFile) {
            spikeResult("tts", false, "reason" to "model_incomplete", "dir" to modelDir.absolutePath)
            onDone()
            return
        }
        val text = ("Der kleine Fuchs lief durch den Wald und zählte die Sterne. " +
                "Eins, zwei, drei — immer weiter, bis der Morgen kam und die Sonne " +
                "über den Bäumen stand. Dann schlief er zufrieden ein.").take(200)
        Thread {
            try {
                val ramBefore = Debug.getNativeHeapAllocatedSize() / 1_048_576
                val t0 = System.nanoTime()
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
                tts.release()

                runOnUiThread {
                    spikeResult(
                        "tts", durationMs > 0,
                        "chars" to text.length,
                        "load_ms" to loadMs,
                        "synth_ms" to synthMs,
                        "audio_ms" to durationMs,
                        "rtf" to String.format("%.2f", synthMs.toFloat() / durationMs),
                        "ram_before_mb" to ramBefore,
                        "ram_after_mb" to ramAfter,
                        "device_free_mb" to mem.availMem / 1_048_576,
                    )
                    onDone()
                }
            } catch (t: Throwable) {
                runOnUiThread {
                    spikeResult("tts", false, "reason" to "exception",
                        "detail" to "${t::class.simpleName}:${t.message}")
                    onDone()
                }
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

    /**
     * Unterscheidet drei Ausgänge:
     *   OK                      — Modus LOCKED(1) oder PINNED(2) aktiv
     *   FAIL reason=not_allowed — Aufruf ging durch, Modus bleibt NONE(0)
     *   FAIL reason=exception   — Fire OS hat die AOSP-Funktion entfernt/gesperrt
     * stopAfter=true beendet das Pinning wieder (Autorun darf kein gepinntes
     * Tablet hinterlassen).
     */
    private fun runLockTask(stopAfter: Boolean) {
        val am = getSystemService(ACTIVITY_SERVICE) as ActivityManager
        val before = am.lockTaskModeState
        try {
            startLockTask()
        } catch (t: Throwable) {
            spikeResult("locktask", false, "reason" to "exception",
                "detail" to "${t::class.simpleName}:${t.message}", "mode_before" to before)
            return
        }
        val after = am.lockTaskModeState
        if (after == ActivityManager.LOCK_TASK_MODE_NONE) {
            spikeResult("locktask", false, "reason" to "not_allowed",
                "mode_before" to before, "mode_after" to after)
            return
        }
        spikeResult("locktask", true, "mode_before" to before, "mode_after" to after,
            "mode_name" to if (after == ActivityManager.LOCK_TASK_MODE_LOCKED) "LOCKED" else "PINNED")
        if (stopAfter) {
            runCatching { stopLockTask() }
                .onSuccess { addLog("LockTask: wieder freigegeben") }
                .onFailure { addLog("LockTask: Freigabe fehlgeschlagen: ${it.message}") }
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

    companion object { private const val TAG = "LesefuchsSpike" }
}
