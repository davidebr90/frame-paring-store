<?php
declare(strict_types=1);

// Dettaglio schermo: rinomina, gallerie assegnate (drag & drop),
// URL di configurazione manuale, revoca ed eliminazione.
require_once __DIR__ . '/_cms.php';
require_cap('screen.manage');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM screens WHERE id = ?');
$stmt->execute([$id]);
$screen = $stmt->fetch();
if ($screen === false) {
    flash_set('err', t('sv.not_found'));
    redirect('screens.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'settings') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $inches = (int) ($_POST['diagonal_inches'] ?? 0);
        $orientation = (string) ($_POST['orientation'] ?? 'landscape');
        if ($name === '' || mb_strlen($name) > 100) {
            flash_set('err', t('sv.err_name'));
        } elseif ($inches < 0 || $inches > 500) {
            flash_set('err', t('snew.err_inches'));
        } elseif (!in_array($orientation, ['landscape', 'portrait'], true)) {
            flash_set('err', t('snew.err_orientation'));
        } else {
            $pdo->prepare('UPDATE screens SET name = ?, diagonal_inches = ?, orientation = ? WHERE id = ?')
                ->execute([$name, $inches > 0 ? $inches : null, $orientation, $id]);
            flash_set('ok', t('sv.saved'));
        }
    } elseif ($do === 'revoke') {
        // Il token resta in tabella ma lo status blocca ogni richiesta
        $pdo->prepare("UPDATE screens SET status = 'revoked' WHERE id = ?")->execute([$id]);
        flash_set('ok', t('sv.revoked'));
    } elseif ($do === 'delete') {
        $pdo->prepare('DELETE FROM screens WHERE id = ?')->execute([$id]);
        flash_set('ok', t('sv.deleted'));
        redirect('screens.php');
    }
    redirect('screen.php?id=' . $id);
}

