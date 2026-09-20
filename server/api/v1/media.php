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
$stmt = db()->prepare('SELECT m.filename, m.mime_type, m.filesize'
                       . rendition_select_fields('m') . '
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

// Quale variante consegnare. L'app verifica lo sha1 di ciò che scarica e
// scarta un file che non combacia con la playlist: il parametro `v` porta
// quindi il checksum che il dispositivo si aspetta, e qui si consegna
// esattamente quello. Senza `v` (app precedenti la 1.13.0, o una playlist
// ancora in cache da prima dell'aggiornamento) si consegna l'originale,
// che è ciò che quelle playlist dichiarano.
$wanted = (string) ($_GET['v'] ?? '');
$delivery = media_delivery($media);
if ($wanted === '' || !hash_equals($delivery['checksum'], $wanted)) {
    $delivery = [
        'filename' => basename((string) $media['filename']),
        'mime'     => (string) $media['mime_type'],
    ];
}

$path = UPLOADS_DIR . '/' . basename($delivery['filename']);
if (!is_file($path)) {
    json_error('File mancante sul server', 404);
}

stream_file($path, $delivery['mime']);
