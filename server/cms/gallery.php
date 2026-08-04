<?php
declare(strict_types=1);

// Gestione media di una galleria: upload (click o trascinamento),
// riordino drag & drop, metadati per file (rotazione, fit, durata).
require __DIR__ . '/_cms.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$gallery = $stmt->fetch();
if ($gallery === false) {
    flash_set('err', 'Galleria non trovata');
    redirect('galleries.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_cap('gallery.manage'); // rinomina e impostazioni di riproduzione
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'rename') {
        if ($gallery['system_key'] !== null) {
            flash_set('err', 'Le gallerie di sistema non si possono rinominare');
            redirect('gallery.php?id=' . $id);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 100) {
            $pdo->prepare('UPDATE galleries SET name = ? WHERE id = ?')->execute([$name, $id]);
            flash_set('ok', 'Nome aggiornato');
        }
    } elseif ($do === 'settings') {
        $transition = (string) ($_POST['transition_default'] ?? '');
        $durationMs = (int) ($_POST['transition_duration_ms'] ?? -1);
        if (!isset(TRANSITIONS[$transition])) {
            flash_set('err', 'Transizione non valida');
        } elseif ($durationMs < 0 || $durationMs > 10000) {
            flash_set('err', 'Durata transizione non valida (0-10000 ms)');
        } else {
            $pdo->prepare('UPDATE galleries SET transition_default = ?, transition_duration_ms = ? WHERE id = ?')
                ->execute([$transition, $durationMs, $id]);
            flash_set('ok', 'Impostazioni di riproduzione salvate');
        }
    }
    redirect('gallery.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT * FROM media WHERE gallery_id = ? AND deleted_at IS NULL ORDER BY sort_order, id');
$stmt->execute([$id]);
$media = $stmt->fetchAll();

cms_header($gallery['name'], 'galleries');
?>
<div class="main-head">
  <div>
    <h1><?= e($gallery['name']) ?></h1>
    <div class="sub"><?= count($media) ?> file · trascina le anteprime per ordinare</div>
  </div>
  <?php if ($gallery['system_key'] === null): ?>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="rename">
    <div class="field">
      <input type="text" name="name" maxlength="100" required value="<?= e($gallery['name']) ?>" aria-label="Nome galleria">
    </div>
    <button class="btn btn-sm" type="submit">Rinomina</button>
  </form>
  <?php else: ?>
  <span class="badge badge-system">galleria di sistema</span>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Riproduzione</h2>
  <p class="hint">La transizione di default vale per tutti i media della galleria;
  ogni file può fare override di entrata e uscita nella propria card.</p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="settings">
    <div class="field">
      <label for="transition_default">Transizione di default</label>
      <select id="transition_default" name="transition_default">
        <?php foreach (TRANSITIONS as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $gallery['transition_default'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="transition_duration_ms">Durata transizione (ms)</label>
      <input type="number" id="transition_duration_ms" name="transition_duration_ms"
             min="0" max="10000" step="50" value="<?= (int) $gallery['transition_duration_ms'] ?>">
    </div>
    <button class="btn" type="submit">Salva</button>
  </form>
</div>

<?php if ($media): $previewGallery = (int) $gallery['id']; require __DIR__ . '/_preview.php'; endif; ?>

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
        <div>
          <label>Entrata</label>
          <select data-media-field="transition_in">
            <option value="">Default</option>
            <?php foreach (TRANSITIONS_SHORT as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $m['transition_in'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Uscita</label>
          <select data-media-field="transition_out">
            <option value="">Default</option>
            <?php foreach (TRANSITIONS_SHORT as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $m['transition_out'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($m['type'] === 'image'): ?>
        <div class="full">
          <label>Durata a schermo (secondi)</label>
          <input type="number" min="1" max="3600" data-media-field="duration"
                 value="<?= (int) $m['duration_seconds'] ?>">
        </div>
        <?php else: ?>
        <div class="full">
          <label>Audio</label>
          <select data-media-field="muted">
            <option value="1"<?= (int) $m['muted'] === 1 ? ' selected' : '' ?>>Muto (solo video)</option>
            <option value="0"<?= (int) $m['muted'] === 0 ? ' selected' : '' ?>>Con audio</option>
          </select>
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