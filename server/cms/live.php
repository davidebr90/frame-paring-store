<?php
declare(strict_types=1);

// Dirette in sovrapposizione: avvio e interruzione. Il contenuto si
// sovrappone alla riproduzione normale senza modificare gallerie, fasce
// orarie o impostazioni: alla fine ogni schermo riprende da solo.
require_once __DIR__ . '/_cms.php';
require_cap('screen.manage');

$pdo = db();
close_expired_overrides();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'stop') {
        stop_override((int) ($_POST['id'] ?? 0));
        flash_set('ok', t('lv.stopped'));
        redirect('live.php');
    }

    if ($do === 'start') {
        $name       = trim((string) ($_POST['name'] ?? ''));
        $sourceType = (string) ($_POST['source_type'] ?? 'media');
        $mediaId    = (int) ($_POST['media_id'] ?? 0);
        $sourceUrl  = trim((string) ($_POST['source_url'] ?? ''));
        $fit        = ($_POST['fit'] ?? 'contain') === 'cover' ? 'cover' : 'contain';
        $rotation   = (int) ($_POST['rotation'] ?? 0);
        $muted      = isset($_POST['muted']) ? 1 : 0;
        $loop       = isset($_POST['loop_content']) ? 1 : 0;
        $duration   = (int) ($_POST['duration_min'] ?? 0);
        $screenIds  = array_map('intval', (array) ($_POST['screens'] ?? []));
        $galleryId  = (int) ($_POST['gallery_id'] ?? 0);
        // Scostamenti per schermo: assenti o vuoti = come la diretta
        $perScreen  = (array) ($_POST['screen_fit'] ?? []);
        $perRot     = (array) ($_POST['screen_rotation'] ?? []);

        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = t('lv.err_name');
        }
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            $errors[] = t('lv.err_rotation');
        }
        if ($duration < 0 || $duration > 1440) {
            $errors[] = t('lv.err_duration');
        }
        if (!$screenIds) {
            $errors[] = t('lv.err_screens');
        }

        if ($sourceType === 'media') {
            $check = $pdo->prepare('SELECT id FROM media WHERE id = ? AND deleted_at IS NULL');
            $check->execute([$mediaId]);
            if ($check->fetchColumn() === false) {
                $errors[] = t('lv.err_media');
            }
            $sourceUrl = null;
            $galleryId = null;
        } elseif ($sourceType === 'gallery') {
            $check = $pdo->prepare('SELECT id FROM galleries WHERE id = ? AND deleted_at IS NULL');
            $check->execute([$galleryId]);
            if ($check->fetchColumn() === false) {
                $errors[] = t('lv.err_gallery');
            }
            $mediaId = null;
            $sourceUrl = null;
        } elseif ($sourceType === 'external') {
            require_feature('live.external');
            if (!preg_match('#^(https?|rtsp)://#i', $sourceUrl) || mb_strlen($sourceUrl) > 500) {
                $errors[] = t('lv.err_url');
            }
            $mediaId = null;
            $galleryId = null;
        } else {
            $errors[] = t('lv.err_type');
        }

        // Gli schermi devono esistere ed essere associati
        if (!$errors) {
            $in = implode(',', array_fill(0, count($screenIds), '?'));
            $stmt = $pdo->prepare("SELECT id FROM screens WHERE id IN ($in) AND status = 'paired'");
            $stmt->execute($screenIds);
            $screenIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (!$screenIds) {
                $errors[] = t('lv.err_unpaired');
            }
        }

        // Ogni schermo può scostarsi: un valore fuori elenco torna al
        // predefinito della diretta invece di far fallire l'avvio
        $screensMap = [];
        foreach ($screenIds as $sid) {
            $f = (string) ($perScreen[$sid] ?? '');
            $r = (string) ($perRot[$sid] ?? '');
            $screensMap[$sid] = [
                'fit'      => in_array($f, ['contain', 'cover'], true) ? $f : null,
                'rotation' => in_array($r, ['0', '90', '180', '270'], true) ? (int) $r : null,
            ];
        }

        if (!$errors) {
            $id = start_override([
                'name'         => $name,
                'source_type'  => $sourceType,
                'media_id'     => $mediaId,
                'gallery_id'   => $galleryId ?: null,
                'source_url'   => $sourceUrl,
                'fit'          => $fit,
                'rotation'     => $rotation,
                'muted'        => $muted,
                'loop_content' => $loop,
                'ends_at'      => $duration > 0
                    ? date('Y-m-d H:i:s', time() + $duration * 60)
                    : null,
            ], $screensMap, (int) current_user()['id']);

            flash_set('ok', t('lv.started', ['n' => count($screenIds)]));
            redirect('live.php');
        }
    }
}

