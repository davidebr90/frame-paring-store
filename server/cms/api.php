<?php
declare(strict_types=1);

// Endpoint AJAX del CMS (sessione admin + CSRF). Azioni:
// upload, media_update, media_delete, media_reorder, screen_galleries.
require __DIR__ . '/_cms.php';

require_method('POST');
require_csrf();

// Segna una galleria come modificata (le TV rilevano il cambio di playlist)
function touch_gallery(int $galleryId): void
{
    db()->prepare('UPDATE galleries SET updated_at = NOW() WHERE id = ?')->execute([$galleryId]);
}

$action = (string) ($_GET['action'] ?? '');
$body = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? read_json_body() : [];
$pdo = db();

switch ($action) {

    case 'upload':
        $galleryId = (int) ($_POST['gallery_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE id = ?');
        $stmt->execute([$galleryId]);
        if ($stmt->fetchColumn() === false) {
            json_error('Galleria non trovata', 404);
        }

        $file = $_FILES['file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            json_error('Upload fallito (file troppo grande per i limiti PHP dell\'hosting?)', 422);
        }
        if ($file['size'] > (int) config('max_upload_bytes')) {
            json_error('File oltre il limite di ' . human_bytes((int) config('max_upload_bytes')), 422);
        }

        // Il MIME si verifica dal contenuto reale, mai dal nome file
        $allowed = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
        ];
        $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
        if (!isset($allowed[$mime])) {
            json_error('Formato non supportato: ' . $mime, 422);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
            json_error('Impossibile salvare il file (permessi cartella uploads?)', 500);
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM media WHERE gallery_id = ?');
        $stmt->execute([$galleryId]);
        $sortOrder = (int) $stmt->fetchColumn();

        $pdo->prepare('INSERT INTO media
                       (gallery_id, type, filename, original_name, mime_type, filesize, checksum, sort_order)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $galleryId,
                str_starts_with($mime, 'image/') ? 'image' : 'video',
                $filename,
                mb_substr((string) $file['name'], 0, 255),
                $mime,
                $file['size'],
                sha1_file(UPLOADS_DIR . '/' . $filename),
                $sortOrder,
            ]);
        $newId = (int) $pdo->lastInsertId(); // prima di touch_gallery: l'UPDATE lo azzererebbe
        touch_gallery($galleryId);
        json_out(['id' => $newId]);

    case 'media_update':
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT gallery_id FROM media WHERE id = ?');
        $stmt->execute([$id]);
        $galleryId = $stmt->fetchColumn();
        if ($galleryId === false) {
            json_error('Media non trovato', 404);
        }

        $fields = [];
        $params = [];
        if (isset($body['rotation'])) {
            if (!in_array((int) $body['rotation'], [0, 90, 180, 270], true)) {
                json_error('Rotazione non valida', 422);
            }
            $fields[] = 'rotation = ?';
            $params[] = (int) $body['rotation'];
        }
        if (isset($body['fit'])) {
            if (!in_array($body['fit'], ['contain', 'cover'], true)) {
                json_error('Fit non valido', 422);
            }
            $fields[] = 'fit = ?';
            $params[] = $body['fit'];
        }
        if (isset($body['duration'])) {
            $d = (int) $body['duration'];
            if ($d < 1 || $d > 3600) {
                json_error('Durata non valida (1-3600 secondi)', 422);
            }
            $fields[] = 'duration_seconds = ?';
            $params[] = $d;
        }
        if (!$fields) {
            json_error('Nessun campo da aggiornare', 422);
        }

        $params[] = $id;
        $pdo->prepare('UPDATE media SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        touch_gallery((int) $galleryId);
        json_out(['ok' => true]);

    case 'media_delete':
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT gallery_id, filename FROM media WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            json_error('Media non trovato', 404);
        }
        $path = UPLOADS_DIR . '/' . basename($row['filename']);
        if (is_file($path)) {
            unlink($path);
        }
        $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        touch_gallery((int) $row['gallery_id']);
        json_out(['ok' => true]);

    case 'media_reorder':
        $galleryId = (int) ($body['gallery_id'] ?? 0);
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || !$ids) {
            json_error('Elenco id mancante', 422);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE media SET sort_order = ? WHERE id = ? AND gallery_id = ?');
        foreach (array_values($ids) as $position => $mediaId) {
            $stmt->execute([$position, (int) $mediaId, $galleryId]);
        }
        $pdo->commit();
        touch_gallery($galleryId);
        json_out(['ok' => true]);

    case 'screen_galleries':
        $screenId = (int) ($body['screen_id'] ?? 0);
        $ids = $body['gallery_ids'] ?? null;
        $stmt = $pdo->prepare('SELECT id FROM screens WHERE id = ?');
        $stmt->execute([$screenId]);
        if ($stmt->fetchColumn() === false) {
            json_error('Schermo non trovato', 404);
        }
        if (!is_array($ids)) {
            json_error('Elenco gallerie mancante', 422);
        }

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM screen_galleries WHERE screen_id = ?')->execute([$screenId]);
        $stmt = $pdo->prepare('INSERT IGNORE INTO screen_galleries (screen_id, gallery_id, sort_order)
                               SELECT ?, id, ? FROM galleries WHERE id = ?');
        foreach (array_values($ids) as $position => $galleryId) {
            $stmt->execute([$screenId, $position, (int) $galleryId]);
        }
        $pdo->commit();
        json_out(['ok' => true]);

    default:
        json_error('Azione sconosciuta', 404);
}
