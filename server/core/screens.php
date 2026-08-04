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

    $pdo->prepare("UPDATE screens
                   SET status = 'paired',
                       auth_token = ?,
                       pair_method = ?,
                       pairing_expires_at = NULL
                   WHERE id = ?")
        ->execute([random_hex(), $method, $id]);

    return (int) $id;
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

    return [
        'screen' => [
            'id'          => (int) $screen['id'],
            'name'        => $screen['name'],
            'orientation' => $screen['orientation'],
            'inches'      => $screen['diagonal_inches'] !== null ? (int) $screen['diagonal_inches'] : null,
        ],
        'version'       => sha1(json_encode($items)),
        'poll_interval' => (int) config('poll_interval'),
        'items'         => $items,
    ];
}
