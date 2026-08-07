<?php
declare(strict_types=1);

// Cestino: media e gallerie eliminati, con ripristino, svuotamento
// manuale e regola di conservazione automatica.
require_once __DIR__ . '/_cms.php';
require_cap('media.upload');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'restore_media') {
        restore_media($id);
        flash_set('ok', t('trs.media_restored'));
    } elseif ($do === 'restore_gallery') {
        restore_gallery($id);
        flash_set('ok', t('trs.gal_restored'));
    } elseif ($do === 'purge_media') {
        $r = purge_trash($id);
        flash_set('ok', t('trs.media_purged', ['size' => human_bytes($r['bytes'])]));
    } elseif ($do === 'purge_gallery') {
        $r = purge_trash(null, $id);
        flash_set('ok', t('trs.gal_purged', ['files' => $r['media'], 'size' => human_bytes($r['bytes'])]));
    } elseif ($do === 'restore_user') {
        require_cap('users.manage');
        restore_user($id);
        flash_set('ok', t('trs.user_restored'));
    } elseif ($do === 'purge_user') {
        require_cap('users.manage');
        purge_user($id);
        flash_set('ok', t('trs.user_purged'));
    } elseif ($do === 'empty') {
        $r = purge_trash();
        if (current_can('users.manage')) {
            $pdo->query('DELETE FROM users WHERE deleted_at IS NOT NULL');
        }
        flash_set('ok', t('trs.emptied', [
            'files' => $r['media'], 'galleries' => $r['galleries'], 'size' => human_bytes($r['bytes']),
        ]));
    } elseif ($do === 'retention') {
        require_cap('settings.manage');
        $days = (int) ($_POST['days'] ?? -1);
        if ($days < 0 || $days > 3650) {
            flash_set('err', t('trs.err_days'));
        } else {
            setting_set('trash_retention_days', (string) $days);
            flash_set('ok', $days > 0
                ? t('trs.days_saved', ['n' => $days])
                : t('trs.days_forever'));
        }
    }
    redirect('trash.php');
}

// Gallerie cestinate, con il conteggio dei loro file
$galleries = $pdo->query('SELECT g.*, u.username AS deleted_by_name,
                                 (SELECT COUNT(*) FROM media m WHERE m.gallery_id = g.id) AS n_media,
                                 (SELECT COALESCE(SUM(m.filesize), 0) FROM media m WHERE m.gallery_id = g.id) AS bytes
                          FROM galleries g
                          LEFT JOIN users u ON u.id = g.deleted_by
                          WHERE g.deleted_at IS NOT NULL
                          ORDER BY g.deleted_at DESC')->fetchAll();

// Media cestinati singolarmente (la loro galleria è ancora attiva)
$media = $pdo->query('SELECT m.*, g.name AS gallery_name, u.username AS deleted_by_name
                      FROM media m
                      JOIN galleries g ON g.id = m.gallery_id
                      LEFT JOIN users u ON u.id = m.deleted_by
                      WHERE m.deleted_at IS NOT NULL AND g.deleted_at IS NULL
                      ORDER BY m.deleted_at DESC')->fetchAll();

$totals = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(filesize), 0) AS bytes
                       FROM media WHERE deleted_at IS NOT NULL')->fetch();
$retention = (int) setting_get('trash_retention_days', '30');

// Utenti nel cestino (solo per chi gestisce gli utenti)
$trashedUsers = [];
if (current_can('users.manage')) {
    $trashedUsers = $pdo->query('SELECT u.*, d.username AS deleted_by_name
                                 FROM users u
                                 LEFT JOIN users d ON d.id = u.deleted_by
                                 WHERE u.deleted_at IS NOT NULL
                                 ORDER BY u.deleted_at DESC')->fetchAll();
}

cms_header(t('trs.title'), 'trash');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('trs.title')) ?></h1>
    <div class="sub">
      <?= e(t('trs.subtitle', [
        'n'         => (int) $totals['n'],
        'size'      => human_bytes((int) $totals['bytes']),
        'retention' => $retention > 0
            ? t('trs.retention_days', ['n' => $retention])
            : t('trs.retention_forever'),
      ])) ?>
    </div>
  </div>
  <?php if ($galleries || $media): ?>
  <form method="post" onsubmit="return confirm('<?= e(t('trs.empty_confirm')) ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="empty">
    <button class="btn btn-danger" type="submit"><?= e(t('trs.empty_btn')) ?></button>
  </form>
  <?php endif; ?>
</div>

<?php if (current_can('settings.manage')): ?>
<div class="card">
  <h2><?= e(t('trs.retention')) ?></h2>
  <p class="hint"><?= t('trs.retention_hint', ['zero' => '<strong>0</strong>']) ?></p>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="retention">
    <div class="field" style="max-width:220px">
      <label for="days"><?= e(t('trs.delete_after')) ?></label>
      <input type="number" id="days" name="days" min="0" max="3650" value="<?= $retention ?>">
    </div>
    <button class="btn" type="submit"><?= e(t('common.save')) ?></button>
  </form>
</div>
<?php endif; ?>

<?php if (!$galleries && !$media && !$trashedUsers): ?>
<div class="card">
  <p class="hint" style="margin:0"><?= e(t('trs.is_empty')) ?></p>
</div>
<?php endif; ?>

<?php if ($trashedUsers): ?>
<div class="card">
  <h2><?= e(t('trs.users')) ?></h2>
  <p class="hint"><?= t('trs.users_hint', ['disabled' => '<strong>' . e(t('trs.users_disabled')) . '</strong>']) ?></p>
  <div class="table-wrap">
    <table class="user-table">
      <thead><tr><th><?= e(t('usr.th_user')) ?></th><th><?= e(t('usr.role')) ?></th><th><?= e(t('usr.th_email')) ?></th><th><?= e(t('trs.th_deleted_m')) ?></th><th><?= e(t('trs.th_by')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($trashedUsers as $u): ?>
        <tr class="role-<?= e($u['role']) ?>">
          <td>
            <div class="cell-user">
              <span class="user-avatar sm"><?= e(mb_strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 1))) ?></span>
              <div>
                <strong><?= e($u['full_name'] ?: $u['username']) ?></strong>
                <div class="hint mono">@<?= e($u['username']) ?></div>
              </div>
            </div>
          </td>
          <td><span class="role-chip"><?= e(role_label($u['role'])) ?></span></td>
          <td class="hint"><?= $u['email'] ? e($u['email']) : '—' ?></td>
          <td class="hint"><?= date('d/m/Y H:i', strtotime($u['deleted_at'])) ?></td>
          <td class="hint"><?= e($u['deleted_by_name'] ?? '—') ?></td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="restore_user">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= e(t('common.restore')) ?></button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('<?= e(t('trs.purge_user_confirm')) ?>')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="purge_user">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit"><?= e(t('trs.purge_forever')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($galleries): ?>
