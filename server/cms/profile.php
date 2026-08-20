<?php
declare(strict_types=1);

// Profilo personale: anagrafica e cambio password. Nessuna capability
// richiesta — ogni utente può gestire i propri dati (ma non il ruolo).
require_once __DIR__ . '/_cms.php';

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
            flash_set('err', t('prof.err_email'));
        } elseif (mb_strlen($fullName) > 120 || mb_strlen($phone) > 40 || mb_strlen($note) > 255) {
            flash_set('err', t('prof.err_length'));
        } else {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, note = ? WHERE id = ?')
                ->execute([
                    $fullName !== '' ? $fullName : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $note !== '' ? $note : null,
                    $me['id'],
                ]);
            flash_set('ok', t('prof.saved'));
        }
    } elseif ($do === 'language') {
        // Stringa vuota = torna a seguire la lingua predefinita del sistema
        $choice = (string) ($_POST['language'] ?? '');
        $value = isset(LANGUAGES[$choice]) ? $choice : null;
        $pdo->prepare('UPDATE users SET language = ? WHERE id = ?')->execute([$value, $me['id']]);
        // La sessione poteva avere una scelta temporanea diversa: si allinea
        unset($_SESSION['lang']);
        if ($value !== null) {
            $_SESSION['lang'] = $value;
        }
        flash_set('ok', t('profile.language_saved'));
    } elseif ($do === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            flash_set('err', t('prof.err_current'));
        } elseif (strlen($new) < 10) {
            flash_set('err', t('prof.err_short'));
        } elseif ($new !== $confirm) {
            flash_set('err', t('prof.err_mismatch'));
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            flash_set('ok', t('prof.password_saved'));
        }
    } elseif ($do === '2fa_start') {
        // Il segreto vive solo in sessione finché non è confermato da un
        // primo codice corretto: niente attivazioni a metà salvate su riga,
        // che lascerebbero l'utente convinto di essere protetto senza esserlo
        $_SESSION['totp_pending_secret'] = totp_secret_new();
    } elseif ($do === '2fa_confirm') {
        $pending = $_SESSION['totp_pending_secret'] ?? null;
        $code = (string) ($_POST['code'] ?? '');
        if ($pending === null) {
            flash_set('err', t('tfa.err_no_pending'));
        } elseif (!totp_verify($pending, $code)) {
            flash_set('err', t('tfa.err_code'));
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')
                ->execute([$pending, $me['id']]);
            unset($_SESSION['totp_pending_secret']);
            flash_set('ok', t('tfa.enabled'));
        }
    } elseif ($do === '2fa_cancel') {
        unset($_SESSION['totp_pending_secret']);
    } elseif ($do === '2fa_disable') {
        $current = (string) ($_POST['current_password'] ?? '');
        if (!password_verify($current, $me['password_hash'])) {
            flash_set('err', t('prof.err_current'));
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')
                ->execute([$me['id']]);
            flash_set('ok', t('tfa.disabled'));
        }
    }
    redirect('profile.php');
}

$caps = user_capabilities($me);
$systemLang = (string) (setting_get('default_language') ?: LANG_FALLBACK);
if (!isset(LANGUAGES[$systemLang])) {
    $systemLang = LANG_FALLBACK;
}

cms_header(t('prof.title'), 'profile');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('prof.title')) ?></h1>
    <div class="sub"><?= e($me['username']) ?> · <?= e(role_label($me['role'])) ?></div>
  </div>
</div>

