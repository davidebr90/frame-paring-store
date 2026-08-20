<?php
declare(strict_types=1);

// Configurazione email: di norma usa mail() nativa di PHP (funziona da sola
// su molto hosting condiviso), oppure — se abilitato qui — un server SMTP
// esterno più affidabile. Serve per il reset della verifica in due
// passaggi via link, ed è pronta per altri usi futuri.
require_once __DIR__ . '/_cms.php';
require_cap('settings.manage');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'save') {
        $enabled = isset($_POST['smtp_enabled']) ? '1' : '0';
        $host = trim((string) ($_POST['smtp_host'] ?? ''));
        $port = (int) ($_POST['smtp_port'] ?? 587);
        $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
        $user = trim((string) ($_POST['smtp_user'] ?? ''));
        $pass = (string) ($_POST['smtp_pass'] ?? '');
        $fromEmail = trim((string) ($_POST['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($_POST['smtp_from_name'] ?? ''));

        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $errors[] = t('mail.err_encryption');
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = t('mail.err_port');
        }
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('mail.err_from_email');
        }
        if ($enabled === '1' && $host === '') {
            $errors[] = t('mail.err_host_required');
        }

        if (!$errors) {
            setting_set('smtp_enabled', $enabled);
            setting_set('smtp_host', $host);
            setting_set('smtp_port', (string) $port);
            setting_set('smtp_encryption', $encryption);
            setting_set('smtp_user', $user);
            // Il campo si mostra sempre vuoto: lasciarlo vuoto al salvataggio
            // mantiene la password già configurata invece di cancellarla
            if ($pass !== '') {
                setting_set('smtp_pass', $pass);
            }
            setting_set('smtp_from_email', $fromEmail);
            setting_set('smtp_from_name', $fromName);
            flash_set('ok', t('mail.saved'));
            redirect('mail.php');
        }
    } elseif ($do === 'test') {
        $me = current_user();
        if (empty($me['email'])) {
            flash_set('err', t('mail.err_no_own_email'));
        } elseif (send_mail(
            $me['email'],
            t('mail.test_subject', ['name' => branding('brand_name')]),
            t('mail.test_body', ['name' => branding('brand_name')])
        )) {
            flash_set('ok', t('mail.test_sent', ['email' => $me['email']]));
        } else {
            flash_set('err', t('mail.test_failed', ['error' => (string) mail_last_error()]));
        }
        redirect('mail.php');
    }
}

$s = mail_settings();

cms_header(t('mail.title'), 'mail');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('mail.title')) ?></h1>
    <div class="sub"><?= e(t('mail.subtitle')) ?></div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">

  <div class="card">
    <h2><?= e(t('mail.card')) ?></h2>
    <p class="hint"><?= e(t('mail.hint')) ?></p>
    <div class="field">
      <label class="switch" for="smtp_enabled">
        <input type="checkbox" id="smtp_enabled" name="smtp_enabled" value="1"<?= $s['smtp_enabled'] ? ' checked' : '' ?> data-switch>
        <span class="switch-track"></span>
        <span class="switch-label">
          <span><?= e(t('mail.use_smtp')) ?></span>
          <span class="switch-state" data-switch-state
                data-on="<?= e(t('mail.smtp_on')) ?>" data-off="<?= e(t('mail.smtp_off')) ?>"><?=
            e($s['smtp_enabled'] ? t('mail.smtp_on') : t('mail.smtp_off')) ?></span>
        </span>
      </label>
    </div>

    <div class="field-grid">
      <div class="field">
        <label for="smtp_host"><?= e(t('mail.host')) ?></label>
        <input type="text" id="smtp_host" name="smtp_host" maxlength="190"
               placeholder="smtp.example.com" value="<?= e($s['smtp_host']) ?>">
      </div>
      <div class="field">
        <label for="smtp_port"><?= e(t('mail.port')) ?></label>
        <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
               value="<?= e((string) $s['smtp_port']) ?>">
      </div>
      <div class="field">
        <label for="smtp_encryption"><?= e(t('mail.encryption')) ?></label>
        <select id="smtp_encryption" name="smtp_encryption">
          <option value="tls"<?= $s['smtp_encryption'] === 'tls' ? ' selected' : '' ?>><?= e(t('mail.encryption_tls')) ?></option>
          <option value="ssl"<?= $s['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>><?= e(t('mail.encryption_ssl')) ?></option>
          <option value="none"<?= $s['smtp_encryption'] === 'none' ? ' selected' : '' ?>><?= e(t('mail.encryption_none')) ?></option>
        </select>
      </div>
      <div class="field">
        <label for="smtp_user"><?= e(t('mail.user')) ?></label>
        <input type="text" id="smtp_user" name="smtp_user" maxlength="190" autocomplete="off"
               value="<?= e($s['smtp_user']) ?>">
      </div>
      <div class="field">
        <label for="smtp_pass"><?= e(t('mail.pass')) ?></label>
        <input type="password" id="smtp_pass" name="smtp_pass" maxlength="255" autocomplete="new-password"
               placeholder="<?= e($s['smtp_pass'] !== '' ? t('mail.pass_unchanged') : '') ?>">
      </div>
    </div>

    <div class="field-grid">
      <div class="field">
        <label for="smtp_from_email"><?= e(t('mail.from_email')) ?></label>
        <input type="email" id="smtp_from_email" name="smtp_from_email" maxlength="190"
               placeholder="no-reply@example.com" value="<?= e($s['smtp_from_email']) ?>">
      </div>
      <div class="field">
        <label for="smtp_from_name"><?= e(t('mail.from_name')) ?></label>
        <input type="text" id="smtp_from_name" name="smtp_from_name" maxlength="120"
               placeholder="<?= e(branding('brand_name')) ?>" value="<?= e($s['smtp_from_name']) ?>">
      </div>
    </div>

    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button></div>
  </div>
</form>

<div class="card">
  <h2><?= e(t('mail.test_card')) ?></h2>
  <p class="hint"><?= t('mail.test_hint', [
    'email' => '<strong>' . e((string) (current_user()['email'] ?? '')) . '</strong>',
  ]) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="test">
    <button class="btn" type="submit"><?= e(t('mail.test_send')) ?></button>
  </form>
</div>
<?php cms_footer();