<div class="card">
  <h2><?= e(t('trs.galleries')) ?></h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= e(t('trs.th_gallery')) ?></th><th><?= e(t('trs.th_files')) ?></th><th><?= e(t('gal.th_size')) ?></th><th><?= e(t('trs.th_deleted_f')) ?></th><th><?= e(t('trs.th_by')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($galleries as $g): ?>
        <tr>
          <td><strong><?= e($g['name']) ?></strong></td>
          <td><?= (int) $g['n_media'] ?></td>
          <td><?= human_bytes((int) $g['bytes']) ?></td>
          <td class="hint"><?= date('d/m/Y H:i', strtotime($g['deleted_at'])) ?></td>
          <td class="hint"><?= e($g['deleted_by_name'] ?? '—') ?></td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="restore_gallery">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= e(t('common.restore')) ?></button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('<?= e(t('trs.purge_gal_confirm')) ?>')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="purge_gallery">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit"><?= e(t('trs.purge_forever')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($media): ?>
<div class="card">
  <h2><?= e(t('trs.files')) ?></h2>
  <div class="media-grid trash-grid">
    <?php foreach ($media as $m): ?>
    <div class="media-card">
      <div class="media-thumb" data-rot="<?= (int) $m['rotation'] ?>" data-fit="<?= e($m['fit']) ?>">
        <span class="media-type"><?= $m['type'] === 'video' ? 'video' : 'img' ?></span>
        <?php if ($m['type'] === 'image'): ?>
          <img src="media_preview.php?id=<?= (int) $m['id'] ?>" alt="" loading="lazy">
        <?php else: ?>
          <video src="media_preview.php?id=<?= (int) $m['id'] ?>" preload="metadata" muted></video>
        <?php endif; ?>
      </div>
      <div class="media-body">
        <div class="media-name" title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></div>
        <div class="hint">
          <?= t('trs.from_gallery', ['name' => '<strong>' . e($m['gallery_name']) . '</strong>']) ?><br>
          <?= e(t('trs.deleted_on', ['when' => date('d/m/Y H:i', strtotime($m['deleted_at']))])) ?>
          <?= $m['deleted_by_name'] ? e(t('trs.deleted_by', ['who' => $m['deleted_by_name']])) : '' ?>
        </div>
        <div class="media-foot">
          <span class="hint"><?= human_bytes((int) $m['filesize']) ?></span>
          <span style="display:flex;gap:8px">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="restore_media">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= e(t('common.restore')) ?></button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('<?= e(t('trs.purge_file_confirm')) ?>')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="purge_media">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit"><?= e(t('common.delete')) ?></button>
            </form>
          </span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php cms_footer();