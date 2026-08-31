package com.frameparing.tv

import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

// Client HTTP verso le API PHP. Solo HttpURLConnection (incluso in
// Android): niente OkHttp/Retrofit, il traffico è JSON minimale + download.

class ApiException(val code: Int, message: String) : Exception(message)

object ApiClient {

    private const val TIMEOUT_MS = 15_000

    val deviceInfo: String =
        "${Build.MANUFACTURER} ${Build.MODEL} / Android ${Build.VERSION.RELEASE}" +
            if (Build.MANUFACTURER.equals("Amazon", ignoreCase = true)) " (Fire OS)" else ""

    data class PairStart(
        val pairingCode: String,
        val pollToken: String,
        val qrUrl: String,
        val expiresIn: Int,
        // Consegnato subito, inerte finché l'admin non conferma: nel
        // database del server vive solo la sua impronta, quindi non
        // potrebbe arrivare in un momento successivo
        val authToken: String,
        val brandTitle: String?,    // white label: titolo mostrato sulla TV
        val brandSubtitle: String?,
        val brandAccent: String?,   // colore accento (#rrggbb)
    )

    sealed class PairState {
        data object Pending : PairState()
        data class Paired(val authToken: String, val screenName: String, val pollInterval: Int) : PairState()
        data object Expired : PairState()
    }

    suspend fun pairStart(baseUrl: String): PairStart = withContext(Dispatchers.IO) {
        val json = postJson(
            "$baseUrl/api/v1/pair.php",
            JSONObject().put("device_info", deviceInfo),
        )
        run {
            val brand = json.optJSONObject("branding")
            PairStart(
                pairingCode = json.getString("pairing_code"),
                pollToken = json.getString("poll_token"),
                qrUrl = json.getString("qr_url"),
                expiresIn = json.getInt("expires_in"),
                authToken = json.getString("auth_token"),
                brandTitle = brand?.optString("title")?.takeIf { it.isNotBlank() },
                brandSubtitle = brand?.optString("subtitle")?.takeIf { it.isNotBlank() },
                brandAccent = brand?.optString("accent")?.takeIf { it.matches(Regex("#[0-9a-fA-F]{6}")) },
            )
        }
    }

    suspend fun pairStatus(baseUrl: String, pollToken: String): PairState = withContext(Dispatchers.IO) {
        val json = try {
            postJson(
                "$baseUrl/api/v1/pair_status.php",
                JSONObject().put("poll_token", pollToken),
            )
        } catch (e: ApiException) {
            if (e.code == 404) return@withContext PairState.Expired
            throw e
        }
        when (json.optString("status")) {
            // Il token NON arriva più da qui: il dispositivo lo possiede
            // dall'avvio del pairing, questa risposta lo attiva soltanto
            "paired" -> PairState.Paired(
                authToken = "",
                screenName = json.getString("screen_name"),
                pollInterval = json.getInt("poll_interval"),
            )
            "expired" -> PairState.Expired
            else -> PairState.Pending
        }
    }

    // Metodo manuale: la URL incollata dal CMS contiene già il claim_token
    suspend fun claim(claimUrl: String): PairState.Paired = withContext(Dispatchers.IO) {
        val json = postJson(claimUrl, JSONObject().put("device_info", deviceInfo))
        PairState.Paired(
            authToken = json.getString("auth_token"),
            screenName = json.getString("screen_name"),
            pollInterval = json.getInt("poll_interval"),
        )
    }

    suspend fun playlistRaw(baseUrl: String, token: String): String = withContext(Dispatchers.IO) {
        val conn = open("$baseUrl/api/v1/playlist.php")
        conn.setRequestProperty("Authorization", "Bearer $token")
        conn.readResponse()
    }

    // Stato compatto: poche centinaia di byte, interrogato spesso per
    // reagire in fretta all'avvio o allo stop di una diretta
    data class ScreenState(
        val playlistVersion: String,
        val override: Override?,
        val stateInterval: Int,
    )

    suspend fun state(baseUrl: String, token: String): ScreenState = withContext(Dispatchers.IO) {
        val conn = open("$baseUrl/api/v1/state.php")
        conn.setRequestProperty("Authorization", "Bearer $token")
        val json = JSONObject(conn.readResponse())
        ScreenState(
            playlistVersion = json.optString("playlist_version"),
            override = Override.parse(json.optJSONObject("override")),
            stateInterval = json.optInt("state_interval", 10),
        )
    }

