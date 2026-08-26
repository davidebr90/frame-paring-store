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

import android.app.Application

// Punto di ingresso del processo: qui, e solo qui, si arma la rete di
// sicurezza che deve esistere ancora PRIMA che qualunque Activity parta.
class FPSApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        FileLogger.init(this)

        // Cattura anche gli Error (non solo le Exception, vedi il crash
        // NoSuchMethodError della 1.12.4 sul watchdog: un catch (e: Exception)
        // da solo non li vede) di QUALUNQUE thread, non solo del main —
        // finora un crash così si scopriva solo collegandosi dal vivo con
        // adb nel momento esatto in cui succedeva. Il gestore precedente
        // resta comunque incaricato di terminare il processo come da
        // comportamento standard: qui si registra soltanto, non si impedisce
        // il crash (che sarebbe pericoloso: un'app rimasta in uno stato
        // corrotto è peggio di un riavvio pulito via watchdog).
        val previousHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            runCatching { FileLogger.crash(thread, throwable) }
            previousHandler?.uncaughtException(thread, throwable)
        }
    }
}
