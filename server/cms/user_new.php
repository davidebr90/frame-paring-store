<?php
declare(strict_types=1);

// Creazione utente: anagrafica, credenziali e ruolo.
require __DIR__ . '/_cms.php';
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
        $errors[] = 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore';
    }
    if (strlen($password) < 10) {
        $errors[] = 'La password deve avere almeno 10 caratteri';
    } elseif ($password !== $confirm) {
        $errors[] = 'Le due password non coincidono';
    }
    if (!isset(ROLE_LABELS[$form['role']])) {
        $errors[] = 'Ruolo non valido';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo email non valido';
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
            flash_set('ok', 'Utente creato: ora puoi rifinirne i permessi');
            redirect('user_edit.php?id=' . (int) $pdo->lastInsertId());
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // Può essere un utente nel cestino: l'username resta occupato
                $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND deleted_at IS NOT NULL');
                $check->execute([$form['username']]);
                $errors[] = $check->fetchColumn() !== false
                    ? 'Un utente con questo username è nel cestino: ripristinalo o eliminalo definitivamente da Contenuti → Cestino'
                    : 'Esiste già un utente con questo username';
            } else {
                throw $e;
            }
        }
    }
}

cms_header('Crea utente', 'users.new');
?>
<div class="main-head">
  <div>
    <h1>Crea utente</h1>
    <div class="sub">I permessi di dettaglio si rifiniscono dopo la creazione</div>
  </div>
  <a class="btn" href="users.php">← Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" autocomplete="off">
  <?= csrf_field() ?>

  <div class="card">
    <h2>Anagrafica</h2>
    <div class="field-grid">
      <div class="field">
        <label for="full_name">Nome e cognome</label>
        <input type="text" id="full_name" name="full_name" maxlength="120"
               value="<?= e($form['full_name']) ?>" placeholder="Mario Rossi">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" maxlength="190"
               value="<?= e($form['email']) ?>" placeholder="mario@azienda.it">
      </div>
      <div class="field">
        <label for="phone">Telefono</label>
        <input type="text" id="phone" name="phone" maxlength="40"
               value="<?= e($form['phone']) ?>" placeholder="+39 …">
      </div>
      <div class="field">
        <label for="note">Note</label>
        <input type="text" id="note" name="note" maxlength="255"
               value="<?= e($form['note']) ?>" placeholder="Reparto, sede, riferimenti…">
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Accesso</h2>
    <div class="field-grid">
      <div class="field">
        <label for="username">Username <span class="req">*</span></label>
        <input type="text" id="username" name="username" maxlength="50" required
               value="<?= e($form['username']) ?>" placeholder="mario.rossi">
      </div>
      <div class="field">
        <label for="role">Ruolo <span class="req">*</span></label>
        <select id="role" name="role">
          <?php foreach (ROLE_LABELS as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $form['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="password">Password <span class="req">*</span></label>
        <input type="password" id="password" name="password" minlength="10" required
               placeholder="minimo 10 caratteri">
      </div>
      <div class="field">
        <label for="confirm">Conferma password <span class="req">*</span></label>
        <input type="password" id="confirm" name="confirm" minlength="10" required>
      </div>
    </div>

    <div class="role-legend">
      <?php foreach (ROLE_CAPABILITIES as $key => $caps): ?>
      <div class="role-legend-item role-<?= e($key) ?>">
        <strong><?= e(ROLE_LABELS[$key]) ?></strong>
        <span><?= $caps === null
            ? 'tutti i permessi'
            : implode(', ', array_map(fn (string $c): string => mb_strtolower(CAPABILITIES[$c]), $caps)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Crea utente</button>
    <a class="btn" href="users.php">Annulla</a>
  </div>
</form>
<?php cms_footer();