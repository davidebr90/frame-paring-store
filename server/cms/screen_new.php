<?php
declare(strict_types=1);

// Aggiunta schermo con configurazione manuale: lo schermo nasce qui e
// riceve una URL monouso da incollare nelle impostazioni dell'app.
require_once __DIR__ . '/_cms.php';
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
        $errors[] = t('snew.err_name');
    }
    if ($inches < 0 || $inches > 500) {
        $errors[] = t('snew.err_inches');
    }
    if (!in_array($form['orientation'], ['landscape', 'portrait'], true)) {
        $errors[] = t('snew.err_orientation');
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
        flash_set('ok', t('snew.created'));
        redirect('screen.php?id=' . (int) $pdo->lastInsertId());
    }
}

cms_header(t('snew.title'), 'screens.new');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('snew.title')) ?></h1>
    <div class="sub"><?= e(t('snew.subtitle')) ?></div>
  </div>
  <a class="btn" href="screens.php"><?= e(t('snew.back')) ?></a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <h2><?= e(t('snew.card')) ?></h2>
    <p class="hint"><?= t('snew.hint', ['link' => '<a href="pair.php">' . e(t('nav.screens.pair')) . '</a>']) ?></p>
    <div class="field-grid">
      <div class="field">
        <label for="name"><?= e(t('screens.name')) ?> <span class="req">*</span></label>
        <input type="text" id="name" name="name" maxlength="100" required
               value="<?= e($form['name']) ?>" placeholder="<?= e(t('screens.name_ph')) ?>">
      </div>
      <div class="field">
        <label for="diagonal_inches"><?= e(t('snew.inches')) ?></label>
        <input type="number" id="diagonal_inches" name="diagonal_inches" min="0" max="500"
               value="<?= e($form['diagonal_inches']) ?>" placeholder="<?= e(t('snew.inches_ph')) ?>">
      </div>
      <div class="field">
        <label for="orientation"><?= e(t('snew.orientation')) ?></label>
        <select id="orientation" name="orientation">
          <option value="landscape"<?= $form['orientation'] === 'landscape' ? ' selected' : '' ?>><?= e(t('snew.landscape')) ?></option>
          <option value="portrait"<?= $form['orientation'] === 'portrait' ? ' selected' : '' ?>><?= e(t('snew.portrait')) ?></option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('snew.submit')) ?></button>
      <a class="btn" href="screens.php"><?= e(t('common.cancel')) ?></a>
    </div>
  </div>
</form>
<?php cms_footer();