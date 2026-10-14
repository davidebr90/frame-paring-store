<?php
declare(strict_types=1);

// Cestino: media e gallerie non spariscono subito, restano recuperabili
// finché non si svuota il cestino o non scade la retention configurata.
// I file su disco vengono rimossi SOLO alla cancellazione definitiva.

// Le impostazioni si leggono una volta sola per richiesta: sono poche
// decine di righe interrogate da quasi ogni pagina. La cache sta qui, in
// un punto solo, perché scrittura e lettura DEVONO vederla uguale: una
// setting_set seguita da una setting_get dentro la stessa richiesta deve
// restituire il valore appena salvato.
function &settings_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT `key`, `value` FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache;
}

function setting_get(string $key, ?string $default = null): ?string
{
    $cache = &settings_cache();
    return $cache[$key] ?? $default;
}

function setting_set(string $key, ?string $value): void
{
    db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, $value]);

    // Senza questa riga il valore nuovo esiste nel database ma non per il
    // resto della richiesta che lo ha appena scritto. È un difetto che si
    // notava a malapena altrove, perché quasi tutte le pagine salvano e
    // poi rimandano il browser a ricaricare; qui no: la pagina Qualità
    // delle immagini salva e SUBITO rigenera le copie, e le rigenerava
    // con la qualità precedente, producendo file identici a quelli di
    // prima. Da fuori sembrava che il cursore non servisse a nulla.
    $cache = &settings_cache();
    $cache[$key] = $value;
}

// Sposta un media nel cestino (resta su disco)
function trash_media(int $mediaId, int $userId): bool
{
    $stmt = db()->prepare('UPDATE media SET deleted_at = NOW(), deleted_by = ?
                           WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$userId, $mediaId]);
    return $stmt->rowCount() === 1;
}

