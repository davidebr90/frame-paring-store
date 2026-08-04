<?php
declare(strict_types=1);

// Lista utenti con ricerca e filtri. Creazione e modifica hanno pagine
// dedicate (user_new.php / user_edit.php).
require __DIR__ . '/_cms.php';
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

$sql = 'SELECT * FROM users';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY FIELD(role,'admin','editor','operator'), COALESCE(full_name, username)";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$byRole = [];
foreach ($pdo->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $row) {
    $byRole[$row['role']] = (int) $row['n'];
}

cms_header('Utenti', 'users');
?>
<div class="main-head">
  <div>
    <h1>Utenti</h1>
    <div class="sub">
      <?= $total ?> registrati ·
      <?php foreach (ROLE_LABELS as $key => $label): ?>
        <?= e($label) ?> <?= $byRole[$key] ?? 0 ?><?= $key !== 'operator' ? ' · ' : '' ?>
      <?php endforeach; ?>
    </div>
  </div>
  <a class="btn btn-primary" href="user_new.php">+ Crea utente</a>
</div>

<div class="card">
  <form method="get" class="filter-bar">
    <div class="field">
      <label for="q">Cerca</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>"
             placeholder="Nome, cognome, username, email o telefono">
    </div>
    <div class="field">
      <label for="role">Ruolo</label>
      <select id="role" name="role">
        <option value="">Tutti i ruoli</option>
        <?php foreach (ROLE_LABELS as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $role === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="status">Stato</label>
      <select id="status" name="status">
        <option value="">Tutti</option>
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Attivi</option>
        <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Disattivati</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn" type="submit">Filtra</button>
      <?php if ($q !== '' || $role !== '' || $status !== ''): ?>
      <a class="btn btn-sm" href="users.php">Azzera</a>
      <?php endif; ?>
      <div class="view-toggle" role="group" aria-label="Modalità di visualizzazione">
        <a href="<?= e($linkFor('cards')) ?>" class="<?= $view === 'cards' ? 'active' : '' ?>" title="Vista a card">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          <span>Card</span>
        </a>
        <a href="<?= e($linkFor('list')) ?>" class="<?= $view === 'list' ? 'active' : '' ?>" title="Vista a elenco">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          <span>Elenco</span>
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
          <th>Utente</th><th>Ruolo</th><th>Email</th><th>Telefono</th>
          <th>Permessi</th><th>Stato</th><th>Ultimo accesso</th><th></th>
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
                <?php if ($isMe): ?><span class="badge badge-me">tu</span><?php endif; ?>
                <div class="hint mono">@<?= e($u['username']) ?></div>
              </div>
            </div>
          </td>
          <td><span class="role-chip"><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?></span></td>
          <td class="hint"><?= $u['email'] ? e($u['email']) : '—' ?></td>
          <td class="hint"><?= $u['phone'] ? e($u['phone']) : '—' ?></td>
          <td><?= count($caps) ?>/<?= count(CAPABILITIES) ?></td>
          <td>
            <?php if ($u['is_active']): ?>
            <span class="badge badge-online"><span class="led"></span>Attivo</span>
            <?php else: ?>
            <span class="badge badge-revoked"><span class="led"></span>Disattivato</span>
            <?php endif; ?>
          </td>
          <td class="hint"><?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'mai' ?></td>
          <td style="text-align:right"><a class="btn btn-sm" href="user_edit.php?id=<?= (int) $u['id'] ?>">Gestisci</a></td>
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
          <?php if ($isMe): ?><span class="badge badge-me">tu</span><?php endif; ?>
        </h2>
        <div class="user-username mono">@<?= e($u['username']) ?></div>
      </div>
      <span class="role-chip"><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?></span>
    </header>

    <dl class="user-meta">
      <?php if ($u['email']): ?>
      <dt>Email</dt><dd><?= e($u['email']) ?></dd>
      <?php endif; ?>
      <?php if ($u['phone']): ?>
      <dt>Telefono</dt><dd><?= e($u['phone']) ?></dd>
      <?php endif; ?>
      <dt>Permessi</dt><dd><?= count($caps) ?> di <?= count(CAPABILITIES) ?></dd>
      <dt>Ultimo accesso</dt>
      <dd><?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'mai' ?></dd>
    </dl>

    <footer class="user-card-foot">
      <?php if ($u['is_active']): ?>
      <span class="badge badge-online"><span class="led"></span>Attivo</span>
      <?php else: ?>
      <span class="badge badge-revoked"><span class="led"></span>Disattivato</span>
      <?php endif; ?>
      <a class="btn btn-sm" href="user_edit.php?id=<?= (int) $u['id'] ?>">Gestisci</a>
    </footer>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php cms_footer();