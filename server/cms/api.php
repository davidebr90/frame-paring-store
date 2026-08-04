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

// Nessuno schermo deve restare in modalità "immagine di cortesia" senza
// immagine (es. dopo la cancellazione del media scelto): si ricade sul nero.
function heal_orphan_idle(): void
{
    db()->query("UPDATE screens SET idle_mode = 'black'
                 WHERE idle_mode = 'image' AND idle_media_id IS NULL");
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
        $media = store_uploaded_media($_FILES['file'] ?? [], $galleryId);
        json_out(['id' => $media['id']]);

    // Immagine di cortesia: upload nella galleria di servizio e attivazione
    // immediata sullo schermo indicato
    case 'idle_upload':
        $screenId = (int) ($_POST['screen_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM screens WHERE id = ?');
        $stmt->execute([$screenId]);
        if ($stmt->fetchColumn() === false) {
            json_error('Schermo non trovato', 404);
        }
        $media = store_uploaded_media($_FILES['file'] ?? [], courtesy_gallery_id(), imagesOnly: true);
        $pdo->prepare("UPDATE screens SET idle_mode = 'image', idle_media_id = ? WHERE id = ?")
            ->execute([$media['id'], $screenId]);
        json_out([
            'id'    => $media['id'],
            'label' => 'Immagini di cortesia — ' . $media['label'],
        ]);

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
        // Transizioni: stringa vuota = torna al default della galleria (NULL)
        foreach (['transition_in', 'transition_out'] as $tField) {
            if (isset($body[$tField])) {
                $t = (string) $body[$tField];
                if ($t !== '' && !isset(TRANSITIONS[$t])) {
                    json_error('Transizione non valida', 422);
                }
                $fields[] = $tField . ' = ?';
                $params[] = $t === '' ? null : $t;
            }
        }
        if (isset($body['muted'])) {
            if (!in_array((string) $body['muted'], ['0', '1'], true)) {
                json_error('Valore audio non valido', 422);
            }
            $fields[] = 'muted = ?';
            $params[] = (int) $body['muted'];
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
        heal_orphan_idle(); // la FK ha già messo a NULL gli idle_media_id
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
