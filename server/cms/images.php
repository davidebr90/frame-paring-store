<?php
declare(strict_types=1);

/*
 * Frame Paring Store — sistema di digital signage self-hosted
 * Copyright (C) 2026 Davide Pica
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA. Vedi la GNU Affero General Public License per i dettagli.
 * Dovresti aver ricevuto una copia della licenza insieme a questo programma;
 * in caso contrario: https://www.gnu.org/licenses/
 */

// Qualità delle copie inviate ai televisori (vedi core/renditions.php).
// Gli originali non vengono mai toccati: qui si decide solo come viene
// preparata la copia che i dispositivi scaricano e mostrano.
require_once __DIR__ . '/_cms.php';
require_cap('settings.manage');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'save') {
        $quality = (int) ($_POST['rendition_quality'] ?? RENDITION_QUALITY_DEFAULT);
        $maxSide = (int) ($_POST['rendition_max_side'] ?? RENDITION_MAX_SIDE_DEFAULT);

        if ($quality < RENDITION_QUALITY_MIN || $quality > RENDITION_QUALITY_MAX) {
            $errors[] = t('img.err_quality', [
                'min' => RENDITION_QUALITY_MIN, 'max' => RENDITION_QUALITY_MAX,
            ]);
        }
        if ($maxSide < RENDITION_MAX_SIDE_MIN || $maxSide > RENDITION_MAX_SIDE_MAX) {
            $errors[] = t('img.err_side', [
                'min' => RENDITION_MAX_SIDE_MIN, 'max' => RENDITION_MAX_SIDE_MAX,
            ]);
        }

        if (!$errors) {
            $cambiato = $quality !== rendition_quality() || $maxSide !== rendition_max_side();
            setting_set('rendition_quality', (string) $quality);
            setting_set('rendition_max_side', (string) $maxSide);

            if ($cambiato) {
                // Le copie esistenti sono state fatte con un'altra regola:
                // si buttano e si rifanno. Gli originali restano intatti,
                // quindi l'operazione è sempre reversibile tornando
                // indietro con i cursori.
                rendition_invalidate_all();
                $esito = rendition_regenerate_batch(20);
                flash_set('ok', $esito['restanti'] > 0
                    ? t('img.saved_partial', ['done' => $esito['fatte'], 'left' => $esito['restanti']])
                    : t('img.saved_all', ['done' => $esito['fatte']]));
            } else {
                flash_set('ok', t('img.saved_nochange'));
            }
            redirect('images.php');
        }
    } elseif ($do === 'regenerate') {
        $esito = rendition_regenerate_batch(20);
        flash_set('ok', $esito['restanti'] > 0
            ? t('img.saved_partial', ['done' => $esito['fatte'], 'left' => $esito['restanti']])
            : t('img.saved_all', ['done' => $esito['fatte']]));
        redirect('images.php');
    }
}

$quality   = rendition_quality();
$maxSide   = rendition_max_side();
$pendenti  = rendition_pending_count();
$spazio    = rendition_storage_summary();
$gdOk      = rendition_available();

// Peso medio reale di una copia: è il numero concreto da mettere accanto
// al cursore, molto più utile di una stima teorica
$mediaKb = $spazio['con_copia'] > 0 ? $spazio['copie'] / $spazio['con_copia'] / 1024 : 0;
$risparmio = $spazio['originali'] > 0
    ? (1 - ($spazio['copie'] / max(1, $spazio['originali']))) * 100
    : 0;

cms_header(t('img.title'), 'images');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('img.title')) ?></h1>
    <div class="sub"><?= e(t('img.subtitle')) ?></div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$gdOk): ?>
<div class="flash flash-err"><?= e(t('img.no_gd')) ?></div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">

  <div class="card">
    <h2><?= e(t('img.card_quality')) ?></h2>
    <p class="hint"><?= e(t('img.hint_quality')) ?></p>

    <div class="field">
      <label for="rendition_quality">
        <?= e(t('img.quality')) ?> — <span data-quality-value><?= $quality ?></span>
        <span class="hint" data-quality-note></span>
      </label>
      <input type="range" id="rendition_quality" name="rendition_quality"
             min="<?= RENDITION_QUALITY_MIN ?>" max="<?= RENDITION_QUALITY_MAX ?>" step="1"
             value="<?= $quality ?>" data-quality>
      <p class="hint"><?= e(t('img.quality_scale')) ?></p>
    </div>

    <div class="field">
      <label for="rendition_max_side">
        <?= e(t('img.max_side')) ?> — <span data-side-value><?= $maxSide ?></span> px
        <span class="hint" data-side-note></span>
      </label>
      <input type="range" id="rendition_max_side" name="rendition_max_side"
             min="<?= RENDITION_MAX_SIDE_MIN ?>" max="<?= RENDITION_MAX_SIDE_MAX ?>" step="320"
             value="<?= $maxSide ?>" data-side>
      <p class="hint"><?= e(t('img.max_side_hint')) ?></p>
    </div>

    <p class="hint"><strong><?= e(t('img.effect_title')) ?></strong> <span data-effect></span></p>

    <button class="btn btn-primary" type="submit"><?= e(t('img.save')) ?></button>
  </div>
