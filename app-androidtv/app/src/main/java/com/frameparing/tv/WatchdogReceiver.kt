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

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.PowerManager

// Consegnata da AlarmManager (vedi Watchdog.arm) ogni 15 minuti, anche se
// nel frattempo il processo dell'app era morto: il sistema lo fa ripartire
// solo per eseguire questo onReceive().
//
// STORIA DI QUESTO FILE: la prima versione (1.12.3) chiamava direttamente
// context.startActivity() dopo aver svegliato lo schermo. Funzionava nei
// test manuali, ma in produzione — dopo un vero kill di sistema, con
// l'app quindi assente dal CMS ("Offline") — non si è mai vista risalire
// da sola: serviva riaprirla a mano o riavviare la Fire Stick. Motivo:
// da Android 10 in poi (Fire OS 8+) il sistema blocca in silenzio, senza
// eccezione né log, l'avvio di un'Activity da un BroadcastReceiver in
// background — a meno che l'app non rientri in una delle eccezioni
// documentate (finestra già visibile, overlay già mostrato, ecc.), nessuna
// delle quali vale per un processo appena resuscitato apposta per
// ricevere questa sveglia. La notifica a schermo intero (setFullScreenIntent)
// è il canale che Android riserva esplicitamente a questo caso d'uso
// (sveglie, chiamate in arrivo): è garantita anche da un processo così.
//
// MainActivity è singleTask: riaprirla quando è già in primo piano non fa
// nulla di visibile — nessun restart, nessuna interruzione della
// riproduzione (arriva solo una onNewIntent ignorata). MainActivity.isForeground
// evita comunque di sparare una notifica quando non serve.
class WatchdogReceiver : BroadcastReceiver() {

    private companion object {
        const val REQUEST_CODE = 1002
        const val NOTIFICATION_ID = 1
        const val CHANNEL_ID = "watchdog"
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (MainActivity.isForeground) return // già in vista: nulla da ripristinare

        // Canale principale, ma solo dove esiste davvero: NotificationChannel
        // è un'API di Android 8.0 (26) — su un Fire Stick di prima
        // generazione (Fire OS 5, API 22, ancora in produzione su questo
        // progetto) il metodo non esiste proprio nel framework. Chiamarlo
        // senza guardia manda un NoSuchMethodError (un Error, non
        // un'Exception: catturato qui sotto solo perché ora si intercetta
        // Throwable) che uccideva l'intero processo appena resuscitato —
        // trasformando il watchdog stesso nella causa del mancato
        // ripristino, osservato in produzione il 24/08/2026.
        if (Build.VERSION.SDK_INT >= 26) {
            try {
                notifyFullScreen(context)
            } catch (e: Throwable) {
                android.util.Log.w("FPS", "WatchdogReceiver: notifica a schermo intero fallita", e)
            }
        }

        // Reti di sicurezza aggiuntive, innocue se il canale sopra ha già
        // fatto il suo lavoro: il risveglio esplicito dello schermo serve
        // per un motivo distinto da FLAG_KEEP_SCREEN_ON di MainActivity —
        // quel flag impedisce alla TV di addormentarsi mentre l'app è viva,
        // ma se il dispositivo è GIÀ addormentato il solo avvio dell'Activity
        // non lo risveglia da solo su tutti i firmware osservati
        try {
            wakeScreen(context)
        } catch (e: Throwable) {
            android.util.Log.w("FPS", "WatchdogReceiver: risveglio schermo fallito", e)
        }
        // Tentativo diretto: sui firmware sotto Android 10 (come questo
        // Fire Stick di prima generazione) è la via che funziona davvero,
        // non solo un fallback — vedi nota sopra
        try {
            context.startActivity(
                Intent(context, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        } catch (e: Throwable) {
            android.util.Log.w("FPS", "WatchdogReceiver: avvio diretto fallito", e)
        }
    }

    private fun notifyFullScreen(context: Context) {
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (nm.getNotificationChannel(CHANNEL_ID) == null) {
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, context.getString(R.string.app_name), NotificationManager.IMPORTANCE_HIGH)
            )
        }
        val activityIntent = Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        val pendingIntent = PendingIntent.getActivity(
            context, REQUEST_CODE, activityIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = Notification.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(context.getString(R.string.app_name))
            .setContentText(context.getString(R.string.watchdog_notification_text))
            .setFullScreenIntent(pendingIntent, true)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            // Stessa categoria delle sveglie/chiamate: è quella che il
            // sistema riconosce come legittima per un lancio a schermo intero
            .setCategory(Notification.CATEGORY_CALL)
            .build()
        nm.notify(NOTIFICATION_ID, notification)
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
