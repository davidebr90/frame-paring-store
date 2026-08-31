# API

Endpoint REST usati dall'app TV. Tutte le risposte sono JSON.
Base: `https://tuodominio.it/percorso/api/v1/`.

## Autenticazione

Dopo l'associazione, il dispositivo riceve un **auth token** (256 bit) che
invia in ogni richiesta successiva:

```
Authorization: Bearer <auth_token>
```

Il token è opaco e revocabile all'istante dal CMS. Non ha scadenza: resta
valido finché lo schermo non viene revocato o eliminato.

## Flusso di associazione

```
POST pair.php          la TV chiede un codice
   ↓
(l'amministratore inserisce il PIN nel CMS)
   ↓
POST pair_status.php   la TV ritira il token
```

### `POST pair.php`

Richiede un codice di associazione. Non autenticato.

**Richiesta**
```json
{ "device_info": "Amazon AFTKA / Android 9 (Fire OS)" }
```

**Risposta**
```json
{
  "pairing_code": "437172",
  "poll_token": "9f2c…",
  "qr_url": "https://tuodominio.it/cms/pair.php?code=437172",
  "expires_in": 300,
  "poll_interval": 60,
  "branding": { "title": "…", "subtitle": "…", "accent": "#ffb224" }
}
```

Il `poll_token` è il segreto che identifica il dispositivo richiedente: senza,
chi vede il PIN sullo schermo non potrebbe comunque ritirare il token.

### `POST pair_status.php`

Verifica se l'amministratore ha confermato. Da chiamare a intervalli regolari.

**Richiesta**
```json
{ "poll_token": "9f2c…" }
```

**Risposte**

| Stato | Significato |
|---|---|
| `{"status":"pending"}` | in attesa di conferma |
| `{"status":"paired","auth_token":"…","screen_name":"…"}` | confermato: token consegnato |
| `{"status":"expired"}` (404) | codice scaduto o già usato: ricominciare |

Il token viene consegnato **una sola volta**: PIN e `poll_token` sono
invalidati subito dopo.

### `POST claim.php?token=<claim_token>`

Associazione senza PIN, per schermi creati in anticipo dal CMS. Il
`claim_token` è monouso.

**Risposta**
```json
{ "status": "paired", "auth_token": "…", "screen_name": "…", "poll_interval": 60 }
```

## Contenuti

### `GET playlist.php`

Playlist completa dello schermo autenticato.

```json
{
  "screen":   { "id": 2, "name": "Vetrina", "orientation": "portrait", "rotation": 90, "inches": 65 },
  "schedule": {
    "bands":     [ { "from": "09:00", "to": "20:00", "days": [1,2,3,4,5] } ],
    "idle_mode": "image",
    "idle_item": { "id": 12, "url": "…", "checksum": "…", "rotation": 0, "fit": "cover" }
  },
  "branding": { "title": "…", "subtitle": "…", "accent": "#ffb224" },
  "banner": {
    "position": "bottom_right", "size": "medium", "opacity": 80,
    "show_date": true, "show_time": true, "date_format": "long",
    "logo_url": "https://…/api/v1/overlay_logo.php", "logo_tag": "a48a2347196e2a01"
  },
  "override": null,
  "version":  "c9b0e765…",
  "poll_interval": 60,
  "items": [
    {
      "id": 4, "type": "image", "mime": "image/png",
      "url": "https://…/api/v1/media.php?id=4",
      "filesize": 111939, "checksum": "b81189…",
      "rotation": 90, "fit": "contain", "duration": 8,
      "transition_in": "crossfade", "transition_out": "slide_left",
      "transition_ms": 800, "muted": true
    }
  ]
}
```

Note sui campi:

- **`version`** è l'impronta di tutto ciò che il player deve rispettare (media,
  fasce orarie, contenuto di riposo, banner). Se non cambia, il dispositivo non
  ha nulla da fare.
- **`banner`** descrive la sovraimpressione **di quello schermo**, che può
  averne una propria o ereditare quella generale. È `null` quando è spenta o
  non ha nulla da mostrare. La data e l'ora **non** arrivano dal server: il client le compone
  con il proprio orologio secondo `date_format` (`short` → `24/06/2026`,
  `long` → `24 Giugno 2026`), così restano corrette anche offline.
  `logo_tag` cambia a ogni sostituzione del logo e va usato come nome in cache.
- **`screen.rotation`** (0/90/180/270, senso orario) è di quanto è girato il
  pannello fisico rispetto al segnale grezzo, sempre orizzontale.
  `screen.orientation` è la forma che ne deriva (`portrait` per 90°/270°,
  altrimenti `landscape`) — non va scelta a parte, solo letta.
- **`override`** descrive la diretta in sovrapposizione attiva su quello
  schermo, o `null`. Vedi *Diretta in sovrapposizione*.
- **`checksum`** è lo SHA-1 del file: usato come nome in cache, permette di
  riscaricare solo ciò che è realmente cambiato.
