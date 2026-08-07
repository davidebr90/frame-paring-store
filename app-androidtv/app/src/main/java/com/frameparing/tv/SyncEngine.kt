package com.frameparing.tv

import android.content.Context
import android.media.MediaMetadataRetriever
import java.io.File

// Sincronizza playlist e media con il server e mantiene la cache locale.
// Il player legge SOLO file locali: se la rete manca, l'ultima playlist
// buona resta in loop (playback offline).
class SyncEngine(context: Context, private val prefs: Prefs) {

    private companion object {
        // Quanto resta in cache il contenuto di una diretta dopo l'ultimo uso.
        // Abbondante rispetto a qualsiasi diretta reale: se una in corso
        // superasse questa soglia il file verrebbe semplicemente riscaricato.
        const val LIVE_CACHE_MAX_AGE_MS = 7L * 24 * 60 * 60 * 1000
    }

    private val mediaDir: File = File(context.filesDir, "media").apply { mkdirs() }

    fun localFile(item: PlaylistItem): File = File(mediaDir, item.localName)

    fun localFile(override: Override): File? =
        override.localName?.let { File(mediaDir, it) }

    fun localFile(banner: Banner): File? =
        banner.localLogoName?.let { File(mediaDir, it) }

    // Scarica il logo del banner se non è già in cache. Il nome contiene il
    // tag del server: sostituire il logo cambia il nome e forza il download,
    // lasciarlo invariato lo evita del tutto.
    suspend fun ensureBannerLogo(banner: Banner?): File? {
        val url = banner?.logoUrl ?: return null
        val file = localFile(banner) ?: return null
        val token = prefs.authToken ?: return null
        if (file.exists() && file.length() > 0) {
            return file
        }
        return try {
            ApiClient.downloadTo(url, token, file)
            file
        } catch (e: Exception) {
            android.util.Log.w("FPS", "Download del logo del banner fallito", e)
            null
        }
    }

    // Scarica il contenuto di una diretta se non è già in cache. I file
    // delle dirette hanno prefisso "live-" e restano fuori dalla pulizia
    // ordinaria finché la diretta è in corso.
    suspend fun ensureOverrideCached(override: Override): File? {
        val token = prefs.authToken ?: return null
        val file = localFile(override) ?: return null
        if (file.exists() && (override.filesize == 0L || file.length() == override.filesize)) {
            return file
        }
        val item = PlaylistItem(
            id = override.id, type = override.mediaType, mime = override.mime,
            url = override.url, filesize = override.filesize,
            checksum = override.checksum ?: return null,
            rotation = override.rotation, fit = override.fit, duration = 0,
            transitionIn = "cut", transitionOut = "cut", transitionMs = 0L,
            muted = override.muted,
        )
        return try {
            ApiClient.downloadMedia(item, token, file)
            file
        } catch (e: Exception) {
            android.util.Log.w("FPS", "Download della diretta fallito", e)
            null
        }
    }

    // Scarica i file di una diretta su galleria. Ritorna solo quelli davvero
    // pronti: se qualcuno manca si riproduce ciò che c'è invece di restare
    // fermi sulla schermata di sincronizzazione.
    suspend fun ensureOverrideItems(override: Override): List<PlaylistItem> {
        val token = prefs.authToken ?: return emptyList()
        val pronti = mutableListOf<PlaylistItem>()
        for (item in override.items) {
            val file = localFile(item)
            if (file.exists() && file.length() == item.filesize) {
                pronti.add(item)
                continue
            }
            try {
                ApiClient.downloadMedia(item, token, file)
                pronti.add(item)
            } catch (e: Exception) {
                android.util.Log.w("FPS", "Diretta: file ${item.id} non scaricato", e)
            }
        }
        return pronti
    }

    // Durata reale di un video, letta dal file in cache. Tutti gli schermi
    // hanno lo stesso identico file, quindi tutti leggono lo stesso numero:
    // è ciò che rende la timeline della diretta deterministica ovunque.
    fun probeVideoDurationMs(item: PlaylistItem): Long? {
        val file = localFile(item)
        if (!file.exists()) return null
        // Niente use{}: MediaMetadataRetriever è AutoCloseable solo da API 29
        // e qui si arriva fino ad API 22 (Fire OS 5)
        val retriever = MediaMetadataRetriever()
        return try {
            retriever.setDataSource(file.path)
            retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull()
        } catch (e: Exception) {
            android.util.Log.w("FPS", "Durata di ${item.id} non leggibile", e)
            null
        } finally {
            runCatching { retriever.release() }
        }
    }

