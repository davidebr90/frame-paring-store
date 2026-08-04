<?php
declare(strict_types=1);

// POST /api/v1/pair_status.php — polling della TV in attesa di conferma.
// Body JSON: {"poll_token": "<64 hex>"}
// Stati possibili: pending | paired (con auth_token, una sola volta) | expired.
// Alla consegna del token, pairing_code e poll_token vengono bruciati:
// se l'app perde la risposta deve ricominciare il pairing da capo.
require __DIR__ . '/_api.php';
require_method('POST');

$body = read_json_body();
$pollToken = (string) ($body['poll_token'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $pollToken) !== 1) {
    json_error('poll_token mancante o malformato', 422);
}

$stmt = db()->prepare('SELECT * FROM screens WHERE poll_token = ?');
$stmt->execute([$pollToken]);
$screen = $stmt->fetch();

if ($screen === false) {
    json_out(['status' => 'expired'], 404);
}

if ($screen['status'] === 'revoked') {
    json_error('Schermo revocato', 403);
}

if ($screen['status'] === 'pending') {
    if ($screen['pairing_expires_at'] !== null && strtotime($screen['pairing_expires_at']) < time()) {
        db()->prepare('DELETE FROM screens WHERE id = ?')->execute([$screen['id']]);
        json_out(['status' => 'expired'], 404);
    }
    json_out(['status' => 'pending', 'poll_interval' => (int) config('poll_interval')]);
}

// status = paired: la consegna del token ha una finestra limitata
if ($screen['pairing_expires_at'] !== null && strtotime($screen['pairing_expires_at']) < time()) {
    // Ritiro tardivo: si bruciano le credenziali e si riparte dal pairing
    db()->prepare("UPDATE screens
                   SET status = 'pending', auth_token = NULL, pair_method = NULL,
                       pairing_code = NULL, poll_token = NULL
                   WHERE id = ?")
        ->execute([$screen['id']]);
    json_out(['status' => 'expired'], 404);
}

// Consegna il token e brucia le credenziali temporanee
db()->prepare('UPDATE screens SET pairing_code = NULL, poll_token = NULL, pairing_expires_at = NULL WHERE id = ?')
    ->execute([$screen['id']]);

json_out([
    'status'        => 'paired',
    'auth_token'    => $screen['auth_token'],
    'screen_name'   => $screen['name'],
    'poll_interval' => (int) config('poll_interval'),
]);
