<?php
declare(strict_types=1);

// Gestione media di una galleria: upload (click o trascinamento),
// riordino drag & drop, metadati per file (rotazione, fit, durata).
require __DIR__ . '/_cms.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ?');
$stmt->execute([$id]);
$gallery = $stmt->fetch();
if ($gallery === false) {
    flash_set('err', 'Galleria non trovata');
    redirect('galleries.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['do'] ?? '') === 'rename') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 100) {
            $pdo->prepare('UPDATE galleries SET name = ? WHERE id = ?')->execute([$name, $id]);
            flash_set('ok', 'Nome aggiornato');
        }
    }
    redirect('gallery.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT * FROM media WHERE gallery_id = ? ORDER BY sort_order, id');
$stmt->execute([$id]);
$media = $stmt->fetchAll();

cms_header($gallery['name'], 'galleries');
?>
<div class="main-head">
  <div>
    <h1><?= e($gallery['name']) ?></h1>
    <div class="sub"><?= count($media) ?> file · trascina le anteprime per ordinare</div>
  </div>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="rename">
    <div class="field">
      <input type="text" name="name" maxlength="100" required value="<?= e($gallery['name']) ?>" aria-label="Nome galleria">
    </div>
    <button class="btn btn-sm" type="submit">Rinomina</button>
  </form>
</div>

<div class="dropzone" id="dropzone" data-gallery="<?= (int) $gallery['id'] ?>">
  <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm">
  <div class="dz-label">Trascina qui immagini o video, oppure clicca per scegliere</div>
  <div class="hint">JPEG, PNG, WebP, GIF, MP4, WebM — max <?= human_bytes((int) config('max_upload_bytes')) ?> per file</div>
</div>

<div class="media-grid" id="media-grid" data-gallery="<?= (int) $gallery['id'] ?>">
  <?php foreach ($media as $m): ?>
  <div class="media-card" data-id="<?= (int) $m['id'] ?>">
    <div class="media-thumb" data-rot="<?= (int) $m['rotation'] ?>" data-fit="<?= e($m['fit']) ?>" title="Trascina per ordinare">
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
          <label>Rotazione</label>
          <select data-media-field="rotation">
            <?php foreach ([0, 90, 180, 270] as $deg): ?>
            <option value="<?= $deg ?>"<?= (int) $m['rotation'] === $deg ? ' selected' : '' ?>><?= $deg ?>°</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Adattamento</label>
          <select data-media-field="fit">
            <option value="contain"<?= $m['fit'] === 'contain' ? ' selected' : '' ?>>Contain</option>
            <option value="cover"<?= $m['fit'] === 'cover' ? ' selected' : '' ?>>Crop</option>
          </select>
        </div>
        <?php if ($m['type'] === 'image'): ?>
        <div class="full">
          <label>Durata a schermo (secondi)</label>
          <input type="number" min="1" max="3600" data-media-field="duration"
                 value="<?= (int) $m['duration_seconds'] ?>">
        </div>
        <?php endif; ?>
      </div>
      <div class="media-foot">
        <span class="hint"><?= human_bytes((int) $m['filesize']) ?></span>
        <span style="display:flex;align-items:center;gap:10px">
          <span class="save-dot"></span>
          <button type="button" class="btn btn-sm btn-danger" data-media-delete>Elimina</button>
        </span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php cms_footer();