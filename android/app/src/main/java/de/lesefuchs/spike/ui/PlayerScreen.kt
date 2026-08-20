package de.lesefuchs.spike.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.text.ClickableText
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.media3.exoplayer.ExoPlayer
import de.lesefuchs.spike.R
import de.lesefuchs.spike.pkg.Lesepaket
import de.lesefuchs.spike.pkg.Token
import de.lesefuchs.spike.sync.HighlightEngine
import kotlinx.coroutines.isActive

/** Farbwerte des Highlights (Konzept §5.2: Satz sanft, Wort kräftig). */
private val SentenceBg = Color(0xFFFFF3C4)
private val WordBg = Color(0xFFFFB300)

/**
 * Was gerade spricht. AUFNAHME nutzt das fertige Audio des Pakets
 * (millisekundengenau); STIMME lässt die gewählte Stimme live vorlesen.
 */
enum class Vorlesequelle { AUFNAHME, STIMME }

/** Zustand des Live-Vorlesens, von außen gesetzt (siehe MainActivity). */
data class LiveZustand(
    val laeuft: Boolean = false,
    val satzIndex: Int = -1,
    val tokenIndex: Int = -1,
)

@Composable
fun PlayerScreen(
    paket: Lesepaket,
    player: ExoPlayer,
    stimmeName: String,
    quelle: Vorlesequelle,
    live: LiveZustand,
    onBack: () -> Unit,
    onStimmeWaehlen: () -> Unit,
    onQuelleWechseln: (Vorlesequelle) -> Unit,
    onLiveStart: (kapitel: Int, abSatz: Int) -> Unit,
    onLiveStop: () -> Unit,
    onOpenSpike: () -> Unit,
) {
    val andika = remember { FontFamily(Font(R.font.andika_regular)) }
    var chapterIndex by remember { mutableIntStateOf(0) }
    var leadOffsetMs by remember { mutableStateOf(-60f) }
    var isPlaying by remember { mutableStateOf(false) }
    var elternOffen by remember { mutableStateOf(false) }

    val chapter = paket.manifest.chapters[chapterIndex]
    val chapterTokens = remember(chapterIndex) {
        paket.content.tokens.subList(chapter.tokenStart, chapter.tokenEnd + 1)
    }
    val engine = remember(chapterIndex) {
        HighlightEngine(paket.content.copy(tokens = chapterTokens))
    }
    val paragraphs = remember(chapterIndex) {
        chapterTokens.groupBy { it.para }.toSortedMap().values.toList()
    }

    var activeTokenI by remember { mutableIntStateOf(-1) }
    var activeSentI by remember { mutableIntStateOf(-1) }

    LaunchedEffect(chapterIndex) {
        val file = paket.audioFiles.getValue(chapter.id)
        player.setMediaItem(androidx.media3.common.MediaItem.fromUri(file.toUri()))
        player.prepare()
        activeTokenI = -1
        activeSentI = -1
    }

    // Frame-Loop nur für die Aufnahme; beim Live-Vorlesen kommen die Marken
    // vom Sprecher (Konzept §5.5: geschätzte Wortdauern).
    LaunchedEffect(chapterIndex, quelle) {
        if (quelle != Vorlesequelle.AUFNAHME) return@LaunchedEffect
        while (isActive) {
            withFrameNanos { }
            isPlaying = player.isPlaying
            val pos = player.currentPosition - leadOffsetMs.toLong()
            val state = engine.stateAt(pos)
            if (state != null) {
                if (state.token.i != activeTokenI) activeTokenI = state.token.i
                if (state.sentence.i != activeSentI) activeSentI = state.sentence.i
            }
        }
    }

    val angezeigterToken = if (quelle == Vorlesequelle.STIMME) live.tokenIndex else activeTokenI
    val angezeigterSatz = if (quelle == Vorlesequelle.STIMME) live.satzIndex else activeSentI
    val laeuft = if (quelle == Vorlesequelle.STIMME) live.laeuft else isPlaying

    val listState = rememberLazyListState()
    val activeParaIndex = paragraphs.indexOfFirst { tokens -> tokens.any { it.i == angezeigterToken } }
    LaunchedEffect(activeParaIndex) {
        if (activeParaIndex >= 0) listState.animateScrollToItem(activeParaIndex)
    }

    Column(Modifier.fillMaxSize().padding(horizontal = 24.dp, vertical = 16.dp)) {
        // --- Kopfzeile: Titel + Kapitelwahl, alles groß ---------------------
        Row(verticalAlignment = Alignment.CenterVertically) {
            BigIconButton("‹", "Regal", onClick = { player.pause(); onLiveStop(); onBack() })
            Spacer(Modifier.width(16.dp))
            Text(
                paket.manifest.title,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.weight(1f))
            if (paket.manifest.chapters.size > 1) {
                paket.manifest.chapters.forEachIndexed { i, ch ->
                    KapitelTaste(
                        nummer = i + 1,
                        aktiv = i == chapterIndex,
                        onClick = {
                            player.pause(); onLiveStop(); chapterIndex = i
                        },
                    )
                    Spacer(Modifier.width(12.dp))
                }
            }
            // Eltern-Ecke: unauffällig, für Kinder uninteressant
            TextButton(onClick = { elternOffen = true }) { Text("⚙", fontSize = 22.sp) }
        }

        // --- Text ------------------------------------------------------------
        LazyColumn(state = listState, modifier = Modifier.weight(1f).fillMaxWidth().padding(top = 8.dp)) {
            items(paragraphs.size) { pIdx ->
                ParagraphText(
                    tokens = paragraphs[pIdx],
                    andika = andika,
                    activeTokenI = angezeigterToken,
                    activeSentI = angezeigterSatz,
                    onWordTap = { token ->
                        if (quelle == Vorlesequelle.AUFNAHME) {
                            player.seekTo(token.t0)
                        } else {
                            onLiveStart(chapterIndex, token.sent)
                        }
                    },
                )
            }
        }

        // --- Bedienleiste: wenige, große Tasten -----------------------------
        Row(
            Modifier.fillMaxWidth().padding(top = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(24.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            BigIconButton(
                symbol = if (laeuft) "⏸" else "▶",
                beschriftung = if (laeuft) "Pause" else "Vorlesen",
                onClick = {
                    if (quelle == Vorlesequelle.AUFNAHME) {
                        if (player.isPlaying) player.pause() else player.play()
                    } else {
                        if (live.laeuft) onLiveStop()
                        else onLiveStart(chapterIndex, maxOf(angezeigterSatz, 0))
                    }
                },
            )
            BigIconButton(
                symbol = "⏮",
                beschriftung = "Nochmal",
                onClick = {
                    if (quelle == Vorlesequelle.AUFNAHME) {
                        player.seekTo((player.currentPosition - 15_000).coerceAtLeast(0))
                    } else {
                        onLiveStart(chapterIndex, maxOf(angezeigterSatz - 1, 0))
                    }
                },
            )
            Spacer(Modifier.weight(1f))
            StimmenTaste(stimmeName, quelle, onStimmeWaehlen, onQuelleWechseln)
        }
    }

    if (elternOffen) {
        ElternDialog(
            leadOffsetMs = leadOffsetMs,
            onLead = { leadOffsetMs = it },
            onSpike = { elternOffen = false; onOpenSpike() },
            onSchliessen = { elternOffen = false },
        )
    }
}

@Composable
private fun KapitelTaste(nummer: Int, aktiv: Boolean, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        colors = CardDefaults.cardColors(
            containerColor = if (aktiv) Color(0xFF7E57C2) else Color(0x14000000)
        ),
        modifier = Modifier.width(72.dp).height(64.dp),
    ) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Text(
                "$nummer",
                fontSize = 26.sp,
                fontWeight = FontWeight.Bold,
                color = if (aktiv) Color.White else Color.DarkGray,
            )
        }
    }
}