</form>

<div class="card">
  <h2><?= e(t('img.card_state')) ?></h2>
  <dl class="kv">
    <dt><?= e(t('img.st_images')) ?></dt>
    <dd><?= (int) $spazio['immagini'] ?></dd>
    <dt><?= e(t('img.st_with_copy')) ?></dt>
    <dd><?= (int) $spazio['con_copia'] ?></dd>
    <dt><?= e(t('img.st_originals')) ?></dt>
    <dd class="mono"><?= number_format($spazio['originali'] / 1048576, 1, ',', '.') ?> MB</dd>
    <dt><?= e(t('img.st_copies')) ?></dt>
    <dd class="mono"><?= number_format($spazio['copie'] / 1048576, 1, ',', '.') ?> MB
      <?php if ($risparmio > 0): ?>
        (<?= number_format($risparmio, 0, ',', '.') ?>% <?= e(t('img.st_less')) ?>)
      <?php endif; ?>
    </dd>
    <dt><?= e(t('img.st_avg')) ?></dt>
    <dd class="mono"><?= $mediaKb > 0 ? number_format($mediaKb, 0, ',', '.') . ' KB' : '—' ?></dd>
  </dl>

  <?php if ($pendenti > 0): ?>
    <p class="hint"><?= e(t('img.pending', ['n' => $pendenti])) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="regenerate">
      <button class="btn" type="submit"><?= e(t('img.regenerate')) ?></button>
    </form>
  <?php else: ?>
    <p class="hint"><?= e(t('img.all_done')) ?></p>
  <?php endif; ?>
</div>

<script>
// Le etichette seguono i cursori senza salvare: chi muove il cursore deve
// capire COSA sta scegliendo, non leggere un numero senza significato.
(function () {
  var q = document.querySelector('[data-quality]');
  var s = document.querySelector('[data-side]');
  if (!q || !s) { return; }
  var qVal = document.querySelector('[data-quality-value]');
  var qNote = document.querySelector('[data-quality-note]');
  var sVal = document.querySelector('[data-side-value]');
  var sNote = document.querySelector('[data-side-note]');
  var eff = document.querySelector('[data-effect]');
  var T = <?= json_encode([
      'q_low'    => t('img.q_low'),
      'q_mid'    => t('img.q_mid'),
      'q_high'   => t('img.q_high'),
      'q_max'    => t('img.q_max'),
      's_hd'     => t('img.s_hd'),
      's_fhd'    => t('img.s_fhd'),
      's_over'   => t('img.s_over'),
      's_4k'     => t('img.s_4k'),
      'effect'   => t('img.effect'),
  ], JSON_UNESCAPED_UNICODE) ?>;

  function nota(v) {
    if (v < 80) { return T.q_low; }
    if (v < 88) { return T.q_mid; }
    if (v < 94) { return T.q_high; }
    return T.q_max;
  }
  function notaLato(v) {
    if (v <= 1280) { return T.s_hd; }
    if (v <= 1920) { return T.s_fhd; }
    if (v <= 2560) { return T.s_over; }
    return T.s_4k;
  }
  // Peso e memoria stimati: i bit per pixel crescono in modo molto simile
  // a questa curva fra qualita' 70 e 97, misurati sulle foto reali di
  // questo impianto. Serve a dare un ordine di grandezza mentre si sceglie,
  // non a predire il byte esatto.
  function stima() {
    var qv = parseInt(q.value, 10), sv = parseInt(s.value, 10);
    var bpp = Math.pow(2, (qv - 70) / 9.5) * 0.55;
    var px = sv * (sv / 1.5);              // foto tipica 2:3
    var kb = Math.round(px * bpp / 8 / 1024);
    var mem = Math.round(px * 4 / 1048576); // bitmap decompressa sul dispositivo
    eff.textContent = T.effect
      .replace(':kb', kb)
      .replace(':mem', mem);
  }
  function aggiorna() {
    qVal.textContent = q.value;
    qNote.textContent = '· ' + nota(parseInt(q.value, 10));
    sVal.textContent = s.value;
    sNote.textContent = '· ' + notaLato(parseInt(s.value, 10));
    stima();
  }
  q.addEventListener('input', aggiorna);
  s.addEventListener('input', aggiorna);
  aggiorna();
})();
</script>
<?php cms_footer();
