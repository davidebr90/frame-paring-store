# Utilizzo del CMS

Guida alle funzioni del pannello di amministrazione.

## Dashboard

Panoramica del sistema: schermi online, numero di gallerie e file, spazio
occupato. Ogni schermo è rappresentato da una card che mostra il pannello
disegnato secondo il suo orientamento reale, il metodo di associazione, le
gallerie assegnate e lo stato.

Uno schermo risulta **online** se ha contattato il server negli ultimi due
cicli di aggiornamento (con l'impostazione predefinita, negli ultimi 2-3 minuti).

## Gallerie e media

![Gestione di una galleria](assets/img/cms-galleria.png)

Una galleria è un insieme ordinato di immagini e video. Un singolo schermo può
riprodurre più gallerie in sequenza.

### Caricare i file

Trascina i file nella zona di caricamento oppure fai clic per selezionarli. Si
caricano uno alla volta con avanzamento visibile; se qualcuno fallisce, il
messaggio resta a schermo per farti vedere quale e perché.

### Ordine di riproduzione

Trascina le anteprime per riordinarle: l'ordine si salva da solo e arriva alle
TV al successivo aggiornamento.

### Impostazioni di ogni file

| Impostazione | Effetto |
|---|---|
| **Rotazione** | 0°, 90°, 180°, 270° — applicata dall'app in riproduzione, il file non viene modificato |
| **Adattamento** | *Contain*: tutto visibile con eventuali bande. *Crop*: riempie lo schermo tagliando le eccedenze |
| **Durata a schermo** | Solo immagini: quanti secondi restano visibili |
| **Audio** | Solo video: riproduzione muta (predefinito, adatto alle vetrine) o con audio |
| **Entrata / Uscita** | Transizione specifica, oppure *Default* per ereditare quella della galleria |

Le modifiche si salvano da sole: il pallino accanto al pulsante Elimina diventa
verde a conferma.

### Transizioni

Nella scheda **Riproduzione** della galleria imposti la transizione predefinita
e la sua durata in millisecondi. Ogni file può discostarsene per l'entrata,
l'uscita o entrambe.

| Transizione | Effetto |
|---|---|
| Taglio secco | Cambio istantaneo |
| Dissolvenza (dal nero) | Sfuma al nero e riappare |
| Dissolvenza incrociata | I due contenuti si sovrappongono sfumando |
| Scorri a sinistra / destra / alto / basso | Il nuovo contenuto entra dal lato indicato |
| Zoom in / Zoom out | Ingrandimento o rimpicciolimento |
| Ken Burns | Lento zoom e deriva durante tutta l'esposizione dell'immagine |
| Casuale | Ne sceglie una diversa a ogni cambio |

### Anteprima

Il riquadro **Anteprima** riproduce i media reali con le transizioni, le
rotazioni e gli adattamenti impostati, dentro una cornice che rispetta
l'orientamento dello schermo. I comandi permettono di mettere in pausa e
scorrere manualmente per esaminare ogni passaggio; sotto è indicata la
configurazione attiva del contenuto mostrato.

L'anteprima è disponibile sia nella pagina di una galleria (solo quei
contenuti) sia nella pagina di uno schermo (playlist completa).

### La galleria di servizio

Fra le gallerie ne trovi una marcata **sistema**, «Immagini di servizio»,
creata dall'installazione. Contiene gli sfondi pronti per le sospensioni —
nero e bianco a piena risoluzione — che servono come immagine di cortesia
fuori fascia oraria, come riempimento, o per oscurare uno schermo durante
una diretta.

Non si può eliminare né rinominare, perché il sistema vi si appoggia; i
singoli file sì, e in fondo alla sua pagina c'è *Ripristina i file standard*
che rimette quelli eliminati senza toccare ciò che hai aggiunto tu. Puoi
usarla come una galleria qualsiasi e caricarci altre immagini di attesa.

## Schermi

![Pagina di uno schermo](assets/img/cms-schermo.png)

### Associare un nuovo schermo

Tre modi, tutti equivalenti nel risultato:

1. **PIN** — la TV mostra un codice a 6 cifre, tu lo digiti in *Schermi →
   Associa con PIN*.
2. **QR code** — inquadri col telefono il codice mostrato sulla TV: si apre il
   CMS con il PIN già inserito, confermi da lì.
3. **URL di configurazione** — crei lo schermo in *Schermi → Aggiungi schermo*
   e incolli nell'app la URL monouso che ottieni. Utile per preparare i
   dispositivi prima di installarli.