/** Zeigt die aktive Stimme; Tipp öffnet die Auswahl, langer Tipp wechselt die Quelle. */
@Composable
private fun StimmenTaste(
    stimmeName: String,
    quelle: Vorlesequelle,
    onStimmeWaehlen: () -> Unit,
    onQuelleWechseln: (Vorlesequelle) -> Unit,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Card(
            onClick = {
                onQuelleWechseln(
                    if (quelle == Vorlesequelle.AUFNAHME) Vorlesequelle.STIMME
                    else Vorlesequelle.AUFNAHME
                )
            },
            colors = CardDefaults.cardColors(
                containerColor = if (quelle == Vorlesequelle.AUFNAHME) Color(0x14000000)
                else Color(0xFF26A69A).copy(alpha = 0.18f)
            ),
            modifier = Modifier.height(64.dp),
        ) {
            // fillMaxSize wuerde die Karte auf die volle Zeilenbreite dehnen
            Box(
                Modifier.fillMaxHeight().padding(horizontal = 24.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    if (quelle == Vorlesequelle.AUFNAHME) "Aufnahme" else "Stimme",
                    fontSize = 18.sp,
                    fontWeight = FontWeight.Bold,
                )
            }
        }
        Spacer(Modifier.width(12.dp))
        BigIconButton("🗣", stimmeName, onClick = onStimmeWaehlen)
    }
}

