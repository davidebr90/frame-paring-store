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
require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth_cms.php';
require_once dirname(__DIR__) . '/core/caps.php';
require_once dirname(__DIR__) . '/core/trash.php';
require_once dirname(__DIR__) . '/core/branding.php';
require_once dirname(__DIR__) . '/core/capabilities.php';
require_once dirname(__DIR__) . '/core/live.php';
require_once dirname(__DIR__) . '/core/overlay.php';

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

// Cambio lingua: vale per la sessione, anche prima del login. Chi è
// connesso se la ritrova salvata nel proprio profilo.
// DEVE stare dopo cms_session_start(): scrivere in $_SESSION prima che la
// sessione esista butterebbe via la scelta.
if (isset($_GET['lang']) && lang_set((string) $_GET['lang'])) {
    if (($me = current_user()) !== null) {
        db()->prepare('UPDATE users SET language = ? WHERE id = ?')
            ->execute([(string) $_GET['lang'], $me['id']]);
    }
    // Si torna alla pagina di partenza senza il parametro, così un
    // aggiornamento non riapplica il cambio
    $back = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '?');
    $query = $_GET;
    unset($query['lang']);
    redirect(basename($back) . ($query ? '?' . http_build_query($query) : ''));
}

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
        return '<span class="badge badge-revoked"><span class="led"></span>' . e(t('screen.badge.revoked')) . '</span>';
    }
    if ($s['status'] === 'pending') {
        return '<span class="badge badge-pending"><span class="led"></span>' . e(t('screen.badge.pending')) . '</span>';
    }
    $isOnline = $s['last_seen_at'] !== null && time() - strtotime($s['last_seen_at']) <= $onlineWindow;
    return $isOnline
        ? '<span class="badge badge-online"><span class="led"></span>' . e(t('screen.badge.online')) . '</span>'
        : '<span class="badge badge-offline"><span class="led"></span>' . e(t('screen.badge.offline')) . '</span>';
}

// Rappresentazione visiva della programmazione: per ogni fascia, le sette
// pastiglie dei giorni con quelle attive evidenziate e l'orario sotto.
// Un testo come "Lun–Ven 09:00–20:00 · Sab 10:00–13:00" va letto; questo si
// riconosce, e incolonnato non allarga la tabella.
//
// $max limita le fasce mostrate: le altre diventano un marcatore che, al
// passaggio del mouse o al tocco, le elenca per esteso.
function schedule_badge(array $bands, ?int $max = null): string
{
    if ($bands === []) {
        return '<span class="sched-always">' . e(t('sch.always')) . '</span>';
    }

    $iniziali = schedule_day_labels(true);
    $nomi = schedule_day_labels();
    $mostrate = $max === null ? $bands : array_slice($bands, 0, $max);
    $restanti = array_slice($bands, count($mostrate));

    $out = '<span class="sched-list">';
    foreach ($mostrate as $band) {
        $scavalca = schedule_minutes($band['from']) > schedule_minutes($band['to']);
        $out .= '<span class="sched-badge">'
            . schedule_day_pills($band['days'], $iniziali, $nomi)
            . '<span class="sched-time">' . e($band['from'] . '–' . $band['to'])
            . ($scavalca ? tip_marker('↷', t('sch.crosses_midnight'), 'sched-cross') : '')
            . '</span></span>';
    }

    if ($restanti) {
        $elenco = [];
        foreach ($restanti as $band) {
            $elenco[] = schedule_days_label($band['days']) . ' ' . $band['from'] . '–' . $band['to'];
        }
        $etichetta = count($restanti) === 1
            ? t('sch.more_bands', ['n' => 1])
            : t('sch.more_bands_plural', ['n' => count($restanti)]);
        // La dicitura per esteso si spiega da sé: un "+2" da solo
        // costringerebbe a indovinare cosa conta
        $out .= tip_marker(
            e($etichetta),
            t('sch.more_tip', ['list' => implode(' · ', $elenco)]),
            'sched-more'
        );
    }
    return $out . '</span>';
}

