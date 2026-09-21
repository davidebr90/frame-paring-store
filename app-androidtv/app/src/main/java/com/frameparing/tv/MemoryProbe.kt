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

import android.app.ActivityManager
import android.content.Context
import android.os.Debug
import android.os.SystemClock

// Quanta memoria sta usando la vetrina, e quanta ne resta al dispositivo.
//
// Sono i numeri che per tutta la diagnosi di agosto si sono potuti
// leggere solo collegandosi con adb al televisore e lanciando `dumpsys
// meminfo` — cioè essendo sul posto, sulla stessa rete, nel momento
// giusto. Un dato ottenibile solo così, in pratica, non si ha mai.
// Adesso viaggiano appesi al battito di stato e si vedono dal CMS.
object MemoryProbe {

    // Il totale usato dal processo (PSS) è il numero che conta davvero,
    // perché comprende anche i buffer grafici che non stanno nello heap
    // Java — cioè esattamente ciò che spiegava il consumo osservato in
    // produzione. Costa però qualche millisecondo, mentre il resto è
    // praticamente gratis: si rilegge di rado, e negli altri battiti si
    // manda solo la memoria libera del dispositivo.
    private const val PSS_INTERVAL_MS = 5 * 60 * 1000L

    private var lastPssAt = 0L
    private var lastPssMb = -1

    // Ritorna la coda da attaccare alla URL di state.php, già pronta
    // ("?mem_avail=..."), oppure stringa vuota se non si è potuto
    // misurare nulla. Non lancia mai: è telemetria, e non deve poter
    // rompere il battito che la trasporta.
    fun queryString(context: Context): String = try {
        val am = context.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager
        if (am == null) {
            ""
        } else {
            val info = ActivityManager.MemoryInfo()
            am.getMemoryInfo(info)
            val parti = mutableListOf<String>()
            parti += "mem_avail=" + (info.availMem / 1048576L)
            // totalMem esiste da API 16; sotto si omette e basta
            if (android.os.Build.VERSION.SDK_INT >= 16) {
                parti += "mem_total=" + (info.totalMem / 1048576L)
            }
            parti += "mem_low=" + (if (info.lowMemory) "1" else "0")

            val pss = pssMb()
            if (pss > 0) {
                parti += "mem_used=$pss"
            }
            "?" + parti.joinToString("&")
        }
    } catch (e: Throwable) {
        FileLogger.w("Lettura della memoria non riuscita", e)
        ""
    }

    // PSS del processo, riletto al massimo ogni PSS_INTERVAL_MS e
    // riproposto nel frattempo: cambia lentamente, e rileggerlo a ogni
    // battito costerebbe più di quanto valga.
    private fun pssMb(): Int {
        val adesso = SystemClock.elapsedRealtime()
        if (lastPssMb > 0 && adesso - lastPssAt < PSS_INTERVAL_MS) {
            return lastPssMb
        }
        return try {
            val info = Debug.MemoryInfo()
            Debug.getMemoryInfo(info)
            lastPssMb = info.totalPss / 1024
            lastPssAt = adesso
            lastPssMb
        } catch (e: Throwable) {
            -1
        }
    }

    // Riga per il log locale, usata quando il sistema segnala pressione
    // di memoria: se anche il battito non arrivasse al server (rete giù
    // proprio in quel momento, che è il caso peggiore e quindi quello
    // che conta), il numero resta comunque scritto sul dispositivo.
    fun describe(context: Context): String = try {
        val am = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        val info = ActivityManager.MemoryInfo()
        am.getMemoryInfo(info)
        "liberi ${info.availMem / 1048576} MB su ${info.totalMem / 1048576}, " +
            "soglia di allarme ${info.threshold / 1048576} MB, " +
            "la vetrina ne usa ${pssMb()} MB"
    } catch (e: Throwable) {
        "non misurabile"
    }
}
