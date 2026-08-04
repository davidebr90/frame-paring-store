package com.frameparing.tv

import org.json.JSONObject

// Modelli della playlist ricevuta dal server. Il parsing usa org.json
// (incluso in Android): nessuna libreria di serializzazione esterna.

data class PlaylistItem(
    val id: Int,
    val type: String,          // "image" | "video"
    val mime: String,
    val url: String,
    val filesize: Long,
    val checksum: String,      // sha1: usato come nome file locale e per validare i download
    val rotation: Int,         // 0 | 90 | 180 | 270
    val fit: String,           // "contain" | "cover"
    val duration: Int,         // secondi a schermo (solo immagini)
    val transitionIn: String,  // transizione di entrata, già risolta dal server
    val transitionOut: String, // transizione di uscita, già risolta dal server
    val transitionMs: Long,    // durata della transizione
    val muted: Boolean,        // solo video: true = riproduci senza audio
) {
    val localName: String
        get() {
            val ext = when (mime) {
                "image/jpeg" -> "jpg"
                "image/png" -> "png"
                "image/webp" -> "webp"
                "image/gif" -> "gif"
                "video/webm" -> "webm"
                else -> "mp4"
            }
            return "$checksum.$ext"
        }
}

// Fascia oraria di attività (valutata con l'orologio del dispositivo).
// Una fascia con from > to scavalca la mezzanotte (es. 22:00 -> 06:00).
data class TimeBand(val from: String, val to: String) {
    fun containsMinuteOfDay(minute: Int): Boolean {
        val start = toMinute(from)
        val end = toMinute(to)
        return when {
            start == end -> true // stesso orario = copertura 24 ore
            start < end -> minute in start until end
            else -> minute >= start || minute < end // scavalca la mezzanotte
        }
    }

    private fun toMinute(hhmm: String): Int =
        hhmm.substringBefore(':').toInt() * 60 + hhmm.substringAfter(':').toInt()
}

data class Playlist(
    val screenName: String,
    val version: String,
    val pollInterval: Int,
    val items: List<PlaylistItem>,
    val scheduleBands: List<TimeBand> = emptyList(), // vuoto = sempre attivo
    val idleMode: String = "black",                  // "black" | "image"
    val idleItem: PlaylistItem? = null,              // immagine di cortesia
) {
    fun isActiveAt(minuteOfDay: Int): Boolean =
        scheduleBands.isEmpty() || scheduleBands.any { it.containsMinuteOfDay(minuteOfDay) }

    companion object {
        fun parse(json: String): Playlist {
            val root = JSONObject(json)
            val arr = root.getJSONArray("items")
            val items = buildList {
                for (i in 0 until arr.length()) {
                    val o = arr.getJSONObject(i)
                    add(
                        PlaylistItem(
                            id = o.getInt("id"),
                            type = o.getString("type"),
                            mime = o.getString("mime"),
                            url = o.getString("url"),
                            filesize = o.getLong("filesize"),
                            // lowercase: il checksum è anche il nome del file in cache
                            checksum = o.getString("checksum").lowercase(),
                            rotation = o.getInt("rotation"),
                            fit = o.getString("fit"),
                            duration = o.getInt("duration"),
                            // opt*: playlist di server più vecchi restano valide
                            transitionIn = o.optString("transition_in", "cut"),
                            transitionOut = o.optString("transition_out", "cut"),
                            transitionMs = o.optLong("transition_ms", 600L),
                            muted = o.optBoolean("muted", true),
                        )
                    )
                }
            }
            val schedule = root.optJSONObject("schedule")
            val bands = buildList {
                val arr2 = schedule?.optJSONArray("bands")
                if (arr2 != null) {
                    for (i in 0 until arr2.length()) {
                        val b = arr2.getJSONObject(i)
                        val from = b.optString("from")
                        val to = b.optString("to")
                        if (Regex("""\d{2}:\d{2}""").matches(from) && Regex("""\d{2}:\d{2}""").matches(to)) {
                            add(TimeBand(from, to))
                        }
                    }
                }
            }
            val idleJson = schedule?.optJSONObject("idle_item")
            val idleItem = idleJson?.let { o ->
                PlaylistItem(
                    id = o.getInt("id"),
                    type = "image",
                    mime = o.getString("mime"),
                    url = o.getString("url"),
                    filesize = o.getLong("filesize"),
                    checksum = o.getString("checksum"),
                    rotation = o.optInt("rotation", 0),
                    fit = o.optString("fit", "contain"),
                    duration = 0,
                    transitionIn = "crossfade",
                    transitionOut = "crossfade",
                    transitionMs = 600L,
                    muted = true,
                )
            }

            return Playlist(
                screenName = root.getJSONObject("screen").getString("name"),
                version = root.getString("version"),
                pollInterval = root.getInt("poll_interval"),
                items = items,
                scheduleBands = bands,
                idleMode = schedule?.optString("idle_mode", "black") ?: "black",
                idleItem = idleItem,
            )
        }
    }
}
