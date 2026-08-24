# Changelog

Tutte le modifiche rilevanti del progetto.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e la
numerazione [Semantic Versioning](https://semver.org/lang/it/).

## [1.12.5] — 2026-08-24

### Corretto (app Android TV)

- **Il watchdog della 1.12.4 crashava all'avvio su Fire OS 5 (Android 5.1,
  API 22)**: osservato in produzione sullo schermo reale — un Fire TV
  Stick di prima generazione — via `adb logcat`. `NotificationChannel` e
  `NotificationManager.getNotificationChannel()`, usati dalla notifica a
  schermo intero introdotta in 1.12.4, sono API di Android 8.0 (26):
  su un framework che non le ha proprio, la chiamata lancia un
  `NoSuchMethodError`. Essendo un `Error` e non un'`Exception`, il
  `catch (e: Exception)` della 1.12.4 non lo intercettava: il processo
  appena resuscitato dal watchdog moriva di nuovo all'istante, ogni 15
  minuti, impedendo qualunque ripristino automatico — peggio della 1.12.3,
  dove almeno il tentativo diretto (inutile su Android 10+, ma
  funzionante sotto) restava in piedi. Corretto guardando `Build.VERSION.SDK_INT
  >= 26` prima di tentare la notifica, e allargando tutti i `catch` del
  watchdog da `Exception` a `Throwable`: un meccanismo di sicurezza non
  deve mai poter diventare lui stesso la causa del guasto, qualunque
  imprevisto capiti su qualunque firmware.

## [1.12.4] — 2026-08-23

### Corretto (app Android TV)

- **Il watchdog della 1.12.3 non risollevava davvero l'app dopo un kill di
  sistema**: osservato in produzione — lo schermo risultava "Offline" nel
  CMS e restava sulla Home della Fire Stick finché non si riapriva l'app a
  mano o si riavviava il dispositivo, nonostante l'`AlarmManager` ogni 15
  minuti fosse regolarmente in esecuzione. Causa: da Android 10 in poi il
  sistema blocca **in silenzio**, senza eccezione né log, un
  `Activity.startActivity()` lanciato da un `BroadcastReceiver` in
  background — la sveglia dell'`AlarmManager` risuscitava il processo solo
  per eseguire una chiamata che il sistema scartava immediatamente.
  Sostituito con una notifica a schermo intero (`setFullScreenIntent`),
  l'unico canale che Android garantisce anche a un processo appena
  resuscitato per l'occasione: è lo stesso meccanismo usato da sveglie e
  chiamate in arrivo. Il tentativo diretto della 1.12.3 resta come rete di
  sicurezza aggiuntiva, innocuo se bloccato.
- **Il watchdog interveniva alla cieca anche quando l'app era già in primo
  piano**: non c'era modo di distinguere un vero ripristino necessario da
  un risveglio di routine. `MainActivity` ora espone se è effettivamente
  visibile (`onStart`/`onStop`); il watchdog lo controlla prima di agire —
  la stessa logica copre anche il caso, distinto dal crash, in cui il
  processo resta vivo ma qualcos'altro della Fire Stick (screensaver di
  sistema, Home) è passato sopra all'app senza ucciderla.

## [1.12.3] — 2026-08-18

### Corretto (app Android TV)

- **Nessun ripristino automatico se il sistema uccide il processo**: su un
  Fire Stick osservato in produzione, un episodio di pressione di memoria
  ha ucciso 341 processi in un colpo solo — inclusa la nostra app e,
  poco dopo, perfino il launcher Fire TV stesso. `BootReceiver` copre solo
  il riavvio del dispositivo: se il device resta acceso ma il processo
  muore, prima d'ora nulla lo faceva ripartire senza intervento manuale.
  Aggiunto un controllo periodico (`AlarmManager`, ogni 15 minuti): la
  sveglia è registrata presso il sistema, non dentro il nostro processo,
  quindi richiama l'app anche se nel frattempo è morta del tutto.
- **Lo schermo poteva addormentarsi anche a processo vivo**: l'app non
  impediva mai la sospensione di Fire TV per inattività del telecomando —
  un rischio distinto dal crash, che poteva presentarsi anche senza alcun
  kill di sistema. Aggiunto il flag che tiene lo schermo sempre acceso
  mentre l'app è in vetrina.

