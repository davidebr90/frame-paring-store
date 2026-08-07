<?php
declare(strict_types=1);

// Manutenzione: informazioni di sistema, reset totale e disinstallazione.
// Ogni azione distruttiva richiede la password dell'amministratore e la
// digitazione di una parola di sicurezza.
require_once __DIR__ . '/_cms.php';
require_cap('settings.manage');

$pdo = db();
$me = current_user();
$errors = [];

// Tutte le tabelle gestite dal sistema, in ordine sicuro per le foreign key
const RESET_TABLES = ['screen_galleries', 'media', 'galleries', 'screens', 'settings', 'users'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do       = (string) ($_POST['do'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $phrase   = trim((string) ($_POST['phrase'] ?? ''));

    // La parola di conferma è tradotta: si digita nella lingua in uso
    $expected = $do === 'uninstall' ? t('mnt.uninstall_word') : t('mnt.reset_word');

    if (!password_verify($password, $me['password_hash'])) {
        $errors[] = t('mnt.err_password');
    } elseif ($phrase !== $expected) {
        $errors[] = t('mnt.err_phrase', ['word' => $expected]);
    } elseif ($do === 'reset' || $do === 'uninstall') {
        // 1. File multimediali e logo
        foreach (glob(UPLOADS_DIR . '/*') ?: [] as $path) {
            if (is_file($path) && !str_ends_with($path, '.htaccess') && !str_ends_with($path, '.gitkeep')) {
                unlink($path);
            }
        }
        foreach (glob(UPLOADS_DIR . '/branding/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        // 2. Svuotamento del database
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (RESET_TABLES as $table) {
            $pdo->exec("TRUNCATE TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // 3. Impostazioni minime ripristinate
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('trash_retention_days', '30')")
            ->execute();

        logout_user();

        if ($do === 'uninstall') {
            // Segnala che l'installazione è stata dismessa: il setup non
            // riparte da solo, servirà rimuovere il file a mano
            @file_put_contents(APP_ROOT . '/core/uninstalled.lock', date('c'));
            redirect('uninstalled.php');
        }
        redirect('setup.php');
    }
}

// Statistiche per l'informativa
$stats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM users)     AS users,
    (SELECT COUNT(*) FROM screens)   AS screens,
    (SELECT COUNT(*) FROM galleries) AS galleries,
    (SELECT COUNT(*) FROM media)     AS media,
    (SELECT COALESCE(SUM(filesize),0) FROM media) AS bytes')->fetch();

cms_header(t('mnt.title'), 'maintenance');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('mnt.title')) ?></h1>
    <div class="sub"><?= e(t('mnt.subtitle')) ?></div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= e(t('mnt.info')) ?></h2>
  <dl class="info-grid">
    <dt><?= e(t('mnt.version')) ?></dt><dd class="mono"><?= APP_VERSION ?> · <?= date('d/m/Y', strtotime(APP_RELEASE_DATE)) ?></dd>
    <dt>PHP</dt><dd class="mono"><?= e(PHP_VERSION) ?></dd>
    <dt><?= e(t('caps.env_db')) ?></dt><dd class="mono"><?= e($pdo->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></dd>
    <dt><?= e(t('mnt.upload_limit')) ?></dt><dd><?= human_bytes((int) config('max_upload_bytes')) ?>
      <span class="hint">(PHP: <?= e(ini_get('upload_max_filesize')) ?>)</span></dd>
    <dt><?= e(t('mnt.media_dir')) ?></dt>
    <dd class="mono"><?= e(is_writable(UPLOADS_DIR) ? t('chk.uploads.ok') : t('chk.uploads.ko')) ?></dd>
    <dt><?= e(t('mnt.content')) ?></dt><dd><?= e(t('mnt.content_val', [
      'files'     => (int) $stats['media'],
      'size'      => human_bytes((int) $stats['bytes']),
      'galleries' => (int) $stats['galleries'],
    ])) ?></dd>
    <dt><?= e(t('mnt.screens')) ?></dt><dd><?= (int) $stats['screens'] ?></dd>
    <dt><?= e(t('mnt.users')) ?></dt><dd><?= (int) $stats['users'] ?></dd>
  </dl>
</div>

<div class="card danger-card">
  <h2><?= e(t('mnt.reset')) ?></h2>
  <p class="hint"><?= t('mnt.reset_hint', [
    'all'     => '<strong>' . e(t('mnt.reset_all')) . '</strong>',
    'warning' => '<strong>' . e(t('mnt.reset_warning')) . '</strong>',
  ]) ?></p>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <div class="field-grid">
      <div class="field">
        <label for="password_reset"><?= e(t('mnt.your_password')) ?></label>
        <input type="password" id="password_reset" name="password" required>
      </div>
      <div class="field">
        <label for="phrase_reset"><?= t('mnt.type_to_confirm', [
          'word' => '<span class="mono danger-word">' . e(t('mnt.reset_word')) . '</span>',
        ]) ?></label>
        <input type="text" id="phrase_reset" name="phrase" required autocomplete="off" placeholder="<?= e(t('mnt.reset_word')) ?>">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-danger" type="submit"
              onclick="return confirm('<?= e(t('mnt.reset_confirm')) ?>')">
        <?= e(t('mnt.reset_btn')) ?>
      </button>
    </div>
  </form>
</div>

<div class="card danger-card">
  <h2><?= e(t('mnt.uninstall')) ?></h2>
  <p class="hint"><?= t('mnt.uninstall_hint', [
    'block' => '<strong>' . e(t('mnt.uninstall_block')) . '</strong>',
  ]) ?></p>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="uninstall">
    <div class="field-grid">
      <div class="field">
        <label for="password_uninstall"><?= e(t('mnt.your_password')) ?></label>
        <input type="password" id="password_uninstall" name="password" required>
      </div>
      <div class="field">
        <label for="phrase_uninstall"><?= t('mnt.type_to_confirm', [
          'word' => '<span class="mono danger-word">' . e(t('mnt.uninstall_word')) . '</span>',
        ]) ?></label>
        <input type="text" id="phrase_uninstall" name="phrase" required autocomplete="off" placeholder="<?= e(t('mnt.uninstall_word')) ?>">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-danger" type="submit"
              onclick="return confirm('<?= e(t('mnt.uninstall_confirm')) ?>')">
        <?= e(t('mnt.uninstall_btn')) ?>
      </button>
    </div>
  </form>
</div>
<?php cms_footer();