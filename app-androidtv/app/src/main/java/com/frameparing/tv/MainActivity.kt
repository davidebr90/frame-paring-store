package com.frameparing.tv

import android.app.Activity
import android.os.Bundle
import android.os.SystemClock
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

// Orchestrazione: setup server -> pairing (PIN/QR o claim manuale) ->
// sync periodico + player in loop. Activity pura, nessuna AppCompat.
class MainActivity : Activity() {

    private lateinit var prefs: Prefs
    private lateinit var sync: SyncEngine
    private lateinit var playerController: PlayerController

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var pairingJob: Job? = null
    private var playbackJob: Job? = null
    private var scheduleJob: Job? = null

    // Stato della programmazione oraria
    private var currentPlaylist: Playlist? = null
    private var isIdle = false
    private var idleKey: String? = null

    private lateinit var pairingPanel: ScrollView
    private lateinit var serverForm: View
    private lateinit var serverInput: EditText
    private lateinit var codePanel: View
    private lateinit var pinText: TextView
    private lateinit var qrImage: ImageView
    private lateinit var pairStatus: TextView
    private lateinit var claimInput: EditText
    private lateinit var playerStatus: TextView
    private lateinit var settingsPanel: View
    private lateinit var settingsServerInput: EditText

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        enterImmersiveMode()

        prefs = Prefs(this)
        sync = SyncEngine(this, prefs)
        playerController = PlayerController(
            this,
            findViewById<FrameLayout>(R.id.playerContainer),
            sync,
            scope,
        )

        pairingPanel = findViewById(R.id.pairingPanel)
        serverForm = findViewById(R.id.serverForm)
        serverInput = findViewById(R.id.serverInput)
        codePanel = findViewById(R.id.codePanel)
        pinText = findViewById(R.id.pinText)
        qrImage = findViewById(R.id.qrImage)
        pairStatus = findViewById(R.id.pairStatus)
        claimInput = findViewById(R.id.claimInput)
        playerStatus = findViewById(R.id.playerStatus)
        settingsPanel = findViewById(R.id.settingsPanel)
        settingsServerInput = findViewById(R.id.settingsServerInput)

        findViewById<Button>(R.id.settingsSaveButton).setOnClickListener { saveServerFromSettings() }
        findViewById<Button>(R.id.settingsCloseButton).setOnClickListener { closeSettings() }
        findViewById<Button>(R.id.settingsUnpairButton).setOnClickListener { unpairFromSettings() }

        serverInput.setText(prefs.baseUrl ?: BuildConfig.DEFAULT_BASE_URL)
        findViewById<Button>(R.id.pairButton).setOnClickListener { startPairing() }
        findViewById<Button>(R.id.claimButton).setOnClickListener { startClaim() }
        findViewById<Button>(R.id.autostartButton).setOnClickListener { requestAutostartPermission() }

