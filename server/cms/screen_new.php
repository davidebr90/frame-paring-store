<?php
declare(strict_types=1);

// Aggiunta schermo con configurazione manuale: lo schermo nasce qui e
// riceve una URL monouso da incollare nelle impostazioni dell'app.
require __DIR__ . '/_cms.php';
require_cap('screen.manage');

$pdo = db();
$errors = [];
$form = ['name' => '', 'diagonal_inches' => '', 'orientation' => 'landscape'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['diagonal_inches'] = trim((string) ($_POST['diagonal_inches'] ?? ''));
    $form['orientation'] = (string) ($_POST['orientation'] ?? 'landscape');

    $inches = $form['diagonal_inches'] === '' ? 0 : (int) $form['diagonal_inches'];

    if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
        $errors[] = 'Il nome dello schermo è obbligatorio (max 100 caratteri)';
    }
    if ($inches < 0 || $inches > 500) {
        $errors[] = 'Polliciaggio non valido';
    }
    if (!in_array($form['orientation'], ['landscape', 'portrait'], true)) {
        $errors[] = 'Orientamento non valido';
    }

    if (!$errors) {
        $pdo->prepare("INSERT INTO screens (name, diagonal_inches, orientation, claim_token, status)
                       VALUES (?, ?, ?, ?, 'pending')")
            ->execute([
                $form['name'],
                $inches > 0 ? $inches : null,
                $form['orientation'],
                random_hex(),
            ]);
        flash_set('ok', 'Schermo creato: copia la URL di configurazione e incollala nell\'app sulla TV');
        redirect('screen.php?id=' . (int) $pdo->lastInsertId());
    }
}

cms_header('Aggiungi schermo', 'screens.new');
?>
<div class="main-head">
  <div>
    <h1>Aggiungi schermo</h1>
    <div class="sub">Configurazione manuale, senza PIN</div>
  </div>
  <a class="btn" href="screens.php">← Elenco schermi</a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <h2>Dati dello schermo</h2>
    <p class="hint">Crea lo schermo prima di accendere la TV: otterrai una URL monouso
    da incollare nelle impostazioni dell'app. Se invece la TV è già accesa e mostra
    un codice, usa <a href="pair.php">Associa con PIN</a>.</p>
    <div class="field-grid">
      <div class="field">
        <label for="name">Nome schermo <span class="req">*</span></label>
        <input type="text" id="name" name="name" maxlength="100" required
               value="<?= e($form['name']) ?>" placeholder="Es. Vetrina ingresso">
      </div>
      <div class="field">
        <label for="diagonal_inches">Dimensione (pollici)</label>
        <input type="number" id="diagonal_inches" name="diagonal_inches" min="0" max="500"
               value="<?= e($form['diagonal_inches']) ?>" placeholder="es. 65">
      </div>
      <div class="field">
        <label for="orientation">Orientamento del pannello</label>
        <select id="orientation" name="orientation">
          <option value="landscape"<?= $form['orientation'] === 'landscape' ? ' selected' : '' ?>>Orizzontale</option>
          <option value="portrait"<?= $form['orientation'] === 'portrait' ? ' selected' : '' ?>>Verticale</option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Crea schermo</button>
      <a class="btn" href="screens.php">Annulla</a>
    </div>
  </div>
</form>
<?php cms_footer();