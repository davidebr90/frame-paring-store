<?php
declare(strict_types=1);

// Dettaglio schermo: rinomina, gallerie assegnate (drag & drop),
// URL di configurazione manuale, revoca ed eliminazione.
require __DIR__ . '/_cms.php';
require_cap('screen.manage');

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

    if ($do === 'settings') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $inches = (int) ($_POST['diagonal_inches'] ?? 0);
        $orientation = (string) ($_POST['orientation'] ?? 'landscape');
        if ($name === '' || mb_strlen($name) > 100) {
            flash_set('err', 'Nome non valido');
        } elseif ($inches < 0 || $inches > 500) {
            flash_set('err', 'Polliciaggio non valido');
        } elseif (!in_array($orientation, ['landscape', 'portrait'], true)) {
            flash_set('err', 'Orientamento non valido');
        } else {
            $pdo->prepare('UPDATE screens SET name = ?, diagonal_inches = ?, orientation = ? WHERE id = ?')
                ->execute([$name, $inches > 0 ? $inches : null, $orientation, $id]);
            flash_set('ok', 'Impostazioni schermo salvate');
        }
    } elseif ($do === 'schedule') {
        $raw = (string) ($_POST['schedule_json'] ?? '[]');
        $decoded = json_decode($raw, true);
        $bands = [];
        $invalid = false;

        if (!is_array($decoded)) {
            // JSON illeggibile: non si cancella in silenzio la programmazione
            $invalid = true;
        } else {
            foreach ($decoded as $band) {
                if (isset($band['from'], $band['to'])
                    && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $band['from'])
                    && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $band['to'])
                    && $band['from'] !== $band['to']) {
                    $bands[] = ['from' => $band['from'], 'to' => $band['to']];
                } else {
                    $invalid = true;
                }
            }
        }

        if ($invalid) {
            flash_set('err', 'Fasce orarie non valide: controlla gli orari (inizio e fine devono essere diversi). Nessuna modifica salvata.');
            redirect('screen.php?id=' . $id);
        }

        $idleMode = ($_POST['idle_mode'] ?? 'black') === 'image' ? 'image' : 'black';
        $idleMediaId = (int) ($_POST['idle_media_id'] ?? 0);
        if ($idleMode === 'image') {
            $check = $pdo->prepare("SELECT id FROM media WHERE id = ? AND type = 'image'");
            $check->execute([$idleMediaId]);
            if ($check->fetchColumn() === false) {
                // Nessuna immagine valida scelta: si ricade sul nero, senza stati incoerenti
                $idleMode = 'black';
                $idleMediaId = 0;
            }
        }

        $pdo->prepare('UPDATE screens SET schedule_json = ?, idle_mode = ?, idle_media_id = ? WHERE id = ?')
            ->execute([
                $bands ? json_encode($bands) : null,
                $idleMode,
                $idleMode === 'image' ? $idleMediaId : null,
                $id,
            ]);
        flash_set('ok', $bands
            ? 'Programmazione salvata: ' . count($bands) . ' fasce attive'
            : 'Programmazione rimossa: schermo sempre attivo');
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

$scheduleBands = $screen['schedule_json'] !== null
    ? (json_decode($screen['schedule_json'], true) ?: [])
    : [];
$imageMedia = $pdo->query("SELECT m.id, m.original_name, g.name AS gallery_name
                           FROM media m JOIN galleries g ON g.id = m.gallery_id
                           WHERE m.type = 'image'
                           ORDER BY g.name, m.original_name")->fetchAll();

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
    <input type="hidden" name="do" value="settings">
    <div class="field" style="flex:2">
      <label for="name">Nome schermo</label>
      <input type="text" id="name" name="name" maxlength="100" required value="<?= e($screen['name']) ?>">
    </div>
    <div class="field" style="max-width:150px">
      <label for="diagonal_inches">Dimensione (pollici)</label>
      <input type="number" id="diagonal_inches" name="diagonal_inches" min="0" max="500"
             placeholder="es. 65" value="<?= $screen['diagonal_inches'] !== null ? (int) $screen['diagonal_inches'] : '' ?>">
    </div>
    <div class="field" style="max-width:190px">
      <label for="orientation">Orientamento</label>
      <select id="orientation" name="orientation">
        <option value="landscape"<?= $screen['orientation'] === 'landscape' ? ' selected' : '' ?>>Orizzontale</option>
        <option value="portrait"<?= $screen['orientation'] === 'portrait' ? ' selected' : '' ?>>Verticale</option>
      </select>
    </div>
    <button class="btn" type="submit">Salva</button>
  </form>
  <p class="hint" style="margin:12px 0 0">Dimensione e orientamento descrivono il pannello fisico:
  ti guidano nella scelta di rotazione e adattamento dei media nelle gallerie.</p>
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

<?php $previewScreen = (int) $screen['id']; require __DIR__ . '/_preview.php'; ?>

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
  <h2>Accensione programmata</h2>
  <p class="hint">Senza fasce lo schermo riproduce sempre. Con le fasce, la riproduzione
  è attiva solo negli orari indicati (una fascia che scavalca la mezzanotte, es.
  22:00 → 06:00, è valida); fuori fascia lo schermo mostra nero (spento apparente)
  o un'immagine di cortesia. La TV decide con il proprio orologio: funziona anche offline.</p>
  <form method="post" id="schedule-form">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="schedule">
    <input type="hidden" name="schedule_json" id="schedule-json">
    <div id="bands" data-bands='<?= e(json_encode($scheduleBands)) ?>'></div>
    <button type="button" class="btn btn-sm" id="band-add" style="margin-top:14px">+ Aggiungi fascia</button>
    <div class="form-row" style="margin-top:18px">
      <div class="field">
        <label for="idle_mode">Fuori fascia mostra</label>
        <select id="idle_mode" name="idle_mode">
          <option value="black"<?= $screen['idle_mode'] === 'black' ? ' selected' : '' ?>>Schermo nero (spento apparente)</option>
          <option value="image"<?= $screen['idle_mode'] === 'image' ? ' selected' : '' ?>>Immagine di cortesia</option>
        </select>
      </div>
      <div class="field" id="idle-media-field">
        <label for="idle_media_id">Immagine di cortesia</label>
        <select id="idle_media_id" name="idle_media_id">
          <option value="">Scegli…</option>
          <?php foreach ($imageMedia as $m): ?>
          <option value="<?= (int) $m['id'] ?>"<?= (int) $screen['idle_media_id'] === (int) $m['id'] ? ' selected' : '' ?>>
            <?= e($m['gallery_name'] . ' — ' . $m['original_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="idle-upload-row">
          <img id="idle-preview" alt="Anteprima cortesia"
               src="<?= $screen['idle_media_id'] !== null ? 'media_preview.php?id=' . (int) $screen['idle_media_id'] : '' ?>"
               <?= $screen['idle_media_id'] === null ? 'style="display:none"' : '' ?>>
          <button type="button" class="btn btn-sm" id="idle-upload-btn"
                  data-screen="<?= (int) $screen['id'] ?>">Carica nuova immagine</button>
          <input type="file" id="idle-upload-input"
                 accept="image/jpeg,image/png,image/webp,image/gif" hidden>
        </div>
        <p class="hint" style="margin:8px 0 0">Le immagini caricate qui finiscono nella
        galleria di servizio "Immagini di cortesia", gestibile come ogni altra galleria.</p>
      </div>
      <button class="btn btn-primary" type="submit">Salva programmazione</button>
    </div>
  </form>
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