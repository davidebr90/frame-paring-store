<?php
declare(strict_types=1);

require __DIR__ . '/_cms.php';

$pdo = db();
$screens = $pdo->query('SELECT s.*, COUNT(sg.gallery_id) AS n_galleries
                        FROM screens s
                        LEFT JOIN screen_galleries sg ON sg.screen_id = s.id
                        GROUP BY s.id
                        ORDER BY s.name, s.id')->fetchAll();
$stats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM galleries)          AS galleries,
    (SELECT COUNT(*) FROM media)              AS media,
    (SELECT COALESCE(SUM(filesize), 0) FROM media) AS bytes')->fetch();

$onlineWindow = (int) config('poll_interval') * 2 + 30;
$online = 0;
foreach ($screens as $s) {
    if ($s['status'] === 'paired' && $s['last_seen_at'] !== null
        && time() - strtotime($s['last_seen_at']) <= $onlineWindow) {
        $online++;
    }
}

cms_header('Dashboard', 'index');
?>
<div class="main-head">
  <div>
    <h1>Dashboard</h1>
    <div class="sub">Panoramica del sistema</div>
  </div>
  <a class="btn btn-primary" href="pair.php">+ Associa schermo</a>
</div>

<div class="grid grid-tiles" style="margin-bottom:26px">
  <div class="tile"><div class="tile-label">Schermi online</div>
    <div class="tile-value"><?= $online ?> <small>/ <?= count($screens) ?></small></div></div>
  <div class="tile"><div class="tile-label">Gallerie</div>
    <div class="tile-value"><?= (int) $stats['galleries'] ?></div></div>
  <div class="tile"><div class="tile-label">File media</div>
    <div class="tile-value"><?= (int) $stats['media'] ?></div></div>
  <div class="tile"><div class="tile-label">Spazio occupato</div>
    <div class="tile-value"><?= human_bytes((int) $stats['bytes']) ?></div></div>
</div>

<h2>Schermi</h2>
<?php if (!$screens): ?>
  <div class="card">
    <p class="hint" style="margin:0">Nessuno schermo registrato. Apri l'app sulla TV e vai su
    <a href="pair.php">Pairing</a> per associare il primo, oppure creane uno in
    <a href="screens.php">Schermi</a> con la configurazione manuale.</p>
  </div>
<?php else: ?>
  <div class="grid grid-screens">
    <?php foreach ($screens as $s):
        $methodLabels = ['pin' => 'PIN', 'qr' => 'QR', 'manual' => 'Manuale'];
        $isPortrait = $s['orientation'] === 'portrait';
    ?>
    <div class="screen-card">
      <div class="screen-stage">
        <div class="screen-panel<?= $isPortrait ? ' portrait' : '' ?>">
          <span>S<?= str_pad((string) $s['id'], 2, '0', STR_PAD_LEFT) ?></span>
        </div>
      </div>
      <div class="screen-head">
        <div class="screen-name"><?= e($s['name']) ?></div>
        <?php if ($s['device_info']): ?>
        <div class="screen-device"><?= e($s['device_info']) ?></div>
        <?php endif; ?>
      </div>
      <dl class="screen-specs">
        <dt>Pannello</dt>
        <dd><?= $s['diagonal_inches'] !== null ? (int) $s['diagonal_inches'] . '&Prime; ' : '' ?><?= $isPortrait ? 'verticale' : 'orizzontale' ?></dd>
        <dt>Pairing</dt>
        <dd><?= e($methodLabels[$s['pair_method']] ?? '—') ?></dd>
        <dt>Gallerie</dt>
        <dd><?= (int) $s['n_galleries'] ?></dd>
      </dl>
      <div class="screen-foot">
        <?= screen_badge($s, $onlineWindow) ?>
        <span class="hint"><?= $s['last_seen_at'] ? 'visto ' . date('d/m H:i', strtotime($s['last_seen_at'])) : 'mai visto' ?></span>
      </div>
      <a class="cover" href="screen.php?id=<?= (int) $s['id'] ?>" aria-label="Gestisci <?= e($s['name']) ?>"></a>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php cms_footer();