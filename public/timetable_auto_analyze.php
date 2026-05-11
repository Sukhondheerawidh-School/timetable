<?php
// filepath: c:\xampp\htdocs\timetable\public\timetable_auto_analyze.php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/helpers.php';
requireLogin();

$currentUser = currentUser();
if (($currentUser['role'] ?? '') !== 'admin') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'คุณไม่มีสิทธิ์ใช้งานฟีเจอร์นี้']);
    exit;
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];
$year_id = (int)($input['year_id'] ?? $_GET['year_id'] ?? 0);
$term_no = (int)($input['term_no'] ?? $_GET['term_no'] ?? 1);

if (!$year_id) { echo json_encode(['error' => 'year_id ไม่ถูกต้อง']); exit; }

// ─────────────────────────────────────────────────────────────────
// 1. ดึงจำนวนคาบทั้งหมดต่อวัน (period_slots)
// ─────────────────────────────────────────────────────────────────
$periods = $pdo->query("SELECT period_no FROM period_slots ORDER BY period_no")
               ->fetchAll(PDO::FETCH_COLUMN);
$totalPeriods = count($periods);   // เช่น 9 คาบ/วัน

// ─────────────────────────────────────────────────────────────────
// 1b. คาบพัก (grade_breaks) → จำนวนคาบพัก/วัน ต่อ grade_label
// ─────────────────────────────────────────────────────────────────
$gradeBreaksRaw = $pdo->query("SELECT grade_label, period_no FROM grade_breaks")->fetchAll();
$gradeBreaksCount = [];   // grade_label => จำนวนคาบพักต่อวัน
foreach ($gradeBreaksRaw as $b) {
    $gl = $b['grade_label'];
    $gradeBreaksCount[$gl] = ($gradeBreaksCount[$gl] ?? 0) + 1;
}

// ─────────────────────────────────────────────────────────────────
// 2. ดึงชั้นเรียนทั้งหมดพร้อม has_saturday / has_sunday
// ─────────────────────────────────────────────────────────────────
$classInfoStmt = $pdo->query("SELECT id, class_name, grade_label, has_saturday, has_sunday FROM classes");
$classInfo = [];   // cid => {class_name, grade_label, effective_slots}
foreach ($classInfoStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $rawDays      = 5 + (int)$c['has_saturday'] + (int)$c['has_sunday'];
    // นักเรียนต้องได้หยุดอย่างน้อย 1 วัน/สัปดาห์ → ตัดอยุ่ที่สูงสุด 6 วัน
    $effectiveDays = min($rawDays, 6);
    $breaksPerDay  = $gradeBreaksCount[$c['grade_label']] ?? 0;
    $slotsPerDay   = $totalPeriods - $breaksPerDay;   // คาบสอนจริงต่อวัน
    $classInfo[(int)$c['id']] = [
        'class_name'      => $c['class_name'],
        'grade_label'     => $c['grade_label'],
        'total_days'      => $effectiveDays,
        'breaks_per_day'  => $breaksPerDay,
        'slots_per_day'   => $slotsPerDay,
        'effective_slots' => $effectiveDays * $slotsPerDay,   // ช่องจริงที่ต้องเติม
    ];
}

