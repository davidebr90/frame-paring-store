<?php
declare(strict_types=1);

// Manutenzione: informazioni di sistema, reset totale e disinstallazione.
// Ogni azione distruttiva richiede la password dell'amministratore e la
// digitazione di una parola di sicurezza.
require __DIR__ . '/_cms.php';
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

    $expected = $do === 'uninstall' ? 'DISINSTALLA' : 'RESET';

    if (!password_verify($password, $me['password_hash'])) {
        $errors[] = 'Password non corretta';
    } elseif ($phrase !== $expected) {
        $errors[] = "Per confermare devi digitare esattamente: $expected";
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

cms_header('Manutenzione', 'maintenance');
?>
<div class="main-head">
  <div>
    <h1>Manutenzione</h1>
    <div class="sub">Informazioni di sistema e operazioni irreversibili</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>Informazioni</h2>
  <dl class="info-grid">
    <dt>Versione</dt><dd class="mono"><?= APP_VERSION ?> · <?= date('d/m/Y', strtotime(APP_RELEASE_DATE)) ?></dd>
    <dt>PHP</dt><dd class="mono"><?= e(PHP_VERSION) ?></dd>
    <dt>Database</dt><dd class="mono"><?= e($pdo->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></dd>
    <dt>Limite upload</dt><dd><?= human_bytes((int) config('max_upload_bytes')) ?>
      <span class="hint">(PHP: <?= e(ini_get('upload_max_filesize')) ?>)</span></dd>
    <dt>Cartella media</dt><dd class="mono"><?= is_writable(UPLOADS_DIR) ? 'scrivibile' : 'NON scrivibile' ?></dd>
    <dt>Contenuti</dt><dd><?= (int) $stats['media'] ?> file ·
      <?= human_bytes((int) $stats['bytes']) ?> ·
      <?= (int) $stats['galleries'] ?> gallerie</dd>
    <dt>Schermi</dt><dd><?= (int) $stats['screens'] ?></dd>
    <dt>Utenti</dt><dd><?= (int) $stats['users'] ?></dd>
  </dl>
</div>

<div class="card danger-card">
  <h2>Reset completo</h2>
  <p class="hint">Cancella <strong>tutto</strong>: schermi associati, gallerie, file
  multimediali, utenti, impostazioni e personalizzazione. Il sistema torna come appena
  installato e riparte dalla creazione del primo amministratore.
  I televisori già associati perderanno l'autorizzazione e dovranno essere riassociati.
  <strong>L'operazione non è annullabile</strong> e non passa dal cestino.</p>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <div class="field-grid">
      <div class="field">
        <label for="password_reset">La tua password</label>
        <input type="password" id="password_reset" name="password" required>
      </div>
      <div class="field">
        <label for="phrase_reset">Digita <span class="mono danger-word">RESET</span> per confermare</label>
        <input type="text" id="phrase_reset" name="phrase" required autocomplete="off" placeholder="RESET">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-danger" type="submit"
              onclick="return confirm('Ultima conferma: tutti i dati verranno eliminati definitivamente. Procedere?')">
        Esegui reset completo
      </button>
    </div>
  </form>
</div>

<div class="card danger-card">
  <h2>Disinstallazione</h2>
  <p class="hint">Esegue lo stesso azzeramento del reset e <strong>blocca l'accesso</strong>
  al sistema: il setup iniziale non ripartirà da solo. Serve quando dismetti
  l'installazione da questo hosting. Al termine dovrai eliminare i file dal server
  via FTP; le istruzioni ti verranno mostrate.</p>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="uninstall">
    <div class="field-grid">
      <div class="field">
        <label for="password_uninstall">La tua password</label>
        <input type="password" id="password_uninstall" name="password" required>
      </div>
      <div class="field">
        <label for="phrase_uninstall">Digita <span class="mono danger-word">DISINSTALLA</span> per confermare</label>
        <input type="text" id="phrase_uninstall" name="phrase" required autocomplete="off" placeholder="DISINSTALLA">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-danger" type="submit"
              onclick="return confirm('Ultima conferma: il sistema verrà azzerato e disattivato. Procedere?')">
        Disinstalla il sistema
      </button>
    </div>
  </form>
</div>
<?php cms_footer();