<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_duty_init($pdo);

// Buildings (optional filter)
$buildings = tt_buildings_list($pdo, true);
$building_id_param = $_GET['building_id'] ?? null;
$building_id = $building_id_param === null ? 0 : (int)$building_id_param;

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$activeYearId = 0;
foreach ($years as $y) { if (!empty($y['is_active'])) { $activeYearId = (int)$y['id']; break; } }
if (!$activeYearId && !empty($years)) $activeYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $activeYearId);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);

// Ensure master slots exist (templates depend on this)
tt_duty_master_sync_from_periods($pdo);

// Summary per teacher (only for selected year/term)
$sumSql = 'SELECT t.id, t.teacher_code, t.first_name, t.last_name, COALESCE(x.duty_cnt,0) AS duty_cnt
  FROM teachers t
  LEFT JOIN (
    SELECT ta.teacher_id, COUNT(*) AS duty_cnt
    FROM duty_term_assignments ta
    JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
    JOIN duty_master_posts mp ON mp.id=ms.duty_post_id
    WHERE ta.academic_year_id=? AND ta.term_no=?';
$sumParams = [$year_id, $term_no];
if ($building_id > 0) {
  $sumSql .= ' AND mp.building_id=?';
  $sumParams[] = $building_id;
}
$sumSql .= '
    GROUP BY ta.teacher_id
  ) x ON x.teacher_id=t.id
  WHERE NOT EXISTS (
    SELECT 1 FROM duty_term_exclusions e
    WHERE e.academic_year_id=? AND e.term_no=? AND e.teacher_id=t.id
  )
  ORDER BY duty_cnt DESC, t.teacher_code, t.first_name, t.last_name';
$sumParams[] = $year_id;
$sumParams[] = $term_no;
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($sumParams);
$rows = $sumStmt->fetchAll();

$detailSql = 'SELECT ta.teacher_id, ms.day_of_week, mts.sort_order AS slot_sort, mts.start_time, mts.end_time,
    mp.post_name, mp.building_id,
    b.building_name
  FROM duty_term_assignments ta
  JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
  JOIN duty_master_time_slots mts ON mts.id=ms.duty_time_slot_id
  JOIN duty_master_posts mp ON mp.id=ms.duty_post_id
  LEFT JOIN duty_buildings b ON b.id=mp.building_id
  WHERE ta.academic_year_id=? AND ta.term_no=?';
$detailParams = [$year_id, $term_no];
if ($building_id > 0) {
  $detailSql .= ' AND mp.building_id=?';
  $detailParams[] = $building_id;
}
$detailSql .= '
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )
  ORDER BY ta.teacher_id, COALESCE(b.building_name,\'\'), ms.day_of_week, mts.sort_order, mp.post_name';
$detailStmt = $pdo->prepare($detailSql);
$detailStmt->execute($detailParams);

$details = [];
while ($r = $detailStmt->fetch(PDO::FETCH_ASSOC)) {
  $tid = (int)$r['teacher_id'];
  $bkey = (string)($r['building_id'] ?? '');
  if ($bkey === '' || $bkey === '0') $bkey = 'none';
  if (!isset($details[$tid])) $details[$tid] = [];
  if (!isset($details[$tid][$bkey])) {
    $details[$tid][$bkey] = [
      'building_name' => $r['building_name'] ?? null,
      'items' => []
    ];
  }
  $details[$tid][$bkey]['items'][] = $r;
}

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8">
  <div class="mb-3">
    <h1 class="text-xl font-semibold">📌 สรุปเวร (รายเทอม)</h1>
  </div>

  <?php
    $ttDutyActive = 'summary';
    $ttDutyYearId = $year_id;
    $ttDutyTermNo = $term_no;
    include __DIR__ . '/../partials/duty_tabs.php';
  ?>

  <form method="get" class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
    <div class="md:col-span-4">
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).(!empty($y['is_active'])?' (ใช้งาน)':'') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-2">
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
        <?php foreach (tt_terms_list($pdo, $year_id) as $t): ?>
          <option value="<?= (int)$t['term_no'] ?>" <?= (int)$t['term_no']===$term_no?'selected':''; ?>><?= htmlspecialchars($t['term_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-3">
      <label class="block text-xs mb-1">อาคาร</label>
      <select name="building_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()" <?= empty($buildings) ? 'disabled' : '' ?>>
        <option value="0" <?= $building_id===0?'selected':''; ?>>ทุกอาคาร</option>
        <?php foreach ($buildings as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id']===$building_id?'selected':''; ?>><?= htmlspecialchars((string)$b['building_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-3 flex items-end">
      <div class="w-full flex items-end justify-between gap-2">
        <div class="text-xs text-slate-500">ดูว่าใครรับเวรกี่ครั้ง และอยู่จุดไหนบ้าง</div>
        <a class="px-3 py-2 rounded-xl border bg-white hover:bg-slate-50" target="_blank" rel="noopener" href="<?= url('duty_summary_report.php?year_id='.$year_id.'&term_no='.$term_no.($building_id>0 ? ('&building_id='.$building_id) : '')) ?>">รายงาน</a>
      </div>
    </div>
  </form>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b font-medium">สรุปตามครู</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2">ครู</th>
            <th class="text-right px-3 py-2">จำนวนเวร</th>
            <th class="text-left px-3 py-2">รายละเอียด</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php $tid = (int)$r['id']; $dGroups = $details[$tid] ?? []; ?>
            <tr class="border-t align-top">
              <td class="px-3 py-2 font-medium"><?= htmlspecialchars(($r['teacher_code']? '['.$r['teacher_code'].'] ' : '').$r['first_name'].' '.$r['last_name']); ?></td>
              <td class="px-3 py-2 text-right font-semibold"><?= (int)$r['duty_cnt']; ?></td>
              <td class="px-3 py-2">
                <?php if (!$dGroups): ?>
                  <span class="text-slate-400">—</span>
                <?php else: ?>
                  <div class="space-y-3">
                    <?php foreach ($dGroups as $g): ?>
                      <?php
                        $bname = $g['building_name'] ?? null;
                        $title = $bname ? ('อาคาร: '.$bname) : 'อาคาร: —';
                      ?>
                      <div>
                        <div class="text-xs font-semibold text-slate-500 mb-1"><?= htmlspecialchars($title); ?></div>
                        <div class="flex flex-wrap gap-2">
                          <?php foreach (($g['items'] ?? []) as $d): ?>
                            <?php $timeRange = substr((string)$d['start_time'],0,5).'–'.substr((string)$d['end_time'],0,5); ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-lg bg-slate-50 border">
                              <?= tt_dow_label((int)$d['day_of_week']); ?> · <?= htmlspecialchars($timeRange); ?> · <?= htmlspecialchars($d['post_name']); ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
