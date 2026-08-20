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

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.PowerManager

// Consegnata da AlarmManager (vedi Watchdog.arm) ogni 15 minuti, anche se
// nel frattempo il processo dell'app era morto: il sistema lo fa ripartire
// solo per eseguire questo onReceive().
//
// MainActivity è singleTask: se è già in primo piano, riaprirla non fa
// nulla di visibile — nessun restart, nessuna interruzione della
// riproduzione (arriva solo una onNewIntent ignorata). È quindi sicuro
// chiamarla incondizionatamente, senza prima verificare se serva davvero.
//
// Il risveglio esplicito dello schermo serve per un motivo distinto dal
// FLAG_KEEP_SCREEN_ON di MainActivity: quel flag impedisce alla TV di
// addormentarsi mentre l'app è viva, ma se il dispositivo è GIÀ addormentato
// (osservato: mWakefulness=Asleep dopo il kill di sistema di ieri) il solo
// startActivity() non lo risveglia — serve un segnale esplicito.
class WatchdogReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        try {
            wakeScreen(context)
            context.startActivity(
                Intent(context, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        } catch (e: Exception) {
            android.util.Log.w("FPS", "WatchdogReceiver: ripristino fallito", e)
        }
    }

    @Suppress("DEPRECATION") // SCREEN_BRIGHT_WAKE_LOCK è deprecato a favore dei
    // window flag dell'Activity, ma qui serve esattamente l'effetto originale:
    // riaccendere un pannello già spento da un dispositivo sempre alimentato,
    // non un caso d'uso sensibile al consumo di batteria
    private fun wakeScreen(context: Context) {
        val powerManager = context.getSystemService(Context.POWER_SERVICE) as? PowerManager ?: return
        val wakeLock = powerManager.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK or
                PowerManager.ACQUIRE_CAUSES_WAKEUP or
                PowerManager.ON_AFTER_RELEASE,
            "FrameParing:WatchdogWake",
        )
        // Timeout di sicurezza: si rilascia comunque da solo anche se il
        // release() esplicito qui sotto non venisse mai raggiunto
        wakeLock.acquire(10_000)
        wakeLock.release()
    }
}
