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
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

// Riproduce la playlist in loop applicando i metadati del CMS:
//  - rotation (0/90/180/270): ruota la view; con 90/270 le dimensioni
//    si calcolano sul contenitore "girato"
//  - fit: contain (tutto visibile) o cover (riempi e ritaglia — il
//    contenitore ritaglia ciò che esce dai bordi)
// Immagini su ImageView (durata dal metadato), video su ExoPlayer con
// TextureView (necessaria per poter ruotare la superficie video).
class PlayerController(
    private val context: Context,
    private val container: FrameLayout,
    private val sync: SyncEngine,
    private val scope: CoroutineScope,
) {

    private val handler = Handler(Looper.getMainLooper())
    private var exo: ExoPlayer? = null
    private var items: List<PlaylistItem> = emptyList()
    private var index = 0
    private var running = false
    private var showToken = 0 // invalida i callback di uno show precedente

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
        exo?.stop()
        container.removeAllViews()
    }

    fun release() {
        stop()
        exo?.release()
        exo = null
    }

    private fun next() {
        if (!running || items.isEmpty()) return
        index = (index + 1) % items.size
        show(items[index])
    }

    private fun show(item: PlaylistItem) {
        showToken++
        val token = showToken
        handler.removeCallbacksAndMessages(null)
        exo?.stop()
        container.removeAllViews()

        val file = sync.localFile(item)
        if (!file.exists()) {
            // File sparito dalla cache: si salta, senza loop stretti
            handler.postDelayed({ next() }, 1_000)
            return
        }

        if (item.type == "video") showVideo(item, file.path, token)
        else showImage(item, file.path, token)
    }

    // ---------- immagini ----------

    private fun showImage(item: PlaylistItem, path: String, token: Int) {
        scope.launch {
            val bitmap = withContext(Dispatchers.IO) {
                val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                BitmapFactory.decodeFile(path, bounds)
                val maxSide = max(container.width, container.height).coerceAtLeast(1920)
                var sample = 1
                while (bounds.outWidth / (sample * 2) > maxSide || bounds.outHeight / (sample * 2) > maxSide) {
                    sample *= 2
                }
                BitmapFactory.decodeFile(path, BitmapFactory.Options().apply { inSampleSize = sample })
            }
            if (token != showToken) return@launch
            if (bitmap == null) {
                handler.postDelayed({ next() }, 1_000)
                return@launch
            }

            val view = ImageView(context).apply {
                scaleType = ImageView.ScaleType.FIT_XY
                setImageBitmap(bitmap)
            }
            container.addView(view)
            applyTransform(view, bitmap.width, bitmap.height, item.rotation, item.fit)
            handler.postDelayed({ next() }, item.duration.coerceAtLeast(1) * 1_000L)
        }
    }

    // ---------- video ----------

    private fun player(): ExoPlayer =
        exo ?: ExoPlayer.Builder(context).build().also { player ->
            player.addListener(object : Player.Listener {
                override fun onPlaybackStateChanged(state: Int) {
                    if (state == Player.STATE_ENDED) next()
                }

                override fun onPlayerError(error: PlaybackException) {
                    handler.postDelayed({ next() }, 1_000)
                }
            })
            exo = player
        }

    private fun showVideo(item: PlaylistItem, path: String, token: Int) {
        val texture = TextureView(context)
        container.addView(texture, FrameLayout.LayoutParams(1, 1, Gravity.CENTER))

        val player = player()
        player.clearVideoSurface()
        player.setVideoTextureView(texture)

        // Le dimensioni reali arrivano dal decoder; l'eventuale rotazione
        // nativa del file (unappliedRotationDegrees, tipica dei video da
        // smartphone su TextureView) si somma a quella del CMS.
        val sizeListener = object : Player.Listener {
            override fun onVideoSizeChanged(videoSize: VideoSize) {
                if (token != showToken || videoSize.width == 0) return
                val total = (item.rotation + videoSize.unappliedRotationDegrees) % 360
                applyTransform(texture, videoSize.width, videoSize.height, total, item.fit)
                player.removeListener(this)
            }
        }
        player.addListener(sizeListener)

        player.setMediaItem(MediaItem.fromUri(Uri.fromFile(java.io.File(path))))
        player.prepare()
        player.play()
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
