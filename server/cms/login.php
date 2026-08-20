<?php
declare(strict_types=1);

define('CMS_PUBLIC', true);
require_once __DIR__ . '/_cms.php';

if (current_user() !== null) {
    redirect('index.php');
}

$pending = $_SESSION['pending_2fa_user_id'] ?? null;
$error = null;
// login.php non passa da cms_header(), quindi non riceve il flash da solo:
// serve per messaggi come "verifica disattivata" dopo il reset via email
$notice = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === '2fa_cancel') {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
        redirect('login.php');
    } elseif ($pending !== null) {
        $esito = attempt_totp((string) ($_SESSION['pending_2fa_username'] ?? ''), (string) ($_POST['code'] ?? ''));
        if ($esito === LOGIN_OK) {
            redirect('index.php');
        }
        if ($esito === LOGIN_LOCKED) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
            $pending = null;
            $error = t('login.locked');
        } else {
            $error = t('login.tfa_error');
        }
    } else {
        $esito = attempt_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($esito === LOGIN_OK) {
            redirect('index.php');
        }
        if ($esito === LOGIN_NEED_2FA) {
            // PRG: un aggiornamento pagina non deve poter rimandare la
            // password, la sessione ricorda già di essere a metà login
            redirect('login.php');
        }
        $error = $esito === LOGIN_LOCKED ? t('login.locked') : t('login.error');
    }
}
?><!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('login.title')) ?> — <?= e(branding('brand_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="icon" href="<?= e(branding_favicon_url()) ?>" sizes="any">
<link rel="icon" type="image/png" href="assets/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
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
  <p class="auth-sub"><?= e(branding('brand_tagline')) ?></p>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="flash flash-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
  <?php if ($pending !== null): ?>
  <form method="post">
    <?= csrf_field() ?>
    <p class="hint"><?= e(t('login.tfa_hint')) ?></p>
    <div class="field">
      <label for="code"><?= e(t('login.tfa_code')) ?></label>
      <input type="text" id="code" name="code" class="pin-input" inputmode="numeric"
             autocomplete="one-time-code" placeholder="000000" maxlength="6" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('login.tfa_submit')) ?></button>
  </form>
  <form method="post" class="auth-secondary">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="2fa_cancel">
    <button class="btn btn-sm" type="submit"><?= e(t('login.tfa_back')) ?></button>
  </form>
  <a class="auth-link" href="totp_reset.php"><?= e(t('login.tfa_lost')) ?></a>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field">
      <label for="username"><?= e(t('login.username')) ?></label>
      <input type="text" id="username" name="username" required autofocus>
    </div>
    <div class="field">
      <label for="password"><?= e(t('login.password')) ?></label>
      <input type="password" id="password" name="password" required>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('login.submit')) ?></button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
