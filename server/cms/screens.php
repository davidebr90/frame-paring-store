<?php
declare(strict_types=1);

// Elenco schermi + creazione "manuale": lo schermo nasce qui nel CMS
// con una URL di configurazione (claim_token) da inserire nell'app.
require __DIR__ . '/_cms.php';
require_cap('screen.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        flash_set('err', 'Nome schermo non valido (1-100 caratteri)');
    } else {
        db()->prepare("INSERT INTO screens (name, claim_token, status) VALUES (?, ?, 'pending')")
            ->execute([$name, random_hex()]);
        flash_set('ok', 'Schermo creato: copia la URL di configurazione qui sotto');
    }
    redirect('screens.php');
}

$pdo = db();
$onlineWindow = (int) config('poll_interval') * 2 + 30;
$screens = $pdo->query('SELECT s.*, COUNT(sg.gallery_id) AS n_galleries
                        FROM screens s
                        LEFT JOIN screen_galleries sg ON sg.screen_id = s.id
                        GROUP BY s.id
                        ORDER BY s.name, s.id')->fetchAll();

$methodLabels = ['pin' => 'PIN', 'qr' => 'QR', 'manual' => 'Manuale'];

cms_header('Schermi', 'screens');
?>
<div class="main-head">
  <div>
    <h1>Schermi</h1>
    <div class="sub"><?= count($screens) ?> registrati</div>
  </div>
</div>

<div class="card">
  <h2>Nuovo schermo (configurazione manuale)</h2>
  <p class="hint">Crea lo schermo prima di accendere la TV: otterrai una URL da
  incollare nelle impostazioni dell'app. Per il pairing con PIN o QR non serve
  creare nulla qui, vai in <a href="pair.php">Pairing</a>.</p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <div class="field">
      <label for="name">Nome schermo</label>
      <input type="text" id="name" name="name" maxlength="100" required placeholder="Es. Vetrina ingresso">
    </div>
    <button class="btn btn-primary" type="submit">Crea</button>
  </form>
</div>

<div class="card">
  <h2>Tutti gli schermi</h2>
  <?php if (!$screens): ?>
    <p class="hint" style="margin:0">Ancora nessuno schermo.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Nome</th><th>Stato</th><th>Pannello</th><th>Metodo</th><th>Gallerie</th><th>Ultimo contatto</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($screens as $s): ?>
      <tr>
        <td><strong><?= e($s['name']) ?></strong>
          <?php if ($s['device_info']): ?><div class="hint mono"><?= e($s['device_info']) ?></div><?php endif; ?>
        </td>
        <td><?= screen_badge($s, $onlineWindow) ?></td>
        <td><?= $s['diagonal_inches'] !== null ? (int) $s['diagonal_inches'] . '&Prime; ' : '' ?><?= $s['orientation'] === 'portrait' ? 'verticale' : 'orizzontale' ?></td>
        <td><?= e($methodLabels[$s['pair_method']] ?? '—') ?></td>
        <td><?= (int) $s['n_galleries'] ?></td>
        <td class="hint"><?= $s['last_seen_at'] ? date('d/m/Y H:i', strtotime($s['last_seen_at'])) : '—' ?></td>
        <td style="text-align:right"><a class="btn btn-sm" href="screen.php?id=<?= (int) $s['id'] ?>">Gestisci</a></td>
      </tr>
      <?php if ($s['status'] === 'pending' && $s['claim_token'] !== null): ?>
      <tr>
        <td colspan="6">
          <label>URL di configurazione (impostazioni app → Configurazione manuale)</label>
          <div class="copy-row">
            <input type="text" readonly id="claim-<?= (int) $s['id'] ?>"
                   value="<?= e(config('base_url') . '/api/v1/claim.php?token=' . $s['claim_token']) ?>">
            <button type="button" class="btn btn-sm" data-copy="#claim-<?= (int) $s['id'] ?>">Copia</button>
          </div>
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php cms_footer();