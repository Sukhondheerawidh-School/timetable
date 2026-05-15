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

// View mode
$view = (string)($_GET['view'] ?? 'week');
if ($view !== 'day' && $view !== 'week') $view = 'week';
$day = (int)($_GET['day'] ?? (int)date('N'));
if ($day < 1 || $day > 7) $day = 1;
$include_loads = !empty($_GET['include_loads']) ? 1 : 0;

// Master slots exist from periods
tt_duty_master_sync_from_periods($pdo);

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } elseif (!canEditSection('duty')) {
    $err = '🔒 ระบบปิดการแก้ไขชั่วคราว กรุณาติดต่อ Superuser';
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
        $returnView = (string)($_POST['return_view'] ?? $view);
        $returnView = ($returnView === 'day') ? 'day' : 'week';
        $returnDay = (int)($_POST['return_day'] ?? $day);
        if ($returnDay < 1 || $returnDay > 7) $returnDay = 1;

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

          // activity busy
          $busyAct = $pdo->prepare('SELECT ag.activity_name
            FROM activity_teachers at2
            JOIN activity_groups ag ON ag.id = at2.activity_id
            WHERE at2.teacher_id=? AND ag.academic_year_id=? AND ag.term_no=?
              AND ag.day_of_week=? AND ag.period_no=? AND ag.is_all_day=0
            LIMIT 1');
          $busyAct->execute([$teacher_id, $year_id, $term_no, $day, $periodNo]);
          $actRow = $busyAct->fetch();
          if ($actRow) throw new Exception('ครูคนนี้มีกิจกรรมช่วงเวลานี้ (กิจกรรม: '.htmlspecialchars($actRow['activity_name']).')');

          // teacher_constraints blocks teaching only, not duty assignments
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
        $qs = 'year_id='.$year_id.'&term_no='.$term_no.($building_id>0?'&building_id='.$building_id:'');
        $qs .= '&view='.$returnView;
        if ($returnView === 'day') $qs .= '&day='.$returnDay;
        redirect('duty_assign.php?'.$qs.$anchor);
      } elseif ($action === 'unassign') {
        $id = (int)($_POST['id'] ?? 0);
        $returnShiftId = (int)($_POST['return_shift_id'] ?? 0);
        $returnView = (string)($_POST['return_view'] ?? $view);
        $returnView = ($returnView === 'day') ? 'day' : 'week';
        $returnDay = (int)($_POST['return_day'] ?? $day);
        if ($returnDay < 1 || $returnDay > 7) $returnDay = 1;

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
        $qs = 'year_id='.$year_id.'&term_no='.$term_no.($building_id>0?'&building_id='.$building_id:'');
        $qs .= '&view='.$returnView;
        if ($returnView === 'day') $qs .= '&day='.$returnDay;
        redirect('duty_assign.php?'.$qs.$anchor);
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
  ORDER BY CASE WHEN period_no IS NULL THEN 9999 ELSE period_no END, start_time, sort_order');
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
  ';
$shiftsParams = [];
if ($view === 'day') {
  $shiftsSql .= ' AND ms.day_of_week = ?';
  $shiftsParams[] = $day;
} else {
  $shiftsSql .= ' AND ms.day_of_week BETWEEN 1 AND 5';
}
if ($building_id > 0) {
  // strict: only shifts whose post belongs to selected building
  $shiftsSql .= ' AND dp.building_id = ?';
  $shiftsParams[] = $building_id;
}
$shiftsSql .= ' ORDER BY ms.day_of_week, CASE WHEN dts.period_no IS NULL THEN 9999 ELSE dts.period_no END, dts.start_time, dts.sort_order, dp.post_name';
$shiftsStmt = $pdo->prepare($shiftsSql);
$shiftsStmt->execute($shiftsParams);
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

  // From activity_teachers (non-all-day activities)
  $q4 = 'SELECT ag.day_of_week, ag.period_no, at2.teacher_id
         FROM activity_groups ag
         JOIN activity_teachers at2 ON at2.activity_id = ag.id
         WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.is_all_day=0
           AND ag.day_of_week BETWEEN 1 AND 7 AND ag.period_no IN ('.$in.')';
  $st4 = $pdo->prepare($q4);
  $st4->execute($params);
  while ($r = $st4->fetch(PDO::FETCH_ASSOC)) {
    $d = (int)$r['day_of_week']; $p = (int)$r['period_no']; $t = (int)$r['teacher_id'];
    $busyTeach[$d][$p][$t] = true;
  }
}

// Duty busy (avoid double duty same slot/day)
$dutyBusy = []; // [day][slotId][teacher]=true
$busyDutySql = 'SELECT ms.day_of_week, ms.duty_time_slot_id AS slot_id, ta.teacher_id
  FROM duty_term_assignments ta
  JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
  WHERE ta.academic_year_id=? AND ta.term_no=?
    ';
$busyDutyParams = [$year_id, $term_no];
if ($view === 'day') {
  $busyDutySql .= ' AND ms.day_of_week=?';
  $busyDutyParams[] = $day;
} else {
  $busyDutySql .= ' AND ms.day_of_week BETWEEN 1 AND 5';
}
$busyDutySql .= '
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )';
$busyDutyStmt = $pdo->prepare($busyDutySql);
$busyDutyStmt->execute($busyDutyParams);
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

