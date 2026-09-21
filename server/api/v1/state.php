<?php
declare(strict_types=1);

// GET state.php — stato compatto dello schermo.
// Risposta di poche centinaia di byte, pensata per essere interrogata
// spesso (ogni 10 secondi) senza pesare: serve a far reagire in fretta
// le TV all'avvio o allo stop di una diretta in sovrapposizione.
// La playlist completa resta su playlist.php, interrogata di rado.
require_once __DIR__ . '/_api.php';
require_method('GET');
require_once dirname(__DIR__, 2) . '/core/live.php';

$screen = require_device();

// La TV allega al battito quanta memoria sta usando e quanta ne resta al
// dispositivo (vedi core/memory.php). Sono dati che prima si potevano
// avere solo collegandosi via adb al televisore, cioè quasi mai.
memory_record($screen, $_GET);

$override = override_payload(active_override_for_screen((int) $screen['id']), light: true);

json_out([
    // Cambia quando cambia qualcosa che il player deve rispettare:
    // la TV confronta questo valore e scarica la playlist solo se serve
    'playlist_version' => playlist_version($screen),
    'override'         => $override,
    'poll_interval'    => (int) config('poll_interval'),
    'state_interval'   => (int) config('state_interval'),
]);
