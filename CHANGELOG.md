# Changelog

Tutte le modifiche rilevanti del progetto.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e la
numerazione [Semantic Versioning](https://semver.org/lang/it/).

## [1.0.0] — 2026-08-04

Prima versione completa.

### Server e CMS

- Gestione gallerie con caricamento, ordinamento drag & drop ed eliminazione
- Metadati per ogni file: rotazione, adattamento *contain*/*crop*, durata a
  schermo, audio per i video
- 11 transizioni con durata configurabile: predefinita per galleria e override
  di entrata/uscita sul singolo file
- Anteprima nel browser con transizioni reali e scorrimento manuale, per
  gallerie e per schermi
- Associazione degli schermi con PIN, QR code o URL di configurazione monouso
- Metadati del pannello fisico (pollici, orientamento) e assegnazione ordinata
  di più gallerie
- Accensione programmata a fasce orarie con schermo nero o immagine di cortesia
- Utenti con tre ruoli e permessi granulari personalizzabili
- Cestino con ripristino per media, gallerie e utenti, ed eliminazione
  automatica configurabile
- Personalizzazione di nome, logo, colore e testi, propagata anche all'app TV
- Sezione di manutenzione con informazioni di sistema, reset completo e
  disinstallazione protetti da doppia conferma

### App Android TV / Fire OS

- Riproduzione in loop di immagini e video a schermo intero
- Cache locale con verifica di integrità: funziona senza rete e scarica solo
  ciò che cambia
- Motore di transizioni allineato alle impostazioni del CMS
- Rotazione e adattamento applicati in riproduzione, senza modificare i file
- Programmazione oraria valutata localmente, quindi valida anche offline
- Avvio automatico all'accensione, con richiesta guidata del permesso sui
  sistemi che lo richiedono
- Impostazioni richiamabili dal telecomando per cambiare server o disassociare
- Una sola APK per Fire TV Stick, Android TV e box Android (da Android 5.1)

### Sicurezza

- Autorizzazione dei dispositivi con token opaco revocabile
- Protezione CSRF su tutte le scritture, con token rigenerato all'accesso
- Verifica del tipo dei file dal contenuto reale, cartella dei media chiusa al web
- Prepared statement nativi su tutte le interrogazioni
- Intestazioni anti-clickjacking e messaggi di errore che non espongono dettagli
  interni

### Aggiornamento

Prima versione pubblicata: nessuna procedura di migrazione necessaria.
