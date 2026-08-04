# Aggiornamento

Come portare server e dispositivi a una nuova versione senza perdere dati.

## Prima di iniziare

Fai sempre due copie:

1. **Database** — esportalo da phpMyAdmin (*Esporta → Esecuzione rapida → SQL*)
2. **Cartella `uploads/`** — scaricala via FTP: contiene tutti i media caricati

Sono le uniche due cose non ricostruibili. Il resto del codice si riscarica dal
repository.

## Aggiornare il server

1. Carica via FTP i file aggiornati **sovrascrivendo** quelli esistenti.
2. **Non toccare** `core/config.php` e la cartella `uploads/`: contengono la tua
   configurazione e i tuoi file.
3. Se la nuova versione modifica il database, applica le istruzioni SQL indicate
   nel [CHANGELOG](../CHANGELOG.md) dalla sezione *Aggiornamento* della versione
   corrispondente.
4. Apri il CMS e verifica in *Sistema → Manutenzione* che la versione mostrata
   sia quella nuova.

Il file `config.php` non viene mai incluso nel repository proprio per evitare di
sovrascriverlo per sbaglio.

## Aggiornare l'app TV

1. Ricompila l'APK dalla nuova versione del progetto (vedi
   [app-tv.md](app-tv.md)), ricordando di impostare `DEFAULT_BASE_URL`.
2. Installa sopra la versione esistente:

   ```
   adb connect INDIRIZZO_IP
   adb install -r app-debug.apk
   ```

   L'opzione `-r` sostituisce l'app conservando dati e associazione: la TV
   riprende a funzionare senza doverla riassociare.

> L'aggiornamento sul posto funziona solo se la nuova APK è firmata con la
> **stessa chiave** della precedente. Passando da debug a release (o cambiando
> chiave) va disinstallata prima l'app, e lo schermo va riassociato.

### Se l'associazione va persa

Non serve rifare tutto: nel CMS lo schermo esiste ancora. Associalo di nuovo
con PIN o QR e riassegnagli le gallerie; la configurazione (nome, pollici,
orientamento, fasce orarie) è rimasta.

## Ordine consigliato

Aggiorna prima il **server**, poi le app. Le API mantengono la compatibilità
con i client più vecchi: un dispositivo non ancora aggiornato continua a
funzionare, semplicemente senza le funzionalità nuove.

## Numerazione delle versioni

La versione del server è in `server/core/version.php`, quella dell'app in
`app-androidtv/app/build.gradle.kts`: vanno tenute allineate. La numerazione
segue il criterio `MAJOR.MINOR.PATCH`, dove un cambio di `MAJOR` segnala
modifiche che richiedono interventi manuali durante l'aggiornamento.

## Ripristino da un backup

1. Ricarica i file del codice
2. Ripristina `core/config.php` con le tue credenziali
3. Importa il dump SQL in un database vuoto
4. Ricarica la cartella `uploads/`
5. Accedi al CMS e verifica gli schermi: se i token sono rimasti nel dump, le
   TV riprendono a funzionare da sole
