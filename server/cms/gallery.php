<?php
declare(strict_types=1);

// Gestione media di una galleria: upload (click o trascinamento),
// riordino drag & drop, metadati per file (rotazione, fit, durata).
require_once __DIR__ . '/_cms.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$gallery = $stmt->fetch();
if ($gallery === false) {
    flash_set('err', t('gv.not_found'));
    redirect('galleries.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_cap('gallery.manage'); // rinomina e impostazioni di riproduzione
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'rename') {
        if ($gallery['system_key'] !== null) {
            flash_set('err', t('gv.err_rename_system'));
            redirect('gallery.php?id=' . $id);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 100) {
            $pdo->prepare('UPDATE galleries SET name = ? WHERE id = ?')->execute([$name, $id]);
            flash_set('ok', t('gv.renamed'));
        }
    } elseif ($do === 'restore_system') {
        if ($gallery['system_key'] === null) {
            flash_set('err', t('gv.err_rename_system'));
            redirect('gallery.php?id=' . $id);
        }
        $n = restore_system_media($id);
        flash_set('ok', $n > 0 ? t('sys.restored', ['n' => $n]) : t('sys.restored_none'));
        redirect('gallery.php?id=' . $id);
    } elseif ($do === 'settings') {
        $transition = (string) ($_POST['transition_default'] ?? '');
        $durationMs = (int) ($_POST['transition_duration_ms'] ?? -1);
        if (!isset(TRANSITIONS[$transition])) {
            flash_set('err', t('gnew.err_transition'));
        } elseif ($durationMs < 0 || $durationMs > 10000) {
            flash_set('err', t('gv.err_duration'));
        } else {
            $pdo->prepare('UPDATE galleries SET transition_default = ?, transition_duration_ms = ? WHERE id = ?')
                ->execute([$transition, $durationMs, $id]);
            flash_set('ok', t('gv.settings_saved'));
        }
    }
    redirect('gallery.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT * FROM media WHERE gallery_id = ? AND deleted_at IS NULL ORDER BY sort_order, id');
$stmt->execute([$id]);
$media = $stmt->fetchAll();

// Schermi a cui questa galleria è davvero assegnata: la correzione manuale
// della rotazione ha senso solo lì, l'override è per la coppia
// schermo+media (vedi screen_media_overrides in schema.sql). Serve anche
// "in emergenza" senza dover uscire dalla scheda Contenuti — vedi
// gv.rotation_override_hint. Gate dietro screen.manage: stessa capability
// della pagina schermo dove questa funzione è nata.
$galleryScreens = [];
$galleryRotations = [];
if (current_can('screen.manage')) {
    $stmt = $pdo->prepare('SELECT s.id, s.name FROM screens s
                           JOIN screen_galleries sg ON sg.screen_id = s.id
                           WHERE sg.gallery_id = ? ORDER BY s.name');
    $stmt->execute([$id]);
    $galleryScreens = $stmt->fetchAll();

    if ($galleryScreens) {
        $stmt = $pdo->prepare('SELECT * FROM screens WHERE id = ?');
        $stmt->execute([(int) $galleryScreens[0]['id']]);
        $refScreen = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT m.id, m.type, m.filename, m.width, m.height,
                                       smo.rotation AS override_rotation
                               FROM media m
                               LEFT JOIN screen_media_overrides smo
                                      ON smo.screen_id = ? AND smo.media_id = m.id
                               WHERE m.gallery_id = ? AND m.deleted_at IS NULL');
        $stmt->execute([(int) $refScreen['id'], $id]);
        foreach ($stmt->fetchAll() as $row) {
            $row = media_ensure_dimensions($row);
            $galleryRotations[(int) $row['id']] = [
                'auto'     => effective_item_rotation($row, $refScreen, null),
                'override' => $row['override_rotation'] !== null ? (int) $row['override_rotation'] : null,
            ];
        }
    }
}

cms_header($gallery['name'], 'galleries');
?>
<div class="main-head">
  <div>
    <h1><?= e($gallery['name']) ?></h1>
    <div class="sub"><?= e(t('gv.subtitle', ['n' => count($media)])) ?></div>
  </div>
  <?php if ($gallery['system_key'] === null): ?>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="rename">
    <div class="field">
      <input type="text" name="name" maxlength="100" required value="<?= e($gallery['name']) ?>" aria-label="<?= e(t('gv.name_aria')) ?>">
    </div>
    <button class="btn btn-sm" type="submit"><?= e(t('gv.rename')) ?></button>
  </form>
  <?php else: ?>
  <span class="badge badge-system"><?= e(t('gv.system_badge')) ?></span>
  <?php endif; ?>
</div>

<?php if ($gallery['system_key'] !== null): ?>
<div class="card">
  <h2><?= e(t('gv.system_badge')) ?></h2>
  <p class="hint"><?= e(t('sys.gallery_hint')) ?></p>
  <?php if (current_can('gallery.manage')): ?>
  <p class="hint" style="margin-top:10px"><?= e(t('sys.restore_hint')) ?></p>
  <form method="post" class="form-actions">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="restore_system">
    <button class="btn" type="submit"><?= e(t('sys.restore')) ?></button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= e(t('gv.playback')) ?></h2>
  <p class="hint"><?= e(t('gv.playback_hint')) ?></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="settings">
    <div class="field">
      <label for="transition_default"><?= e(t('gnew.transition')) ?></label>
      <select id="transition_default" name="transition_default">
        <?php foreach (transition_labels() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $gallery['transition_default'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="transition_duration_ms"><?= e(t('gnew.duration')) ?></label>
      <input type="number" id="transition_duration_ms" name="transition_duration_ms"
             min="0" max="10000" step="50" value="<?= (int) $gallery['transition_duration_ms'] ?>">
    </div>
    <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
  </form>
</div>

<?php if ($media):
    $previewGallery = (int) $gallery['id'];
    $previewScreenOptions = $pdo->query('SELECT id, name FROM screens ORDER BY name')->fetchAll();
    require_once __DIR__ . '/_preview.php';
endif; ?>

<div class="dropzone" id="dropzone" data-gallery="<?= (int) $gallery['id'] ?>">
  <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm">
  <div class="dz-label"><?= e(t('gv.dropzone')) ?></div>
  <div class="hint"><?= e(t('gv.dropzone_hint', ['size' => human_bytes((int) config('max_upload_bytes'))])) ?></div>
  <div class="dz-progress" hidden>
    <div class="dz-progress-bar"></div>
  </div>
</div>

<?php if (!current_can('screen.manage') || !$galleryScreens): ?>
<p class="hint"><?= e(current_can('screen.manage') ? t('gv.rotation_no_screens') : t('gv.rotation_moved')) ?></p>
<?php else: ?>
<div class="field" style="max-width:320px;margin:4px 0 6px">
  <label for="rotation-screen-select"><?= e(t('gv.rotation_screen_picker')) ?></label>
  <select id="rotation-screen-select">
    <?php foreach ($galleryScreens as $s): ?>
    <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<p class="hint"><?= e(t('gv.rotation_override_hint')) ?></p>
<script>var GV_ROTATION_AUTO_LABEL = <?= json_encode(t('sv.rotation_auto_option'), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<div class="media-grid" id="media-grid" data-gallery="<?= (int) $gallery['id'] ?>"<?= $galleryScreens ? ' data-screen="' . (int) $galleryScreens[0]['id'] . '"' : '' ?>>
  <?php foreach ($media as $m):
      $rot = $galleryScreens ? ($galleryRotations[(int) $m['id']] ?? ['auto' => 0, 'override' => null]) : null;
      $effectiveRot = $rot ? ($rot['override'] !== null ? $rot['override'] : $rot['auto']) : 0;
  ?>
  <div class="media-card" data-id="<?= (int) $m['id'] ?>">
    <div class="media-thumb" data-rot="<?= $effectiveRot ?>" data-fit="<?= e($m['fit']) ?>" title="<?= e(t('gv.drag_title')) ?>">
      <span class="media-type"><?= $m['type'] === 'video' ? 'video' : 'img' ?></span>
      <?php if ($m['type'] === 'image'): ?>
        <img src="media_preview.php?id=<?= (int) $m['id'] ?>" alt="" loading="lazy">
      <?php else: ?>
        <video src="media_preview.php?id=<?= (int) $m['id'] ?>" preload="metadata" muted></video>
      <?php endif; ?>
    </div>
    <div class="media-body">
      <div class="media-name" title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></div>
      <div class="media-controls">
        <div>
          <label><?= e(t('gv.fit')) ?></label>
          <select data-media-field="fit">
            <option value="contain"<?= $m['fit'] === 'contain' ? ' selected' : '' ?>><?= e(t('gv.fit_contain')) ?></option>
            <option value="cover"<?= $m['fit'] === 'cover' ? ' selected' : '' ?>><?= e(t('gv.fit_cover')) ?></option>
          </select>
        </div>
        <?php if ($rot): ?>
        <div>
          <label><?= e(t('gv.rotation')) ?></label>
          <select data-screen-media-rotation data-id="<?= (int) $m['id'] ?>" data-auto="<?= (int) $rot['auto'] ?>">
            <option value=""<?= $rot['override'] === null ? ' selected' : '' ?>>
              <?= e(t('sv.rotation_auto_option', ['deg' => (string) $rot['auto']])) ?>
            </option>
            <?php foreach ([0, 90, 180, 270] as $deg): ?>
            <option value="<?= $deg ?>"<?= $rot['override'] !== null && $rot['override'] === $deg ? ' selected' : '' ?>><?= $deg ?>°</option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div>
          <label><?= e(t('gv.trans_in')) ?></label>
          <select data-media-field="transition_in">
            <option value=""><?= e(t('gv.default')) ?></option>
            <?php foreach (transition_labels_short() as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $m['transition_in'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label><?= e(t('gv.trans_out')) ?></label>
          <select data-media-field="transition_out">
            <option value=""><?= e(t('gv.default')) ?></option>
            <?php foreach (transition_labels_short() as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $m['transition_out'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($m['type'] === 'image'): ?>
        <div class="full">
          <label><?= e(t('gv.duration')) ?></label>
          <input type="number" min="1" max="3600" data-media-field="duration"
                 value="<?= (int) $m['duration_seconds'] ?>">
        </div>
        <?php else: ?>
        <div class="full">
          <label><?= e(t('gv.audio')) ?></label>
          <select data-media-field="muted">
            <option value="1"<?= (int) $m['muted'] === 1 ? ' selected' : '' ?>><?= e(t('gv.audio_muted')) ?></option>
            <option value="0"<?= (int) $m['muted'] === 0 ? ' selected' : '' ?>><?= e(t('gv.audio_on')) ?></option>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <div class="media-foot">
        <span class="hint"><?= human_bytes((int) $m['filesize']) ?></span>
        <span style="display:flex;align-items:center;gap:10px">
          <span class="save-dot"></span>
          <button type="button" class="btn btn-sm btn-danger" data-media-delete><?= e(t('common.delete')) ?></button>
        </span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php cms_footer();