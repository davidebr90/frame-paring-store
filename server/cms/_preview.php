<?php
declare(strict_types=1);

// Blocco anteprima riusabile.
// - $previewScreen (id schermo): anteprima di quello schermo, con le sue
//   gallerie assegnate — usato da screen.php.
// - $previewGallery (id galleria) da solo: anteprima "generica", nessuno
//   schermo reale coinvolto — usato quando non ha senso sceglierne uno
//   (es. nessuno schermo esiste ancora).
// - $previewGallery insieme a $previewScreenOptions (elenco id => name di
//   TUTTI gli schermi esistenti): anteprima di questa galleria COME
//   APPARIREBBE su uno schermo reale a scelta — usato da gallery.php, per
//   vedere la rotazione automatica vera invece di un valore di comodo.
$previewScreen = $previewScreen ?? 0;
$previewGallery = $previewGallery ?? 0;
$previewScreenOptions = $previewScreenOptions ?? null;
$previewScreenPicker = $previewGallery > 0 && $previewScreenOptions !== null;
?>
<div class="card">
  <h2><?= e(t('pv.title')) ?></h2>
  <p class="hint">Riproduce i media reali con le transizioni, le rotazioni e gli
  adattamenti impostati, esattamente come li vedrà la TV. Usa le frecce per
  scorrere a mano e ispezionare ogni passaggio.</p>

  <?php if ($previewScreenPicker && !$previewScreenOptions): ?>
  <p class="hint"><?= e(t('pv.no_screens')) ?></p>
  <?php elseif ($previewScreenPicker): ?>
  <div class="field" style="max-width:320px;margin-bottom:14px">
    <label for="preview-screen-select"><?= e(t('pv.screen_picker')) ?></label>
    <select id="preview-screen-select">
      <?php foreach ($previewScreenOptions as $s): ?>
      <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <?php if (!$previewScreenPicker || $previewScreenOptions): ?>
  <div id="preview" class="pv"
       data-screen="<?= $previewScreenPicker ? (int) $previewScreenOptions[0]['id'] : (int) $previewScreen ?>"
       data-gallery="<?= (int) $previewGallery ?>">
    <div class="pv-frame">
      <div class="pv-stage"></div>
    </div>
    <div class="pv-bar">
      <div class="pv-controls">
        <button type="button" class="btn btn-sm" data-pv="prev" title="Media precedente">‹ Indietro</button>
        <button type="button" class="btn btn-sm" data-pv="play">❚❚ Pausa</button>
        <button type="button" class="btn btn-sm" data-pv="next" title="<?= e(t('pv.next_title')) ?>"><?= e(t('pv.next')) ?></button>
        <button type="button" class="btn btn-sm" data-pv="reload" title="Ricarica dalla configurazione attuale">↻</button>
      </div>
      <span class="pv-counter mono">0 / 0</span>
    </div>
    <div class="pv-caption mono"><?= e(t('pv.loading')) ?></div>
    <div class="pv-idle-note hint" hidden></div>
  </div>
  <?php endif; ?>
</div>
