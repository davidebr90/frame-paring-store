<?php
declare(strict_types=1);

// Gestione di un singolo utente: anagrafica, ruolo, permessi, stato,
// reset password ed eliminazione.
require_once __DIR__ . '/_cms.php';
require_cap('users.manage');

$pdo = db();
$me = current_user();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$user = $stmt->fetch();
if ($user === false) {
    flash_set('err', t('ued.not_found'));
    redirect('users.php');
}
$isMe = (int) $user['id'] === (int) $me['id'];

function active_admins(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL")
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
            flash_set('err', t('prof.err_email'));
        } else {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, note = ? WHERE id = ?')
                ->execute([
                    $fullName !== '' ? $fullName : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $note !== '' ? $note : null,
                    $id,
                ]);
            flash_set('ok', t('ued.identity_saved'));
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
            flash_set('err', t('ued.err_self_admin'));
            redirect('user_edit.php?id=' . $id);
        }
        if ($user['role'] === 'admin' && ($role !== 'admin' || $isActive === 0) && active_admins($pdo) <= 1) {
            flash_set('err', t('ued.err_last_admin'));
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
        flash_set('ok', t('ued.perms_saved'));
    } elseif ($do === 'password') {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');
        if (strlen($password) < 10) {
            flash_set('err', t('unew.err_password'));
        } elseif ($password !== $confirm) {
            flash_set('err', t('unew.err_mismatch'));
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash_set('ok', t('ued.password_reset'));
        }
    } elseif ($do === 'reset_2fa') {
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_reset_token = NULL, totp_reset_expires_at = NULL WHERE id = ?')
            ->execute([$id]);
        flash_set('ok', t('ued.tfa_reset_done'));
    } elseif ($do === 'delete') {
        if ($isMe) {
            flash_set('err', t('ued.err_self_delete'));
        } elseif ($user['role'] === 'admin' && active_admins($pdo) <= 1) {
            flash_set('err', t('ued.err_last_admin'));
        } else {
            trash_user($id, (int) $me['id']);
            flash_set('ok', t('ued.trashed'));
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
    <div class="sub"><?= e(t('ued.header', [
      'username' => $user['username'],
      'role'     => role_label($user['role']),
      'created'  => date('d/m/Y', strtotime($user['created_at'])),
      'login'    => $user['last_login_at'] ? date('d/m/Y H:i', strtotime($user['last_login_at'])) : t('common.never'),
    ])) ?></div>
  </div>
  <a class="btn" href="users.php"><?= e(t('unew.back')) ?></a>
</div>

<div class="card">
  <h2><?= e(t('prof.identity')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile">
    <div class="field-grid">
      <div class="field">
        <label for="full_name"><?= e(t('prof.full_name')) ?></label>
        <input type="text" id="full_name" name="full_name" maxlength="120" value="<?= e($user['full_name']) ?>">
      </div>
      <div class="field">
        <label for="email"><?= e(t('prof.email')) ?></label>
        <input type="email" id="email" name="email" maxlength="190" value="<?= e($user['email']) ?>">
      </div>
      <div class="field">
        <label for="phone"><?= e(t('prof.phone')) ?></label>
        <input type="text" id="phone" name="phone" maxlength="40" value="<?= e($user['phone']) ?>">
      </div>
      <div class="field">
        <label for="note"><?= e(t('prof.note')) ?></label>
        <input type="text" id="note" name="note" maxlength="255" value="<?= e($user['note']) ?>">
      </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('ued.save_identity')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('ued.perms')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="permissions">
    <div class="field-grid">
      <div class="field">
        <label for="role"><?= e(t('usr.role')) ?></label>
        <select id="role" name="role" data-role-select>
          <?php foreach (role_label_list() as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $user['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="is_active"><?= e(t('ued.account_status')) ?></label>
        <label class="switch" for="is_active">
          <input type="checkbox" id="is_active" name="is_active" value="1"<?= $user['is_active'] ? ' checked' : '' ?> data-switch>
          <span class="switch-track"></span>
          <span class="switch-label">
            <span><?= e(t('ued.cms_access')) ?></span>
            <span class="switch-state" data-switch-state
                  data-on="<?= e(t('ued.can_access')) ?>" data-off="<?= e(t('ued.cannot_access')) ?>"><?=
              e($user['is_active'] ? t('ued.can_access') : t('ued.cannot_access')) ?></span>
          </span>
        </label>
      </div>
    </div>

    <div class="caps-block" data-caps<?= $user['role'] === 'admin' ? ' hidden' : '' ?>>
      <p class="hint"><?= t('ued.caps_custom', [
        'state' => $isCustom
            ? '<strong>' . e(t('ued.caps_custom_word')) . '</strong>'
            : e(t('ued.caps_inherited')),
      ]) ?></p>
      <div class="caps-grid">
        <?php foreach (capability_labels() as $key => $label): ?>
        <label class="check-inline">
          <input type="checkbox" name="caps[]" value="<?= e($key) ?>"<?= in_array($key, $caps, true) ? ' checked' : '' ?>>
          <?= e($label) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="hint" data-admin-note<?= $user['role'] === 'admin' ? '' : ' hidden' ?>>
      <?= e(t('ued.admin_note')) ?>
    </p>

    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('ued.save_perms')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('prof.password')) ?></h2>
  <p class="hint"><?= e(t('ued.password_hint')) ?></p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <div class="field-grid">
      <div class="field">
        <label for="password"><?= e(t('prof.new_password')) ?></label>
        <input type="password" id="password" name="password" minlength="10" required placeholder="<?= e(t('unew.password_ph')) ?>">
      </div>
      <div class="field">
        <label for="confirm"><?= e(t('prof.confirm')) ?></label>
        <input type="password" id="confirm" name="confirm" minlength="10" required>
      </div>
    </div>
    <div class="form-actions"><button class="btn" type="submit"><?= e(t('ued.reset_password')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('tfa.card')) ?></h2>
  <p class="hint">
    <?= $user['totp_enabled']
        ? e(t('ued.tfa_active'))
        : e(t('ued.tfa_inactive')) ?>
  </p>
  <?php if ($user['totp_enabled']): ?>
  <form method="post" onsubmit="return confirm('<?= e(t('ued.tfa_reset_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset_2fa">
    <button class="btn btn-danger" type="submit"><?= e(t('ued.tfa_reset_btn')) ?></button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$isMe): ?>
<div class="card">
  <h2><?= e(t('ued.danger')) ?></h2>
  <p class="hint"><?= e(t('ued.danger_hint')) ?></p>
  <form method="post" onsubmit="return confirm('<?= e(t('ued.delete_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="delete">
    <button class="btn btn-danger" type="submit"><?= e(t('ued.delete_btn')) ?></button>
  </form>
</div>
<?php endif; ?>
<?php cms_footer();