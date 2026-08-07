package com.frameparing.tv

import android.content.Context
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Handler
import android.os.Looper
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import java.io.File
import java.util.Calendar

// Banner in sovraimpressione: un riquadro discreto sopra il contenuto, con
// logo e/o data e ora. Vive in un contenitore separato da quello del player:
// non partecipa alle transizioni e non viene mai rimosso dai cambi di media.
//
// L'orologio è quello del dispositivo: il banner resta corretto anche a rete
// assente e passa da solo al giorno successivo.
class BannerController(
    private val context: Context,
    private val host: FrameLayout,
) {

    private companion object {
        const val TICK_MS = 20_000L // ricalcolo: il minuto non deve mai sfasare
    }

    // I nomi dei mesi vengono dalle risorse, quindi seguono la lingua scelta
    // nel CMS invece del locale del dispositivo (su questi box quasi sempre
    // inglese, anche quando lo schermo sta in un negozio italiano)
    private val mesi: Array<String> by lazy {
        context.resources.getStringArray(R.array.months)
    }

    private val handler = Handler(Looper.getMainLooper())
    private var banner: Banner? = null
    private var box: LinearLayout? = null
    private var label: TextView? = null
    private var logo: ImageView? = null

    private val tick = object : Runnable {
        override fun run() {
            updateText()
            handler.postDelayed(this, TICK_MS)
        }
    }

    // Applica la configurazione ricevuta. null = banner spento: si smonta.
    fun apply(config: Banner?, logoFile: File?) {
        banner = config
        handler.removeCallbacks(tick)
        host.removeAllViews()
        box = null
        label = null
        logo = null

        if (config == null) {
            host.visibility = View.GONE
            return
        }

        val scale = when (config.size) {
            "small" -> 0.75f
            "large" -> 1.5f
            else -> 1f
        }
        val padH = dp(11f * scale)
        val padV = dp(7f * scale)

        val container = LinearLayout(context).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(padH, padV, padH, padV)
            background = GradientDrawable().apply {
                cornerRadius = dp(7f * scale).toFloat()
                // L'opacità agisce solo sul fondo: il testo resta pieno,
                // altrimenti su un contenuto chiaro diventa illeggibile
                setColor(Color.argb(
                    (config.opacity.coerceIn(0, 100) * 255 / 100),
                    12, 14, 18,
                ))
            }
        }

        if (logoFile != null && logoFile.exists()) {
            val bitmap = BitmapFactory.decodeFile(logoFile.path)
            if (bitmap != null) {
                val height = dp(22f * scale)
                val width = (height.toFloat() * bitmap.width / bitmap.height).toInt().coerceAtLeast(1)
                val image = ImageView(context).apply {
                    setImageBitmap(bitmap)
                    adjustViewBounds = true
                    scaleType = ImageView.ScaleType.FIT_CENTER
                }
                container.addView(image, LinearLayout.LayoutParams(width, height).apply {
                    marginEnd = dp(8f * scale)
                })
                logo = image
            }
        }

        val text = TextView(context).apply {
            setTextColor(Color.WHITE)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 15f * scale)
            includeFontPadding = false
            // Ombra: garantisce la leggibilità anche con fondo trasparente
            setShadowLayer(dp(2f).toFloat(), 0f, dp(1f).toFloat(), Color.argb(180, 0, 0, 0))
        }
        container.addView(text)
        label = text
        box = container

        host.addView(container, FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.WRAP_CONTENT,
            FrameLayout.LayoutParams.WRAP_CONTENT,
            gravityFor(config.position),
        ))
        // Margine di sicurezza: sui pannelli con overscan il bordo esatto
        // rischia di finire fuori dall'area visibile
        val margin = dp(26f)
        host.setPadding(margin, margin, margin, margin)
        host.visibility = View.VISIBLE

        updateText()
        handler.postDelayed(tick, TICK_MS)
    }

    fun hide() {
        handler.removeCallbacks(tick)
        host.visibility = View.GONE
    }

    private fun updateText() {
        val config = banner ?: return
        val view = label ?: return
        val now = Calendar.getInstance()

        val parti = buildList {
            if (config.showDate) {
                val giorno = now.get(Calendar.DAY_OF_MONTH)
                val mese = now.get(Calendar.MONTH)
                val anno = now.get(Calendar.YEAR)
                add(
                    if (config.dateFormat == "long") "$giorno ${mesi[mese]} $anno"
                    else "%02d/%02d/%d".format(giorno, mese + 1, anno)
                )
            }
            if (config.showTime) {
                add("%02d:%02d".format(now.get(Calendar.HOUR_OF_DAY), now.get(Calendar.MINUTE)))
            }
        }

        view.text = parti.joinToString("  ·  ")
        // Senza testo il riquadro resta solo attorno al logo
        view.visibility = if (parti.isEmpty()) View.GONE else View.VISIBLE
        // Nulla da mostrare (né testo né logo): il riquadro sparisce del tutto
        box?.visibility = if (parti.isEmpty() && logo == null) View.GONE else View.VISIBLE
    }

    private fun gravityFor(position: String): Int = when (position) {
        "top_left" -> Gravity.TOP or Gravity.START
        "top_center" -> Gravity.TOP or Gravity.CENTER_HORIZONTAL
        "top_right" -> Gravity.TOP or Gravity.END
        "middle_left" -> Gravity.CENTER_VERTICAL or Gravity.START
        "center" -> Gravity.CENTER
        "middle_right" -> Gravity.CENTER_VERTICAL or Gravity.END
        "bottom_left" -> Gravity.BOTTOM or Gravity.START
        "bottom_center" -> Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
        else -> Gravity.BOTTOM or Gravity.END
    }

    private fun dp(value: Float): Int =
        (value * context.resources.displayMetrics.density).toInt()
}