- **`items[].rotation`** per le immagini è già quella EFFETTIVA per questo
  schermo: il server la calcola da solo confrontando l'orientamento della
  foto con quello dello schermo (correggendo anche il montaggio), salvo
  correzione manuale impostata per quella coppia schermo/file. Il client
  non deve fare altro che applicarla — non è (più) un metadato fisso del
  file. Per i video resta 0 salvo correzione manuale.
- **`transition_in`/`out`** arrivano già risolte: il server applica l'eventuale
  override del singolo file sul valore predefinito della galleria.
- **`bands`** vuoto significa riproduzione continua. `days` usa la
  numerazione ISO (1 = lunedì … 7 = domenica) e, se assente, la fascia vale
  tutti i giorni: le playlist di server precedenti restano così valide.
  Una fascia con `from` maggiore di `to` scavalca la mezzanotte e **comincia
  nel giorno indicato**, finendo il mattino successivo.

### `GET media.php?id=<id>`

Scarica un file. Autorizzato solo se il media appartiene a una galleria
assegnata a quello schermo (o ne è l'immagine di cortesia).

Risponde con il file e il suo `Content-Type`. Il trasferimento avviene a
blocchi con svuotamento progressivo del buffer, per non troncare i file grandi
verso client lenti.

### `GET overlay_logo.php`

Logo del banner in sovraimpressione. Risponde `404` se il banner è spento o
non ha un logo. Il file non ha uno SHA-1 pubblicato: si usa `logo_tag` della
playlist come chiave di cache.

## Diretta in sovrapposizione

### `GET state.php`

Endpoint leggero, pensato per essere interrogato di frequente (ogni
`state_interval` secondi, molto più spesso di `poll_interval`). Serve a
reagire in pochi secondi senza riscaricare tutta la playlist.

`playlist_version` è **la stessa impronta** restituita nel campo `version`
della playlist: il client la confronta con quella che sta applicando e, se
differisce, richiede subito `playlist.php` invece di attendere il polling
successivo. È così che una modifica fatta nel CMS arriva sugli schermi in
pochi secondi.

```json
{
  "playlist_version": "c9b0e765…",
  "override": {
    "id": 7, "name": "Evento in piazza", "type": "media",
    "media_type": "video", "mime": "video/mp4",
    "url": "https://…/api/v1/media.php?id=9&live=1",
    "checksum": "f18e9f…", "filesize": 6601173,
    "fit": "contain", "rotation": 0, "muted": true, "loop": true,
    "elapsed": 249, "ends_in": 1551
  },
  "poll_interval": 60,
  "state_interval": 10
}
```

Note sui campi:

- **`type`** vale `media` (file da scaricare in cache, con `checksum`) oppure
  `external` (flusso HLS/DASH/RTSP da riprodurre dalla rete, `checksum` nullo).
- **`elapsed`** sono i secondi trascorsi dall'avvio della diretta: il client si
  posiziona lì dentro, così tutti gli schermi restano allineati anche se
  accesi in momenti diversi.
- **`ends_in`** è `null` per le dirette senza durata.
- Quando `override` torna `null` la diretta è finita: il client riprende la
  propria programmazione, che non è mai stata modificata.

## Telemetria

### `POST restart.php`

Segnala un avvio vero del processo app (non il polling): incrementa
`restart_count` sullo schermo e registra motivo e orario, visibili nella
scheda dello schermo nel CMS. Va chiamato **una sola volta per avvio**, da
`MainActivity.onCreate()` — mai nel ciclo di `state.php`/`playlist.php`.

**Richiesta**
```json
{ "reason": "watchdog" }
```

`reason` vale `boot` (il dispositivo si è acceso), `watchdog` (il processo
era morto, il watchdog l'ha risollevato da solo) o `manual` (avvio normale,
incluso un intervento manuale) — qualunque altro valore o l'assenza del
campo ricade su `manual`.

**Risposta**
```json
{ "ok": true }
```

Un fallimento di questa chiamata non deve mai bloccare la riproduzione: è
solo telemetria, il client la considera "fire and forget".

## Codici di errore

| Codice | Significato |
|---|---|
| 401 | token mancante o non valido |
| 403 | schermo revocato |
| 404 | risorsa inesistente, o codice di associazione scaduto |
| 422 | parametri mancanti o malformati |
| 500 | errore interno (i dettagli restano nel log del server) |
| 503 | sistema disinstallato |

## Comportamento atteso dal client

1. Alla partenza riproduce ciò che ha in cache, senza attendere la rete.
2. Interroga `playlist.php` a intervalli di `poll_interval` secondi.
3. Se `version` è cambiata, scarica solo i media il cui `checksum` non è già in
   cache ed elimina quelli non più elencati.
4. Un errore su un singolo download non è mai una revoca: si riprova al ciclo
   successivo. Solo un 401/403 su `playlist.php` indica che l'autorizzazione
   non è più valida.
