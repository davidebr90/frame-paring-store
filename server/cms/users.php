<?php
declare(strict_types=1);

// Lista utenti con ricerca e filtri. Creazione e modifica hanno pagine
// dedicate (user_new.php / user_edit.php).
require_once __DIR__ . '/_cms.php';
require_cap('users.manage');

$pdo = db();
$me = current_user();

$q      = trim((string) ($_GET['q'] ?? ''));
$role   = (string) ($_GET['role'] ?? '');
$status = (string) ($_GET['status'] ?? '');

// Vista scelta dall'utente, ricordata nella sessione
if (isset($_GET['view']) && in_array($_GET['view'], ['cards', 'list'], true)) {
    $_SESSION['users_view'] = $_GET['view'];
}
$view = $_SESSION['users_view'] ?? 'cards';

// Query string corrente senza il parametro view (per i link del toggle)
$baseQuery = array_filter(['q' => $q, 'role' => $role, 'status' => $status], fn ($v) => $v !== '');
$linkFor = fn (string $v): string => 'users.php?' . http_build_query($baseQuery + ['view' => $v]);

$where  = [];
$params = [];

if ($q !== '') {
    // Un placeholder per colonna: con i prepared statement nativi lo stesso
    // nome non può comparire più volte nella query
    $where[] = '(username LIKE :q1 OR full_name LIKE :q2 OR email LIKE :q3 OR phone LIKE :q4)';
    $like = '%' . $q . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
    $params['q4'] = $like;
}
if (isset(ROLE_LABELS[$role])) {
    $where[] = 'role = :role';
    $params['role'] = $role;
}
if ($status === 'active' || $status === 'inactive') {
    $where[] = 'is_active = :active';
    $params['active'] = $status === 'active' ? 1 : 0;
}

array_unshift($where, 'deleted_at IS NULL');
$sql = 'SELECT * FROM users WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY FIELD(role,'admin','editor','operator'), COALESCE(full_name, username)";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$total = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();
$byRole = [];
foreach ($pdo->query('SELECT role, COUNT(*) AS n FROM users WHERE deleted_at IS NULL GROUP BY role') as $row) {
    $byRole[$row['role']] = (int) $row['n'];
}

