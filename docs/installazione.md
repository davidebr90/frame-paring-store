# Installazione

Guida al primo avvio su un hosting condiviso. Non serve accesso SSH: tutto si
fa via FTP e dal pannello di controllo dell'hosting.

## 1. Verifica dei requisiti

Il tuo hosting deve avere:

- **PHP 8.0 o superiore** con le estensioni `pdo_mysql` e `fileinfo`
  (praticamente sempre presenti)
- **MySQL 5.7+** o **MariaDB 10.3+**
- Possibilità di scrivere nelle cartelle (per i file caricati)

Se non sai quale versione di PHP hai, quasi tutti i pannelli (cPanel, Plesk,
Aruba, Register) lo mostrano nella sezione "PHP" o "Impostazioni PHP", dove di
solito puoi anche cambiarla.

## 2. Creazione del database

Dal pannello dell'hosting crea un database MySQL e un utente con permessi
completi su quel database. Annota:

- nome del database
- nome utente
- password
- host (di solito `localhost`, ma alcuni hosting usano un indirizzo dedicato)

**Non serve importare nulla a mano**: le tabelle le crea l'installazione
guidata al passo successivo.

## 3. Caricamento dei file

Carica via FTP **il contenuto della cartella `server/`** nella cartella pubblica
del tuo hosting (`public_html`, `htdocs` o `www` a seconda del provider).

Puoi metterlo nella radice del dominio oppure in una sottocartella:

| Scelta | Risultato |
|---|---|
| Radice del dominio | `https://tuodominio.it/cms/` |
| Sottocartella `signage/` | `https://tuodominio.it/signage/cms/` |

Carica anche **`database/schema.sql`**, ma non nella sua sottocartella: va
messo direttamente accanto a `install.php`, rinominandolo se serve solo per
chiarezza (il nome del file resta `schema.sql`). L'installazione guidata lo
cerca lì quando la cartella `database/` del repository non è stata caricata
(caso comune: si carica solo il contenuto di `server/`, non l'intero
repository) — senza, il passo 2 si ferma con «File database/schema.sql non
trovato».

Struttura attesa sul server:

```
public_html/
├── .htaccess
├── install.php      ← procedura guidata, da eliminare al termine
├── schema.sql        ← database/schema.sql del repository, caricato qui
├── api/v1/
├── cms/
├── core/
└── uploads/
```

## 4. Installazione guidata

Apri nel browser `https://tuodominio.it/install.php` (o
`https://tuodominio.it/signage/install.php` se hai usato una sottocartella).

La procedura è in italiano e in inglese — la lingua si sceglie in alto a
destra — e si compone di quattro passi:

1. **Requisiti** — un controllo dell'ambiente: versione di PHP, estensioni
   `pdo_mysql` e `fileinfo`, permessi delle cartelle. Per ogni requisito non
   soddisfatto trovi scritto come sistemarlo dal pannello dell'hosting.
2. **Database** — inserisci i dati annotati al passo 2. La connessione viene
   provata subito e, se funziona, le tabelle vengono create automaticamente.
   Se il database contiene già dati di questo sistema, la procedura si ferma
   e chiede conferma prima di proseguire: non sovrascrive nulla per sbaglio.
3. **Impostazioni** — l'indirizzo pubblico viene dedotto dalla pagina che stai
   guardando ed è quasi sempre già corretto. Gli altri valori (validità del
   PIN, intervalli di aggiornamento, dimensione massima dei file) hanno valori
   sensati e si possono lasciare così.
4. **Fine** — la configurazione è salvata in `core/config.php`. Si passa alla
   creazione dell'amministratore.

> Se la cartella `core/` non è scrivibile, la procedura non si blocca: ti
> mostra il contenuto di `config.php` da copiare e caricare via FTP. È
> l'unico passaggio manuale, e solo su hosting particolarmente chiusi.

Al termine **elimina `install.php` dal server via FTP**. Una volta creato
l'amministratore la procedura si chiude da sola — riaprendola risponde
«Installazione già completata» — ma è buona norma non lasciarla in giro.

