/*
 * Frame Paring Store — sistema di digital signage self-hosted
 * Copyright (C) 2026 Davide Pica
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA. Vedi la GNU Affero General Public License per i dettagli.
 * Dovresti aver ricevuto una copia della licenza insieme a questo programma;
 * in caso contrario: https://www.gnu.org/licenses/
 */

package com.frameparing.tv

import android.app.Activity
import android.content.Context
import android.graphics.PixelFormat
import android.os.Build
import android.provider.Settings
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.FrameLayout

// Finestra di sistema che sta sopra qualunque altra cosa: notifiche,
// avvisi Fire OS come «telecomando non rilevato», overlay di altre app.
// Il contenuto in riproduzione si sposta qui finché è in vetrina, così
// quegli avvisi smettono di interromperla — restano lì, semplicemente
// non si vedono più.
//
// Non è focalizzabile né tocca gli eventi: il telecomando continua a
// funzionare esattamente come prima (MENU, OK) perché quei tasti restano
// diretti alla finestra normale dell'Activity, mai a questa.
//
// Serve il permesso «Visualizza sopra altre app»: dalla 6.0 in poi va
// concesso dall'utente una volta sola (lo stesso della sezione «Avvio
// automatico» in impostazioni, non un secondo permesso da chiedere).
// Prima della 6.0 basta averlo dichiarato nel manifest. Se manca, questa
// classe si limita a non fare nulla: il contenuto resta nella finestra
// normale, esattamente come se non esistesse.
class KioskOverlay(private val activity: Activity) {

    private val wm = activity.getSystemService(Context.WINDOW_SERVICE) as WindowManager
    private var root: FrameLayout? = null
    private var creationAttempted = false

    fun available(): Boolean =
        Build.VERSION.SDK_INT < 23 || Settings.canDrawOverlays(activity)

    // Sposta la view dentro la finestra sopra-tutto (creandola alla prima
    // chiamata). Ritorna false se il permesso manca o la finestra non si
    // può creare: in quel caso la view non viene toccata.
    fun attach(view: View): Boolean {
        val overlay = root ?: createRoot() ?: return false
        (view.parent as? ViewGroup)?.removeView(view)
        overlay.addView(view)
        return true
    }

    // Riporta la view nella finestra normale, alla posizione originale:
    // serve quando bisogna mostrare le impostazioni, che vivono lì e
    // resterebbero coperte dalla finestra sopra-tutto.
    fun detach(view: View, backTo: ViewGroup, index: Int) {
        root?.removeView(view)
        backTo.addView(view, index)
    }

    private fun createRoot(): FrameLayout? {
        if (creationAttempted) return null
        creationAttempted = true
        if (!available()) return null

        val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
        } else {
            @Suppress("DEPRECATION")
            WindowManager.LayoutParams.TYPE_SYSTEM_ERROR
        }
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT,
            WindowManager.LayoutParams.MATCH_PARENT,
            type,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT,
        )
        val view = FrameLayout(activity)
        return try {
            wm.addView(view, params)
            root = view
            view
        } catch (e: Exception) {
            // Alcuni Fire OS negano comunque la finestra pur con il permesso
            // concesso: si continua nella finestra normale senza insistere
            FileLogger.w("Finestra sopra-tutto non disponibile, resto nella finestra normale", e)
            null
        }
    }

    fun release() {
        root?.let { runCatching { wm.removeView(it) } }
        root = null
    }
}
