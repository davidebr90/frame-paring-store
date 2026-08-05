<?php
declare(strict_types=1);

// Dirette: panoramica delle modalità e del loro stato su questa installazione.
// La configurazione delle sorgenti arriva con la fase successiva; qui si
// vede fin da ora cosa il server può fare e cosa richiede un'altra macchina.
require __DIR__ . '/_cms.php';
require_cap('screen.manage');

cms_header('Dirette', 'live');
?>
<div class="main-head">
  <div>
    <h1>Dirette</h1>
    <div class="sub">Contenuti trasmessi in tempo reale sugli schermi</div>
  </div>
</div>

<div class="card">
  <h2>Modalità</h2>
  <p class="hint">Ogni modalità ha requisiti diversi: le prime due funzionano
  ovunque, la terza richiede un server con accesso completo.</p>

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
          <span><strong><?= e($check['label']) ?>:</strong>
            <span class="hint"><?= e($check['detail']) ?></span>
            <?php if ($check['howto'] !== ''): ?>
            <div class="hint req-howto"><?= e($check['howto']) ?></div>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php if ($key === 'live.webcam'): ?>
        <div class="form-actions" style="margin-top:16px;padding-top:0;border:0">
          <a class="btn btn-sm" href="live_webcam.php">Requisiti e installazione</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Sorgenti configurate</h2>
  <p class="hint" style="margin:0">Nessuna sorgente configurata. La gestione delle
  sorgenti (avvio sincronizzato di un file su più schermi e ritrasmissione di un
  flusso esterno) è la prossima funzionalità in lavorazione: questa pagina mostra
  già quali modalità il server potrà usare.</p>
</div>
<?php cms_footer();