// Teaching load per teacher (for combined-score recommendation)
$teachLoad = [];
if ($include_loads) {
  $tlStmt = $pdo->prepare('SELECT teacher_id, COUNT(*) AS cnt FROM (
    SELECT teacher_id, day_of_week, period_no FROM timetable_slots
    WHERE academic_year_id=? AND term_no=? AND teacher_id IS NOT NULL
    UNION
    SELECT tst.teacher_id, ts.day_of_week, ts.period_no
    FROM timetable_slots ts JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=?
    UNION
    SELECT att.teacher_id, ag.day_of_week, ag.period_no
    FROM activity_teachers att
    JOIN activity_groups ag ON ag.id=att.activity_id
    WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.is_all_day=0
  ) sub GROUP BY teacher_id');
  $tlStmt->execute([$year_id, $term_no, $year_id, $term_no, $year_id, $term_no]);
  while ($r = $tlStmt->fetch(PDO::FETCH_ASSOC)) {
    $teachLoad[(int)$r['teacher_id']] = (int)$r['cnt'];
  }
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

// ✅ Pre-build presenter data for JS (Presenter Mode)
$presenterShifts = [];
foreach ($shifts as $sh) {
  $shiftId = (int)$sh['id'];
  $slotId  = (int)$sh['slot_id'];
  $pDay    = (int)$sh['day_of_week'];
  $pPno    = isset($sh['period_no']) && $sh['period_no'] !== null ? (int)$sh['period_no'] : null;
  $as      = $assignments[$shiftId] ?? [];
  $cap     = (int)$sh['required_count'];
  $filled  = count($as);
  $available = [];
  foreach ($teachers as $t) {
    $tid = (int)$t['id'];
    if (!empty($dutyBusy[$pDay][$slotId][$tid])) continue;
    if ($pPno !== null) {
      if (!empty($busyTeach[$pDay][$pPno][$tid])) continue;
    }
    $available[] = $t;
  }
  $scored = [];
  foreach ($available as $t) {
    $tid = (int)$t['id'];
    $cnt = (int)($dutyCount[$tid] ?? 0);
    $tload = $include_loads ? (int)($teachLoad[$tid] ?? 0) : 0;
    $score = $cnt + $tload;
    $adj = 0; $adjNotes = [];
    if ($pPno !== null && $pPno > 0) {
      $prev = $pPno - 1; $next = $pPno + 1;
      $hasPrev = ($prev > 0 && !empty($busyTeach[$pDay][$prev][$tid]));
      $hasNext = !empty($busyTeach[$pDay][$next][$tid]);
      if ($hasPrev) { $adj++; $adjNotes[] = 'มีสอนคาบ '.$prev; }
      if ($hasNext) { $adj++; $adjNotes[] = 'มีสอนคาบ '.$next; }
      if ($pPno >= 4 && $pPno <= 6 && $adj === 1 && ($hasPrev xor $hasNext)) { $adj = 0; $adjNotes[] = 'คาบติดกันด้านเดียว'; }
    }
    $scored[] = ['t' => $t, 'cnt' => $cnt, 'tload' => $tload, 'score' => $score, 'adj' => $adj, 'adjNotes' => $adjNotes];
  }
  usort($scored, function($a, $b) {
    if ($a['adj'] !== $b['adj']) return $a['adj'] <=> $b['adj'];
    if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
    return $a['t']['teacher_code'] <=> $b['t']['teacher_code'];
  });
  $bestCnt = null;
  foreach ($scored as $rr) {
    if ((int)$rr['adj'] !== 0) continue;
    $c = (int)$rr['score'];
    if ($bestCnt === null || $c < $bestCnt) $bestCnt = $c;
  }
  $presenterShifts[$shiftId] = [
    'id'        => $shiftId,
    'day'       => $pDay,
    'slotId'    => $slotId,
    'slotLabel' => (string)$sh['slot_label'],
    'postName'  => (string)$sh['post_name'],
    'cap'       => $cap,
    'filled'    => $filled,
    'assignees' => array_map(function($a) {
      return ['id' => (int)$a['id'], 'teacherId' => (int)$a['teacher_id'],
        'name' => ($a['teacher_code'] ? '['.$a['teacher_code'].'] ' : '').$a['first_name'].' '.$a['last_name']];
    }, $as),
    'teachers'  => array_map(function($r) use ($bestCnt) {
      $t = $r['t'];
      $best = ((int)$r['adj'] === 0) && $bestCnt !== null && ((int)$r['score'] === $bestCnt);
      return ['id' => (int)$t['id'],
        'name' => ($t['teacher_code'] ? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name'],
        'cnt' => (int)$r['cnt'], 'tload' => (int)$r['tload'], 'score' => (int)$r['score'],
        'adj' => (int)$r['adj'],
        'adjNotes' => $r['adjNotes'], 'best' => $best];
    }, $scored),
  ];
}
$pmJson = json_encode([
  'csrf'         => csrf_token(),
  'yearId'       => $year_id,
  'termNo'       => $term_no,
  'buildingId'   => $building_id,
  'includeLoads' => (bool)$include_loads,
  'shifts'       => $presenterShifts,
  'slots'        => array_values($slots),
  'totalFilled'  => $totalFilled,
  'totalNeed'    => $totalNeed,
  'progressPct'  => $progressPct,
], JSON_UNESCAPED_UNICODE);

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="w-full px-4 mt-8">
  <div class="max-w-7xl mx-auto">
  <div class="mb-3 flex items-center justify-between gap-3 flex-wrap">
    <h1 class="text-xl font-semibold">🧑‍🏫 จัดเวรครู (รายเทอม)</h1>
    <button type="button" onclick="ttPresenter.enter()"
      class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition-colors shadow">
      🖥️ โหมดจอใหญ่
    </button>
  </div>

  <?php
    $ttDutyActive = 'assign';
    $ttDutyYearId = $year_id;
    $ttDutyTermNo = $term_no;
    $ttDutyBuildingId = $building_id;
    include __DIR__ . '/../partials/duty_tabs.php';
  ?>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-xl flex items-start gap-2 <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'; ?> text-sm">
      <span class="text-base flex-shrink-0"><?= $flash['type']==='success' ? '✅' : '❌'; ?></span>
      <span><?= htmlspecialchars($flash['msg']); ?></span>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded-xl flex items-start gap-2 bg-rose-50 text-rose-700 border border-rose-200 text-sm"><span class="text-base flex-shrink-0">❌</span><span><?= htmlspecialchars($err) ?></span></div>
  <?php endif; ?>

  <form id="filterForm" method="get" class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
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

    <div class="md:col-span-4">
      <label class="block text-xs mb-1">โหมดมุมมอง</label>
      <input type="hidden" name="view" value="<?= htmlspecialchars($view); ?>">
      <div class="inline-flex rounded-xl border bg-white overflow-hidden">
        <button type="button" class="px-3 py-2 text-sm <?= $view==='week' ? 'bg-slate-900 text-white' : 'bg-white hover:bg-slate-50'; ?>" onclick="this.form.view.value='week'; this.form.submit();">รายสัปดาห์</button>
        <button type="button" class="px-3 py-2 text-sm <?= $view==='day' ? 'bg-slate-900 text-white' : 'bg-white hover:bg-slate-50'; ?>" onclick="this.form.view.value='day'; this.form.submit();">รายวัน</button>
      </div>
    </div>
    <div class="md:col-span-4 <?= $view==='day' ? '' : 'hidden'; ?>">
      <label class="block text-xs mb-1">เลือกวัน</label>
      <select name="day" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
        <?php for($d=1;$d<=7;$d++): ?>
          <option value="<?= (int)$d; ?>" <?= (int)$d===(int)$day?'selected':''; ?>><?= htmlspecialchars(tt_dow_label($d)); ?></option>
        <?php endfor; ?>
      </select>
    </div>

    <div class="md:col-span-12">
      <div class="text-xs text-slate-500">ครูที่มีสอน/ติดข้อจำกัด/ถูกละเว้นเวร (ช่วงเวลานั้น) จะไม่แสดงในรายการให้เลือก · ถ้ามีสอนคาบติดกันจะยังเลือกได้ และระบบจะพยายามจัดอันดับให้เหมาะสม</div>
      <label class="inline-flex items-center gap-2 mt-2 cursor-pointer select-none">
        <input type="checkbox" name="include_loads" value="1"
          <?= $include_loads ? 'checked' : '' ?>
          onchange="this.form.submit()"
          class="w-4 h-4 rounded border-slate-300 text-sky-600 accent-sky-600">
        <span class="text-xs text-slate-700 font-medium">รวมคาบสอนในการจัดอันดับ</span>
        <span class="text-xs text-slate-400">(เปิด = นับเวร + คาบสอนรวมกัน เพื่อให้เห็นว่าใครภาระรวมน้อยที่สุด)</span>
      </label>
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
      <div class="h-full bg-sky-600 transition-all duration-700" style="width: <?= (int)$progressPct ?>%"></div>
    </div>
  </div>

    </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="overflow-x-auto">
      <?php if ($view === 'week'): ?>
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
                          $shiftId = (int)$sh['id'];
                          $as = $assignments[$shiftId] ?? [];
                          $cap = (int)$sh['required_count'];
                          $filled = count($as);
                          $shiftBg = ($filled >= $cap)
                            ? 'bg-emerald-50 border-emerald-200'
                            : (($shiftIndex % 2 === 1) ? 'bg-sky-50/80 border-sky-100' : 'bg-white border-slate-200');

                          // Build available teacher list
                          $available = [];
                          foreach ($teachers as $t) {
                            $tid = (int)$t['id'];
                            if (!empty($dutyBusy[$day][$slotId][$tid])) continue;
                            if ($pno !== null) {
                              if (!empty($busyTeach[$day][$pno][$tid])) continue;
                            }
                            $available[] = $t;
                          }

                          // Recommendations: fewer duty counts, avoid adjacent teaching
                          $scored = [];
                          foreach ($available as $t) {
                            $tid = (int)$t['id'];
                            $cnt = (int)($dutyCount[$tid] ?? 0);
                            $tload = $include_loads ? (int)($teachLoad[$tid] ?? 0) : 0;
                            $score = $cnt + $tload;
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

                            $scored[] = ['t'=>$t, 'cnt'=>$cnt, 'tload'=>$tload, 'score'=>$score, 'adj'=>$adj, 'adjNotes'=>$adjNotes];
                          }
                          usort($scored, function($a,$b){
                            // Sort: no adjacent burdens first, then fewer burdens, then fewer duties, then teacher_code
                            if ($a['adj'] !== $b['adj']) return $a['adj'] <=> $b['adj'];
                            if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
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
                          <?php $capPct = $cap > 0 ? min(100, (int)round($filled/$cap*100)) : 0; ?>
                          <div class="mt-1.5 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all <?= $filled>=$cap ? 'bg-emerald-400' : 'bg-sky-400'; ?>" style="width:<?= $capPct ?>%"></div>
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
                                    <input type="hidden" name="return_view" value="<?= htmlspecialchars($view); ?>">
                                    <input type="hidden" name="return_day" value="<?= (int)$day; ?>">
                                    <input type="hidden" name="id" value="<?= (int)$a['id']; ?>">
                                    <button type="submit" class="text-xs px-2 py-1 rounded border border-rose-200 text-rose-700 hover:bg-rose-50">ลบ</button>
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
                              <input type="hidden" name="return_view" value="<?= htmlspecialchars($view); ?>">
                              <input type="hidden" name="return_day" value="<?= (int)$day; ?>">

                              <div class="flex-1">
                                <select name="teacher_id" class="w-full border rounded px-2 py-1" required data-tt-teacher-select>
                                <option value="">-- เลือกครู (ว่าง) --</option>

                                <?php
                                  $bestDutyCnt = null;
                                  foreach ($sortedTeachers as $rr) {
                                    if ((int)$rr['adj'] !== 0) continue;
                                    $c = (int)$rr['score'];
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
                                    $isBest = ((int)$r['adj'] === 0) && ($bestDutyCnt !== null) && ((int)$r['score'] === (int)$bestDutyCnt);
                                    if ($include_loads) {
                                      $suffix = ' (รับแล้ว '.(int)$r['cnt'].' เวร · สอน '.(int)$r['tload'].' คาบ'.($notes ? ' · '.implode(' · ', $notes) : '').')';
                                    } else {
                                      $suffix = ' (รับแล้ว '.(int)$r['cnt'].' เวร'.($notes ? ' · '.implode(' · ', $notes) : '').')';
                                    }
                                    $prefix = $isBest ? '✓ ' : '';
                                    $label = $prefix.($t['teacher_code']? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name'].$suffix;
                                  ?>
                                  <option value="<?= (int)$t['id']; ?>" <?= $isBest ? 'style="background:#e0f2fe;"' : ''; ?>><?= htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                                </select>
                              </div>
                              <button type="submit" class="px-3 py-1.5 rounded bg-slate-900 text-white text-sm whitespace-nowrap">ลง</button>
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
      <?php else: ?>
      <div class="px-4 py-3 border-b bg-slate-50">
        <div class="text-sm font-semibold">โหมดรายวัน: <?= htmlspecialchars(tt_dow_label((int)$day)); ?></div>
        <div class="text-xs text-slate-500 mt-1">คอลัมน์เป็นคาบ/ช่วงเวลา เรียงซ้าย → ขวา</div>
      </div>
      <?php
        $slotsDay = [];
        foreach ($slots as $slot) {
          $sid = (int)$slot['id'];
          if (!empty($shiftByCell[$day][$sid])) $slotsDay[] = $slot;
        }
      ?>
      <?php if (!$slotsDay): ?>
        <div class="p-4 text-sm text-slate-500">— ยังไม่มีเวรในวันนี้ —</div>
      <?php else: ?>
      <table class="min-w-full text-sm border-separate border-spacing-0">
        <thead class="bg-slate-50 sticky top-0 z-10">
          <tr>
            <?php foreach ($slotsDay as $slot): ?>
              <?php $pno = $slot['period_no']===null? null : (int)$slot['period_no']; ?>
              <th class="text-left px-3 py-2 min-w-[240px]">
                <div class="font-medium"><?= htmlspecialchars($slot['slot_label']); ?></div>
                <div class="text-xs text-slate-500"><?= htmlspecialchars(substr((string)$slot['start_time'],0,5)); ?>–<?= htmlspecialchars(substr((string)$slot['end_time'],0,5)); ?><?= $pno===null? '' : ' · คาบ '.$pno; ?></div>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <tr class="align-top">
            <?php foreach ($slotsDay as $slot): ?>
              <?php
                $slotId = (int)$slot['id'];
                $pno = $slot['period_no']===null? null : (int)$slot['period_no'];
                $cellShifts = $shiftByCell[$day][$slotId] ?? [];
              ?>
              <td class="px-3 py-3">
                <?php if (!$cellShifts): ?>
                  <div class="text-xs text-slate-400">—</div>
                <?php else: ?>
                  <div class="space-y-3">
                    <?php $shiftIndex = 0; ?>
                    <?php foreach ($cellShifts as $sh): ?>
                      <?php
                        $shiftIndex++;
                        $shiftId = (int)$sh['id'];
                        $as = $assignments[$shiftId] ?? [];
                        $cap = (int)$sh['required_count'];
                        $filled = count($as);
                        $shiftBg = ($filled >= $cap)
                          ? 'bg-emerald-50 border-emerald-200'
                          : (($shiftIndex % 2 === 1) ? 'bg-sky-50/80 border-sky-100' : 'bg-white border-slate-200');

                        // Build available teacher list
                        $available = [];
                        foreach ($teachers as $t) {
                          $tid = (int)$t['id'];
                          if (!empty($dutyBusy[$day][$slotId][$tid])) continue;
                          if ($pno !== null) {
                            if (!empty($busyTeach[$day][$pno][$tid])) continue;
                          }
                          $available[] = $t;
                        }

                        // Recommendations: fewer duty counts, avoid adjacent teaching
                        $scored = [];
                        foreach ($available as $t) {
                          $tid = (int)$t['id'];
                          $cnt = (int)($dutyCount[$tid] ?? 0);
                          $tload = $include_loads ? (int)($teachLoad[$tid] ?? 0) : 0;
                          $score = $cnt + $tload;
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

                            if ($pno >= 4 && $pno <= 6 && $adj === 1 && ($hasPrevTeach xor $hasNextTeach)) {
                              $adj = 0;
                              $adjNotes[] = 'คาบติดกันด้านเดียว';
                            }
                          }

                          $scored[] = ['t'=>$t, 'cnt'=>$cnt, 'tload'=>$tload, 'score'=>$score, 'adj'=>$adj, 'adjNotes'=>$adjNotes];
                        }
                        usort($scored, function($a,$b){
                          if ($a['adj'] !== $b['adj']) return $a['adj'] <=> $b['adj'];
                          if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
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
                        <?php $capPct = $cap > 0 ? min(100, (int)round($filled/$cap*100)) : 0; ?>
                        <div class="mt-1.5 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                          <div class="h-full rounded-full transition-all <?= $filled>=$cap ? 'bg-emerald-400' : 'bg-sky-400'; ?>" style="width:<?= $capPct ?>%"></div>
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
                                  <input type="hidden" name="return_view" value="<?= htmlspecialchars($view); ?>">
                                  <input type="hidden" name="return_day" value="<?= (int)$day; ?>">
                                  <input type="hidden" name="id" value="<?= (int)$a['id']; ?>">
                                  <button type="submit" class="text-xs px-2 py-1 rounded border border-rose-200 text-rose-700 hover:bg-rose-50">ลบ</button>
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
                              <input type="hidden" name="return_view" value="<?= htmlspecialchars($view); ?>">
                              <input type="hidden" name="return_day" value="<?= (int)$day; ?>">

                            <div class="flex-1">
                              <select name="teacher_id" class="w-full border rounded px-2 py-1" required data-tt-teacher-select>
                              <option value="">-- เลือกครู (ว่าง) --</option>

                              <?php
                                $bestDutyCnt = null;
                                foreach ($sortedTeachers as $rr) {
                                  if ((int)$rr['adj'] !== 0) continue;
                                  $c = (int)$rr['score'];
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
                                  $isBest = ((int)$r['adj'] === 0) && ($bestDutyCnt !== null) && ((int)$r['score'] === (int)$bestDutyCnt);
                                  if ($include_loads) {
                                    $suffix = ' (รับแล้ว '.(int)$r['cnt'].' เวร · สอน '.(int)$r['tload'].' คาบ'.($notes ? ' · '.implode(' · ', $notes) : '').')';
                                  } else {
                                    $suffix = ' (รับแล้ว '.(int)$r['cnt'].' เวร'.($notes ? ' · '.implode(' · ', $notes) : '').')';
                                  }
                                  $prefix = $isBest ? '✓ ' : '';
                                  $label = $prefix.($t['teacher_code']? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name'].$suffix;
                                ?>
                                <option value="<?= (int)$t['id']; ?>" <?= $isBest ? 'style="background:#e0f2fe;"' : ''; ?>><?= htmlspecialchars($label); ?></option>
                              <?php endforeach; ?>
                              </select>
                            </div>
                            <button type="submit" class="px-3 py-1.5 rounded bg-slate-900 text-white text-sm whitespace-nowrap">ลง</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        </tbody>
      </table>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ✅ Presenter Mode Overlay -->
<div id="ttPresenterOverlay" style="display:none;position:fixed;inset:0;z-index:200;background:#0f172a;flex-direction:column;overflow:hidden;">
  <div style="background:#1e293b;border-bottom:1px solid #334155;padding:12px 20px;display:flex;align-items:center;gap:16px;flex-shrink:0;">
    <div style="font-size:1.1rem;font-weight:700;color:#f8fafc;white-space:nowrap;">🖥️ โหมดจอใหญ่ · จัดเวรครู</div>
    <div id="pPresenterProgress" style="flex:1;min-width:0;"></div>
    <button onclick="ttPresenter.exit()" style="padding:8px 16px;background:#ef4444;color:white;border:none;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer;flex-shrink:0;">✕ ออก (ESC)</button>
  </div>
  <div id="pPresenterGrid" style="flex:1;overflow:auto;padding:16px;"></div>
</div>
<!-- Presenter Teacher Picker Modal -->
<div id="pPresenterModal" style="display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,0.75);align-items:center;justify-content:center;padding:24px;">
  <div id="pPresenterModalBox" style="background:#1e293b;border:1px solid #334155;border-radius:20px;width:100%;max-width:820px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;"></div>
</div>

<script>
  (function () {
    function norm(s) {
      return (s || '').toString().toLowerCase().replace(/\s+/g, ' ').trim();
    }

    var enhancedForms = new WeakSet();
    var currentOpen = null; // wrapper element

    function enhanceTeacherSelect(select) {
      if (!select || select.dataset.ttEnhanced === '1') return;
      select.dataset.ttEnhanced = '1';

      var options = [];
      var placeholder = 'เลือกครู…';
      for (var i = 0; i < (select.options || []).length; i++) {
        var o = select.options[i];
        if (!o) continue;
        if (i === 0 || !o.value) {
          if (o.text) placeholder = o.text;
          continue;
        }
        options.push({
          value: o.value,
          text: o.text,
          style: o.getAttribute('style') || ''
        });
      }
      if (!options.length) return;

      // Wrap & hide native select (keep it for submission)
      var wrapper = document.createElement('div');
      wrapper.className = 'relative';
      select.parentNode.insertBefore(wrapper, select);
      wrapper.appendChild(select);

      select.style.position = 'absolute';
      select.style.left = '-9999px';
      select.style.width = '1px';
      select.style.height = '1px';
      select.tabIndex = -1;
      select.required = false;

      // Visible combobox input
      var input = document.createElement('input');
      input.type = 'search';
      input.placeholder = placeholder;
      input.className = 'w-full border rounded px-2 py-1 text-xs';
      input.autocomplete = 'off';
      input.spellcheck = false;
      wrapper.insertBefore(input, select);

      // Dropdown list
      var menu = document.createElement('div');
      menu.className = 'absolute left-0 right-0 mt-1 bg-white border rounded-lg shadow-lg max-h-56 overflow-auto z-50 hidden';
      wrapper.appendChild(menu);

      var filtered = options.slice();
      var activeIndex = -1;

      wrapper.__ttClose = close;

      function close() {
        menu.classList.add('hidden');
        activeIndex = -1;
        if (currentOpen === wrapper) currentOpen = null;
      }

      function open() {
        if (currentOpen && currentOpen !== wrapper && currentOpen.__ttClose) {
          try { currentOpen.__ttClose(); } catch (e) {}
        }
        menu.classList.remove('hidden');
        currentOpen = wrapper;
      }

      function render() {
        menu.innerHTML = '';
        if (!filtered.length) {
          var empty = document.createElement('div');
          empty.className = 'px-3 py-2 text-xs text-slate-500';
          empty.textContent = '— ไม่พบครู —';
          menu.appendChild(empty);
          return;
        }

        for (var j = 0; j < filtered.length; j++) {
          (function (idx) {
            var opt = filtered[idx];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-full text-left px-3 py-2 text-xs hover:bg-slate-50';
            if (idx === activeIndex) btn.className += ' bg-slate-100';
            btn.textContent = opt.text;
            if (opt.style) btn.setAttribute('style', opt.style);

            // Prevent input blur before click fires
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () { choose(opt); });

            menu.appendChild(btn);
          })(j);
        }
      }

      function filterNow() {
        var q = norm(input.value);
        filtered = options.filter(function (opt) {
          return q === '' || norm(opt.text).indexOf(q) !== -1;
        });
        activeIndex = filtered.length ? 0 : -1;
        render();
      }

      function choose(opt) {
        select.value = opt.value;
        input.value = opt.text;
        close();
        try { select.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
      }

      // Init input from existing selected value
      if (select.value) {
        for (var k = 0; k < options.length; k++) {
          if (options[k].value === select.value) {
            input.value = options[k].text;
            break;
          }
        }
      }

      input.addEventListener('focus', function () {
        filterNow();
        open();
      });
      input.addEventListener('click', function () {
        filterNow();
        open();
      });
      input.addEventListener('input', function () {
        filterNow();
        open();
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          if (!menu.classList.contains('hidden') && activeIndex >= 0 && filtered[activeIndex]) {
            e.preventDefault();
            choose(filtered[activeIndex]);
          } else {
            e.preventDefault();
          }
        } else if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (menu.classList.contains('hidden')) {
            filterNow();
            open();
          }
          if (filtered.length) {
            activeIndex = Math.min(filtered.length - 1, activeIndex + 1);
            render();
          }
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (filtered.length) {
            activeIndex = Math.max(0, activeIndex - 1);
            render();
          }
        } else if (e.key === 'Escape') {
          close();
        }
      });

      // Validate on submit
      var form = select.form;
      if (form && !enhancedForms.has(form)) {
        enhancedForms.add(form);
        form.addEventListener('submit', function (e) {
          var sel = form.querySelector('select[data-tt-teacher-select]');
          if (!sel) return;
          if (!sel.value) {
            e.preventDefault();
            var inp = form.querySelector('input[type="search"]');
            if (inp) {
              inp.focus();
              inp.classList.add('border-rose-400');
              setTimeout(function(){ inp.classList.remove('border-rose-400'); }, 700);
            }
          }
        });
      }
    }

    function init() {
      var selects = document.querySelectorAll('select[data-tt-teacher-select]');
      selects.forEach(function (s) { enhanceTeacherSelect(s); });

      document.addEventListener('click', function (e) {
        if (!currentOpen) return;
        if (currentOpen.contains(e.target)) return;
        if (currentOpen.__ttClose) {
          try { currentOpen.__ttClose(); } catch (err) {}
        }
      });
    }

    document.addEventListener('DOMContentLoaded', init);
  })();

  // ✅ Loading overlay on filter change
  (function() {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,0.65);display:flex;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity 0.2s';
    overlay.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;gap:12px"><div style="width:36px;height:36px;border:4px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:ttSpin 0.7s linear infinite"></div><span style="font-size:0.85rem;color:#475569">กำลังโหลด...</span></div><style>@keyframes ttSpin{to{transform:rotate(360deg)}}</style>';
    document.body.appendChild(overlay);
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
      filterForm.addEventListener('submit', function() {
        overlay.style.opacity = '1';
        overlay.style.pointerEvents = 'auto';
      });
      var _origSubmit = filterForm.submit.bind(filterForm);
      filterForm.submit = function() {
        overlay.style.opacity = '1';
        overlay.style.pointerEvents = 'auto';
        _origSubmit();
      };
    }
  })();

  // ✅ Presenter Mode
  var ttPresenter = (function () {
    var PM = <?= $pmJson ?>;
    var DOW = {1:'จันทร์',2:'อังคาร',3:'พุธ',4:'พฤหัส',5:'ศุกร์',6:'เสาร์',7:'อาทิตย์'};
    var currentShiftId = null;

    function esc(s) {
      return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function enter() {
      var el = document.getElementById('ttPresenterOverlay');
      el.style.display = 'flex';
      try { document.documentElement.requestFullscreen(); } catch(e){}
      renderProgress();
      renderGrid();
      document.addEventListener('keydown', onKeyDown);
      sessionStorage.setItem('tt_presenter_active','1');
    }

    function exit() {
      document.getElementById('ttPresenterOverlay').style.display = 'none';
      closeModal();
      try { if (document.fullscreenElement) document.exitFullscreen(); } catch(e){}
      document.removeEventListener('keydown', onKeyDown);
      sessionStorage.removeItem('tt_presenter_active');
    }

    function onKeyDown(e) {
      if (e.key === 'Escape') {
        if (document.getElementById('pPresenterModal').style.display !== 'none') { closeModal(); }
        else { exit(); }
      }
    }

    function renderProgress() {
      var el = document.getElementById('pPresenterProgress');
      var pct = PM.progressPct;
      var color = pct >= 100 ? '#22c55e' : pct >= 50 ? '#eab308' : '#ef4444';
      el.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">'
        +'<div style="flex:1;background:#334155;border-radius:999px;height:10px;overflow:hidden;">'
        +'<div style="height:10px;border-radius:999px;background:'+color+';width:'+pct+'%;"></div></div>'
        +'<span style="color:#94a3b8;font-size:0.85rem;white-space:nowrap;">ลงแล้ว '+PM.totalFilled+'/'+PM.totalNeed+' ช่อง ('+pct+'%)</span>'
        +'</div>';
    }

    function renderGrid() {
      var container = document.getElementById('pPresenterGrid');
      var slotMap = {};
      PM.slots.forEach(function(s) { slotMap[s.id] = s; });

      var shiftBySlotDay = {};
      Object.keys(PM.shifts).forEach(function(sid) {
        var sh = PM.shifts[sid];
        if (!shiftBySlotDay[sh.slotId]) shiftBySlotDay[sh.slotId] = {};
        if (!shiftBySlotDay[sh.slotId][sh.day]) shiftBySlotDay[sh.slotId][sh.day] = [];
        shiftBySlotDay[sh.slotId][sh.day].push(parseInt(sid));
      });

      var visibleSlotIds = Object.keys(shiftBySlotDay).map(Number);
      visibleSlotIds.sort(function(a,b) {
        var pa = slotMap[a] && slotMap[a].period_no != null ? slotMap[a].period_no : 9999;
        var pb = slotMap[b] && slotMap[b].period_no != null ? slotMap[b].period_no : 9999;
        if (pa !== pb) return pa - pb;
        return ((slotMap[a]||{}).sort_order||0) - ((slotMap[b]||{}).sort_order||0);
      });

      if (!visibleSlotIds.length) {
        container.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:60px;font-size:1.3rem;">— ยังไม่มีเวรที่กำหนดไว้ —</div>';
        return;
      }

      var days = [1,2,3,4,5];
      var html = '<table style="width:100%;border-collapse:separate;border-spacing:8px;">';
      html += '<thead><tr>';
      html += '<th style="position:sticky;top:0;z-index:10;background:#1e293b;color:#64748b;font-size:0.85rem;padding:10px 14px;border-radius:10px;text-align:left;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,0.5);">ช่วงเวลา</th>';
      days.forEach(function(d) {
        html += '<th style="position:sticky;top:0;z-index:10;background:#1e293b;color:#f1f5f9;font-size:1.15rem;font-weight:800;padding:12px;border-radius:10px;text-align:center;min-width:190px;box-shadow:0 2px 6px rgba(0,0,0,0.5);">'+DOW[d]+'</th>';
      });
      html += '</tr></thead><tbody>';

      visibleSlotIds.forEach(function(slotId) {
        var slot = slotMap[slotId];
        if (!slot) return;
        var timeStr = (slot.start_time||'').substr(0,5)+'–'+(slot.end_time||'').substr(0,5);
        html += '<tr>';
        html += '<td style="background:#1e293b;border-radius:10px;padding:12px 14px;vertical-align:top;white-space:nowrap;">';
        html += '<div style="font-size:1rem;font-weight:700;color:#f8fafc;">'+esc(slot.slot_label)+'</div>';
        html += '<div style="font-size:0.8rem;color:#64748b;margin-top:3px;">'+esc(timeStr)+(slot.period_no ? ' · คาบ '+slot.period_no : '')+'</div>';
        html += '</td>';
        days.forEach(function(d) {
          var shiftIds = (shiftBySlotDay[slotId] && shiftBySlotDay[slotId][d]) ? shiftBySlotDay[slotId][d] : [];
          shiftIds.sort(function(a, b) {
            var na = (PM.shifts[a] && PM.shifts[a].postName) || '';
            var nb = (PM.shifts[b] && PM.shifts[b].postName) || '';
            return na.localeCompare(nb, 'th');
          });
          html += '<td style="vertical-align:top;padding:0;">';
          if (!shiftIds.length) {
            html += '<div style="min-height:72px;background:#0f172a;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#1e293b;font-size:1.2rem;">—</div>';
          } else {
            shiftIds.forEach(function(sid) { html += renderShiftCard(sid); });
          }
          html += '</td>';
        });
        html += '</tr>';
      });

      html += '</tbody></table>';
      container.innerHTML = html;
    }

    function renderShiftCard(shiftId) {
      var sh = PM.shifts[shiftId];
      if (!sh) return '';
      var isFull = sh.filled >= sh.cap;
      var isEmpty = sh.filled === 0;
      var pct = sh.cap > 0 ? Math.min(100, Math.round(sh.filled / sh.cap * 100)) : 0;
      var bg     = isFull ? '#052e16' : isEmpty ? '#3b0a0a' : '#1c1917';
      var border = isFull ? '#16a34a' : isEmpty ? '#dc2626' : '#ca8a04';
      var dot    = isFull ? '#22c55e' : isEmpty ? '#ef4444' : '#eab308';
      var statusTxt   = isFull ? '✓ ครบแล้ว' : sh.filled+'/'+sh.cap+' คน';
      var statusColor = isFull ? '#86efac' : isEmpty ? '#fca5a5' : '#fde68a';
      var names = sh.assignees.map(function(a){ return a.name.replace(/\[.*?\]\s*/,''); }).join(', ');

      return '<div onclick="ttPresenter.openModal('+shiftId+')"'
        +' style="background:'+bg+';border:2px solid '+border+';border-radius:12px;padding:12px;margin-bottom:8px;cursor:pointer;"'
        +' onmouseover="this.style.opacity=\'0.82\'" onmouseout="this.style.opacity=\'1\'" >'
        +'<div style="display:flex;align-items:flex-start;gap:8px;">'
        +'<span style="width:10px;height:10px;border-radius:50%;background:'+dot+';flex-shrink:0;margin-top:4px;"></span>'
        +'<div style="flex:1;min-width:0;">'
        +'<div style="font-size:1rem;font-weight:700;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(sh.postName)+'</div>'
        +'<div style="font-size:0.82rem;font-weight:600;color:'+statusColor+';margin-top:2px;">'+statusTxt+'</div>'
        +'</div></div>'
        +'<div style="height:4px;background:#1e293b;border-radius:999px;overflow:hidden;margin:8px 0 4px;">'
        +'<div style="height:4px;background:'+dot+';width:'+pct+'%;border-radius:999px;"></div></div>'
        +(names ? '<div style="font-size:0.75rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+esc(names)+'</div>' : '')
        +'</div>';
    }

    function openModal(shiftId) {
      currentShiftId = shiftId;
      var sh = PM.shifts[shiftId];
      if (!sh) return;
      document.getElementById('pPresenterModal').style.display = 'flex';
      renderModal(sh);
    }

    function closeModal() {
      document.getElementById('pPresenterModal').style.display = 'none';
      currentShiftId = null;
    }

    function renderModal(sh) {
      var dayLabel = DOW[sh.day] || '';
      var isFull = sh.filled >= sh.cap;
      var dot = isFull ? '#22c55e' : sh.filled === 0 ? '#ef4444' : '#eab308';
      var html = '';

      // Header
      html += '<div style="background:#0f172a;border-bottom:1px solid #334155;padding:20px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-shrink:0;">';
      html += '<div><div style="display:flex;align-items:center;gap:10px;">'
        +'<span style="width:14px;height:14px;border-radius:50%;background:'+dot+';flex-shrink:0;"></span>'
        +'<span style="font-size:1.6rem;font-weight:800;color:#f8fafc;">'+esc(sh.postName)+'</span></div>'
        +'<div style="margin-top:5px;color:#64748b;font-size:1rem;">'
        +esc(sh.slotLabel)+' · วัน'+dayLabel+' · ต้องการ '+sh.cap+' คน'
        +(sh.filled ? ' (ลงแล้ว '+sh.filled+' คน)' : '')
        +'</div></div>';
      html += '<button onclick="ttPresenter.closeModal()" style="padding:10px 18px;background:#374151;color:#f8fafc;border:none;border-radius:10px;font-size:1rem;cursor:pointer;font-weight:600;flex-shrink:0;">✕ ปิด</button>';
      html += '</div>';

      // Body
      html += '<div style="overflow-y:auto;padding:20px 24px;flex:1;">';

      // Current assignees
      if (sh.assignees.length) {
        html += '<div style="margin-bottom:22px;">';
        html += '<div style="font-size:0.75rem;font-weight:800;color:#475569;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:10px;">✅ ลงเวรแล้ว</div>';
        html += '<div style="display:flex;flex-direction:column;gap:8px;">';
        sh.assignees.forEach(function(a) {
          html += '<div style="display:flex;align-items:center;justify-content:space-between;background:#0f172a;border:1px solid #22c55e;border-radius:14px;padding:14px 18px;">';
          html += '<span style="font-size:1.15rem;font-weight:700;color:#f8fafc;">'+esc(a.name)+'</span>';
          html += '<form method="post" onsubmit="ttPresenter.saveState('+currentShiftId+');" style="margin:0;">';
          html += hiddenFields(sh.id) + '<input type="hidden" name="action" value="unassign"><input type="hidden" name="id" value="'+a.id+'">';
          html += '<button type="submit" style="padding:10px 20px;background:#7f1d1d;color:#fca5a5;border:1px solid #dc2626;border-radius:10px;font-size:0.95rem;font-weight:700;cursor:pointer;">🗑️ ยกเลิก</button>';
          html += '</form></div>';
        });
        html += '</div></div>';
      }

      // Available teachers
      if (!isFull) {
        html += '<div><div style="font-size:0.75rem;font-weight:800;color:#475569;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:12px;">เลือกครู</div>';
        if (!sh.teachers.length) {
          html += '<div style="color:#64748b;text-align:center;padding:40px;font-size:1.05rem;">— ไม่มีครูที่ว่างในช่วงเวลานี้ —</div>';
        } else {
          html += '<input type="text" id="ppTeacherSearch" placeholder="🔍 พิมพ์ชื่อครู..." autocomplete="off" style="width:100%;box-sizing:border-box;padding:11px 16px;background:#0f172a;border:1px solid #334155;border-radius:12px;color:#f1f5f9;font-size:1rem;margin-bottom:12px;outline:none;" oninput="ttPresenter.filterTeachers(this.value)">';
          html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;" id="ppTeacherGrid">';
          sh.teachers.forEach(function(t) {
            var bg, border, badge = '', cntColor;
            if (t.best) {
              bg = '#052e16'; border = '#16a34a'; cntColor = '#22c55e';
              badge = '<span style="background:#14532d;color:#86efac;font-size:0.72rem;font-weight:800;padding:4px 10px;border-radius:999px;">⭐ แนะนำ</span>';
            } else if (t.adj > 0) {
              bg = '#1c1400'; border = '#92400e'; cntColor = '#fbbf24';
              badge = '<span style="background:#451a03;color:#fde68a;font-size:0.72rem;font-weight:800;padding:4px 10px;border-radius:999px;">⚠️ สอนติดกัน</span>';
            } else {
              bg = '#0f172a'; border = '#334155'; cntColor = '#64748b';
            }
            var adjTxt = (t.adjNotes && t.adjNotes.length) ? '<div style="font-size:0.75rem;color:#94a3b8;margin-top:3px;">'+esc(t.adjNotes.join(' · '))+'</div>' : '';
            var tname = esc(t.name.replace(/\[.*?\]\s*/g,''));
            html += '<div class="pp-ti" data-name="'+tname.toLowerCase()+'">';
            html += '<form method="post" onsubmit="ttPresenter.saveState('+currentShiftId+');" style="margin:0;">';
            html += hiddenFields(sh.id) + '<input type="hidden" name="action" value="assign"><input type="hidden" name="teacher_id" value="'+t.id+'">';
            html += '<button type="submit" style="width:100%;text-align:left;background:'+bg+';border:2px solid '+border
              +';border-radius:14px;padding:16px 18px;cursor:pointer;display:flex;align-items:center;gap:14px;"'
              +' onmouseover="this.style.opacity=\'0.82\'" onmouseout="this.style.opacity=\'1\'">';
            html += '<div style="width:42px;height:42px;border-radius:50%;background:#1e293b;border:2px solid '+border
              +';display:flex;align-items:center;justify-content:center;font-size:1.15rem;font-weight:900;color:'+cntColor+';flex-shrink:0;">'+(PM.includeLoads ? t.score : t.cnt)+'</div>';
            html += '<div style="flex:1;min-width:0;">';
            html += '<div style="font-size:1.05rem;font-weight:700;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(t.name)+'</div>';
            html += '<div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'+badge
              +'<span style="font-size:0.78rem;color:#64748b;">รับแล้ว '+t.cnt+' เวร'+(PM.includeLoads ? ' · สอน '+t.tload+' คาบ' : '')+'</span></div>';
            html += adjTxt + '</div></button></form></div>';
          });
          html += '</div>';
        }
        html += '</div>';
      } else {
        html += '<div style="text-align:center;padding:40px;color:#86efac;font-size:1.2rem;font-weight:700;">✅ เวรนี้ครบจำนวนแล้ว</div>';
      }

      html += '</div>'; // end body
      document.getElementById('pPresenterModalBox').innerHTML = html;
      var si = document.getElementById('ppTeacherSearch');
      if (si) { si.focus(); }
    }

    function hiddenFields(shiftId) {
      return '<input type="hidden" name="csrf" value="'+esc(PM.csrf)+'">'
        +'<input type="hidden" name="year_id" value="'+PM.yearId+'">'
        +'<input type="hidden" name="term_no" value="'+PM.termNo+'">'
        +'<input type="hidden" name="building_id" value="'+PM.buildingId+'">'
        +'<input type="hidden" name="shift_id" value="'+shiftId+'">'
        +'<input type="hidden" name="return_shift_id" value="'+shiftId+'">'
        +'<input type="hidden" name="return_view" value="week">';
    }

    function saveState(shiftId) {
      sessionStorage.setItem('tt_presenter_active', '1');
      sessionStorage.setItem('tt_presenter_shift', shiftId);
      var grid = document.getElementById('pPresenterGrid');
      if (grid) sessionStorage.setItem('tt_presenter_scroll', grid.scrollTop);
    }

    // Auto-restore after page reload (post-assign/unassign)
    document.addEventListener('DOMContentLoaded', function() {
      if (sessionStorage.getItem('tt_presenter_active') === '1') {
        sessionStorage.removeItem('tt_presenter_active');
        var savedScroll = parseInt(sessionStorage.getItem('tt_presenter_scroll') || '0');
        var savedShift  = parseInt(sessionStorage.getItem('tt_presenter_shift')  || '0');
        sessionStorage.removeItem('tt_presenter_scroll');
        sessionStorage.removeItem('tt_presenter_shift');
        setTimeout(function() {
          enter();
          var grid = document.getElementById('pPresenterGrid');
          if (grid && savedScroll) grid.scrollTop = savedScroll;
          if (savedShift) openModal(savedShift);
        }, 80);
      }
    });

    function filterTeachers(val) {
      var v = val.toLowerCase();
      document.querySelectorAll('#pPresenterModalBox .pp-ti').forEach(function(el) {
        el.style.display = (el.dataset.name || '').includes(v) ? '' : 'none';
      });
    }

    return { enter: enter, exit: exit, openModal: openModal, closeModal: closeModal, saveState: saveState, filterTeachers: filterTeachers };
  })();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
