<?php
declare(strict_types=1);

define('CMS_PUBLIC', true);
require __DIR__ . '/_cms.php';

if (current_user() !== null) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (attempt_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
        redirect('index.php');
    }
    $error = 'Credenziali non valide';
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accedi — <?= e(branding('brand_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<style>:root { --accent: <?= branding_accent() ?>; --accent-ink: <?= branding_accent_ink() ?>; --accent-soft: <?= branding_accent() ?>1f; }</style>
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand">
    <?php if ($logo = branding_logo_url()): ?>
      <img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e(branding('brand_name')) ?>" style="max-height:48px">
    <?php else: ?>
      <span class="brand-led"></span><?= e(mb_strtoupper(branding('brand_name'))) ?>
    <?php endif; ?>
  </div>
  <p class="auth-sub"><?= e(branding('brand_tagline')) ?></p>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button class="btn btn-primary" type="submit">Accedi</button>
  </form>
</div>
</body>
</html>
