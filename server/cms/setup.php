<?php
declare(strict_types=1);

// Primo avvio: crea l'utente amministratore. Raggiungibile solo
// finché la tabella users è vuota, poi reindirizza al login.
define('CMS_PUBLIC', true);
require __DIR__ . '/_cms.php';

if (users_exist()) {
    redirect('login.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) !== 1) {
        $error = 'Username: 3-50 caratteri, lettere, numeri, punto, trattino';
    } elseif (strlen($password) < 10) {
        $error = 'La password deve avere almeno 10 caratteri';
    } elseif ($password !== $confirm) {
        $error = 'Le password non coincidono';
    } else {
        db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        attempt_login($username, $password);
        flash_set('ok', 'Benvenuto! Amministratore creato.');
        redirect('index.php');
    }
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurazione iniziale — Frame Paring Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><span class="brand-led"></span>FRAME&nbsp;PARING&nbsp;<b>STORE</b></div>
  <p class="auth-sub">Configurazione iniziale — crea l'amministratore</p>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus
             value="<?= e($_POST['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Password (min 10 caratteri)</label>
      <input type="password" id="password" name="password" required minlength="10">
    </div>
    <div class="field">
      <label for="confirm">Conferma password</label>
      <input type="password" id="confirm" name="confirm" required minlength="10">
    </div>
    <button class="btn btn-primary" type="submit">Crea amministratore</button>
  </form>
</div>
</body>
</html>
