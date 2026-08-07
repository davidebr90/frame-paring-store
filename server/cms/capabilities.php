<?php
declare(strict_types=1);

// Capacità dell'installazione: cosa il sistema può fare e cosa manca.
// Utile anche come diagnostica generale, non solo per le funzioni avanzate.
require_once __DIR__ . '/_cms.php';
require_cap('settings.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    capabilities(refresh: true);
    flash_set('ok', t('caps.rechecked'));
    redirect('capabilities.php');
}

$checks = capabilities();
$updated = json_decode((string) setting_get('capabilities_json'), true)['at'] ?? null;

cms_header(t('nav.capabilities'), 'capabilities');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('caps.title')) ?></h1>
    <div class="sub">
      <?= e(t('caps.subtitle')) ?><?= $updated ? e(t('caps.checked_at', ['when' => date('d/m/Y H:i', (int) $updated)])) : '' ?>
    </div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <button class="btn" type="submit"><?= e(t('caps.recheck')) ?></button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('caps.features')) ?></h2>
  <p class="hint"><?= e(t('caps.features_hint')) ?></p>

  <div class="grid feature-grid">
    <?php foreach (FEATURES as $key => $feature):
        $available = feature_available($key);
        $missing = $available ? [] : feature_missing($key);
    ?>
    <article class="feature-card<?= $available ? ' is-available' : ' is-locked' ?>">
      <header class="feature-head">
        <span class="feature-icon">
          <?php if ($available): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
          <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          <?php endif; ?>
        </span>
        <div>
          <h3><?= e(t($feature['label'])) ?></h3>
          <p class="hint"><?= e(t($feature['summary'])) ?></p>
        </div>
        <span class="badge <?= $available ? 'badge-online' : '' ?>">
          <?php if ($available): ?><span class="led"></span><?= e(t('caps.available')) ?><?php else: ?><?= e(t('caps.locked')) ?><?php endif; ?>
        </span>
      </header>

      <?php if ($missing): ?>
      <div class="feature-missing">
        <strong><?= e(t('caps.missing')) ?></strong>
        <?php foreach ($missing as $check): ?>
        <div class="req-line">
          <span class="req-mark">✕</span>
          <span><?= e(t($check['label'])) ?>: <span class="hint"><?= e(capability_view($check)['detail']) ?></span></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2><?= e(t('caps.checks')) ?></h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= e(t('caps.th_check')) ?></th><th><?= e(t('caps.th_result')) ?></th><th><?= e(t('caps.th_howto')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($checks as $check): $check = capability_view($check); ?>
        <tr>
          <td><strong><?= e($check['label']) ?></strong></td>
          <td>
            <span class="badge <?= $check['ok'] ? 'badge-online' : 'badge-revoked' ?>">
              <span class="led"></span><?= e($check['detail']) ?>
            </span>
          </td>
          <td class="hint"><?= $check['howto'] !== '' ? e($check['howto']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2><?= e(t('caps.env')) ?></h2>
  <dl class="info-grid">
    <dt><?= e(t('caps.env_php')) ?></dt><dd class="mono"><?= e(PHP_VERSION) ?> · <?= e(php_sapi_name()) ?></dd>
    <dt><?= e(t('caps.env_db')) ?></dt><dd class="mono"><?= e(db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></dd>
    <dt><?= e(t('caps.env_os')) ?></dt><dd class="mono"><?= e(PHP_OS_FAMILY) ?></dd>
    <dt><?= e(t('caps.env_upload')) ?></dt>
    <dd><?= human_bytes((int) config('max_upload_bytes')) ?>
      <span class="hint">(PHP: <?= e(ini_get('upload_max_filesize')) ?> / <?= e(ini_get('post_max_size')) ?>)</span></dd>
  </dl>
  <p class="hint" style="margin-top:16px"><?= t('caps.env_note', [
    'link' => '<a href="../../docs/installazione.md">' . e(t('caps.env_note_link')) . '</a>',
  ]) ?></p>
</div>
<?php cms_footer();