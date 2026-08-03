<?php
declare(strict_types=1);

// Prologo comune degli endpoint API: bootstrap + errori sempre in JSON,
// senza mai esporre dettagli interni al client.
require dirname(__DIR__, 2) . '/core/bootstrap.php';

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage());
    json_error('Errore interno del server', 500);
});
