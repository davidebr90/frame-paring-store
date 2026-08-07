<?php
declare(strict_types=1);

// Primo avvio: crea l'utente amministratore. Raggiungibile solo
// finché la tabella users è vuota, poi reindirizza al login.
define('CMS_PUBLIC', true);
require_once __DIR__ . '/_cms.php';

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
        $error = t('setup.err_username');
    } elseif (strlen($password) < 10) {
        $error = t('setup.err_password');
    } elseif ($password !== $confirm) {
        $error = t('setup.err_mismatch');
    } else {
        // Il primo utente è sempre amministratore
        db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        attempt_login($username, $password); // LOGIN_OK: appena creato
        flash_set('ok', t('setup.welcome'));
        redirect('index.php');
    }
}
?><!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('setup.title')) ?> — <?= e(branding('brand_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="icon" href="<?= e(branding_favicon_url()) ?>" sizes="any">
<link rel="icon" type="image/png" href="assets/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/app.css">
<style>:root { --accent: <?= branding_accent() ?>; --accent-ink: <?= branding_accent_ink() ?>; --accent-soft: <?= branding_accent() ?>1f; }</style>
</head>
<body class="auth-body">
<div class="auth-card">
  <?php $langSelectClass = 'lang-auth'; require __DIR__ . '/_lang_select.php'; ?>
  <div class="auth-brand">
    <?php if ($logo = branding_logo_url()): ?>
      <img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e(branding('brand_name')) ?>" style="max-height:48px">
    <?php else: ?>
      <span class="brand-led"></span><?= e(mb_strtoupper(branding('brand_name'))) ?>
    <?php endif; ?>
  </div>
  <p class="auth-sub"><?= e(t('setup.subtitle')) ?></p>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username"><?= e(t('login.username')) ?></label>
      <input type="text" id="username" name="username" required autofocus
             value="<?= e($_POST['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password"><?= e(t('setup.password_hint')) ?></label>
      <input type="password" id="password" name="password" required minlength="10">
    </div>
    <div class="field">
      <label for="confirm"><?= e(t('setup.confirm')) ?></label>
      <input type="password" id="confirm" name="confirm" required minlength="10">
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('setup.submit')) ?></button>
  </form>
</div>
</body>
</html>