<div class="card">
  <h2><?= e(t('prof.identity')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="profile">
    <div class="form-row">
      <div class="field">
        <label for="full_name"><?= e(t('prof.full_name')) ?></label>
        <input type="text" id="full_name" name="full_name" maxlength="120" value="<?= e($me['full_name']) ?>">
      </div>
      <div class="field">
        <label for="email"><?= e(t('prof.email')) ?></label>
        <input type="text" id="email" name="email" maxlength="190" value="<?= e($me['email']) ?>">
      </div>
    </div>
    <div class="form-row" style="margin-top:14px">
      <div class="field">
        <label for="phone"><?= e(t('prof.phone')) ?></label>
        <input type="text" id="phone" name="phone" maxlength="40" value="<?= e($me['phone']) ?>">
      </div>
      <div class="field">
        <label for="note"><?= e(t('prof.note')) ?></label>
        <input type="text" id="note" name="note" maxlength="255" value="<?= e($me['note']) ?>">
      </div>
      <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('prof.password')) ?></h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <div class="form-row">
      <div class="field">
        <label for="current_password"><?= e(t('prof.current_password')) ?></label>
        <input type="password" id="current_password" name="current_password" required>
      </div>
      <div class="field">
        <label for="new_password"><?= e(t('prof.new_password')) ?></label>
        <input type="password" id="new_password" name="new_password" minlength="10" required>
      </div>
      <div class="field">
        <label for="confirm_password"><?= e(t('prof.confirm')) ?></label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="10" required>
      </div>
      <button class="btn" type="submit"><?= e(t('prof.change_password')) ?></button>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('tfa.card')) ?></h2>
  <?php if ($me['totp_enabled']): ?>
    <p class="hint"><?= e(t('tfa.active_hint')) ?></p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="2fa_disable">
      <div class="form-row">
        <div class="field">
          <label for="tfa_current_password"><?= e(t('prof.current_password')) ?></label>
          <input type="password" id="tfa_current_password" name="current_password" required>
        </div>
        <button class="btn btn-danger" type="submit"><?= e(t('tfa.disable_btn')) ?></button>
      </div>
    </form>
  <?php elseif (isset($_SESSION['totp_pending_secret'])):
    $pendingSecret = $_SESSION['totp_pending_secret'];
    $provisioningUri = totp_provisioning_uri($pendingSecret, $me['username'], branding('brand_name'));
    $grouped = trim(chunk_split($pendingSecret, 4, ' '));
  ?>
    <p class="hint"><?= e(t('tfa.setup_hint')) ?></p>
    <div class="tfa-setup">
      <div id="tfa-qr" data-uri="<?= e($provisioningUri) ?>"></div>
      <div class="tfa-setup-side">
        <p class="hint"><?= e(t('tfa.setup_manual')) ?></p>
        <p class="mono"><?= e($grouped) ?></p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="2fa_confirm">
          <div class="field">
            <label for="tfa_code"><?= e(t('tfa.code_label')) ?></label>
            <input type="text" id="tfa_code" name="code" class="pin-input" inputmode="numeric"
                   autocomplete="one-time-code" placeholder="000000" maxlength="6" required>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= e(t('tfa.confirm_btn')) ?></button>
          </div>
        </form>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="2fa_cancel">
          <button class="btn btn-sm" type="submit"><?= e(t('common.cancel')) ?></button>
        </form>
      </div>
    </div>
    <script src="assets/js/qrcode.min.js"></script>
    <script>
      new QRCode(document.getElementById('tfa-qr'), {
        text: document.getElementById('tfa-qr').dataset.uri,
        width: 180,
        height: 180,
      });
    </script>
  <?php else: ?>
    <p class="hint"><?= e(t('tfa.disabled_hint')) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="2fa_start">
      <button class="btn btn-primary" type="submit"><?= e(t('tfa.enable_btn')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= e(t('prof.caps')) ?></h2>
  <p class="hint"><?= t('prof.caps_hint', [
    'role'   => '<strong>' . e(role_label($me['role'])) . '</strong>',
    'custom' => $me['capabilities_json'] !== null ? e(t('prof.caps_custom')) : '',
  ]) ?></p>
  <ul class="cap-list">
    <?php foreach (capability_labels() as $key => $label): ?>
    <li class="<?= in_array($key, $caps, true) ? 'on' : 'off' ?>">
      <span class="cap-dot"></span><?= e($label) ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="card">
  <h2><?= e(t('profile.language')) ?></h2>
  <p class="hint"><?= e(t('profile.language_hint')) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="language">
    <div class="form-row">
      <div class="field">
        <label for="language"><?= e(t('common.language')) ?></label>
        <select id="language" name="language">
          <option value=""<?= $me['language'] === null ? ' selected' : '' ?>><?= e(t('profile.language_system', [
            'label' => LANGUAGES[$systemLang]['label'],
          ])) ?></option>
          <?php foreach (lang_options() as $code => $info): ?>
          <option value="<?= e($code) ?>"<?= $me['language'] === $code ? ' selected' : '' ?>><?= e($info['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
    </div>
  </form>
</div>

<?php cms_footer();