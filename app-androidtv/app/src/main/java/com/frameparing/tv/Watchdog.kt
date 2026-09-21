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

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.SystemClock

// Rete di sicurezza contro i kill del sistema (lowmemorykiller e simili):
// BootReceiver riporta su l'app solo al riavvio del dispositivo, ma un Fire
// Stick può restare acceso per settimane — se nel frattempo il sistema
// uccide il processo per pressione di memoria (osservato: 341 processi
// morti in un colpo solo, incluso perfino il launcher Fire TV), oggi nulla
// lo fa ripartire finché non interviene qualcuno a mano.
//
// AlarmManager risolve il problema alla radice: la sveglia è registrata
// presso il SISTEMA, non dentro il nostro processo, quindi scatta anche se
// il processo è già morto — Android lo fa ripartire solo per consegnargli
// la sveglia (vedi WatchdogReceiver).
object Watchdog {
    private const val REQUEST_CODE = 1001
    private const val RESTART_REQUEST_CODE = 1003

    // Quanto si aspetta, dopo un crash, prima di riaprire la vetrina.
    // Abbastanza perché il processo sia morto davvero e il sistema abbia
    // finito di ripulire, abbastanza poco da non lasciare uno schermo
    // spento in negozio.
    private const val RESTART_DELAY_MS = 5_000L

    // Prenota la riapertura dell'app dopo un crash. Chiamata dal gestore
    // globale delle eccezioni non gestite (vedi FPSApplication) nei
    // pochi istanti fra l'errore e la fine del processo: la sveglia vive
    // presso il sistema, quindi resta valida anche dopo.
    fun scheduleRestart(context: Context) {
        val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        val intent = Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(MainActivity.EXTRA_RESTART_REASON, "crash")
        val pendingIntent = PendingIntent.getActivity(
            context,
            RESTART_REQUEST_CODE,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        alarmManager.set(
            AlarmManager.ELAPSED_REALTIME_WAKEUP,
            SystemClock.elapsedRealtime() + RESTART_DELAY_MS,
            pendingIntent,
        )
    }

    // Richiamata da MainActivity.onCreate(): essendo l'activity singleTask,
    // onCreate() gira una sola volta per ogni avvio vero del processo (boot,
    // ripristino del watchdog, avvio manuale) — mai per i richiami
    // successivi a un'istanza già viva. Registrare di nuovo lo stesso
    // PendingIntent (stesso request code, FLAG_UPDATE_CURRENT) sostituisce
    // la sveglia precedente invece di accumularne altre.
    fun arm(context: Context) {
        val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        val pendingIntent = PendingIntent.getBroadcast(
            context,
            REQUEST_CODE,
            Intent(context, WatchdogReceiver::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        // 15 minuti: la granularità minima che AlarmManager concede a una
        // sveglia ripetuta inesatta dalla API 19 in poi, indipendentemente
        // dallo stato della batteria (qui irrilevante: il dispositivo resta
        // sempre alimentato). Comunque un netto miglioramento rispetto a
        // "nessun ripristino automatico".
        alarmManager.setInexactRepeating(
            AlarmManager.ELAPSED_REALTIME_WAKEUP,
            SystemClock.elapsedRealtime() + AlarmManager.INTERVAL_FIFTEEN_MINUTES,
            AlarmManager.INTERVAL_FIFTEEN_MINUTES,
            pendingIntent,
        )
    }
}
