<?php
declare(strict_types=1);

// Gestione utenti: creazione, ruoli, capabilities personalizzate,
// attivazione/disattivazione, reset password. Richiede users.manage.
require __DIR__ . '/_cms.php';
require_cap('users.manage');

$pdo = db();
$me = current_user();

// Numero di amministratori attivi: non si può restare senza
function active_admins(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")
        ->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role     = (string) ($_POST['role'] ?? 'operator');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));

        if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) !== 1) {
            flash_set('err', 'Username: 3-50 caratteri (lettere, numeri, punto, trattino)');
        } elseif (strlen($password) < 10) {
            flash_set('err', 'La password deve avere almeno 10 caratteri');
        } elseif (!isset(ROLE_LABELS[$role])) {
            flash_set('err', 'Ruolo non valido');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'Indirizzo email non valido');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role)
                               VALUES (?, ?, ?, ?, ?)')
                    ->execute([
                        $username,
                        password_hash($password, PASSWORD_DEFAULT),
                        $fullName !== '' ? $fullName : null,
                        $email !== '' ? $email : null,
                        $role,
                    ]);
                flash_set('ok', 'Utente creato');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash_set('err', 'Username già esistente');
                } else {
                    throw $e;
                }
            }
        }
    } elseif ($do === 'update' && $id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target === false) {
            flash_set('err', 'Utente non trovato');
            redirect('users.php');
        }

        $role = (string) ($_POST['role'] ?? $target['role']);
        if (!isset(ROLE_LABELS[$role])) {
            $role = $target['role'];
        }
        // Capabilities personalizzate: solo per i non-admin
        $caps = null;
        if ($role !== 'admin' && isset($_POST['caps'])) {
            $posted = array_values(array_intersect((array) $_POST['caps'], array_keys(CAPABILITIES)));
            $caps = json_encode($posted);
        }
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Non ci si può togliere il proprio accesso, né lasciare il CMS
        // senza nemmeno un amministratore attivo
        if ($id === (int) $me['id'] && ($role !== 'admin' || $isActive === 0)) {
            flash_set('err', 'Non puoi rimuovere il tuo ruolo di amministratore o disattivarti');
            redirect('users.php');
        }
        if ($target['role'] === 'admin' && ($role !== 'admin' || $isActive === 0) && active_admins($pdo) <= 1) {
            flash_set('err', 'Deve restare almeno un amministratore attivo');
            redirect('users.php');
        }

        $pdo->prepare('UPDATE users SET role = ?, capabilities_json = ?, is_active = ? WHERE id = ?')
            ->execute([$role, $caps, $isActive, $id]);
        flash_set('ok', 'Utente aggiornato');
    } elseif ($do === 'reset_password' && $id > 0) {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 10) {
            flash_set('err', 'La password deve avere almeno 10 caratteri');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash_set('ok', 'Password reimpostata');
        }
    } elseif ($do === 'delete' && $id > 0) {
        if ($id === (int) $me['id']) {
            flash_set('err', 'Non puoi eliminare te stesso');
        } else {
            $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if ($target && $target['role'] === 'admin' && active_admins($pdo) <= 1) {
                flash_set('err', 'Deve restare almeno un amministratore attivo');
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash_set('ok', 'Utente eliminato');
            }
        }
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, username')->fetchAll();

cms_header('Utenti', 'users');
?>
<div class="main-head">
  <div>
    <h1>Utenti</h1>
    <div class="sub"><?= count($users) ?> registrati</div>
  </div>
</div>

<div class="card">
  <h2>Nuovo utente</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-row">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required maxlength="50" placeholder="es. mario.rossi">
      </div>
      <div class="field">
        <label for="full_name">Nome e cognome</label>
        <input type="text" id="full_name" name="full_name" maxlength="120">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="text" id="email" name="email" maxlength="190">
      </div>
    </div>
    <div class="form-row" style="margin-top:14px">
      <div class="field">
        <label for="password">Password (min 10 caratteri)</label>
        <input type="password" id="password" name="password" required minlength="10">
      </div>
      <div class="field">
        <label for="role">Ruolo</label>
        <select id="role" name="role">
          <?php foreach (ROLE_LABELS as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $key === 'operator' ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit">Crea utente</button>
    </div>
  </form>
</div>

<?php foreach ($users as $u):
    $caps = user_capabilities($u);
    $isMe = (int) $u['id'] === (int) $me['id'];
?>
<div class="card user-card">
  <div class="user-head">
    <div>
      <h2><?= e($u['full_name'] ?: $u['username']) ?>
        <?php if ($isMe): ?><span class="badge">tu</span><?php endif; ?>
        <?php if (!$u['is_active']): ?><span class="badge badge-revoked"><span class="led"></span>Disattivato</span><?php endif; ?>
      </h2>
      <div class="hint mono"><?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?></div>
      <div class="hint">Ultimo accesso: <?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'mai' ?></div>
    </div>
    <span class="badge"><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?></span>
  </div>

  <form method="post" class="user-form">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="update">
    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
    <div class="form-row">
      <div class="field" style="max-width:220px">
        <label>Ruolo</label>
        <select name="role" data-role-select>
          <?php foreach (ROLE_LABELS as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $u['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <label class="check-inline">
        <input type="checkbox" name="is_active" value="1"<?= $u['is_active'] ? ' checked' : '' ?>>
        Account attivo
      </label>
    </div>

    <div class="caps-grid" data-caps<?= $u['role'] === 'admin' ? ' hidden' : '' ?>>
      <?php foreach (CAPABILITIES as $key => $label): ?>
      <label class="check-inline">
        <input type="checkbox" name="caps[]" value="<?= e($key) ?>"<?= in_array($key, $caps, true) ? ' checked' : '' ?>>
        <?= e($label) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <p class="hint" data-admin-note<?= $u['role'] === 'admin' ? '' : ' hidden' ?>>
      L'amministratore ha sempre tutti i permessi.
    </p>

    <button class="btn btn-sm" type="submit" style="margin-top:12px">Salva permessi</button>
  </form>

  <div class="form-row" style="margin-top:16px">
    <form method="post" class="form-row" autocomplete="off" style="flex:1">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="reset_password">
      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <div class="field">
        <label>Reimposta password</label>
        <input type="password" name="password" minlength="10" placeholder="Nuova password (min 10)">
      </div>
      <button class="btn btn-sm" type="submit">Reimposta</button>
    </form>
    <?php if (!$isMe): ?>
    <form method="post" onsubmit="return confirm('Eliminare definitivamente questo utente?')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="delete">
      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <button class="btn btn-sm btn-danger" type="submit">Elimina utente</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php cms_footer();