// ─────────────────────────────────────────────────────────────────
// 3. Teaching loads ของปี/เทอม
// ─────────────────────────────────────────────────────────────────
$loadsStmt = $pdo->prepare("
    SELECT
        tl.id, tl.class_id, tl.teacher_id, tl.subject_id,
        tl.periods_per_week, tl.room_id,
        c.class_name, c.grade_label,
        CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
        s.subject_name,
        CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name
             ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS label,
        r.room_name
    FROM teaching_loads tl
    JOIN classes  c ON c.id = tl.class_id
    JOIN teachers t ON t.id = tl.teacher_id
    JOIN subjects s ON s.id = tl.subject_id
    LEFT JOIN rooms r ON r.id = tl.room_id
    WHERE tl.academic_year_id = ? AND tl.term_no = ?
");
$loadsStmt->execute([$year_id, $term_no]);
$loads = $loadsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$loads) {
    echo json_encode(['warnings' => [], 'errors' => [], 'info' => 'ไม่มีกำลังสอนในปี/เทอมนี้']);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// 4. Teacher constraints → คาบที่ครูไม่ว่าง
//    ตรวจจาก 5 วัน (จันทร์-ศุกร์) เพราะครูไม่สอนเสาร์-อาทิตย์
// ─────────────────────────────────────────────────────────────────
$conStmt = $pdo->prepare("
    SELECT teacher_id, day_of_week, period_no
    FROM teacher_constraints
    WHERE academic_year_id = ? AND term_no = ?
");
$conStmt->execute([$year_id, $term_no]);
$teacherBlockedSlots = [];   // tid => ["day-per" => true]
foreach ($conStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tid = (int)$row['teacher_id'];
    $day = $row['day_of_week'] ? (int)$row['day_of_week'] : null;
    $per = $row['period_no']   ? (int)$row['period_no']   : null;
    if ($day && !$per) {
        foreach ($periods as $p) {
            $teacherBlockedSlots[$tid]["$day-$p"] = true;
        }
    } elseif ($day && $per) {
        $teacherBlockedSlots[$tid]["$day-$per"] = true;
    } elseif (!$day && $per) {
        for ($d = 1; $d <= 5; $d++) {
            $teacherBlockedSlots[$tid]["$d-$per"] = true;
        }
    }
}
$teacherBlockedCount = [];
foreach ($teacherBlockedSlots as $tid => $slots) {
    $teacherBlockedCount[$tid] = count($slots);
}

// ─────────────────────────────────────────────────────────────────
// 5. Activity slots
//    - is_all_day = 1, period_no = NULL → กิจกรรมเต็มวัน = totalPeriods คาบในวันนั้น
//    - is_all_day = 0 → 1 คาบ (day, period) ปกติ
// ─────────────────────────────────────────────────────────────────
$actStmt = $pdo->prepare("
    SELECT ag.day_of_week, ag.period_no, ag.is_all_day,
           at.teacher_id, ag.room_id, ac.class_id
    FROM activity_groups ag
    LEFT JOIN activity_teachers at ON at.activity_id = ag.id
    LEFT JOIN activity_classes  ac ON ac.activity_id = ag.id
    WHERE ag.academic_year_id = ? AND ag.term_no = ?
");
$actStmt->execute([$year_id, $term_no]);

$teacherActivitySlots  = [];   // tid => ["day-per" => true]
$roomActivitySlots     = [];   // rid => ["day-per" => true]
// สำหรับ class นับจำนวนคาบ (รวม all-day)
$classActivityPeriods  = [];   // cid => count of activity periods

foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $act) {
    $d        = (int)$act['day_of_week'];
    $isAllDay = (bool)$act['is_all_day'];
    $tid      = $act['teacher_id'] ? (int)$act['teacher_id'] : null;
    $rid      = $act['room_id']    ? (int)$act['room_id']    : null;
    $cid      = $act['class_id']   ? (int)$act['class_id']   : null;

    if ($isAllDay) {
        // เต็มวัน: ครูและห้องถูก block ทุกคาบในวันนั้น
        foreach ($periods as $p) {
            $key = "$d-$p";
            if ($tid) $teacherActivitySlots[$tid][$key] = true;
            if ($rid) $roomActivitySlots[$rid][$key]    = true;
        }
        // class นับ slots_per_day คาบ (หักคาบพักออกแล้ว) ต่อ 1 กิจกรรมเต็มวัน
        // แต่ row นี้อาจซ้ำถ้า JOIN กับ teachers (หลายครู) → ใช้ key ป้องกัน
        if ($cid) {
            if (!isset($classActivityPeriods[$cid])) $classActivityPeriods[$cid] = [];
            $classActivityPeriods[$cid]["allday-$d"] = $classInfo[$cid]['slots_per_day'] ?? ($totalPeriods - 1);
        }
    } else {
        $p   = (int)$act['period_no'];
        $key = "$d-$p";
        if ($tid) $teacherActivitySlots[$tid][$key] = true;
        if ($rid) $roomActivitySlots[$rid][$key]    = true;
        if ($cid) {
            if (!isset($classActivityPeriods[$cid])) $classActivityPeriods[$cid] = [];
            $classActivityPeriods[$cid][$key] = 1;
        }
    }
}
// รวมคาบกิจกรรมต่อ class
$classActivityCount = [];   // cid => total activity period count
foreach ($classActivityPeriods as $cid => $slots) {
    $classActivityCount[$cid] = array_sum($slots);
}

// ─────────────────────────────────────────────────────────────────
// 6. สรุปภาระการสอนต่อครู / ห้องเฉพาะ / ห้องเรียน
// ─────────────────────────────────────────────────────────────────
$teacherDemand = [];   // tid => sum periods_per_week
$teacherName   = [];   // tid => name
$roomDemand    = [];   // rid => sum periods_per_week
$roomName      = [];   // rid => name
$classDemand   = [];   // cid => sum periods_per_week (teaching only)

foreach ($loads as $L) {
    $tid = (int)$L['teacher_id'];
    $cid = (int)$L['class_id'];
    $pw  = (int)$L['periods_per_week'];

    $teacherDemand[$tid] = ($teacherDemand[$tid] ?? 0) + $pw;
    if (!isset($teacherName[$tid])) $teacherName[$tid] = $L['teacher_name'];

    if ($L['room_id']) {
        $rid = (int)$L['room_id'];
        $roomDemand[$rid] = ($roomDemand[$rid] ?? 0) + $pw;
        $roomName[$rid]   = $L['room_name'] ?? "ห้อง #$rid";
    }

    $classDemand[$cid] = ($classDemand[$cid] ?? 0) + $pw;
}

// ─────────────────────────────────────────────────────────────────
// 7. วิเคราะห์
// ─────────────────────────────────────────────────────────────────
$errors   = [];
$warnings = [];

// ── 7A. ตรวจห้องเรียน: กำลังสอน + กิจกรรม ต้องพอดีกับช่องจริง ──
foreach ($classDemand as $cid => $teaching) {
    $info           = $classInfo[$cid] ?? null;
    if (!$info) continue;
    $effectiveSlots = $info['effective_slots'];   // วัน × (คาบ/วัน − คาบพัก)
    $activity       = $classActivityCount[$cid] ?? 0;
    $total          = $teaching + $activity;

    if ($total > $effectiveSlots) {
        $over = $total - $effectiveSlots;
        $errors[] = [
            'type'   => 'class_overload',
            'icon'   => '📚',
            'title'  => "ห้อง {$info['class_name']} กำลังสอน+กิจกรรมเกินกว่าช่องว่าง",
            'detail' => "กำลังสอน {$teaching} + กิจกรรม {$activity} = {$total} คาบ แต่มีช่องจริง {$effectiveSlots} ช่อง ({$info['total_days']} วัน × {$info['slots_per_day']} คาบ) — เกิน {$over} คาบ",
        ];
    } elseif ($total < $effectiveSlots) {
        $short = $effectiveSlots - $total;
        $warnings[] = [
            'type'   => 'class_underload',
            'icon'   => '📋',
            'title'  => "ห้อง {$info['class_name']} กำลังสอน+กิจกรรมยังไม่ครบ",
            'detail' => "กำลังสอน {$teaching} + กิจกรรม {$activity} = {$total} คาบ แต่มีช่องจริง {$effectiveSlots} ช่อง ({$info['total_days']} วัน × {$info['slots_per_day']} คาบ) — ขาดอีก {$short} คาบ",
        ];
    }
    // total == effectiveSlots → พอดี ไม่ต้องเตือน
}

// ── 7B. ตรวจครู: demand ต้องไม่เกินคาบว่าง ─────────────────────
$teacherSlotsPerWeek = 5 * $totalPeriods;   // ครูสอนจันทร์-ศุกร์เท่านั้น
foreach ($teacherDemand as $tid => $demand) {
    $blocked   = $teacherBlockedCount[$tid] ?? 0;
    $activity  = count($teacherActivitySlots[$tid] ?? []);
    $available = max(0, $teacherSlotsPerWeek - $blocked - $activity);

    if ($demand > $available) {
        $errors[] = [
            'type'   => 'teacher_overload',
            'icon'   => '👨‍🏫',
            'title'  => "ครู {$teacherName[$tid]} มีภาระสอนเกินกว่าคาบว่าง",
            'detail' => "ต้องการ {$demand} คาบ แต่มีคาบว่างเพียง {$available} คาบ (ทั้งหมด {$teacherSlotsPerWeek}, constraint {$blocked}, กิจกรรม {$activity})",
        ];
    }
}

// ── 7C. ตรวจห้องเฉพาะ: demand ต้องไม่เกินคาบว่าง ───────────────
foreach ($roomDemand as $rid => $demand) {
    // ห้องเฉพาะใช้ได้กี่วัน? ใช้ 5 วันปกติ (ครูต้องสอนในวันเรียน)
    $roomTotalSlots = 5 * $totalPeriods;
    $activity  = count($roomActivitySlots[$rid] ?? []);
    $available = max(0, $roomTotalSlots - $activity);

    if ($demand > $available) {
        $errors[] = [
            'type'   => 'room_overload',
            'icon'   => '🏫',
            'title'  => "ห้อง {$roomName[$rid]} ถูกจองเกินกว่าที่รับได้",
            'detail' => "กำลังสอนที่ต้องใช้ห้องนี้รวม {$demand} คาบ แต่มีคาบว่างเพียง {$available} คาบ (กิจกรรมจอง {$activity} คาบ)",
        ];
    }
}

// ── 7D. ไม่มีกำลังสอนเลย ───────────────────────────────────────
$totalDemand = array_sum($teacherDemand);
if ($totalDemand === 0) {
    $errors[] = [
        'type'   => 'no_loads',
        'icon'   => '❌',
        'title'  => 'ไม่มีกำลังสอนในปี/เทอมนี้',
        'detail' => 'กรุณาสร้างกำลังสอนก่อนจัดตาราง',
    ];
}

echo json_encode([
    'errors'   => $errors,
    'warnings' => $warnings,
    'summary'  => [
        'total_loads'         => count($loads),
        'total_demand'        => $totalDemand,
        'total_slots_per_week'=> 5 * $totalPeriods,   // reference สำหรับ Mon-Fri
        'periods_per_day'     => $totalPeriods,
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
