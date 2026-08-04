<?php
declare(strict_types=1);

// Blocco anteprima riusabile. Si include passando $previewScreen (id schermo)
// oppure $previewGallery (id galleria); il resto lo fa assets/js/preview.js.
$previewScreen = $previewScreen ?? 0;
$previewGallery = $previewGallery ?? 0;
?>
<div class="card">
  <h2>Anteprima</h2>
  <p class="hint">Riproduce i media reali con le transizioni, le rotazioni e gli
  adattamenti impostati, esattamente come li vedrà la TV. Usa le frecce per
  scorrere a mano e ispezionare ogni passaggio.</p>

  <div id="preview" class="pv"
       data-screen="<?= (int) $previewScreen ?>"
       data-gallery="<?= (int) $previewGallery ?>">
    <div class="pv-frame">
      <div class="pv-stage"></div>
    </div>
    <div class="pv-bar">
      <div class="pv-controls">
        <button type="button" class="btn btn-sm" data-pv="prev" title="Media precedente">‹ Indietro</button>
        <button type="button" class="btn btn-sm" data-pv="play">❚❚ Pausa</button>
        <button type="button" class="btn btn-sm" data-pv="next" title="Media successivo">Avanti ›</button>
        <button type="button" class="btn btn-sm" data-pv="reload" title="Ricarica dalla configurazione attuale">↻</button>
      </div>
      <span class="pv-counter mono">0 / 0</span>
    </div>
    <div class="pv-caption mono">Caricamento…</div>
    <div class="pv-idle-note hint" hidden></div>
  </div>
</div>
