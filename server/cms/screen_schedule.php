<?php
declare(strict_types=1);

// Programmazione di un singolo schermo: fasce con i giorni, comportamento
// fuori fascia e copia su altri schermi.
require_once __DIR__ . '/_cms.php';
require_cap('schedule.manage');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM screens WHERE id = ? AND status <> 'revoked'");
$stmt->execute([$id]);
$screen = $stmt->fetch();
if ($screen === false) {
    flash_set('err', t('sv.not_found'));
    redirect('schedule.php');
}

$errors = [];

// Applica fasce e comportamento fuori fascia a uno schermo
function schedule_apply(int $screenId, array $bands, string $idleMode, ?int $idleMediaId): void
{
    db()->prepare('UPDATE screens SET schedule_json = ?, idle_mode = ?, idle_media_id = ? WHERE id = ?')
        ->execute([
            schedule_encode($bands),
            $idleMode,
            $idleMode === 'image' ? $idleMediaId : null,
            $screenId,
        ]);

    screen_event_add($screenId, 'config', 'schedule');
}

// Immagine di cortesia valida? Altrimenti si ricade sul nero, senza
// lasciare lo schermo in uno stato incoerente.
function schedule_idle_from_post(PDO $pdo): array
{
    $mode = ($_POST['idle_mode'] ?? 'black') === 'image' ? 'image' : 'black';
    $media = (int) ($_POST['idle_media_id'] ?? 0);
    if ($mode === 'image') {
        $check = $pdo->prepare("SELECT id FROM media WHERE id = ? AND type = 'image' AND deleted_at IS NULL");
        $check->execute([$media]);
        if ($check->fetchColumn() === false) {
            return ['black', null];
        }
    }
    return [$mode, $mode === 'image' ? $media : null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'save') {
        $decoded = json_decode((string) ($_POST['schedule_json'] ?? '[]'), true);
        $bands = [];
        $invalid = !is_array($decoded);

        if (is_array($decoded)) {
            foreach ($decoded as $band) {
                $clean = schedule_clean_band(is_array($band) ? $band : []);
                if ($clean === null) {
                    $invalid = true;
                } else {
                    $bands[] = $clean;
                }
            }
        }

        if ($invalid) {
            flash_set('err', t('sv.err_bands'));
            redirect('screen_schedule.php?id=' . $id);
        }

        [$idleMode, $idleMediaId] = schedule_idle_from_post($pdo);
        schedule_apply($id, $bands, $idleMode, $idleMediaId);
        flash_set('ok', $bands ? t('sch.saved') : t('sch.cleared'));
        redirect('screen_schedule.php?id=' . $id);
    } elseif ($do === 'copy') {
        $targets = array_values(array_filter(
            array_map('intval', (array) ($_POST['targets'] ?? [])),
            static fn (int $t): bool => $t > 0 && $t !== $id
        ));
        if (!$targets) {
            $errors[] = t('sch.copy_none');
        } else {
            $bands = schedule_parse($screen['schedule_json']);
            $idleMediaId = $screen['idle_media_id'] !== null ? (int) $screen['idle_media_id'] : null;
            foreach ($targets as $target) {
                schedule_apply($target, $bands, (string) $screen['idle_mode'], $idleMediaId);
            }
            flash_set('ok', count($targets) === 1
                ? t('sch.copy_done_one')
                : t('sch.copy_done', ['n' => count($targets)]));
            redirect('screen_schedule.php?id=' . $id);
        }
    }
}

$bands = schedule_parse($screen['schedule_json']);
$giorni = schedule_day_labels();
$giorniBrevi = schedule_day_labels(true);
$presets = schedule_presets();

