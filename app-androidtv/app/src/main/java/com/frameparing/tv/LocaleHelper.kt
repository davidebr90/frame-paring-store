package com.frameparing.tv

import android.content.Context
import android.content.res.Configuration
import java.util.Locale

// Lingua dell'interfaccia sulla TV.
//
// Non si segue il locale del dispositivo: questi box arrivano quasi sempre
// configurati in inglese, anche quando lo schermo sta in un negozio italiano.
// La lingua la decide il CMS e viaggia con la playlist; il locale del
// dispositivo resta solo come ripiego prima della prima connessione.
object LocaleHelper {

    // Lingue per cui esiste un catalogo nell'APK. Aggiungerne una significa
    // creare values-<codice>/strings.xml e aggiungere il codice qui.
    private val SUPPORTED = setOf("it", "en")

    fun wrap(context: Context, language: String?): Context {
        val code = language?.takeIf { it in SUPPORTED } ?: return context
        val locale = Locale(code)
        Locale.setDefault(locale)

        val config = Configuration(context.resources.configuration)
        config.setLocale(locale)
        return context.createConfigurationContext(config)
    }

    fun isSupported(language: String?): Boolean = language != null && language in SUPPORTED
}
