package com.frameparing.tv

import android.content.Context
import android.content.SharedPreferences

// Stato persistente del dispositivo: server, token e playlist in cache.
class Prefs(context: Context) {

    private val sp: SharedPreferences =
        context.getSharedPreferences("frame_paring", Context.MODE_PRIVATE)

    var baseUrl: String?
        get() = sp.getString("base_url", null)
        set(value) = sp.edit().putString("base_url", value).apply()

    var authToken: String?
        get() = sp.getString("auth_token", null)
        set(value) = sp.edit().putString("auth_token", value).apply()

    var pollInterval: Int
        get() = sp.getInt("poll_interval", 60)
        set(value) = sp.edit().putInt("poll_interval", value).apply()

    // Lingua dell'interfaccia, decisa nel CMS. null = si segue quella del
    // dispositivo (vale solo prima della prima connessione).
    var language: String?
        get() = sp.getString("language", null)
        set(value) = sp.edit().putString("language", value).apply()

    var playlistJson: String?
        get() = sp.getString("playlist_json", null)
        set(value) = sp.edit().putString("playlist_json", value).apply()

    fun clearPairing() {
        sp.edit()
            .remove("auth_token")
            .remove("playlist_json")
            .apply()
    }
}
