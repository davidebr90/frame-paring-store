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

// Prologo comune delle pagine CMS: bootstrap, sessione, guardia di
// autenticazione (salvo pagine che dichiarano CMS_PUBLIC) e layout.
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/auth_cms.php';
require dirname(__DIR__) . '/core/caps.php';
require dirname(__DIR__) . '/core/trash.php';
require dirname(__DIR__) . '/core/branding.php';
require dirname(__DIR__) . '/core/capabilities.php';

// Nessuno stack trace verso il browser: un errore PDO mostrerebbe host,
// utente e password del database nella pagina
set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage());
    http_response_code(500);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json')
        || str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Errore interno del server']);
    } else {
        echo '<!doctype html><meta charset="utf-8">'
            . '<p style="font:16px system-ui;padding:2rem">Errore interno del server. '
            . 'Controlla il log degli errori dell\'hosting.</p>';
    }
    exit;
});
ini_set('display_errors', '0');

cms_session_start();

// Il CMS non va mai incorniciato: evita clickjacking (es. conferma di
// pairing di un dispositivo ostile con un click su una pagina esca)
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: same-origin');

$script = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Installazione dismessa: nessuna pagina è raggiungibile finché il lock c'è
if (is_file(APP_ROOT . '/core/uninstalled.lock') && $script !== 'uninstalled.php') {
    redirect('uninstalled.php');
}

if (!users_exist() && $script !== 'setup.php') {
    redirect('setup.php');
}
if (!defined('CMS_PUBLIC') && current_user() === null) {
    redirect('login.php');
}

// Su hosting condiviso non c'è un cron affidabile: la pulizia del cestino
// scatta durante la normale navigazione, al massimo una volta all'ora
if (current_user() !== null) {
    trash_autoclean();
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function screen_badge(array $s, int $onlineWindow): string
{
    if ($s['status'] === 'revoked') {
        return '<span class="badge badge-revoked"><span class="led"></span>Revocato</span>';
    }
    if ($s['status'] === 'pending') {
        return '<span class="badge badge-pending"><span class="led"></span>In attesa</span>';
    }
    $isOnline = $s['last_seen_at'] !== null && time() - strtotime($s['last_seen_at']) <= $onlineWindow;
    return $isOnline
        ? '<span class="badge badge-online"><span class="led"></span>Online</span>'
        : '<span class="badge badge-offline"><span class="led"></span>Offline</span>';
}

function human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < 4) {
        $v /= 1024;
        $i++;
    }
    return round($v, $i > 1 ? 1 : 0) . ' ' . $units[$i];
}

