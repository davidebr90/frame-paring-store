<?php
declare(strict_types=1);

// Impostazioni generali del banner in sovraimpressione: logo e/o data e ora
// sopra il contenuto. Disattivato di default; non modifica in alcun modo la
// riproduzione. Ogni schermo può discostarsene con una configurazione propria.
require_once __DIR__ . '/_cms.php';
require_cap('settings.manage');

$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'settings') {
        $values = overlay_normalize([
            'overlay_enabled'     => isset($_POST['overlay_enabled']) ? '1' : '0',
            'overlay_show_logo'   => isset($_POST['overlay_show_logo']) ? '1' : '0',
            'overlay_show_date'   => isset($_POST['overlay_show_date']) ? '1' : '0',
            'overlay_show_time'   => isset($_POST['overlay_show_time']) ? '1' : '0',
            'overlay_position'    => (string) ($_POST['overlay_position'] ?? ''),
            'overlay_size'        => (string) ($_POST['overlay_size'] ?? ''),
            'overlay_date_format' => (string) ($_POST['overlay_date_format'] ?? ''),
            'overlay_opacity'     => (string) ($_POST['overlay_opacity'] ?? '80'),
            'overlay_logo'        => (string) overlay('overlay_logo'),
        ]);
        foreach ($values as $key => $value) {
            setting_set($key, $value);
        }
        flash_set('ok', t('ovp.saved'));
        redirect('overlay.php');
    } elseif ($do === 'logo') {
        [$name, $error] = overlay_store_logo($_FILES['logo'] ?? null, (string) overlay('overlay_logo'));
        if ($error !== null) {
            $errors[] = $error;
        } else {
            setting_set('overlay_logo', $name);
            setting_set('overlay_show_logo', '1');
            flash_set('ok', t('ovp.logo_saved'));
            redirect('overlay.php');
        }
    } elseif ($do === 'logo_remove') {
        $old = (string) overlay('overlay_logo');
        setting_set('overlay_logo', '');
        setting_set('overlay_show_logo', '0');
        overlay_forget_logo($old);
        flash_set('ok', t('ovp.logo_removed'));
        redirect('overlay.php');
    } elseif ($do === 'reset') {
        $old = (string) overlay('overlay_logo');
        foreach (array_keys(OVERLAY_DEFAULTS) as $key) {
            setting_set($key, '');
        }
        overlay_forget_logo($old);
        flash_set('ok', t('ovp.reset_done'));
        redirect('overlay.php');
    } elseif ($do === 'inherit_all') {
        $pdo->exec('UPDATE screens SET overlay_json = NULL');
        flash_set('ok', t('ovp.inherit_all_done'));
        redirect('overlay.php');
    }
}

$o = overlay();
$hasLogo = overlay_logo_file() !== null;
$logoSrc = 'banner_logo.php?v=' . substr(md5((string) $o['overlay_logo']), 0, 8);

$screens = $pdo->query("SELECT * FROM screens WHERE status <> 'revoked' ORDER BY name, id")->fetchAll();
$custom = array_filter($screens, 'overlay_is_custom');

cms_header(t('ovp.title'), 'overlay');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('ovp.title')) ?></h1>
    <div class="sub"><?= e(t('ovp.subtitle')) ?></div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<p class="hint"><?= t('ovp.general_hint', ['strong' => '<strong>' . e(t('ovp.general_word')) . '</strong>']) ?></p>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="settings">
  <?php require __DIR__ . '/_overlay_form.php'; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('ovp.save_general')) ?></button></div>
</form>

<div class="card">
  <h2><?= e(t('ovp.logo_card')) ?></h2>
  <p class="hint"><?= e(t('ovp.logo_hint')) ?></p>
  <?php if ($hasLogo): ?>
  <div class="brand-logo-row">
    <div class="brand-logo-preview"><img src="<?= e($logoSrc) ?>" alt="<?= e(t('ovp.logo_alt')) ?>"></div>
    <div class="brand-logo-actions">
      <form method="post" onsubmit="return confirm('<?= e(t('ovp.logo_confirm')) ?>')">
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

<div class="card">
  <h2><?= e(t('ovp.screens')) ?></h2>
  <p class="hint"><?= e(t('ovp.screens_hint')) ?></p>

  <?php if (!$screens): ?>
  <p class="hint"><?= e(t('ovp.no_screens')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= e(t('ovp.th_screen')) ?></th>
          <th><?= e(t('ovp.th_settings')) ?></th>
          <th><?= e(t('ovp.th_banner')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($screens as $screen):
            $settings = overlay_settings_for_screen($screen);
            $attivo = overlay_settings_active($settings);
            $proprio = overlay_is_custom($screen);
        ?>
        <tr>
          <td>
            <strong><?= e($screen['name']) ?></strong>
            <div class="hint"><?= e($screen['status'] === 'paired' ? t('ovp.paired') : t('ovp.pending')) ?></div>
          </td>
          <td>
            <span class="badge <?= $proprio ? 'badge-pending' : 'badge-offline' ?>">
              <?= e($proprio ? t('ovp.own') : t('ovp.inherited')) ?>
            </span>
          </td>
          <td>
            <?php if ($attivo): ?>
              <span class="badge badge-online"><span class="led"></span><?= e(t('common.enabled')) ?></span>
              <div class="hint"><?= e(t(OVERLAY_POSITIONS[$settings['overlay_position']])) ?></div>
            <?php else: ?>
              <span class="badge badge-offline"><?= e(t('common.disabled')) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <a class="btn btn-sm" href="screen_overlay.php?id=<?= (int) $screen['id'] ?>"><?= e(t('ovp.configure')) ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($custom): ?>
  <form method="post" class="form-actions"
        onsubmit="return confirm('<?= e(t('ovp.inherit_all_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="inherit_all">
    <button class="btn" type="submit">
      <?= e(count($custom) === 1 ? t('ovp.inherit_all_one') : t('ovp.inherit_all', ['n' => count($custom)])) ?>
    </button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= e(t('ovp.restore')) ?></h2>
  <p class="hint"><?= e(t('ovp.restore_hint')) ?></p>
  <form method="post" onsubmit="return confirm('<?= e(t('ovp.restore_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <button class="btn btn-danger" type="submit"><?= e(t('common.restore')) ?></button>
  </form>
</div>
<?php cms_footer();