## [1.12.2] — 2026-08-18

### Corretto

- **Caricamento di file pesanti ancora bloccato oltre ~16 MB**: il
  `.user.ini` della 1.12.1 non bastava su questo hosting (LiteSpeed) —
  il pannello impone un tetto che solo `php_value` in `.htaccess`
  riesce a scavalcare (verificato direttamente sul sito). Aggiunto
  quel blocco, innocuo sugli hosting che non girano su LiteSpeed
  (`<IfModule Litespeed>` lo salta del tutto quando manca il modulo).
  Il `.user.ini` resta comunque, per gli hosting dove basta da solo.

### Aggiunto

- **Barra di caricamento con percentuale reale**: la dropzone della
  galleria mostrava solo "Caricamento…" per l'intera durata, senza modo
  di distinguere un file grande ancora in corso da uno bloccato. Ora una
  barra di avanzamento segue byte per byte l'invio di ciascun file; a
  trasferimento concluso, mentre il server elabora il file (checksum,
  dimensioni immagine), lo dice esplicitamente invece di lasciare la
  barra ferma senza spiegazioni.

## [1.12.1] — 2026-08-18

### Corretto

- **Anteprima: rotazione mostrata sbagliata sugli schermi verticali**.
  Il calcolo della rotazione (vedi 1.11.0) è pensato per un buffer di
  riproduzione sempre orizzontale, compensato poi dal montaggio
  fisico del pannello reale — un passaggio che l'anteprima nel browser,
  non avendo un pannello da ruotare davvero, deve "disfare" per mostrare
  la forma finale che vede davvero chi guarda. Non lo faceva: applicava
  la rotazione per intero dentro una cornice già disegnata verticale,
  mostrando il contenuto piccolo e con bordi neri sproporzionati invece
  di riempire la cornice come fa davvero la TV. Il valore inviato alla TV
  non è cambiato: è solo l'anteprima del CMS a calcolare ora la parte di
  rotazione che le serve davvero.
- **Caricamento di file oltre ~16 MB non riusciva**: i limiti PHP
  dell'hosting (`upload_max_filesize`/`post_max_size`) erano più stretti
  del tetto configurato nell'app (200 MB di default). Aggiunto un
  `.user.ini` nella cartella del sistema per allinearli: su hosting
  LiteSpeed/LSAPI o PHP-FPM viene letto entro pochi minuti dal
  caricamento (fino a 5 minuti, per come funziona la cache di PHP per
  questo file), senza bisogno di accesso al pannello di controllo.

## [1.12.0] — 2026-08-18

### Aggiunto

- **Anteprima realistica per schermo nella scheda Contenuti**: nella
  pagina di ogni galleria, l'Anteprima non mostra più uno schermo
  generico ma un selettore con gli schermi realmente configurati —
  scegliendone uno, l'anteprima calcola e mostra la galleria esattamente
  come apparirebbe su quello schermo (rotazione automatica delle foto,
  sovraimpressione compresa), indipendentemente dal fatto che quella
  galleria sia già assegnata allo schermo scelto o meno. Se non esiste
  ancora nessuno schermo configurato, compare un avviso al posto del
  selettore e l'anteprima resta quella generica di prima.
- **Correzione manuale della rotazione anche nei singoli contenuti**:
  nella stessa scheda, scegliendo uno schermo compare in ogni card di
  contenuto un campo Rotazione (Automatico + 0/90/180/270°) — la stessa
  correzione per-schermo già disponibile nella pagina di ogni schermo,
  ma richiamabile direttamente dai contenuti, utile per un intervento
  rapido "in emergenza" senza uscire dalla pagina. Vale solo per lo
  schermo scelto: gli altri schermi che condividono la stessa galleria
  non ne risentono.

### Nota

Nessuna modifica allo schema del database: la funzionalità riusa le
tabelle `screen_media_overrides` e le colonne `media.width`/`height`
introdotte nella 1.11.0.

## [1.11.0] — 2026-08-18

### Aggiunto

