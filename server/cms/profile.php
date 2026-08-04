<?php
declare(strict_types=1);

// Profilo personale: anagrafica e cambio password. Nessuna capability
// richiesta — ogni utente può gestire i propri dati (ma non il ruolo).
require __DIR__ . '/_cms.php';

$pdo = db();
$me = current_user();

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
        } elseif (mb_strlen($fullName) > 120 || mb_strlen($phone) > 40 || mb_strlen($note) > 255) {
            flash_set('err', 'Uno dei campi supera la lunghezza consentita');
        } else {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, note = ? WHERE id = ?')
                ->execute([
                    $fullName !== '' ? $fullName : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $note !== '' ? $note : null,
                    $me['id'],
                ]);
            flash_set('ok', 'Profilo aggiornato');
        }
    } elseif ($do === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            flash_set('err', 'Password attuale errata');
        } elseif (strlen($new) < 10) {
            flash_set('err', 'La nuova password deve avere almeno 10 caratteri');
        } elseif ($new !== $confirm) {
            flash_set('err', 'Le password non coincidono');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            flash_set('ok', 'Password aggiornata');
        }
    }
    redirect('profile.php');
}

$caps = user_capabilities($me);

cms_header('Profilo', '');
?>
<div class="main-head">
  <div>
    <h1>Il tuo profilo</h1>
    <div class="sub"><?= e($me['username']) ?> · <?= e(ROLE_LABELS[$me['role']] ?? $me['role']) ?></div>
  </div>
</div>

<div class="card">
  <h2>Anagrafica</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile">
    <div class="form-row">
      <div class="field">
        <label for="full_name">Nome e cognome</label>
        <input type="text" id="full_name" name="full_name" maxlength="120" value="<?= e($me['full_name']) ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input type="text" id="email" name="email" maxlength="190" value="<?= e($me['email']) ?>">
      </div>
    </div>
    <div class="form-row" style="margin-top:14px">
      <div class="field">
        <label for="phone">Telefono</label>
        <input type="text" id="phone" name="phone" maxlength="40" value="<?= e($me['phone']) ?>">
      </div>
      <div class="field">
        <label for="note">Note</label>
        <input type="text" id="note" name="note" maxlength="255" value="<?= e($me['note']) ?>">
      </div>
      <button class="btn btn-primary" type="submit">Salva</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Password</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <div class="form-row">
      <div class="field">
        <label for="current_password">Password attuale</label>
        <input type="password" id="current_password" name="current_password" required>
      </div>
      <div class="field">
        <label for="new_password">Nuova password</label>
        <input type="password" id="new_password" name="new_password" minlength="10" required>
      </div>
      <div class="field">
        <label for="confirm_password">Conferma</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="10" required>
      </div>
      <button class="btn" type="submit">Cambia password</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>I tuoi permessi</h2>
  <p class="hint">Assegnati dal ruolo <strong><?= e(ROLE_LABELS[$me['role']] ?? $me['role']) ?></strong><?= $me['capabilities_json'] !== null ? ' (personalizzati)' : '' ?>.</p>
  <ul class="cap-list">
    <?php foreach (CAPABILITIES as $key => $label): ?>
    <li class="<?= in_array($key, $caps, true) ? 'on' : 'off' ?>">
      <span class="cap-dot"></span><?= e($label) ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php cms_footer();