// Dirette attive
$active = $pdo->query('SELECT o.*, u.username AS started_by,
                              GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ", ") AS screen_names,
                              COUNT(los.screen_id) AS n_screens,
                              m.original_name AS media_name,
                              g.name AS gallery_name,
                              (SELECT COUNT(*) FROM media mm
                               WHERE mm.gallery_id = o.gallery_id AND mm.deleted_at IS NULL) AS gallery_files
                       FROM live_overrides o
                       JOIN live_override_screens los ON los.override_id = o.id
                       JOIN screens s ON s.id = los.screen_id
                       LEFT JOIN users u ON u.id = o.created_by
                       LEFT JOIN media m ON m.id = o.media_id
                       LEFT JOIN galleries g ON g.id = o.gallery_id
                       WHERE o.stopped_at IS NULL
                         AND (o.ends_at IS NULL OR o.ends_at > NOW())
                       GROUP BY o.id
                       ORDER BY o.started_at DESC')->fetchAll();

$screens = $pdo->query("SELECT id, name, orientation, diagonal_inches, last_seen_at
                        FROM screens WHERE status = 'paired' ORDER BY name")->fetchAll();

$media = $pdo->query("SELECT m.id, m.original_name, m.type, m.fit, m.rotation, g.name AS gallery_name
                      FROM media m JOIN galleries g ON g.id = m.gallery_id
                      WHERE m.deleted_at IS NULL AND g.deleted_at IS NULL
                      ORDER BY g.name, m.original_name")->fetchAll();

$galleries = live_galleries();
$externalAvailable = feature_available('live.external');

// Dati minimi per l'anteprima: di ogni file serve il tipo, di ogni schermo
// l'orientamento. Il resto lo calcola il browser.
$previewMedia = [];
foreach ($media as $m) {
    $previewMedia[(int) $m['id']] = [
        'type'     => $m['type'],
        'name'     => $m['original_name'],
        'fit'      => $m['fit'],
        'rotation' => (int) $m['rotation'],
    ];
}
// Contenuto di ogni galleria: serve all'anteprima per farla scorrere
$previewGalleries = [];
foreach ($galleries as $g) {
    $st = $pdo->prepare('SELECT id FROM media WHERE gallery_id = ? AND deleted_at IS NULL
                         ORDER BY sort_order, id');
    $st->execute([$g['id']]);
    $previewGalleries[(int) $g['id']] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$previewScreens = [];
foreach ($screens as $s) {
    $previewScreens[(int) $s['id']] = [
        'name'        => $s['name'],
        'orientation' => $s['orientation'],
        'inches'      => $s['diagonal_inches'] !== null ? (int) $s['diagonal_inches'] : null,
    ];
}

cms_header(t('lv.title'), 'live');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('lv.title')) ?></h1>
    <div class="sub"><?= e(t('lv.subtitle')) ?></div>
  </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-err">
  <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($active): ?>
<div class="card live-active">
  <h2><span class="live-dot"></span><?= e(t('lv.on_air')) ?></h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th><?= e(t('lv.th_live')) ?></th><th><?= e(t('lv.th_content')) ?></th><th><?= e(t('lv.th_screens')) ?></th><th><?= e(t('lv.th_started')) ?></th><th><?= e(t('lv.th_ends')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($active as $o): ?>
        <tr>
          <td><strong><?= e($o['name']) ?></strong>
            <?php if ($o['started_by']): ?><div class="hint"><?= e(t('lv.started_by', ['who' => $o['started_by']])) ?></div><?php endif; ?>
          </td>
          <td class="hint">
            <?php if ($o['source_type'] === 'media'): ?>
              <?= e((string) $o['media_name']) ?>
            <?php elseif ($o['source_type'] === 'gallery'): ?>
              <?= e((string) $o['gallery_name']) ?>
              <span class="hint">· <?= e(t('lv.gallery_files', ['n' => (int) $o['gallery_files']])) ?></span>
            <?php else: ?>
              <?= e(mb_strimwidth((string) $o['source_url'], 0, 48, '…')) ?>
            <?php endif; ?>
          </td>
          <td><?= (int) $o['n_screens'] ?>
            <div class="hint"><?= e(mb_strimwidth((string) $o['screen_names'], 0, 60, '…')) ?></div>
          </td>
          <td class="hint"><?= date('H:i:s', strtotime($o['started_at'])) ?></td>
          <td class="hint">
            <?= $o['ends_at'] !== null
                ? date('H:i', strtotime($o['ends_at']))
                : e(t('lv.until_manual')) ?>
          </td>
          <td style="text-align:right">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="stop">
              <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit"><?= e(t('lv.stop')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint" style="margin:14px 0 0"><?= e(t('lv.on_stop_hint')) ?></p>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="start">

  <div class="card">
    <h2><?= e(t('lv.start_card')) ?></h2>
    <p class="hint"><?= e(t('lv.start_hint')) ?></p>

    <div class="field-grid">
      <div class="field">
        <label for="name"><?= e(t('lv.name')) ?> <span class="req">*</span></label>
        <input type="text" id="name" name="name" maxlength="100" required
               placeholder="<?= e(t('lv.name_ph')) ?>">
      </div>
      <div class="field">
        <label for="duration_min"><?= e(t('lv.duration')) ?></label>
        <input type="number" id="duration_min" name="duration_min" min="0" max="1440" value="0">
        <p class="hint" style="margin:6px 0 0"><?= e(t('lv.duration_hint')) ?></p>
      </div>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('lv.source')) ?></h2>
    <div class="source-tabs">
      <label class="source-tab">
        <input type="radio" name="source_type" value="media" checked data-source>
        <span><?= e(t('lv.source_media')) ?></span>
      </label>
      <label class="source-tab">
        <input type="radio" name="source_type" value="gallery" data-source>
        <span><?= e(t('lv.source_gallery')) ?></span>
      </label>
      <label class="source-tab<?= $externalAvailable ? '' : ' is-locked' ?>">
        <input type="radio" name="source_type" value="external" data-source<?= $externalAvailable ? '' : ' disabled' ?>>
        <span><?= e(t('lv.source_external')) ?></span>
      </label>
    </div>

    <div data-source-panel="media">
      <div class="field-grid" style="margin-top:18px">
        <div class="field">
          <label for="media_id">File</label>
          <select id="media_id" name="media_id">
            <option value="">Scegli…</option>
            <?php foreach ($media as $m): ?>
            <option value="<?= (int) $m['id'] ?>">
              <?= e($m['gallery_name'] . ' — ' . $m['original_name']) ?>
              (<?= $m['type'] === 'video' ? 'video' : 'immagine' ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div data-source-panel="gallery" hidden>
      <div class="field-grid" style="margin-top:18px">
        <div class="field">
          <label for="gallery_id"><?= e(t('lv.gallery')) ?></label>
          <select id="gallery_id" name="gallery_id">
            <option value=""><?= e(t('lv.choose')) ?></option>
            <?php foreach ($galleries as $g): ?>
            <option value="<?= (int) $g['id'] ?>">
              <?= e($g['name']) ?> (<?= e(t('lv.gallery_files', ['n' => (int) $g['n_media']])) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <p class="hint" style="margin:6px 0 0"><?= e(t('lv.gallery_hint')) ?></p>
        </div>
      </div>
    </div>

    <div data-source-panel="external" hidden>
      <div class="field-grid" style="margin-top:18px">
        <div class="field">
          <label for="source_url"><?= e(t('lv.stream_url')) ?></label>
          <input type="text" id="source_url" name="source_url" maxlength="500"
                 placeholder="<?= e(t('lv.stream_url_ph')) ?>">
          <p class="hint" style="margin:6px 0 0"><?= e(t('lv.stream_hint')) ?></p>
        </div>
      </div>
    </div>

    <div class="field-grid" style="margin-top:22px" data-not-gallery>
      <div class="field" style="grid-column:1/-1">
        <label style="margin:0"><?= e(t('lv.defaults')) ?></label>
        <p class="hint" style="margin:4px 0 0"><?= e(t('lv.defaults_hint')) ?></p>
      </div>
      <div class="field">
        <label for="fit"><?= e(t('lv.fit')) ?></label>
        <select id="fit" name="fit">
          <option value="contain"><?= e(t('lv.fit_contain')) ?></option>
          <option value="cover"><?= e(t('lv.fit_cover')) ?></option>
        </select>
      </div>
      <div class="field">
        <label for="rotation"><?= e(t('lv.rotation')) ?></label>
        <select id="rotation" name="rotation">
          <?php foreach ([0, 90, 180, 270] as $deg): ?>
          <option value="<?= $deg ?>"><?= $deg ?>°</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field" style="margin-top:22px">
      <label><?= e(t('lv.playback_options')) ?></label>
      <div class="switch-group switch-group-inline">
        <label class="switch">
          <input type="checkbox" name="muted" value="1" data-switch>
          <span class="switch-track"></span>
          <span class="switch-label">
            <span><?= e(t('lv.muted')) ?></span>
            <span class="switch-state" data-switch-state
                  data-on="<?= e(t('lv.muted_on')) ?>" data-off="<?= e(t('lv.muted_off')) ?>"><?= e(t('lv.muted_off')) ?></span>
          </span>
        </label>
        <label class="switch">
          <input type="checkbox" name="loop_content" value="1" checked data-switch>
          <span class="switch-track"></span>
          <span class="switch-label">
            <span><?= e(t('lv.loop')) ?></span>
            <span class="switch-state" data-switch-state
                  data-on="<?= e(t('lv.loop_on')) ?>" data-off="<?= e(t('lv.loop_off')) ?>"><?= e(t('lv.loop_on')) ?></span>
          </span>
        </label>
      </div>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('lv.preview')) ?></h2>
    <p class="hint"><?= e(t('lv.preview_hint')) ?></p>
    <div class="lv-preview" data-live-preview
         data-media='<?= e(json_encode($previewMedia, JSON_UNESCAPED_UNICODE)) ?>'
         data-screens='<?= e(json_encode($previewScreens, JSON_UNESCAPED_UNICODE)) ?>'
         data-galleries='<?= e(json_encode($previewGalleries)) ?>'
         data-labels='<?= e(json_encode([
             'landscape'  => t('lv.preview_landscape'),
             'portrait'   => t('lv.preview_portrait'),
             'screens'    => t('lv.preview_screens_n', ['n' => '{n}']),
             'screens_p'  => t('lv.preview_screens_n_p', ['n' => '{n}']),
             'no_content' => t('lv.preview_no_content'),
             'no_screens' => t('lv.preview_no_screens'),
             'external'   => t('lv.preview_external'),
             'mixed'      => t('lv.preview_mixed'),
             'audio'      => t('lv.preview_audio'),
             'crop'       => t('lv.preview_crop'),
             'gallery'    => t('lv.preview_gallery'),
             'own'        => t('lv.preview_own'),
         ], JSON_UNESCAPED_UNICODE)) ?>'>
      <div class="lv-frames" data-lv-frames></div>
      <p class="hint lv-preview-note" data-lv-note></p>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('lv.screens')) ?></h2>
    <?php if (!$screens): ?>
    <p class="hint" style="margin:0"><?= e(t('lv.no_screens')) ?></p>
    <?php else: ?>
    <div class="form-row" style="margin-bottom:14px">
      <button type="button" class="btn btn-sm" data-select-all><?= e(t('lv.select_all')) ?></button>
      <button type="button" class="btn btn-sm" data-select-none><?= e(t('lv.select_none')) ?></button>
    </div>
    <div class="caps-grid">
      <?php $onlineWindow = (int) config('poll_interval') * 2 + 30; ?>
      <?php foreach ($screens as $s):
          $online = $s['last_seen_at'] !== null
              && time() - strtotime($s['last_seen_at']) <= $onlineWindow;
      ?>
      <div class="lv-screen">
        <label class="check-inline">
          <input type="checkbox" name="screens[]" value="<?= (int) $s['id'] ?>" data-screen-check>
          <?= e($s['name']) ?>
          <span class="hint">
            <?= $s['diagonal_inches'] ? (int) $s['diagonal_inches'] . '&Prime; ' : '' ?>
            <?= e($s['orientation'] === 'portrait' ? t('screen.portrait') : t('screen.landscape')) ?>
            · <?= e($online ? t('lv.online') : t('lv.offline')) ?>
          </span>
        </label>
        <?php // Scostamenti di questo schermo: compaiono solo quando è scelto ?>
        <div class="lv-screen-opts" data-screen-opts="<?= (int) $s['id'] ?>" hidden>
          <label class="lv-opt">
            <span><?= e(t('lv.fit')) ?></span>
            <select name="screen_fit[<?= (int) $s['id'] ?>]" data-screen-fit="<?= (int) $s['id'] ?>">
              <option value=""><?= e(t('lv.per_screen_same')) ?></option>
              <option value="contain"><?= e(t('lv.fit_contain')) ?></option>
              <option value="cover"><?= e(t('lv.fit_cover')) ?></option>
            </select>
          </label>
          <label class="lv-opt">
            <span><?= e(t('lv.rotation')) ?></span>
            <select name="screen_rotation[<?= (int) $s['id'] ?>]" data-screen-rot="<?= (int) $s['id'] ?>">
              <option value=""><?= e(t('lv.per_screen_same')) ?></option>
              <?php foreach ([0, 90, 180, 270] as $deg): ?>
              <option value="<?= $deg ?>"><?= $deg ?>°</option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="hint" data-not-gallery style="margin:14px 0 0"><?= e(t('lv.per_screen_hint')) ?></p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('lv.start_btn')) ?></button>
    </div>
    <?php endif; ?>
  </div>
</form>
<?php cms_footer();