- **Rotazione delle foto automatica, per schermo**: al caricamento il
  sistema legge le dimensioni naturali dell'immagine e, in riproduzione,
  la ruota da sola confrontando il suo orientamento (verticale/
  orizzontale) con quello dello schermo che la mostra — il lato lungo
  della foto finisce sul lato lungo dello schermo, e il montaggio
  fisico del pannello viene compensato insieme, nello stesso calcolo.
  Non è più un metadato del file: la stessa foto, condivisa fra più
  schermi con montaggi diversi, appare ruotata correttamente su
  ciascuno. In *Adattamento contenuti*, nella pagina di ogni schermo,
  si vede il calcolo automatico e si può correggerlo a mano per un
  singolo file — la correzione vale solo per quello schermo, gli altri
  che condividono la stessa galleria non ne risentono. Il controllo di
  rotazione nella scheda dei singoli file in galleria è stato rimosso:
  non ha più un doppio significato ambiguo con la nuova logica per
  schermo. L'adattamento (`contain`/`cover`) resta invece un'impostazione
  del file, invariato.
  Vale solo per le foto: i video restano a rotazione manuale come prima
  (servirebbe leggere le loro dimensioni con `ffprobe`, non garantito su
  ogni hosting condiviso).

### Aggiornamento

```sql
ALTER TABLE media
  ADD COLUMN width INT UNSIGNED NULL AFTER checksum,
  ADD COLUMN height INT UNSIGNED NULL AFTER width;

CREATE TABLE screen_media_overrides (
  screen_id INT UNSIGNED NOT NULL,
  media_id  INT UNSIGNED NOT NULL,
  rotation  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (screen_id, media_id),
  KEY idx_smo_media (media_id),
  CONSTRAINT fk_smo_screen
    FOREIGN KEY (screen_id) REFERENCES screens (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_smo_media
    FOREIGN KEY (media_id) REFERENCES media (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Le foto già caricate prima di questo aggiornamento non hanno ancora
`width`/`height`: si calcolano da sole, una alla volta, al primo utilizzo
(nessuna migrazione da lanciare a mano).

## [1.10.0] — 2026-08-11

### Corretto

- **Orientamento e rotazione dello schermo potevano disallinearsi**:
  `orientation` era una scelta indipendente da `rotation`, quindi uno
  schermo poteva restare "orizzontale" anche dopo averlo segnato come
  ruotato di 90°/270°. L'anteprima nel CMS, basata sull'orientamento
  per decidere la forma del riquadro, mostrava allora i contenuti
  ruotati tagliati ai bordi invece che nel formato verticale corretto.
  Ora l'orientamento si calcola sempre da `rotation` (90°/270° →
  verticale, altrimenti orizzontale) e non è più scelto a parte, né alla
  creazione né dalle impostazioni dello schermo.
- **La sovraimpressione non compariva nell'anteprima del CMS**: il
  simulatore "Anteprima" (schermo e galleria) riproduceva posizione e
  rotazione dei contenuti ma ignorava completamente logo/data/ora — ora
  li mostra nella stessa posizione e con la stessa rotazione che
  applicherà davvero il dispositivo, stessa matematica del banner
  sull'app TV.
- **Le miniature delle gallerie non riflettevano la rotazione impostata
  per i file a 90°/270°**: il ritaglio avveniva nel riquadro orizzontale
  della card e POI veniva ruotato, invece di essere ritagliato già
  nell'inquadratura corretta — il risultato sembrava semplicemente non
  ruotato. Ora il ritaglio avviene nelle dimensioni scambiate, coerenti
  con come apparirà davvero il file una volta ruotato.

### Aggiornamento

```sql
UPDATE screens SET orientation = IF(rotation IN (90,270), 'portrait', 'landscape');
```

## [1.9.0] — 2026-08-11

### Aggiunto

- **Verifica in due passaggi (2FA)**: ogni utente può attivare dal proprio
  profilo un secondo passaggio al login con un'app authenticator (Google
  Authenticator, Aegis, 1Password, ecc.), tramite QR o codice manuale.
  L'amministratore può disattivarla per un altro utente da Utenti → scheda
  utente; chi ha perso l'accesso al proprio autenticatore può disattivarla
  da solo con un link a uso singolo inviato alla propria email.
- **Invio email configurabile**: nuova pagina Sistema → Email. Di norma usa
  mail() di PHP (funziona da sola su molto hosting condiviso); in
  alternativa si può configurare un server SMTP esterno, con un pulsante
  per inviarsi un'email di prova.

### Aggiornamento

```sql
ALTER TABLE `users`
  ADD COLUMN `totp_secret` VARCHAR(64) NULL AFTER `deleted_by`,
  ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`,
  ADD COLUMN `totp_reset_token` CHAR(64) NULL AFTER `totp_enabled`,
  ADD COLUMN `totp_reset_expires_at` DATETIME NULL AFTER `totp_reset_token`;
```