function cms_header(string $title, string $active = ''): void
{
    $user = current_user();

    // Struttura del menu: sezioni con sottovoci, ognuna filtrata per
    // capability. Una sezione sparisce se nessuna sua voce è accessibile.
    $navSections = [
        [
            'key'   => 'index',
            'href'  => 'index.php',
            'label' => 'Dashboard',
            'icon'  => 'M3 5h18v11H3zM8 20h8',
            'cap'   => null,
            'items' => [],
        ],
        [
            'key'   => 'screens',
            'href'  => 'screens.php',
            'label' => 'Schermi',
            'icon'  => 'M2 6h20v12H2zM12 18v3M8 21h8',
            'cap'   => null,
            'items' => [
                ['key' => 'screens',     'href' => 'screens.php',    'label' => 'Elenco schermi',  'cap' => 'screen.manage'],
                ['key' => 'screens.new', 'href' => 'screen_new.php', 'label' => 'Aggiungi schermo', 'cap' => 'screen.manage'],
                ['key' => 'pair',        'href' => 'pair.php',       'label' => 'Associa con PIN',  'cap' => 'screen.pair'],
            ],
        ],
        [
            'key'   => 'content',
            'href'  => 'galleries.php',
            'label' => 'Contenuti',
            'icon'  => 'M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z',
            'cap'   => null,
            'items' => [
                ['key' => 'galleries',     'href' => 'galleries.php',   'label' => 'Gallerie',        'cap' => null],
                ['key' => 'galleries.new', 'href' => 'gallery_new.php', 'label' => 'Nuova galleria',  'cap' => 'gallery.manage'],
                ['key' => 'trash',         'href' => 'trash.php',       'label' => 'Cestino',         'cap' => 'media.upload'],
            ],
        ],
        [
            'key'   => 'users',
            'href'  => 'users.php',
            'label' => 'Utenti',
            'icon'  => 'M16 20v-2a4 4 0 00-8 0v2M12 12a4 4 0 100-8 4 4 0 000 8z',
            'cap'   => 'users.manage',
            'items' => [
                ['key' => 'users',     'href' => 'users.php',    'label' => 'Lista utenti', 'cap' => 'users.manage'],
                ['key' => 'users.new', 'href' => 'user_new.php', 'label' => 'Crea utente',  'cap' => 'users.manage'],
            ],
        ],
        [
            'key'   => 'account',
            'href'  => 'profile.php',
            'label' => 'Account',
            'icon'  => 'M12 12a4 4 0 100-8 4 4 0 000 8zM6 21v-2a6 6 0 0112 0v2',
            'cap'   => null,
            'items' => [
                ['key' => 'profile', 'href' => 'profile.php', 'label' => 'Il mio profilo', 'cap' => null],
            ],
        ],
        [
            'key'   => 'live',
            'href'  => 'live.php',
            'label' => 'Dirette',
            'icon'  => 'M12 12m-3 0a3 3 0 106 0 3 3 0 10-6 0M5.6 5.6a9 9 0 000 12.8M18.4 5.6a9 9 0 010 12.8',
            'cap'   => 'screen.manage',
            'items' => [
                ['key' => 'live',        'href' => 'live.php',   'label' => 'Sorgenti live',  'cap' => 'screen.manage'],
                // Bloccata dove il server non ha le capacità necessarie:
                // resta visibile per far capire cosa offrirebbe
                ['key' => 'live.webcam', 'href' => 'live_webcam.php', 'label' => 'Diretta webcam',
                 'cap' => 'screen.manage', 'feature' => 'live.webcam'],
            ],
        ],
        [
            'key'   => 'system',
            'href'  => 'branding.php',
            'label' => 'Sistema',
            'icon'  => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 008 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9v0a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z',
            'cap'   => 'settings.manage',
            'items' => [
                ['key' => 'branding',     'href' => 'branding.php',     'label' => 'Personalizzazione', 'cap' => 'settings.manage'],
                ['key' => 'capabilities', 'href' => 'capabilities.php', 'label' => 'Capacità',          'cap' => 'settings.manage'],
                ['key' => 'maintenance',  'href' => 'maintenance.php',  'label' => 'Manutenzione',      'cap' => 'settings.manage'],
            ],
        ],
    ];

    // Filtro per permessi
    $nav = [];
    foreach ($navSections as $section) {
        if ($section['cap'] !== null && !current_can($section['cap'])) {
            continue;
        }
        $section['items'] = array_values(array_filter(
            $section['items'],
            fn (array $i): bool => $i['cap'] === null || current_can($i['cap'])
        ));
        // Sezione con sottovoci ma nessuna accessibile: si nasconde
        if ($section['items'] === [] && $section['key'] !== 'index') {
            continue;
        }
        // Il link della sezione punta alla prima voce realmente accessibile
        if ($section['items'] !== []) {
            $section['href'] = $section['items'][0]['href'];
        }
        $nav[] = $section;
    }
    ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> — <?= e(branding('brand_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<style>:root { --accent: <?= branding_accent() ?>; --accent-ink: <?= branding_accent_ink() ?>; --accent-soft: <?= branding_accent() ?>1f; }</style>
</head>
<body>
<div class="shell">
<aside class="side">
  <a class="brand" href="index.php">
    <?php if ($logo = branding_logo_url()): ?>
      <img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e(branding('brand_name')) ?>">
    <?php else: ?>
      <span class="brand-led"></span><?= e(mb_strtoupper(branding('brand_name'))) ?>
    <?php endif; ?>
  </a>
  <nav class="nav">
    <?php foreach ($nav as $section):
        // La sezione è attiva se lo è una delle sue voci
        $sectionActive = $active === $section['key']
            || str_starts_with($active, $section['key'] . '.')
            || in_array($active, array_column($section['items'], 'key'), true);
    ?>
    <a href="<?= $section['href'] ?>" class="nav-link<?= $sectionActive ? ' active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $section['icon'] ?>"/></svg>
      <span><?= $section['label'] ?></span>
      <?php if ($section['items']): ?>
      <svg class="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
      <?php endif; ?>
    </a>
    <?php if ($section['items'] && $sectionActive): ?>
    <div class="nav-sub">
      <?php foreach ($section['items'] as $item):
          // Voce legata a una funzionalità non disponibile: resta visibile
          // ma bloccata, così si capisce cosa offrirebbe
          $locked = isset($item['feature']) && !feature_available($item['feature']);
      ?>
      <?php if ($locked): ?>
      <a href="#" class="locked" data-feature="<?= e($item['feature']) ?>">
        <?= $item['label'] ?>
        <svg class="nav-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
      </a>
      <?php else: ?>
      <a href="<?= $item['href'] ?>" class="<?= $active === $item['key'] ? 'active' : '' ?>"><?= $item['label'] ?></a>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <a class="side-user" href="profile.php" title="Il tuo profilo"><?= e($user['full_name'] ?: ($user['username'] ?? '')) ?></a>
    <a class="side-logout" href="logout.php" title="Esci">Esci</a>
  </div>
</aside>
<main class="main">
<?php if ($f = flash_get()): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endif; ?>
<?php
}

function cms_footer(): void
{
    ?>
<footer class="app-footer">
  <div class="app-footer-left">
    <strong><?= e(branding('brand_name')) ?></strong>
    <span class="mono">v<?= APP_VERSION ?></span>
    <span>·</span>
    <span><?= date('d/m/Y', strtotime(APP_RELEASE_DATE)) ?></span>
  </div>
  <div class="app-footer-right">
    <?php if ($note = branding('brand_footer_note')): ?>
      <span><?= e($note) ?></span>
    <?php else: ?>
      <span>Sistema di digital signage self-hosted</span>
    <?php endif; ?>
  </div>
</footer>
</main>
</div>
<!-- Diagnostica delle funzioni bloccate: fattuale, mai commerciale -->
<div class="modal" id="feature-modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="feature-modal-title">
    <h2 id="feature-modal-title"></h2>
    <p class="hint" data-modal-summary></p>
    <div data-modal-missing></div>
    <p class="hint" data-modal-note></p>
    <div class="form-actions">
      <a class="btn btn-primary" href="capabilities.php">Vedi tutti i requisiti</a>
      <button type="button" class="btn" data-modal-close>Chiudi</button>
    </div>
  </div>
</div>
<script>window.FPS_FEATURES = <?= json_encode(array_map(
    fn (string $key): array => [
        'label'     => FEATURES[$key]['label'],
        'summary'   => FEATURES[$key]['summary'],
        'available' => feature_available($key),
        'missing'   => array_values(array_map(
            fn (array $c): array => ['label' => $c['label'], 'detail' => $c['detail'], 'howto' => $c['howto']],
            feature_missing($key)
        )),
    ],
    array_combine(array_keys(FEATURES), array_keys(FEATURES))
), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/js/sortable.min.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/preview.js"></script>
</body>
</html><?php
}
