<?php
declare(strict_types=1);

// Capacità dell'installazione: cosa il sistema può fare e cosa manca.
// Utile anche come diagnostica generale, non solo per le funzioni avanzate.
require __DIR__ . '/_cms.php';
require_cap('settings.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    capabilities(refresh: true);
    flash_set('ok', 'Controlli eseguiti di nuovo');
    redirect('capabilities.php');
}

$checks = capabilities();
$updated = json_decode((string) setting_get('capabilities_json'), true)['at'] ?? null;

cms_header('Capacità', 'capabilities');
?>
<div class="main-head">
  <div>
    <h1>Capacità dell'installazione</h1>
    <div class="sub">
      Cosa può fare questo server<?= $updated ? ' · verificato il ' . date('d/m/Y H:i', (int) $updated) : '' ?>
    </div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <button class="btn" type="submit">Verifica di nuovo</button>
  </form>
</div>

<div class="card">
  <h2>Funzionalità</h2>
  <p class="hint">Le funzioni non disponibili restano visibili nel menu ma bloccate:
  puoi vedere cosa offrirebbero e quali requisiti servono per attivarle.</p>

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
          <h3><?= e($feature['label']) ?></h3>
          <p class="hint"><?= e($feature['summary']) ?></p>
        </div>
        <span class="badge <?= $available ? 'badge-online' : '' ?>">
          <?php if ($available): ?><span class="led"></span>Disponibile<?php else: ?>Bloccata<?php endif; ?>
        </span>
      </header>

      <?php if ($missing): ?>
      <div class="feature-missing">
        <strong>Requisiti mancanti</strong>
        <?php foreach ($missing as $check): ?>
        <div class="req-line">
          <span class="req-mark">✕</span>
          <span><?= e($check['label']) ?>: <span class="hint"><?= e($check['detail']) ?></span></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Controlli di sistema</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Controllo</th><th>Esito</th><th>Come abilitarlo</th></tr></thead>
      <tbody>
      <?php foreach ($checks as $check): ?>
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
  <h2>Ambiente</h2>
  <dl class="info-grid">
    <dt>PHP</dt><dd class="mono"><?= e(PHP_VERSION) ?> · <?= e(php_sapi_name()) ?></dd>
    <dt>Database</dt><dd class="mono"><?= e(db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></dd>
    <dt>Sistema</dt><dd class="mono"><?= e(PHP_OS_FAMILY) ?></dd>
    <dt>Limite upload</dt>
    <dd><?= human_bytes((int) config('max_upload_bytes')) ?>
      <span class="hint">(PHP: <?= e(ini_get('upload_max_filesize')) ?> / <?= e(ini_get('post_max_size')) ?>)</span></dd>
  </dl>
  <p class="hint" style="margin-top:16px">
    Le funzioni bloccate richiedono un server con accesso completo (VPS o dedicato).
    Tutto il resto del sistema continua a funzionare normalmente su hosting condiviso:
    vedi <a href="../../docs/installazione.md">la guida all'installazione</a>.
  </p>
</div>
<?php cms_footer();