<?php
declare(strict_types=1);

// Serve il logo del banner nell'anteprima del pannello: uploads/ è chiusa
// al web. Con ?screen=N mostra quello dello schermo indicato, altrimenti
// quello delle impostazioni generali.
// Diversamente dal logo del marchio questo non è pubblico: lo vede solo
// chi è già autenticato nel CMS.
require_once __DIR__ . '/_cms.php';

$screenId = (int) ($_GET['screen'] ?? 0);
if ($screenId > 0) {
    $stmt = db()->prepare('SELECT * FROM screens WHERE id = ?');
    $stmt->execute([$screenId]);
    $screen = $stmt->fetch();
    $settings = $screen !== false ? overlay_settings_for_screen($screen) : overlay();
} else {
    $settings = overlay();
}

$path = overlay_logo_path($settings);
if ($path === null) {
    http_response_code(404);
    exit;
}

$mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
if (!str_starts_with($mime, 'image/')) {
    http_response_code(415);
    exit;
}

stream_file($path, $mime, 'private, max-age=86400');
