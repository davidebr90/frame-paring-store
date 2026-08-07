<?php
declare(strict_types=1);

// Sovraimpressione di un singolo schermo. Finché non gliene si dà una
// propria, lo schermo segue le impostazioni generali: staccarlo non tocca
// gli altri, e si può sempre tornare all'eredità senza perdere nulla.
require_once __DIR__ . '/_cms.php';
require_cap('screen.manage');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM screens WHERE id = ? AND status <> 'revoked'");
$stmt->execute([$id]);
$screen = $stmt->fetch();
if ($screen === false) {
    flash_set('err', t('ovs.not_found'));
    redirect('overlay.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $current = overlay_settings_for_screen($screen);

    if ($do === 'settings') {
        overlay_save_for_screen($id, [
            'overlay_enabled'     => isset($_POST['overlay_enabled']) ? '1' : '0',
            'overlay_show_logo'   => isset($_POST['overlay_show_logo']) ? '1' : '0',
            'overlay_show_date'   => isset($_POST['overlay_show_date']) ? '1' : '0',
            'overlay_show_time'   => isset($_POST['overlay_show_time']) ? '1' : '0',
            'overlay_position'    => (string) ($_POST['overlay_position'] ?? ''),
            'overlay_size'        => (string) ($_POST['overlay_size'] ?? ''),
            'overlay_date_format' => (string) ($_POST['overlay_date_format'] ?? ''),
            'overlay_opacity'     => (string) ($_POST['overlay_opacity'] ?? '80'),
            'overlay_logo'        => (string) $current['overlay_logo'],
        ]);
        flash_set('ok', t('ovs.saved'));
        redirect('screen_overlay.php?id=' . $id);
    } elseif ($do === 'inherit') {
        // Il logo proprio potrebbe restare orfano: si libera se non serve più
        $old = overlay_is_custom($screen) ? (string) $current['overlay_logo'] : '';
        overlay_save_for_screen($id, null);
        if ($old !== '') {
            overlay_forget_logo($old);
        }
        flash_set('ok', t('ovs.inherited_done'));
        redirect('screen_overlay.php?id=' . $id);
    } elseif ($do === 'detach') {
        // Si parte dai valori attualmente in vigore: staccarsi non deve
        // far cambiare aspetto allo schermo finché non si modifica qualcosa
        overlay_save_for_screen($id, $current);
        flash_set('ok', t('ovs.detached_done'));
        redirect('screen_overlay.php?id=' . $id);
    } elseif ($do === 'logo') {
        if (!overlay_is_custom($screen)) {
            $errors[] = t('ovs.err_detach_first');
        } else {
            [$name, $error] = overlay_store_logo($_FILES['logo'] ?? null, (string) $current['overlay_logo']);
            if ($error !== null) {
                $errors[] = $error;
            } else {
                overlay_save_for_screen($id, ['overlay_show_logo' => '1', 'overlay_logo' => $name] + $current);
                flash_set('ok', t('ovs.logo_saved'));
                redirect('screen_overlay.php?id=' . $id);
            }
        }
    } elseif ($do === 'logo_remove') {
        $old = (string) $current['overlay_logo'];
        overlay_save_for_screen($id, ['overlay_show_logo' => '0', 'overlay_logo' => ''] + $current);
        overlay_forget_logo($old);
        flash_set('ok', t('ovs.logo_removed'));
        redirect('screen_overlay.php?id=' . $id);
    }
}

$custom  = overlay_is_custom($screen);
$o       = overlay_settings_for_screen($screen);
$hasLogo = overlay_logo_path($o) !== null;
$logoSrc = 'banner_logo.php?screen=' . $id . '&v=' . substr(md5((string) $o['overlay_logo']), 0, 8);

cms_header(t('ovp.title') . ' — ' . $screen['name'], 'overlay');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('ovp.title')) ?></h1>
    <div class="sub"><?= t('ovs.screen', ['name' => '<strong>' . e($screen['name']) . '</strong>']) ?></div>
  </div>
  <a class="btn" href="overlay.php"><?= e(t('ovs.back')) ?></a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= e(t('ovs.origin')) ?></h2>
  <?php if ($custom): ?>
  <p class="hint"><?= t('ovs.own_text', ['strong' => '<strong>' . e(t('ovs.own_word')) . '</strong>']) ?></p>
  <form method="post"
        onsubmit="return confirm('<?= e(t('ovs.inherit_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="inherit">
    <button class="btn" type="submit"><?= e(t('ovs.inherit_btn')) ?></button>
  </form>
  <?php else: ?>
  <p class="hint"><?= t('ovs.inherited_text', ['strong' => '<strong>' . e(t('ovs.inherited_word')) . '</strong>']) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="detach">
    <button class="btn btn-primary" type="submit"><?= e(t('ovs.detach_btn')) ?></button>
  </form>
  <?php endif; ?>
</div>

<?php if ($custom): ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="settings">
  <?php require __DIR__ . '/_overlay_form.php'; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('ovs.save')) ?></button></div>
</form>

<div class="card">
  <h2><?= e(t('ovs.logo_card')) ?></h2>
  <p class="hint"><?= e(t('ovs.logo_hint')) ?></p>
  <?php if ($hasLogo): ?>
  <div class="brand-logo-row">
    <div class="brand-logo-preview"><img src="<?= e($logoSrc) ?>" alt="<?= e(t('ovp.logo_alt')) ?>"></div>
    <div class="brand-logo-actions">
      <form method="post" onsubmit="return confirm('<?= e(t('ovs.logo_confirm')) ?>')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="logo_remove">
        <button class="btn btn-danger" type="submit"><?= e(t('ovp.logo_remove')) ?></button>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="logo">
    <div class="field">
      <label for="logo"><?= e(t('ovp.image')) ?></label>
      <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp" required>
    </div>
    <div class="form-actions"><button class="btn" type="submit"><?= e(t('ovp.logo_upload')) ?></button></div>
  </form>
</div>
<?php else: ?>
<div class="card">
  <h2><?= e(t('ovs.current')) ?></h2>
  <p class="hint"><?= t('ovs.current_hint', [
    'link' => '<a href="overlay.php">' . e(t('ovs.current_link')) . '</a>',
  ]) ?></p>
  <dl class="info-grid">
    <dt><?= e(t('ovs.banner')) ?></dt>
    <dd><?= e(overlay_settings_active($o) ? t('common.enabled') : t('common.disabled')) ?></dd>
    <dt><?= e(t('ov.position')) ?></dt><dd><?= e(t(OVERLAY_POSITIONS[$o['overlay_position']])) ?></dd>
    <dt><?= e(t('ov.size')) ?></dt><dd><?= e(t(OVERLAY_SIZES[$o['overlay_size']])) ?></dd>
    <dt><?= e(t('ovs.content_label')) ?></dt><dd><?php
      $parti = [];
      if ($o['overlay_show_logo'] === '1' && $hasLogo) { $parti[] = mb_strtolower(t('ov.logo')); }
      if ($o['overlay_show_date'] === '1') { $parti[] = mb_strtolower(t('ov.date')); }
      if ($o['overlay_show_time'] === '1') { $parti[] = mb_strtolower(t('ov.time')); }
      echo $parti ? e(implode(', ', $parti)) : e(t('ovs.nothing'));
    ?></dd>
    <dt><?= e(t('common.preview')) ?></dt><dd><?= e(overlay_sample_text($o)) ?: '—' ?></dd>
  </dl>
</div>
<?php endif; ?>
<?php cms_footer();
