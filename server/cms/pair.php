<?php
declare(strict_types=1);

// Conferma del pairing: l'admin digita il PIN mostrato dalla TV,
// oppure arriva qui dal QR con ?code= precompilato (metodo 'qr').
require __DIR__ . '/_cms.php';

$prefill = preg_match('/^\d{6}$/', (string) ($_GET['code'] ?? '')) === 1
    ? (string) $_GET['code']
    : '';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $code = (string) ($_POST['code'] ?? '');
    $method = ($_POST['origin'] ?? '') === 'qr' ? 'qr' : 'pin';

    if (preg_match('/^\d{6}$/', $code) !== 1) {
        $error = 'Il codice è di 6 cifre';
    } else {
        $screenId = confirm_pairing($code, $method);
        if ($screenId === null) {
            $error = 'Codice non valido o scaduto: controlla la TV e riprova';
        } else {
            flash_set('ok', 'Schermo associato! Dagli un nome e assegna le gallerie.');
            redirect('screen.php?id=' . $screenId);
        }
    }
}

cms_header('Pairing', 'pair');
?>
<div class="main-head">
  <div>
    <h1>Associa uno schermo</h1>
    <div class="sub">Inserisci il codice mostrato sulla TV</div>
  </div>
</div>

<div class="card" style="max-width:460px">
  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="origin" value="<?= $prefill !== '' ? 'qr' : 'pin' ?>">
    <div class="field">
      <label for="code">Codice di pairing</label>
      <input type="text" id="code" name="code" class="pin-input" inputmode="numeric"
             autocomplete="one-time-code" placeholder="000000" maxlength="6" required
             value="<?= e($prefill) ?>">
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">
      Associa schermo
    </button>
  </form>
  <p class="hint" style="margin:16px 0 0">
    La TV mostra PIN e QR alla prima apertura dell'app. Il codice vale
    <?= (int) config('pairing_ttl') / 60 ?> minuti; se scade, l'app ne genera uno nuovo da sola.
    Per il metodo manuale (senza PIN) crea lo schermo in
    <a href="screens.php">Schermi</a>.
  </p>
</div>
<?php cms_footer();