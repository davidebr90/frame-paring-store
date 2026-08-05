<?php
declare(strict_types=1);

// Diretta da webcam: richiede capacità che l'hosting condiviso non offre.
// La pagina resta raggiungibile per mostrare requisiti e istruzioni, ma
// ogni azione operativa è protetta da require_feature().
require __DIR__ . '/_cms.php';
require_cap('screen.manage');

$available = feature_available('live.webcam');
$missing = feature_missing('live.webcam');

cms_header('Diretta webcam', 'live.webcam');
?>
<div class="main-head">
  <div>
    <h1>Diretta da webcam e microfono</h1>
    <div class="sub">Trasmette agli schermi un flusso proveniente dal tuo computer</div>
  </div>
  <span class="badge <?= $available ? 'badge-online' : '' ?>">
    <?php if ($available): ?><span class="led"></span>Disponibile<?php else: ?>Bloccata<?php endif; ?>
  </span>
</div>

<?php if (!$available): ?>
<div class="card">
  <h2>Perché non è disponibile qui</h2>
  <p class="hint">Ricevere un flusso in diretta e ridistribuirlo richiede un
  programma sempre in esecuzione sul server. Gli hosting condivisi non lo
  permettono: eseguono PHP solo per il tempo di una richiesta e bloccano
  l'avvio di programmi esterni. Non è una limitazione del sistema, ma
  dell'ambiente in cui è installato.</p>

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
</div>

<div class="card">
  <h2>Come attivarla</h2>
  <p class="hint">Lo stesso pacchetto, installato su un server con accesso completo
  (VPS o dedicato), attiva questa funzione da solo: il sistema rileva le capacità
  disponibili a ogni verifica, senza configurazioni aggiuntive.</p>

  <dl class="info-grid">
    <dt>Server</dt>
    <dd>VPS con accesso amministrativo — indicativamente 5-10 € al mese</dd>
    <dt>Software</dt>
    <dd class="mono">ffmpeg</dd>
    <dt>Configurazione PHP</dt>
    <dd><span class="mono">exec</span> e <span class="mono">proc_open</span> non
      elencate in <span class="mono">disable_functions</span>,
      <span class="mono">max_execution_time</span> a 0</dd>
    <dt>Banda in uscita</dt>
    <dd>circa 180 GB al mese con 10 schermi a 5 Mbit/s per 8 ore al giorno</dd>
    <dt>Ritardo atteso</dt>
    <dd>3-8 secondi rispetto alla ripresa</dd>
  </dl>

  <p class="hint" style="margin-top:16px">Dopo l'installazione su un server idoneo,
  apri <a href="capabilities.php">Sistema → Capacità</a> e usa
  <em>Verifica di nuovo</em>: la funzione si sblocca automaticamente.</p>
</div>

<div class="card">
  <h2>Alternative senza cambiare server</h2>
  <p class="hint">Queste modalità funzionano già su questa installazione e coprono
  molti casi d'uso reali:</p>
  <ul class="hint" style="line-height:1.9;padding-left:20px;margin:10px 0 0">
    <li><strong>Diretta sincronizzata da file</strong> — lo stesso contenuto parte
      insieme su tutti gli schermi: qualità perfetta e nessun consumo di banda.</li>
    <li><strong>Sorgente esterna</strong> — una telecamera IP del punto vendita o una
      diretta ospitata altrove: gli schermi si collegano direttamente al flusso,
      il server fa solo da regia.</li>
  </ul>
</div>

<?php else: ?>
<div class="card">
  <h2>Pronta all'uso</h2>
  <p class="hint" style="margin:0">Questo server ha tutte le capacità necessarie.
  La configurazione delle sorgenti in diretta è la prossima funzionalità in
  lavorazione.</p>
</div>
<?php endif; ?>
<?php cms_footer();