    // Playlist riproducibile ORA: quella in cache, filtrata sui file presenti.
    fun cachedPlaylist(): Playlist? =
        prefs.playlistJson?.let { json ->
            runCatching { Playlist.parse(json) }.getOrNull()
        }?.let { pl ->
            pl.copy(items = pl.items.filter { localFile(it).exists() })
        }

    // Scarica la playlist dal server e porta la cache al suo stato.
    // Ritorna la playlist aggiornata (solo item con file locale pronto),
    // o null se il server non è raggiungibile. Lancia ApiException(401/403)
    // se il token è stato revocato: il chiamante torna al pairing.
    suspend fun sync(): Playlist? {
        val base = prefs.baseUrl ?: return null
        val token = prefs.authToken ?: return null

        val raw = try {
            ApiClient.playlistRaw(base, token)
        } catch (e: ApiException) {
            if (e.code == 401 || e.code == 403) throw e
            return null // errore server temporaneo: si continua con la cache
        } catch (_: Exception) {
            return null // rete assente
        }

        // Risposta malformata (proxy, errore transitorio del server, ...):
        // non deve MAI abbattere il ciclo di sync, si riprova al prossimo giro
        val playlist = try {
            Playlist.parse(raw)
        } catch (e: Exception) {
            android.util.Log.w("FPS", "Playlist malformata, la ignoro", e)
            return null
        }
        prefs.pollInterval = playlist.pollInterval

        // Scarica solo ciò che manca (il nome file È lo sha1 del contenuto)
        val ready = mutableListOf<PlaylistItem>()
        for (item in playlist.items) {
            val file = localFile(item)
            if (file.exists() && file.length() == item.filesize) {
                ready.add(item)
                continue
            }
            try {
                ApiClient.downloadMedia(item, token, file)
                ready.add(item)
            } catch (e: ApiException) {
                // Un 401/403 sul singolo media NON è una revoca (può venire da
                // WAF/CDN/hotlink protection): la revoca la dichiara solo la
                // playlist, che è appena passata. Qui si salta e si ritenta.
                android.util.Log.w("FPS", "Download ${item.id} fallito: ${e.message}")
            } catch (e: Exception) {
                android.util.Log.w("FPS", "Download ${item.id} errore IO", e)
            }
        }

        // Anche l'immagine di cortesia va in cache (serve fuori fascia, offline)
        playlist.idleItem?.let { idle ->
            val file = localFile(idle)
            if (!file.exists() || file.length() != idle.filesize) {
                try {
                    ApiClient.downloadMedia(idle, token, file)
                } catch (_: Exception) {
                    // come sopra: un download fallito non è mai una revoca
                }
            }
        }

        // La cache diventa ufficiale solo ora; poi si eliminano i file orfani.
        // Anche i .part: qui nessun download è in corso, quindi un .part è
        // sempre un residuo (es. app uccisa a metà download) da spazzare.
        prefs.playlistJson = raw
        val wanted = buildSet {
            playlist.items.forEach { add(it.localName) }
            playlist.idleItem?.let { add(it.localName) }
            // Senza questa riga il logo del banner verrebbe cancellato a
            // ogni sincronizzazione e riscaricato di continuo
            playlist.banner?.localLogoName?.let { add(it) }
        }
        val now = System.currentTimeMillis()
        mediaDir.listFiles()?.forEach { file ->
            // Il .part di un file atteso può essere un download in corso su
            // un'altra coroutine (il logo del banner): cancellarlo lo farebbe
            // fallire e ripartire all'infinito. Restano invece da spazzare i
            // .part orfani, residui di un'app uccisa a metà scaricamento.
            val base = file.name.removeSuffix(".part")
            // I file delle dirette (prefisso live-) non seguono la playlist:
            // una diretta può attingere a qualsiasi galleria. Si eliminano
            // quando sono vecchi, altrimenti si accumulerebbero senza limite
            // riempiendo la memoria del box a ogni diretta di un file nuovo.
            if (file.name.startsWith("live-")) {
                if (now - file.lastModified() > LIVE_CACHE_MAX_AGE_MS) {
                    file.delete()
                }
            } else if (base !in wanted) {
                file.delete()
            }
        }

        return playlist.copy(items = ready)
    }
}
