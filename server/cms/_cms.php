<?php
declare(strict_types=1);

// Prologo comune delle pagine CMS: bootstrap, sessione, guardia di
// autenticazione (salvo pagine che dichiarano CMS_PUBLIC) e layout.
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/auth_cms.php';
require dirname(__DIR__) . '/core/caps.php';

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
if (!users_exist() && $script !== 'setup.php') {
    redirect('setup.php');
}
if (!defined('CMS_PUBLIC') && current_user() === null) {
    redirect('login.php');
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
<title><?= e($title) ?> — Frame Paring Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="shell">
<aside class="side">
  <a class="brand" href="index.php"><span class="brand-led"></span>FRAME&nbsp;PARING&nbsp;<b>STORE</b></a>
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
    </a>
    <?php if ($section['items'] && $sectionActive): ?>
    <div class="nav-sub">
      <?php foreach ($section['items'] as $item): ?>
      <a href="<?= $item['href'] ?>" class="<?= $active === $item['key'] ? 'active' : '' ?>"><?= $item['label'] ?></a>
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
    ?></main>
</div>
<script src="assets/js/sortable.min.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/preview.js"></script>
</body>
</html><?php
}
