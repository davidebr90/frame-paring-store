# FRAME PARING STORE

Sistema di digital signage self-hosted.

- **`server/`** — CMS + API REST in PHP 8 / MySQL (PDO). Pensato per hosting condiviso: si carica via FTP, nessun comando da terminale richiesto.
- **`app-androidtv/`** — App Kotlin per Android TV / Fire Stick: pairing via PIN, cache offline dei media, player in loop con rotazione/fit da metadati.
- **`database/`** — Schema SQL del database.

## Struttura server

```
server/
├── api/v1/       API REST consumate dall'app TV (pairing, playlist, download media)
├── cms/          Dashboard di amministrazione (login, schermi, gallerie, media)
├── core/         Config, connessione DB, autenticazione, helper condivisi
└── uploads/      File multimediali caricati dal CMS
```

## Installazione (hosting condiviso)

1. Creare un database MySQL e importare `database/schema.sql` (es. via phpMyAdmin).
2. Caricare il contenuto di `server/` nella cartella pubblica dell'hosting.
3. Copiare `server/core/config.sample.php` in `server/core/config.php` e compilare le credenziali.
4. Aprire il CMS nel browser: al primo avvio verrà chiesto di creare l'utente amministratore.
