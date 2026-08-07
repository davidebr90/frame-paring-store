<?php
declare(strict_types=1);

// POST /api/v1/pair_status.php — polling della TV in attesa di conferma.
// Body JSON: {"poll_token": "<64 hex>"}
// Stati possibili: pending | paired (con auth_token, una sola volta) | expired.
// Alla consegna del token, pairing_code e poll_token vengono bruciati:
// se l'app perde la risposta deve ricominciare il pairing da capo.
require_once __DIR__ . '/_api.php';
require_method('POST');

$body = read_json_body();
$pollToken = (string) ($body['poll_token'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $pollToken) !== 1) {
    json_error('poll_token mancante o malformato', 422);
}

$stmt = db()->prepare('SELECT * FROM screens WHERE poll_token = ?');
$stmt->execute([token_hash($pollToken)]);
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

// status = paired: il dispositivo deve accorgersene entro la finestra.
// Il token ce l'ha già dall'avvio del pairing: qui non viaggia nulla.
if ($screen['pairing_expires_at'] !== null && strtotime($screen['pairing_expires_at']) < time()) {
    // Presa d'atto tardiva: la riga torna pending e la pulizia la rimuove;
    // il token che il dispositivo conserva muore con lei
    db()->prepare("UPDATE screens
                   SET status = 'pending', auth_token = NULL, pair_method = NULL,
                       pairing_code = NULL, poll_token = NULL
                   WHERE id = ?")
        ->execute([$screen['id']]);
    json_out(['status' => 'expired'], 404);
}

// Brucia le credenziali temporanee: da qui in poi vale solo l'auth_token
db()->prepare('UPDATE screens SET pairing_code = NULL, poll_token = NULL, pairing_expires_at = NULL WHERE id = ?')
    ->execute([$screen['id']]);

json_out([
    'status'        => 'paired',
    'screen_name'   => $screen['name'],
    'poll_interval' => (int) config('poll_interval'),
]);
