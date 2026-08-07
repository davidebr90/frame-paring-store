<?php
declare(strict_types=1);

/*
 * Controlli del banner in sovraimpressione, condivisi fra le impostazioni
 * generali e quelle del singolo schermo: le due pagine devono offrire
 * esattamente le stesse possibilità, e una sola copia evita che divergano.
 *
 * Variabili attese:
 *   $o        array   valori correnti (forma di OVERLAY_DEFAULTS)
 *   $hasLogo  bool    esiste un logo caricato
 *   $logoSrc  string  URL dell'anteprima del logo ('' se assente)
 */
?>
<div class="card">
  <h2><?= e(t('ov.activation')) ?></h2>
  <p class="hint"><?= e(t('ov.activation_hint')) ?></p>
  <div class="field">
    <label for="overlay_enabled"><?= e(t('usr.status')) ?></label>
    <label class="switch" for="overlay_enabled">
      <input type="checkbox" id="overlay_enabled" name="overlay_enabled" value="1"
             <?= $o['overlay_enabled'] === '1' ? ' checked' : '' ?> data-switch data-preview-toggle>
      <span class="switch-track"></span>
      <span class="switch-label">
        <span><?= e(t('ov.show')) ?></span>
        <span class="switch-state" data-switch-state
              data-on="<?= e(t('ov.on')) ?>" data-off="<?= e(t('ov.off')) ?>"><?=
          e($o['overlay_enabled'] === '1' ? t('ov.on') : t('ov.off')) ?></span>
      </span>
    </label>
  </div>
</div>

