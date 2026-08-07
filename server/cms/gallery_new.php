<?php
declare(strict_types=1);

// Creazione galleria con le impostazioni di riproduzione iniziali.
require_once __DIR__ . '/_cms.php';
require_cap('gallery.manage');

$pdo = db();
$errors = [];
$form = ['name' => '', 'transition_default' => 'crossfade', 'transition_duration_ms' => '600'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['transition_default'] = (string) ($_POST['transition_default'] ?? 'crossfade');
    $form['transition_duration_ms'] = (string) ($_POST['transition_duration_ms'] ?? '600');
    $durationMs = (int) $form['transition_duration_ms'];

    if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
        $errors[] = t('gnew.err_name');
    }
    if (!isset(TRANSITIONS[$form['transition_default']])) {
        $errors[] = t('gnew.err_transition');
    }
    if ($durationMs < 0 || $durationMs > 10000) {
        $errors[] = t('gnew.err_duration');
    }

    if (!$errors) {
        $pdo->prepare('INSERT INTO galleries (name, transition_default, transition_duration_ms)
                       VALUES (?, ?, ?)')
            ->execute([$form['name'], $form['transition_default'], $durationMs]);
        flash_set('ok', t('gnew.created'));
        redirect('gallery.php?id=' . (int) $pdo->lastInsertId());
    }
}

cms_header(t('gnew.title'), 'galleries.new');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('gnew.title')) ?></h1>
    <div class="sub"><?= e(t('gnew.subtitle')) ?></div>
  </div>
  <a class="btn" href="galleries.php"><?= e(t('gnew.back')) ?></a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <h2><?= e(t('gnew.card')) ?></h2>
    <div class="field-grid">
      <div class="field">
        <label for="name"><?= e(t('common.name')) ?> <span class="req">*</span></label>
        <input type="text" id="name" name="name" maxlength="100" required
               value="<?= e($form['name']) ?>" placeholder="<?= e(t('gal.name_ph')) ?>">
      </div>
      <div class="field">
        <label for="transition_default"><?= e(t('gnew.transition')) ?></label>
        <select id="transition_default" name="transition_default">
          <?php foreach (transition_labels() as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $form['transition_default'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="transition_duration_ms"><?= e(t('gnew.duration')) ?></label>
        <input type="number" id="transition_duration_ms" name="transition_duration_ms"
               min="0" max="10000" step="50" value="<?= e($form['transition_duration_ms']) ?>">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('gal.create')) ?></button>
      <a class="btn" href="galleries.php"><?= e(t('common.cancel')) ?></a>
    </div>
  </div>
</form>
<?php cms_footer();