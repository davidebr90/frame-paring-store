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
        $rotation = (int) ($_POST['rotation'] ?? 0);
        if ($name === '' || mb_strlen($name) > 100) {
            flash_set('err', t('sv.err_name'));
        } elseif ($inches < 0 || $inches > 500) {
            flash_set('err', t('snew.err_inches'));
        } elseif (!in_array($rotation, [0, 90, 180, 270], true)) {
            flash_set('err', t('snew.err_rotation'));
        } else {
            // L'orientamento non si sceglie più a parte: segue sempre la
            // rotazione, altrimenti tornano a scollarsi (vedi screens.php)
            $orientation = screen_orientation_for_rotation($rotation);
            $pdo->prepare('UPDATE screens SET name = ?, diagonal_inches = ?, orientation = ?, rotation = ? WHERE id = ?')
                ->execute([$name, $inches > 0 ? $inches : null, $orientation, $rotation, $id]);
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

// Adattamento contenuti: tutto ciò che questo schermo mostra tramite le
// sue gallerie, con la rotazione calcolata e l'eventuale correzione manuale
$screenMedia = $pdo->prepare("SELECT m.id, m.type, m.original_name, m.width, m.height, m.rotation, m.fit,
                                     g.name AS gallery_name, smo.rotation AS override_rotation
                              FROM media m
                              JOIN screen_galleries sg ON sg.gallery_id = m.gallery_id
                              JOIN galleries g         ON g.id = m.gallery_id
                              LEFT JOIN screen_media_overrides smo
                                     ON smo.screen_id = sg.screen_id AND smo.media_id = m.id
                              WHERE sg.screen_id = ? AND m.deleted_at IS NULL AND g.deleted_at IS NULL
                              ORDER BY g.name, m.sort_order, m.id");
$screenMedia->execute([$id]);
$screenMedia = array_map(function (array $m) use ($screen): array {
    $m = media_ensure_dimensions($m);
    $m['auto_rotation'] = effective_item_rotation($m, $screen, null);
    return $m;
}, $screenMedia->fetchAll());

cms_header($screen['name'], 'screens');
?>
<div class="main-head">
  <div>
    <h1><?= e($screen['name']) ?></h1>
    <div class="sub">
      <?= $screen['device_info'] ? e($screen['device_info']) . ' · ' : '' ?>
      <?= e(t('sv.registered_on', ['date' => date('d/m/Y', strtotime($screen['created_at']))])) ?>
      <?php if ((int) $screen['restart_count'] > 0 && $screen['last_restart_at']): ?>
        · <?= e(t('sv.restarts', [
            'count'  => (int) $screen['restart_count'],
            'reason' => t('sv.restart_reason.' . ($screen['last_restart_reason'] ?? 'manual')),
            'date'   => date('d/m/Y', strtotime($screen['last_restart_at'])),
            'time'   => date('H:i', strtotime($screen['last_restart_at'])),
        ])) ?>
      <?php endif; ?>
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
    <div class="field" style="max-width:230px">
      <label for="rotation"><?= e(t('snew.rotation')) ?></label>
      <select id="rotation" name="rotation" data-orientation-source>
        <?php foreach ([0, 90, 180, 270] as $deg): ?>
        <option value="<?= $deg ?>"<?= (int) $screen['rotation'] === $deg ? ' selected' : '' ?>><?= e(t('snew.rot_' . $deg)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="max-width:190px">
      <span class="hint"><?= e(t('snew.orientation_derived')) ?></span>
      <div class="mono" data-orientation-label>
        <?= e($screen['orientation'] === 'portrait' ? t('snew.portrait') : t('snew.landscape')) ?>
      </div>
    </div>
    <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
  </form>
  <p class="hint" style="margin:12px 0 0"><?= e(t('sv.settings_hint')) ?></p>
  <p class="hint" style="margin:6px 0 0"><?= e(t('snew.rotation_hint')) ?></p>
  <script>
    (function () {
      var select = document.querySelector('[data-orientation-source]');
      var label = document.querySelector('[data-orientation-label]');
      if (!select || !label) { return; }
      var LANDSCAPE = <?= json_encode(t('snew.landscape')) ?>;
      var PORTRAIT = <?= json_encode(t('snew.portrait')) ?>;
      select.addEventListener('change', function () {
        var deg = Number(select.value);
        label.textContent = (deg === 90 || deg === 270) ? PORTRAIT : LANDSCAPE;
      });
    })();
  </script>
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
  <h2><?= e(t('sv.content_fit')) ?></h2>
  <p class="hint"><?= e(t('sv.content_fit_hint')) ?></p>
  <?php if (!$screenMedia): ?>
    <p class="hint"><?= e(t('sv.content_fit_empty')) ?></p>
  <?php else: ?>
  <div class="cfit-list" data-screen="<?= (int) $screen['id'] ?>">
    <?php foreach ($screenMedia as $m):
        $effective = $m['override_rotation'] !== null ? (int) $m['override_rotation'] : (int) $m['auto_rotation'];
    ?>
    <div class="cfit-row" data-id="<?= (int) $m['id'] ?>">
      <div class="media-thumb" data-rot="<?= $effective ?>" data-fit="<?= e($m['fit']) ?>">
        <?php if ($m['type'] === 'image'): ?>
          <img src="media_preview.php?id=<?= (int) $m['id'] ?>" alt="" loading="lazy">
        <?php else: ?>
          <video src="media_preview.php?id=<?= (int) $m['id'] ?>" preload="metadata" muted></video>
        <?php endif; ?>
      </div>
      <div class="cfit-info">
        <div class="cfit-name" title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></div>
        <div class="hint">
          <?= e($m['gallery_name']) ?>
          <?php if ($m['type'] === 'image' && $m['width'] !== null): ?>
            · <?= e((int) $m['height'] > (int) $m['width'] ? t('sv.photo_portrait') : t('sv.photo_landscape')) ?>
          <?php endif; ?>
          · <?= e(t('sv.auto_rotation', ['deg' => (string) $m['auto_rotation']])) ?>
        </div>
      </div>
      <select data-screen-media-rotation data-id="<?= (int) $m['id'] ?>" data-auto="<?= (int) $m['auto_rotation'] ?>">
        <option value=""<?= $m['override_rotation'] === null ? ' selected' : '' ?>>
          <?= e(t('sv.rotation_auto_option', ['deg' => (string) $m['auto_rotation']])) ?>
        </option>
        <?php foreach ([0, 90, 180, 270] as $deg): ?>
        <option value="<?= $deg ?>"<?= $m['override_rotation'] !== null && (int) $m['override_rotation'] === $deg ? ' selected' : '' ?>><?= $deg ?>°</option>
        <?php endforeach; ?>
      </select>
      <span class="save-dot" data-save-dot></span>
    </div>
    <?php endforeach; ?>
  </div>
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