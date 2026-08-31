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

// POST restart.php — segnala che il processo dell'app è appena (ri)partito,
// e perché. Chiamato una sola volta per ogni avvio vero (da MainActivity
// .onCreate(), non nel polling continuo di state.php): incrementa il
// contatore riavvii dello schermo, visibile nel CMS senza doversi collegare
// via adb al dispositivo per saperlo.
require_once __DIR__ . '/_api.php';
require_method('POST');

$screen = require_device();

$body = read_json_body();
$reason = $body['reason'] ?? 'manual';
if (!in_array($reason, ['boot', 'watchdog', 'manual'], true)) {
    $reason = 'manual';
}

db()->prepare('UPDATE screens
                SET restart_count = restart_count + 1,
                    last_restart_reason = ?,
                    last_restart_at = NOW()
                WHERE id = ?')
    ->execute([$reason, $screen['id']]);

// Oltre al contatore riassuntivo, una riga datata nella cronologia: il
// contatore dice quanti, la cronologia dice quando.
screen_event_add((int) $screen['id'], 'restart', $reason);

json_out(['ok' => true]);
