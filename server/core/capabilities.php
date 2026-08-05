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

// Rilevamento delle CAPACITÀ dell'installazione, non del tipo di hosting:
// esistono hosting condivisi generosi e VPS configurati male, quindi si
// verifica ciò che il sistema sa fare davvero. Le funzioni avanzate si
// abilitano da sole dove le capacità ci sono.

// Ogni funzionalità dichiara quali controlli le servono.
const FEATURES = [
    'live.sync' => [
        'label'    => 'Diretta sincronizzata da file',
        'summary'  => 'Lo stesso contenuto parte insieme su più schermi. Nessun requisito particolare.',
        'requires' => [],
    ],
    'live.external' => [
        'label'    => 'Sorgente esterna (HLS, DASH, RTSP)',
        'summary'  => 'Ritrasmette un flusso già esistente: telecamera IP, diretta di un servizio esterno.',
        'requires' => [],
    ],
    'live.webcam' => [
        'label'    => 'Diretta da webcam e microfono',
        'summary'  => 'Riceve un flusso dal tuo computer e lo distribuisce agli schermi.',
        'requires' => ['exec', 'ffmpeg', 'long_running'],
    ],
];

// I singoli controlli. Ognuno restituisce [ok, dettaglio leggibile].
function capability_checks(): array
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    $execAvailable = function_exists('exec')
        && function_exists('proc_open')
        && !in_array('exec', $disabled, true)
        && !in_array('proc_open', $disabled, true);

    $ffmpegPath = null;
    $ffmpegVersion = null;
    if ($execAvailable) {
        $candidates = ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
        // percorso configurabile per installazioni non standard
        if (($custom = setting_get('ffmpeg_path')) !== null && $custom !== '') {
            array_unshift($candidates, $custom);
        }
        foreach ($candidates as $candidate) {
            $output = [];
            $code = 1;
            @exec(escapeshellcmd($candidate) . ' -version 2>&1', $output, $code);
            if ($code === 0 && isset($output[0]) && str_contains($output[0], 'ffmpeg version')) {
                $ffmpegPath = $candidate;
                $ffmpegVersion = trim(explode(' Copyright', $output[0])[0]);
                break;
            }
        }
    }

    $maxExec = (int) ini_get('max_execution_time');
    $memory  = ini_get('memory_limit');

    return [
        'exec' => [
            'label'  => 'Esecuzione di programmi esterni',
            'ok'     => $execAvailable,
            'detail' => $execAvailable
                ? 'exec e proc_open disponibili'
                : 'exec/proc_open disabilitate dalla configurazione PHP',
            'howto'  => 'Su hosting condiviso sono quasi sempre bloccate per sicurezza. '
                      . 'Su un server proprio si abilitano rimuovendole da disable_functions in php.ini.',
        ],
        'ffmpeg' => [
            'label'  => 'ffmpeg',
            'ok'     => $ffmpegPath !== null,
            'detail' => $ffmpegPath !== null
                ? $ffmpegVersion . ' (' . $ffmpegPath . ')'
                : ($execAvailable ? 'non trovato nel sistema' : 'non verificabile senza exec'),
            'howto'  => 'Necessario per trattare i flussi video. Su Debian/Ubuntu: apt install ffmpeg',
        ],
        'long_running' => [
            'label'  => 'Operazioni prolungate',
            'ok'     => $maxExec === 0 || $maxExec >= 300,
            'detail' => $maxExec === 0
                ? 'nessun limite di durata'
                : "limite di $maxExec secondi per richiesta",
            'howto'  => 'Serve max_execution_time pari a 0 (illimitato) o almeno 300 secondi.',
        ],
        'memory' => [
            'label'  => 'Memoria disponibile a PHP',
            'ok'     => true, // informativo
            'detail' => (string) $memory,
            'howto'  => '',
        ],
        'uploads_writable' => [
            'label'  => 'Cartella dei media scrivibile',
            'ok'     => is_writable(UPLOADS_DIR),
            'detail' => is_writable(UPLOADS_DIR) ? 'scrivibile' : 'NON scrivibile',
            'howto'  => 'Imposta i permessi della cartella uploads/ a 755 (o 775) dal client FTP.',
        ],
    ];
}

// Capacità con cache: i controlli eseguono comandi esterni, quindi non
// vanno ripetuti a ogni pagina. Si aggiornano da Sistema → Capacità.
function capabilities(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    if (!$refresh) {
        $stored = setting_get('capabilities_json');
        if ($stored !== null) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded) && isset($decoded['checks'], $decoded['at'])) {
                // Le capacità possono cambiare (migrazione di hosting, nuovo
                // php.ini): si ricalcolano comunque una volta al giorno
                if (time() - (int) $decoded['at'] < 86400) {
                    return $cache = $decoded['checks'];
                }
            }
        }
    }

    $checks = capability_checks();
    setting_set('capabilities_json', json_encode(['at' => time(), 'checks' => $checks]));
    return $cache = $checks;
}

// Una funzionalità è disponibile se tutti i suoi requisiti sono soddisfatti
function feature_available(string $feature): bool
{
    $required = FEATURES[$feature]['requires'] ?? null;
    if ($required === null) {
        return false; // funzionalità sconosciuta: mai disponibile
    }
    $checks = capabilities();
    foreach ($required as $key) {
        if (empty($checks[$key]['ok'])) {
            return false;
        }
    }
    return true;
}

// Requisiti non soddisfatti, per la diagnostica mostrata all'utente
function feature_missing(string $feature): array
{
    $missing = [];
    $checks = capabilities();
    foreach (FEATURES[$feature]['requires'] ?? [] as $key) {
        if (empty($checks[$key]['ok'])) {
            $missing[$key] = $checks[$key];
        }
    }
    return $missing;
}

// Guardia server-side: nascondere una voce nel menu non è una protezione.
// Ogni endpoint di una funzione avanzata deve chiamare questa funzione.
function require_feature(string $feature): void
{
    if (feature_available($feature)) {
        return;
    }
    $label = FEATURES[$feature]['label'] ?? $feature;
    if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) {
        json_error("Funzione non disponibile su questa installazione: $label", 409);
    }
    flash_set('err', "\"$label\" non è disponibile su questa installazione. "
        . 'Controlla i requisiti in Sistema → Capacità.');
    redirect('capabilities.php');
}