function schedule_day_pills(array $days, array $iniziali, array $nomi): string
{
    $out = '<span class="sched-days">';
    foreach (SCHEDULE_DAYS as $d) {
        $attivo = in_array($d, $days, true);
        $out .= '<span class="sched-day' . ($attivo ? ' on' : '') . '"'
            . ' title="' . e($nomi[$d]) . '">'
            // Due lettere, non una: "M" da solo non distingue martedì
            // da mercoledì, e in inglese vale lo stesso per Tuesday/Thursday
            . e(mb_substr($iniziali[$d], 0, 2)) . '</span>';
    }
    return $out . '</span>';
}

// Marcatore con spiegazione. Non usa l'attributo title, che sui dispositivi
// touch non compare mai: il suggerimento si apre al passaggio del mouse e
// anche al tocco, ed è raggiungibile da tastiera.
function tip_marker(string $html, string $spiegazione, string $classe = ''): string
{
    return '<button type="button" class="tip ' . e($classe) . '"'
        . ' data-tip="' . e($spiegazione) . '"'
        . ' aria-label="' . e($spiegazione) . '">' . $html . '</button>';
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
            'label' => t('nav.dashboard'),
            'icon'  => 'M3 5h18v11H3zM8 20h8',
            'cap'   => null,
            'items' => [],
        ],
        [
            'key'   => 'screens',
            'href'  => 'screens.php',
            'label' => t('nav.screens'),
            'icon'  => 'M2 6h20v12H2zM12 18v3M8 21h8',
            'cap'   => null,
            // Il sottomenu elenca TUTTE le pagine della sezione, compresa
            // quella su cui la sezione stessa punta: chi legge il menu si
            // aspetta l'inventario completo, non le sole voci aggiuntive.
            'items' => [
                ['key' => 'screens',     'href' => 'screens.php',    'label' => t('nav.screens.list'), 'cap' => 'screen.manage'],
                ['key' => 'screens.new', 'href' => 'screen_new.php', 'label' => t('nav.screens.new'),  'cap' => 'screen.manage'],
                ['key' => 'pair',        'href' => 'pair.php',       'label' => t('nav.screens.pair'), 'cap' => 'screen.pair'],
            ],
        ],
        [
            'key'   => 'content',
            'href'  => 'galleries.php',
            'label' => t('nav.content'),
            'icon'  => 'M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z',
            'cap'   => null,
            'items' => [
                ['key' => 'galleries',     'href' => 'galleries.php',   'label' => t('nav.galleries'),     'cap' => null],
                ['key' => 'galleries.new', 'href' => 'gallery_new.php', 'label' => t('nav.galleries.new'), 'cap' => 'gallery.manage'],
                ['key' => 'trash',         'href' => 'trash.php',       'label' => t('nav.trash'),         'cap' => 'media.upload'],
            ],
        ],
        [
            'key'   => 'schedule',
            'href'  => 'schedule.php',
            'label' => t('nav.schedule'),
            'icon'  => 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z',
            'cap'   => 'schedule.manage',
            'items' => [
                ['key' => 'schedule', 'href' => 'schedule.php', 'label' => t('nav.schedule.overview'), 'cap' => 'schedule.manage'],
            ],
        ],
        [
            'key'   => 'users',
            'href'  => 'users.php',
            'label' => t('nav.users'),
            'icon'  => 'M16 20v-2a4 4 0 00-8 0v2M12 12a4 4 0 100-8 4 4 0 000 8z',
            'cap'   => 'users.manage',
            'items' => [
                ['key' => 'users',     'href' => 'users.php',    'label' => t('nav.users.list'), 'cap' => 'users.manage'],
                ['key' => 'users.new', 'href' => 'user_new.php', 'label' => t('nav.users.new'),  'cap' => 'users.manage'],
            ],
        ],
        [
            'key'   => 'account',
            'href'  => 'profile.php',
            'label' => t('nav.account'),
            'icon'  => 'M12 12a4 4 0 100-8 4 4 0 000 8zM6 21v-2a6 6 0 0112 0v2',
            'cap'   => null,
            'items' => [
                ['key' => 'profile', 'href' => 'profile.php', 'label' => t('nav.profile'), 'cap' => null],
            ],
        ],
        [
            'key'   => 'live',
            'href'  => 'live.php',
            'label' => t('nav.live'),
            'icon'  => 'M12 12m-3 0a3 3 0 106 0 3 3 0 10-6 0M5.6 5.6a9 9 0 000 12.8M18.4 5.6a9 9 0 010 12.8',
            'cap'   => 'screen.manage',
            // Spia di stato accanto alla voce: si vede a colpo d'occhio
            // se qualcosa è in onda senza aprire la sezione
            'status' => current_can('screen.manage') ? live_status() : null,
            'items' => [
                ['key' => 'live', 'href' => 'live.php', 'label' => t('nav.live.sources'), 'cap' => 'screen.manage'],
                // Bloccata dove il server non ha le capacità necessarie:
                // resta visibile per far capire cosa offrirebbe
                ['key' => 'live.webcam', 'href' => 'live_webcam.php', 'label' => t('nav.live.webcam'),
                 'cap' => 'screen.manage', 'feature' => 'live.webcam'],
            ],
        ],
        [
            'key'   => 'system',
            'href'  => 'branding.php',
            'label' => t('nav.system'),
            'icon'  => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 008 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9v0a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z',
            'cap'   => 'settings.manage',
            'items' => [
                ['key' => 'branding',     'href' => 'branding.php',     'label' => t('nav.branding'), 'cap' => 'settings.manage'],
                ['key' => 'overlay',      'href' => 'overlay.php',      'label' => t('nav.overlay'),  'cap' => 'settings.manage'],
                ['key' => 'mail',         'href' => 'mail.php',         'label' => t('nav.mail'),     'cap' => 'settings.manage'],
                ['key' => 'capabilities', 'href' => 'capabilities.php', 'label' => t('nav.capabilities'),          'cap' => 'settings.manage'],
                ['key' => 'maintenance',  'href' => 'maintenance.php',  'label' => t('nav.maintenance'),      'cap' => 'settings.manage'],
            ],
        ],
    ];

    // Filtro per permessi
    $nav = [];
    foreach ($navSections as $section) {
        if ($section['cap'] !== null && !current_can($section['cap'])) {
            continue;
        }
        // Alcune sezioni non hanno sottovoci per scelta (il loro nome è già
        // la pagina): vanno distinte da quelle che le hanno tutte precluse
        $conSottovoci = $section['items'] !== [];
        $section['items'] = array_values(array_filter(
            $section['items'],
            fn (array $i): bool => $i['cap'] === null || current_can($i['cap'])
        ));
        // Aveva sottovoci ma nessuna è accessibile: la sezione si nasconde
        if ($conSottovoci && $section['items'] === []) {
            continue;
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
<link rel="icon" href="<?= e(branding_favicon_url()) ?>" sizes="any">
<link rel="icon" type="image/png" href="assets/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
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
      <?php if (!empty($section['status'])): ?>
      <span class="nav-dot nav-dot-<?= e($section['status']['state']) ?>"
            title="<?= e($section['status']['label']) ?>"
            role="img" aria-label="<?= e($section['status']['label']) ?>"></span>
      <?php endif; ?>
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
  <?php require __DIR__ . '/_lang_select.php'; ?>
  <div class="side-foot">
    <a class="side-user" href="profile.php" title="<?= e(t('chrome.profile_title')) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zM6 21v-2a6 6 0 0112 0v2"/></svg><span><?= e($user['full_name'] ?: ($user['username'] ?? '')) ?></span></a>
    <a class="side-logout" href="logout.php" title="<?= e(t('chrome.logout')) ?>"><?= e(t('chrome.logout')) ?></a>
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
      <span><?= e(t('chrome.tagline')) ?></span>
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
      <a class="btn btn-primary" href="capabilities.php"><?= e(t('chrome.requirements')) ?></a>
      <button type="button" class="btn" data-modal-close><?= e(t('common.close')) ?></button>
    </div>
  </div>
</div>
<script>window.FPS_FEATURES = <?= json_encode(array_map(
    fn (string $key): array => [
        'label'     => t(FEATURES[$key]['label']),
        'summary'   => t(FEATURES[$key]['summary']),
        'available' => feature_available($key),
        'missing'   => array_values(array_map(
            fn (array $c): array => capability_view($c),
            feature_missing($key)
        )),
    ],
    array_combine(array_keys(FEATURES), array_keys(FEATURES))
), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/js/sortable.min.js?v=<?= APP_VERSION ?>"></script>
<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
<script src="assets/js/preview.js?v=<?= APP_VERSION ?>"></script>
<script src="assets/js/live-preview.js?v=<?= APP_VERSION ?>"></script>
</body>
</html><?php
}
