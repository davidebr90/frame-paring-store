<?php
declare(strict_types=1);

// White labeling: nome, logo, colore e testi mostrati nel CMS e sulla TV.
require_once __DIR__ . '/_cms.php';
require_cap('settings.manage');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'save') {
        $name    = trim((string) ($_POST['brand_name'] ?? ''));
        $tagline = trim((string) ($_POST['brand_tagline'] ?? ''));
        $accent  = trim((string) ($_POST['brand_accent'] ?? ''));
        $tvTitle = trim((string) ($_POST['brand_tv_title'] ?? ''));
        $tvSub   = trim((string) ($_POST['brand_tv_subtitle'] ?? ''));
        $note    = trim((string) ($_POST['brand_footer_note'] ?? ''));

        if ($name === '' || mb_strlen($name) > 60) {
            $errors[] = t('brd.err_name');
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
            $errors[] = t('brd.err_color');
        }
        if (mb_strlen($tvTitle) > 40 || mb_strlen($tvSub) > 60) {
            $errors[] = 'I testi per la TV sono troppo lunghi';
        }

        if (!$errors) {
            setting_set('brand_name', $name);
            setting_set('brand_tagline', $tagline);
            setting_set('brand_accent', strtolower($accent));
            setting_set('brand_tv_title', $tvTitle);
            setting_set('brand_tv_subtitle', $tvSub);
            setting_set('brand_footer_note', $note);
            flash_set('ok', t('brd.saved'));
            redirect('branding.php');
        }
    } elseif ($do === 'language') {
        $choice = (string) ($_POST['default_language'] ?? '');
        if (isset(LANGUAGES[$choice])) {
            setting_set('default_language', $choice);
            // La scelta di sistema vale anche per chi non ne ha una propria:
            // la sessione va allineata, altrimenti la pagina resterebbe
            // nella lingua precedente fino al prossimo accesso
            if (current_user()['language'] === null) {
                $_SESSION['lang'] = $choice;
            }
            flash_set('ok', t('syslang.saved'));
            redirect('branding.php');
        }
    } elseif ($do === 'logo') {
        $file = $_FILES['logo'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = t('ovp.err_nofile');
        } else {
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
            if (!isset($allowed[$mime])) {
                $errors[] = t('brd.err_format');
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = t('ovp.err_toobig');
            } else {
                // Rimuove il logo precedente per non lasciare file orfani
                $old = (string) branding('brand_logo');
                if ($old !== '' && is_file(branding_dir() . '/' . basename($old))) {
                    unlink(branding_dir() . '/' . basename($old));
                }
                $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], branding_dir() . '/' . $name)) {
                    setting_set('brand_logo', $name);
                    flash_set('ok', t('brd.logo_saved'));
                    redirect('branding.php');
                }
                $errors[] = t('ovp.err_save');
            }
        }
    } elseif ($do === 'logo_remove') {
        $old = (string) branding('brand_logo');
        if ($old !== '' && is_file(branding_dir() . '/' . basename($old))) {
            unlink(branding_dir() . '/' . basename($old));
        }
        setting_set('brand_logo', '');
        flash_set('ok', t('brd.logo_removed'));
        redirect('branding.php');
    } elseif ($do === 'reset') {
        foreach (array_keys(BRANDING_DEFAULTS) as $key) {
            if ($key === 'brand_logo') {
                $old = (string) branding('brand_logo');
                if ($old !== '' && is_file(branding_dir() . '/' . basename($old))) {
                    unlink(branding_dir() . '/' . basename($old));
                }
            }
            setting_set($key, '');
        }
        flash_set('ok', t('brd.reset_done'));
        redirect('branding.php');
    }
}

$b = branding();

