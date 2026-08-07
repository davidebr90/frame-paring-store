<?php
declare(strict_types=1);

// La lista è visibile a tutti (serve per caricare i media); creare ed
// eliminare gallerie richiede gallery.manage
require_once __DIR__ . '/_cms.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_cap('gallery.manage');
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 100) {
            $pdo->prepare('INSERT INTO galleries (name) VALUES (?)')->execute([$name]);
            flash_set('ok', t('gal.created'));
            redirect('gallery.php?id=' . (int) $pdo->lastInsertId());
        }
        flash_set('err', t('gal.err_name'));
    } elseif ($do === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        // Le gallerie di sistema non si eliminano: sono referenziate dal
        // funzionamento del CMS (es. immagini di cortesia degli schermi)
        if (!trash_gallery($id, (int) current_user()['id'])) {
            flash_set('err', t('gal.err_system'));
            redirect('galleries.php');
        }

        // Schermi che usavano come cortesia un'immagine finita nel cestino
        $pdo->prepare("UPDATE screens SET idle_mode = 'black', idle_media_id = NULL
                       WHERE idle_media_id IN (SELECT id FROM media WHERE gallery_id = ?)")
            ->execute([$id]);
        flash_set('ok', t('gal.trashed'));
    }
    redirect('galleries.php');
}

// Anche chi aggiorna da una versione precedente deve trovarla: costa una
// query indicizzata, e solo la prima volta fa qualcosa
courtesy_gallery_id();

$galleries = $pdo->query('SELECT g.*, COUNT(m.id) AS n_media,
                                 COALESCE(SUM(m.filesize), 0) AS bytes,
                                 (SELECT COUNT(*) FROM screen_galleries sg WHERE sg.gallery_id = g.id) AS n_screens
                          FROM galleries g
                          LEFT JOIN media m ON m.gallery_id = g.id AND m.deleted_at IS NULL
                          WHERE g.deleted_at IS NULL
                          GROUP BY g.id
                          ORDER BY g.name')->fetchAll();

cms_header(t('gal.title'), 'galleries');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('gal.title')) ?></h1>
    <div class="sub"><?= e(t('gal.count', ['n' => count($galleries)])) ?></div>
  </div>
  <?php if (current_can('gallery.manage')): ?>
  <a class="btn btn-primary" href="gallery_new.php"><?= e(t('gal.add')) ?></a>
  <?php endif; ?>
</div>

<?php if (current_can('gallery.manage')): ?>
<div class="card">
  <h2><?= e(t('gal.quick')) ?></h2>
  <p class="hint"><?= t('gal.quick_hint', ['link' => '<a href="gallery_new.php">' . e(t('nav.galleries.new')) . '</a>']) ?></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="field">
      <label for="name"><?= e(t('common.name')) ?></label>
      <input type="text" id="name" name="name" maxlength="100" required placeholder="<?= e(t('gal.name_ph')) ?>">
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('gal.create')) ?></button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= e(t('gal.all')) ?></h2>
  <?php if (!$galleries): ?>
    <p class="hint" style="margin:0"><?= e(t('gal.empty')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= e(t('common.name')) ?></th><th><?= e(t('gal.th_media')) ?></th><th><?= e(t('gal.th_size')) ?></th><th><?= e(t('gal.th_screens')) ?></th><th><?= e(t('gal.th_updated')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($galleries as $g): ?>
      <tr>
        <td><strong><?= e($g['name']) ?></strong>
          <?php if ($g['system_key'] !== null): ?>
          <span class="badge badge-system" title="<?= e(t('gal.system_title')) ?>"><?= e(t('gal.system_badge')) ?></span>
          <?php endif; ?>
        </td>
        <td><?= (int) $g['n_media'] ?></td>
        <td><?= human_bytes((int) $g['bytes']) ?></td>
        <td><?= (int) $g['n_screens'] ?></td>
        <td class="hint"><?= date('d/m/Y H:i', strtotime($g['updated_at'])) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn btn-sm" href="gallery.php?id=<?= (int) $g['id'] ?>"><?= e(t('gal.open')) ?></a>
          <?php if (current_can('gallery.manage') && $g['system_key'] === null): ?>
          <form method="post" style="display:inline"
                onsubmit="return confirm('<?= e(t('gal.confirm_delete')) ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit"><?= e(t('common.delete')) ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php cms_footer();