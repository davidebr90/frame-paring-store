<?php
declare(strict_types=1);

// POST /api/v1/claim.php — pairing manuale via URL dedicata.
// L'admin crea lo schermo nel CMS e ottiene una URL con claim_token;
// l'app la riceve nelle impostazioni, estrae il token e lo presenta qui.
// Il token è monouso: viene azzerato al primo claim andato a buon fine.
// Accetta il token sia come query string (?token=...) sia nel body JSON.
require_once __DIR__ . '/_api.php';
require_method('POST');

$body = read_json_body();
$claimToken = (string) ($_GET['token'] ?? $body['claim_token'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $claimToken) !== 1) {
    json_error('claim_token mancante o malformato', 422);
}

$deviceInfo = isset($body['device_info'])
    ? mb_substr(trim((string) $body['device_info']), 0, 255)
    : null;

// Qui il flusso è avviato dal dispositivo: il token nasce adesso, la
// risposta porta il chiaro e il database riceve solo l'impronta.
// Il claim_token invece resta leggibile nel database DI PROPOSITO: il CMS
// deve poter rimostrare la URL di configurazione finché non viene usata
// (è il senso del pre-provisioning). È monouso, vive solo su schermi
// pending e viene azzerato al primo utilizzo.
$authToken = random_hex();
$stmt = db()->prepare("UPDATE screens
                       SET status = 'paired',
                           auth_token = ?,
                           pair_method = 'manual',
                           claim_token = NULL,
                           device_info = COALESCE(?, device_info)
                       WHERE claim_token = ?
                         AND status = 'pending'");
$stmt->execute([token_hash($authToken), $deviceInfo !== '' ? $deviceInfo : null, $claimToken]);

if ($stmt->rowCount() !== 1) {
    json_error('URL di configurazione non valida o già utilizzata', 404);
}

$stmt = db()->prepare('SELECT id, name FROM screens WHERE auth_token = ?');
$stmt->execute([token_hash($authToken)]);
$row  = $stmt->fetch();
$name = (string) $row['name'];

screen_event_add((int) $row['id'], 'paired', 'manual');

json_out([
    'status'        => 'paired',
    'auth_token'    => $authToken,
    'screen_name'   => $name,
    'poll_interval' => (int) config('poll_interval'),
]);
