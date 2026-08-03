package com.frameparing.tv

import android.content.Context
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.TextureView
import android.view.View
import android.widget.FrameLayout
import android.widget.ImageView
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.VideoSize
import androidx.media3.exoplayer.ExoPlayer
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

    private val handler = Handler(Looper.getMainLooper())
    private val players = arrayOfNulls<ExoPlayer>(2)
    private val playerListeners = arrayOfNulls<Player.Listener>(2)
    private var activeSlot = 0

    private var items: List<PlaylistItem> = emptyList()
    private var index = 0
    private var running = false
    private var showToken = 0          // invalida i callback degli show precedenti

    private var currentView: View? = null
    private var currentItem: PlaylistItem? = null
    private var currentSlot = -1       // slot ExoPlayer del video corrente (-1 = immagine)

    // Transizioni "vere" tra cui pesca 'random'
    private val randomPool = listOf(
        "fade", "crossfade", "slide_left", "slide_right",
        "slide_up", "slide_down", "zoom_in", "zoom_out",
    )

    fun play(playlist: Playlist) {
        stop()
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
        container.removeAllViews()
        currentView = null
        currentItem = null
        currentSlot = -1
    }

    fun release() {
        stop()
        players.forEachIndexed { i, p ->
            p?.release()
            players[i] = null
        }
    }

    private fun next() {
        if (!running || items.isEmpty()) return
        index = (index + 1) % items.size
        show(items[index])
    }

    private fun scheduleSkip(token: Int) {
        handler.postDelayed({ if (token == showToken) next() }, 1_000)
    }

    private fun show(item: PlaylistItem) {
        showToken++
        val token = showToken
        handler.removeCallbacksAndMessages(null)

        val file = sync.localFile(item)
        if (!file.exists()) {
            scheduleSkip(token)
            return
        }

        if (item.type == "video") showVideo(item, file, token)
        else showImage(item, file, token)
    }

    // ---------- immagini ----------

    private fun showImage(item: PlaylistItem, file: File, token: Int) {
        scope.launch {
            val bitmap = withContext(Dispatchers.IO) {
                val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                BitmapFactory.decodeFile(file.path, bounds)
                val maxSide = max(container.width, container.height).coerceAtLeast(1920)
                var sample = 1
                while (bounds.outWidth / (sample * 2) > maxSide || bounds.outHeight / (sample * 2) > maxSide) {
                    sample *= 2
                }
                BitmapFactory.decodeFile(file.path, BitmapFactory.Options().apply { inSampleSize = sample })
            }
            if (token != showToken) return@launch
            if (bitmap == null) {
                scheduleSkip(token)
                return@launch
            }

            val view = ImageView(context).apply {
                scaleType = ImageView.ScaleType.FIT_XY
                setImageBitmap(bitmap)
            }
            presentView(view, item, videoSlot = -1, token = token,
                contentW = bitmap.width, contentH = bitmap.height, extraRotation = 0)

            val displayMs = item.duration.coerceAtLeast(1) * 1_000L
            if (effectiveIn(item) == "kenburns") startKenBurns(view, item.transitionMs + displayMs)
            handler.postDelayed({ if (token == showToken) next() }, item.transitionMs + displayMs)
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

    private fun pooledPlayer(slot: Int): ExoPlayer =
        players[slot] ?: ExoPlayer.Builder(context).build().also { players[slot] = it }

    private fun showVideo(item: PlaylistItem, file: File, token: Int) {
        val slot = 1 - activeSlot
        activeSlot = slot
        val player = pooledPlayer(slot)

        playerListeners[slot]?.let { player.removeListener(it) }

        val texture = TextureView(context)
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
                if (token == showToken && state == Player.STATE_ENDED) next()
            }

            override fun onPlayerError(error: PlaybackException) {
                if (token == showToken) scheduleSkip(token)
            }
        }
        playerListeners[slot] = listener
        player.addListener(listener)

        player.setVideoTextureView(texture)
        player.volume = if (item.muted) 0f else 1f
        player.setMediaItem(MediaItem.fromUri(Uri.fromFile(file)))
        player.prepare()
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

        container.addView(view, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))
        applyTransform(view, contentW, contentH, (item.rotation + extraRotation) % 360, item.fit)

        val transIn = effectiveIn(item)
        val transOut = resolveRandom(outgoingItem?.transitionOut ?: "cut")
        val duration = item.transitionMs

        val cleanup = {
            outgoingView?.let { container.removeView(it) }
            if (outgoingSlot >= 0 && outgoingSlot != videoSlot) players[outgoingSlot]?.stop()
        }

        if (duration <= 0L || (transIn == "cut" && transOut == "cut")) {
            cleanup()
            return
        }

        container.post {
            if (token != showToken) return@post
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

    private fun applyTransform(view: View, contentW: Int, contentH: Int, rotation: Int, fit: String) {
        container.post {
            val w = container.width
            val h = container.height
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
