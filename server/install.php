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

// Installazione guidata: requisiti → database → indirizzo e impostazioni →
// creazione dell'amministratore. Pensata per un hosting condiviso, dove non
// c'è una riga di comando e l'unico strumento è il browser.
//
// Non passa da bootstrap.php: quel file pretende core/config.php, che è
// proprio ciò che questa pagina deve creare.

require_once __DIR__ . '/core/version.php';
require_once __DIR__ . '/core/i18n.php';
require_once __DIR__ . '/core/installer.php';

// Stessi flag del CMS: il cookie della procedura non deve essere più
// debole di quello del pannello che sta per installare
session_name('fps_install');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Cambio lingua: la procedura è multilingua dalla prima schermata
if (isset($_GET['lang']) && lang_set((string) $_GET['lang'])) {
    header('Location: install.php' . (isset($_GET['step']) ? '?step=' . (int) $_GET['step'] : ''));
    exit;
}

// Installazione già completata: la procedura si chiude a chiave da sola.
// Senza questo controllo chiunque potrebbe riscrivere la configurazione.
if (install_already_done()) {
    http_response_code(403);
    $done = true;
} else {
    $done = false;
}

// e() non arriva da helpers.php: qui non c'è bootstrap
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$requirements = install_requirements();
$blocked = install_blocked($requirements);

$step = max(1, min(4, (int) ($_GET['step'] ?? 1)));
$errors = [];
$manualConfig = null;   // contenuto da copiare via FTP se la scrittura fallisce
$form = $_SESSION['install_form'] ?? [
    'db_host'          => 'localhost',
    'db_name'          => '',
    'db_user'          => '',
    'db_pass'          => '',
    'base_url'         => install_guess_base_url(),
    'pairing_ttl'      => 300,
    'poll_interval'    => 60,
    'state_interval'   => 10,
    'max_upload_bytes' => install_default_upload_bytes(),
];

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['do'] ?? '');

    if ($action === 'database') {
        foreach (['db_host', 'db_name', 'db_user', 'db_pass'] as $key) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        $_SESSION['install_form'] = $form;

        if ($form['db_host'] === '' || $form['db_name'] === '' || $form['db_user'] === '') {
            $errors[] = t('inst.db_required');
        } else {
            [$pdo, $error] = install_connect(
                $form['db_host'], $form['db_name'], $form['db_user'], $form['db_pass']
            );
            if ($pdo === null) {
                $errors[] = $error;
            } else {
                $existing = install_existing_tables($pdo);
                $keep = ($_POST['keep_data'] ?? '') === '1';

                if ($existing && !$keep) {
                    // Non si sovrascrive un database già popolato senza una
                    // conferma esplicita: potrebbe essere un'installazione viva
                    $errors[] = t('inst.db_not_empty', ['tables' => implode(', ', $existing)]);
                } elseif ($existing && $keep) {
                    $_SESSION['install_db_ready'] = true;
                    header('Location: install.php?step=3');
                    exit;
                } else {
                    [$ok, $error] = install_import_schema($pdo);
                    if (!$ok) {
                        $errors[] = $error;
                    } else {
                        $_SESSION['install_db_ready'] = true;
                        header('Location: install.php?step=3');
                        exit;
                    }
                }
            }
        }
        $step = 2;
    } elseif ($action === 'settings') {
        $form['base_url'] = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        foreach (['pairing_ttl', 'poll_interval', 'state_interval', 'max_upload_bytes'] as $key) {
            $form[$key] = (int) ($_POST[$key] ?? 0);
        }
        $_SESSION['install_form'] = $form;

        if (!preg_match('#^https?://#', $form['base_url'])) {
            $errors[] = t('inst.err_base_url');
        }
        if ($form['pairing_ttl'] < 60 || $form['pairing_ttl'] > 3600) {
            $errors[] = t('inst.err_pairing_ttl');
        }
        if ($form['poll_interval'] < 15 || $form['poll_interval'] > 3600) {
            $errors[] = t('inst.err_poll');
        }
        if ($form['state_interval'] < 5 || $form['state_interval'] > 300) {
            $errors[] = t('inst.err_state');
        }
        if ($form['max_upload_bytes'] < 1024 * 1024) {
            $errors[] = t('inst.err_upload');
        }

        if (!$errors) {
            $contents = install_config_contents($form);
            if (install_write_config($contents)) {
                unset($_SESSION['install_form'], $_SESSION['install_db_ready']);
                header('Location: install.php?step=4');
                exit;
            }
            // Cartella core/ non scrivibile: si mostra il file da caricare
            $manualConfig = $contents;
        }
        $step = 3;
    }
}

