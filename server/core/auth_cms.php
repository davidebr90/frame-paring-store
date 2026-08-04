<?php
declare(strict_types=1);

// Sessioni, login e CSRF del CMS. Le API dei dispositivi non usano
// nulla di tutto questo: la loro autenticazione è il Bearer token.

function cms_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('fps_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function users_exist(): bool
{
    return (bool) db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (isset($_SESSION['user_id'])) {
            $stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        }
    }
    return $user;
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($password, $row['password_hash'])) {
        // Rallenta i tentativi di forza bruta
        usleep(random_int(300000, 800000));
        return false;
    }

    // Il token CSRF nato prima del login non deve sopravvivere all'elevazione
    // di privilegio: si rigenera insieme all'ID di sessione
    unset($_SESSION['csrf']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_hex();
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

// Valida il token da campo POST o header X-CSRF-Token (fetch JS).
// 403 e non 419: i codici non standard vengono convertiti in 500 da Apache.
function require_csrf(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        json_error('Sessione scaduta, ricaricare la pagina', 403);
    }
}
