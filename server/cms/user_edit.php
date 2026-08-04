<?php
declare(strict_types=1);

// Gestione di un singolo utente: anagrafica, ruolo, permessi, stato,
// reset password ed eliminazione.
require __DIR__ . '/_cms.php';
require_cap('users.manage');

$pdo = db();
$me = current_user();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if ($user === false) {
    flash_set('err', 'Utente non trovato');
    redirect('users.php');
}
$isMe = (int) $user['id'] === (int) $me['id'];

function active_admins(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")
        ->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $phone    = trim((string) ($_POST['phone'] ?? ''));
        $note     = trim((string) ($_POST['note'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'Indirizzo email non valido');
        } else {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, note = ? WHERE id = ?')
                ->execute([
                    $fullName !== '' ? $fullName : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $note !== '' ? $note : null,
                    $id,
                ]);
            flash_set('ok', 'Anagrafica aggiornata');
        }
    } elseif ($do === 'permissions') {
        $role = (string) ($_POST['role'] ?? $user['role']);
        if (!isset(ROLE_LABELS[$role])) {
            $role = $user['role'];
        }
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Nessuno può togliersi l'accesso da solo, e almeno un
        // amministratore attivo deve sempre restare
        if ($isMe && ($role !== 'admin' || $isActive === 0)) {
            flash_set('err', 'Non puoi rimuovere il tuo ruolo di amministratore né disattivarti');
            redirect('user_edit.php?id=' . $id);
        }
        if ($user['role'] === 'admin' && ($role !== 'admin' || $isActive === 0) && active_admins($pdo) <= 1) {
            flash_set('err', 'Deve restare almeno un amministratore attivo');
            redirect('user_edit.php?id=' . $id);
        }

        // Permessi personalizzati solo per i ruoli diversi da admin.
        // Se coincidono col set del ruolo si salva NULL (eredita).
        $caps = null;
        if ($role !== 'admin') {
            $posted = array_values(array_intersect((array) ($_POST['caps'] ?? []), array_keys(CAPABILITIES)));
            $roleDefault = ROLE_CAPABILITIES[$role] ?? [];
            sort($posted);
            $sortedDefault = $roleDefault;
            sort($sortedDefault);
            $caps = $posted === $sortedDefault ? null : json_encode($posted);
        }

        $pdo->prepare('UPDATE users SET role = ?, capabilities_json = ?, is_active = ? WHERE id = ?')
            ->execute([$role, $caps, $isActive, $id]);
        flash_set('ok', 'Ruolo e permessi aggiornati');
    } elseif ($do === 'password') {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');
        if (strlen($password) < 10) {
            flash_set('err', 'La password deve avere almeno 10 caratteri');
        } elseif ($password !== $confirm) {
            flash_set('err', 'Le due password non coincidono');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash_set('ok', 'Password reimpostata');
        }
    } elseif ($do === 'delete') {
        if ($isMe) {
            flash_set('err', 'Non puoi eliminare te stesso');
        } elseif ($user['role'] === 'admin' && active_admins($pdo) <= 1) {
            flash_set('err', 'Deve restare almeno un amministratore attivo');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash_set('ok', 'Utente eliminato');
            redirect('users.php');
        }
    }
    redirect('user_edit.php?id=' . $id);
}

$caps = user_capabilities($user);
$isCustom = $user['capabilities_json'] !== null;

cms_header($user['full_name'] ?: $user['username'], 'users');
?>
<div class="main-head">
  <div>
    <h1><?= e($user['full_name'] ?: $user['username']) ?></h1>
    <div class="sub">
      @<?= e($user['username']) ?> ·
      <?= e(ROLE_LABELS[$user['role']] ?? $user['role']) ?> ·
      creato il <?= date('d/m/Y', strtotime($user['created_at'])) ?> ·
      ultimo accesso <?= $user['last_login_at'] ? date('d/m/Y H:i', strtotime($user['last_login_at'])) : 'mai' ?>
    </div>
  </div>
  <a class="btn" href="users.php">← Torna alla lista</a>
</div>

<div class="card">
  <h2>Anagrafica</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile">
    <div class="field-grid">
      <div class="field">
        <label for="full_name">Nome e cognome</label>
        <input type="text" id="full_name" name="full_name" maxlength="120" value="<?= e($user['full_name']) ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" maxlength="190" value="<?= e($user['email']) ?>">
      </div>
      <div class="field">
        <label for="phone">Telefono</label>
        <input type="text" id="phone" name="phone" maxlength="40" value="<?= e($user['phone']) ?>">
      </div>
      <div class="field">
        <label for="note">Note</label>
        <input type="text" id="note" name="note" maxlength="255" value="<?= e($user['note']) ?>">
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Salva anagrafica</button></div>
  </form>
</div>

<div class="card">
  <h2>Ruolo e permessi</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="permissions">
    <div class="field-grid">
      <div class="field">
        <label for="role">Ruolo</label>
        <select id="role" name="role" data-role-select>
          <?php foreach (ROLE_LABELS as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $user['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Stato account</label>
        <label class="check-inline">
          <input type="checkbox" name="is_active" value="1"<?= $user['is_active'] ? ' checked' : '' ?>>
          Account attivo (può accedere al CMS)
        </label>
      </div>
    </div>

    <div class="caps-block" data-caps<?= $user['role'] === 'admin' ? ' hidden' : '' ?>>
      <p class="hint">
        Permessi <?= $isCustom ? '<strong>personalizzati</strong>' : 'ereditati dal ruolo' ?>.
        Modificandoli si scostano dal set predefinito; riportandoli identici al ruolo tornano a essere ereditati.
      </p>
      <div class="caps-grid">
        <?php foreach (CAPABILITIES as $key => $label): ?>
        <label class="check-inline">
          <input type="checkbox" name="caps[]" value="<?= e($key) ?>"<?= in_array($key, $caps, true) ? ' checked' : '' ?>>
          <?= e($label) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="hint" data-admin-note<?= $user['role'] === 'admin' ? '' : ' hidden' ?>>
      L'amministratore ha sempre tutti i permessi: non sono personalizzabili.
    </p>

    <div class="form-actions"><button class="btn btn-primary" type="submit">Salva permessi</button></div>
  </form>
</div>

<div class="card">
  <h2>Password</h2>
  <p class="hint">Reimposta la password di accesso di questo utente.</p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <div class="field-grid">
      <div class="field">
        <label for="password">Nuova password</label>
        <input type="password" id="password" name="password" minlength="10" required placeholder="minimo 10 caratteri">
      </div>
      <div class="field">
        <label for="confirm">Conferma</label>
        <input type="password" id="confirm" name="confirm" minlength="10" required>
      </div>
    </div>
    <div class="form-actions"><button class="btn" type="submit">Reimposta password</button></div>
  </form>
</div>

<?php if (!$isMe): ?>
<div class="card">
  <h2>Zona pericolosa</h2>
  <p class="hint">L'eliminazione è definitiva. Per sospendere temporaneamente l'accesso
  è preferibile disattivare l'account.</p>
  <form method="post" onsubmit="return confirm('Eliminare definitivamente questo utente?')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="delete">
    <button class="btn btn-danger" type="submit">Elimina utente</button>
  </form>
</div>
<?php endif; ?>
<?php cms_footer();