Il PIN vale 5 minuti; se scade, l'app ne genera uno nuovo da sola.

### Impostazioni dello schermo

- **Nome** — per riconoscerlo nell'elenco
- **Dimensione e orientamento** del pannello fisico: guidano le scelte di
  rotazione e adattamento dei media
- **Gallerie assegnate** — trascinabili per definire l'ordine di riproduzione

### Accensione programmata

La programmazione ha una sezione tutta sua: **Programmazione**. Vedi
[il capitolo dedicato](#programmazione).

### Revoca ed eliminazione

**Revoca accesso** blocca l'autorizzazione della TV mantenendone la storia:
il dispositivo smette di ricevere contenuti e va riassociato.
**Elimina schermo** lo rimuove del tutto.

## Programmazione

Ogni schermo ha la propria programmazione: quando riprodurre e cosa mostrare
nelle ore in cui resta spento. La TV la valuta con il **proprio orologio**,
quindi continua a rispettarla anche senza rete.

### Panoramica

*Programmazione → Tutti gli schermi* mette in fila i televisori con, per
ciascuno: se è acceso **in questo momento**, il riassunto delle fasce, una
striscia con la settimana a colpo d'occhio, le ore complessive di accensione
e cosa mostra fuori fascia.

### Configurare uno schermo

Dal pulsante *Configura* si apre l'editor.

**Modelli rapidi** — un punto di partenza per i casi più comuni: negozio,
negozio con pausa pranzo, ufficio, solo weekend, vetrina. Applicandone uno le
fasce vengono sostituite e restano modificabili; nulla è salvato finché non
premi *Salva*.

**Fasce di accensione** — ogni fascia ha un orario di inizio, uno di fine e
**i giorni in cui vale**, scelti con le pastiglie Lun…Dom. Senza fasce lo
schermo riproduce sempre.

Una fascia che scavalca la mezzanotte — per esempio `22:00 → 06:00` — è
legittima e viene segnalata: **comincia nel giorno scelto e finisce il
mattino dopo**. Selezionando il sabato, l'accensione copre il sabato sera e
le prime ore di domenica, non la notte fra venerdì e sabato.

**Anteprima della settimana** — una griglia di sette giorni per ventiquattro
ore in cui le ore colorate sono quelle di riproduzione. Si aggiorna mentre
modifichi, quindi vedi subito il risultato senza dover salvare per scoprirlo.
Sotto trovi le ore complessive: utile per capire quanto resterà acceso il
pannello.

**Fuori fascia** — schermo nero (spento apparente) oppure un'immagine di
cortesia, caricabile direttamente da qui.

### Copiare su altri schermi

In fondo alla pagina puoi applicare la stessa programmazione — fasce e
comportamento fuori fascia — ad altri schermi selezionandoli dall'elenco:
con più vetrine con gli stessi orari si configura una volta sola.

## Utenti e permessi

![Elenco utenti](assets/img/cms-utenti.png)

Tre ruoli predefiniti:

| Ruolo | Permessi predefiniti |
|---|---|
| **Amministratore** | Tutti, non modificabili |
| **Editor** | Media, gallerie, schermi, programmazione |
| **Operatore** | Solo caricamento e modifica dei media |

I permessi del singolo utente si possono personalizzare uno per uno: caricare
media, modificarne i metadati, creare gallerie, associare schermi, gestirli,
gestire le fasce orarie, gestire gli utenti, modificare le impostazioni di
sistema. Se riporti i permessi identici a quelli del ruolo, tornano a essere
ereditati automaticamente.

La lista si può filtrare per nome, cognome, username, email, telefono, ruolo e
stato, e si visualizza a **card** o a **elenco**.

Un utente **disattivato** non può accedere ma resta nell'elenco con la sua
storia. Il sistema impedisce di rimanere senza amministratori attivi e di
disattivare sé stessi.

## Cestino

![Cestino](assets/img/cms-cestino.png)

Media, gallerie e utenti eliminati non spariscono subito: finiscono nel cestino
e restano ripristinabili. I file restano sul server finché non vengono
eliminati definitivamente.

- **Ripristina** riporta l'elemento al suo posto (una galleria ripristinata
  recupera anche i suoi file; un utente ripristinato torna visibile ma
  disattivato, per scelta consapevole)
- **Elimina per sempre** cancella riga e file, liberando spazio
- **Svuota cestino** fa la stessa cosa su tutto

