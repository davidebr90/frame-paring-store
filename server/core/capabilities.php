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
        'label'    => 'feat.live.sync.label',
        'summary'  => 'feat.live.sync.sum',
        'requires' => [],
    ],
    'live.external' => [
        'label'    => 'feat.live.external.label',
        'summary'  => 'feat.live.external.sum',
        'requires' => [],
    ],
    'live.webcam' => [
        'label'    => 'feat.live.webcam.label',
        'summary'  => 'feat.live.webcam.sum',
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

    // Si memorizzano CHIAVI di traduzione, non testo: il risultato finisce
    // in cache per un giorno e deve restare valido in qualunque lingua.
    // 'detail_raw' è per i valori tecnici, che non si traducono.
    return [
        'exec' => [
            'label'      => 'chk.exec.label',
            'ok'         => $execAvailable,
            'detail_key' => $execAvailable ? 'chk.exec.ok' : 'chk.exec.ko',
            'howto'      => 'chk.exec.howto',
        ],
        'ffmpeg' => [
            'label'      => 'chk.ffmpeg.label',
            'ok'         => $ffmpegPath !== null,
            'detail_raw' => $ffmpegPath !== null ? $ffmpegVersion . ' (' . $ffmpegPath . ')' : null,
            'detail_key' => $ffmpegPath !== null
                ? null
                : ($execAvailable ? 'chk.ffmpeg.missing' : 'chk.ffmpeg.unknown'),
            'howto'      => 'chk.ffmpeg.howto',
        ],
        'long_running' => [
            'label'         => 'chk.long.label',
            'ok'            => $maxExec === 0 || $maxExec >= 300,
            'detail_key'    => $maxExec === 0 ? 'chk.long.unlimited' : 'chk.long.limited',
            'detail_params' => ['n' => $maxExec],
            'howto'         => 'chk.long.howto',
        ],
        'memory' => [
            'label'      => 'chk.memory.label',
            'ok'         => true, // informativo
            'detail_raw' => (string) $memory,
            'howto'      => null,
        ],
        'uploads_writable' => [
            'label'      => 'chk.uploads.label',
            'ok'         => is_writable(UPLOADS_DIR),
            'detail_key' => is_writable(UPLOADS_DIR) ? 'chk.uploads.ok' : 'chk.uploads.ko',
            'howto'      => 'chk.uploads.howto',
        ],
    ];
}

// Traduce un controllo per la visualizzazione. Va applicata al momento di
// stampare, MAI prima della cache: la lingua può cambiare in ogni istante.
function capability_view(array $check): array
{
    return [
        'label'  => t((string) $check['label']),
        'ok'     => (bool) $check['ok'],
        'detail' => $check['detail_raw']
            ?? (isset($check['detail_key']) && $check['detail_key'] !== null
                ? t((string) $check['detail_key'], $check['detail_params'] ?? [])
                : ''),
        'howto'  => $check['howto'] !== null ? t((string) $check['howto']) : '',
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
    $label = isset(FEATURES[$feature]) ? t(FEATURES[$feature]['label']) : $feature;
    if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) {
        json_error(t('feat.unavailable_api', ['label' => $label]), 409);
    }
    flash_set('err', t('feat.unavailable', ['label' => $label]));
    redirect('capabilities.php');
}