cms_header(t('brd.title'), 'branding');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('brd.title')) ?></h1>
    <div class="sub"><?= e(t('brd.subtitle')) ?></div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= e(t('syslang.card')) ?></h2>
  <p class="hint"><?= t('syslang.hint', [
    'screens' => '<strong>' . e(t('syslang.screens')) . '</strong>',
  ]) ?></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="language">
    <div class="field" style="max-width:260px">
      <label for="default_language"><?= e(t('syslang.label')) ?></label>
      <select id="default_language" name="default_language">
        <?php $sysLang = (string) (setting_get('default_language') ?: LANG_FALLBACK); ?>
        <?php foreach (lang_options() as $code => $info): ?>
        <option value="<?= e($code) ?>"<?= $sysLang === $code ? ' selected' : '' ?>><?= e($info['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('brd.logo')) ?></h2>
  <p class="hint"><?= e(t('brd.logo_hint')) ?></p>
  <div class="brand-logo-row">
    <div class="brand-logo-preview">
      <?php if ($logo = branding_logo_url()): ?>
        <img src="<?= e($logo) ?>" alt="<?= e(t('brd.logo_alt')) ?>">
      <?php else: ?>
        <span class="hint"><?= e(t('brd.logo_none')) ?></span>
      <?php endif; ?>
    </div>
    <div class="brand-logo-actions">
      <form method="post" enctype="multipart/form-data" class="form-row">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="logo">
        <div class="field">
          <label for="logo"><?= e(t('brd.logo_upload_label')) ?></label>
          <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" required>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(t('common.upload')) ?></button>
      </form>
      <?php if (branding_logo_url()): ?>
      <form method="post" onsubmit="return confirm('<?= e(t('brd.logo_confirm')) ?>')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="logo_remove">
        <button class="btn btn-sm btn-danger" type="submit"><?= e(t('ovp.logo_remove')) ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">

  <div class="card">
    <h2><?= e(t('brd.identity')) ?></h2>
    <div class="field-grid">
      <div class="field">
        <label for="brand_name"><?= e(t('brd.name')) ?> <span class="req">*</span></label>
        <input type="text" id="brand_name" name="brand_name" maxlength="60" required
               value="<?= e($b['brand_name']) ?>">
      </div>
      <div class="field">
        <label for="brand_tagline"><?= e(t('brd.tagline')) ?></label>
        <input type="text" id="brand_tagline" name="brand_tagline" maxlength="120"
               value="<?= e($b['brand_tagline']) ?>">
      </div>
      <div class="field">
        <label for="brand_accent"><?= e(t('brd.accent')) ?></label>
        <div class="color-row">
          <input type="color" id="brand_accent_picker" value="<?= e(branding_accent()) ?>"
                 aria-label="<?= e(t('brd.accent_aria')) ?>">
          <input type="text" id="brand_accent" name="brand_accent" maxlength="7"
                 value="<?= e(branding_accent()) ?>" class="mono">
        </div>
      </div>
      <div class="field">
        <label for="brand_footer_note"><?= e(t('brd.footer_note')) ?></label>
        <input type="text" id="brand_footer_note" name="brand_footer_note" maxlength="120"
               placeholder="<?= e(t('brd.footer_note_ph')) ?>" value="<?= e($b['brand_footer_note']) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('brd.tv_screen')) ?></h2>
    <p class="hint"><?= e(t('brd.tv_hint')) ?></p>
    <div class="field-grid">
      <div class="field">
        <label for="brand_tv_title"><?= e(t('brd.tv_title')) ?></label>
        <input type="text" id="brand_tv_title" name="brand_tv_title" maxlength="40"
               placeholder="<?= e($b['brand_name']) ?>" value="<?= e($b['brand_tv_title']) ?>">
      </div>
      <div class="field">
        <label for="brand_tv_subtitle"><?= e(t('brd.tv_subtitle')) ?></label>
        <input type="text" id="brand_tv_subtitle" name="brand_tv_subtitle" maxlength="60"
               value="<?= e($b['brand_tv_subtitle']) ?>">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('brd.save')) ?></button>
    </div>
  </div>
</form>

<div class="card">
  <h2><?= e(t('ovp.restore')) ?></h2>
  <p class="hint"><?= e(t('brd.restore_hint', ['name' => APP_NAME_DEFAULT])) ?></p>
  <form method="post" onsubmit="return confirm('<?= e(t('brd.restore_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <button class="btn btn-danger" type="submit"><?= e(t('brd.restore_btn')) ?></button>
  </form>
</div>
<?php cms_footer();