## [1.8.0] — 2026-08-11

### Aggiunto

- **Sovraimpressione consapevole del montaggio fisico dello schermo**: ogni
  schermo ha ora una propria rotazione (0°/90°/180°/270°, impostabile dalla
  sua pagina nel CMS) che indica di quanto è girato il pannello rispetto al
  segnale grezzo. Prima la posizione scelta per il banner («in alto a
  sinistra», ecc.) e l'orientamento di data/ora/logo restavano quelli del
  buffer non ruotato: su uno schermo montato in verticale, il riquadro
  finiva sul lato sbagliato e la scritta appariva di traverso. Ora area e
  contenuto del banner si adattano schermo per schermo, coerenti con come
  lo vede davvero chi guarda il pannello montato.

## [1.7.0] — 2026-08-11

Collaudo su Fire TV Stick reale (Fire OS 5, Android 5.1.1 — la generazione
più vecchia supportata): tre correzioni emerse solo su hardware fisico,
non riproducibili in emulatore.

### Aggiunto

- **La vetrina resta sempre in vista**: il contenuto in riproduzione ora
  vive in una finestra di sistema sopra a qualunque altra cosa — notifiche,
  overlay di altre app e gli avvisi propri di Fire OS come «telecomando non
  rilevato», che su alcuni Fire Stick compaiono a schermo intero da soli
  dopo l'accensione. Restano lì, semplicemente non si vedono più. Il
  telecomando continua a funzionare esattamente come prima (MENU riapre le
  impostazioni): quella finestra non intercetta input, solo la vista.
  Richiede il permesso «Visualizza sopra altre app» — sui Fire Stick più
  vecchi (fino ad Android 5) è già concesso da soli, sui più recenti è lo
  stesso permesso della sezione *Avvio automatico*, non uno in più da
  chiedere.

### Corretto

- **Crash all'avvio su Fire OS 5** (schermo nero, l'app si chiudeva subito
  dopo l'apertura): il framework di telemetria proprietario di Amazon
  intercetta la lettura delle preferenze dell'app e, chiamato troppo presto
  nel ciclo di vita, lancia un'eccezione per un proprio bug interno. La
  lettura della lingua salvata — necessaria prima di mostrare qualunque
  schermata — ora è isolata: se fallisce, si parte con la lingua del
  dispositivo, esattamente come quando la preferenza non è ancora
  impostata. Nessun altro dispositivo ne risente.
- L'esito di un tentativo di pairing fallito (server non raggiungibile,
  errore di rete) restava silenzioso; ora lascia una traccia in log,
  utile per capire a distanza perché un'associazione non riesce.
- L'avvio automatico dopo il riavvio segnalava un fallimento silenzioso
  senza lasciare traccia; ora un log conferma se il segnale di sistema è
  arrivato e se il riavvio dell'app è stato inviato con successo.

### Aggiornamento

⚠️ **Gli schermi già associati vanno riassociati.** I token di
autenticazione ora vivono nel database solo come impronta: quelli salvati
in chiaro dalle versioni precedenti non sono più riconosciuti. Dopo
l'aggiornamento ogni televisore mostrerà di nuovo la schermata del PIN;
bastano i soliti pochi secondi per schermo. Nessuna modifica alle tabelle.

### Sicurezza

- **I token dei dispositivi non stanno più in chiaro nel database**: al
  loro posto c'è l'impronta SHA-256, quindi un backup del database o una
  copia di phpMyAdmin non bastano più a impersonare uno schermo. Il token
  vero e proprio nasce all'avvio del pairing, viaggia verso il televisore
  una sola volta e resta inerte finché l'amministratore non conferma:
  dopo, dal server non può più uscire perché il server stesso non lo
  conosce. Le colonne restano CHAR(64), il flusso di associazione (PIN,
  QR e URL manuale) è identico per chi lo usa. La URL di configurazione
  manuale resta invece rileggibile dal pannello di proposito: è monouso,
  vive solo su schermi in attesa e si azzera al primo utilizzo.

