<?php
declare(strict_types=1);

// GET /api/v1/media.php?id=N — download di un file multimediale.
// Autenticazione: header "Authorization: Bearer <auth_token>".
// Il file viene servito solo se appartiene a una galleria assegnata
// allo schermo che lo richiede. Niente supporto Range: l'app scarica
// i file in cache locale e li riproduce da lì, non in streaming.
require_once __DIR__ . '/_api.php';

$screen = require_device();

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    json_error('Parametro id mancante', 422);
}

// Autorizzato se il media è in una galleria assegnata allo schermo, se è la
// sua immagine di cortesia, oppure se è il contenuto di una diretta attiva
// su quello schermo (che può provenire da qualsiasi galleria)
$stmt = db()->prepare('SELECT m.filename, m.mime_type, m.filesize
                       FROM media m
                       WHERE m.id = :id AND m.deleted_at IS NULL AND (
                           EXISTS (SELECT 1 FROM screen_galleries sg
                                   WHERE sg.gallery_id = m.gallery_id AND sg.screen_id = :sid)
                           OR EXISTS (SELECT 1 FROM screens s
                                      WHERE s.id = :sid2 AND s.idle_media_id = m.id)
                           OR EXISTS (SELECT 1 FROM live_overrides o
                                      JOIN live_override_screens los ON los.override_id = o.id
                                      WHERE los.screen_id = :sid3
                                        AND (o.media_id = m.id OR o.gallery_id = m.gallery_id)
                                        AND o.stopped_at IS NULL
                                        AND (o.ends_at IS NULL OR o.ends_at > NOW()))
                       )
                       LIMIT 1');
$stmt->execute([
    'id' => $id, 'sid' => $screen['id'], 'sid2' => $screen['id'], 'sid3' => $screen['id'],
]);
$media = $stmt->fetch();

if ($media === false) {
    json_error('Media non trovato o non assegnato a questo schermo', 404);
}

$path = UPLOADS_DIR . '/' . basename($media['filename']);
if (!is_file($path)) {
    json_error('File mancante sul server', 404);
}

stream_file($path, $media['mime_type']);
