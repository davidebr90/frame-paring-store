<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        json_error('Metodo non consentito', 405);
    }
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Token esadecimale a 256 bit (64 caratteri)
function random_hex(): string
{
    return bin2hex(random_bytes(32));
}

function random_pin(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Estrae il Bearer token: su hosting condiviso (CGI/FastCGI) l'header
// Authorization arriva solo se il .htaccess lo inoltra, con fallback.
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m) === 1) {
        return strtolower($m[1]);
    }
    return null;
}

// Autentica il dispositivo e aggiorna last_seen_at. Esce con 401/403 se non valido.
function require_device(): array
{
    $token = bearer_token();
    if ($token === null) {
        json_error('Token mancante o malformato', 401);
    }

    $stmt = db()->prepare('SELECT * FROM screens WHERE auth_token = ?');
    $stmt->execute([$token]);
    $screen = $stmt->fetch();

    if ($screen === false) {
        json_error('Token non valido', 401);
    }
    if ($screen['status'] !== 'paired') {
        json_error('Schermo revocato', 403);
    }

    db()->prepare('UPDATE screens SET last_seen_at = NOW() WHERE id = ?')
        ->execute([$screen['id']]);

    return $screen;
}