$assigned = $pdo->prepare('SELECT g.id, g.name FROM screen_galleries sg
                           JOIN galleries g ON g.id = sg.gallery_id
                           WHERE sg.screen_id = ?
                           ORDER BY sg.sort_order');
$assigned->execute([$id]);
$assigned = $assigned->fetchAll();

$assignedIds = array_column($assigned, 'id');
$available = array_filter(
    $pdo->query('SELECT id, name FROM galleries WHERE deleted_at IS NULL ORDER BY name')->fetchAll(),
    fn (array $g): bool => !in_array($g['id'], $assignedIds, true)
);

$onlineWindow = (int) config('poll_interval') * 2 + 30;

$scheduleBands = schedule_parse($screen['schedule_json']);
$imageMedia = $pdo->query("SELECT m.id, m.original_name, g.name AS gallery_name
                           FROM media m JOIN galleries g ON g.id = m.gallery_id
                           WHERE m.type = 'image' AND m.deleted_at IS NULL AND g.deleted_at IS NULL
                           ORDER BY g.name, m.original_name")->fetchAll();

cms_header($screen['name'], 'screens');
?>
<div class="main-head">
  <div>
    <h1><?= e($screen['name']) ?></h1>
    <div class="sub">
      <?= $screen['device_info'] ? e($screen['device_info']) . ' · ' : '' ?>
      <?= e(t('sv.registered_on', ['date' => date('d/m/Y', strtotime($screen['created_at']))])) ?>
    </div>
  </div>
  <div class="head-actions">
    <a class="btn" href="screen_schedule.php?id=<?= (int) $screen['id'] ?>"><?= e(t('sch.title')) ?></a>
    <a class="btn" href="screen_overlay.php?id=<?= (int) $screen['id'] ?>"><?= e(t('ovp.title')) ?></a>
    <?= screen_badge($screen, $onlineWindow) ?>
  </div>
</div>

<div class="card">
  <h2><?= e(t('common.settings')) ?></h2>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="settings">
    <div class="field" style="flex:2">
      <label for="name"><?= e(t('screens.name')) ?></label>
      <input type="text" id="name" name="name" maxlength="100" required value="<?= e($screen['name']) ?>">
    </div>
    <div class="field" style="max-width:150px">
      <label for="diagonal_inches"><?= e(t('snew.inches')) ?></label>
      <input type="number" id="diagonal_inches" name="diagonal_inches" min="0" max="500"
             placeholder="<?= e(t('snew.inches_ph')) ?>" value="<?= $screen['diagonal_inches'] !== null ? (int) $screen['diagonal_inches'] : '' ?>">
    </div>
    <div class="field" style="max-width:190px">
      <label for="orientation"><?= e(t('snew.orientation')) ?></label>
      <select id="orientation" name="orientation">
        <option value="landscape"<?= $screen['orientation'] === 'landscape' ? ' selected' : '' ?>><?= e(t('snew.landscape')) ?></option>
        <option value="portrait"<?= $screen['orientation'] === 'portrait' ? ' selected' : '' ?>><?= e(t('snew.portrait')) ?></option>
      </select>
    </div>
    <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
  </form>
  <p class="hint" style="margin:12px 0 0"><?= e(t('sv.settings_hint')) ?></p>
</div>

<?php if ($screen['status'] === 'pending' && $screen['claim_token'] !== null): ?>
<div class="card">
  <h2><?= e(t('sv.claim_title')) ?></h2>
  <p class="hint"><?= e(t('sv.claim_hint')) ?></p>
  <div class="copy-row">
    <input type="text" readonly id="claim-url"
           value="<?= e(config('base_url') . '/api/v1/claim.php?token=' . $screen['claim_token']) ?>">
    <button type="button" class="btn" data-copy="#claim-url"><?= e(t('screens.copy')) ?></button>
  </div>
</div>
<?php endif; ?>

<?php $previewScreen = (int) $screen['id']; require_once __DIR__ . '/_preview.php'; ?>

<div class="card">
  <h2><?= e(t('sv.galleries')) ?></h2>
  <p class="hint"><?= e(t('sv.galleries_hint')) ?></p>
  <ul class="sort-list" id="screen-galleries" data-screen="<?= (int) $screen['id'] ?>">
    <?php foreach ($assigned as $g): ?>
    <li data-id="<?= (int) $g['id'] ?>">
      <span class="drag">⠿</span>
      <span class="grow"><?= e($g['name']) ?></span>
      <button type="button" class="remove" title="<?= e(t('sv.remove')) ?>">×</button>
    </li>
    <?php endforeach; ?>
  </ul>
  <div class="field" style="margin-top:14px;max-width:320px">
    <label for="gallery-add"><?= e(t('sv.add_gallery')) ?></label>
    <select id="gallery-add">
      <option value=""><?= e(t('sv.choose')) ?></option>
      <?php foreach ($available as $g): ?>
      <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (!$assigned && !$available): ?>
    <p class="hint"><?= t('sv.no_galleries', [
      'link' => '<a href="galleries.php">' . e(t('sv.no_galleries_link')) . '</a>',
    ]) ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= e(t('sv.schedule')) ?></h2>
  <p class="hint"><?= e(t('sv.schedule_moved')) ?></p>
  <dl class="info-grid">
    <dt><?= e(t('sch.th_schedule')) ?></dt><dd><?= schedule_badge($scheduleBands) ?></dd>
    <dt><?= e(t('sch.th_now')) ?></dt>
    <dd><?= e(schedule_active_now($scheduleBands) ? t('sch.on_now') : t('sch.off_now')) ?></dd>
    <dt><?= e(t('sch.th_idle')) ?></dt>
    <dd><?= e($screen['idle_mode'] === 'image' ? t('sv.idle_image') : t('sv.idle_black')) ?></dd>
  </dl>
  <div class="form-actions">
    <a class="btn btn-primary" href="screen_schedule.php?id=<?= (int) $screen['id'] ?>"><?= e(t('sch.configure')) ?></a>
  </div>
</div>

<div class="card">
  <h2><?= e(t('sv.danger')) ?></h2>
  <div class="form-row">
    <?php if ($screen['status'] === 'paired'): ?>
    <form method="post" onsubmit="return confirm('<?= e(t('sv.revoke_confirm')) ?>')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="revoke">
      <button class="btn btn-danger" type="submit"><?= e(t('sv.revoke_btn')) ?></button>
    </form>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('<?= e(t('sv.delete_confirm')) ?>')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="delete">
      <button class="btn btn-danger" type="submit"><?= e(t('sv.delete_btn')) ?></button>
    </form>
  </div>
</div>
<?php cms_footer();