<div class="card">
  <h2><?= e(t('ov.content')) ?></h2>
  <div class="field">
    <label><?= e(t('ov.what')) ?></label>
    <div class="switch-group switch-group-inline">
      <label class="switch" for="overlay_show_logo">
        <input type="checkbox" id="overlay_show_logo" name="overlay_show_logo" value="1"
               <?= $o['overlay_show_logo'] === '1' ? ' checked' : '' ?>
               <?= $hasLogo ? '' : ' disabled' ?> data-switch data-preview-logo>
        <span class="switch-track"></span>
        <span class="switch-label">
          <span><?= e(t('ov.logo')) ?></span>
          <span class="switch-state" data-switch-state
                data-on="<?= e(t('ov.visible')) ?>" data-off="<?= e(t('ov.hidden_m')) ?>"><?=
            e($hasLogo
              ? ($o['overlay_show_logo'] === '1' ? t('ov.visible') : t('ov.hidden_m'))
              : t('ov.upload_first')) ?></span>
        </span>
      </label>
      <label class="switch" for="overlay_show_date">
        <input type="checkbox" id="overlay_show_date" name="overlay_show_date" value="1"
               <?= $o['overlay_show_date'] === '1' ? ' checked' : '' ?> data-switch data-preview-date>
        <span class="switch-track"></span>
        <span class="switch-label">
          <span><?= e(t('ov.date')) ?></span>
          <span class="switch-state" data-switch-state
                data-on="<?= e(t('ov.visible')) ?>" data-off="<?= e(t('ov.hidden_f')) ?>"><?=
            e($o['overlay_show_date'] === '1' ? t('ov.visible') : t('ov.hidden_f')) ?></span>
        </span>
      </label>
      <label class="switch" for="overlay_show_time">
        <input type="checkbox" id="overlay_show_time" name="overlay_show_time" value="1"
               <?= $o['overlay_show_time'] === '1' ? ' checked' : '' ?> data-switch data-preview-time>
        <span class="switch-track"></span>
        <span class="switch-label">
          <span><?= e(t('ov.time')) ?></span>
          <span class="switch-state" data-switch-state
                data-on="<?= e(t('ov.visible')) ?>" data-off="<?= e(t('ov.hidden_f')) ?>"><?=
            e($o['overlay_show_time'] === '1' ? t('ov.visible') : t('ov.hidden_f')) ?></span>
        </span>
      </label>
    </div>
  </div>

  <div class="field-grid">
    <div class="field">
      <label for="overlay_date_format"><?= e(t('ov.date_format')) ?></label>
      <select id="overlay_date_format" name="overlay_date_format" data-preview-format>
        <?php foreach (overlay_format_labels() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $o['overlay_date_format'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="overlay_size"><?= e(t('ov.size')) ?></label>
      <select id="overlay_size" name="overlay_size" data-preview-size>
        <?php foreach (overlay_size_labels() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $o['overlay_size'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<div class="card">
  <h2><?= e(t('ov.position')) ?></h2>
  <p class="hint"><?= e(t('ov.position_hint')) ?></p>
  <div class="pos-layout">
    <div class="pos-grid" role="radiogroup" aria-label="<?= e(t('ov.position_aria')) ?>">
      <?php foreach (overlay_position_labels() as $key => $label): ?>
      <label class="pos-cell<?= $o['overlay_position'] === $key ? ' selected' : '' ?>" title="<?= e($label) ?>">
        <input type="radio" name="overlay_position" value="<?= e($key) ?>"
               <?= $o['overlay_position'] === $key ? ' checked' : '' ?> data-preview-pos>
        <span class="pos-mark"></span>
        <span class="visually-hidden"><?= e($label) ?></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="pos-preview">
      <div class="tv-frame pos-<?= e($o['overlay_position']) ?>" data-preview-frame>
        <div class="tv-banner size-<?= e($o['overlay_size']) ?>" data-preview-banner>
          <?php if ($hasLogo): ?>
          <img src="<?= e($logoSrc) ?>" alt="" data-preview-logo-img<?= $o['overlay_show_logo'] === '1' ? '' : ' hidden' ?>>
          <?php endif; ?>
          <span data-preview-text><?= e(overlay_sample_text($o)) ?></span>
        </div>
      </div>
      <div class="field">
        <label for="overlay_opacity"><?= e(t('ov.opacity')) ?> — <span data-opacity-value><?= (int) $o['overlay_opacity'] ?></span>%</label>
        <input type="range" id="overlay_opacity" name="overlay_opacity" min="0" max="100" step="5"
               value="<?= (int) $o['overlay_opacity'] ?>" data-preview-opacity>
      </div>
    </div>
  </div>
</div>

<script>
// Anteprima dal vivo: riflette le scelte senza salvare
(function () {
  var frame   = document.querySelector('[data-preview-frame]');
  var banner  = document.querySelector('[data-preview-banner]');
  var text    = document.querySelector('[data-preview-text]');
  var logoImg = document.querySelector('[data-preview-logo-img]');
  if (!frame || !banner) { return; }

  // I nomi dei mesi arrivano dal catalogo della lingua in uso
  var MESI = <?= json_encode(array_map(fn (int $m): string => overlay_month($m), range(1, 12)), JSON_UNESCAPED_UNICODE) ?>;

  function due(n) { return n < 10 ? '0' + n : '' + n; }

  function render() {
    var on      = document.getElementById('overlay_enabled').checked;
    var showDay = document.getElementById('overlay_show_date').checked;
    var showOra = document.getElementById('overlay_show_time').checked;
    var logoBox = document.getElementById('overlay_show_logo');
    var formato = document.querySelector('[data-preview-format]').value;
    var size    = document.querySelector('[data-preview-size]').value;
    var opacity = document.querySelector('[data-preview-opacity]').value;
    var pos     = document.querySelector('[data-preview-pos]:checked');

    var d = new Date();
    var parti = [];
    if (showDay) {
      parti.push(formato === 'long'
        ? d.getDate() + ' ' + MESI[d.getMonth()] + ' ' + d.getFullYear()
        : due(d.getDate()) + '/' + due(d.getMonth() + 1) + '/' + d.getFullYear());
    }
    if (showOra) { parti.push(due(d.getHours()) + ':' + due(d.getMinutes())); }
    text.textContent = parti.join(' · ');
    text.hidden = parti.length === 0;

    var conLogo = !!(logoBox && logoBox.checked);
    if (logoImg) { logoImg.hidden = !conLogo; }

    frame.className = 'tv-frame pos-' + (pos ? pos.value : 'bottom_right');
    banner.className = 'tv-banner size-' + size;
    banner.style.setProperty('--banner-bg-alpha', (opacity / 100).toFixed(2));
    banner.hidden = !on || (parti.length === 0 && !conLogo);

    document.querySelector('[data-opacity-value]').textContent = opacity;
    document.querySelectorAll('.pos-cell').forEach(function (cell) {
      cell.classList.toggle('selected', cell.querySelector('input').checked);
    });
  }

  document.querySelectorAll(
    '[data-preview-toggle],[data-preview-logo],[data-preview-date],[data-preview-time],' +
    '[data-preview-format],[data-preview-size],[data-preview-opacity],[data-preview-pos]'
  ).forEach(function (el) {
    el.addEventListener('change', render);
    el.addEventListener('input', render);
  });

  render();
  setInterval(render, 30000); // l'orologio dell'anteprima resta veritiero
})();
</script>
