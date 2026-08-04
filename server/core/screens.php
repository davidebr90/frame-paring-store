<?php
declare(strict_types=1);

// Logica di pairing e playlist, condivisa tra API (dispositivi) e CMS (admin).

// Transizioni disponibili (whitelist unica per CMS e API).
// 'kenburns' anima anche durante l'esposizione delle immagini;
// 'random' fa scegliere all'app una transizione diversa a ogni cambio.
const TRANSITIONS = [
    'cut'         => 'Taglio secco',
    'fade'        => 'Dissolvenza (dal nero)',
    'crossfade'   => 'Dissolvenza incrociata',
    'slide_left'  => 'Scorri a sinistra',
    'slide_right' => 'Scorri a destra',
    'slide_up'    => 'Scorri in alto',
    'slide_down'  => 'Scorri in basso',
    'zoom_in'     => 'Zoom in',
    'zoom_out'    => 'Zoom out',
    'kenburns'    => 'Ken Burns',
    'random'      => 'Casuale',
];

// Etichette brevi per i menu stretti delle card media
const TRANSITIONS_SHORT = [
    'cut'         => 'Taglio',
    'fade'        => 'Dissolv. nero',
    'crossfade'   => 'Dissolv. incr.',
    'slide_left'  => 'Scorri ←',
    'slide_right' => 'Scorri →',
    'slide_up'    => 'Scorri ↑',
    'slide_down'  => 'Scorri ↓',
    'zoom_in'     => 'Zoom in',
    'zoom_out'    => 'Zoom out',
    'kenburns'    => 'Ken Burns',
    'random'      => 'Casuale',
];

// Crea uno schermo 'pending' con PIN univoco. Ritenta in caso di
// collisione sul codice (violazione della UNIQUE KEY, SQLSTATE 23000).
function create_pending_screen(?string $deviceInfo): array
{
    $pdo = db();

    // Pulizia dei tentativi di pairing scaduti e mai completati.
    // Gli schermi pre-creati dal CMS (metodo manuale) hanno claim_token
    // e non vanno toccati.
    $pdo->query("DELETE FROM screens
                 WHERE status = 'pending'
                   AND claim_token IS NULL
                   AND pairing_expires_at < NOW()");

    $expiresAt = date('Y-m-d H:i:s', time() + (int) config('pairing_ttl'));
    $pollToken = random_hex();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = random_pin();
        try {
            $pdo->prepare('INSERT INTO screens (pairing_code, pairing_expires_at, poll_token, device_info)
                           VALUES (?, ?, ?, ?)')
                ->execute([$code, $expiresAt, $pollToken, $deviceInfo]);

            return [
                'id'           => (int) $pdo->lastInsertId(),
                'pairing_code' => $code,
                'poll_token'   => $pollToken,
            ];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    json_error('Impossibile generare un codice di pairing, riprovare', 500);
}

// Chiamata dal CMS quando l'admin inserisce il PIN (o conferma via QR).
// Genera l'auth_token (il dispositivo lo ritirerà al prossimo polling)
// e restituisce l'id dello schermo, oppure null se il codice non è valido.
function confirm_pairing(string $code, string $method): ?int
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM screens
                           WHERE pairing_code = ?
                             AND status = 'pending'
                             AND pairing_expires_at >= NOW()");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        return null;
    }

    // pairing_expires_at diventa la finestra di RITIRO del token: se la TV
    // non lo ritira entro il TTL, il pairing scade e va rifatto (il
    // poll_token non resta valido a tempo indefinito)
    $stmt = $pdo->prepare("UPDATE screens
                           SET status = 'paired',
                               auth_token = ?,
                               pair_method = ?,
                               pairing_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                           WHERE id = ? AND status = 'pending'");
    $stmt->execute([random_hex(), $method, (int) config('pairing_ttl'), $id]);

    // Conferme simultanee dello stesso PIN: solo la prima passa
    if ($stmt->rowCount() !== 1) {
        return null;
    }

    return (int) $id;
}

// Salva un upload del CMS dentro una galleria e registra il media.
// Validazione MIME dal contenuto reale; con $imagesOnly accetta solo immagini.
// Ritorna [id, label] del nuovo media; esce con json_error in caso di problemi.
function store_uploaded_media(array $file, int $galleryId, bool $imagesOnly = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Upload fallito (file troppo grande per i limiti PHP dell\'hosting?)', 422);
    }
    if ($file['size'] > (int) config('max_upload_bytes')) {
        json_error('File oltre il limite consentito', 422);
    }

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
    ];
    $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
    if (!isset($allowed[$mime]) || ($imagesOnly && !str_starts_with($mime, 'image/'))) {
        json_error('Formato non supportato: ' . $mime, 422);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
        json_error('Impossibile salvare il file (permessi cartella uploads?)', 500);
    }

    $pdo = db();
    $originalName = mb_substr((string) $file['name'], 0, 255);

    // sort_order calcolato dentro la INSERT: upload concorrenti nella stessa
    // galleria non possono ottenere la stessa posizione
    $pdo->prepare('INSERT INTO media
                   (gallery_id, type, filename, original_name, mime_type, filesize, checksum, sort_order)
                   SELECT ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(m.sort_order), -1) + 1
                   FROM media m WHERE m.gallery_id = ? AND m.deleted_at IS NULL')
        ->execute([
            $galleryId,
            str_starts_with($mime, 'image/') ? 'image' : 'video',
            $filename,
            $originalName,
            $mime,
            $file['size'],
            sha1_file(UPLOADS_DIR . '/' . $filename),
            $galleryId,
        ]);
    $newId = (int) $pdo->lastInsertId(); // prima di altri statement

    $pdo->prepare('UPDATE galleries SET updated_at = NOW() WHERE id = ?')->execute([$galleryId]);

    return ['id' => $newId, 'label' => $originalName];
}

