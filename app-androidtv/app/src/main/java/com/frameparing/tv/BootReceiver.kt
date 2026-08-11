package com.frameparing.tv

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

// Avvio automatico al boot. Funziona direttamente fino ad Android 9
// (Fire OS 5/6/7). Su Android 10+ (alcune Android TV, Fire OS 8) il
// sistema blocca le activity avviate dal background: va concesso una
// tantum "Visualizza sopra altre app" all'app (vedi README).
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        android.util.Log.i("FPS", "BootReceiver: ricevuto ${intent.action}")
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        try {
            context.startActivity(
                Intent(context, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            android.util.Log.i("FPS", "BootReceiver: startActivity inviata")
        } catch (e: Exception) {
            // Se il sistema lo impedisce, l'app resta avviabile dal launcher
            android.util.Log.w("FPS", "BootReceiver: startActivity fallita", e)
        }
    }
}