// Sposta una galleria nel cestino insieme ai suoi media ancora attivi.
// I media già nel cestino restano lì con la loro data originale.
function trash_gallery(int $galleryId, int $userId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT system_key FROM galleries WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    if ($row === false || $row['system_key'] !== null) {
        return false; // inesistente o di sistema
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE galleries SET deleted_at = NOW(), deleted_by = ? WHERE id = ?')
        ->execute([$userId, $galleryId]);
    $pdo->prepare('UPDATE media SET deleted_at = NOW(), deleted_by = ?
                   WHERE gallery_id = ? AND deleted_at IS NULL')
        ->execute([$userId, $galleryId]);
    $pdo->commit();
    return true;
}

// Ripristina un media. Se la sua galleria è nel cestino, torna a galla
// anche quella: un media senza galleria visibile sarebbe irraggiungibile.
function restore_media(int $mediaId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT gallery_id FROM media WHERE id = ? AND deleted_at IS NOT NULL');
    $stmt->execute([$mediaId]);
    $galleryId = $stmt->fetchColumn();
    if ($galleryId === false) {
        return;
    }
    $pdo->prepare('UPDATE media SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')
        ->execute([$mediaId]);
    $pdo->prepare('UPDATE galleries SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')
        ->execute([$galleryId]);
}

// Ripristina una galleria e i media eliminati insieme a lei (stesso istante)
function restore_gallery(int $galleryId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT deleted_at FROM galleries WHERE id = ? AND deleted_at IS NOT NULL');
    $stmt->execute([$galleryId]);
    $deletedAt = $stmt->fetchColumn();
    if ($deletedAt === false) {
        return;
    }
    $pdo->prepare('UPDATE galleries SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')
        ->execute([$galleryId]);
    $pdo->prepare('UPDATE media SET deleted_at = NULL, deleted_by = NULL
                   WHERE gallery_id = ? AND deleted_at = ?')
        ->execute([$galleryId, $deletedAt]);
}

// Utenti: stesso principio, restano ripristinabili con permessi e anagrafica
function trash_user(int $userId, int $byUserId): bool
{
    $stmt = db()->prepare('UPDATE users SET deleted_at = NOW(), deleted_by = ?, is_active = 0
                           WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$byUserId, $userId]);
    return $stmt->rowCount() === 1;
}

// Ripristina un utente disattivato: torna visibile ma NON riattivato,
// così chi lo ripristina decide consapevolmente se riabilitarne l'accesso
function restore_user(int $userId): void
{
    db()->prepare('UPDATE users SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')
        ->execute([$userId]);
}

function purge_user(int $userId): bool
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL');
    $stmt->execute([$userId]);
    return $stmt->rowCount() === 1;
}

// Cancellazione definitiva: rimuove i file da disco e le righe dal DB.
// $olderThanDays > 0 limita l'operazione agli elementi più vecchi.
// Ritorna [media, gallerie, byte] effettivamente rimossi.
function purge_trash(?int $mediaId = null, ?int $galleryId = null, int $olderThanDays = 0): array
{
    $pdo = db();
    $conds = ['deleted_at IS NOT NULL'];
    $params = [];

    if ($mediaId !== null) {
        $conds[] = 'id = ?';
        $params[] = $mediaId;
    }
    if ($galleryId !== null) {
        $conds[] = 'gallery_id = ?';
        $params[] = $galleryId;
    }
    if ($olderThanDays > 0) {
        $conds[] = 'deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params[] = $olderThanDays;
    }

    $stmt = $pdo->prepare('SELECT id, filename, filesize'
        . rendition_select_fields('media') . '
        FROM media WHERE ' . implode(' AND ', $conds));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $bytes = 0;
    $ids = [];
    foreach ($rows as $row) {
        $path = UPLOADS_DIR . '/' . basename($row['filename']);
        if (is_file($path)) {
            unlink($path);
        }
        // La copia ridotta segue l'originale: lasciarla la renderebbe un
        // file orfano che nessuna pulizia successiva saprebbe più
        // ricondurre a un media
        $bytes += (int) ($row['rendition_filesize'] ?? 0);
        rendition_delete($row);
        $bytes += (int) $row['filesize'];
        $ids[] = (int) $row['id'];
    }
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM media WHERE id IN ($in)")->execute($ids);
    }

    // Gallerie nel cestino ormai prive di media (anche cestinati)
    $gConds = ['g.deleted_at IS NOT NULL'];
    $gParams = [];
    if ($galleryId !== null) {
        $gConds[] = 'g.id = ?';
        $gParams[] = $galleryId;
    }
    if ($olderThanDays > 0) {
        $gConds[] = 'g.deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $gParams[] = $olderThanDays;
    }
    $sql = 'SELECT g.id FROM galleries g
            WHERE ' . implode(' AND ', $gConds) . '
              AND NOT EXISTS (SELECT 1 FROM media m WHERE m.gallery_id = g.id)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($gParams);
    $gIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($gIds) {
        $in = implode(',', array_fill(0, count($gIds), '?'));
        $pdo->prepare("DELETE FROM galleries WHERE id IN ($in)")->execute($gIds);
    }

    // Schermi rimasti senza immagine di cortesia
    $pdo->query("UPDATE screens SET idle_mode = 'black'
                 WHERE idle_mode = 'image' AND idle_media_id IS NULL");

    return ['media' => count($ids), 'galleries' => count($gIds), 'bytes' => $bytes];
}

// Pulizia automatica secondo la retention configurata. Chiamata a ogni
// accesso al CMS: su hosting condiviso non c'è un cron affidabile.
function trash_autoclean(): void
{
    $days = (int) setting_get('trash_retention_days', '30');
    if ($days <= 0) {
        return; // conservazione illimitata
    }
    // Al massimo una passata all'ora, per non appesantire ogni pagina
    $last = (int) setting_get('trash_autoclean_at', '0');
    if (time() - $last < 3600) {
        return;
    }
    setting_set('trash_autoclean_at', (string) time());
    purge_trash(null, null, $days);

    // Stessa regola per gli utenti cestinati
    db()->prepare('DELETE FROM users
                   WHERE deleted_at IS NOT NULL
                     AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)')
        ->execute([$days]);
}
