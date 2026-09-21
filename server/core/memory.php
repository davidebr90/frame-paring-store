<?php
declare(strict_types=1);

/*
 * Frame Paring Store — sistema di digital signage self-hosted
 * Copyright (C) 2026 Davide Pica
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA. Vedi la GNU Affero General Public License per i dettagli.
 * Dovresti aver ricevuto una copia della licenza insieme a questo programma;
 * in caso contrario: https://www.gnu.org/licenses/
 */

// MEMORIA DEI DISPOSITIVI
//
// Fino alla 1.12.11 l'unico modo di sapere quanta memoria stesse usando
// una TV era collegarsi a quella TV con adb e lanciare `dumpsys meminfo`,
// cioè essere sul posto, sulla stessa rete, nel momento giusto. Un dato
// che si può avere solo così, in pratica, non si ha mai: la diagnosi del
// guasto di agosto è arrivata settimane dopo i fatti.
//
// L'app allega ora questi numeri al proprio battito di stato, che passa
// comunque ogni pochi secondi. Costano una manciata di byte e si vedono
// dalla scheda dello schermo nel CMS.

// Sotto questa soglia di memoria libera si considera il dispositivo in
// affanno. Android dichiara la propria soglia di allarme (lowMemory), che
// è il dato migliore quando c'è: questa vale come rete di sicurezza per i
// firmware che non la riportano in modo sensato.
const MEMORY_LOW_MB = 80;

// Un evento in cronologia per ogni battito significherebbe centinaia di
// righe l'ora. Si registra solo quando lo stato CAMBIA, e comunque non
// più spesso di così.
const MEMORY_EVENT_MIN_INTERVAL_MIN = 30;

// Le colonne della memoria esistono già? Stessa ragione di
// rendition_columns_available(): i file del server si caricano prima di
// eseguire la migrazione SQL, e in quella finestra nulla deve rompersi.
function memory_columns_available(): bool
{
    static $disponibili = null;
    if ($disponibili !== null) {
        return $disponibili;
    }
    try {
        db()->query('SELECT mem_at FROM screens LIMIT 0');
        $disponibili = true;
    } catch (Throwable $e) {
        $disponibili = false;
    }
    return $disponibili;
}

// Registra i numeri arrivati con il battito di stato. Tutti i parametri
// sono facoltativi: un'app precedente la 1.13.0 non ne manda nessuno e
// deve continuare a funzionare identica.
//
// Non può mai far fallire il battito: è telemetria, e una TV non deve
// smettere di riprodurre perché non si è riusciti a scrivere una statistica.
function memory_record(array $screen, array $query): void
{
    if (!memory_columns_available()) {
        return;
    }
    $usata = isset($query['mem_used']) ? (int) $query['mem_used'] : null;
    $libera = isset($query['mem_avail']) ? (int) $query['mem_avail'] : null;
    $totale = isset($query['mem_total']) ? (int) $query['mem_total'] : null;
    if ($usata === null && $libera === null) {
        return; // nessun dato: app precedente, niente da registrare
    }

    // Valori palesemente fuori scala (parametro manomesso, firmware che
    // riporta sciocchezze): si scartano invece di sporcare lo storico
    foreach ([$usata, $libera, $totale] as $valore) {
        if ($valore !== null && ($valore < 0 || $valore > 1024 * 1024)) {
            return;
        }
    }

    // In affanno se lo dice Android, oppure se la memoria libera è sotto
    // la nostra soglia
    $bassa = isset($query['mem_low'])
        ? (int) ((string) $query['mem_low'] === '1')
        : (int) ($libera !== null && $libera < MEMORY_LOW_MB);

    try {
        db()->prepare('UPDATE screens
                        SET mem_used_mb = ?, mem_avail_mb = ?, mem_total_mb = ?,
                            mem_low = ?, mem_at = NOW()
                        WHERE id = ?')
            ->execute([$usata, $libera, $totale, $bassa, (int) $screen['id']]);
    } catch (Throwable $e) {
        error_log('memory_record: ' . $e->getMessage());
        return;
    }

    memory_maybe_log_event($screen, $bassa === 1, $usata, $libera);
}

// Una riga in cronologia quando il dispositivo ENTRA in affanno, e una
// quando ne esce. Gli stati intermedi non aggiungono nulla: interessa
// quando è cambiato, non che stia continuando.
function memory_maybe_log_event(array $screen, bool $bassa, ?int $usata, ?int $libera): void
{
    $eraBassa = isset($screen['mem_low']) && (int) $screen['mem_low'] === 1;
    if ($bassa === $eraBassa) {
        return;
    }
    // Un dispositivo che oscilla attorno alla soglia riempirebbe la
    // cronologia di righe alternate: si aspetta comunque un intervallo
    if (!empty($screen['mem_at'])) {
        $ultimo = strtotime((string) $screen['mem_at']);
        if ($ultimo !== false && time() - $ultimo < MEMORY_EVENT_MIN_INTERVAL_MIN * 60) {
            // Solo se lo stato precedente era già stato registrato: la
            // prima transizione passa comunque
            if (!empty($screen['mem_event_at'])) {
                $ultimoEvento = strtotime((string) $screen['mem_event_at']);
                if ($ultimoEvento !== false
                    && time() - $ultimoEvento < MEMORY_EVENT_MIN_INTERVAL_MIN * 60
                ) {
                    return;
                }
            }
        }
    }

    $dettaglio = $bassa ? 'low' : 'ok';
    if ($libera !== null) {
        $dettaglio .= ':' . $libera;
    }
    if ($usata !== null) {
        $dettaglio .= '/' . $usata;
    }
    screen_event_add((int) $screen['id'], 'memory', $dettaglio);

    try {
        db()->prepare('UPDATE screens SET mem_event_at = NOW() WHERE id = ?')
            ->execute([(int) $screen['id']]);
    } catch (Throwable $e) {
        error_log('memory_maybe_log_event: ' . $e->getMessage());
    }
}

// Come si legge il dettaglio di un evento 'memory' nella cronologia del
// CMS: "low:42/118" significa entrato in affanno con 42 MB liberi e 118
// usati dall'app.
function memory_event_detail(?string $reason): string
{
    if ($reason === null || $reason === '') {
        return '';
    }
    $parti = explode(':', $reason, 2);
    $stato = $parti[0] === 'low' ? t('sv.memory_event_low') : t('sv.memory_event_ok');
    if (!isset($parti[1])) {
        return $stato;
    }
    $numeri = explode('/', $parti[1], 2);
    $dettagli = [t('sv.memory_free', ['mb' => (int) $numeri[0]])];
    if (isset($numeri[1])) {
        $dettagli[] = t('sv.memory_app', ['mb' => (int) $numeri[1]]);
    }
    return $stato . ' — ' . implode(', ', $dettagli);
}
