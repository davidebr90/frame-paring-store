<?php
declare(strict_types=1);

// Panoramica della programmazione: tutti gli schermi, il loro stato in
// questo momento e un colpo d'occhio sulla settimana.
require_once __DIR__ . '/_cms.php';
require_cap('schedule.manage');

$pdo = db();
$screens = $pdo->query("SELECT * FROM screens WHERE status <> 'revoked' ORDER BY name, id")->fetchAll();
$giorni = schedule_day_labels(true);

cms_header(t('sch.title'), 'schedule');
?>
<div class="main-head">
  <div>
    <h1><?= e(t('sch.title')) ?></h1>
    <div class="sub"><?= e(t('sch.subtitle')) ?></div>
  </div>
</div>

<p class="hint"><?= e(t('sch.overview_hint')) ?></p>

<div class="card">
  <?php if (!$screens): ?>
  <p class="hint" style="margin:0"><?= e(t('sch.no_screens')) ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="schedule-table">
      <thead>
        <tr>
          <th><?= e(t('sch.th_screen')) ?></th>
          <th><?= e(t('sch.th_now')) ?></th>
          <th><?= e(t('sch.th_schedule')) ?></th>
          <th><?= e(t('sch.th_week')) ?></th>
          <th><?= e(t('sch.th_hours')) ?></th>
          <th><?= e(t('sch.th_idle')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($screens as $s):
            $bands = schedule_parse($s['schedule_json']);
            $acceso = schedule_active_now($bands);
            $grid = schedule_grid($bands);
        ?>
        <tr>
          <td><strong><?= e($s['name']) ?></strong></td>
          <td>
            <span class="badge <?= $acceso ? 'badge-online' : 'badge-offline' ?>">
              <span class="led"></span><?= e($acceso ? t('sch.on_now') : t('sch.off_now')) ?>
            </span>
          </td>
          <td><?= schedule_badge($bands) ?></td>
          <td>
            <?php // Striscia compatta: sette righe da ventiquattro ore ?>
            <div class="week-strip" tabindex="0" role="img"
                 data-tip="<?= e(t('sch.week_tip')) ?> — <?= e(schedule_summary($bands)) ?>"
                 aria-label="<?= e(schedule_summary($bands)) ?>">
              <?php foreach (SCHEDULE_DAYS as $d): ?>
              <div class="week-strip-row" title="<?= e($giorni[$d]) ?>">
                <?php foreach (range(0, 23) as $h): ?>
                <span class="<?= $grid[$d][$h] ? 'on' : '' ?>"></span>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </td>
          <td class="hint"><?= e(t('sch.weekly_hours', ['n' => schedule_weekly_hours($bands)])) ?></td>
          <td class="hint">
            <?= e($s['idle_mode'] === 'image' ? t('sv.idle_image') : t('sv.idle_black')) ?>
          </td>
          <td style="text-align:right">
            <a class="btn btn-sm" href="screen_schedule.php?id=<?= (int) $s['id'] ?>"><?= e(t('sch.configure')) ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php cms_footer();
