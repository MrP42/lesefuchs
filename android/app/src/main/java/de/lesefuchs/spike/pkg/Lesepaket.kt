package de.lesefuchs.spike.pkg

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Datenklassen für manifest.json / content.json (Konzept §4.3/§4.4). */

@Serializable
data class Manifest(
    val schema: String,
    val id: String,
    val title: String,
    val author: String? = null,
    val type: String,
    val language: String,
    val readingLevel: Int = 1,
    val pageCount: Int = 0,
    val durationMs: Long = 0,
    val voice: String? = null,
    val packageVersion: Int = 1,
    val chapters: List<ManifestChapter> = emptyList(),
)

@Serializable
data class ManifestChapter(
    val id: String,
    val title: String,
    val audio: String,
    val firstPage: Int = 1,
    val lastPage: Int = 1,
    val tokenStart: Int,
    val tokenEnd: Int,
)

@Serializable
data class Content(
    val tokens: List<Token>,
    val sentences: List<Sentence>,
    val paragraphs: List<Paragraph>,
)

@Serializable
data class Token(
    val i: Int,
    val w: String,
    val t0: Long,
    val t1: Long,
    val sent: Int,
    val para: Int,
    val syl: List<Syllable> = emptyList(),
    val src: String? = null,
)

@Serializable
data class Syllable(val s: String, val t0: Long, val t1: Long)

@Serializable
data class Sentence(
    val i: Int,
    val t0: Long,
    val t1: Long,
    val tokenStart: Int,
    val tokenEnd: Int,
    val page: Int = 1,
)

@Serializable
data class Paragraph(
    val i: Int,
    val page: Int = 1,
    val sentenceStart: Int,
    val sentenceEnd: Int,
)

/** Ein geladenes Paket: Metadaten + Inhalt + entpackte Audiodateien. */
data class Lesepaket(
    val manifest: Manifest,
    val content: Content,
    val audioFiles: Map<String, java.io.File>, // chapterId -> Opus-Datei
)
