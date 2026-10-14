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
    // I file di una diretta non appartengono alla playlist dello schermo: il
    // prefisso live- li tiene fuori dalla pulizia della cache, che altrimenti
    // li cancellerebbe al primo aggiornamento
    val live: Boolean = false,
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
            return (if (live) "live-" else "") + checksum + "." + ext
        }

    companion object {
        // Un elemento della playlist letto da JSON, condiviso fra la playlist
        // dello schermo e gli item di una diretta su galleria
        fun parse(o: JSONObject, live: Boolean = false): PlaylistItem = PlaylistItem(
            id = o.getInt("id"),
            type = o.getString("type"),
            mime = o.getString("mime"),
            url = o.getString("url"),
            filesize = o.getLong("filesize"),
            // lowercase: il checksum è anche il nome del file in cache
            checksum = o.getString("checksum").lowercase(),
            rotation = o.optInt("rotation", 0),
            fit = o.optString("fit", "contain"),
            duration = o.optInt("duration", 8),
            // opt*: playlist di server più vecchi restano valide
            transitionIn = o.optString("transition_in", "cut"),
            transitionOut = o.optString("transition_out", "cut"),
            transitionMs = o.optLong("transition_ms", 600L),
            muted = o.optBoolean("muted", true),
            live = live,
        )
    }
}

// Fascia di accensione, valutata con l'orologio del dispositivo: la
// programmazione continua a valere anche a rete assente.
//
// `days` usa la numerazione ISO (1 = lunedì … 7 = domenica). Vuoto significa
// tutti i giorni: è così che restano valide le programmazioni salvate prima
// che i giorni esistessero.
//
// Una fascia con from > to scavalca la mezzanotte (es. 22:00 -> 06:00):
// COMINCIA nel giorno indicato e finisce il mattino dopo. Scegliendo il
// sabato, l'accensione copre il sabato sera e le prime ore di domenica.
data class TimeBand(
    val from: String,
    val to: String,
    val days: List<Int> = emptyList(),
) {
    fun contains(dayOfWeek: Int, minute: Int): Boolean {
        val start = toMinute(from)
        val end = toMinute(to)
        if (start == end) return false

        if (start < end) {
            return coversDay(dayOfWeek) && minute in start until end
        }
        // Scavalca la mezzanotte: sera del giorno scelto…
        if (coversDay(dayOfWeek) && minute >= start) return true
        // …oppure mattina successiva a un giorno scelto
        val ieri = if (dayOfWeek == 1) 7 else dayOfWeek - 1
        return coversDay(ieri) && minute < end
    }

    private fun coversDay(day: Int): Boolean = days.isEmpty() || days.contains(day)

    private fun toMinute(hhmm: String): Int =
        hhmm.substringBefore(':').toInt() * 60 + hhmm.substringAfter(':').toInt()
}

// Diretta in sovrapposizione: contenuto temporaneo che copre la
// riproduzione normale. La playlist sottostante resta intatta e riprende
// da sola alla fine.
data class Override(
    val id: Int,
    val name: String,
    val type: String,        // "media" | "external"
    val mediaType: String,   // "image" | "video"
    val mime: String,
    val url: String,
    val checksum: String?,   // null per i flussi esterni (non si scaricano)
    val filesize: Long,
    val rotation: Int,
    val fit: String,
    val muted: Boolean,
    val loop: Boolean,
    val elapsedSeconds: Long, // da quanto è iniziata: serve a partire allineati
    // Valorizzati solo quando type == "gallery": la TV li riproduce in loop
    // con il proprio player, come una playlist qualsiasi
    val items: List<PlaylistItem> = emptyList(),
    val galleryName: String = "",
    // Impronta del contenuto della galleria: cambia quando la galleria
    // cambia, e fa riscaricare l'elenco anche a diretta in corso
    val itemsSig: String = "",
    // Istante locale in cui la risposta è stata letta. `elapsed` vale in
    // QUEL momento: qualsiasi lavoro fatto dopo (download dei file di una
    // galleria, code sul thread principale) lo renderebbe stantio, quindi
    // l'ancora del ciclo va calcolata da qui e non da "adesso"
    val parsedAtRealtime: Long = 0L,
) {
    val localName: String?
        get() = checksum?.let { "live-$it" }

    companion object {
        fun parse(o: JSONObject?): Override? {
            if (o == null) return null
            return Override(
                id = o.getInt("id"),
                name = o.optString("name"),
                type = o.optString("type", "media"),
                mediaType = o.optString("media_type", "video"),
                mime = o.optString("mime", ""),
                // Una diretta su galleria non ha una URL sola: sta negli item.
                // getString qui farebbe fallire il parsing dell'intera diretta.
                url = o.optString("url"),
                checksum = o.optString("checksum").takeIf { it.isNotBlank() && it != "null" },
                filesize = o.optLong("filesize", 0),
                rotation = o.optInt("rotation", 0),
                fit = o.optString("fit", "contain"),
                muted = o.optBoolean("muted", false),
                loop = o.optBoolean("loop", true),
                elapsedSeconds = o.optLong("elapsed", 0),
                items = buildList {
                    val arr = o.optJSONArray("items")
                    if (arr != null) {
                        for (i in 0 until arr.length()) {
                            add(PlaylistItem.parse(arr.getJSONObject(i), live = true))
                        }
                    }
                },
                galleryName = o.optString("gallery_name"),
                itemsSig = o.optString("items_sig"),
                parsedAtRealtime = android.os.SystemClock.elapsedRealtime(),
            )
        }
    }
}

