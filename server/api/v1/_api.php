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

// Prologo comune degli endpoint API: bootstrap + errori sempre in JSON,
// senza mai esporre dettagli interni al client.
require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/trash.php';    // setting_get()
require_once dirname(__DIR__, 2) . '/core/branding.php'; // nome e testi mostrati sulla TV
require_once dirname(__DIR__, 2) . '/core/live.php';     // dirette in sovrapposizione
require_once dirname(__DIR__, 2) . '/core/overlay.php';  // banner in sovraimpressione

// Sistema disinstallato: nessun dispositivo deve più ricevere contenuti
if (is_file(APP_ROOT . '/core/uninstalled.lock')) {
    json_error('Servizio non disponibile', 503);
}

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage());
    json_error('Errore interno del server', 500);
});
