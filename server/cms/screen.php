<?php
declare(strict_types=1);

// Dettaglio schermo: rinomina, gallerie assegnate (drag & drop),
// URL di configurazione manuale, revoca ed eliminazione.
require __DIR__ . '/_cms.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM screens WHERE id = ?');
$stmt->execute([$id]);
$screen = $stmt->fetch();
if ($screen === false) {
    flash_set('err', 'Schermo non trovato');
    redirect('screens.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'rename') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 100) {
            $pdo->prepare('UPDATE screens SET name = ? WHERE id = ?')->execute([$name, $id]);
            flash_set('ok', 'Nome aggiornato');
        } else {
            flash_set('err', 'Nome non valido');
        }
    } elseif ($do === 'revoke') {
        // Il token resta in tabella ma lo status blocca ogni richiesta
        $pdo->prepare("UPDATE screens SET status = 'revoked' WHERE id = ?")->execute([$id]);
        flash_set('ok', 'Accesso revocato: lo schermo non riceverà più contenuti');
    } elseif ($do === 'delete') {
        $pdo->prepare('DELETE FROM screens WHERE id = ?')->execute([$id]);
        flash_set('ok', 'Schermo eliminato');
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
    $pdo->query('SELECT id, name FROM galleries ORDER BY name')->fetchAll(),
    fn (array $g): bool => !in_array($g['id'], $assignedIds, true)
);

$onlineWindow = (int) config('poll_interval') * 2 + 30;

cms_header($screen['name'], 'screens');
?>
<div class="main-head">
  <div>
    <h1><?= e($screen['name']) ?></h1>
    <div class="sub">
      <?= $screen['device_info'] ? e($screen['device_info']) . ' · ' : '' ?>
      registrato il <?= date('d/m/Y', strtotime($screen['created_at'])) ?>
    </div>
  </div>
  <?= screen_badge($screen, $onlineWindow) ?>
</div>

<div class="card">
  <h2>Impostazioni</h2>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="rename">
    <div class="field">
      <label for="name">Nome schermo</label>
      <input type="text" id="name" name="name" maxlength="100" required value="<?= e($screen['name']) ?>">
    </div>
    <button class="btn" type="submit">Rinomina</button>
  </form>
</div>

<?php if ($screen['status'] === 'pending' && $screen['claim_token'] !== null): ?>
<div class="card">
  <h2>URL di configurazione manuale</h2>
  <p class="hint">Da inserire nelle impostazioni dell'app sulla TV. Monouso:
  sparirà appena lo schermo si collega.</p>
  <div class="copy-row">
    <input type="text" readonly id="claim-url"
           value="<?= e(config('base_url') . '/api/v1/claim.php?token=' . $screen['claim_token']) ?>">
    <button type="button" class="btn" data-copy="#claim-url">Copia</button>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Gallerie assegnate</h2>
  <p class="hint">Trascina per cambiare l'ordine di riproduzione. Le modifiche
  si salvano da sole e arrivano alla TV al prossimo aggiornamento.</p>
  <ul class="sort-list" id="screen-galleries" data-screen="<?= (int) $screen['id'] ?>">
    <?php foreach ($assigned as $g): ?>
    <li data-id="<?= (int) $g['id'] ?>">
      <span class="drag">⠿</span>
      <span class="grow"><?= e($g['name']) ?></span>
      <button type="button" class="remove" title="Rimuovi">×</button>
    </li>
    <?php endforeach; ?>
  </ul>
  <div class="field" style="margin-top:14px;max-width:320px">
    <label for="gallery-add">Aggiungi galleria</label>
    <select id="gallery-add">
      <option value="">Scegli…</option>
      <?php foreach ($available as $g): ?>
      <option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (!$assigned && !$available): ?>
    <p class="hint">Non hai ancora gallerie: <a href="galleries.php">creane una</a>.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Zona pericolosa</h2>
  <div class="form-row">
    <?php if ($screen['status'] === 'paired'): ?>
    <form method="post" onsubmit="return confirm('Revocare l\'accesso di questo schermo?')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="revoke">
      <button class="btn btn-danger" type="submit">Revoca accesso</button>
    </form>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Eliminare definitivamente questo schermo?')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="delete">
      <button class="btn btn-danger" type="submit">Elimina schermo</button>
    </form>
  </div>
</div>
<?php cms_footer();