// Banner in sovraimpressione: logo e/o data e ora sopra il contenuto.
// La data e l'ora si compongono qui con l'orologio del dispositivo, non
// arrivano dal server: restano corrette anche offline e cambiano da sole
// a mezzanotte senza attendere un polling.
data class Banner(
    val position: String,   // top_left … bottom_right (nove ancoraggi)
    val size: String,       // "small" | "medium" | "large"
    val opacity: Int,       // opacità del fondo, 0-100
    val showDate: Boolean,
    val showTime: Boolean,
    val dateFormat: String, // "short" (24/06/2026) | "long" (24 Giugno 2026)
    val logoUrl: String?,   // null se il logo non va mostrato
    val logoTag: String?,   // cambia a ogni sostituzione: guida la cache
) {
    val localLogoName: String?
        get() = logoTag?.let { "banner-$it" }

    companion object {
        fun parse(o: JSONObject?): Banner? {
            if (o == null) return null
            return Banner(
                position = o.optString("position", "bottom_right"),
                size = o.optString("size", "medium"),
                opacity = o.optInt("opacity", 80),
                showDate = o.optBoolean("show_date", true),
                showTime = o.optBoolean("show_time", true),
                dateFormat = o.optString("date_format", "short"),
                logoUrl = o.optString("logo_url").takeIf { it.isNotBlank() && it != "null" },
                logoTag = o.optString("logo_tag").takeIf { it.isNotBlank() && it != "null" },
            )
        }
    }
}

data class Playlist(
    val version: String,
    val pollInterval: Int,
    val items: List<PlaylistItem>,
    val scheduleBands: List<TimeBand> = emptyList(), // vuoto = sempre attivo
    val idleMode: String = "black",                  // "black" | "image"
    val idleItem: PlaylistItem? = null,              // immagine di cortesia
    val override: Override? = null,                  // diretta in corso
    val banner: Banner? = null,                      // sovraimpressione, null = spenta
    val language: String? = null,                    // lingua scelta nel CMS
    // Di quanto è girato il pannello fisico rispetto al segnale (0/90/180/270,
    // senso orario): serve solo a far atterrare la sovraimpressione sul lato
    // giusto. Le gallerie non c'entrano, hanno una rotazione propria per file.
    val screenRotation: Int = 0,
    // Permesso di usare il colore a 16 bit sui dispositivi con poca memoria
    // (vedi PlayerController.lowColorAllowed). Spento salvo richiesta
    // esplicita dal CMS: dimezza la memoria ma lascia bande nelle zone
    // piatte delle fotografie.
    val lowColor: Boolean = false,
) {
    fun isActiveAt(dayOfWeek: Int, minuteOfDay: Int): Boolean =
        scheduleBands.isEmpty() || scheduleBands.any { it.contains(dayOfWeek, minuteOfDay) }

    companion object {
        fun parse(json: String): Playlist {
            val root = JSONObject(json)
            val arr = root.getJSONArray("items")
            val items = buildList {
                for (i in 0 until arr.length()) {
                    add(PlaylistItem.parse(arr.getJSONObject(i)))
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
                            // Giorni assenti = tutti i giorni: le playlist
                            // di server più vecchi restano valide
                            val giorni = buildList {
                                val arr3 = b.optJSONArray("days")
                                if (arr3 != null) {
                                    for (k in 0 until arr3.length()) {
                                        val d = arr3.optInt(k, 0)
                                        if (d in 1..7) add(d)
                                    }
                                }
                            }
                            add(TimeBand(from, to, giorni))
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
                version = root.getString("version"),
                pollInterval = root.getInt("poll_interval"),
                items = items,
                scheduleBands = bands,
                idleMode = schedule?.optString("idle_mode", "black") ?: "black",
                idleItem = idleItem,
                override = Override.parse(root.optJSONObject("override")),
                banner = Banner.parse(root.optJSONObject("banner")),
                language = root.optJSONObject("branding")
                    ?.optString("language")
                    ?.takeIf { it.isNotBlank() },
                screenRotation = root.optJSONObject("screen")
                    ?.optInt("rotation", 0)
                    ?.takeIf { it == 90 || it == 180 || it == 270 } ?: 0,
                // Assente nelle playlist di server precedenti la 1.15.0:
                // in quel caso vale false, cioè colore pieno
                lowColor = root.optJSONObject("playback")?.optBoolean("low_color", false) ?: false,
            )
        }
    }
}
