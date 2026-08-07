<?php
declare(strict_types=1);

// Conferma del pairing: l'admin digita il PIN mostrato dalla TV,
// oppure arriva qui dal QR con ?code= precompilato (metodo 'qr').
require_once __DIR__ . '/_cms.php';
require_cap('screen.pair');

$prefill = preg_match('/^\d{6}$/', (string) ($_GET['code'] ?? '')) === 1
    ? (string) $_GET['code']
    : '';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $code = (string) ($_POST['code'] ?? '');
    $method = ($_POST['origin'] ?? '') === 'qr' ? 'qr' : 'pin';

    if (preg_match('/^\d{6}$/', $code) !== 1) {
        $error = t('pair.err_format');
    } else {
        $screenId = confirm_pairing($code, $method);
        if ($screenId === null) {
            $error = t('pair.err_invalid');
        } else {
            flash_set('ok', t('pair.done'));
            redirect('screen.php?id=' . $screenId);
        }
    }
}

cms_header(t('pair.title'), 'pair');
?>
<!-- Pagina a compito singolo: titolo e riquadro al centro dello spazio
     disponibile, orizzontalmente e verticalmente -->
<div class="stage-center">
  <div class="main-head">
    <div>
      <h1><?= e(t('pair.title')) ?></h1>
      <div class="sub"><?= e(t('pair.subtitle')) ?></div>
    </div>
  </div>

  <div class="card">
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="origin" value="<?= $prefill !== '' ? 'qr' : 'pin' ?>">
    <div class="field">
      <label for="code"><?= e(t('pair.code')) ?></label>
      <input type="text" id="code" name="code" class="pin-input" inputmode="numeric"
             autocomplete="one-time-code" placeholder="000000" maxlength="6" required
             value="<?= e($prefill) ?>">
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">
      <?= e(t('pair.submit')) ?>
    </button>
  </form>
    <p class="hint" style="margin:16px 0 0"><?= t('pair.hint', [
      'minutes' => (int) ((int) config('pairing_ttl') / 60),
      'link'    => '<a href="screens.php">' . e(t('nav.screens')) . '</a>',
    ]) ?></p>
  </div>
</div>
<?php cms_footer();