// Galleria di servizio per le immagini di cortesia. Identificata dal
// system_key (non dal nome, che l'utente potrebbe cambiare) e protetta da
// rinomina/eliminazione nel CMS. Creata al primo uso.
function courtesy_gallery_id(): int
{
    $pdo = db();
    $id = $pdo->query("SELECT id FROM galleries WHERE system_key = 'courtesy' AND deleted_at IS NULL LIMIT 1")
        ->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare("INSERT INTO galleries (name, system_key) VALUES (?, 'courtesy')")
        ->execute(['Immagini di cortesia']);
    return (int) $pdo->lastInsertId();
}

// Playlist di una singola galleria, nello stesso formato di quella degli
// schermi: serve all'anteprima nel CMS (nessuna fascia oraria, nessun idle).
function playlist_for_gallery(array $gallery): array
{
    $stmt = db()->prepare('SELECT m.*,
                                  COALESCE(m.transition_in,  :t1) AS trans_in,
                                  COALESCE(m.transition_out, :t2) AS trans_out
                           FROM media m
                           WHERE m.gallery_id = :gid AND m.deleted_at IS NULL
                           ORDER BY m.sort_order, m.id');
    $stmt->execute([
        't1'  => $gallery['transition_default'],
        't2'  => $gallery['transition_default'],
        'gid' => $gallery['id'],
    ]);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id'             => (int) $row['id'],
            'type'           => $row['type'],
            'mime'           => $row['mime_type'],
            'url'            => config('base_url') . '/api/v1/media.php?id=' . $row['id'],
            'filesize'       => (int) $row['filesize'],
            'checksum'       => $row['checksum'],
            'rotation'       => (int) $row['rotation'],
            'fit'            => $row['fit'],
            'duration'       => (int) $row['duration_seconds'],
            'transition_in'  => $row['trans_in'],
            'transition_out' => $row['trans_out'],
            'transition_ms'  => (int) $gallery['transition_duration_ms'],
            'muted'          => (bool) $row['muted'],
            'name'           => $row['original_name'],
        ];
    }

    return [
        'screen'        => ['id' => 0, 'name' => $gallery['name'], 'orientation' => 'landscape', 'inches' => null],
        'schedule'      => ['bands' => [], 'idle_mode' => 'black', 'idle_item' => null],
        'version'       => sha1(json_encode($items)),
        'poll_interval' => (int) config('poll_interval'),
        'items'         => $items,
    ];
}

