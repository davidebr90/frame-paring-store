package com.frameparing.tv

import android.content.Context
import java.io.File

// Sincronizza playlist e media con il server e mantiene la cache locale.
// Il player legge SOLO file locali: se la rete manca, l'ultima playlist
// buona resta in loop (playback offline).
class SyncEngine(context: Context, private val prefs: Prefs) {

    private val mediaDir: File = File(context.filesDir, "media").apply { mkdirs() }

    fun localFile(item: PlaylistItem): File = File(mediaDir, item.localName)

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
        }
        mediaDir.listFiles()?.forEach { file ->
            if (file.name !in wanted) {
                file.delete()
            }
        }

        return playlist.copy(items = ready)
    }
}
