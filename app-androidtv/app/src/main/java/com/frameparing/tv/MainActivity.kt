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

import android.app.Activity
import android.content.Context
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
import kotlinx.coroutines.withTimeoutOrNull

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
    private var wasActive: Boolean? = null // per registrare i passaggi di fascia

    // Diretta in sovrapposizione attualmente in onda (id), se c'è
    private var stateJob: Job? = null
    private var activeOverrideId: Int? = null
    private var activeOverrideSig: String? = null // impronta della galleria in onda
    // Timeline della diretta su galleria: durate degli slot e ancora
    // temporale (elapsedRealtime a cui il ciclo valeva zero)
    private var gallerySlots: List<Long> = emptyList()
    private var galleryAnchor = 0L
    private lateinit var bannerController: BannerController
    private var bannerKey: String? = null // configurazione già applicata

    // Richiesta di sincronizzazione anticipata. CONFLATED: più segnali
    // ravvicinati valgono per uno solo, non si accodano.
    private val syncWake = kotlinx.coroutines.channels.Channel<Unit>(
        kotlinx.coroutines.channels.Channel.CONFLATED
    )

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
    private lateinit var pairProgress: android.widget.ProgressBar
    private lateinit var pairCountdown: TextView

    // La lingua va applicata PRIMA che il layout venga gonfiato: dopo,
    // le stringhe già risolte resterebbero nella lingua precedente.
    override fun attachBaseContext(newBase: Context) {
        val saved = Prefs(newBase).language
        super.attachBaseContext(LocaleHelper.wrap(newBase, saved))
    }

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
        bannerController = BannerController(this, findViewById(R.id.bannerLayer))

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
        pairProgress = findViewById(R.id.pairProgress)
        pairCountdown = findViewById(R.id.pairCountdown)

        findViewById<Button>(R.id.settingsSaveButton).setOnClickListener { saveServerFromSettings() }
        findViewById<Button>(R.id.settingsCloseButton).setOnClickListener { closeSettings() }
        findViewById<Button>(R.id.settingsUnpairButton).setOnClickListener { unpairFromSettings() }

        serverInput.setText(prefs.baseUrl ?: BuildConfig.DEFAULT_BASE_URL)
        findViewById<Button>(R.id.pairButton).setOnClickListener { startPairing() }
        findViewById<Button>(R.id.claimButton).setOnClickListener { startClaim() }
        // Interruttore: mostra lo stato reale del permesso, non solo un'azione.
        // Spegnerlo dall'app non è possibile (lo revoca il sistema), quindi
        // in quel caso si accompagna l'utente alla schermata di sistema.
        findViewById<android.widget.Switch>(R.id.autostartSwitch)
            .setOnClickListener { requestAutostartPermission() }

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
        val switch = findViewById<android.widget.Switch>(R.id.autostartSwitch)
        val granted = !autostartPermissionNeeded()

        // Il pannello resta visibile sempre: così si vede anche che è "acceso"
        panel.visibility = View.VISIBLE
        switch.isChecked = granted
        findViewById<TextView>(R.id.autostartState).setText(
            if (granted) R.string.autostart_on else R.string.autostart_off
        )
        findViewById<TextView>(R.id.autostartState)
            .setTextColor(if (granted) 0xFF3ECF6E.toInt() else 0xFF8B93A2.toInt())

        if (granted) {
            findViewById<View>(R.id.playerAutostartButton).visibility = View.GONE
            if (autostartRequested) {
                autostartRequested = false
                toast(getString(R.string.autostart_done))
            }
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
        stateJob?.cancel()
        currentPlaylist = null
        isIdle = false
        activeOverrideId = null
        playerController.stop()
        // Durante l'associazione il banner non c'entra nulla: si nasconde,
        // e la chiave azzerata lo fa rimontare quando riparte la riproduzione
        bannerController.hide()
        bannerKey = null
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

                // Battito al secondo per il conto alla rovescia, con
                // interrogazione del server ogni 4 tick: chi guarda lo schermo
                // vede che il sistema è vivo e quanto vale ancora il codice
                val totalMs = start.expiresIn * 1_000L
                val deadline = SystemClock.elapsedRealtime() + totalMs
                var paired: ApiClient.PairState.Paired? = null
                var tick = 0

                while (isActive) {
                    val remainingMs = deadline - SystemClock.elapsedRealtime()
                    if (remainingMs <= 0) break
                    updateCountdown(remainingMs, totalMs)

                    delay(1_000)
                    tick++
                    if (tick % 4 != 0) continue

                    val state = runCatching {
                        ApiClient.pairStatus(base, start.pollToken)
                    }.getOrNull() ?: continue
                    if (state is ApiClient.PairState.Paired) {
                        // Il chiaro del token è quello ricevuto all'avvio
                        paired = state.copy(authToken = start.authToken)
                        break
                    }
                    if (state is ApiClient.PairState.Expired) break
                }

                if (paired != null) {
                    onPaired(paired)
                    return@launch
                }
                showRenewing()
            }
        }
    }

    // Conto alla rovescia della validità del codice
    private fun updateCountdown(remainingMs: Long, totalMs: Long) {
        val seconds = (remainingMs / 1000).toInt()
        pairCountdown.text = getString(R.string.pairing_countdown, seconds / 60, seconds % 60)
        pairProgress.progress = ((remainingMs * 1000) / totalMs).toInt().coerceIn(0, 1000)
        pairProgress.visibility = View.VISIBLE
        pairCountdown.visibility = View.VISIBLE
    }

    // Il codice è scaduto: si sta chiedendo il successivo al server
    private fun showRenewing() {
        pairStatus.setText(R.string.pairing_renewing)
        pairProgress.progress = 0
        pairCountdown.visibility = View.GONE
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

    // Giorno della settimana nella numerazione ISO usata dal CMS:
    // 1 = lunedì … 7 = domenica. Calendar parte invece dalla domenica.
    private fun isoDayOfWeekNow(): Int {
        val cal = java.util.Calendar.getInstance()
        return when (val d = cal.get(java.util.Calendar.DAY_OF_WEEK)) {
            java.util.Calendar.SUNDAY -> 7
            else -> d - 1
        }
    }

    // Ciclo di stato: risposta leggerissima, interrogata spesso, così una
    // diretta avviata dal CMS compare sugli schermi in pochi secondi.
    private fun startStateLoop() {
        stateJob?.cancel()
        stateJob = scope.launch {
            var interval = 10
            while (isActive) {
                delay(interval * 1_000L)
                val base = prefs.baseUrl ?: continue
                val token = prefs.authToken ?: continue
                val state = runCatching { ApiClient.state(base, token) }.getOrNull() ?: continue
                interval = state.stateInterval.coerceIn(5, 60)
                applyOverride(state.override)

                // La version è la stessa impronta che accompagna la playlist:
                // se è cambiata, una modifica fatta dal CMS attende solo di
                // essere applicata. Si sveglia subito il ciclo di sync invece
                // di lasciar passare l'intero intervallo di polling.
                val applied = currentPlaylist?.version
                if (state.playlistVersion.isNotBlank() && applied != null
                    && state.playlistVersion != applied
                ) {
                    syncWake.trySend(Unit)
                }
            }
        }
    }

    // Entra o esce dalla diretta. Uscendo, la playlist normale riprende
    // dallo stato in cui era: non è mai stata modificata.
    // Oltre questa deriva si corregge la posizione; sotto, si lascia stare:
    // l'elapsed del server ha la precisione del secondo e un ri-allineamento
    // continuo produrrebbe solo scatti visibili senza guadagno reale
    private companion object {
        const val SYNC_TOLERANCE_MS = 3_500L
        // Durante lo sforamento di un video la fine del file si ri-aggancia
        // da sola: il riallineamento a strappo interverrebbe a metà scena.
        // Oltre questa soglia però il video è considerato bloccato e la
        // rete di sicurezza torna a valere.
        const val VIDEO_OVERRUN_GRACE_MS = 15_000L
    }

    private fun applyOverride(override: Override?) {
        if (override != null && override.id == activeOverrideId
            && (override.type != "gallery" || override.itemsSig == activeOverrideSig)
        ) {
            // Nessun cambiamento di contenuto: per le gallerie si sfrutta il
            // battito per correggere l'eventuale deriva. L'elapsed appena
            // ricevuto È l'orologio del server: chi ha accumulato scarto
            // (buffering, un video dalla durata stimata male) rientra.
            if (override != null && override.type == "gallery" && gallerySlots.isNotEmpty()) {
                galleryAnchor = override.parsedAtRealtime - override.elapsedSeconds * 1_000L
                // L'aggancio ordinario è del player, a ogni cambio di file;
                // il ri-allineamento duro scatta solo oltre la tolleranza
                playerController.updateAnchor(galleryAnchor)
                realignGallery()
            }
            return
        }

        if (override == null) {
            if (activeOverrideId == null) {
                return // non c'era nulla in onda: nulla da riprendere
            }
            android.util.Log.i("FPS", "Diretta terminata: riprende la programmazione")
            activeOverrideId = null
            activeOverrideSig = null
            isIdle = false
            val pl = currentPlaylist
            if (pl != null) {
                applySchedule(pl, force = true)
            } else {
                // Non c'è una programmazione da riprendere: meglio dirlo
                // che lasciare uno schermo nero senza spiegazioni
                playerController.stop()
                showPlayerStatus(R.string.status_empty)
            }
            return
        }

        android.util.Log.i("FPS", "Diretta ${override.id} «${override.name}» da ${override.elapsedSeconds}s")
        activeOverrideId = override.id

        // Galleria intera: si riusa il player della playlist, con transizioni
        // e durate dei singoli file. Non serve un percorso di riproduzione a
        // parte, serve solo un'altra lista.
        if (override.type == "gallery") {
            activeOverrideSig = override.itemsSig
            scope.launch {
                showPlayerStatus(R.string.status_syncing)
                // Da state.php arriva solo l'impronta: l'elenco intero sta
                // nella playlist, che si scarica al bisogno
                val pieno = if (override.items.isNotEmpty()) override else fetchFullOverride(override.id)
                if (activeOverrideId != override.id) return@launch
                if (pieno == null) {
                    activeOverrideId = null
                    activeOverrideSig = null
                    android.util.Log.w("FPS", "Diretta ${override.id}: elenco non recuperato, riprovo")
                    return@launch
                }
                val pronti = sync.ensureOverrideItems(pieno)
                if (pronti.isEmpty()) {
                    // Nulla in cache: si riprova al giro successivo invece di
                    // lasciare lo schermo fermo per tutta la diretta
                    activeOverrideId = null
                    activeOverrideSig = null
                    android.util.Log.w("FPS", "Diretta ${override.id}: nessun file pronto, riprovo")
                    return@launch
                }
                if (pronti.size != pieno.items.size) {
                    // Con file mancanti la timeline di questo schermo non può
                    // coincidere con quella degli altri: si riproduce comunque,
                    // ma va detto nei log
                    android.util.Log.w("FPS", "Diretta ${override.id}: ${pieno.items.size - pronti.size} file mancanti, sincronia parziale")
                }
                hidePlayerStatus()
                // La timeline: ogni immagine occupa transizione+esposizione
                // (esattamente ciò che il player pianifica), ogni video la sua
                // durata reale letta dal file. Stessi file su tutti gli
                // schermi, stessi numeri su tutti gli schermi.
                gallerySlots = pronti.map { item ->
                    if (item.type == "video") {
                        sync.probeVideoDurationMs(item) ?: 10_000L
                    } else {
                        item.transitionMs + item.duration.coerceAtLeast(1) * 1_000L
                    }
                }
                // L'elapsed vale all'istante del parse della risposta, non
                // adesso: nel mezzo ci sono stati i download della galleria
                galleryAnchor = override.parsedAtRealtime - override.elapsedSeconds * 1_000L
                playerController.playSynced(
                    Playlist(
                        version = "live-${override.id}-${override.itemsSig}",
                        pollInterval = prefs.pollInterval,
                        items = pronti,
                    ),
                    gallerySlots,
                    galleryAnchor,
                )
            }
            return
        }
        activeOverrideSig = null

        scope.launch {
            // I file si scaricano in cache; i flussi esterni si riproducono
            // direttamente dalla rete
            val file = if (override.type == "media") sync.ensureOverrideCached(override) else null
            if (activeOverrideId != override.id) return@launch
            if (override.type == "media" && file == null) {
                // Si azzera l'id così il ciclo successivo riprova il download:
                // senza questo lo schermo resterebbe fermo sulla sincronizzazione
                activeOverrideId = null
                showPlayerStatus(R.string.status_syncing)
                android.util.Log.w("FPS", "Diretta ${override.id}: file non disponibile, riprovo")
                return@launch
            }
            hidePlayerStatus()
            playerController.playOverride(override, file)
        }
    }

    // Lingua decisa nel CMS. Cambiarla richiede di ricostruire l'activity:
    // è l'unico modo perché le risorse già risolte vengano rilette. Succede
    // solo quando cambia davvero, quindi la riproduzione non ne risente.
    private fun applyLanguage(playlist: Playlist) {
        val wanted = playlist.language
        if (!LocaleHelper.isSupported(wanted) || wanted == prefs.language) {
            return
        }
        android.util.Log.i("FPS", "Lingua dell'interfaccia: $wanted")
        prefs.language = wanted
        recreate()
    }

    // Monta o smonta il banner in sovraimpressione. Si ricostruisce solo
    // quando la configurazione cambia davvero: ogni polling altrimenti
    // ricreerebbe le view e farebbe sfarfallare il riquadro.
    private fun applyBanner(playlist: Playlist) {
        val config = playlist.banner
        val key = config?.let {
            listOf(
                it.position, it.size, it.opacity, it.showDate,
                it.showTime, it.dateFormat, it.logoTag,
            ).joinToString("|")
        }
        if (key == bannerKey) return
        bannerKey = key
        scope.launch {
            val logo = sync.ensureBannerLogo(config)
            bannerController.apply(config, logo)
            // Logo atteso ma non arrivato (server irraggiungibile, errore
            // temporaneo): il banner intanto mostra data e ora, e la chiave
            // azzerata fa riprovare il download al polling successivo
            if (config?.logoUrl != null && logo == null) {
                bannerKey = null
            }
        }
    }

    // Confronta la posizione reale nel ciclo con quella attesa e, se la
    // deriva supera la tolleranza, riposiziona. È questo che rende la
    // sincronia definitiva: anche partendo perfetti, ore di riproduzione
    // accumulano scarti — e ogni battito di stato li azzera.
    private fun realignGallery() {
        val slots = gallerySlots
        if (slots.isEmpty()) return
        val cycle = slots.sum().coerceAtLeast(1L)
        val atteso = ((SystemClock.elapsedRealtime() - galleryAnchor) % cycle + cycle) % cycle
        val reale = playerController.playbackGlobalMs() ?: return
        // Deriva circolare: a fine ciclo 100 ms e cycle-100 ms sono vicini
        val diff = ((atteso - reale) % cycle + cycle) % cycle
        val deriva = minOf(diff, cycle - diff)
        // Un video che sta sforando il proprio slot si corregge da solo
        // alla propria fine: qui si interviene solo se è rimasto indietro
        // così tanto da sembrare bloccato
        if (deriva <= SYNC_TOLERANCE_MS) return
        if (playerController.inVideoOverrun() && deriva <= VIDEO_OVERRUN_GRACE_MS) {
            android.util.Log.i("FPS", "Diretta: video oltre il suo slot di ${deriva} ms, attendo la sua fine")
            return
        }
        run {
            android.util.Log.i("FPS", "Diretta: deriva di ${deriva} ms, riallineo")
            playerController.playSynced(
                Playlist(
                    version = "live-realign",
                    pollInterval = prefs.pollInterval,
                    items = playerController.currentItems() ?: return,
                ),
                slots,
                galleryAnchor,
            )
        }
    }

    // Elenco completo di una diretta su galleria: sta nella playlist, che
    // lo porta per intero (state.php manda solo l'impronta)
    private suspend fun fetchFullOverride(overrideId: Int): Override? {
        val base = prefs.baseUrl ?: return null
        val token = prefs.authToken ?: return null
        return runCatching {
            Playlist.parse(ApiClient.playlistRaw(base, token)).override
        }.getOrNull()?.takeIf { it.id == overrideId && it.items.isNotEmpty() }
    }

    // Applica lo stato di programmazione corrente. Ritorna true se in fascia.
    private fun applySchedule(playlist: Playlist, force: Boolean): Boolean {
        // Durante una diretta il programma ordinario resta sospeso
        if (activeOverrideId != null) {
            return false
        }
        val active = playlist.isActiveAt(isoDayOfWeekNow(), minuteOfDayNow())
        // Traccia i passaggi dentro/fuori fascia: è la prima cosa da
        // guardare quando uno schermo "non si accende" a un certo orario
        if (active != wasActive) {
            wasActive = active
            android.util.Log.i(
                "FPS",
                "Programmazione: " + (if (active) "in fascia" else "fuori fascia") +
                    " (giorno " + isoDayOfWeekNow() + ", " + minuteOfDayNow() / 60 + ":" +
                    "%02d".format(minuteOfDayNow() % 60) + ", fasce " + playlist.scheduleBands.size + ")"
            )
        }
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

        startStateLoop()

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
                applyLanguage(cached)
                applySchedule(cached, force = true)
                applyBanner(cached)
            } else {
                showPlayerStatus(R.string.status_syncing)
            }

            while (isActive) {
                try {
                    val fresh = sync.sync()
                    if (fresh != null) {
                        if (fresh.items.isEmpty()) {
                            currentPlaylist = null
                            playingKey = null
                            // Con una diretta in corso il player sta
                            // riproducendo LEI, non la playlist: fermarlo
                            // qui la ucciderebbe in silenzio a ogni polling
                            if (activeOverrideId == null) {
                                playerController.stop()
                                showPlayerStatus(R.string.status_empty)
                            }
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
                            applyLanguage(fresh)
                            applySchedule(fresh, force = changed || (idleChanged && isIdle))
                            applyBanner(fresh)
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
                // Attesa interrompibile: il ciclo di stato può svegliarla
                // appena si accorge che il CMS ha cambiato qualcosa
                withTimeoutOrNull(prefs.pollInterval.coerceAtLeast(15) * 1_000L) {
                    syncWake.receive()
                }
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
