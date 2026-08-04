<?php
declare(strict_types=1);

// La lista è visibile a tutti (serve per caricare i media); creare ed
// eliminare gallerie richiede gallery.manage
require __DIR__ . '/_cms.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    require_cap('gallery.manage');
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 100) {
            $pdo->prepare('INSERT INTO galleries (name) VALUES (?)')->execute([$name]);
            flash_set('ok', 'Galleria creata');
            redirect('gallery.php?id=' . (int) $pdo->lastInsertId());
        }
        flash_set('err', 'Nome non valido (1-100 caratteri)');
    } elseif ($do === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Elimina prima i file da disco, poi la riga (cascade sui media)
        $stmt = $pdo->prepare('SELECT filename FROM media WHERE gallery_id = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $filename) {
            $path = UPLOADS_DIR . '/' . basename($filename);
            if (is_file($path)) {
                unlink($path);
            }
        }
        $pdo->prepare('DELETE FROM galleries WHERE id = ?')->execute([$id]);
        // Schermi che usavano un'immagine di questa galleria come cortesia
        $pdo->query("UPDATE screens SET idle_mode = 'black'
                     WHERE idle_mode = 'image' AND idle_media_id IS NULL");
        flash_set('ok', 'Galleria eliminata');
    }
    redirect('galleries.php');
}

$galleries = $pdo->query('SELECT g.*, COUNT(m.id) AS n_media,
                                 COALESCE(SUM(m.filesize), 0) AS bytes,
                                 (SELECT COUNT(*) FROM screen_galleries sg WHERE sg.gallery_id = g.id) AS n_screens
                          FROM galleries g
                          LEFT JOIN media m ON m.gallery_id = g.id
                          GROUP BY g.id
                          ORDER BY g.name')->fetchAll();

cms_header('Gallerie', 'galleries');
?>
<div class="main-head">
  <div>
    <h1>Gallerie</h1>
    <div class="sub"><?= count($galleries) ?> gallerie</div>
  </div>
</div>

<?php if (current_can('gallery.manage')): ?>
<div class="card">
  <h2>Nuova galleria</h2>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="field">
      <label for="name">Nome</label>
      <input type="text" id="name" name="name" maxlength="100" required placeholder="Es. Promozioni estate">
    </div>
    <button class="btn btn-primary" type="submit">Crea galleria</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Tutte le gallerie</h2>
  <?php if (!$galleries): ?>
    <p class="hint" style="margin:0">Nessuna galleria ancora.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Nome</th><th>Media</th><th>Peso</th><th>Schermi</th><th>Aggiornata</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($galleries as $g): ?>
      <tr>
        <td><strong><?= e($g['name']) ?></strong></td>
        <td><?= (int) $g['n_media'] ?></td>
        <td><?= human_bytes((int) $g['bytes']) ?></td>
        <td><?= (int) $g['n_screens'] ?></td>
        <td class="hint"><?= date('d/m/Y H:i', strtotime($g['updated_at'])) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn btn-sm" href="gallery.php?id=<?= (int) $g['id'] ?>">Apri</a>
          <?php if (current_can('gallery.manage')): ?>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Eliminare la galleria e tutti i suoi file?')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">Elimina</button>
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