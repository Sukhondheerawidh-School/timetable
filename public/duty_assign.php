<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

tt_duty_init($pdo);
tt_buildings_init($pdo);

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$activeYearId = 0;
foreach ($years as $y) { if (!empty($y['is_active'])) { $activeYearId = (int)$y['id']; break; } }
if (!$activeYearId && !empty($years)) $activeYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $activeYearId);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);

// Buildings
$buildings = tt_buildings_list($pdo, true);
$building_id = (int)($_GET['building_id'] ?? 0);
if ($building_id <= 0 && !empty($buildings)) {
  $building_id = (int)$buildings[0]['id'];
}

// Master slots exist from periods
tt_duty_master_sync_from_periods($pdo);

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $year_id = (int)($_POST['year_id'] ?? $year_id);
    $term_no = tt_validate_term_no($pdo, $year_id, (int)($_POST['term_no'] ?? $term_no));
    $building_id = (int)($_POST['building_id'] ?? $building_id);

    $action = $_POST['action'] ?? '';

    try {
      if ($action === 'assign') {
        $shift_id = (int)($_POST['shift_id'] ?? 0); // duty_master_shift_id
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        if (!$shift_id || !$teacher_id) throw new Exception('เลือกเวร/ครูให้ครบ');

        $returnShiftId = (int)($_POST['return_shift_id'] ?? $shift_id);

        // Exclusions
        $exChk = $pdo->prepare('SELECT 1 FROM duty_term_exclusions WHERE academic_year_id=? AND term_no=? AND teacher_id=? LIMIT 1');
        $exChk->execute([$year_id, $term_no, $teacher_id]);
        if ($exChk->fetchColumn()) throw new Exception('ครูคนนี้ถูกตั้งค่าให้ “ละเว้นเวร” ในเทอมนี้');

        $metaStmt = $pdo->prepare('SELECT ms.id, ms.day_of_week, ms.required_count,
            dts.id AS slot_id, dts.period_no
          FROM duty_master_shifts ms
          JOIN duty_master_time_slots dts ON dts.id=ms.duty_time_slot_id
          WHERE ms.id=? AND ms.is_active=1');
        $metaStmt->execute([$shift_id]);
        $meta = $metaStmt->fetch();
        if (!$meta) throw new Exception('ไม่พบเวร');

        // Capacity
        $cnt = $pdo->prepare('SELECT COUNT(*)
          FROM duty_term_assignments ta
          WHERE ta.academic_year_id=? AND ta.term_no=? AND ta.duty_master_shift_id=?
            AND NOT EXISTS (
              SELECT 1 FROM duty_term_exclusions e
              WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
            )');
        $cnt->execute([$year_id, $term_no, $shift_id]);
        if ((int)$cnt->fetchColumn() >= (int)$meta['required_count']) {
          throw new Exception('ช่องนี้ครบจำนวนแล้ว');
        }

        $day = (int)$meta['day_of_week'];
        $slotId = (int)$meta['slot_id'];
        $periodNo = $meta['period_no'] === null ? null : (int)$meta['period_no'];

        // Check conflicts: already on duty same slot/day
        $busyDuty = $pdo->prepare('SELECT 1
          FROM duty_term_assignments ta
          JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
          WHERE ta.teacher_id=? AND ta.academic_year_id=? AND ta.term_no=?
            AND ms.day_of_week=? AND ms.duty_time_slot_id=?
          LIMIT 1');
        $busyDuty->execute([$teacher_id, $year_id, $term_no, $day, $slotId]);
        if ($busyDuty->fetchColumn()) throw new Exception('ครูคนนี้ถูกจัดเวรในช่วงเวลานี้แล้ว');

        if ($periodNo !== null) {
          // teaching busy (supports co-teaching)
          $busyTeach = $pdo->prepare('SELECT 1
            FROM timetable_slots ts
            LEFT JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id AND tst.teacher_id=?
            WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week=? AND ts.period_no=?
              AND (ts.teacher_id=? OR tst.teacher_id IS NOT NULL)
            LIMIT 1');
          $busyTeach->execute([$teacher_id, $year_id, $term_no, $day, $periodNo, $teacher_id]);
          if ($busyTeach->fetchColumn()) throw new Exception('ครูคนนี้มีสอนช่วงเวลานี้');

          $busyCons = $pdo->prepare('SELECT 1 FROM teacher_constraints WHERE teacher_id=? AND academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? LIMIT 1');
          $busyCons->execute([$teacher_id, $year_id, $term_no, $day, $periodNo]);
          if ($busyCons->fetchColumn()) throw new Exception('ครูคนนี้ติดข้อจำกัดช่วงเวลานี้');
        }

        $ins = $pdo->prepare('INSERT INTO duty_term_assignments(academic_year_id, term_no, duty_master_shift_id, teacher_id) VALUES (?,?,?,?)');
        $ins->execute([$year_id, $term_no, $shift_id, $teacher_id]);

        logActivity('duty_assign', 'duty_term_assignments', (int)$pdo->lastInsertId(), null, [
          'academic_year_id' => $year_id,
          'term_no' => $term_no,
          'duty_master_shift_id' => $shift_id,
          'teacher_id' => $teacher_id,
          'building_id' => $building_id,
        ]);

        flash_set('success', 'จัดเวรแล้ว');
        $anchor = $returnShiftId > 0 ? '#shift-'.$returnShiftId : '';
        redirect('duty_assign.php?year_id='.$year_id.'&term_no='.$term_no.($building_id>0?'&building_id='.$building_id:'').$anchor);
      } elseif ($action === 'unassign') {
        $id = (int)($_POST['id'] ?? 0);
        $returnShiftId = (int)($_POST['return_shift_id'] ?? 0);

        $oldStmt = $pdo->prepare('SELECT id, academic_year_id, term_no, duty_master_shift_id, teacher_id FROM duty_term_assignments WHERE id=? AND academic_year_id=? AND term_no=?');
        $oldStmt->execute([$id, $year_id, $term_no]);
        $oldRow = $oldStmt->fetch();

        $del = $pdo->prepare('DELETE FROM duty_term_assignments WHERE id=? AND academic_year_id=? AND term_no=?');
        $del->execute([$id, $year_id, $term_no]);

        if ($oldRow) {
          logActivity('duty_unassign', 'duty_term_assignments', (int)$oldRow['id'], $oldRow, null);
        }
        flash_set('success', 'ลบแล้ว');
        $anchor = $returnShiftId > 0 ? '#shift-'.$returnShiftId : '';
        redirect('duty_assign.php?year_id='.$year_id.'&term_no='.$term_no.($building_id>0?'&building_id='.$building_id:'').$anchor);
      }
    } catch (Throwable $e) {
      $err = 'ผิดพลาด: '.$e->getMessage();
    }
  }
}

// Load enabled master slots (used in templates)
$slotsStmt = $pdo->prepare('SELECT id, slot_label, period_no, start_time, end_time, sort_order
  FROM duty_master_time_slots
  WHERE is_active=1
  ORDER BY sort_order');
$slotsStmt->execute();
$slots = $slotsStmt->fetchAll();

// Load master shifts templates
$shiftsSql = 'SELECT ms.id, ms.day_of_week, ms.required_count,
    dts.id AS slot_id, dts.slot_label, dts.period_no, dts.sort_order,
    dp.post_name, dp.building_id
  FROM duty_master_shifts ms
  JOIN duty_master_time_slots dts ON dts.id=ms.duty_time_slot_id
  JOIN duty_master_posts dp ON dp.id=ms.duty_post_id
  WHERE ms.is_active=1 AND dts.is_active=1 AND dp.is_active=1
    AND ms.day_of_week BETWEEN 1 AND 5
  ';
if ($building_id > 0) {
  // strict: only shifts whose post belongs to selected building
  $shiftsSql .= ' AND dp.building_id = ?';
}
$shiftsSql .= ' ORDER BY ms.day_of_week, dts.sort_order, dp.post_name';
$shiftsStmt = $pdo->prepare($shiftsSql);
$shiftsStmt->execute($building_id > 0 ? [$building_id] : []);
$shifts = $shiftsStmt->fetchAll();

// Assignments
$asStmt = $pdo->prepare('SELECT ta.id, ta.duty_master_shift_id AS duty_shift_id, t.id AS teacher_id, t.teacher_code, t.first_name, t.last_name
  FROM duty_term_assignments ta
  JOIN teachers t ON t.id=ta.teacher_id
  WHERE ta.academic_year_id=? AND ta.term_no=?
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )
  ORDER BY t.teacher_code, t.first_name, t.last_name');
$asStmt->execute([$year_id, $term_no]);
$assignmentsRaw = $asStmt->fetchAll();
$assignments = []; // shift_id => list
foreach ($assignmentsRaw as $a) {
  $sid = (int)$a['duty_shift_id'];
  if (!isset($assignments[$sid])) $assignments[$sid] = [];
  $assignments[$sid][] = $a;
}

// Teachers (hide excluded)
$teachersSql = 'SELECT t.id, t.teacher_code, t.first_name, t.last_name
  FROM teachers t
  WHERE NOT EXISTS (
    SELECT 1 FROM duty_term_exclusions e
    WHERE e.academic_year_id=? AND e.term_no=? AND e.teacher_id=t.id
  )
  ';
if ($building_id > 0) {
  // strict: only teachers assigned to selected building
  $teachersSql .= ' AND EXISTS (SELECT 1 FROM teacher_buildings tb WHERE tb.teacher_id=t.id AND tb.building_id=?)';
}
$teachersSql .= ' ORDER BY t.teacher_code, t.first_name, t.last_name';
$teachersStmt = $pdo->prepare($teachersSql);
$params = [$year_id, $term_no];
if ($building_id > 0) $params[] = $building_id;
$teachersStmt->execute($params);
$teachers = $teachersStmt->fetchAll();

// Precompute busy maps for teaching/constraints for relevant period_nos
$periodNos = [];
$slotById = [];
foreach ($slots as $s) {
  $slotById[(int)$s['id']] = $s;
  if ($s['period_no'] !== null) {
    $pno = (int)$s['period_no'];
    $periodNos[$pno] = true;
    if ($pno > 0) {
      $periodNos[max(0, $pno-1)] = true;
      $periodNos[$pno+1] = true;
    }
  }
}
$periodList = array_keys($periodNos);
sort($periodList);

$busyTeach = []; // [day][period][teacher]=true
$busyCons = [];  // [day][period][teacher]=true

if ($periodList) {
  $in = implode(',', array_fill(0, count($periodList), '?'));
  $params = array_merge([$year_id, $term_no], $periodList);

  // From timetable_slots teacher_id
  $q1 = 'SELECT day_of_week, period_no, teacher_id FROM timetable_slots WHERE academic_year_id=? AND term_no=? AND day_of_week BETWEEN 1 AND 7 AND period_no IN ('.$in.')';
  $st1 = $pdo->prepare($q1);
  $st1->execute($params);
  while ($r = $st1->fetch(PDO::FETCH_ASSOC)) {
    $d = (int)$r['day_of_week']; $p = (int)$r['period_no']; $t = (int)$r['teacher_id'];
    $busyTeach[$d][$p][$t] = true;
  }

  // From timetable_slot_teachers (co-teaching)
  $q2 = 'SELECT ts.day_of_week, ts.period_no, tst.teacher_id
         FROM timetable_slots ts
         JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
      WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week BETWEEN 1 AND 7 AND ts.period_no IN ('.$in.')';
  $st2 = $pdo->prepare($q2);
  $st2->execute($params);
  while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
    $d = (int)$r['day_of_week']; $p = (int)$r['period_no']; $t = (int)$r['teacher_id'];
    $busyTeach[$d][$p][$t] = true;
  }

  // Constraints
  $q3 = 'SELECT day_of_week, period_no, teacher_id FROM teacher_constraints WHERE academic_year_id=? AND term_no=? AND day_of_week BETWEEN 1 AND 7 AND period_no IN ('.$in.')';
  $st3 = $pdo->prepare($q3);
  $st3->execute($params);
  while ($r = $st3->fetch(PDO::FETCH_ASSOC)) {
    $d = (int)$r['day_of_week']; $p = (int)$r['period_no']; $t = (int)$r['teacher_id'];
    $busyCons[$d][$p][$t] = true;
  }
}

// Duty busy (avoid double duty same slot/day)
$dutyBusy = []; // [day][slotId][teacher]=true
$busyDutyStmt = $pdo->prepare('SELECT ms.day_of_week, ms.duty_time_slot_id AS slot_id, ta.teacher_id
  FROM duty_term_assignments ta
  JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
  WHERE ta.academic_year_id=? AND ta.term_no=?
    AND ms.day_of_week BETWEEN 1 AND 5
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )');
$busyDutyStmt->execute([$year_id, $term_no]);
while ($r = $busyDutyStmt->fetch(PDO::FETCH_ASSOC)) {
  $d = (int)$r['day_of_week']; $sid = (int)$r['slot_id']; $tid = (int)$r['teacher_id'];
  $dutyBusy[$d][$sid][$tid] = true;
}

// Duty counts for recommendations
$dutyCount = [];
$dcStmt = $pdo->prepare('SELECT teacher_id, COUNT(*) AS cnt
  FROM duty_term_assignments
  WHERE academic_year_id=? AND term_no=?
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=duty_term_assignments.academic_year_id
        AND e.term_no=duty_term_assignments.term_no
        AND e.teacher_id=duty_term_assignments.teacher_id
    )
  GROUP BY teacher_id');
$dcStmt->execute([$year_id, $term_no]);
while ($r = $dcStmt->fetch(PDO::FETCH_ASSOC)) {
  $dutyCount[(int)$r['teacher_id']] = (int)$r['cnt'];
}

// Group shifts by day+slot
$shiftByCell = []; // [day][slotId] => list
foreach ($shifts as $s) {
  $d = (int)$s['day_of_week'];
  $sid = (int)$s['slot_id'];
  if (!isset($shiftByCell[$d][$sid])) $shiftByCell[$d][$sid] = [];
  $shiftByCell[$d][$sid][] = $s;
}

$flash = flash_get();

// Overall progress (only shifts shown in this page context)
$totalNeed = 0;
$totalFilled = 0;
foreach ($shifts as $s) {
  $sid = (int)$s['id'];
  $cap = (int)$s['required_count'];
  $totalNeed += $cap;
  $totalFilled += min($cap, count($assignments[$sid] ?? []));
}
$totalRemaining = max(0, $totalNeed - $totalFilled);
$progressPct = $totalNeed > 0 ? (int)round(($totalFilled / $totalNeed) * 100) : 0;

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="w-full px-4 mt-8">
  <div class="max-w-7xl mx-auto">
  <div class="mb-3">
    <h1 class="text-xl font-semibold">🧑‍🏫 จัดเวรครู (รายเทอม)</h1>
  </div>

  <?php
    $ttDutyActive = 'assign';
    $ttDutyYearId = $year_id;
    $ttDutyTermNo = $term_no;
    $ttDutyBuildingId = $building_id;
    include __DIR__ . '/../partials/duty_tabs.php';
  ?>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="get" class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
    <div class="md:col-span-5">
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).(!empty($y['is_active'])?' (ใช้งาน)':'') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-3">
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
        <?php foreach (tt_terms_list($pdo, $year_id) as $t): ?>
          <option value="<?= (int)$t['term_no'] ?>" <?= (int)$t['term_no']===$term_no?'selected':''; ?>><?= htmlspecialchars($t['term_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-4">
      <label class="block text-xs mb-1">อาคาร</label>
      <select name="building_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()" <?= empty($buildings) ? 'disabled' : '' ?>>
        <?php if (empty($buildings)): ?>
          <option value="">— ยังไม่มีอาคาร —</option>
        <?php else: ?>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id']===$building_id?'selected':''; ?>><?= htmlspecialchars((string)$b['building_name']) ?></option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
      <div class="text-xs text-slate-500 mt-1">
        กำหนดครูประจำอาคารได้ที่ <a class="underline" href="<?= url('teacher_buildings.php'); ?>">ครูประจำอาคาร</a>
        · จัดการรายชื่ออาคารที่ <a class="underline" href="<?= url('buildings.php'); ?>">อาคาร</a>
      </div>
      <?php if (empty($buildings)): ?>
        <div class="text-xs text-rose-700 mt-1">ยังไม่มีอาคาร: ไปที่ <a class="underline" href="<?= url('buildings.php'); ?>">อาคาร</a> เพื่อเพิ่มก่อน</div>
      <?php endif; ?>
    </div>

    <div class="md:col-span-12">
      <div class="text-xs text-slate-500">ครูที่มีสอน/ติดข้อจำกัด/ถูกละเว้นเวร (ช่วงเวลานั้น) จะไม่แสดงในรายการให้เลือก · ถ้ามีสอนคาบติดกันจะยังเลือกได้ และระบบจะพยายามจัดอันดับให้เหมาะสม</div>
    </div>
  </form>

  <?php if (!empty($buildings) && $building_id > 0 && empty($teachers)): ?>
    <div class="mb-4 p-3 rounded-xl border bg-amber-50 text-amber-800 text-sm">
      ยังไม่มีครูที่ถูกกำหนดให้อยู่ในอาคารนี้ — ไปกำหนดได้ที่ <a class="underline" href="<?= url('teacher_buildings.php'); ?>">ครูประจำอาคาร</a>
    </div>
  <?php endif; ?>

  <div class="mb-4 bg-white rounded-2xl shadow p-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-sm font-semibold text-slate-900">ความคืบหน้าการลงเวร</div>
        <div class="text-xs text-slate-600 mt-1">ลงแล้ว <span class="font-semibold text-slate-900"><?= (int)$totalFilled ?></span> / <?= (int)$totalNeed ?> ช่อง · เหลือ <span class="font-semibold text-slate-900"><?= (int)$totalRemaining ?></span> ช่อง</div>
      </div>
      <div class="text-right">
        <div class="text-sm font-semibold text-slate-900"><?= (int)$progressPct ?>%</div>
        <div class="text-xs text-slate-500">(ตามจำนวนที่ต้องการทั้งหมด)</div>
      </div>
    </div>
    <div class="mt-3 h-3 w-full bg-slate-100 rounded-full overflow-hidden" role="progressbar" aria-valuenow="<?= (int)$progressPct ?>" aria-valuemin="0" aria-valuemax="100">
      <div class="h-full bg-sky-600" style="width: <?= (int)$progressPct ?>%"></div>
    </div>
  </div>

    </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm border-separate border-spacing-0">
        <thead class="bg-slate-50 sticky top-0 z-10">
          <tr>
            <th class="text-left px-3 py-2 sticky left-0 z-20 bg-slate-50">ช่วงเวลา</th>
            <?php for($d=1;$d<=5;$d++): ?>
              <th class="text-left px-3 py-2"><?= tt_dow_label($d) ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php
            $slotPalette = ['bg-sky-500', 'bg-emerald-500', 'bg-amber-500', 'bg-violet-500', 'bg-rose-500', 'bg-indigo-500', 'bg-slate-500'];
            $slotPaletteSoft = ['bg-sky-500/60', 'bg-emerald-500/60', 'bg-amber-500/60', 'bg-violet-500/60', 'bg-rose-500/60', 'bg-indigo-500/60', 'bg-slate-500/60'];
            $slotRowIndex = 0;
          ?>
          <?php foreach ($slots as $slot): ?>
            <?php $slotId = (int)$slot['id']; $pno = $slot['period_no']===null? null : (int)$slot['period_no']; ?>
            <?php
              $slotRowIndex++;
              $barClass = $slotPalette[($slotRowIndex - 1) % count($slotPalette)];
              $barSoftClass = $slotPaletteSoft[($slotRowIndex - 1) % count($slotPaletteSoft)];
            ?>
            <tr class="border-t align-top">
              <td class="px-3 py-2 whitespace-nowrap sticky left-0 bg-white z-10 border-r">
                <div class="flex items-start gap-2">
                  <div class="w-2 h-10 rounded-full <?= $barClass ?> flex-shrink-0 mt-0.5" aria-hidden="true"></div>
                  <div>
                    <div class="h-1 w-full rounded <?= $barSoftClass ?> mb-2" aria-hidden="true"></div>
                    <div class="font-medium"><?= htmlspecialchars($slot['slot_label']); ?></div>
                    <div class="text-xs text-slate-500"><?= htmlspecialchars(substr((string)$slot['start_time'],0,5)); ?>–<?= htmlspecialchars(substr((string)$slot['end_time'],0,5)); ?><?= $pno===null? '' : ' · คาบ '.$pno; ?></div>
                  </div>
                </div>
              </td>
              <?php for($day=1;$day<=5;$day++): ?>
                <td class="px-3 py-2 min-w-[230px]">
                  <div class="h-1 w-full rounded <?= $barSoftClass ?> mb-2" aria-hidden="true"></div>
                  <?php $cellShifts = $shiftByCell[$day][$slotId] ?? []; ?>
                  <?php if (!$cellShifts): ?>
                    <div class="text-xs text-slate-400">—</div>
                  <?php else: ?>
                    <div class="space-y-3">
                      <?php $shiftIndex = 0; ?>
                      <?php foreach ($cellShifts as $sh): ?>
                        <?php
                          $shiftIndex++;
                          $shiftBg = ($shiftIndex % 2 === 1) ? 'bg-sky-100/80 border-sky-200' : 'bg-white border-slate-200';
                          $shiftId = (int)$sh['id'];
                          $as = $assignments[$shiftId] ?? [];
                          $cap = (int)$sh['required_count'];
                          $filled = count($as);

                          // Build available teacher list
                          $available = [];
                          foreach ($teachers as $t) {
                            $tid = (int)$t['id'];
                            if (!empty($dutyBusy[$day][$slotId][$tid])) continue;
                            if ($pno !== null) {
                              if (!empty($busyTeach[$day][$pno][$tid])) continue;
                              if (!empty($busyCons[$day][$pno][$tid])) continue;
                            }
                            $available[] = $t;
                          }

                          // Recommendations: fewer duty counts, avoid adjacent teaching
                          $scored = [];
                          foreach ($available as $t) {
                            $tid = (int)$t['id'];
                            $cnt = (int)($dutyCount[$tid] ?? 0);
                            $adj = 0;
                            $adjNotes = [];
                            if ($pno !== null && $pno > 0) {
                              $prev = $pno - 1;
                              $next = $pno + 1;

                              $hasPrevTeach = false;
                              $hasNextTeach = false;

                              if ($prev > 0) {
                                if (!empty($busyTeach[$day][$prev][$tid])) { $hasPrevTeach = true; }
                              }
                              if (!empty($busyTeach[$day][$next][$tid])) { $hasNextTeach = true; }

                              if ($hasPrevTeach) { $adj++; $adjNotes[] = 'มีสอนคาบ '.$prev; }
                              if ($hasNextTeach) { $adj++; $adjNotes[] = 'มีสอนคาบ '.$next; }

                              // Midday exception: periods 4–6 allow single-side adjacency (prev XOR next)
                              // Example: duty period 4, teaches period 3, period 5 is free => still recommendable.
                              if ($pno >= 4 && $pno <= 6 && $adj === 1 && ($hasPrevTeach xor $hasNextTeach)) {
                                $adj = 0;
                                $adjNotes[] = 'คาบติดกันด้านเดียว';
                              }
                            }

                            $scored[] = ['t'=>$t, 'cnt'=>$cnt, 'adj'=>$adj, 'adjNotes'=>$adjNotes];
                          }
                          usort($scored, function($a,$b){
                            // Sort: no adjacent burdens first, then fewer burdens, then fewer duties, then teacher_code
                            if ($a['adj'] !== $b['adj']) return $a['adj'] <=> $b['adj'];
                            if ($a['cnt'] !== $b['cnt']) return $a['cnt'] <=> $b['cnt'];
                            return $a['t']['teacher_code'] <=> $b['t']['teacher_code'];
                          });
                          $sortedTeachers = $scored;
                        ?>
                        <div id="shift-<?= (int)$shiftId; ?>" class="rounded-xl p-3 border <?= $shiftBg; ?>">
                          <div class="flex items-start justify-between gap-2">
                            <div>
                              <div class="font-medium"><?= htmlspecialchars($sh['post_name']); ?></div>
                              <div class="text-xs text-slate-500">ต้องการ <?= $cap ?> คน · ลงแล้ว <?= $filled ?> คน</div>
                            </div>
                            <?php if ($filled >= $cap): ?>
                              <span class="text-xs px-2 py-1 rounded bg-emerald-100 text-emerald-700">ครบแล้ว</span>
                            <?php endif; ?>
                          </div>

                          <?php if ($as): ?>
                            <div class="mt-2 space-y-1">
                              <?php foreach ($as as $a): ?>
                                <div class="flex items-center justify-between gap-2 bg-white border rounded-lg px-2 py-1">
                                  <div class="text-sm">
                                    <?= htmlspecialchars(($a['teacher_code']? '['.$a['teacher_code'].'] ' : '').$a['first_name'].' '.$a['last_name']); ?>
                                  </div>
                                  <form method="post" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบ', text: 'ลบครูคนนี้ออกจากเวร?', confirmButtonText: 'ลบ' });">
                                    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
                                    <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
                                    <input type="hidden" name="building_id" value="<?= (int)$building_id; ?>">
                                    <input type="hidden" name="return_shift_id" value="<?= (int)$shiftId; ?>">
                                    <input type="hidden" name="id" value="<?= (int)$a['id']; ?>">
                                    <button class="text-xs px-2 py-1 rounded border border-rose-200 text-rose-700 hover:bg-rose-50">ลบ</button>
                                  </form>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>

                          <?php if ($filled < $cap): ?>
                            <form method="post" class="mt-2 flex gap-2">
                              <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                              <input type="hidden" name="action" value="assign">
                              <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
                              <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
                              <input type="hidden" name="building_id" value="<?= (int)$building_id; ?>">
                              <input type="hidden" name="shift_id" value="<?= (int)$shiftId; ?>">
                              <input type="hidden" name="return_shift_id" value="<?= (int)$shiftId; ?>">

                              <div class="flex-1">
                                <select name="teacher_id" class="w-full border rounded px-2 py-1" required>
                                <option value="">-- เลือกครู (ว่าง) --</option>

                                <?php
                                  $bestDutyCnt = null;
                                  foreach ($sortedTeachers as $rr) {
                                    if ((int)$rr['adj'] !== 0) continue;
                                    $c = (int)$rr['cnt'];
                                    if ($bestDutyCnt === null || $c < $bestDutyCnt) $bestDutyCnt = $c;
                                  }
                                ?>

                                <?php foreach ($sortedTeachers as $r): ?>
                                  <?php
                                    $t = $r['t'];
                                    $notes = [];
                                    if (!empty($r['adjNotes'])) {
                                      $notes[] = implode(', ', (array)$r['adjNotes']);
                                    }
                                    $isBest = ((int)$r['adj'] === 0) && ($bestDutyCnt !== null) && ((int)$r['cnt'] === (int)$bestDutyCnt);
                                    $suffix = ' (รับแล้ว '.(int)$r['cnt'].' เวร'.($notes ? ' · '.implode(' · ', $notes) : '').')';
                                    $prefix = $isBest ? '✓ ' : '';
                                    $label = $prefix.($t['teacher_code']? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name'].$suffix;
                                  ?>
                                  <option value="<?= (int)$t['id']; ?>" <?= $isBest ? 'style="background:#e0f2fe;"' : ''; ?>><?= htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                                </select>
                              </div>
                              <button class="px-3 py-1.5 rounded bg-slate-900 text-white">ลง</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
