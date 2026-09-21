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
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder

// PERCHÉ UN SERVIZIO IN PRIMO PIANO
//
// Quando la memoria finisce, Android non sceglie la vittima a caso: la
// sceglie per priorità. Un'app che ha solo un'Activity visibile sta in
// cima alla lista dei sacrificabili appena qualcosa la copre — e su un
// Fire Stick basta un avviso di sistema, o la Home che torna davanti per
// un istante, perché ciò accada. È la spiegazione dei 341 processi uccisi
// in un colpo solo osservati in produzione: il sistema stava facendo
// pulizia, e noi eravamo fra i candidati più facili.
//
// Un servizio in primo piano dichiara al sistema che questo processo sta
// facendo un lavoro che l'utente si aspetta continui anche senza
// guardarlo. È esattamente il caso di una vetrina, ed è la ragione per
// cui le app di digital signage commerciali ne usano uno: non accelera
// nulla, sposta il processo più in basso nella lista di chi viene ucciso
// per primo.
//
// In più è la rete di sicurezza più rapida che esista: START_STICKY dice
// al sistema di ricreare il servizio da solo se lo termina, e appena
// viene ricreato il servizio riapre la vetrina. Nessuna attesa dei
// quindici minuti dell'AlarmManager.
class PlaybackService : Service() {

    companion object {
        private const val CHANNEL_ID = "playback"
        private const val NOTIFICATION_ID = 2

        // Avvia il servizio. Chiamata da MainActivity: se qualcosa va
        // storto l'app continua a funzionare esattamente come prima,
        // semplicemente senza questa protezione in più.
        fun start(context: Context) {
            try {
                context.startService(Intent(context, PlaybackService::class.java))
            } catch (e: Throwable) {
                FileLogger.w("Servizio di riproduzione non avviato", e)
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        promuovi()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        promuovi()

        // Ricreati dal sistema dopo essere stati terminati: l'Activity non
        // c'è più, e va riaperta. È il ripristino più veloce di cui
        // disponiamo, perché avviene appena il sistema torna a respirare
        // invece che al prossimo giro dell'AlarmManager.
        if (!MainActivity.isForeground) {
            FileLogger.i("Servizio riavviato dal sistema: riapro la vetrina")
            try {
                startActivity(
                    Intent(this, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        .putExtra(MainActivity.EXTRA_RESTART_REASON, "service")
                )
            } catch (e: Throwable) {
                FileLogger.w("Riapertura della vetrina dal servizio fallita", e)
            }
        }
        return START_STICKY
    }

    // La notifica è il prezzo obbligatorio di un servizio in primo piano.
    // Si tiene al minimo assoluto della scala di importanza: su un
    // televisore non deve comparire nulla sopra la vetrina, e a quel
    // livello il sistema non mostra avvisi né suoni.
    private fun promuovi() {
        try {
            if (Build.VERSION.SDK_INT >= 26) {
                val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
                if (nm.getNotificationChannel(CHANNEL_ID) == null) {
                    nm.createNotificationChannel(
                        NotificationChannel(
                            CHANNEL_ID,
                            getString(R.string.app_name),
                            NotificationManager.IMPORTANCE_MIN,
                        )
                    )
                }
            }
            startForeground(NOTIFICATION_ID, costruisciNotifica())
        } catch (e: Throwable) {
            // Fire OS 5 non ha i canali, Android 14 pretende i tipi
            // dichiarati, certi firmware hanno idee proprie: qualunque
            // cosa vada storta, la vetrina continua a funzionare senza
            // questa protezione invece di non partire affatto
            FileLogger.w("Promozione a servizio in primo piano non riuscita", e)
        }
    }

    @Suppress("DEPRECATION") // Notification.Builder senza canale: obbligatorio sotto API 26
    private fun costruisciNotifica(): Notification {
        val apri = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val builder = if (Build.VERSION.SDK_INT >= 26) {
            Notification.Builder(this, CHANNEL_ID)
        } else {
            Notification.Builder(this).setPriority(Notification.PRIORITY_MIN)
        }
        return builder
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(getString(R.string.service_notification_text))
            .setContentIntent(apri)
            .setOngoing(true)
            .build()
    }
}
