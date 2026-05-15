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
$view_mode = (($_GET['view_mode'] ?? '') === 'week') ? 'week' : 'teacher';

// Ensure master slots exist (templates depend on this)
tt_duty_master_sync_from_periods($pdo);

// Posts filter
$postsSql = 'SELECT id, post_name FROM duty_master_posts WHERE is_active=1';
$postsParams = [];
if ($building_id > 0) { $postsSql .= ' AND building_id=?'; $postsParams[] = $building_id; }
$postsSql .= ' ORDER BY sort_order, post_name';
$postsStmt = $pdo->prepare($postsSql);
$postsStmt->execute($postsParams);
$allPosts = $postsStmt->fetchAll();
$post_id = (int)($_GET['post_id'] ?? 0);
// Reset post_id if not in current post list
if ($post_id > 0 && !in_array($post_id, array_column($allPosts, 'id'), false)) $post_id = 0;

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
if ($post_id > 0) {
  $sumSql .= ' AND ms.duty_post_id=?';
  $sumParams[] = $post_id;
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
if ($post_id > 0) {
  $detailSql .= ' AND ms.duty_post_id=?';
  $detailParams[] = $post_id;
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

// ── stats สำหรับ summary cards ─────────────────────────────────
$totalAssigned    = array_sum(array_column($rows, 'duty_cnt'));
$teachersWithDuty = count(array_filter($rows, fn($r) => (int)$r['duty_cnt'] > 0));
$maxDuty = $rows ? (int)$rows[0]['duty_cnt'] : 0; // sorted DESC

// ── Week grid data ─────────────────────────────────────────────────────
$weekSql = 'SELECT
    mts.sort_order AS slot_sort, mts.slot_label, mts.period_no, mts.start_time, mts.end_time,
    ms.id AS shift_id, ms.day_of_week, ms.required_count,
    mp.post_name,
    t.teacher_code, t.first_name, t.last_name
  FROM duty_master_time_slots mts
  JOIN duty_master_shifts ms ON ms.duty_time_slot_id = mts.id AND ms.is_active = 1
  JOIN duty_master_posts mp ON mp.id = ms.duty_post_id AND mp.is_active = 1
  LEFT JOIN duty_term_assignments ta ON ta.duty_master_shift_id = ms.id
    AND ta.academic_year_id = ? AND ta.term_no = ?
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id = ta.academic_year_id AND e.term_no = ta.term_no AND e.teacher_id = ta.teacher_id
    )
  LEFT JOIN teachers t ON t.id = ta.teacher_id
  WHERE mts.is_active = 1';
$weekParams = [$year_id, $term_no];
if ($building_id > 0) { $weekSql .= ' AND mp.building_id = ?'; $weekParams[] = $building_id; }
if ($post_id > 0)     { $weekSql .= ' AND mp.id = ?';          $weekParams[] = $post_id; }
$weekSql .= ' ORDER BY mts.sort_order, mts.start_time, ms.day_of_week, mp.sort_order, mp.post_name, t.teacher_code, t.first_name';
$weekStmt = $pdo->prepare($weekSql);
$weekStmt->execute($weekParams);

$weekGrid  = []; // slot_key => [meta + days[dow][shift_id]]
$weekSlots = []; // ordered slot keys
while ($wr = $weekStmt->fetch(PDO::FETCH_ASSOC)) {
  $sk = (int)$wr['slot_sort'].'_'.(string)$wr['start_time'];
  if (!isset($weekGrid[$sk])) {
    $weekGrid[$sk] = [
      'slot_label' => $wr['slot_label'],
      'period_no'  => $wr['period_no'],
      'start_time' => $wr['start_time'],
      'end_time'   => $wr['end_time'],
      'days'       => [],
    ];
    $weekSlots[] = $sk;
  }
  $dow = (int)$wr['day_of_week'];
  $sid = (int)$wr['shift_id'];
  if (!isset($weekGrid[$sk]['days'][$dow])) $weekGrid[$sk]['days'][$dow] = [];
  if (!isset($weekGrid[$sk]['days'][$dow][$sid])) {
    $weekGrid[$sk]['days'][$dow][$sid] = [
      'post_name'      => $wr['post_name'],
      'required_count' => (int)$wr['required_count'],
      'teachers'       => [],
    ];
  }
  if ($wr['first_name'] !== null) {
    $weekGrid[$sk]['days'][$dow][$sid]['teachers'][] =
      trim(($wr['teacher_code'] ? $wr['teacher_code'].' ' : '').$wr['first_name'].' '.$wr['last_name']);
  }
}

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8 pb-12">

  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-bold tracking-tight text-slate-900">สรุปเวรครู</h1>
      <p class="text-sm text-slate-500 mt-1">ภาพรวมการรับเวรของครูแต่ละคนในเทอมนี้</p>
    </div>
    <?php $reportQs = 'year_id='.$year_id.'&term_no='.$term_no.($building_id>0?'&building_id='.$building_id:'').($post_id>0?'&post_id='.$post_id:''); ?>
    <?php $baseQs   = $reportQs; ?>
    <div class="flex items-center gap-2 flex-wrap">
      <!-- View toggle -->
      <a href="<?= url('duty_summary.php?'.$baseQs.'&view_mode=teacher') ?>"
         class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-medium shadow-sm transition <?= $view_mode==='teacher' ? 'bg-indigo-600 text-white' : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700' ?>">
        👤 รายครู
      </a>
      <a href="<?= url('duty_summary.php?'.$baseQs.'&view_mode=week') ?>"
         class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-medium shadow-sm transition <?= $view_mode==='week' ? 'bg-indigo-600 text-white' : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700' ?>">
        📅 รายสัปดาห์
      </a>
      <div class="w-px h-5 bg-slate-200"></div>
      <a target="_blank" rel="noopener" href="<?= url('duty_summary_report.php?'.$reportQs) ?>"
         class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-sm font-medium shadow-sm transition">
        🖨 รายงานเวร
      </a>
      <a target="_blank" rel="noopener" href="<?= url('duty_summary_teacher_report.php?'.$reportQs) ?>"
         class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90 text-sm font-medium shadow-sm transition">
        👤 รายงานแยกครู
      </a>
    </div>
  </div>

  <?php
    $ttDutyActive = 'summary';
    $ttDutyYearId = $year_id;
    $ttDutyTermNo = $term_no;
    include __DIR__ . '/../partials/duty_tabs.php';
  ?>

  <!-- Filter bar -->
  <form method="get" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-5">
    <input type="hidden" name="view_mode" value="<?= htmlspecialchars($view_mode) ?>">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1.5">ปีการศึกษา</label>
        <select name="year_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-200" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).(!empty($y['is_active'])?' ✓':'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1.5">เทอม</label>
        <select name="term_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-200" onchange="this.form.submit()">
          <?php foreach (tt_terms_list($pdo, $year_id) as $t): ?>
            <option value="<?= (int)$t['term_no'] ?>" <?= (int)$t['term_no']===$term_no?'selected':''; ?>><?= htmlspecialchars($t['term_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1.5">อาคาร</label>
        <select name="building_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-200" onchange="this.form.submit()" <?= empty($buildings)?'disabled':''; ?>>
          <option value="0" <?= $building_id===0?'selected':''; ?>>ทุกอาคาร</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id']===$building_id?'selected':''; ?>><?= htmlspecialchars((string)$b['building_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1.5">จุดเวร</label>
        <select name="post_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-200" onchange="this.form.submit()">
          <option value="0" <?= $post_id===0?'selected':''; ?>>ทุกจุด</option>
          <?php foreach ($allPosts as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id']===$post_id?'selected':''; ?>><?= htmlspecialchars($p['post_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </form>

  <?php if ($view_mode === 'teacher'): ?>
  <!-- Summary cards -->
  <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-5">
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
      <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">ครูที่รับเวร</div>
      <div class="text-3xl font-bold text-slate-900"><?= $teachersWithDuty ?></div>
      <div class="text-xs text-slate-400 mt-1">จาก <?= count($rows) ?> คนทั้งหมด</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
      <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">เวรรวมทั้งหมด</div>
      <div class="text-3xl font-bold text-slate-900"><?= $totalAssigned ?></div>
      <div class="text-xs text-slate-400 mt-1">ครั้ง (ไม่นับที่ถูกยกเว้น)</div>
    </div>
    <div class="col-span-2 md:col-span-1 bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
      <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">รับเวรสูงสุด</div>
      <div class="text-3xl font-bold text-slate-900"><?= $maxDuty ?></div>
      <div class="text-xs text-slate-400 mt-1">ครั้ง (ครูที่รับมากที่สุด)</div>
    </div>
  </div>

  <!-- Table -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
      <span class="font-semibold text-slate-800">รายชื่อครูและรายละเอียดเวร</span>
      <span class="text-xs text-slate-400"><?= count($rows) ?> คน · เรียงจากมากไปน้อย</span>
    </div>
    <?php if (!$rows): ?>
      <div class="px-5 py-12 text-center text-slate-400 text-sm">— ยังไม่มีข้อมูลเวรในเทอมนี้ —</div>
    <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php
        $rankColors = ['bg-amber-400','bg-slate-300','bg-orange-300'];
        $rowIdx = 0;
      ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $rowIdx++;
          $tid = (int)$r['id'];
          $cnt = (int)$r['duty_cnt'];
          $dGroups = $details[$tid] ?? [];
          $teacherName = ($r['teacher_code'] ? '['.$r['teacher_code'].'] ' : '').$r['first_name'].' '.$r['last_name'];
          // bar width
          $barPct = $maxDuty > 0 ? min(100, (int)round($cnt / $maxDuty * 100)) : 0;
          $hasAny = $cnt > 0;
        ?>
        <div class="px-5 py-4 <?= !$hasAny ? 'opacity-50' : '' ?>">
          <div class="flex items-start gap-3">
            <!-- Rank / avatar -->
            <div class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold
              <?= $hasAny ? ($rowIdx <= 3 ? $rankColors[$rowIdx-1].' text-white' : 'bg-slate-100 text-slate-500') : 'bg-slate-50 text-slate-300' ?>">
              <?= $hasAny ? $rowIdx : '—' ?>
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="font-semibold text-slate-800 text-sm"><?= htmlspecialchars($teacherName) ?></div>
                <div class="flex items-center gap-2">
                  <?php if ($hasAny): ?>
                    <span class="text-xs font-bold text-white bg-slate-700 rounded-full px-2.5 py-0.5"><?= $cnt ?> เวร</span>
                  <?php else: ?>
                    <span class="text-xs text-slate-300 italic">ยังไม่มีเวร</span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($hasAny): ?>
                <!-- Progress bar -->
                <div class="mt-1.5 h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                  <div class="h-full rounded-full bg-sky-500 transition-all" style="width:<?= $barPct ?>%"></div>
                </div>

                <!-- Duty tags -->
                <div class="mt-2.5 space-y-2">
                  <?php foreach ($dGroups as $bkey => $g): ?>
                    <?php $bname = $g['building_name'] ?? null; ?>
                    <?php if (count($dGroups) > 1 && $bname): ?>
                      <div class="text-xs font-semibold text-slate-400 mb-1"><?= htmlspecialchars($bname) ?></div>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-1.5">
                      <?php foreach (($g['items'] ?? []) as $d): ?>
                        <?php
                          $timeRange = substr((string)$d['start_time'],0,5).'–'.substr((string)$d['end_time'],0,5);
                          $dow = tt_dow_label((int)$d['day_of_week']);
                        ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-sky-50 border border-sky-100 text-sky-800 text-xs font-medium">
                          <span class="font-bold"><?= htmlspecialchars($dow) ?></span>
                          <span class="text-sky-400">·</span>
                          <span><?= htmlspecialchars($timeRange) ?></span>
                          <span class="text-sky-400">·</span>
                          <span><?= htmlspecialchars($d['post_name']) ?></span>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; /* view_mode === teacher */ ?>

  <?php if ($view_mode === 'week'): ?>
  <!-- Week grid table -->
  <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
      <span class="font-semibold text-slate-800">ตารางเวรรายสัปดาห์</span>
      <div class="flex items-center gap-3">
        <span class="text-xs text-slate-400">แสดงทุกจุดเวร · สีเทา = ยังไม่มีครู</span>
        <a target="_blank" rel="noopener"
           href="<?= url('duty_week_report.php?'.$reportQs) ?>"
           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 shadow-sm transition">
          🖨 พิมพ์รายงาน
        </a>
      </div>
    </div>
    <?php if (!$weekSlots): ?>
      <div class="px-5 py-12 text-center text-slate-400 text-sm">— ยังไม่มีข้อมูลเวรในเทอมนี้ —</div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm border-collapse">
        <thead>
          <tr class="bg-slate-50">
            <th class="text-left px-4 py-3 font-semibold text-slate-600 border-b border-r border-slate-200 whitespace-nowrap min-w-[130px]">คาบ / เวลา</th>
            <?php foreach ([1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์'] as $dow => $dlabel): ?>
              <th class="text-center px-4 py-3 font-semibold text-slate-600 border-b border-r border-slate-200 whitespace-nowrap min-w-[120px]"><?= $dlabel ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($weekSlots as $sk): ?>
            <?php $sg = $weekGrid[$sk]; ?>
            <tr class="border-b border-slate-100 align-top hover:bg-slate-50/60 transition-colors">
              <!-- คาบ/เวลา -->
              <td class="px-4 py-3 border-r border-slate-100 whitespace-nowrap align-middle">
                <?php
                  $pno = $sg['period_no'];
                  $st  = substr((string)$sg['start_time'], 0, 5);
                  $et  = substr((string)$sg['end_time'],   0, 5);
                  $stDot = str_replace(':', '.', $st);
                  $etDot = str_replace(':', '.', $et);
                ?>
                <div class="font-semibold text-slate-700 text-sm">
                  <?php if ($pno !== null): ?>คาบ <?= (int)$pno ?><?php else: ?><?= htmlspecialchars((string)$sg['slot_label']) ?><?php endif; ?>
                </div>
                <div class="text-xs text-slate-400 mt-0.5"><?= $stDot ?> – <?= $etDot ?></div>
              </td>
              <!-- จันทร์–ศุกร์ -->
              <?php foreach ([1,2,3,4,5] as $dow): ?>
                <td class="px-3 py-2 border-r border-slate-100 align-top text-xs">
                  <?php $shifts = $sg['days'][$dow] ?? []; ?>
                  <?php if ($shifts): ?>
                    <div class="space-y-2">
                      <?php foreach ($shifts as $sd): ?>
                        <div class="leading-relaxed">
                          <span class="font-semibold text-slate-700"><?= htmlspecialchars($sd['post_name']) ?></span>
                          <?php if ($sd['teachers']): ?>
                            <?php foreach ($sd['teachers'] as $tn): ?>
                              <div class="text-slate-600 pl-1"><?= htmlspecialchars($tn) ?></div>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <div class="text-slate-300 italic pl-1">(ว่าง <?= $sd['required_count'] ?> คน)</div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-slate-200">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; /* view_mode === week */ ?>

</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
