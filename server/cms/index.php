<?php
declare(strict_types=1);

require_once __DIR__ . '/_cms.php';

$pdo = db();
$screens = $pdo->query('SELECT s.*, COUNT(sg.gallery_id) AS n_galleries
                        FROM screens s
                        LEFT JOIN screen_galleries sg ON sg.screen_id = s.id
                        GROUP BY s.id
                        ORDER BY s.name, s.id')->fetchAll();
$stats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM galleries WHERE deleted_at IS NULL) AS galleries,
    (SELECT COUNT(*) FROM media WHERE deleted_at IS NULL)      AS media,
    (SELECT COALESCE(SUM(filesize), 0) FROM media WHERE deleted_at IS NULL) AS bytes,
    (SELECT COUNT(*) FROM media WHERE deleted_at IS NOT NULL)  AS trashed')->fetch();

$onlineWindow = (int) config('poll_interval') * 2 + 30;
$online = 0;
foreach ($screens as $s) {
    if ($s['status'] === 'paired' && $s['last_seen_at'] !== null
        && time() - strtotime($s['last_seen_at']) <= $onlineWindow) {
        $online++;
    }
}

cms_header(t('dash.title'), 'index');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('dash.title')) ?></h1>
    <div class="sub"><?= e(t('dash.subtitle')) ?></div>
  </div>
  <?php if (current_can('screen.pair')): ?>
  <a class="btn btn-primary" href="pair.php"><?= e(t('dash.pair_cta')) ?></a>
  <?php endif; ?>
</div>

<div class="grid grid-tiles" style="margin-bottom:26px">
  <div class="tile"><div class="tile-label"><?= e(t('dash.tile_online')) ?></div>
    <div class="tile-value"><?= $online ?> <small>/ <?= count($screens) ?></small></div></div>
  <div class="tile"><div class="tile-label"><?= e(t('dash.tile_galleries')) ?></div>
    <div class="tile-value"><?= (int) $stats['galleries'] ?></div></div>
  <div class="tile"><div class="tile-label"><?= e(t('dash.tile_media')) ?></div>
    <div class="tile-value"><?= (int) $stats['media'] ?></div></div>
  <div class="tile"><div class="tile-label"><?= e(t('dash.tile_space')) ?></div>
    <div class="tile-value"><?= human_bytes((int) $stats['bytes']) ?></div></div>
</div>

<h2><?= e(t('dash.screens')) ?></h2>
<?php if (!$screens): ?>
  <div class="card">
    <p class="hint" style="margin:0"><?= e(t('dash.empty')) ?>
    <?php if (current_can('screen.pair')): ?>
      <?= t('dash.empty_pair', ['link' => '<a href="pair.php">' . e(t('nav.screens.pair')) . '</a>']) ?><?php
      if (current_can('screen.manage')): ?><?= t('dash.empty_manual', ['link' => '<a href="screens.php">' . e(t('nav.screens')) . '</a>']) ?><?php endif; ?>.
    <?php endif; ?></p>
  </div>
<?php else: ?>
  <div class="grid grid-screens">
    <?php foreach ($screens as $s):
        $methodLabels = ['pin' => 'PIN', 'qr' => 'QR', 'manual' => t('screen.method.manual')];
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
        <dt><?= e(t('screen.panel')) ?></dt>
        <dd><?= $s['diagonal_inches'] !== null ? (int) $s['diagonal_inches'] . '&Prime; ' : '' ?><?= e($isPortrait ? t('screen.portrait') : t('screen.landscape')) ?></dd>
        <dt><?= e(t('screen.pairing')) ?></dt>
        <dd><?= e($methodLabels[$s['pair_method']] ?? '—') ?></dd>
        <dt><?= e(t('screen.galleries')) ?></dt>
        <dd><?= (int) $s['n_galleries'] ?></dd>
      </dl>
      <div class="screen-foot">
        <?= screen_badge($s, $onlineWindow) ?>
        <span class="hint"><?= e($s['last_seen_at'] ? t('screen.seen_at', ['when' => date('d/m H:i', strtotime($s['last_seen_at']))]) : t('screen.never_seen')) ?></span>
      </div>
      <?php // il footer resta ancorato in basso anche con card di altezza diversa ?>
      <?php if (current_can('screen.manage')): ?>
      <a class="cover" href="screen.php?id=<?= (int) $s['id'] ?>" aria-label="<?= e(t('screen.manage_aria', ['name' => $s['name']])) ?>"></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php cms_footer();