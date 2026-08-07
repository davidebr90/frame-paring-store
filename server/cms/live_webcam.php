<?php
declare(strict_types=1);

// Diretta da webcam: richiede capacità che l'hosting condiviso non offre.
// La pagina resta raggiungibile per mostrare requisiti e istruzioni, ma
// ogni azione operativa è protetta da require_feature().
require_once __DIR__ . '/_cms.php';
require_cap('screen.manage');

$available = feature_available('live.webcam');
$missing = feature_missing('live.webcam');

cms_header(t('nav.live.webcam'), 'live.webcam');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('wc.title')) ?></h1>
    <div class="sub"><?= e(t('wc.subtitle')) ?></div>
  </div>
  <span class="badge <?= $available ? 'badge-online' : '' ?>">
    <?php if ($available): ?><span class="led"></span><?= e(t('caps.available')) ?><?php else: ?><?= e(t('caps.locked')) ?><?php endif; ?>
  </span>
</div>

<?php if (!$available): ?>
<div class="card">
  <h2><?= e(t('wc.why')) ?></h2>
  <p class="hint"><?= e(t('wc.why_text')) ?></p>

  <?php foreach ($missing as $check): $check = capability_view($check); ?>
  <div class="req-line">
    <span class="req-mark">✕</span>
    <span><strong><?= e($check['label']) ?>:</strong>
      <span class="hint"><?= e($check['detail']) ?></span>
      <?php if ($check['howto'] !== ''): ?>
      <div class="hint req-howto"><?= e($check['howto']) ?></div>
      <?php endif; ?>
    </span>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2><?= e(t('wc.howto')) ?></h2>
  <p class="hint"><?= e(t('wc.howto_text')) ?></p>

  <dl class="info-grid">
    <dt><?= e(t('wc.server')) ?></dt>
    <dd><?= e(t('wc.server_val')) ?></dd>
    <dt><?= e(t('wc.software')) ?></dt>
    <dd class="mono">ffmpeg</dd>
    <dt><?= e(t('wc.php_config')) ?></dt>
    <dd><?= t('wc.php_config_val', [
      'exec'    => '<span class="mono">exec</span>',
      'proc'    => '<span class="mono">proc_open</span>',
      'disable' => '<span class="mono">disable_functions</span>',
      'maxexec' => '<span class="mono">max_execution_time</span>',
    ]) ?></dd>
    <dt><?= e(t('wc.bandwidth')) ?></dt>
    <dd><?= e(t('wc.bandwidth_val')) ?></dd>
    <dt><?= e(t('wc.latency')) ?></dt>
    <dd><?= e(t('wc.latency_val')) ?></dd>
  </dl>

  <p class="hint" style="margin-top:16px"><?= t('wc.after_install', [
    'link'   => '<a href="capabilities.php">' . e(t('wc.after_link')) . '</a>',
    'action' => '<em>' . e(t('caps.recheck')) . '</em>',
  ]) ?></p>
</div>

<div class="card">
  <h2><?= e(t('wc.alternatives')) ?></h2>
  <p class="hint"><?= e(t('wc.alt_text')) ?></p>
  <ul class="hint" style="line-height:1.9;padding-left:20px;margin:10px 0 0">
    <li><strong><?= e(t('wc.alt_sync')) ?></strong> <?= e(t('wc.alt_sync_text')) ?></li>
    <li><strong><?= e(t('wc.alt_ext')) ?></strong> <?= e(t('wc.alt_ext_text')) ?></li>
  </ul>
</div>

<?php else: ?>
<div class="card">
  <h2><?= e(t('wc.ready')) ?></h2>
  <p class="hint" style="margin:0"><?= e(t('wc.ready_text')) ?></p>
</div>
<?php endif; ?>
<?php cms_footer();