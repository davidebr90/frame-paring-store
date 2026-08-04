<?php
declare(strict_types=1);

// White labeling: nome, logo, colore e testi mostrati nel CMS e sulla TV.
require __DIR__ . '/_cms.php';
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
            $errors[] = 'Il nome è obbligatorio (max 60 caratteri)';
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
            $errors[] = 'Il colore deve essere in formato esadecimale (es. #ffb224)';
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
            flash_set('ok', 'Personalizzazione salvata');
            redirect('branding.php');
        }
    } elseif ($do === 'logo') {
        $file = $_FILES['logo'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Nessun file caricato (o file troppo grande)';
        } else {
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
            if (!isset($allowed[$mime])) {
                $errors[] = 'Formato non supportato: usa PNG, JPEG, WebP o SVG';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Il logo non deve superare 2 MB';
            } else {
                // Rimuove il logo precedente per non lasciare file orfani
                $old = (string) branding('brand_logo');
                if ($old !== '' && is_file(branding_dir() . '/' . basename($old))) {
                    unlink(branding_dir() . '/' . basename($old));
                }
                $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], branding_dir() . '/' . $name)) {
                    setting_set('brand_logo', $name);
                    flash_set('ok', 'Logo aggiornato');
                    redirect('branding.php');
                }
                $errors[] = 'Impossibile salvare il logo (permessi della cartella uploads?)';
            }
        }
    } elseif ($do === 'logo_remove') {
        $old = (string) branding('brand_logo');
        if ($old !== '' && is_file(branding_dir() . '/' . basename($old))) {
            unlink(branding_dir() . '/' . basename($old));
        }
        setting_set('brand_logo', '');
        flash_set('ok', 'Logo rimosso: torna il marchio testuale');
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
        flash_set('ok', 'Personalizzazione riportata ai valori predefiniti');
        redirect('branding.php');
    }
}

$b = branding();

cms_header('Personalizzazione', 'branding');
?>
<div class="main-head">
  <div>
    <h1>Personalizzazione</h1>
    <div class="sub">Nome, logo, colore e testi di CMS e app TV</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>Logo</h2>
  <p class="hint">Sostituisce il marchio testuale nella barra laterale e nelle pagine
  di accesso. PNG, JPEG, WebP o SVG, massimo 2 MB. Consigliato: sfondo trasparente,
  altezza almeno 64px.</p>
  <div class="brand-logo-row">
    <div class="brand-logo-preview">
      <?php if ($logo = branding_logo_url()): ?>
        <img src="<?= e($logo) ?>" alt="Logo attuale">
      <?php else: ?>
        <span class="hint">Nessun logo: viene mostrato il nome testuale</span>
      <?php endif; ?>
    </div>
    <div class="brand-logo-actions">
      <form method="post" enctype="multipart/form-data" class="form-row">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="logo">
        <div class="field">
          <label for="logo">Carica un nuovo logo</label>
          <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" required>
        </div>
        <button class="btn btn-primary" type="submit">Carica</button>
      </form>
      <?php if (branding_logo_url()): ?>
      <form method="post" onsubmit="return confirm('Rimuovere il logo?')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="logo_remove">
        <button class="btn btn-sm btn-danger" type="submit">Rimuovi logo</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">

  <div class="card">
    <h2>Identità</h2>
    <div class="field-grid">
      <div class="field">
        <label for="brand_name">Nome del sistema <span class="req">*</span></label>
        <input type="text" id="brand_name" name="brand_name" maxlength="60" required
               value="<?= e($b['brand_name']) ?>">
      </div>
      <div class="field">
        <label for="brand_tagline">Sottotitolo nelle pagine di accesso</label>
        <input type="text" id="brand_tagline" name="brand_tagline" maxlength="120"
               value="<?= e($b['brand_tagline']) ?>">
      </div>
      <div class="field">
        <label for="brand_accent">Colore principale</label>
        <div class="color-row">
          <input type="color" id="brand_accent_picker" value="<?= e(branding_accent()) ?>"
                 aria-label="Scegli colore">
          <input type="text" id="brand_accent" name="brand_accent" maxlength="7"
                 value="<?= e(branding_accent()) ?>" class="mono">
        </div>
      </div>
      <div class="field">
        <label for="brand_footer_note">Nota nel footer</label>
        <input type="text" id="brand_footer_note" name="brand_footer_note" maxlength="120"
               placeholder="Es. Gestito da Nome Azienda" value="<?= e($b['brand_footer_note']) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Schermata dell'app TV</h2>
    <p class="hint">Testi mostrati sul televisore durante la configurazione, prima
    che lo schermo venga associato. Arrivano alle TV al primo collegamento.</p>
    <div class="field-grid">
      <div class="field">
        <label for="brand_tv_title">Titolo sulla TV</label>
        <input type="text" id="brand_tv_title" name="brand_tv_title" maxlength="40"
               placeholder="<?= e($b['brand_name']) ?>" value="<?= e($b['brand_tv_title']) ?>">
      </div>
      <div class="field">
        <label for="brand_tv_subtitle">Sottotitolo sulla TV</label>
        <input type="text" id="brand_tv_subtitle" name="brand_tv_subtitle" maxlength="60"
               value="<?= e($b['brand_tv_subtitle']) ?>">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Salva personalizzazione</button>
    </div>
  </div>
</form>

<div class="card">
  <h2>Ripristino</h2>
  <p class="hint">Riporta nome, colore, logo e testi ai valori originali di
  <?= e(APP_NAME_DEFAULT) ?>. Non tocca contenuti, schermi o utenti.</p>
  <form method="post" onsubmit="return confirm('Ripristinare la personalizzazione predefinita?')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <button class="btn btn-danger" type="submit">Ripristina valori predefiniti</button>
  </form>
</div>
<?php cms_footer();