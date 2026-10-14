package com.frameparing.tv

import android.content.Context
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.view.Gravity
import android.view.TextureView
import android.view.View
import android.widget.FrameLayout
import android.widget.ImageView
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.VideoSize
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.random.Random

// Riproduce la playlist in loop applicando i metadati del CMS:
// rotazione (0/90/180/270), fit (contain/cover con ritaglio reale),
// transizioni di entrata/uscita con durata configurabile, audio on/off.
//
// La view entrante viene aggiunta SOPRA quella corrente e la transizione
// parte solo quando il contenuto è pronto (bitmap decodificata o primo
// frame video renderizzato): mai schermate nere tra un media e l'altro.
// Due ExoPlayer in alternanza permettono il crossfade video->video.
class PlayerController(
    private val context: Context,
    private val container: FrameLayout,
    private val sync: SyncEngine,
    private val scope: CoroutineScope,
) {

    private companion object {
        // Buffer dei flussi esterni. Il minimo tiene la riproduzione al
        // riparo dalle oscillazioni di banda; l'attesa iniziale è più lunga
        // di quella di serie perché su un pannello in vetrina è preferibile
        // partire un attimo dopo che scattare durante.
        const val BUFFER_MIN_MS = 20_000
        const val BUFFER_MAX_MS = 60_000
        const val BUFFER_FOR_PLAYBACK_MS = 4_000
        const val BUFFER_AFTER_REBUFFER_MS = 10_000
        // 64 MB: circa mezzo minuto a 16 Mbit/s, oltre due minuti a 4 Mbit/s.
        // Su un box con poca memoria è una fetta enorme, quindi lì si
        // scende a 16 MB: qualche secondo in meno di margine contro le
        // oscillazioni di banda, in cambio di non farsi uccidere.
        const val TARGET_BUFFER_BYTES = 64 * 1024 * 1024
        const val TARGET_BUFFER_BYTES_LOW = 16 * 1024 * 1024
        const val BUFFER_MIN_MS_LOW = 10_000
        const val BUFFER_MAX_MS_LOW = 30_000

        // File LOCALE: il buffer di serie di ExoPlayer è tarato sullo
        // streaming da rete (50 secondi, con un tetto di allocazione da
        // 125 MB per la traccia video). Qui i byte arrivano dal disco del
        // dispositivo: pochi secondi coprono ampiamente anche una eMMC
        // lenta, e ogni secondo in più è memoria tolta al resto del
        // sistema su un box che ne ha poca.
        const val LOCAL_BUFFER_MIN_MS = 2_000
        const val LOCAL_BUFFER_MAX_MS = 5_000
        const val LOCAL_BUFFER_FOR_PLAYBACK_MS = 1_000
        const val LOCAL_BUFFER_AFTER_REBUFFER_MS = 2_000
        const val LOCAL_TARGET_BUFFER_BYTES = 8 * 1024 * 1024

        // Un timer di cambio file che si sveglia entro questa distanza dal
        // confine del proprio slot "è" quel confine (vedi next)
        const val BOUNDARY_SNAP_MS = 600L
    }

    // Colore a 16 bit sui dispositivi con poca memoria: dimezza la memoria
    // di ogni foto, ma nelle zone piatte (cieli, pareti, pelle) i valori
    // saltano di otto livelli alla volta e si vedono le bande. Su questo
    // impianto si vedevano eccome, ed è una vetrina fotografica: il
    // risparmio non vale il difetto, quindi di serie è SPENTO e lo si
    // riaccende dal CMS solo se un dispositivo davvero non ce la fa.
    // Impostato da MainActivity a ogni playlist.
    @Volatile var lowColorAllowed = false

    private val handler = Handler(Looper.getMainLooper())
    private val players = arrayOfNulls<ExoPlayer>(2)
    private val playerListeners = arrayOfNulls<Player.Listener>(2)
    // Usato solo dalle dirette da sorgente esterna (flussi di rete)
    private var streamPlayer: ExoPlayer? = null
    private var streamListener: Player.Listener? = null

    private var items: List<PlaylistItem> = emptyList()
    private var index = 0
    private var running = false
    private var showToken = 0          // invalida i callback degli show precedenti

    // Sincronia delle dirette su galleria: durate degli slot, ancora
    // temporale del ciclo e istante di presentazione dell'item corrente
    private var syncSlots: List<Long>? = null
    private var syncAnchor = 0L        // elapsedRealtime a cui il ciclo vale zero
    private var itemShownAt = 0L

    private var currentView: View? = null
    private var currentItem: PlaylistItem? = null
    private var currentSlot = -1       // slot ExoPlayer del video corrente (-1 = immagine)

    // Transizioni "vere" tra cui pesca 'random'
    private val randomPool = listOf(
        "fade", "crossfade", "slide_left", "slide_right",
        "slide_up", "slide_down", "zoom_in", "zoom_out",
    )

    // Riproduzione allineata: la posizione nel ciclo non è "da capo" ma
    // quella in cui si troverebbe uno schermo partito all'istante ancora.
    // Tutti gli schermi calcolano dalla stessa ancora sulle stesse durate,
    // quindi tutti cadono nello stesso punto.
    fun playSynced(playlist: Playlist, slots: List<Long>, anchorRealtime: Long) {
        stop()
        items = playlist.items
        if (items.isEmpty() || slots.size != items.size) {
            play(playlist)
            return
        }
        running = true
        syncSlots = slots
        syncAnchor = anchorRealtime
        val pos = syncPosition() ?: (0 to 0L)
        index = pos.first
        show(items[index], startOffsetMs = pos.second)
    }

    // Dove si trova ADESSO il ciclo, misurato dall'ancora. È il cuore
    // dell'aggancio assoluto: ogni cambio di file riparte da qui, quindi i
    // ritardi di decodifica non possono sommarsi nel tempo.
    private fun syncPosition(): Pair<Int, Long>? {
        val slots = syncSlots ?: return null
        val cycle = slots.sum().coerceAtLeast(1L)
        var resto = ((SystemClock.elapsedRealtime() - syncAnchor) % cycle + cycle) % cycle
        var idx = 0
        while (idx < slots.size && resto >= slots[idx]) {
            resto -= slots[idx]
            idx++
        }
        if (idx >= slots.size) { idx = 0; resto = 0L }
        return idx to resto
    }

    // Sposta l'ancora (l'orologio del server è arrivato con l'ultimo
    // battito): la correzione agirà al prossimo cambio di file
    fun updateAnchor(anchorRealtime: Long) {
        if (syncSlots != null) {
            syncAnchor = anchorRealtime
        }
    }

    fun currentItems(): List<PlaylistItem>? = items.takeIf { it.isNotEmpty() }

    // Vero se un video allineato ha superato il proprio slot ed è ancora in
    // riproduzione (decodifica più lenta del tempo reale, durata dai
    // metadati ottimista). La sua fine si ri-aggancia da sola in modo
    // assoluto: un riallineamento a strappo nel frattempo peggiorerebbe.
    fun inVideoOverrun(): Boolean {
        val slots = syncSlots ?: return false
        if (currentItem?.type != "video" || index !in slots.indices || itemShownAt == 0L) return false
        return SystemClock.elapsedRealtime() - itemShownAt > slots[index]
    }

    // Posizione attuale dentro il ciclo, per il ri-allineamento periodico
    fun playbackGlobalMs(): Long? {
        val slots = syncSlots ?: return null
        if (index !in slots.indices || itemShownAt == 0L) return null
        val dentro = (SystemClock.elapsedRealtime() - itemShownAt).coerceIn(0L, slots[index])
        return slots.take(index).sum() + dentro
    }

    fun play(playlist: Playlist) {
        stop()
        syncSlots = null
        // Tornati alla programmazione ordinaria il player dei flussi non
        // serve più: tenerlo vivo significherebbe tenere occupati i suoi
        // decine di MB di buffer per tutto il resto della giornata
        streamPlayer?.release()
        streamPlayer = null
        streamListener = null
        items = playlist.items
        if (items.isEmpty()) return
        running = true
        index = 0
        show(items[0])
    }

    fun stop() {
        running = false
        showToken++
        handler.removeCallbacksAndMessages(null)
        players.forEach { it?.stop() }
        streamPlayer?.stop()
        // Le foto ancora appese alle view se ne vanno con loro: senza
        // questo, fermare la riproduzione lascerebbe in memoria l'ultimo
        // contenuto per tutto il tempo in cui lo schermo resta fermo
        for (i in 0 until container.childCount) {
            releaseImage(container.getChildAt(i))
        }
        container.removeAllViews()
        currentView = null
        currentItem = null
        currentSlot = -1
    }

    // Il sistema segnala che la memoria scarseggia: si molla tutto ciò
    // che si può mollare senza interrompere quello che è in onda.
    // Chiamata da MainActivity.onTrimMemory().
    //
    // Non tocca il player del contenuto corrente: fermarlo per liberare
    // memoria significherebbe spegnere la vetrina esattamente nel momento
    // in cui il sistema sta decidendo se valga la pena tenerci vivi, che è
    // il contrario di quel che serve.
    fun trimMemory() {
        var liberati = 0
        players.forEachIndexed { i, p ->
            if (i != currentSlot && p != null) {
                playerListeners[i]?.let { p.removeListener(it) }
                playerListeners[i] = null
                p.release()
                players[i] = null
                liberati++
            }
        }
        // Il player dei flussi tiene il buffer più grosso di tutti: se in
        // questo momento non sta riproducendo lui, se ne va per primo
        if (currentSlot != -1 || currentItem?.type != "video") {
            streamPlayer?.let {
                streamListener?.let { l -> it.removeListener(l) }
                it.release()
                liberati++
            }
            streamPlayer = null
            streamListener = null
        }
        // Le view superate che non fossero ancora state ripulite
        for (i in container.childCount - 1 downTo 0) {
            val child = container.getChildAt(i)
            if (child !== currentView) {
                container.removeView(child)
                releaseImage(child)
            }
        }
        if (liberati > 0) {
            FileLogger.i("Memoria scarsa: rilasciati $liberati player non in uso")
        }
    }

    fun release() {
        stop()
        players.forEachIndexed { i, p ->
            p?.release()
            players[i] = null
        }
        // Il player dei flussi tiene decine di MB di buffer: va liberato
        streamPlayer?.release()
        streamPlayer = null
        streamListener = null
    }

    // Diretta in sovrapposizione: sospende il loop e mostra il contenuto
    // indicato. La playlist resta in memoria: alla fine si riprende con
    // play() senza aver perso nulla.
    // Per i file, elapsedSeconds permette a tutti gli schermi di partire
    // dallo stesso punto anche se accesi in momenti diversi.
    fun playOverride(override: Override, localFile: File?) {
        stop()
        running = false
        showToken++
        val token = showToken

        if (override.mediaType == "image") {
            val path = localFile?.path ?: return
            scope.launch {
                val bounds = withContext(Dispatchers.IO) {
                    BitmapFactory.Options().apply { inJustDecodeBounds = true }
                        .also { BitmapFactory.decodeFile(path, it) }
                }
                if (token != showToken) return@launch
                val (tw, th) = displayedSize(bounds.outWidth, bounds.outHeight, override.rotation, override.fit)
                val bitmap = withContext(Dispatchers.IO) {
                    decodeScaled(path, tw, th, allowLowColor = override.mime == "image/jpeg")
                }
                if (token != showToken || bitmap == null) {
                    bitmap?.recycle()
                    return@launch
                }
                val view = ImageView(context).apply {
                    scaleType = ImageView.ScaleType.FIT_XY
                    setImageBitmap(bitmap)
                }
                container.addView(view, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))
                applyTransform(view, bitmap.width, bitmap.height, override.rotation, override.fit)
            }
            return
        }

        // Video: file locale per le dirette da file, indirizzo remoto per i
        // flussi esterni (che non si scaricano)
        val uri = when {
            localFile != null -> Uri.fromFile(localFile)
            else -> Uri.parse(override.url)
        }

        // File in cache: player normale. Flusso di rete: player dedicato,
        // tarato per non scattare sulle sorgenti ad alto bitrate.
        val isStream = localFile == null
        val slot = if (isStream) -1 else if (currentSlot == 0) 1 else 0
        val player = if (isStream) streamingPlayer() else pooledPlayer(slot)
        val previous = if (isStream) streamListener else playerListeners[slot]
        previous?.let { player.removeListener(it) }

        val texture = TextureView(context).apply { alpha = 0f }
        container.addView(texture, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))
        var presented = false

        val listener = object : Player.Listener {
            override fun onVideoSizeChanged(videoSize: VideoSize) {
                if (token != showToken || videoSize.width == 0 || presented) return
                presented = true
                currentView = texture
                currentSlot = slot
                container.bringChildToFront(texture)
                applyTransform(
                    texture, videoSize.width, videoSize.height,
                    (override.rotation + videoSize.unappliedRotationDegrees) % 360,
                    override.fit,
                )
                texture.alpha = 1f
            }

            override fun onPlayerError(error: PlaybackException) {
                // Un flusso che cade non deve lasciare lo schermo bloccato:
                // si ritenta dopo qualche secondo
                if (token == showToken) {
                    handler.postDelayed({
                        if (token == showToken) {
                            player.prepare()
                            player.play()
                        }
                    }, 5_000)
                }
            }
        }
        if (isStream) streamListener = listener else playerListeners[slot] = listener
        player.addListener(listener)

        player.setVideoTextureView(texture)
        player.volume = if (override.muted) 0f else 1f
        player.repeatMode = if (override.loop) Player.REPEAT_MODE_ONE else Player.REPEAT_MODE_OFF
        player.setMediaItem(MediaItem.fromUri(uri))
        player.prepare()
        // Partenza allineata fra schermi: ci si posiziona al punto in cui
        // si troverebbe chi ha iniziato all'avvio della diretta
        if (localFile != null && override.elapsedSeconds > 0) {
            player.seekTo(override.elapsedSeconds * 1000)
        }
        player.play()
    }

    // Fuori fascia oraria: ferma il loop e mostra l'immagine di cortesia
    // (con i suoi metadati di rotazione/fit) oppure nero se item è null.
    fun idle(item: PlaylistItem?) {
        stop()
        if (item == null) return // contenitore vuoto su sfondo nero = spento apparente

        val file = sync.localFile(item)
        if (!file.exists()) return

        running = false
        showToken++
        val token = showToken
        scope.launch {
            val bounds = withContext(Dispatchers.IO) {
                BitmapFactory.Options().apply { inJustDecodeBounds = true }
                    .also { BitmapFactory.decodeFile(file.path, it) }
            }
            if (token != showToken) return@launch
            val (tw, th) = displayedSize(bounds.outWidth, bounds.outHeight, item.rotation, item.fit)
            val bitmap = withContext(Dispatchers.IO) {
                decodeScaled(file.path, tw, th, allowLowColor = item.mime == "image/jpeg")
            }
            if (token != showToken || bitmap == null) {
                bitmap?.recycle()
                return@launch
            }
            val view = ImageView(context).apply {
                scaleType = ImageView.ScaleType.FIT_XY
                setImageBitmap(bitmap)
            }
            container.addView(view, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))
            applyTransform(view, bitmap.width, bitmap.height, item.rotation, item.fit)
        }
    }

    private fun next() {
        if (!running || items.isEmpty()) return
        val slots = syncSlots
        val pos = syncPosition()
        if (pos != null && slots != null) {
            // Aggancio assoluto: non "l'item successivo", ma l'item in cui
            // il ciclo si trova ADESSO. Un ritardo accumulato qui muore qui,
            // invece di sommarsi slot dopo slot fino al riallineamento.
            var idx = pos.first
            var off = pos.second
            // Il timer del cambio file si sveglia spesso qualche millisecondo
            // PRIMA del confine per cui era armato: "ancora nello slot
            // corrente, a un soffio dalla fine" significa quel confine.
            // Senza questo scatto l'item verrebbe rimostrato per una frazione
            // di secondo — transizione compresa — slittando a ogni giro.
            if (idx == index && idx in slots.indices && off >= slots[idx] - BOUNDARY_SNAP_MS) {
                idx = (idx + 1) % items.size
                off = 0L
            }
            index = idx.coerceAtMost(items.size - 1)
            show(items[index], startOffsetMs = off)
            return
        }
        index = (index + 1) % items.size
        show(items[index])
    }

    private fun scheduleSkip(token: Int) {
        handler.postDelayed({ if (token == showToken) next() }, 1_000)
    }

    private fun show(item: PlaylistItem, startOffsetMs: Long = 0) {
        showToken++
        val token = showToken
        itemShownAt = SystemClock.elapsedRealtime() - startOffsetMs
        handler.removeCallbacksAndMessages(null)

        // Bonifica al volo: via le view degli show superati (resta solo la
        // corrente) e stop ai player non associati alla view visibile — un
        // video abortito non deve continuare a suonare in sottofondo
        for (i in container.childCount - 1 downTo 0) {
            val child = container.getChildAt(i)
            if (child !== currentView) {
                container.removeView(child)
                releaseImage(child)
            }
        }
        // Ogni ExoPlayer pooled tiene viva una TextureView con la propria
        // catena di buffer GPU (~25 MB l'una, osservato: su un Fire Stick di
        // prima generazione, 6 buffer ION da 1920×1080×4 byte spiegavano da
        // soli la metà della memoria del processo — vedi CHANGELOG). Il
        // secondo player serve DAVVERO vivo insieme al primo solo per il
        // crossfade di un video che ne sostituisce un altro: in ogni altro
        // caso (foto→foto, foto→video, video→foto) tenerlo pronto "per
        // scorta" costa memoria senza motivo. Si rilascia quindi qui, non
        // solo lo si ferma — pooledPlayer() lo ricrea al bisogno, con un
        // costo di qualche decina di ms al prossimo video, non prima.
        val nextIsVideo = item.type == "video"
        val outgoingIsVideo = currentItem?.type == "video"
        val needsBothPlayersAlive = nextIsVideo && outgoingIsVideo
        players.forEachIndexed { i, p ->
            if (i != currentSlot) {
                if (needsBothPlayersAlive) {
                    p?.stop()
                } else if (p != null) {
                    playerListeners[i]?.let { p.removeListener(it) }
                    playerListeners[i] = null
                    p.release()
                    players[i] = null
                }
            }
        }

        val file = sync.localFile(item)
        if (!file.exists()) {
            scheduleSkip(token)
            return
        }

        if (item.type == "video") showVideo(item, file, token, startOffsetMs)
        else showImage(item, file, token, startOffsetMs)
    }

    // ---------- immagini ----------

    // Quanti pixel occuperà DAVVERO il contenuto una volta a schermo.
    // Stessa identica matematica di applyTransform(): è lì che la view
    // viene dimensionata, quindi è quello il numero di pixel oltre il
    // quale decodificare è solo memoria sprecata.
    private fun displayedSize(contentW: Int, contentH: Int, rotation: Int, fit: String): Pair<Int, Int> {
        // Cold start: il contenitore può non essere ancora misurato. Un
        // pannello full HD è l'ipotesi giusta su questi dispositivi, e
        // comunque la view verrà poi dimensionata da applyTransform.
        val w = if (container.width > 0) container.width else 1920
        val h = if (container.height > 0) container.height else 1080
        if (contentW <= 0 || contentH <= 0) return w to h

        val swap = rotation == 90 || rotation == 270
        val boxW = if (swap) contentH else contentW
        val boxH = if (swap) contentW else contentH
        val scale = if (fit == "cover") {
            max(w.toFloat() / boxW, h.toFloat() / boxH)
        } else {
            min(w.toFloat() / boxW, h.toFloat() / boxH)
        }
        // Mai ingrandire in fase di decodifica: scalare in su un bitmap
        // costa memoria senza aggiungere un solo dettaglio
        val capped = min(1f, scale)
        return max(1, (contentW * capped).roundToInt()) to max(1, (contentH * capped).roundToInt())
    }

    // Decodifica alla misura in cui il contenuto andrà a schermo, non alla
    // potenza di due più vicina.
    //
    // inSampleSize dimezza soltanto: si ferma quando la metà successiva
    // scenderebbe sotto il necessario, e può quindi lasciare in memoria
    // fino al QUADRUPLO dei pixel che servono (il doppio per lato). Su una
    // foto grande sono decine di MB tenuti per nulla, e su un Fire Stick
    // di prima generazione sono la differenza fra riprodurre ed essere
    // uccisi dal sistema. Dopo il dimezzamento si finisce quindi il
    // lavoro con una scalatura esatta, liberando SUBITO l'intermedio.
    //
    // targetW/targetH sono i pixel finali a schermo; extra allarga il
    // bersaglio per le transizioni che ingrandiscono il contenuto durante
    // l'esposizione (Ken Burns), che altrimenti mostrerebbero un bitmap
    // scalato in su.
    private fun decodeScaled(
        path: String,
        targetW: Int,
        targetH: Int,
        allowLowColor: Boolean,
        extra: Float = 1f,
    ): android.graphics.Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(path, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        val wantW = max(1, (targetW * extra).roundToInt())
        val wantH = max(1, (targetH * extra).roundToInt())

        var sample = 1
        while (bounds.outWidth / (sample * 2) >= wantW && bounds.outHeight / (sample * 2) >= wantH) {
            sample *= 2
        }

        val options = BitmapFactory.Options().apply {
            inSampleSize = sample
            // 565 dimezza la memoria di ogni foto (due byte per pixel
            // invece di quattro). Non ha canale alpha, quindi vale solo
            // dove la trasparenza non può esserci — un JPEG — e solo se
            // l'amministratore lo ha chiesto dal CMS: su una vetrina
            // fotografica le bande che produce nelle zone piatte si
            // vedono, e la memoria risparmiata non le ripaga.
            if (allowLowColor && lowColorAllowed && lowMemoryDevice) {
                inPreferredConfig = android.graphics.Bitmap.Config.RGB_565
            }
        }
        val decoded = BitmapFactory.decodeFile(path, options) ?: return null

        // Ancora più grande del necessario: si scala esatto e si butta
        // subito l'intermedio, senza aspettare il garbage collector — che
        // sotto pressione di memoria arriva tardi, ed è esattamente
        // quando conta.
        //
        // La soglia è volutamente stretta. Una copia ridotta a 1920 pixel
        // di lato, mostrata su un pannello da 1080, arriva qui a 1,185
        // volte il necessario: con una soglia larga non si scalerebbe mai
        // proprio nel caso più comune di questo impianto, e si terrebbe
        // un terzo di memoria in più per ogni foto — il doppio durante
        // una dissolvenza incrociata, che di foto ne tiene due.
        if (decoded.width > wantW * 1.05 || decoded.height > wantH * 1.05) {
            val scaled = try {
                android.graphics.Bitmap.createScaledBitmap(decoded, wantW, wantH, true)
            } catch (e: Throwable) {
                FileLogger.w("Scalatura esatta non riuscita, tengo il bitmap dimezzato", e)
                null
            }
            if (scaled != null && scaled !== decoded) {
                decoded.recycle()
                return scaled
            }
        }
        return decoded
    }

    // Dispositivo con poca memoria: qui si decide se accettare il
    // compromesso sulla profondità di colore. isLowRamDevice esiste solo
    // da API 19 e non tutti i costruttori lo dichiarano, quindi vale anche
    // la memoria che il sistema concede all'app.
    private val lowMemoryDevice: Boolean by lazy {
        val am = context.getSystemService(Context.ACTIVITY_SERVICE) as? android.app.ActivityManager
        if (am == null) {
            false
        } else {
            val lowRam = android.os.Build.VERSION.SDK_INT >= 19 &&
                runCatching { am.isLowRamDevice }.getOrDefault(false)
            // 64 MB di heap concesso è il valore tipico dei box economici;
            // i dispositivi moderni stanno su 192 MB e oltre
            lowRam || am.memoryClass <= 96
        }
    }

    // Toglie di mezzo il bitmap di una view che esce di scena. I bitmap
    // vivono nello heap gestito, quindi prima o poi il garbage collector
    // li raccoglierebbe da solo: "prima o poi" però non basta a un
    // dispositivo che sta scegliendo in quel momento quale processo
    // uccidere. Si libera subito, dopo che la view è già stata rimossa.
    private fun releaseImage(view: View?) {
        val imageView = view as? ImageView ?: return
        val bitmap = (imageView.drawable as? android.graphics.drawable.BitmapDrawable)?.bitmap
        imageView.setImageDrawable(null)
        if (bitmap != null && !bitmap.isRecycled) {
            runCatching { bitmap.recycle() }
        }
    }

    private fun showImage(item: PlaylistItem, file: File, token: Int, startOffsetMs: Long = 0) {
        scope.launch {
            // Le dimensioni naturali servono per sapere quanti pixel
            // finiranno davvero a schermo: si leggono senza decodificare
            val bounds = withContext(Dispatchers.IO) {
                BitmapFactory.Options().apply { inJustDecodeBounds = true }
                    .also { BitmapFactory.decodeFile(file.path, it) }
            }
            if (token != showToken) return@launch
            val (targetW, targetH) = displayedSize(bounds.outWidth, bounds.outHeight, item.rotation, item.fit)
            // Ken Burns ingrandisce il contenuto fino a 1,08 durante
            // l'esposizione: si decodifica con un filo di margine
            val extra = if (effectiveIn(item) == "kenburns") 1.1f else 1f
            val bitmap = withContext(Dispatchers.IO) {
                decodeScaled(file.path, targetW, targetH, allowLowColor = item.mime == "image/jpeg", extra = extra)
            }
            if (token != showToken) {
                // Il contenuto è cambiato mentre si decodificava: questo
                // bitmap non lo vedrà nessuno, e non deve restare in giro
                bitmap?.recycle()
                return@launch
            }
            if (bitmap == null) {
                scheduleSkip(token)
                return@launch
            }

            val view = ImageView(context).apply {
                scaleType = ImageView.ScaleType.FIT_XY
                setImageBitmap(bitmap)
                alpha = 0f
            }
            container.addView(view, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))
            presentView(view, item, videoSlot = -1, token = token,
                contentW = bitmap.width, contentH = bitmap.height, extraRotation = 0)

            val displayMs = item.duration.coerceAtLeast(1) * 1_000L
            // Partenza a metà slot (diretta allineata): il tempo residuo è
            // quello che manca alla fine dello slot
            val slotMs = item.transitionMs + displayMs
            val residuo = (slotMs - startOffsetMs).coerceAtLeast(800L)
            // Uno scarto di poche centinaia di ms è il normale ritardo del
            // cambio file, non una partenza a metà slot: sotto la soglia le
            // animazioni si mostrano, altrimenti in modalità allineata non
            // se ne vedrebbe mai una
            val aggancioPiccolo = startOffsetMs < 1_200L
            // Ken Burns parte DOPO l'entrata: condividono il ViewPropertyAnimator
            // della view, quindi avviarlo subito annullerebbe l'animazione di entrata
            if (effectiveIn(item) == "kenburns" && aggancioPiccolo) {
                handler.postDelayed(
                    { if (token == showToken) startKenBurns(view, displayMs) },
                    item.transitionMs,
                )
            }
            handler.postDelayed({ if (token == showToken) next() }, residuo)
        }
    }

    // Lenta deriva zoom+pan durante l'esposizione (Ken Burns)
    private fun startKenBurns(view: View, durationMs: Long) {
        val drift = 0.04f * min(container.width, container.height)
        view.animate()
            .scaleX(1.08f).scaleY(1.08f)
            .translationXBy(if (Random.nextBoolean()) drift else -drift)
            .translationYBy(if (Random.nextBoolean()) drift else -drift)
            .setStartDelay(0)
            .setDuration(durationMs)
            .start()
    }

    // ---------- video ----------

    // Player dei file in cache. Vedi LOCAL_BUFFER_*: la taratura di serie
    // è pensata per un flusso di rete e qui costerebbe decine di MB per
    // leggere byte che stanno già sul disco del dispositivo.
    @androidx.annotation.OptIn(androidx.media3.common.util.UnstableApi::class)
    private fun pooledPlayer(slot: Int): ExoPlayer =
        players[slot] ?: ExoPlayer.Builder(context)
            .setLoadControl(
                DefaultLoadControl.Builder()
                    .setBufferDurationsMs(
                        LOCAL_BUFFER_MIN_MS,
                        LOCAL_BUFFER_MAX_MS,
                        LOCAL_BUFFER_FOR_PLAYBACK_MS,
                        LOCAL_BUFFER_AFTER_REBUFFER_MS,
                    )
                    .setTargetBufferBytes(LOCAL_TARGET_BUFFER_BYTES)
                    .setPrioritizeTimeOverSizeThresholds(false)
                    .build()
            )
            .build()
            .also { players[slot] = it }

    // Player dedicato ai flussi esterni. I file locali non ne hanno bisogno
    // (si leggono da disco, i valori predefiniti bastano); un flusso di rete
    // sì: con la taratura di serie, pensata per il mobile, la riproduzione
    // parte dopo appena 2,5 s di buffer e un calo di banda basta a farla
    // scattare. Su un pannello in vetrina non è accettabile.
    // Le API di taratura del buffer sono marcate instabili da Media3: la
    // versione della libreria è bloccata nel build.gradle, quindi il
    // contratto non cambia sotto i piedi.
    @androidx.annotation.OptIn(androidx.media3.common.util.UnstableApi::class)
    private fun streamingPlayer(): ExoPlayer = streamPlayer ?: ExoPlayer.Builder(context)
        .setLoadControl(
            DefaultLoadControl.Builder()
                .setBufferDurationsMs(
                    if (lowMemoryDevice) BUFFER_MIN_MS_LOW else BUFFER_MIN_MS,
                    if (lowMemoryDevice) BUFFER_MAX_MS_LOW else BUFFER_MAX_MS,
                    BUFFER_FOR_PLAYBACK_MS,
                    BUFFER_AFTER_REBUFFER_MS,
                )
                // Il tetto è in byte, non in secondi: così un flusso a
                // bitrate alto riempie comunque il buffer senza far
                // esplodere la memoria di un box economico
                .setTargetBufferBytes(
                    if (lowMemoryDevice) TARGET_BUFFER_BYTES_LOW else TARGET_BUFFER_BYTES
                )
                .setPrioritizeTimeOverSizeThresholds(false)
                .build()
        )
        .setMediaSourceFactory(
            DefaultMediaSourceFactory(
                DefaultHttpDataSource.Factory()
                    .setConnectTimeoutMs(15_000)
                    .setReadTimeoutMs(15_000)
                    .setAllowCrossProtocolRedirects(true)
                    .setUserAgent("FrameParingStore")
            )
        )
        .build()
        .also { streamPlayer = it }

    private fun showVideo(item: PlaylistItem, file: File, token: Int, startOffsetMs: Long = 0) {
        // Mai lo slot della view ancora visibile: il crossfade richiede
        // che il video uscente continui a riprodurre durante la transizione
        val slot = if (currentSlot == 0) 1 else 0
        val player = pooledPlayer(slot)

        playerListeners[slot]?.let { player.removeListener(it) }

        // La TextureView va attaccata SUBITO (invisibile): senza superficie
        // il decoder non parte e onVideoSizeChanged non arriverebbe mai.
        val texture = TextureView(context).apply { alpha = 0f }
        container.addView(texture, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))
        var presented = false

        val listener = object : Player.Listener {
            override fun onVideoSizeChanged(videoSize: VideoSize) {
                if (token != showToken || videoSize.width == 0 || presented) return
                presented = true
                // La rotazione nativa del file (tipica dei video da smartphone
                // su TextureView) si somma a quella impostata nel CMS
                presentView(texture, item, videoSlot = slot, token = token,
                    contentW = videoSize.width, contentH = videoSize.height,
                    extraRotation = videoSize.unappliedRotationDegrees)
            }

            override fun onPlaybackStateChanged(state: Int) {
                if (token != showToken || state != Player.STATE_ENDED) return
                // Video più corto del suo slot (metadati che sovrastimano):
                // in modalità allineata si tiene l'ultimo fotogramma fino
                // alla fine dello slot, che è ciò che vedono gli altri
                // schermi ancora in riproduzione. Passare subito oltre
                // significherebbe rimostrare lo stesso video con un seek
                // oltre la fine, in un ciclo di scatti.
                val slots = syncSlots
                if (slots != null && index in slots.indices) {
                    val residuo = slots[index] - (SystemClock.elapsedRealtime() - itemShownAt)
                    if (residuo > 250) {
                        handler.postDelayed({ if (token == showToken) next() }, residuo)
                        return
                    }
                }
                next()
            }

            override fun onPlayerError(error: PlaybackException) {
                if (token == showToken) scheduleSkip(token)
            }
        }
        playerListeners[slot] = listener
        player.addListener(listener)

        player.setVideoTextureView(texture)
        player.volume = if (item.muted) 0f else 1f
        player.repeatMode = Player.REPEAT_MODE_OFF
        player.setMediaItem(MediaItem.fromUri(Uri.fromFile(file)))
        player.prepare()
        // Diretta allineata: ci si posiziona dove sono già gli altri
        // schermi. Sotto il secondo non si tocca nulla: un micro-seek a
        // ogni avvio costerebbe più della precisione che compra.
        if (startOffsetMs > 1_000) {
            player.seekTo(startOffsetMs)
        }
        player.play()
    }

    // ---------- presentazione + transizione ----------

    private fun effectiveIn(item: PlaylistItem): String = resolveRandom(item.transitionIn)

    private fun resolveRandom(name: String): String =
        if (name == "random") randomPool.random() else name

    private fun presentView(
        view: View,
        item: PlaylistItem,
        videoSlot: Int,
        token: Int,
        contentW: Int,
        contentH: Int,
        extraRotation: Int,
    ) {
        if (token != showToken) return

        val outgoingView = currentView
        val outgoingItem = currentItem
        val outgoingSlot = currentSlot
        currentView = view
        currentItem = item
        currentSlot = videoSlot

        // La view è già nel contenitore (attaccata da showImage/showVideo,
        // invisibile): la si dimensiona, la si porta in cima e la si rivela
        container.bringChildToFront(view)
        applyTransform(view, contentW, contentH, (item.rotation + extraRotation) % 360, item.fit)

        val transIn = effectiveIn(item)
        val transOut = resolveRandom(outgoingItem?.transitionOut ?: "cut")
        val duration = item.transitionMs

        // La cleanup può scattare dopo che un nuovo show ha già riassegnato
        // lo slot: si ferma il player solo se quello slot non è più in uso
        val cleanup = {
            outgoingView?.let {
                container.removeView(it)
                // La foto appena uscita di scena non serve più a nessuno:
                // la sua memoria si libera qui, non quando passerà il
                // garbage collector (vedi releaseImage)
                releaseImage(it)
            }
            if (outgoingSlot >= 0 && outgoingSlot != currentSlot) players[outgoingSlot]?.stop()
        }

        if (duration <= 0L || (transIn == "cut" && transOut == "cut")) {
            view.alpha = 1f
            cleanup()
            return
        }

        container.post {
            if (token != showToken) return@post
            view.alpha = 1f // baseline; animateIn imposta lo stato iniziale se serve
            animateIn(view, transIn, duration)
            if (outgoingView != null) animateOut(outgoingView, transOut, duration, cleanup)
            else handler.postDelayed({ cleanup() }, duration)
        }
    }

    // Entrata: la view parte dallo stato iniziale della transizione e
    // arriva allo stato "a riposo" (alpha 1, traslazioni 0, scala 1)
    private fun animateIn(view: View, transition: String, duration: Long) {
        val w = container.width.toFloat()
        val h = container.height.toFloat()
        var delay = 0L

        when (transition) {
            "cut" -> return
            "fade" -> {
                // fade "dal nero": entra solo nella seconda metà
                view.alpha = 0f
                delay = duration / 2
            }
            "crossfade", "kenburns" -> view.alpha = 0f
            "slide_left" -> view.translationX = w
            "slide_right" -> view.translationX = -w
            "slide_up" -> view.translationY = h
            "slide_down" -> view.translationY = -h
            "zoom_in" -> { view.alpha = 0f; view.scaleX = 0.6f; view.scaleY = 0.6f }
            "zoom_out" -> { view.alpha = 0f; view.scaleX = 1.4f; view.scaleY = 1.4f }
            else -> return
        }

        view.animate()
            .alpha(1f).translationX(0f).translationY(0f).scaleX(1f).scaleY(1f)
            .setStartDelay(delay)
            .setDuration(if (transition == "fade") duration / 2 else duration)
            .start()
    }

    // Uscita: dalla posizione a riposo allo stato finale, poi cleanup
    private fun animateOut(view: View, transition: String, duration: Long, onEnd: () -> Unit) {
        val w = container.width.toFloat()
        val h = container.height.toFloat()
        val anim = view.animate()

        when (transition) {
            "cut" -> { onEnd(); return }
            "fade" -> anim.alpha(0f).setDuration(duration / 2)
            "crossfade", "kenburns" -> anim.alpha(0f).setDuration(duration)
            "slide_left" -> anim.translationX(-w).setDuration(duration)
            "slide_right" -> anim.translationX(w).setDuration(duration)
            "slide_up" -> anim.translationY(-h).setDuration(duration)
            "slide_down" -> anim.translationY(h).setDuration(duration)
            "zoom_in" -> anim.alpha(0f).scaleX(1.4f).scaleY(1.4f).setDuration(duration)
            "zoom_out" -> anim.alpha(0f).scaleX(0.6f).scaleY(0.6f).setDuration(duration)
            else -> { onEnd(); return }
        }

        anim.withEndAction(onEnd).start()
    }

    // ---------- geometria comune ----------

    private fun applyTransform(
        view: View,
        contentW: Int,
        contentH: Int,
        rotation: Int,
        fit: String,
        attempt: Int = 0,
    ) {
        container.post {
            val w = container.width
            val h = container.height
            // Cold start: il contenitore può non essere ancora misurato
            if ((w == 0 || h == 0) && attempt < 20) {
                handler.postDelayed(
                    { applyTransform(view, contentW, contentH, rotation, fit, attempt + 1) },
                    50,
                )
                return@post
            }
            if (w == 0 || h == 0 || contentW == 0 || contentH == 0) return@post

            // Ingombro a schermo dopo la rotazione
            val swap = rotation == 90 || rotation == 270
            val boxW = if (swap) contentH else contentW
            val boxH = if (swap) contentW else contentH

            val scale = if (fit == "cover") {
                max(w.toFloat() / boxW, h.toFloat() / boxH)
            } else {
                min(w.toFloat() / boxW, h.toFloat() / boxH)
            }

            view.layoutParams = FrameLayout.LayoutParams(
                (contentW * scale).roundToInt(),
                (contentH * scale).roundToInt(),
                Gravity.CENTER,
            )
            view.rotation = rotation.toFloat()
        }
    }
}
