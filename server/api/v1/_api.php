<?php
declare(strict_types=1);

// Prologo comune degli endpoint API: bootstrap + errori sempre in JSON,
// senza mai esporre dettagli interni al client.
require dirname(__DIR__, 2) . '/core/bootstrap.php';
require dirname(__DIR__, 2) . '/core/trash.php';    // setting_get()
require dirname(__DIR__, 2) . '/core/branding.php'; // nome e testi mostrati sulla TV

// Sistema disinstallato: nessun dispositivo deve più ricevere contenuti
if (is_file(APP_ROOT . '/core/uninstalled.lock')) {
    json_error('Servizio non disponibile', 503);
}

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage());
    json_error('Errore interno del server', 500);
});