@Composable
private fun ElternDialog(
    leadOffsetMs: Float,
    onLead: (Float) -> Unit,
    onSpike: () -> Unit,
    onSchliessen: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onSchliessen,
        title = { Text("Für Eltern") },
        text = {
            Column {
                Text("Vorlauf des Highlights: ${leadOffsetMs.toInt()} ms")
                Text(
                    "Sitzt die Markierung hinter dem Ton, weiter nach links schieben.",
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.Gray,
                )
                Slider(
                    value = leadOffsetMs,
                    onValueChange = onLead,
                    valueRange = -300f..100f,
                    modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
                )
                TextButton(onClick = onSpike, modifier = Modifier.padding(top = 8.dp)) {
                    Text("Technik-Spike öffnen")
                }
            }
        },
        confirmButton = { TextButton(onClick = onSchliessen) { Text("Fertig") } },
    )
}

@Composable
private fun ParagraphText(
    tokens: List<Token>,
    andika: FontFamily,
    activeTokenI: Int,
    activeSentI: Int,
    onWordTap: (Token) -> Unit,
) {
    val paraActive = tokens.any { it.i == activeTokenI || it.sent == activeSentI }
    val text = remember(tokens, if (paraActive) activeTokenI else -1, if (paraActive) activeSentI else -1) {
        buildParagraph(tokens, activeTokenI, activeSentI)
    }
    ClickableText(
        text = text,
        style = MaterialTheme.typography.bodyLarge.copy(
            fontFamily = andika,
            fontSize = 30.sp,
            lineHeight = 50.sp,
        ),
        modifier = Modifier.padding(bottom = 22.dp),
        onClick = { offset ->
            text.getStringAnnotations("token", offset, offset).firstOrNull()?.let { ann ->
                tokens.firstOrNull { it.i == ann.item.toInt() }?.let(onWordTap)
            }
        },
    )
}

private fun buildParagraph(tokens: List<Token>, activeTokenI: Int, activeSentI: Int): AnnotatedString =
    buildAnnotatedString {
        tokens.forEachIndexed { idx, token ->
            val style = when {
                token.i == activeTokenI -> SpanStyle(background = WordBg, fontWeight = FontWeight.Bold)
                token.sent == activeSentI -> SpanStyle(background = SentenceBg)
                else -> null
            }
            pushStringAnnotation("token", token.i.toString())
            if (style != null) withStyle(style) { append(token.w) } else append(token.w)
            pop()
            if (idx != tokens.lastIndex) {
                val next = tokens[idx + 1]
                val bothInSentence = token.sent == activeSentI && next.sent == activeSentI
                if (bothInSentence) withStyle(SpanStyle(background = SentenceBg)) { append(" ") }
                else append(" ")
            }
        }
    }

private fun java.io.File.toUri(): android.net.Uri = android.net.Uri.fromFile(this)
