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
    return (bool) db()->query('SELECT 1 FROM users WHERE deleted_at IS NULL LIMIT 1')->fetchColumn();
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (isset($_SESSION['user_id'])) {
            // is_active: un utente disattivato perde l'accesso all'istante,
            // anche se ha una sessione aperta
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        }
    }
    return $user;
}

// Esiti del tentativo di accesso. Il blocco è un esito a sé: la pagina
// deve poter dire "riprova più tardi" invece di "credenziali non valide".
const LOGIN_OK = 0;
const LOGIN_BAD = 1;
const LOGIN_LOCKED = 2;
// Password corretta, ma l'account ha la verifica in due passaggi: manca
// ancora il codice prima di potersi dire davvero autenticati
const LOGIN_NEED_2FA = 3;

// Dopo quanti errori scatta il blocco, e per quanto
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 10;

// La chiave lega username e indirizzo: bloccare il solo username
// permetterebbe a chiunque di chiudere fuori l'amministratore vero, e il
// solo indirizzo non ferma un attacco distribuito su un utente.
function login_attempt_key(string $username): string
{
    return sha1(mb_strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function login_locked(string $username): bool
{
    $stmt = db()->prepare('SELECT attempts, last_at FROM login_attempts WHERE attempt_key = ?');
    $stmt->execute([login_attempt_key($username)]);
    $row = $stmt->fetch();
    return $row !== false
        && (int) $row['attempts'] >= LOGIN_MAX_ATTEMPTS
        && strtotime($row['last_at']) > time() - LOGIN_LOCK_MINUTES * 60;
}

function login_record_failure(string $username): void
{
    // Un blocco scaduto riparte da 1 invece di restare gonfio per sempre
    db()->prepare('INSERT INTO login_attempts (attempt_key, attempts, last_at)
                   VALUES (?, 1, NOW())
                   ON DUPLICATE KEY UPDATE
                       attempts = IF(last_at < NOW() - INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE, 1, attempts + 1),
                       last_at = NOW()')
        ->execute([login_attempt_key($username)]);
    // Pulizia opportunistica delle righe ormai irrilevanti
    db()->query('DELETE FROM login_attempts WHERE last_at < NOW() - INTERVAL 1 DAY');
}

function attempt_login(string $username, string $password): int
{
    if (login_locked($username)) {
        // Niente verifica della password: durante il blocco non si offre
        // nemmeno l'oracolo del tempo di risposta
        return LOGIN_LOCKED;
    }

    $stmt = db()->prepare('SELECT id, password_hash, totp_enabled FROM users WHERE username = ? AND is_active = 1 AND deleted_at IS NULL');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($password, $row['password_hash'])) {
        login_record_failure($username);
        // Rallenta anche il singolo tentativo
        usleep(random_int(300000, 800000));
        return login_locked($username) ? LOGIN_LOCKED : LOGIN_BAD;
    }

    if ((int) $row['totp_enabled'] === 1) {
        // La password da sola non basta: il blocco anti forza bruta resta
        // attivo (login_attempts non si azzera) finché non arriva anche il
        // codice giusto in attempt_totp(), e la sessione non ha ancora
        // user_id — un browser fermo su questa pagina non è "dentro"
        $_SESSION['pending_2fa_user_id'] = (int) $row['id'];
        $_SESSION['pending_2fa_username'] = $username;
        return LOGIN_NEED_2FA;
    }

    db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')
        ->execute([login_attempt_key($username)]);

    // Il token CSRF nato prima del login non deve sopravvivere all'elevazione
    // di privilegio: si rigenera insieme all'ID di sessione
    unset($_SESSION['csrf']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);
    return LOGIN_OK;
}

// Secondo passo del login quando l'account ha la verifica in due passaggi
// attiva. Stessa struttura di attempt_login(), stesso blocco anti forza
// bruta (la chiave è la stessa username+IP: la password da sola già
// consumava un tentativo se sbagliata, il codice sbagliato ne consuma un
// altro — un attaccante con la password rubata non ha un canale a parte).
function attempt_totp(string $username, string $code): int
{
    if (login_locked($username)) {
        return LOGIN_LOCKED;
    }

    $id = $_SESSION['pending_2fa_user_id'] ?? null;
    $stmt = db()->prepare('SELECT id, totp_secret FROM users
                           WHERE id = ? AND username = ? AND is_active = 1 AND deleted_at IS NULL AND totp_enabled = 1');
    $stmt->execute([$id, $username]);
    $row = $stmt->fetch();

    if ($row === false || !totp_verify((string) $row['totp_secret'], $code)) {
        login_record_failure($username);
        usleep(random_int(300000, 800000));
        return login_locked($username) ? LOGIN_LOCKED : LOGIN_BAD;
    }

    db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')
        ->execute([login_attempt_key($username)]);

    unset($_SESSION['csrf'], $_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);
    return LOGIN_OK;
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
