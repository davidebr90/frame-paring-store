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

// Cronologia degli eventi di uno schermo: a differenza del contatore
// riassuntivo su `screens` (restart_count e ultimo riavvio, 1.12.8), qui
// resta una riga per ogni evento, con il suo orario. È l'unico modo per
// rispondere a "quanto spesso succede e a che ora" senza collegarsi via
// adb al dispositivo per leggere il log su file.
//
// Due tipi di evento:
//  - 'restart' — l'app ha segnalato un avvio vero del processo, con il
//    motivo dichiarato ('boot' | 'watchdog' | 'manual').
//  - 'gap'     — il dispositivo ha ripreso a farsi vivo dopo essere stato
//    irraggiungibile più a lungo della finestra di "online". Registrato al
//    ritorno, non alla scomparsa: il server non ha modo di accorgersi in
//    tempo reale che una TV è sparita, se ne accorge quando ricompare.
//    `created_at` è quindi l'istante del RIENTRO, e `gap_seconds` dice per
//    quanto era stata assente.

// Conserva gli eventi per questo periodo. Uno schermo che va in crisi ogni
// 15 minuti produce ~100 righe al giorno: a 90 giorni restano nell'ordine
// delle migliaia di righe da poche decine di byte, trascurabili anche su
// hosting condiviso.
const SCREEN_EVENTS_RETENTION_DAYS = 90;

// Oltre questa distanza dall'ultimo contatto uno schermo e' considerato
// sparito. Stessa formula del badge nel CMS (elenco schermi e scheda
// schermo): cosi' il log dice esattamente le stesse assenze che il badge
// ha mostrato come "Offline", senza soglie che si contraddicono.
function screen_online_window(): int
{
    return (int) config('poll_interval') * 2 + 30;
}

// Secondi di assenza appena conclusi, oppure null se il dispositivo non e'
// mai mancato piu' della finestra di online. Va chiamata PRIMA di
// aggiornare last_seen_at, sulla riga letta dal database.
function screen_gap_seconds(array $screen): ?int
{
    if (empty($screen['last_seen_at'])) {
        return null;   // primo contatto in assoluto: non e' un'assenza
    }
    $gap = time() - strtotime((string) $screen['last_seen_at']);
    return $gap > screen_online_window() ? $gap : null;
}

// Registra un evento. È telemetria: non deve MAI far fallire la richiesta
// che l'ha generata, nemmeno se la tabella non esiste ancora (server
// aggiornato nei file ma non nel database). Per questo inghiotte qualunque
// Throwable limitandosi a lasciarne traccia nel log di PHP.
function screen_event_add(int $screenId, string $type, ?string $reason = null, ?int $gapSeconds = null): void
{
    try {
        db()->prepare('INSERT INTO screen_events (screen_id, type, reason, gap_seconds, created_at)
                       VALUES (?, ?, ?, ?, NOW())')
            ->execute([$screenId, $type, $reason, $gapSeconds]);

        // Potatura opportunistica: gli inserimenti sono rari (solo riavvii e
        // assenze vere, mai il polling), quindi si può fare qui senza pesare.
        db()->prepare('DELETE FROM screen_events
                        WHERE screen_id = ?
                          AND created_at < NOW() - INTERVAL ' . SCREEN_EVENTS_RETENTION_DAYS . ' DAY')
            ->execute([$screenId]);
    } catch (Throwable $e) {
        error_log('screen_event_add: ' . $e->getMessage());
    }
}

// Ultimi eventi di uno schermo, dal più recente. Restituisce array vuoto
// (invece di esplodere) se la tabella non c'è ancora: la scheda schermo
// resta consultabile anche a database non aggiornato.
function screen_events(int $screenId, int $limit = 60): array
{
    try {
        $stmt = db()->prepare('SELECT type, reason, gap_seconds, created_at
                                 FROM screen_events
                                WHERE screen_id = ?
                                ORDER BY id DESC
                                LIMIT ' . max(1, $limit));
        $stmt->execute([$screenId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('screen_events: ' . $e->getMessage());
        return [];
    }
}

// Come mostrare un evento nella cronologia del CMS: classe del badge,
// etichetta e dettaglio. Tenuto qui e non nel template perche' i tipi di
// evento sono un concetto del dominio, non della pagina — e perche' cosi'
// aggiungerne uno tocca un punto solo.
function screen_event_view(array $ev): array
{
    $type   = (string) $ev['type'];
    $reason = $ev['reason'] !== null ? (string) $ev['reason'] : null;

    switch ($type) {
        case 'restart':
            return ['badge-pending', t('sv.ev.restart'),
                    t('sv.restart_reason.' . ($reason ?? 'manual'))];

        case 'gap':
            return ['badge-offline', t('sv.ev.gap'),
                    t('sv.ev.gap_detail', ['duration' => format_duration((int) $ev['gap_seconds'])])];

        case 'paired':
            // Stesse etichette dell'elenco schermi, per non dire la stessa
            // cosa in due modi diversi in due pagine
            $methods = ['pin' => 'PIN', 'qr' => 'QR', 'manual' => t('screen.method.manual')];
            return ['badge-online', t('sv.ev.paired'), $methods[$reason] ?? ''];

        case 'revoked':
            return ['badge-revoked', t('sv.ev.revoked'), ''];

        case 'registered':
            return ['badge-offline', t('sv.ev.registered'), ''];

        case 'config':
            return ['badge-offline', t('sv.ev.config'),
                    $reason !== null ? t('sv.ev.config.' . $reason) : ''];

        // Evento scritto da una versione piu' recente di quella che sta
        // disegnando la pagina: si mostra grezzo invece di sparire
        default:
            return ['badge-offline', $type, $reason ?? ''];
    }
}

// Durata in forma leggibile: "45 s", "17 min", "1 h 12 min". Sotto il
// minuto i secondi contano, sopra l'ora i secondi sono rumore.
function format_duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $rest  = $minutes % 60;
    return $rest > 0 ? $hours . ' h ' . $rest . ' min' : $hours . ' h';
}
