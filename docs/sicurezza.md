# Sicurezza

Scelte di protezione adottate e cosa resta a carico di chi installa il sistema.

## Modello di autorizzazione

Il sistema ha due mondi separati, con credenziali diverse:

| Chi | Come si autentica | Dove |
|---|---|---|
| Amministratori e operatori | sessione PHP con cookie HttpOnly | CMS |
| Televisori | token opaco da 256 bit nell'header `Authorization` | API |

Un token compromesso non dà accesso al CMS, e viceversa.

### Perché un token opaco e non un JWT

Un token casuale conservato nel database è **revocabile all'istante** dal
pannello: basta cambiare lo stato dello schermo. Un JWT resterebbe valido fino
alla scadenza anche dopo la revoca, e richiederebbe una gestione di chiavi di
firma. Per un parco dispositivi proprio, il token opaco è più semplice e più
sicuro.

## Associazione dei dispositivi

Il PIN mostrato sulla TV è visibile a chiunque passi davanti alla vetrina, ma
**non basta per rubare l'accesso**:

- per ritirare il token serve anche il `poll_token`, un segreto da 256 bit
  consegnato solo al dispositivo che ha richiesto l'associazione;
- per confermare il PIN serve comunque un accesso autenticato al CMS;
- il codice scade in 5 minuti e viene invalidato al primo uso;
- conferme simultanee dello stesso codice sono gestite in modo che solo la
  prima vada a buon fine;
- anche il ritiro del token ha una finestra temporale limitata.

La URL di configurazione manuale contiene una credenziale monouso: viene
invalidata appena un dispositivo la utilizza.

## Protezioni del CMS

- **Password** con `password_hash()` (bcrypt/Argon2 secondo la versione di PHP)
- **CSRF** su tutte le operazioni di scrittura, con token rigenerato al login
  per non trasportare nella sessione autenticata un valore nato prima
- **Sessioni** con cookie `HttpOnly`, `SameSite=Lax`, `Secure` su HTTPS, e
  rigenerazione dell'identificativo all'accesso
- **Ritardo casuale** sui tentativi di accesso falliti
- **Anti-clickjacking**: `X-Frame-Options: DENY` e `frame-ancestors 'none'`,
  perché una pagina esca potrebbe altrimenti far confermare l'associazione di
  un dispositivo ostile con un clic
- **Errori generici** verso il browser: gli stack trace resterebbero pieni di
  credenziali del database, quindi finiscono solo nel log del server

## Caricamento dei file

- Il tipo del file è verificato **dal contenuto reale** (`finfo`), mai
  dall'estensione o dall'intestazione inviata dal browser
- Il nome sul disco è generato in modo casuale: nessun nome scelto dall'utente
  arriva al filesystem
- La cartella `uploads/` è chiusa al web da `.htaccess`: i file passano solo
  dagli endpoint che verificano l'autorizzazione
- Ogni schermo può scaricare esclusivamente i media assegnati alle proprie
  gallerie

## Interrogazioni al database

Tutte le query usano prepared statement con parametri, senza concatenazione di
stringhe, ed `EMULATE_PREPARES` è disattivato per avere prepared statement
nativi reali.

## Permessi

Ogni azione sensibile verifica il permesso sia nella pagina sia nell'endpoint
che la esegue: nascondere un pulsante nell'interfaccia non è considerato una
protezione. Il sistema impedisce inoltre di restare senza amministratori attivi
e di disattivare il proprio stesso accesso.

## Cosa resta a carico di chi installa

**HTTPS.** È l'unico elemento essenziale che non può essere garantito dal
codice. Senza certificato, il token dei dispositivi viaggia in chiaro e chi si
trova sulla stessa rete della TV può intercettarlo. Praticamente tutti gli
hosting offrono Let's Encrypt gratuito.

**Password solide** per gli account del CMS: il sistema impone almeno 10
caratteri, ma la scelta resta tua.

**Accesso all'hosting**: chi può leggere i file del server può leggere
`config.php`, quindi le credenziali del database. Vale la pena limitare gli
accessi FTP e i backup pubblici.

**Aggiornamenti di PHP**: mantieni una versione supportata.

## Segnalazione di problemi

Se individui una vulnerabilità, segnalala in privato al responsabile del
repository invece di aprire una issue pubblica.