### Configurazione a mano (alternativa)

Se preferisci non usare la procedura guidata, copia `core/config.sample.php`
in `core/config.php`, compila i valori e importa
[`database/schema.sql`](../database/schema.sql) da phpMyAdmin. Il file di
configurazione ha questa forma:

```php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'nome_database',
        'user' => 'utente_database',
        'pass' => 'password_database',
    ],

    // URL pubblico SENZA slash finale: è l'indirizzo che le TV useranno
    // per scaricare i media, quindi deve essere raggiungibile da Internet
    'base_url' => 'https://tuodominio.it/signage',

    'pairing_ttl' => 300,        // validità del PIN, in secondi
    'poll_interval' => 60,       // ogni quanto le TV controllano gli aggiornamenti
    'state_interval' => 10,      // controllo di stato, molto più frequente
    'max_upload_bytes' => 209715200,  // 200 MB per file
];
```

> **Attenzione a `base_url`**: se è sbagliato le TV si associano ma non
> riescono a scaricare i media. Deve corrispondere esattamente all'indirizzo
> da cui raggiungi il CMS, senza `/cms` finale.

### Permessi della cartella uploads

La cartella `uploads/` deve essere scrivibile dal server web. Se l'upload dei
media fallisce con un errore di salvataggio, imposta i permessi a `755` (o `775`
su alcuni hosting) dal client FTP: tasto destro sulla cartella → *Permessi*.

## 5. Primo accesso

Al termine dell'installazione guidata scegli username e password
dell'amministratore (almeno 10 caratteri). Dopo la creazione entri
direttamente nella dashboard.

Se hai configurato a mano, apri `https://tuodominio.it/cms/`: la creazione
dell'amministratore compare da sola al primo avvio.

La lingua del pannello si cambia in qualsiasi momento dal selettore in fondo
al menu, e ogni utente può averne una propria dal proprio profilo. In
*Sistema → Personalizzazione* si imposta quella predefinita, che vale anche
per i televisori.

## 6. HTTPS (fortemente consigliato)

I dispositivi inviano il proprio token di autorizzazione in ogni richiesta:
senza HTTPS quel token viaggia in chiaro e chi è sulla stessa rete della TV
potrebbe intercettarlo.

Quasi tutti gli hosting offrono un certificato Let's Encrypt gratuito,
attivabile con un clic dal pannello. Una volta attivo:

1. aggiorna `base_url` in `config.php` con `https://`
2. se hai già associato delle TV, aprile e aggiorna l'indirizzo dal pannello
   impostazioni (tasto MENU sul telecomando)

## 7. Limiti di upload dell'hosting

`max_upload_bytes` nella configurazione non può superare i limiti di PHP
imposti dall'hosting. Se i video grandi non si caricano, verifica nel pannello
i valori di `upload_max_filesize` e `post_max_size`: molti hosting permettono
di alzarli, altri no. In quel caso comprimi i video prima di caricarli
(un video full HD ben compresso per digital signage sta in 20-50 MB al minuto).

## Risoluzione dei problemi

**"Configurazione mancante"** — non hai completato l'installazione guidata,
oppure `core/config.php` non è stato caricato via FTP dopo averlo copiato.

**Pagina bianca o errore 500** — controlla il log degli errori dell'hosting
(di solito nel pannello, sezione "Log"). Le cause più frequenti sono credenziali
del database errate o versione di PHP inferiore alla 8.0.

**Le TV si associano ma restano su "Scaricamento contenuti"** — `base_url` non
è corretto, oppure la cartella `uploads/` non è raggiungibile. Verifica che
`https://tuodominio.it/signage/api/v1/playlist.php` risponda con un errore JSON
(`{"error":"Token mancante..."}`): se dà 404 il percorso è sbagliato.

**Errore di sessione scaduta a ogni salvataggio** — l'hosting non conserva le
sessioni PHP; contatta l'assistenza chiedendo di verificare `session.save_path`.