### Corretto

- **Le dirette su galleria ora scorrono allineate fra gli schermi.** La
  posizione nel ciclo non è più «l'elemento dopo quello corrente» ma
  quella calcolata a ogni cambio di file dall'orologio della diretta:
  i tempi di decodifica non si sommano più (prima costavano ~5 secondi
  di deriva al minuto, con un riallineamento visibile a scatto ogni
  giro). Un televisore acceso a diretta già in corso entra direttamente
  a metà ciclo, nello stesso punto degli altri; il riallineamento di
  sicurezza resta come rete, ma non ha più lavoro ordinario da fare.
- **Su uno schermo senza gallerie assegnate, una diretta si congelava a
  ogni polling.** Il ciclo di sincronizzazione, trovando la playlist
  ordinaria vuota, fermava il player — anche se in quel momento il player
  stava riproducendo una diretta, che restava congelata fino al
  riallineamento successivo. Era la causa principale dei micro-scatti
  periodici nelle dirette su galleria.
- A diretta conclusa il registro annotava «Diretta terminata» a ogni
  battito invece che una volta sola; e se non c'è alcuna playlist da
  riprendere ora compare «Nessun contenuto» invece di uno schermo nero
  senza spiegazioni
- Su Android 5–9 (Fire OS 5–7 inclusi) la lettura della durata dei video
  per le dirette su galleria mandava in crash l'app: l'API usata per
  chiudere il lettore di metadati esiste solo da Android 10. Ora la
  risorsa si rilascia esplicitamente, su ogni versione.

### Modificato

- Pulizia interna: rimossi una funzione mai chiamata su ciascun lato
  (server e app), 23 chiavi di traduzione orfane per lingua, tre regole
  CSS senza più alcun elemento e una risorsa stringa inutilizzata; i due
  endpoint che servono i loghi ora passano dallo stesso streaming a
  blocchi dei media (prima reimplementavano l'invio a mano, senza la
  protezione contro i client lenti)

## [1.5.0] — 2026-08-06

### Aggiornamento

Questa versione aggiunge tre colonne. Esegui in phpMyAdmin, sul database del
CMS, prima di aprire il pannello:

```sql
ALTER TABLE `live_overrides` MODIFY `source_type` ENUM('media','external','gallery') NOT NULL;
ALTER TABLE `live_overrides` ADD COLUMN `gallery_id` INT UNSIGNED NULL AFTER `media_id`;
ALTER TABLE `live_override_screens` ADD COLUMN `fit` ENUM('contain','cover') NULL AFTER `screen_id`;
ALTER TABLE `live_override_screens` ADD COLUMN `rotation` SMALLINT UNSIGNED NULL AFTER `fit`;
CREATE TABLE `login_attempts` (
  `attempt_key` CHAR(40)     NOT NULL,
  `attempts`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`attempt_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Aggiunto

- **Programmazione settimanale**: ogni fascia oraria ha ora i **giorni** in
  cui vale, scelti con le pastiglie Lun…Dom. Nessuna migrazione necessaria —
  i giorni entrano nel campo già esistente e una fascia senza giorni continua
  a valere per tutti, quindi gli schermi già configurati restano invariati.
- **Sezione «Programmazione» dedicata**, con la panoramica di tutti gli
  schermi: stato in questo momento, riassunto delle fasce, striscia della
  settimana, ore complessive di accensione e comportamento fuori fascia.
- **Anteprima della settimana** nell'editor: una griglia 7×24 che si aggiorna
  mentre modifichi, con il totale delle ore. Si vede il risultato senza
  doverlo salvare per scoprirlo.
- **Modelli rapidi**: negozio, negozio con pausa pranzo, ufficio, solo
  weekend, vetrina, sempre acceso.
- **Copia della programmazione su altri schermi**: con più vetrine dagli
  stessi orari si configura una volta sola.
- L'app registra i passaggi dentro e fuori fascia con giorno e ora: è la
  prima cosa da guardare quando uno schermo «non si accende» a un certo orario.