        if (prefs.authToken != null) enterPlayerMode() else showPairingPanel()
    }

    override fun onResume() {
        super.onResume()
        refreshAutostartPanel()
    }

    // ---------- impostazioni richiamabili dal telecomando ----------

    // MENU (presente sui telecomandi Fire TV) oppure pressione lunga su OK:
    // servono per cambiare server o disassociare una TV già in funzione.
    override fun onKeyDown(keyCode: Int, event: android.view.KeyEvent?): Boolean {
        if (keyCode == android.view.KeyEvent.KEYCODE_MENU ||
            keyCode == android.view.KeyEvent.KEYCODE_SETTINGS
        ) {
            toggleSettings()
            return true
        }
        // Pressione lunga su OK: alternativa per i telecomandi senza tasto MENU
        if (keyCode == android.view.KeyEvent.KEYCODE_DPAD_CENTER && event?.repeatCount == 4) {
            toggleSettings()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    override fun onBackPressed() {
        if (settingsPanel.visibility == View.VISIBLE) {
            closeSettings()
            return
        }
        @Suppress("DEPRECATION")
        super.onBackPressed()
    }

    private var unpairArmed = false

    private fun toggleSettings() {
        if (settingsPanel.visibility == View.VISIBLE) {
            closeSettings()
            return
        }
        unpairArmed = false
        settingsServerInput.setText(prefs.baseUrl ?: BuildConfig.DEFAULT_BASE_URL)
        findViewById<TextView>(R.id.settingsState).setText(
            if (prefs.authToken != null) R.string.settings_state_paired else R.string.settings_state_unpaired
        )
        findViewById<TextView>(R.id.settingsVersion).text =
            "${BuildConfig.VERSION_NAME} · ${ApiClient.deviceInfo}"
        findViewById<Button>(R.id.settingsUnpairButton).setText(R.string.settings_unpair)
        settingsPanel.visibility = View.VISIBLE
        settingsServerInput.requestFocus()
    }

    private fun closeSettings() {
        settingsPanel.visibility = View.GONE
        unpairArmed = false
    }

    // Cambio di server: il token attuale vale solo per il vecchio host,
    // quindi si riparte dal pairing sul nuovo indirizzo.
    private fun saveServerFromSettings() {
        val base = settingsServerInput.text.toString().trim().trimEnd('/')
        if (!base.startsWith("http://") && !base.startsWith("https://")) {
            toast(getString(R.string.server_hint))
            return
        }
        val changed = base != prefs.baseUrl
        prefs.baseUrl = base
        if (changed) {
            prefs.clearPairing()
            toast(getString(R.string.settings_saved))
            closeSettings()
            showPairingPanel()
            serverInput.setText(base)
        } else {
            closeSettings()
        }
    }

    // Disassociazione locale: la TV dimentica il token e torna al pairing.
    // Doppia pressione per evitare disassociazioni accidentali.
    private fun unpairFromSettings() {
        val button = findViewById<Button>(R.id.settingsUnpairButton)
        if (!unpairArmed) {
            unpairArmed = true
            button.setText(R.string.settings_unpair_confirm)
            return
        }
        prefs.clearPairing()
        closeSettings()
        showPairingPanel()
    }

    // Applica il white label ricevuto dal server alla schermata di configurazione
    private fun applyBranding(title: String?, subtitle: String?, accent: String?) {
        title?.let { findViewById<TextView>(R.id.brandTitle).text = it }
        subtitle?.let { findViewById<TextView>(R.id.brandSubtitle).text = it }
        accent?.let {
            val color = android.graphics.Color.parseColor(it)
            findViewById<TextView>(R.id.brandTitle).setTextColor(color)
            pinText.setTextColor(color)
        }
    }

    // ---------- avvio automatico (Android 10+) ----------

    // Da Android 10 il sistema blocca le activity avviate dal BootReceiver
    // a meno che l'app non abbia il permesso "Mostra sopra altre app".
    // Android NON lo chiede da solo: il bottone porta alla pagina giusta
    // e sparisce quando il permesso risulta concesso.
    private fun autostartPermissionNeeded(): Boolean =
        android.os.Build.VERSION.SDK_INT >= 29 &&
            !android.provider.Settings.canDrawOverlays(this)

    private var autostartRequested = false

    private fun refreshAutostartPanel() {
        val panel = findViewById<View>(R.id.autostartPanel)
        val needed = autostartPermissionNeeded()
        panel.visibility = if (needed) View.VISIBLE else View.GONE
        if (!needed) {
            findViewById<View>(R.id.playerAutostartButton).visibility = View.GONE
        }
        if (!needed && autostartRequested) {
            autostartRequested = false
            toast(getString(R.string.autostart_done))
        }
    }

    // In modalità player il pannello di setup non c'è: si mostra un invito
    // flottante per 20 secondi, azionabile col telecomando
    private fun showPlayerAutostartHint() {
        if (!autostartPermissionNeeded()) return
        val button = findViewById<Button>(R.id.playerAutostartButton)
        button.visibility = View.VISIBLE
        button.setOnClickListener { requestAutostartPermission() }
        button.requestFocus()
        button.postDelayed({ button.visibility = View.GONE }, 20_000)
    }

    private fun requestAutostartPermission() {
        autostartRequested = true
        val direct = android.content.Intent(
            android.provider.Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
            android.net.Uri.parse("package:$packageName"),
        )
        try {
            startActivity(direct)
        } catch (_: Exception) {
            // Alcune TV non espongono la pagina diretta: si passa dai dettagli app
            try {
                startActivity(
                    android.content.Intent(
                        android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                        android.net.Uri.parse("package:$packageName"),
                    )
                )
                toast(getString(R.string.autostart_manual))
            } catch (_: Exception) {
                toast(getString(R.string.autostart_manual))
            }
        }
    }

    override fun onDestroy() {
        playerController.release()
        scope.cancel()
        super.onDestroy()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) enterImmersiveMode()
    }

    @Suppress("DEPRECATION") // API storiche: valgono anche su Fire OS 5/6
    private fun enterImmersiveMode() {
        window.decorView.systemUiVisibility =
            View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or
                View.SYSTEM_UI_FLAG_FULLSCREEN or
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE or
                View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN or
                View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
    }

    // ---------- pairing PIN / QR ----------

    private fun showPairingPanel() {
        playbackJob?.cancel()
        scheduleJob?.cancel()
        currentPlaylist = null
        isIdle = false
        playerController.stop()
        playerStatus.visibility = View.GONE
        pairingPanel.visibility = View.VISIBLE
        serverForm.visibility = View.VISIBLE
        codePanel.visibility = View.GONE
    }

    private fun startPairing() {
        val base = serverInput.text.toString().trim().trimEnd('/')
        if (!base.startsWith("http://") && !base.startsWith("https://")) {
            toast(getString(R.string.server_hint))
            return
        }
        prefs.baseUrl = base

        pairingJob?.cancel()
        pairingJob = scope.launch {
            serverForm.visibility = View.GONE
            codePanel.visibility = View.VISIBLE

            while (isActive) {
                pairStatus.setText(R.string.status_connecting)
                val start = try {
                    ApiClient.pairStart(base)
                } catch (_: Exception) {
                    pairStatus.setText(R.string.status_error)
                    delay(5_000)
                    continue
                }

                applyBranding(start.brandTitle, start.brandSubtitle, start.brandAccent)
                pinText.text = start.pairingCode.chunked(3).joinToString(" ")
                qrImage.setImageBitmap(QrCode.render(start.qrUrl, 512))
                pairStatus.setText(R.string.pairing_wait)

                // Polling fitto (4s) finché il PIN è valido: la conferma
                // dell'admin deve riflettersi subito sulla TV
                val deadline = SystemClock.elapsedRealtime() + start.expiresIn * 1_000L
                var paired: ApiClient.PairState.Paired? = null
                while (isActive && SystemClock.elapsedRealtime() < deadline) {
                    delay(4_000)
                    val state = runCatching {
                        ApiClient.pairStatus(base, start.pollToken)
                    }.getOrNull() ?: continue
                    if (state is ApiClient.PairState.Paired) {
                        paired = state
                        break
                    }
                    if (state is ApiClient.PairState.Expired) break
                }

                if (paired != null) {
                    onPaired(paired)
                    return@launch
                }
                pairStatus.setText(R.string.pairing_expired)
            }
        }
    }

    // ---------- configurazione manuale (URL dedicata) ----------

    private fun startClaim() {
        val url = claimInput.text.toString().trim()
        if (!url.contains("/api/v1/claim.php?token=")) {
            toast(getString(R.string.manual_hint))
            return
        }
        scope.launch {
            try {
                val paired = ApiClient.claim(url)
                prefs.baseUrl = url.substringBefore("/api/v1/")
                pairingJob?.cancel()
                onPaired(paired)
            } catch (e: Exception) {
                toast(e.message ?: getString(R.string.status_error))
            }
        }
    }

    private fun onPaired(state: ApiClient.PairState.Paired) {
        prefs.authToken = state.authToken
        prefs.pollInterval = state.pollInterval
        toast(state.screenName)
        enterPlayerMode()
    }

    // ---------- player + sync periodico ----------

    private fun minuteOfDayNow(): Int {
        val cal = java.util.Calendar.getInstance()
        return cal.get(java.util.Calendar.HOUR_OF_DAY) * 60 + cal.get(java.util.Calendar.MINUTE)
    }

    // Applica lo stato di programmazione corrente. Ritorna true se in fascia.
    private fun applySchedule(playlist: Playlist, force: Boolean): Boolean {
        val active = playlist.isActiveAt(minuteOfDayNow())
        if (active && (isIdle || force)) {
            isIdle = false
            hidePlayerStatus()
            playerController.play(playlist)
        } else if (!active && (!isIdle || force)) {
            isIdle = true
            hidePlayerStatus()
            playerController.idle(if (playlist.idleMode == "image") playlist.idleItem else null)
        }
        return active
    }

    private fun enterPlayerMode() {
        pairingJob?.cancel()
        pairingPanel.visibility = View.GONE
        showPlayerAutostartHint()

        // Sentinella oraria: entra/esce dalle fasce anche senza rete
        scheduleJob?.cancel()
        scheduleJob = scope.launch {
            while (isActive) {
                delay(30_000)
                currentPlaylist?.let { applySchedule(it, force = false) }
            }
        }

        playbackJob?.cancel()
        playbackJob = scope.launch {
            // Prima la cache (playback immediato anche offline), poi il sync.
            // La chiave include anche i checksum degli item pronti: se la
            // version non cambia ma nuovi file completano il download, il
            // loop va comunque rigenerato.
            fun playKey(pl: Playlist): String =
                pl.version + ":" + pl.items.joinToString(",") { it.checksum }

            var playingKey: String? = null
            val cached = sync.cachedPlaylist()
            if (cached != null && cached.items.isNotEmpty()) {
                currentPlaylist = cached
                playingKey = playKey(cached)
                applySchedule(cached, force = true)
            } else {
                showPlayerStatus(R.string.status_syncing)
            }

            while (isActive) {
                try {
                    val fresh = sync.sync()
                    if (fresh != null) {
                        if (fresh.items.isEmpty()) {
                            playerController.stop()
                            currentPlaylist = null
                            playingKey = null
                            showPlayerStatus(R.string.status_empty)
                        } else {
                            // currentPlaylist sempre aggiornata: anche a parità di
                            // contenuti le fasce orarie possono essere cambiate.
                            // Se cambia la config di riposo mentre siamo fuori
                            // fascia, lo stato idle va ridisegnato subito.
                            currentPlaylist = fresh
                            val newIdleKey = fresh.idleMode + ":" + (fresh.idleItem?.checksum ?: "")
                            val idleChanged = newIdleKey != idleKey
                            idleKey = newIdleKey
                            val changed = playKey(fresh) != playingKey
                            playingKey = playKey(fresh)
                            applySchedule(fresh, force = changed || (idleChanged && isIdle))
                        }
                    } else if (playingKey == null) {
                        showPlayerStatus(R.string.status_error)
                    }
                } catch (e: ApiException) {
                    // 401/403: schermo revocato dal CMS
                    prefs.clearPairing()
                    playerController.stop()
                    toast(getString(R.string.status_revoked))
                    showPairingPanel()
                    return@launch
                } catch (e: Exception) {
                    // Qualunque imprevisto NON deve uccidere il loop di sync:
                    // uno schermo in vetrina deve riprovare, sempre
                    android.util.Log.w("FPS", "Errore imprevisto nel sync, riprovo", e)
                }
                delay(prefs.pollInterval.coerceAtLeast(15) * 1_000L)
            }
        }
    }

    private fun showPlayerStatus(resId: Int) {
        playerStatus.setText(resId)
        playerStatus.visibility = View.VISIBLE
    }

    private fun hidePlayerStatus() {
        playerStatus.visibility = View.GONE
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show()
    }
}
