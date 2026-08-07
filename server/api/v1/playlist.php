<?php
declare(strict_types=1);

// GET /api/v1/playlist.php — playlist corrente dello schermo.
// Autenticazione: header "Authorization: Bearer <auth_token>".
// L'app confronta 'version' con quella in cache: se identica non
// deve scaricare nulla; altrimenti scarica i soli media con checksum nuovo.
require_once __DIR__ . '/_api.php';
require_method('GET');

$screen = require_device();

json_out(playlist_for_screen($screen));
