<?php
declare(strict_types=1);

// Pagina mostrata dopo la disinstallazione: dati azzerati e sistema disattivato.
define('CMS_PUBLIC', true);
require __DIR__ . '/_cms.php';

// Se il lock non c'è, il sistema è attivo: non deve restare raggiungibile
if (!is_file(APP_ROOT . '/core/uninstalled.lock')) {
    redirect('index.php');
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sistema disinstallato</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
<div class="auth-card" style="max-width:560px">
  <div class="auth-brand"><span class="brand-led"></span>SISTEMA DISINSTALLATO</div>
  <p class="auth-sub">Tutti i dati sono stati eliminati e l'accesso è disattivato</p>

  <p class="hint">Per completare la rimozione dall'hosting:</p>
  <ol class="hint" style="line-height:1.9;padding-left:20px">
    <li>Elimina via FTP la cartella dell'applicazione dal server</li>
    <li>Elimina il database MySQL dal pannello dell'hosting (o le sue tabelle)</li>
    <li>Disinstalla l'app dai televisori: <em>Impostazioni → App</em></li>
  </ol>

  <p class="hint" style="margin-top:18px">Per <strong>riattivare</strong> il sistema su
  questo hosting è sufficiente eliminare il file
  <span class="mono">core/uninstalled.lock</span>: ripartirà dalla configurazione iniziale.</p>
</div>
</body>
</html>