- **Galleria di servizio già pronta**: esiste dall'installazione invece di
  nascere al primo uso, e arriva con gli sfondi standard per le sospensioni
  (nero e bianco a piena risoluzione). Prima bisognava fabbricarsi un PNG
  nero per il primo schermo da sospendere. Resta non eliminabile né
  rinominabile; i singoli file sì, e un comando li rimette a posto senza
  toccare quelli aggiunti da te.
- **Galleria intera come sorgente di una diretta**: gli schermi riproducono
  l'intera galleria in loop, con le transizioni e le durate di ogni file,
  finché non la si interrompe. Sulla TV riusa il player della playlist: non
  serviva un secondo percorso di riproduzione, serviva un'altra lista.
- **Adattamento e rotazione per singolo schermo**: la stessa diretta può
  ritagliare e ruotare in modo diverso su ogni pannello. Un unico valore per
  tutti costringeva ad avviare due dirette separate per una vetrina
  orizzontale e una verticale. I valori della diretta restano i predefiniti,
  e gli schermi che se ne scostano sono marcati nell'anteprima.
- **Anteprima nelle dirette**: scelto il contenuto e gli schermi, si vede
  come apparirà prima di mandarlo in onda. La cornice prende il rapporto del
  pannello davvero selezionato e, con schermi di orientamento diverso, se ne
  mostra una per formato: la stessa immagine riempie un pannello orizzontale
  ma su uno verticale resta con le bande nere, e prima lo si scopriva solo a
  diretta avviata. Adattamento e rotazione usano la stessa matematica del
  player, quindi ciò che si vede è ciò che andrà sulla TV. Con una galleria
  l'anteprima ne fa scorrere i file, ognuno con le proprie impostazioni.

### Modificato

- **Riassunto visivo della programmazione**: sette pastiglie con le iniziali
  dei giorni — due lettere, perché una sola non distingue martedì da
  mercoledì — con quelle attive evidenziate e l'orario accanto. Sostituisce
  il testo «Lun–Ven 09:00–20:00 · Sab 10:00–13:00», che andava letto.
  Usato nell'elenco schermi, nella panoramica e nella scheda dello schermo.
- **Azioni rapide nell'elenco schermi**: una colonna *Programmazione* e tre
  icone per riga — orologio verso la programmazione di quello schermo,
  riquadro verso la sua sovraimpressione, freccia verso la scheda.
- Una fascia che scavalca la mezzanotte **comincia nel giorno selezionato** e
  finisce il mattino successivo: scegliendo il sabato, l'accensione copre il
  sabato sera e le prime ore di domenica. L'editor lo segnala.
- La programmazione non sta più nella scheda dello schermo, che ora ne mostra
  il riassunto e rimanda alla sezione dedicata

- **Suggerimenti che funzionano anche al tocco**: i marcatori sintetici
  (fasce aggiuntive, scavalco della mezzanotte, striscia della settimana)
  spiegano cosa indicano al passaggio del mouse, al tocco e da tastiera.
  L'attributo `title` non sarebbe bastato: su un telefono non compare mai.
- **Tabelle a schede su schermo stretto**: sotto i 760 px le righe diventano
  schede con ogni valore preceduto dalla sua intestazione, letta dal `thead`
  invece di ripeterla in ogni pagina. Niente più scorrimento orizzontale.
- **Colonne disciplinate**: i dati stanno su una riga sola e solo la colonna
  che identifica la riga cresce, con troncamento e testo completo al
  passaggio del mouse. Data e ora incolonnate invece che spezzate.

### Sicurezza

- **Blocco anti forza bruta sull'accesso**: cinque tentativi falliti sulla
  coppia utente+indirizzo bloccano l'accesso per dieci minuti, anche con la
  password giusta. Il messaggio «Troppi tentativi» esisteva già nel catalogo
  delle traduzioni, ma non c'era alcun codice dietro: l'unica difesa era un
  ritardo casuale, che richieste parallele aggirano.
- Il cookie di sessione dell'installazione guidata ha ora gli stessi flag
  di quello del pannello (httponly, samesite, secure su HTTPS)

### Corretto

