package de.lesefuchs.spike.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.text.ClickableText
import androidx.compose.material3.Button
import androidx.compose.material3.FilterChip
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
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
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

@Composable
fun PlayerScreen(paket: Lesepaket, player: ExoPlayer, onOpenSpike: () -> Unit) {
    val andika = remember { FontFamily(Font(R.font.andika_regular)) }
    var chapterIndex by remember { mutableIntStateOf(0) }
    var leadOffsetMs by remember { mutableStateOf(-60f) }
    var isPlaying by remember { mutableStateOf(false) }

    val chapter = paket.manifest.chapters[chapterIndex]
    val chapterTokens = remember(chapterIndex) {
        paket.content.tokens.subList(chapter.tokenStart, chapter.tokenEnd + 1)
    }
    val engine = remember(chapterIndex) {
        HighlightEngine(paket.content.copy(tokens = chapterTokens))
    }
    // Absätze des Kapitels in Anzeige-Reihenfolge
    val paragraphs = remember(chapterIndex) {
        chapterTokens.groupBy { it.para }.toSortedMap().values.toList()
    }

    var activeTokenI by remember { mutableIntStateOf(-1) }
    var activeSentI by remember { mutableIntStateOf(-1) }

    // Kapitel in den Player laden
    LaunchedEffect(chapterIndex) {
        val file = paket.audioFiles.getValue(chapter.id)
        player.setMediaItem(androidx.media3.common.MediaItem.fromUri(file.toUri()))
        player.prepare()
        activeTokenI = -1
        activeSentI = -1
    }

    // Frame-Loop (Konzept §5.3): keine Timer, Aktualisierung mit jedem Frame
    LaunchedEffect(chapterIndex) {
        while (isActive) {
            withFrameNanos { }
            isPlaying = player.isPlaying
            val pos = player.currentPosition - leadOffsetMs.toLong() // −60 ⇒ Auge vor Ohr
            val state = engine.stateAt(pos)
            if (state != null) {
                if (state.token.i != activeTokenI) activeTokenI = state.token.i
                if (state.sentence.i != activeSentI) activeSentI = state.sentence.i
            }
        }
    }

    val listState = rememberLazyListState()
    // Auto-Scroll zum aktiven Absatz (400 ms Übergang laut Konzept — animateScroll)
    val activeParaIndex = paragraphs.indexOfFirst { tokens ->
        tokens.any { it.i == activeTokenI }
    }
    LaunchedEffect(activeParaIndex) {
        if (activeParaIndex >= 0) listState.animateScrollToItem(activeParaIndex)
    }

    Column(Modifier.fillMaxSize().padding(24.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(paket.manifest.title, style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.width(16.dp))
            paket.manifest.chapters.forEachIndexed { i, ch ->
                FilterChip(
                    selected = i == chapterIndex,
                    onClick = { chapterIndex = i },
                    label = { Text(ch.title) },
                    modifier = Modifier.padding(end = 8.dp),
                )
            }
            Spacer(Modifier.weight(1f))
            TextButton(onClick = onOpenSpike) { Text("Technik-Spike") }
        }

        LazyColumn(state = listState, modifier = Modifier.weight(1f).fillMaxWidth()) {
            items(paragraphs.size) { pIdx ->
                ParagraphText(
                    tokens = paragraphs[pIdx],
                    andika = andika,
                    activeTokenI = activeTokenI,
                    activeSentI = activeSentI,
                    onWordTap = { token -> player.seekTo(token.t0) },
                )
            }
        }

        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            Button(onClick = { if (player.isPlaying) player.pause() else player.play() }) {
                Text(if (isPlaying) "⏸ Pause" else "▶ Vorlesen", fontSize = 20.sp)
            }
            Button(onClick = { player.seekTo((player.currentPosition - 10_000).coerceAtLeast(0)) }) {
                Text("⏪ 10 s")
            }
            Text("Lead ${leadOffsetMs.toInt()} ms")
            Slider(
                value = leadOffsetMs,
                onValueChange = { leadOffsetMs = it },
                valueRange = -200f..100f,
                modifier = Modifier.width(220.dp),
            )
        }
    }
}

@Composable
private fun ParagraphText(
    tokens: List<Token>,
    andika: FontFamily,
    activeTokenI: Int,
    activeSentI: Int,
    onWordTap: (Token) -> Unit,
) {
    // AnnotatedString nur neu bauen, wenn sich das aktive Wort/der Satz ändert
    // UND dieser Absatz betroffen ist (sonst stabile Referenz aus remember).
    val paraActive = tokens.any { it.i == activeTokenI || it.sent == activeSentI }
    val text = remember(tokens, if (paraActive) activeTokenI else -1, if (paraActive) activeSentI else -1) {
        buildParagraph(tokens, activeTokenI, activeSentI)
    }
    ClickableText(
        text = text,
        style = MaterialTheme.typography.bodyLarge.copy(
            fontFamily = andika,
            fontSize = 28.sp,
            lineHeight = 46.sp,
        ),
        modifier = Modifier.padding(bottom = 20.dp),
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
            if (idx != tokens.lastIndex) append(" ")
        }
    }

private fun java.io.File.toUri(): android.net.Uri = android.net.Uri.fromFile(this)