La **conservazione automatica** elimina definitivamente ciò che è nel cestino
da più giorni di quelli impostati (predefinito 30; `0` disattiva la pulizia
automatica). La verifica avviene durante la normale navigazione nel CMS, quindi
non richiede attività pianificate sull'hosting.

## Personalizzazione

![Personalizzazione](assets/img/cms-personalizzazione.png)

In *Sistema → Personalizzazione* puoi cambiare nome, logo, colore principale e
testi mostrati sia nel CMS sia sulla schermata di configurazione delle TV.
Le modifiche arrivano ai dispositivi al collegamento successivo, senza
ricompilare l'app.

## Sovraimpressione

In *Sistema → Sovraimpressione* si configura un banner discreto sopra il
contenuto, con il logo e/o la data e l'ora. È **disattivato di default** e non
tocca in alcun modo la riproduzione: gallerie, transizioni e fasce orarie
restano quelle che sono, e il banner resta visibile anche durante una diretta.

Si sceglie:

- **cosa mostrare**: logo, data, ora, in qualsiasi combinazione
- **il formato della data**: contratta (`24/06/2026`) o estesa (`24 Giugno 2026`)
- **la posizione** fra nove ancoraggi, dai tre angoli superiori ai tre inferiori
  passando per la fascia centrale
- **la dimensione** (per un pannello da 55" in su conviene *Grande*) e
  l'**opacità del fondo**

L'anteprima a fianco della griglia mostra il risultato mentre lo si imposta.

Il logo del banner è indipendente da quello del pannello: qui serve
un'immagine leggibile su fondo scuro, preferibilmente un PNG con sfondo
trasparente. Non sono ammessi gli SVG, che la TV non sa disegnare.

La data e l'ora sono composte dalla TV con il proprio orologio: restano
corrette anche a rete assente e passano da sole al giorno successivo senza
attendere un collegamento.

### Impostazioni diverse per ogni schermo

Quelle descritte sopra sono le impostazioni **generali**: le segue ogni
schermo che non ne ha di proprie. In fondo alla pagina c'è l'elenco degli
schermi con, per ciascuno, l'origine delle impostazioni (*Generali* o
*Proprie*) e se il banner è acceso.

Da *Configura* si apre lo schermo: finché eredita, si vede solo il riepilogo
di ciò che sta applicando. Il pulsante **Dai a questo schermo impostazioni
proprie** lo scollega partendo dagli stessi valori — non cambia aspetto — e da
lì si può modificare tutto senza toccare gli altri: posizione, contenuto,
formato, dimensione, opacità e persino un **logo diverso**, utile quando gli
schermi stanno in punti vendita o sedi differenti.

Si torna all'eredità quando si vuole, e un solo pulsante riporta tutti gli
schermi alle impostazioni generali.

> Le modifiche arrivano agli schermi entro pochi secondi, non serve
> riavviarli né attendere il ciclo di aggiornamento completo.

## Dirette

In *Dirette → Sorgenti live* si manda un contenuto **in sovrapposizione** su
uno o più schermi contemporaneamente: un livello temporaneo che copre la
riproduzione normale senza cancellare nulla. Alla fine ogni schermo riprende
la propria programmazione da solo.

La sorgente può essere un file già caricato nelle gallerie oppure un flusso
esterno (HLS, DASH, RTSP). Per la diretta si impostano adattamento, rotazione,
audio e ripetizione in loop, indipendenti da quelli del file originale. Con
una durata impostata la diretta si chiude da sola; altrimenti resta in onda
finché non la si interrompe.

Gli schermi partono allineati sullo stesso istante del contenuto, anche se
accesi in momenti diversi: chi entra dopo si posiziona nel punto in cui si
trova la diretta.

Accanto alla voce *Dirette* del menu c'è una spia di stato:

| Spia | Significato |
| --- | --- |
| grigia | nessuna diretta in corso |
| verde | diretta in onda su tutti gli schermi indicati |
| ambra | diretta conclusa da meno di un quarto d'ora |
| rossa | diretta avviata ma uno o più schermi non la stanno ricevendo |

La spia si aggiorna da sola: una diretta avviata da un altro operatore si vede
comparire senza ricaricare la pagina.

## Manutenzione

In *Sistema → Manutenzione* trovi le informazioni tecniche dell'installazione
(versioni, limiti di upload, permessi delle cartelle) e due operazioni
irreversibili, entrambe protette da password e parola di conferma:

- **Reset completo**: azzera tutto e riporta il sistema alla configurazione
  iniziale
- **Disinstallazione**: azzera e disattiva l'accesso, con le istruzioni per
  rimuovere i file dall'hosting
