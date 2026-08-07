<?php
declare(strict_types=1);

// Anteprima media per il CMS (sessione admin). La cartella uploads è
// chiusa al web: questo è l'unico modo per vedere i file dal browser.
require_once __DIR__ . '/_cms.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT filename, mime_type FROM media WHERE id = ?');  // anche nel cestino: serve all'anteprima di ripristino
$stmt->execute([$id]);
$media = $stmt->fetch();

if ($media === false) {
    http_response_code(404);
    exit;
}

$path = UPLOADS_DIR . '/' . basename($media['filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

stream_file($path, $media['mime_type']);