if ($step === 3 && empty($_SESSION['install_db_ready']) && $manualConfig === null) {
    $step = 2; // non si salta il passo del database
}
if ($step === 4 && !install_config_exists()) {
    $step = 3;
}

$stepLabels = [
    1 => t('inst.step_requirements'),
    2 => t('inst.step_database'),
    3 => t('inst.step_settings'),
    4 => t('inst.step_done'),
];
?><!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('inst.title')) ?> — <?= e(APP_NAME_DEFAULT) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Martian+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="icon" href="cms/assets/img/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="cms/assets/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="cms/assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="cms/assets/css/app.css">
</head>
<body class="install-body">
<div class="install-shell">

  <header class="install-head">
    <div class="brand"><span class="brand-led"></span><?= e(mb_strtoupper(APP_NAME_DEFAULT)) ?></div>
    <?php $langBase = 'install.php?step=' . $step; ?>
    <details class="lang-select">
      <summary aria-label="<?= e(t('chrome.language_title')) ?>">
        <span class="lang-flag"><?= lang_flag(lang()) ?></span>
        <span class="lang-name"><?= e(LANGUAGES[lang()]['label']) ?></span>
        <svg class="lang-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M6 9l6 6 6-6"/>
        </svg>
      </summary>
      <div class="lang-menu" role="menu">
        <?php foreach (lang_options() as $code => $info): ?>
        <a role="menuitem" hreflang="<?= e($code) ?>" class="lang-item<?= lang() === $code ? ' active' : '' ?>"
           href="<?= e($langBase . '&lang=' . $code) ?>">
          <span class="lang-flag"><?= lang_flag($code) ?></span>
          <span class="lang-name"><?= e($info['label']) ?></span>
          <span class="lang-code"><?= e($info['region']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </details>
  </header>

<?php if ($done): ?>
  <div class="card">
    <h2><?= e(t('inst.already_done')) ?></h2>
    <p class="hint"><?= e(t('inst.already_done_hint')) ?></p>
    <div class="form-actions"><a class="btn btn-primary" href="cms/"><?= e(t('inst.go_cms')) ?></a></div>
  </div>
</div>
</body>
</html>
<?php exit; endif; ?>

  <ol class="install-steps">
    <?php foreach ($stepLabels as $n => $label): ?>
    <li class="<?= $n === $step ? 'current' : ($n < $step ? 'done' : '') ?>">
      <span class="step-n"><?= $n < $step ? '✓' : $n ?></span>
      <span><?= e($label) ?></span>
    </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($errors): ?>
  <div class="flash flash-err">
    <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php if ($step === 1): ?>
  <div class="card">
    <h2><?= e(t('inst.step_requirements')) ?></h2>
    <p class="hint"><?= e(t('inst.req_hint')) ?></p>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th><?= e(t('caps.th_check')) ?></th>
          <th><?= e(t('caps.th_result')) ?></th>
          <th><?= e(t('caps.th_howto')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($requirements as $r): ?>
          <tr>
            <td><strong><?= e($r['label']) ?></strong>
              <?php if (!$r['blocking']): ?>
              <div class="hint"><?= e(t('inst.optional')) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $r['ok'] ? 'badge-online' : ($r['blocking'] ? 'badge-revoked' : 'badge-pending') ?>">
                <span class="led"></span><?= e($r['detail']) ?>
              </span>
            </td>
            <td class="hint"><?= $r['ok'] ? '—' : e($r['howto']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="form-actions">
      <?php if ($blocked): ?>
      <a class="btn" href="install.php?step=1"><?= e(t('inst.recheck')) ?></a>
      <span class="hint"><?= e(t('inst.blocked')) ?></span>
      <?php else: ?>
      <a class="btn btn-primary" href="install.php?step=2"><?= e(t('inst.continue')) ?></a>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($step === 2): ?>
  <div class="card">
    <h2><?= e(t('inst.step_database')) ?></h2>
    <p class="hint"><?= e(t('inst.db_hint')) ?></p>
    <form method="post">
      <input type="hidden" name="do" value="database">
      <div class="field-grid">
        <div class="field">
          <label for="db_host"><?= e(t('inst.db_host')) ?></label>
          <input type="text" id="db_host" name="db_host" required value="<?= e((string) $form['db_host']) ?>">
          <p class="hint" style="margin:6px 0 0"><?= e(t('inst.db_host_hint')) ?></p>
        </div>
        <div class="field">
          <label for="db_name"><?= e(t('inst.db_name')) ?> <span class="req">*</span></label>
          <input type="text" id="db_name" name="db_name" required value="<?= e((string) $form['db_name']) ?>">
        </div>
        <div class="field">
          <label for="db_user"><?= e(t('inst.db_user')) ?> <span class="req">*</span></label>
          <input type="text" id="db_user" name="db_user" required value="<?= e((string) $form['db_user']) ?>">
        </div>
        <div class="field">
          <label for="db_pass"><?= e(t('inst.db_pass')) ?></label>
          <input type="password" id="db_pass" name="db_pass" value="<?= e((string) $form['db_pass']) ?>">
        </div>
      </div>

      <label class="switch" for="keep_data" style="margin-top:18px">
        <input type="checkbox" id="keep_data" name="keep_data" value="1" data-switch>
        <span class="switch-track"></span>
        <span class="switch-label">
          <span><?= e(t('inst.keep_data')) ?></span>
          <span class="switch-state" data-switch-state
                data-on="<?= e(t('inst.keep_data_on')) ?>" data-off="<?= e(t('inst.keep_data_off')) ?>"><?= e(t('inst.keep_data_off')) ?></span>
        </span>
      </label>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= e(t('inst.db_submit')) ?></button>
        <a class="btn" href="install.php?step=1"><?= e(t('common.back')) ?></a>
      </div>
    </form>
  </div>

<?php elseif ($step === 3): ?>
  <div class="card">
    <h2><?= e(t('inst.step_settings')) ?></h2>
    <p class="hint"><?= e(t('inst.settings_hint')) ?></p>
    <form method="post">
      <input type="hidden" name="do" value="settings">
      <div class="field">
        <label for="base_url"><?= e(t('inst.base_url')) ?> <span class="req">*</span></label>
        <input type="text" id="base_url" name="base_url" required value="<?= e((string) $form['base_url']) ?>">
        <p class="hint" style="margin:6px 0 0"><?= e(t('inst.base_url_hint')) ?></p>
      </div>
      <div class="field-grid" style="margin-top:18px">
        <div class="field">
          <label for="pairing_ttl"><?= e(t('inst.pairing_ttl')) ?></label>
          <input type="number" id="pairing_ttl" name="pairing_ttl" min="60" max="3600" step="30"
                 value="<?= (int) $form['pairing_ttl'] ?>">
        </div>
        <div class="field">
          <label for="poll_interval"><?= e(t('inst.poll_interval')) ?></label>
          <input type="number" id="poll_interval" name="poll_interval" min="15" max="3600" step="5"
                 value="<?= (int) $form['poll_interval'] ?>">
        </div>
        <div class="field">
          <label for="state_interval"><?= e(t('inst.state_interval')) ?></label>
          <input type="number" id="state_interval" name="state_interval" min="5" max="300"
                 value="<?= (int) $form['state_interval'] ?>">
        </div>
        <div class="field">
          <label for="max_upload_bytes"><?= e(t('inst.max_upload')) ?></label>
          <input type="number" id="max_upload_bytes" name="max_upload_bytes" min="1048576" step="1048576"
                 value="<?= (int) $form['max_upload_bytes'] ?>">
          <p class="hint" style="margin:6px 0 0"><?= e(t('inst.max_upload_hint', [
            'upload' => (string) ini_get('upload_max_filesize'),
            'post'   => (string) ini_get('post_max_size'),
          ])) ?></p>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= e(t('inst.settings_submit')) ?></button>
      </div>
    </form>
  </div>

  <?php if ($manualConfig !== null): ?>
  <div class="card danger-card">
    <h2><?= e(t('inst.manual_title')) ?></h2>
    <p class="hint"><?= e(t('inst.manual_hint')) ?></p>
    <textarea class="mono install-config" rows="26" readonly onclick="this.select()"><?= e($manualConfig) ?></textarea>
    <div class="form-actions">
      <a class="btn btn-primary" href="install.php?step=4"><?= e(t('inst.manual_done')) ?></a>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <div class="card">
    <h2><?= e(t('inst.step_done')) ?></h2>
    <p class="hint"><?= e(t('inst.done_hint')) ?></p>
    <ul class="hint" style="line-height:1.9;padding-left:20px">
      <li><?= e(t('inst.done_admin')) ?></li>
      <li><?= e(t('inst.done_delete')) ?></li>
    </ul>
    <div class="form-actions">
      <a class="btn btn-primary" href="cms/setup.php"><?= e(t('inst.done_cta')) ?></a>
    </div>
  </div>
<?php endif; ?>

</div>
<script src="cms/assets/js/app.js"></script>
</body>
</html>
