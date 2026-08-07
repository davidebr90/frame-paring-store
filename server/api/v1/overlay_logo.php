<?php
declare(strict_types=1);

// GET /api/v1/overlay_logo.php — logo del banner in sovraimpressione.
// Autenticazione: header "Authorization: Bearer <auth_token>".
// Ogni schermo riceve il proprio logo: quello della sua configurazione
// personalizzata, o quello generale se eredita.
// La cartella uploads è chiusa al web: il file passa sempre da qui.
require_once __DIR__ . '/_api.php';

$screen = require_device();

$settings = overlay_settings_for_screen($screen);
if (!overlay_settings_active($settings) || (string) $settings['overlay_show_logo'] !== '1') {
    json_error('Banner non attivo', 404);
}

$path = overlay_logo_path($settings);
if ($path === null) {
    json_error('Logo non impostato', 404);
}

// stream_file() imposta da sé Content-Type, Content-Length e la cache,
// e invia il file a blocchi (i download interi troncavano su Apache)
$mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
stream_file($path, $mime);
