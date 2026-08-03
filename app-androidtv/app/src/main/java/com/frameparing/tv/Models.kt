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

data class Playlist(
    val screenName: String,
    val version: String,
    val pollInterval: Int,
    val items: List<PlaylistItem>,
) {
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
                            checksum = o.getString("checksum"),
                            rotation = o.getInt("rotation"),
                            fit = o.getString("fit"),
                            duration = o.getInt("duration"),
                        )
                    )
                }
            }
            return Playlist(
                screenName = root.getJSONObject("screen").getString("name"),
                version = root.getString("version"),
                pollInterval = root.getInt("poll_interval"),
                items = items,
            )
        }
    }
}
