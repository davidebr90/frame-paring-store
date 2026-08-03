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

    private lateinit var pairingPanel: ScrollView
    private lateinit var serverForm: View
    private lateinit var serverInput: EditText
    private lateinit var codePanel: View
    private lateinit var pinText: TextView
    private lateinit var qrImage: ImageView
    private lateinit var pairStatus: TextView
    private lateinit var claimInput: EditText
    private lateinit var playerStatus: TextView

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

        serverInput.setText(prefs.baseUrl ?: BuildConfig.DEFAULT_BASE_URL)
        findViewById<Button>(R.id.pairButton).setOnClickListener { startPairing() }
        findViewById<Button>(R.id.claimButton).setOnClickListener { startClaim() }

        if (prefs.authToken != null) enterPlayerMode() else showPairingPanel()
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

    private fun enterPlayerMode() {
        pairingJob?.cancel()
        pairingPanel.visibility = View.GONE

        playbackJob?.cancel()
        playbackJob = scope.launch {
            // Prima la cache (playback immediato anche offline), poi il sync
            var playingVersion: String? = null
            val cached = sync.cachedPlaylist()
            if (cached != null && cached.items.isNotEmpty()) {
                playerController.play(cached)
                playingVersion = cached.version
            } else {
                showPlayerStatus(R.string.status_syncing)
            }

            while (isActive) {
                try {
                    val fresh = sync.sync()
                    if (fresh != null) {
                        if (fresh.items.isEmpty()) {
                            playerController.stop()
                            playingVersion = null
                            showPlayerStatus(R.string.status_empty)
                        } else if (fresh.version != playingVersion) {
                            hidePlayerStatus()
                            playerController.play(fresh)
                            playingVersion = fresh.version
                        }
                    } else if (playingVersion == null) {
                        showPlayerStatus(R.string.status_error)
                    }
                } catch (e: ApiException) {
                    // 401/403: schermo revocato dal CMS
                    prefs.clearPairing()
                    playerController.stop()
                    toast(getString(R.string.status_revoked))
                    showPairingPanel()
                    return@launch
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
