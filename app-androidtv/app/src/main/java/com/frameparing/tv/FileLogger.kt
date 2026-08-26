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

import android.content.Context
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

// Log persistente su file interno all'app, a complemento di logcat: logcat
// si azzera a ogni riavvio e su un device con poca RAM (osservato: un Fire
// Stick di prima generazione, 1 GB totale) può andare in overflow prima che
// qualcuno si colleghi con adb. Questo file sopravvive ai riavvii e resta
// recuperabile anche molto dopo l'evento (`adb pull`), invece di dover
// essere collegati al momento esatto in cui succede qualcosa.
//
// Sostituisce android.util.Log come punto di chiamata in tutto il resto del
// codice (stessa firma, stesso tag "FPS" implicito): logga sempre anche su
// logcat, quindi non toglie nulla a chi è collegato dal vivo.
object FileLogger {
    private const val TAG = "FPS"

    // Due file a rotazione invece di uno che cresce senza limite: un device
    // di vetrina può restare acceso per settimane di fila
    private const val MAX_BYTES = 256 * 1024L

    private var logFile: File? = null
    private var oldFile: File? = null
    private val dateFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss.SSS", Locale.ITALY)

    fun init(context: Context) {
        val dir = File(context.filesDir, "logs").apply { mkdirs() }
        logFile = File(dir, "fps.log")
        oldFile = File(dir, "fps.log.old")
    }

    @Synchronized
    fun i(message: String) {
        android.util.Log.i(TAG, message)
        write("I", message, null)
    }

    @Synchronized
    fun w(message: String, throwable: Throwable? = null) {
        android.util.Log.w(TAG, message, throwable)
        write("W", message, throwable)
    }

    // Chiamato dal gestore globale delle eccezioni non gestite (vedi
    // FPSApplication): deve essere sincrono e a prova di ulteriori errori,
    // il processo sta per morire da un momento all'altro.
    @Synchronized
    fun crash(thread: Thread, throwable: Throwable) {
        write("E", "FATAL su thread «${thread.name}»", throwable)
    }

    private fun write(level: String, message: String, throwable: Throwable?) {
        val file = logFile ?: return
        runCatching {
            val old = oldFile
            if (file.exists() && file.length() > MAX_BYTES && old != null) {
                old.delete()
                file.renameTo(old)
            }
            val line = buildString {
                append(dateFormat.format(Date()))
                append(' ').append(level).append('/').append(TAG).append(": ")
                append(message)
                if (throwable != null) {
                    append('\n')
                    val sw = StringWriter()
                    throwable.printStackTrace(PrintWriter(sw))
                    append(sw)
                }
                append('\n')
            }
            file.appendText(line)
        }
    }
}
