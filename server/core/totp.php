<?php
declare(strict_types=1);

// Verifica in due passaggi (TOTP, RFC 6238) — implementazione autonoma,
// nessuna libreria esterna: solo hash_hmac(), già incluso in PHP. Coerente
// con la scelta di progetto di restare senza Composer/vendor.

function totp_secret_new(): string
{
    // 160 bit (20 byte), la lunghezza raccomandata da RFC 4226 §4 R6
    return totp_base32_encode(random_bytes(20));
}

function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    $bits = '';
    for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) {
            break;
        }
        $bytes .= chr(bindec($byte));
    }
    return $bytes;
}

// Codice a 6 cifre per l'istante dato (HOTP con contatore = tempo/30s)
function totp_code(string $secretBase32, ?int $timestamp = null, int $step = 30, int $digits = 6): string
{
    $timestamp ??= time();
    $counter = intdiv($timestamp, $step);
    $key = totp_base32_decode($secretBase32);
    $binCounter = pack('N2', 0, $counter); // 64 bit big-endian, metà alta sempre 0
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

// ±1 finestra (30s) di tolleranza: la deriva dell'orologio del telefono è
// la prima causa di "codice giusto rifiutato" nei report degli utenti
function totp_verify(string $secretBase32, string $code, int $window = 1): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretBase32, $now + $i * 30), $code)) {
            return true;
        }
    }
    return false;
}

// URI otpauth:// standard, letto da qualunque app authenticator via QR
function totp_provisioning_uri(string $secretBase32, string $accountLabel, string $issuer): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
    $query = http_build_query([
        'secret'    => $secretBase32,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => 6,
        'period'    => 30,
    ]);
    return 'otpauth://totp/' . $label . '?' . $query;
}
