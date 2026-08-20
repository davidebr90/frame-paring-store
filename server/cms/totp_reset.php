<?php
declare(strict_types=1);

// Reset della verifica in due passaggi via link email: per chi ha perso
// l'accesso al proprio autenticatore e non può più completare il login.
// Pagina pubblica, in due tempi — GET non fa mai scrivere il database (un
// prefetch automatico del link da parte del client email non deve bruciare
// il token), solo la conferma esplicita in POST lo consuma.
define('CMS_PUBLIC', true);
require_once __DIR__ . '/_cms.php';

const TOTP_RESET_TTL_MINUTES = 30;

if (current_user() !== null) {
    redirect('index.php');
}

$pdo = db();
$notice = null;
$error = null;
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'request') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE username = ? AND is_active = 1 AND deleted_at IS NULL AND totp_enabled = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user !== false && !empty($user['email'])) {
            $rawToken = random_hex();
            $pdo->prepare('UPDATE users SET totp_reset_token = ?, totp_reset_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?')
                ->execute([token_hash($rawToken), TOTP_RESET_TTL_MINUTES, $user['id']]);
            $link = rtrim((string) config('base_url'), '/') . '/cms/totp_reset.php?token=' . $rawToken;
            send_mail(
                $user['email'],
                t('treset.mail_subject', ['name' => branding('brand_name')]),
                t('treset.mail_body', ['link' => $link, 'minutes' => (string) TOTP_RESET_TTL_MINUTES])
            );
        }
        // Stesso messaggio a prescindere dall'esito: altrimenti la pagina
        // diventerebbe un modo per scoprire quali username esistono
        $notice = t('treset.request_sent');
    } elseif ($do === 'confirm') {
        $stmt = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_reset_token = NULL, totp_reset_expires_at = NULL
                               WHERE totp_reset_token = ? AND totp_reset_expires_at >= NOW()');
        $stmt->execute([token_hash($token)]);
        if ($stmt->rowCount() === 1) {
            flash_set('ok', t('treset.done'));
            redirect('login.php');
        }
        $error = t('treset.err_invalid');
        $token = '';
    }
}

$showConfirm = false;
if ($token !== '' && $error === null) {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE totp_reset_token = ? AND totp_reset_expires_at >= NOW()');
    $stmt->execute([token_hash($token)]);
    if ($stmt->fetchColumn()) {
        $showConfirm = true;
    } else {
        $error = t('treset.err_invalid');
        $token = '';
    }
}
?><!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('treset.title')) ?> — <?= e(branding('brand_name')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="icon" href="<?= e(branding_favicon_url()) ?>" sizes="any">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
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
  <p class="auth-sub"><?= e(t('treset.subtitle')) ?></p>
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="flash flash-ok"><?= e($notice) ?></div><?php endif; ?>

  <?php if ($showConfirm): ?>
  <p class="hint"><?= e(t('treset.confirm_hint')) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="confirm">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <button class="btn btn-primary" type="submit"><?= e(t('treset.confirm_btn')) ?></button>
  </form>
  <?php elseif (!$notice): ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="request">
    <div class="field">
      <label for="username"><?= e(t('login.username')) ?></label>
      <input type="text" id="username" name="username" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('treset.request_btn')) ?></button>
  </form>
  <?php endif; ?>
  <a class="auth-link" href="login.php"><?= e(t('treset.back_login')) ?></a>
</div>
</body>
</html>
