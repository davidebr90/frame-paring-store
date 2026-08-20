<?php
declare(strict_types=1);

// Endpoint AJAX del CMS (sessione admin + CSRF). Azioni:
// upload, media_update, media_delete, media_reorder, screen_galleries.
require_once __DIR__ . '/_cms.php';

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
        require_cap('media.upload');
        $galleryId = (int) ($_POST['gallery_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$galleryId]);
        if ($stmt->fetchColumn() === false) {
            json_error(t('api.gallery_not_found'), 404);
        }
        $media = store_uploaded_media($_FILES['file'] ?? [], $galleryId);
        json_out(['id' => $media['id']]);

    // Immagine di cortesia: upload nella galleria di servizio e attivazione
    // immediata sullo schermo indicato
    case 'idle_upload':
        require_cap('schedule.manage');
        $screenId = (int) ($_POST['screen_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM screens WHERE id = ?');
        $stmt->execute([$screenId]);
        if ($stmt->fetchColumn() === false) {
            json_error(t('api.screen_not_found'), 404);
        }
        $media = store_uploaded_media($_FILES['file'] ?? [], courtesy_gallery_id(), imagesOnly: true);
        $pdo->prepare("UPDATE screens SET idle_mode = 'image', idle_media_id = ? WHERE id = ?")
            ->execute([$media['id'], $screenId]);
        json_out([
            'id'    => $media['id'],
            'label' => t('api.courtesy_label', ['name' => $media['label']]),
        ]);

    // Playlist risolta per l'anteprima nel CMS: stessi dati che riceve la TV
    // (transizioni ereditate, rotazioni, fit, fasce), ma con URL serviti
    // dalla sessione admin. Funziona per uno schermo o per una galleria.
    case 'preview':
        $screenId  = (int) ($_GET['screen_id'] ?? 0);
        $galleryId = (int) ($_GET['gallery_id'] ?? 0);

        if ($screenId > 0 && $galleryId > 0) {
            // Una galleria COME APPARIREBBE su questo schermo reale, che sia
            // o no già fra quelli assegnati — usato dal selettore schermo
            // dell'anteprima nella scheda Contenuti
            $stmt = $pdo->prepare('SELECT * FROM screens WHERE id = ?');
            $stmt->execute([$screenId]);
            $screen = $stmt->fetch();
            if ($screen === false) {
                json_error(t('api.screen_not_found'), 404);
            }
            $stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$galleryId]);
            $gallery = $stmt->fetch();
            if ($gallery === false) {
                json_error(t('api.gallery_not_found'), 404);
            }
            $playlist = playlist_for_gallery_on_screen($gallery, $screen);
        } elseif ($screenId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM screens WHERE id = ?');
            $stmt->execute([$screenId]);
            $screen = $stmt->fetch();
            if ($screen === false) {
                json_error(t('api.screen_not_found'), 404);
            }
            $playlist = playlist_for_screen($screen);
        } elseif ($galleryId > 0) {
            // Anteprima di una singola galleria senza alcuno schermo reale
            // (nessuno schermo esiste ancora): schermo fittizio orizzontale
            $stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$galleryId]);
            $gallery = $stmt->fetch();
            if ($gallery === false) {
                json_error(t('api.gallery_not_found'), 404);
            }
            $playlist = playlist_for_gallery($gallery);
        } else {
            json_error(t('api.need_id'), 422);
        }

        foreach ($playlist['items'] as &$item) {
            $item['url'] = 'media_preview.php?id=' . $item['id'];
        }
        unset($item);
        if (!empty($playlist['schedule']['idle_item'])) {
            $playlist['schedule']['idle_item']['url'] =
                'media_preview.php?id=' . $playlist['schedule']['idle_item']['id'];
        }
        json_out($playlist);

    case 'media_update':
        require_cap('media.edit');
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT gallery_id FROM media WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $galleryId = $stmt->fetchColumn();
        if ($galleryId === false) {
            json_error(t('api.media_not_found'), 404);
        }

        $fields = [];
        $params = [];
        if (isset($body['fit'])) {
            if (!in_array($body['fit'], ['contain', 'cover'], true)) {
                json_error(t('api.bad_fit'), 422);
            }
            $fields[] = 'fit = ?';
            $params[] = $body['fit'];
        }
        if (isset($body['duration'])) {
            $d = (int) $body['duration'];
            if ($d < 1 || $d > 3600) {
                json_error(t('api.bad_duration'), 422);
            }
            $fields[] = 'duration_seconds = ?';
            $params[] = $d;
        }
        // Transizioni: stringa vuota = torna al default della galleria (NULL)
        foreach (['transition_in', 'transition_out'] as $tField) {
            if (isset($body[$tField])) {
                $t = (string) $body[$tField];
                if ($t !== '' && !isset(TRANSITIONS[$t])) {
                    json_error(t('gnew.err_transition'), 422);
                }
                $fields[] = $tField . ' = ?';
                $params[] = $t === '' ? null : $t;
            }
        }
        if (isset($body['muted'])) {
            if (!in_array((string) $body['muted'], ['0', '1'], true)) {
                json_error(t('api.bad_audio'), 422);
            }
            $fields[] = 'muted = ?';
            $params[] = (int) $body['muted'];
        }
        if (!$fields) {
            json_error(t('api.nothing_to_update'), 422);
        }

        $params[] = $id;
        $pdo->prepare('UPDATE media SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        touch_gallery((int) $galleryId);
        json_out(['ok' => true]);

    // Il media va nel cestino: il file resta su disco ed è ripristinabile
    case 'media_delete':
        require_cap('media.upload');
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT gallery_id FROM media WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $galleryId = $stmt->fetchColumn();
        if ($galleryId === false) {
            json_error(t('api.media_not_found'), 404);
        }
        trash_media($id, (int) current_user()['id']);
        // Schermi che usavano questo media come immagine di cortesia
        $pdo->prepare("UPDATE screens SET idle_mode = 'black', idle_media_id = NULL
                       WHERE idle_media_id = ?")->execute([$id]);
        touch_gallery((int) $galleryId);
        json_out(['ok' => true, 'trashed' => true]);

    case 'media_reorder':
        require_cap('media.edit');
        $galleryId = (int) ($body['gallery_id'] ?? 0);
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || !$ids) {
            json_error(t('api.missing_ids'), 422);
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
        require_cap('screen.manage');
        $screenId = (int) ($body['screen_id'] ?? 0);
        $ids = $body['gallery_ids'] ?? null;
        $stmt = $pdo->prepare('SELECT id FROM screens WHERE id = ?');
        $stmt->execute([$screenId]);
        if ($stmt->fetchColumn() === false) {
            json_error(t('api.screen_not_found'), 404);
        }
        if (!is_array($ids)) {
            json_error(t('api.missing_galleries'), 422);
        }

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM screen_galleries WHERE screen_id = ?')->execute([$screenId]);
        $stmt = $pdo->prepare('INSERT IGNORE INTO screen_galleries (screen_id, gallery_id, sort_order)
                               SELECT ?, id, ? FROM galleries WHERE id = ? AND deleted_at IS NULL');
        foreach (array_values($ids) as $position => $galleryId) {
            $stmt->execute([$screenId, $position, (int) $galleryId]);
        }
        $pdo->commit();
        json_out(['ok' => true]);

    // Correzione manuale della rotazione automatica, valida SOLO per
    // questo schermo. rotation assente/vuota = torna al calcolo
    // automatico (si cancella la riga, non si svuota — vedi schema.sql).
    case 'screen_media_override':
        require_cap('screen.manage');
        $screenId = (int) ($body['screen_id'] ?? 0);
        $mediaId  = (int) ($body['media_id'] ?? 0);
        $rotationRaw = $body['rotation'] ?? null;

        // Ha senso solo per un media davvero assegnato a quello schermo
        // tramite una sua galleria: evita override su coppie a caso
        $stmt = $pdo->prepare('SELECT 1 FROM media m
                               JOIN screen_galleries sg ON sg.gallery_id = m.gallery_id
                               WHERE sg.screen_id = ? AND m.id = ? AND m.deleted_at IS NULL');
        $stmt->execute([$screenId, $mediaId]);
        if ($stmt->fetchColumn() === false) {
            json_error(t('api.media_not_found'), 404);
        }

        if ($rotationRaw === null || $rotationRaw === '') {
            $pdo->prepare('DELETE FROM screen_media_overrides WHERE screen_id = ? AND media_id = ?')
                ->execute([$screenId, $mediaId]);
        } else {
            $rotation = (int) $rotationRaw;
            if (!in_array($rotation, [0, 90, 180, 270], true)) {
                json_error(t('api.bad_rotation'), 422);
            }
            $pdo->prepare('INSERT INTO screen_media_overrides (screen_id, media_id, rotation)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE rotation = VALUES(rotation)')
                ->execute([$screenId, $mediaId, $rotation]);
        }
        json_out(['ok' => true]);

    // Stato rotazione (automatica + eventuale correzione) di tutti i media
    // di una galleria PER UNO schermo specifico — usato dal selettore
    // "schermo di riferimento" nella scheda Contenuti della galleria, per
    // cambiare schermo senza ricaricare la pagina (vedi anche 'preview').
    case 'gallery_screen_rotations':
        require_cap('screen.manage');
        $galleryId = (int) ($_GET['gallery_id'] ?? 0);
        $screenId  = (int) ($_GET['screen_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM screens WHERE id = ?');
        $stmt->execute([$screenId]);
        $screen = $stmt->fetch();
        if ($screen === false) {
            json_error(t('api.screen_not_found'), 404);
        }
        // Ha senso solo se la galleria è davvero assegnata a questo schermo
        // (l'override si registra per la coppia schermo+media, vedi schema.sql)
        $stmt = $pdo->prepare('SELECT 1 FROM screen_galleries WHERE screen_id = ? AND gallery_id = ?');
        $stmt->execute([$screenId, $galleryId]);
        if ($stmt->fetchColumn() === false) {
            json_error(t('api.gallery_not_found'), 404);
        }

        $stmt = $pdo->prepare('SELECT m.id, m.type, m.filename, m.width, m.height,
                                       smo.rotation AS override_rotation
                               FROM media m
                               LEFT JOIN screen_media_overrides smo
                                      ON smo.screen_id = ? AND smo.media_id = m.id
                               WHERE m.gallery_id = ? AND m.deleted_at IS NULL');
        $stmt->execute([$screenId, $galleryId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $row = media_ensure_dimensions($row);
            $items[] = [
                'id'       => (int) $row['id'],
                'auto'     => effective_item_rotation($row, $screen, null),
                'override' => $row['override_rotation'] !== null ? (int) $row['override_rotation'] : null,
            ];
        }
        json_out(['items' => $items]);

    default:
        json_error(t('api.unknown_action'), 404);
}
