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
  "screen":   { "id": 2, "name": "Vetrina", "orientation": "portrait", "inches": 65 },
  "schedule": {
    "bands":     [ { "from": "09:00", "to": "20:00" } ],
    "idle_mode": "image",
    "idle_item": { "id": 12, "url": "…", "checksum": "…", "rotation": 0, "fit": "cover" }
  },
  "branding": { "title": "…", "subtitle": "…", "accent": "#ffb224" },
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
  fasce orarie, contenuto di riposo). Se non cambia, il dispositivo non ha
  nulla da fare.
- **`checksum`** è lo SHA-1 del file: usato come nome in cache, permette di
  riscaricare solo ciò che è realmente cambiato.
- **`transition_in`/`out`** arrivano già risolte: il server applica l'eventuale
  override del singolo file sul valore predefinito della galleria.
- **`bands`** vuoto significa riproduzione continua. Una fascia con `from`
  maggiore di `to` scavalca la mezzanotte.

### `GET media.php?id=<id>`

Scarica un file. Autorizzato solo se il media appartiene a una galleria
assegnata a quello schermo (o ne è l'immagine di cortesia).

Risponde con il file e il suo `Content-Type`. Il trasferimento avviene a
blocchi con svuotamento progressivo del buffer, per non troncare i file grandi
verso client lenti.

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