- Con una diretta su galleria, `state.php` — interrogato ogni dieci
  secondi — trasportava l'intero elenco dei file a ogni battito; ora manda
  solo un'impronta e l'app scarica l'elenco dalla playlist quando cambia.
  Questo corregge anche un buco funzionale: **una galleria modificata a
  diretta in corso ora arriva agli schermi**, prima veniva ignorata fino
  all'interruzione.
- Una risposta non-JSON del server (pagina d'errore, proxy dell'hosting)
  faceva fallire i salvataggi del pannello con un errore indecifrabile
- L'attributo `hidden` non nascondeva gli elementi con un `display` esplicito
  (griglie, flex): la regola del browser perde per specificità contro
  qualsiasi regola d'autore
- La sezione *Account* non si apriva: la pagina del profilo non dichiarava
  la propria voce attiva, quindi il sottomenu restava chiuso
- Nove griglie con una larghezza minima fissa sbordavano sotto quella
  soglia: ora cedono fino allo spazio disponibile
- La barra di navigazione orizzontale su mobile allargava la pagina invece
  di scorrere al proprio interno (mancava `min-width: 0`)
- La barra dei filtri degli utenti sommava larghezze minime oltre lo schermo
- Il nome utente nell'intestazione mobile si sovrapponeva a «Esci»

## [1.3.0] — 2026-08-05

### Aggiornamento

Questa versione aggiunge una colonna. Esegui in phpMyAdmin, sul database del
CMS, prima di aprire il pannello:

```sql
ALTER TABLE `users` ADD COLUMN `language` CHAR(5) NULL AFTER `capabilities_json`;
```

### Aggiunto

- **Identità visiva**: il logo del progetto diventa l'icona della scheda del
  browser (favicon in tre risoluzioni, ognuna disegnata alla sua dimensione
  nativa), l'icona dell'app in tutte le densità e il banner del launcher
  Android TV. Un logo caricato in *Personalizzazione* prende il posto anche
  della favicon: su un'installazione white label mostrare il marchio del
  prodotto sarebbe fuori luogo.
  Le varianti si rigenerano dal solo file sorgente con `tools/genera-icone.py`.
- **Installazione guidata** (`install.php`), pensata per un hosting condiviso
  dove l'unico strumento è il browser: controlla i requisiti dell'ambiente,
  prova la connessione al database, **crea le tabelle da sé** (niente più
  importazione manuale da phpMyAdmin), deduce l'indirizzo pubblico dalla
  pagina in corso e scrive `core/config.php`. Se la cartella non è
  scrivibile mostra il file da caricare via FTP invece di bloccarsi. Si
  chiude a chiave da sola appena esiste un amministratore. È tradotta come
  il resto del sistema, fin dalla prima schermata.
- **Interfaccia multilingua**, italiano e inglese, sia nel pannello sia sui
  televisori. Aggiungere una lingua richiede solo un file in `core/lang/` più
  una riga nell'elenco: le chiavi mancanti ricadono sull'italiano, quindi una
  traduzione incompleta non lascia mai etichette vuote.
- Selettore di lingua nella barra laterale e sulle pagine di accesso, con
  bandiere disegnate in SVG (le emoji delle bandiere non hanno glifo su
  Windows) e nome della lingua nella lingua stessa
- Ogni utente può scegliere la propria lingua dal profilo, oppure seguire
  quella predefinita del sistema
- **Lingua di sistema** in *Sistema → Personalizzazione*: vale come
  predefinita del pannello e come lingua mostrata sui televisori, che la
  applicano entro pochi secondi senza riassociarli
- Catalogo inglese dell'app TV (`values-en/`); la lingua degli schermi la
  decide il CMS, non il locale del box — che su questi dispositivi è quasi
  sempre inglese anche quando lo schermo sta in un negozio italiano

### Modificato

- Ruoli, permessi, transizioni, funzionalità, controlli di sistema, posizioni
  e formati del banner non sono più testo fisso nel codice: le costanti
  contengono chiavi di traduzione e le etichette si risolvono al momento di
  stamparle
- Il riquadro di associazione con PIN è centrato su entrambi gli assi

### Corretto

- Le capacità del server erano in cache per 24 ore **con il testo già
  tradotto**: cambiando lingua sarebbero rimaste nella precedente fino al
  giorno dopo. Ora la cache conserva solo dati e chiavi.
