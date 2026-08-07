<?php
declare(strict_types=1);

// Creazione utente: anagrafica, credenziali e ruolo.
require_once __DIR__ . '/_cms.php';
require_cap('users.manage');

$pdo = db();
$errors = [];
$form = [
    'username' => '', 'full_name' => '', 'email' => '',
    'phone' => '', 'note' => '', 'role' => 'operator',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($form as $key => $_) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $form['username']) !== 1) {
        $errors[] = t('unew.err_username');
    }
    if (strlen($password) < 10) {
        $errors[] = t('unew.err_password');
    } elseif ($password !== $confirm) {
        $errors[] = t('unew.err_mismatch');
    }
    if (!isset(ROLE_LABELS[$form['role']])) {
        $errors[] = t('unew.err_role');
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('unew.err_email');
    }

    if (!$errors) {
        try {
            $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, note, role)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $form['username'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $form['full_name'] !== '' ? $form['full_name'] : null,
                    $form['email'] !== '' ? $form['email'] : null,
                    $form['phone'] !== '' ? $form['phone'] : null,
                    $form['note'] !== '' ? $form['note'] : null,
                    $form['role'],
                ]);
            flash_set('ok', t('unew.created'));
            redirect('user_edit.php?id=' . (int) $pdo->lastInsertId());
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // Può essere un utente nel cestino: l'username resta occupato
                $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND deleted_at IS NOT NULL');
                $check->execute([$form['username']]);
                $errors[] = $check->fetchColumn() !== false
                    ? t('unew.err_trashed')
                    : t('unew.err_duplicate');
            } else {
                throw $e;
            }
        }
    }
}

cms_header(t('unew.title'), 'users.new');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('unew.title')) ?></h1>
    <div class="sub"><?= e(t('unew.subtitle')) ?></div>
  </div>
  <a class="btn" href="users.php"><?= e(t('unew.back')) ?></a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" autocomplete="off">
  <?= csrf_field() ?>

  <div class="card">
    <h2><?= e(t('prof.identity')) ?></h2>
    <div class="field-grid">
      <div class="field">
        <label for="full_name"><?= e(t('prof.full_name')) ?></label>
        <input type="text" id="full_name" name="full_name" maxlength="120"
               value="<?= e($form['full_name']) ?>" placeholder="<?= e(t('unew.name_ph')) ?>">
      </div>
      <div class="field">
        <label for="email"><?= e(t('prof.email')) ?></label>
        <input type="email" id="email" name="email" maxlength="190"
               value="<?= e($form['email']) ?>" placeholder="<?= e(t('unew.email_ph')) ?>">
      </div>
      <div class="field">
        <label for="phone"><?= e(t('prof.phone')) ?></label>
        <input type="text" id="phone" name="phone" maxlength="40"
               value="<?= e($form['phone']) ?>" placeholder="<?= e(t('unew.phone_ph')) ?>">
      </div>
      <div class="field">
        <label for="note">Note</label>
        <input type="text" id="note" name="note" maxlength="255"
               value="<?= e($form['note']) ?>" placeholder="<?= e(t('unew.note_ph')) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('unew.access')) ?></h2>
    <div class="field-grid">
      <div class="field">
        <label for="username"><?= e(t('login.username')) ?> <span class="req">*</span></label>
        <input type="text" id="username" name="username" maxlength="50" required
               value="<?= e($form['username']) ?>" placeholder="<?= e(t('unew.username_ph')) ?>">
      </div>
      <div class="field">
        <label for="role"><?= e(t('usr.role')) ?> <span class="req">*</span></label>
        <select id="role" name="role">
          <?php foreach (role_label_list() as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $form['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="password"><?= e(t('login.password')) ?> <span class="req">*</span></label>
        <input type="password" id="password" name="password" minlength="10" required
               placeholder="<?= e(t('unew.password_ph')) ?>">
      </div>
      <div class="field">
        <label for="confirm"><?= e(t('unew.confirm')) ?> <span class="req">*</span></label>
        <input type="password" id="confirm" name="confirm" minlength="10" required>
      </div>
    </div>

    <div class="role-legend">
      <?php foreach (ROLE_CAPABILITIES as $key => $caps): ?>
      <div class="role-legend-item role-<?= e($key) ?>">
        <strong><?= e(role_label($key)) ?></strong>
        <span><?= $caps === null
            ? t('unew.all_caps')
            : implode(', ', array_map(fn (string $c): string => mb_strtolower(cap_label($c)), $caps)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= e(t('unew.title')) ?></button>
    <a class="btn" href="users.php"><?= e(t('common.cancel')) ?></a>
  </div>
</form>
<?php cms_footer();