cms_header(t('usr.title'), 'users');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('usr.title')) ?></h1>
    <div class="sub">
      <?= e(t('usr.registered', ['n' => $total])) ?> ·
      <?php foreach (role_label_list() as $key => $label): ?>
        <?= e($label) ?> <?= $byRole[$key] ?? 0 ?><?= $key !== 'operator' ? ' · ' : '' ?>
      <?php endforeach; ?>
    </div>
  </div>
  <a class="btn btn-primary" href="user_new.php"><?= e(t('usr.add')) ?></a>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <div class="field">
      <label for="q"><?= e(t('common.search')) ?></label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>"
             placeholder="<?= e(t('usr.search_ph')) ?>">
    </div>
    <div class="field">
      <label for="role"><?= e(t('usr.role')) ?></label>
      <select id="role" name="role">
        <option value=""><?= e(t('usr.all_roles')) ?></option>
        <?php foreach (role_label_list() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $role === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="status"><?= e(t('usr.status')) ?></label>
      <select id="status" name="status">
        <option value=""><?= e(t('common.all')) ?></option>
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>><?= e(t('usr.status_active')) ?></option>
        <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>><?= e(t('usr.status_inactive')) ?></option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn" type="submit"><?= e(t('usr.filter')) ?></button>
      <?php if ($q !== '' || $role !== '' || $status !== ''): ?>
      <a class="btn btn-sm" href="users.php"><?= e(t('usr.reset')) ?></a>
      <?php endif; ?>
      <div class="view-toggle" role="group" aria-label="<?= e(t('usr.view_aria')) ?>">
        <a href="<?= e($linkFor('cards')) ?>" class="<?= $view === 'cards' ? 'active' : '' ?>" title="<?= e(t('usr.view_cards_title')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          <span><?= e(t('usr.view_cards')) ?></span>
        </a>
        <a href="<?= e($linkFor('list')) ?>" class="<?= $view === 'list' ? 'active' : '' ?>" title="<?= e(t('usr.view_list_title')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          <span><?= e(t('usr.view_list')) ?></span>
        </a>
      </div>
    </div>
  </form>
</div>

<?php if (!$users): ?>
<div class="card"><p class="hint" style="margin:0">Nessun utente corrisponde ai filtri.</p></div>

<?php elseif ($view === 'list'): ?>
<div class="card">
  <div class="table-wrap">
    <table class="user-table">
      <thead>
        <tr>
          <th><?= e(t('usr.th_user')) ?></th><th><?= e(t('usr.role')) ?></th>
          <th><?= e(t('usr.th_email')) ?></th><th><?= e(t('usr.th_phone')) ?></th>
          <th><?= e(t('usr.th_caps')) ?></th><th><?= e(t('usr.status')) ?></th>
          <th><?= e(t('usr.th_last_login')) ?></th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
          $caps = user_capabilities($u);
          $isMe = (int) $u['id'] === (int) $me['id'];
      ?>
        <tr class="role-<?= e($u['role']) ?><?= $u['is_active'] ? '' : ' is-inactive' ?>">
          <td>
            <div class="cell-user">
              <span class="user-avatar sm"><?= e(mb_strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 1))) ?></span>
              <div>
                <strong><?= e($u['full_name'] ?: $u['username']) ?></strong>
                <?php if ($isMe): ?><span class="badge badge-me"><?= e(t('usr.me')) ?></span><?php endif; ?>
                <div class="hint mono">@<?= e($u['username']) ?></div>
              </div>
            </div>
          </td>
          <td><span class="role-chip"><?= e(role_label($u['role'])) ?></span></td>
          <td class="hint"><?= $u['email'] ? e($u['email']) : '—' ?></td>
          <td class="hint"><?= $u['phone'] ? e($u['phone']) : '—' ?></td>
          <td><?= count($caps) ?>/<?= count(CAPABILITIES) ?></td>
          <td>
            <?php if ($u['is_active']): ?>
            <span class="badge badge-online"><span class="led"></span><?= e(t('common.active')) ?></span>
            <?php else: ?>
            <span class="badge badge-revoked"><span class="led"></span><?= e(t('common.inactive')) ?></span>
            <?php endif; ?>
          </td>
          <td class="hint"><?= e($u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : t('common.never')) ?></td>
          <td style="text-align:right"><a class="btn btn-sm" href="user_edit.php?id=<?= (int) $u['id'] ?>"><?= e(t('usr.manage')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<div class="grid user-grid">
  <?php foreach ($users as $u):
      $caps = user_capabilities($u);
      $isMe = (int) $u['id'] === (int) $me['id'];
      $initials = mb_strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 1));
  ?>
  <article class="user-card role-<?= e($u['role']) ?><?= $u['is_active'] ? '' : ' is-inactive' ?>">
    <header class="user-card-head">
      <span class="user-avatar"><?= e($initials) ?></span>
      <div class="user-ident">
        <h2 class="user-name">
          <?= e($u['full_name'] ?: $u['username']) ?>
          <?php if ($isMe): ?><span class="badge badge-me"><?= e(t('usr.me')) ?></span><?php endif; ?>
        </h2>
        <div class="user-username mono">@<?= e($u['username']) ?></div>
      </div>
      <span class="role-chip"><?= e(role_label($u['role'])) ?></span>
    </header>

    <dl class="user-meta">
      <?php if ($u['email']): ?>
      <dt><?= e(t('usr.th_email')) ?></dt><dd><?= e($u['email']) ?></dd>
      <?php endif; ?>
      <?php if ($u['phone']): ?>
      <dt><?= e(t('usr.th_phone')) ?></dt><dd><?= e($u['phone']) ?></dd>
      <?php endif; ?>
      <dt><?= e(t('usr.th_caps')) ?></dt><dd><?= e(t('usr.caps_count', ['n' => count($caps), 'total' => count(CAPABILITIES)])) ?></dd>
      <dt><?= e(t('usr.th_last_login')) ?></dt>
      <dd><?= e($u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : t('common.never')) ?></dd>
    </dl>

    <footer class="user-card-foot">
      <?php if ($u['is_active']): ?>
      <span class="badge badge-online"><span class="led"></span><?= e(t('common.active')) ?></span>
      <?php else: ?>
      <span class="badge badge-revoked"><span class="led"></span><?= e(t('common.inactive')) ?></span>
      <?php endif; ?>
      <a class="btn btn-sm" href="user_edit.php?id=<?= (int) $u['id'] ?>"><?= e(t('usr.manage')) ?></a>
    </footer>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php cms_footer();