// Playlist di uno schermo: gallerie assegnate in ordine, media in sort_order.
// 'version' cambia se cambia qualunque cosa rilevante per il player:
// l'app la confronta con quella in cache per decidere se aggiornare.
function playlist_for_screen(array $screen): array
{
    // COALESCE: il media eredita la transizione della galleria salvo override
    $stmt = db()->prepare('SELECT m.id, m.type, m.mime_type, m.filesize, m.checksum,
                                  m.rotation, m.fit, m.duration_seconds, m.muted,
                                  COALESCE(m.transition_in,  g.transition_default) AS transition_in,
                                  COALESCE(m.transition_out, g.transition_default) AS transition_out,
                                  g.transition_duration_ms
                           FROM media m
                           JOIN screen_galleries sg ON sg.gallery_id = m.gallery_id
                           JOIN galleries g        ON g.id = m.gallery_id
                           WHERE sg.screen_id = ?
                             AND m.deleted_at IS NULL AND g.deleted_at IS NULL
                           ORDER BY sg.sort_order, m.gallery_id, m.sort_order, m.id');
    $stmt->execute([$screen['id']]);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id'             => (int) $row['id'],
            'type'           => $row['type'],
            'mime'           => $row['mime_type'],
            'url'            => config('base_url') . '/api/v1/media.php?id=' . $row['id'],
            'filesize'       => (int) $row['filesize'],
            'checksum'       => $row['checksum'],
            'rotation'       => (int) $row['rotation'],
            'fit'            => $row['fit'],
            'duration'       => (int) $row['duration_seconds'],
            'transition_in'  => $row['transition_in'],
            'transition_out' => $row['transition_out'],
            'transition_ms'  => (int) $row['transition_duration_ms'],
            'muted'          => (bool) $row['muted'],
        ];
    }

    // Fasce orarie: la TV le valuta in locale (funziona anche offline).
    // Nessuna fascia = sempre attivo. Fuori fascia: nero o immagine di cortesia.
    $bands = [];
    if ($screen['schedule_json'] !== null) {
        $decoded = json_decode($screen['schedule_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $band) {
                if (isset($band['from'], $band['to'])
                    && preg_match('/^\d{2}:\d{2}$/', $band['from'])
                    && preg_match('/^\d{2}:\d{2}$/', $band['to'])) {
                    $bands[] = ['from' => $band['from'], 'to' => $band['to']];
                }
            }
        }
    }

    $idleItem = null;
    if ($screen['idle_mode'] === 'image' && $screen['idle_media_id'] !== null) {
        $stmt = db()->prepare("SELECT * FROM media WHERE id = ? AND type = 'image' AND deleted_at IS NULL");
        $stmt->execute([$screen['idle_media_id']]);
        if (($m = $stmt->fetch()) !== false) {
            $idleItem = [
                'id'       => (int) $m['id'],
                'type'     => 'image',
                'mime'     => $m['mime_type'],
                'url'      => config('base_url') . '/api/v1/media.php?id=' . $m['id'] . '&idle=1',
                'filesize' => (int) $m['filesize'],
                'checksum' => $m['checksum'],
                'rotation' => (int) $m['rotation'],
                'fit'      => $m['fit'],
            ];
        }
    }

    return [
        'screen' => [
            'id'          => (int) $screen['id'],
            'name'        => $screen['name'],
            'orientation' => $screen['orientation'],
            'inches'      => $screen['diagonal_inches'] !== null ? (int) $screen['diagonal_inches'] : null,
        ],
        'schedule' => [
            'bands'     => $bands,
            'idle_mode' => $screen['idle_mode'],
            'idle_item' => $idleItem,
        ],
        // La version copre TUTTO ciò che il player deve rispettare: media,
        // fasce orarie e riposo. Se cambia solo la programmazione, la TV
        // deve comunque accorgersene al primo polling.
        'version'       => sha1(json_encode([$items, $bands, $screen['idle_mode'], $idleItem])),
        'poll_interval' => (int) config('poll_interval'),
        'items'         => $items,
    ];
}