$imageMedia = $pdo->query("SELECT m.id, m.original_name, g.name AS gallery_name
                           FROM media m JOIN galleries g ON g.id = m.gallery_id
                           WHERE m.type = 'image' AND m.deleted_at IS NULL AND g.deleted_at IS NULL
                           ORDER BY g.name, m.original_name")->fetchAll();

$altri = $pdo->prepare("SELECT id, name FROM screens WHERE id <> ? AND status <> 'revoked' ORDER BY name, id");
$altri->execute([$id]);
$altri = $altri->fetchAll();

cms_header(t('sch.title') . ' — ' . $screen['name'], 'schedule');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('sch.title')) ?></h1>
    <div class="sub"><?= t('sch.editor_for', ['name' => '<strong>' . e($screen['name']) . '</strong>']) ?></div>
  </div>
  <a class="btn" href="schedule.php"><?= e(t('sch.back')) ?></a>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="schedule-form">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">
  <input type="hidden" name="schedule_json" id="schedule-json">

  <div class="card">
    <h2><?= e(t('sch.presets')) ?></h2>
    <p class="hint"><?= e(t('sch.presets_hint')) ?></p>
    <div class="preset-row">
      <?php foreach ($presets as $key => $preset): ?>
      <button type="button" class="btn btn-sm" data-preset="<?= e($key) ?>"><?= e($preset['label']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('sch.bands')) ?></h2>
    <p class="hint"><?= e(t('sch.bands_hint')) ?></p>

    <div id="bands"
         data-bands="<?= e(json_encode($bands)) ?>"
         data-presets="<?= e(json_encode(array_map(fn (array $p): array => $p['bands'], $presets))) ?>"
         data-days="<?= e(json_encode($giorniBrevi)) ?>"
         data-day-names="<?= e(json_encode($giorni)) ?>"
         data-labels="<?= e(json_encode([
             'from'     => t('sch.from'),
             'to'       => t('sch.to'),
             'days'     => t('sch.days'),
             'remove'   => t('sch.remove_band'),
             'midnight' => t('sch.crosses_midnight'),
             'errDays'  => t('sch.err_days'),
             'none'     => t('sch.no_bands'),
         ])) ?>"></div>

    <button type="button" class="btn btn-sm" id="band-add" style="margin-top:16px"><?= e(t('sch.add_band')) ?></button>
  </div>

  <div class="card">
    <h2><?= e(t('sch.preview')) ?></h2>
    <p class="hint"><?= e(t('sch.preview_hint')) ?></p>
    <div class="week-grid" id="week-grid"
         data-hours-label="<?= e(t('sch.weekly_hours', ['n' => ':n'])) ?>">
      <div class="week-grid-head">
        <span></span>
        <?php foreach (range(0, 23) as $h): ?>
        <span class="<?= $h % 6 === 0 ? 'mark' : '' ?>"><?= $h % 6 === 0 ? $h : '' ?></span>
        <?php endforeach; ?>
      </div>
      <?php foreach (SCHEDULE_DAYS as $d): ?>
      <div class="week-grid-row" data-day="<?= $d ?>">
        <span class="week-grid-day"><?= e($giorniBrevi[$d]) ?></span>
        <?php foreach (range(0, 23) as $h): ?>
        <span class="cell" data-hour="<?= $h ?>" title="<?= e($giorni[$d]) ?> <?= sprintf('%02d:00', $h) ?>"></span>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="hint" id="week-total" style="margin:14px 0 0"></p>
  </div>

  <div class="card">
    <h2><?= e(t('sch.idle')) ?></h2>
    <p class="hint"><?= e(t('sch.idle_hint')) ?></p>
    <div class="form-row">
      <div class="field">
        <label for="idle_mode"><?= e(t('sv.idle_mode')) ?></label>
        <select id="idle_mode" name="idle_mode">
          <option value="black"<?= $screen['idle_mode'] === 'black' ? ' selected' : '' ?>><?= e(t('sv.idle_black')) ?></option>
          <option value="image"<?= $screen['idle_mode'] === 'image' ? ' selected' : '' ?>><?= e(t('sv.idle_image')) ?></option>
        </select>
      </div>
      <div class="field" id="idle-media-field">
        <label for="idle_media_id"><?= e(t('sv.idle_image')) ?></label>
        <select id="idle_media_id" name="idle_media_id">
          <option value=""><?= e(t('sv.choose')) ?></option>
          <?php foreach ($imageMedia as $m): ?>
          <option value="<?= (int) $m['id'] ?>"<?= (int) $screen['idle_media_id'] === (int) $m['id'] ? ' selected' : '' ?>>
            <?= e($m['gallery_name'] . ' — ' . $m['original_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="idle-upload-row">
          <img id="idle-preview" alt="<?= e(t('sv.idle_preview_alt')) ?>"
               src="<?= $screen['idle_media_id'] !== null ? 'media_preview.php?id=' . (int) $screen['idle_media_id'] : '' ?>"
               <?= $screen['idle_media_id'] === null ? 'style="display:none"' : '' ?>>
          <button type="button" class="btn btn-sm" id="idle-upload-btn"
                  data-screen="<?= (int) $screen['id'] ?>"><?= e(t('sv.idle_upload')) ?></button>
          <input type="file" id="idle-upload-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
        </div>
        <p class="hint" style="margin:8px 0 0"><?= e(t('sv.idle_upload_hint')) ?></p>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= e(t('sch.save')) ?></button>
  </div>
</form>

<div class="card">
  <h2><?= e(t('sch.copy')) ?></h2>
  <p class="hint"><?= e(t('sch.copy_hint')) ?></p>
  <?php if (!$altri): ?>
  <p class="hint" style="margin:0"><?= e(t('sch.copy_no_targets')) ?></p>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="copy">
    <div class="field">
      <label><?= e(t('sch.copy_select')) ?></label>
      <div class="caps-grid">
        <?php foreach ($altri as $a): ?>
        <label class="check-inline">
          <input type="checkbox" name="targets[]" value="<?= (int) $a['id'] ?>" data-screen-check>
          <?= e($a['name']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-row" style="margin-top:12px">
      <button type="button" class="btn btn-sm" data-select-all><?= e(t('lv.select_all')) ?></button>
      <button type="button" class="btn btn-sm" data-select-none><?= e(t('lv.select_none')) ?></button>
    </div>
    <div class="form-actions">
      <button class="btn" type="submit"><?= e(t('sch.copy_btn')) ?></button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php cms_footer();
