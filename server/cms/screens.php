<?php
declare(strict_types=1);

// Elenco schermi + creazione "manuale": lo schermo nasce qui nel CMS
// con una URL di configurazione (claim_token) da inserire nell'app.
require_once __DIR__ . '/_cms.php';
require_cap('screen.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        flash_set('err', t('screens.err_name'));
    } else {
        db()->prepare("INSERT INTO screens (name, claim_token, status) VALUES (?, ?, 'pending')")
            ->execute([$name, random_hex()]);
        flash_set('ok', t('screens.created'));
    }
    redirect('screens.php');
}

$pdo = db();
$onlineWindow = (int) config('poll_interval') * 2 + 30;
$screens = $pdo->query('SELECT s.*, COUNT(sg.gallery_id) AS n_galleries
                        FROM screens s
                        LEFT JOIN screen_galleries sg ON sg.screen_id = s.id
                        GROUP BY s.id
                        ORDER BY s.name, s.id')->fetchAll();

$methodLabels = ['pin' => 'PIN', 'qr' => 'QR', 'manual' => t('screen.method.manual')];

cms_header(t('screens.title'), 'screens');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('screens.title')) ?></h1>
    <div class="sub"><?= e(t('screens.count', ['n' => count($screens)])) ?></div>
  </div>
  <a class="btn btn-primary" href="screen_new.php"><?= e(t('screens.add')) ?></a>
</div>

<div class="card">
  <h2><?= e(t('screens.manual_title')) ?></h2>
  <p class="hint"><?= t('screens.manual_hint', ['link' => '<a href="pair.php">' . e(t('nav.screens.pair')) . '</a>']) ?></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <div class="field">
      <label for="name"><?= e(t('screens.name')) ?></label>
      <input type="text" id="name" name="name" maxlength="100" required placeholder="<?= e(t('screens.name_ph')) ?>">
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('common.create')) ?></button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('screens.all')) ?></h2>
  <?php if (!$screens): ?>
    <p class="hint" style="margin:0"><?= e(t('screens.empty')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr>
      <th><?= e(t('common.name')) ?></th>
      <th><?= e(t('screens.th_status')) ?></th>
      <th><?= e(t('screen.panel')) ?></th>
      <th><?= e(t('screens.th_schedule')) ?></th>
      <th><?= e(t('screens.th_method')) ?></th>
      <th><?= e(t('screen.galleries')) ?></th>
      <th><?= e(t('screens.th_last_seen')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($screens as $s): ?>
      <tr>
        <td><strong><?= e($s['name']) ?></strong>
          <?php if ($s['device_info']): ?><div class="hint mono" title="<?= e($s['device_info']) ?>"><?= e($s['device_info']) ?></div><?php endif; ?>
        </td>
        <td><?= screen_badge($s, $onlineWindow) ?></td>
        <td><?= $s['diagonal_inches'] !== null ? (int) $s['diagonal_inches'] . '&Prime; ' : '' ?><?= e($s['orientation'] === 'portrait' ? t('screen.portrait') : t('screen.landscape')) ?></td>
        <td><?= schedule_badge(schedule_parse($s['schedule_json']), 2) ?></td>
        <td><?= e($methodLabels[$s['pair_method']] ?? '—') ?></td>
        <td><?= (int) $s['n_galleries'] ?></td>
        <td class="hint"><?php if ($s['last_seen_at']): ?>
          <span class="cell-when">
            <span><?= date('d/m/Y', strtotime($s['last_seen_at'])) ?></span>
            <span><?= date('H:i', strtotime($s['last_seen_at'])) ?></span>
          </span>
        <?php else: ?>—<?php endif; ?></td>
        <td>
          <div class="row-actions">
            <?php // L'orologio porta dritto alla programmazione di QUESTO schermo ?>
            <a class="icon-btn" href="screen_schedule.php?id=<?= (int) $s['id'] ?>"
               data-tip="<?= e(t('screens.action_schedule', ['name' => $s['name']])) ?>"
               aria-label="<?= e(t('screens.action_schedule', ['name' => $s['name']])) ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                   stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
              </svg>
            </a>
            <a class="icon-btn" href="screen_overlay.php?id=<?= (int) $s['id'] ?>"
               data-tip="<?= e(t('screens.action_overlay', ['name' => $s['name']])) ?>"
               aria-label="<?= e(t('screens.action_overlay', ['name' => $s['name']])) ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                   stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="16" rx="2"/><rect x="12" y="14" width="7" height="4" rx="1"/>
              </svg>
            </a>
            <a class="icon-btn" href="screen.php?id=<?= (int) $s['id'] ?>"
               data-tip="<?= e(t('screens.action_manage', ['name' => $s['name']])) ?>"
               aria-label="<?= e(t('screens.action_manage', ['name' => $s['name']])) ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                   stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 6l6 6-6 6"/>
              </svg>
            </a>
          </div>
        </td>
      </tr>
      <?php if ($s['status'] === 'pending' && $s['claim_token'] !== null): ?>
      <tr>
        <td colspan="8">
          <label><?= e(t('screens.claim_label')) ?></label>
          <div class="copy-row">
            <input type="text" readonly id="claim-<?= (int) $s['id'] ?>"
                   value="<?= e(config('base_url') . '/api/v1/claim.php?token=' . $s['claim_token']) ?>">
            <button type="button" class="btn btn-sm" data-copy="#claim-<?= (int) $s['id'] ?>"><?= e(t('screens.copy')) ?></button>
          </div>
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php cms_footer();