- Il cambio lingua scriveva nella sessione prima che questa fosse avviata,
  quindi la scelta andava persa a ogni click
- Il pannello non dichiarava alcuna icona: ogni pagina produceva una
  richiesta fallita per `favicon.ico`

## [1.2.0] — 2026-08-05

### Aggiornamento

Questa versione aggiunge una colonna. Esegui in phpMyAdmin, sul database del
CMS, prima di aprire il pannello:

```sql
ALTER TABLE `screens` ADD COLUMN `overlay_json` TEXT NULL AFTER `idle_media_id`;
```

### Aggiunto

- **Sovraimpressione per singolo schermo**: ogni schermo segue le impostazioni
  generali finché non gliene si danno di proprie — posizione, contenuto,
  formato, dimensione, opacità e persino un logo diverso. Scollegare uno
  schermo parte dai valori che sta già applicando, quindi non ne cambia
  l'aspetto finché non si modifica qualcosa; si torna all'eredità quando si
  vuole, per uno schermo o per tutti.
- Elenco degli schermi nella pagina della sovraimpressione, con l'origine
  delle impostazioni e lo stato del banner di ciascuno
- Collegamento alla sovraimpressione dalla scheda di ogni schermo

### Modificato

- **Le modifiche arrivano agli schermi entro pochi secondi** invece che al
  ciclo di aggiornamento successivo (fino a un minuto): il controllo di stato,
  già interrogato ogni dieci secondi, riconosce che qualcosa è cambiato e
  richiede subito la playlist aggiornata.
- `playlist.php` e `state.php` pubblicano ora la stessa identica impronta di
  versione: prima erano calcolate con due formule diverse e non erano
  confrontabili fra loro.

## [1.1.0] — 2026-08-05

### Aggiunto

- **Diretta in sovrapposizione**: un livello temporaneo sopra la riproduzione
  normale, avviabile su più schermi contemporaneamente da un file delle
  gallerie o da una sorgente esterna (HLS, DASH, RTSP). Gallerie, fasce orarie
  e impostazioni degli schermi non vengono toccate: alla fine tutto riprende da
  solo. Gli schermi partono allineati sullo stesso istante del contenuto.
- Durata facoltativa con chiusura automatica, adattamento e rotazione propri
  della diretta, riproduzione muta e ripetizione in loop
- Spia di stato accanto alla voce *Dirette* del menu: grigia quando non c'è
  nulla in onda, verde durante una diretta, ambra per una diretta appena
  conclusa, rossa quando gli schermi non stanno ricevendo. Si aggiorna da sola
  senza ricaricare la pagina.
- Endpoint `state.php`, interrogato di frequente e molto leggero, che consente
  agli schermi di reagire in pochi secondi senza riscaricare la playlist
- **Banner in sovraimpressione**, disattivato di default: logo e/o data e ora
  sopra il contenuto, in uno di nove ancoraggi, con dimensione e opacità
  regolabili e data in formato contratto (24/06/2026) o esteso
  (24 Giugno 2026). La data e l'ora si compongono sulla TV con l'orologio del
  dispositivo, quindi restano corrette anche a rete assente.
- Supporto effettivo ai formati delle dirette esterne: senza i moduli HLS,
  DASH e RTSP un indirizzo `.m3u8`, `.mpd` o `rtsp://` non era riproducibile
- Buffer dedicato ai flussi di rete (20-60 s, tetto di 64 MB, avvio dopo 4 s):
  con la taratura di serie, pensata per il mobile, una sorgente ad alto
  bitrate su un pannello in vetrina scattava al primo calo di banda

### Modificato

- La versione della playlist tiene ora conto anche delle fasce orarie e
  dell'immagine di cortesia: prima una modifica a quei campi non raggiungeva
  gli schermi fino al riavvio
- Interruttori del pannello uniformati: larghezza piena, dimensione stabile al
  cambio di stato

### Corretto

- I contenuti delle dirette restavano in cache sul dispositivo senza limite:
  ora scadono dopo una settimana dall'ultimo uso
- Un download fallito del contenuto di una diretta lasciava lo schermo fermo
  sulla schermata di sincronizzazione senza mai riprovare
- La pulizia della cache poteva cancellare il file parziale di un download in
  corso, facendolo ripartire da capo

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