    // Segnala un avvio vero del processo (una volta per avvio, mai nel
    // polling di state.php): il CMS lo mostra come contatore, utile per
    // capire se e quanto il watchdog sta intervenendo senza doversi
    // collegare al dispositivo per saperlo. Chi chiama deve trattarla
    // "fire and forget": un fallimento qui non deve mai bloccare la
    // riproduzione, è solo telemetria.
    suspend fun reportRestart(baseUrl: String, token: String, reason: String): Unit =
        withContext(Dispatchers.IO) {
            val conn = open("$baseUrl/api/v1/restart.php")
            conn.requestMethod = "POST"
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json")
            conn.setRequestProperty("Authorization", "Bearer $token")
            conn.outputStream.use { it.write(JSONObject().put("reason", reason).toString().toByteArray()) }
            conn.readResponse()
            Unit
        }

    // Scarica su file temporaneo, verifica lo sha1 e solo allora rinomina:
    // in cache non entra mai un file corrotto o troncato.
    suspend fun downloadMedia(item: PlaylistItem, token: String, dest: File): Unit =
        withContext(Dispatchers.IO) {
            val tmp = File(dest.parentFile, dest.name + ".part")
            val conn = open(item.url)
            conn.setRequestProperty("Authorization", "Bearer $token")
            try {
                downloadInto(conn, item, tmp, dest)
            } catch (e: Exception) {
                tmp.delete() // mai lasciare parziali in cache
                throw e
            } finally {
                conn.disconnect()
            }
        }

    // Download semplice, senza verifica del checksum: per i file accessori
    // (oggi il logo del banner) di cui il server non pubblica lo sha1.
    // Il file appare in cache solo a scaricamento completato.
    suspend fun downloadTo(url: String, token: String, dest: File): Unit =
        withContext(Dispatchers.IO) {
            val tmp = File(dest.parentFile, dest.name + ".part")
            val conn = open(url)
            conn.setRequestProperty("Authorization", "Bearer $token")
            try {
                if (conn.responseCode !in 200..299) {
                    throw ApiException(conn.responseCode, "HTTP ${conn.responseCode}")
                }
                conn.inputStream.use { input ->
                    FileOutputStream(tmp).use { output -> input.copyTo(output, 64 * 1024) }
                }
                dest.delete()
                if (!tmp.renameTo(dest)) {
                    throw ApiException(0, "Impossibile completare il download")
                }
            } catch (e: Exception) {
                tmp.delete()
                throw e
            } finally {
                conn.disconnect()
            }
        }

    private fun downloadInto(
        conn: HttpURLConnection,
        item: PlaylistItem,
        tmp: File,
        dest: File,
    ) {
        if (conn.responseCode !in 200..299) {
            throw ApiException(conn.responseCode, "HTTP ${conn.responseCode}")
        }
        val digest = MessageDigest.getInstance("SHA-1")
        conn.inputStream.use { input ->
            FileOutputStream(tmp).use { output ->
                val buffer = ByteArray(64 * 1024)
                while (true) {
                    val read = input.read(buffer)
                    if (read < 0) break
                    digest.update(buffer, 0, read)
                    output.write(buffer, 0, read)
                }
            }
        }
        val sha1 = digest.digest().joinToString("") { "%02x".format(it) }
        if (!sha1.equals(item.checksum, ignoreCase = true)) {
            throw ApiException(0, "Checksum errato per ${item.url}")
        }
        if (!tmp.renameTo(dest)) {
            throw ApiException(0, "Impossibile salvare ${dest.name}")
        }
    }

    // ---------- interni ----------

    private fun open(url: String): HttpURLConnection =
        (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = TIMEOUT_MS
            readTimeout = TIMEOUT_MS
        }

    private fun postJson(url: String, body: JSONObject): JSONObject {
        val conn = open(url)
        conn.requestMethod = "POST"
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json")
        conn.outputStream.use { it.write(body.toString().toByteArray()) }
        return JSONObject(conn.readResponse())
    }

    private fun HttpURLConnection.readResponse(): String {
        try {
            val code = responseCode
            val stream = if (code in 200..299) inputStream else errorStream
            val text = stream?.bufferedReader()?.use { it.readText() } ?: ""
            if (code !in 200..299) {
                val message = runCatching { JSONObject(text).getString("error") }
                    .getOrDefault("HTTP $code")
                throw ApiException(code, message)
            }
            return text
        } finally {
            disconnect()
        }
    }
}
