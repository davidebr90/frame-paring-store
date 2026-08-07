<?php
declare(strict_types=1);

// POST /api/v1/pair.php — la TV chiede un codice di pairing.
// Body JSON opzionale: {"device_info": "Fire TV Stick 4K / Fire OS 8"}
// Risposta: PIN da mostrare, URL da codificare nel QR (generato on-device),
// poll_token segreto con cui ritirare l'auth_token dopo la conferma admin.
require_once __DIR__ . '/_api.php';
require_method('POST');

$body = read_json_body();
$deviceInfo = isset($body['device_info'])
    ? mb_substr(trim((string) $body['device_info']), 0, 255)
    : null;

$screen = create_pending_screen($deviceInfo !== '' ? $deviceInfo : null);

json_out([
    'pairing_code'  => $screen['pairing_code'],
    'poll_token'    => $screen['poll_token'],
    // Consegnato ora, inerte finché l'admin non conferma: nel database
    // esiste solo la sua impronta e non potrebbe viaggiare più tardi
    'auth_token'    => $screen['auth_token'],
    'qr_url'        => config('base_url') . '/cms/pair.php?code=' . $screen['pairing_code'],
    'expires_in'    => (int) config('pairing_ttl'),
    'poll_interval' => (int) config('poll_interval'),
    // White label: testi e colore mostrati sulla schermata di configurazione
    'branding'      => [
        'title'    => branding_tv_title(),
        'subtitle' => branding('brand_tv_subtitle'),
        'accent'   => branding_accent(),
    ],
]);
