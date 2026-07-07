<?php
// filepath: c:\xampp\htdocs\timetable\app\timetable_optimizer.php

/**
 * หา "กลุ่มวิชา" (family) ของวิชาหลัก/เสริม
 * วิชาที่ลงท้ายด้วย "หลัก" หรือ "เสริม" ถือว่าอยู่กลุ่มเดียวกันถ้า base ตรงกัน
 *   เช่น "คณิตหลัก" / "คณิตเสริม" → base = "คณิต"
 *        "วิทย์หลัก" / "วิทย์เสริม" → base = "วิทย์"
 * ใช้กันไม่ให้คาบหลัก/เสริมของวิชาเดียวกันมาเรียนติดกัน (เด็กได้พักสมอง)
 *
 * รองรับ label ทั้งแบบ "NAME" และ "CODE - NAME" (จะดูเฉพาะส่วนชื่อวิชา)
 * คืน null ถ้าวิชานี้ไม่ใช่วิชาหลัก/เสริม
 */
function tt_subject_family(?string $label): ?string {
  if ($label === null || $label === '') return null;
  // ถ้าเป็นรูปแบบ "CODE - NAME" ให้ใช้เฉพาะส่วนชื่อ (หลัง ' - ' ตัวสุดท้าย)
  $name = $label;
  $sepPos = mb_strrpos($label, ' - ');
  if ($sepPos !== false) {
    $name = mb_substr($label, $sepPos + 3);
  }
  $name = trim($name);

  foreach (['หลัก', 'เสริม'] as $suffix) {
    $len = mb_strlen($suffix);
    if (mb_substr($name, -$len) === $suffix) {
      $base = trim(mb_substr($name, 0, mb_strlen($name) - $len));
      if ($base !== '') return $base;
    }
  }
  return null;
}

function buildTimetableMaps($pdo, $year_id, $term_no) {
  // ✅ ดึงข้อมูลทั้งหมดมาทำ Map
  $existingSlots = $pdo->prepare("
    SELECT ts.day_of_week, ts.period_no, ts.class_id, ts.room_id,
           ts.teacher_id AS primary_teacher_id,
           st.teacher_id AS map_teacher_id,
           ts.subject_name,
           c.grade_label,
           COALESCE(r.building,'') AS class_building
    FROM timetable_slots ts
    LEFT JOIN timetable_slot_teachers st ON st.slot_id = ts.id
    LEFT JOIN classes c ON c.id = ts.class_id
    LEFT JOIN rooms r ON r.id = c.homeroom_room_id
    WHERE ts.academic_year_id = ? AND ts.term_no = ?
  ");
  $existingSlots->execute([$year_id, $term_no]);

  $classBusyMap = [];
  $teacherBusyMap = [];
  $roomBusyMap = [];
  $subjectDayMap = [];
  $subjectTeacherDayMap = [];
  // classPeriodSubject[$day][$period][$cid] = subject_name (label)
  // ใช้ตรวจว่าคาบข้างเคียงเป็นวิชากลุ่มเดียวกัน (หลัก/เสริม) หรือไม่
  $classPeriodSubjectMap = [];
  // teacherPeriodBuilding[$day][$period][$tid] = building (จาก homeroom_room_id → rooms.building)
  // ใช้ตรวจ cross-building ว่าครูสอนในอาคารไหนจริงๆ ในคาบนั้น
  $teacherPeriodBuildingMap = [];
  // teacherActivityBuilding[$day][$period][$tid] = building (จาก activity_groups.room_id → rooms.building)
  $teacherActivityBuildingMap = [];

  foreach ($existingSlots->fetchAll() as $slot) {
    $d = (int)$slot['day_of_week'];
    $p = (int)$slot['period_no'];
    $cid = (int)$slot['class_id'];
    $rid = $slot['room_id'] ? (int)$slot['room_id'] : null;
    $tid = $slot['map_teacher_id'] ? (int)$slot['map_teacher_id'] : ($slot['primary_teacher_id'] ? (int)$slot['primary_teacher_id'] : null);
    $subj = $slot['subject_name'];
    $building = (string)($slot['class_building'] ?? '');
    
    $classBusyMap[$d][$p][$cid] = true;
    if ($rid) $roomBusyMap[$d][$p][$rid] = true;
    if ($tid) {
      $teacherBusyMap[$d][$p][$tid] = true;
      if ($building !== '') $teacherPeriodBuildingMap[$d][$p][$tid] = $building;
    }
    if ($subj) $subjectDayMap[$d][$cid][$subj] = true;
    if ($subj && $tid) $subjectTeacherDayMap[$d][$cid][$subj][$tid] = true;
    if ($subj) $classPeriodSubjectMap[$d][$p][$cid] = $subj;
  }

  // ✅ ดึงกิจกรรมทั้งหมด (รวม building ของสถานที่กิจกรรม)
  $activitySlots = $pdo->prepare("
    SELECT ag.day_of_week, ag.period_no, ac.class_id, at.teacher_id,
           COALESCE(r.building,'') AS building
    FROM activity_groups ag
    LEFT JOIN activity_classes ac ON ac.activity_id = ag.id
    LEFT JOIN activity_teachers at ON at.activity_id = ag.id
    LEFT JOIN rooms r ON r.id = ag.room_id
    WHERE ag.academic_year_id = ? AND ag.term_no = ?
  ");
  $activitySlots->execute([$year_id, $term_no]);

  $classActivityMap = [];
  $teacherActivityMap = [];

  foreach ($activitySlots->fetchAll() as $act) {
    $d = (int)$act['day_of_week'];
    $p = (int)$act['period_no'];
    if ($act['class_id']) $classActivityMap[$d][$p][(int)$act['class_id']] = true;
    if ($act['teacher_id']) {
      $teacherActivityMap[$d][$p][(int)$act['teacher_id']] = true;
      if ($act['building'] !== '') $teacherActivityBuildingMap[$d][$p][(int)$act['teacher_id']] = $act['building'];
    }
  }

  // ✅ ดึง Teacher Constraints (แบบ row-based)
  $constraintsStmt = $pdo->prepare("
    SELECT teacher_id, day_of_week, period_no, reason
    FROM teacher_constraints
    WHERE academic_year_id = ? AND term_no = ?
  ");
  $constraintsStmt->execute([$year_id, $term_no]);
  
  $teacherConstraints = [];
  
  foreach ($constraintsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tid = (int)$row['teacher_id'];
    $day = $row['day_of_week'] ? (int)$row['day_of_week'] : null;
    $period = $row['period_no'] ? (int)$row['period_no'] : null;
    
    if (!isset($teacherConstraints[$tid])) {
      $teacherConstraints[$tid] = [
        'unavailable_days' => [],
        'unavailable_periods' => []
      ];
    }
    
    // ถ้ามี day_of_week แต่ไม่มี period_no = ครูไม่ว่างทั้งวัน
    if ($day && !$period) {
      $teacherConstraints[$tid]['unavailable_days'][] = $day;
    }
    
    // ถ้ามีทั้ง day_of_week และ period_no = ครูไม่ว่างคาบนั้น ๆ
    if ($day && $period) {
      if (!isset($teacherConstraints[$tid]['unavailable_slots'])) {
        $teacherConstraints[$tid]['unavailable_slots'] = [];
      }
      $teacherConstraints[$tid]['unavailable_slots'][$day][$period] = true;
    }
    
    // ถ้าไม่มี day_of_week แต่มี period_no = ครูไม่ว่างคาบนี้ทุกวัน
    if (!$day && $period) {
      $teacherConstraints[$tid]['unavailable_periods'][] = $period;
    }
  }

  // ✅ ดึงเวรครู (duty) เพื่อให้ตัวจัดอัตโนมัติหลบคาบที่ครูติดเวร
  // teacherDuty[$day][$period][$tid] = true
  $teacherDutyMap = [];
  try {
    $dutyStmt = $pdo->prepare("
      SELECT dms.day_of_week, dmts.period_no, dta.teacher_id
      FROM duty_term_assignments dta
      JOIN duty_master_shifts dms ON dms.id = dta.duty_master_shift_id
      JOIN duty_master_time_slots dmts ON dmts.id = dms.duty_time_slot_id
      WHERE dta.academic_year_id = ? AND dta.term_no = ? AND dmts.period_no IS NOT NULL
    ");
    $dutyStmt->execute([$year_id, $term_no]);
    foreach ($dutyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $d = (int)$row['day_of_week'];
      $p = (int)$row['period_no'];
      $tid = (int)$row['teacher_id'];
      if ($tid) $teacherDutyMap[$d][$p][$tid] = true;
    }
  } catch (Throwable $e) {
    // ถ้าไม่มีตารางเวร (เช่นการติดตั้งเก่า) ให้ข้ามไป
    $teacherDutyMap = [];
  }

  return [
    'classBusy' => $classBusyMap,
    'teacherBusy' => $teacherBusyMap,
    'teacherDuty' => $teacherDutyMap,
    'roomBusy' => $roomBusyMap,
    'subjectDay' => $subjectDayMap,
    'subjectTeacherDay' => $subjectTeacherDayMap,
    'classPeriodSubject' => $classPeriodSubjectMap,
    'classActivity' => $classActivityMap,
    'teacherActivity' => $teacherActivityMap,
    'teacherConstraints' => $teacherConstraints,
    'teacherPeriodBuilding' => $teacherPeriodBuildingMap,
    'teacherActivityBuilding' => $teacherActivityBuildingMap,
  ];
}

/**
 * อัปเดต maps หลัง insert 1 คาบ
 * $building: ชื่ออาคารของห้องเรียนประจำ (homeroom_room_id → rooms.building) ใช้ตรวจ cross-building
 */
function updateMapsAfterInsert(&$maps, $day, $period, $class_id, $teacher_ids, $room_id, $subject_name, $building = '') {
  $maps['classBusy'][$day][$period][$class_id] = true;
  
  foreach ($teacher_ids as $tid) {
    $maps['teacherBusy'][$day][$period][$tid] = true;
    if ($building !== '') {
      $maps['teacherPeriodBuilding'][$day][$period][$tid] = $building;
    }
  }
  
  if ($room_id) {
    $maps['roomBusy'][$day][$period][$room_id] = true;
  }
  
  $maps['subjectDay'][$day][$class_id][$subject_name] = true;
  $maps['classPeriodSubject'][$day][$period][$class_id] = $subject_name;

  foreach ($teacher_ids as $tid) {
    $maps['subjectTeacherDay'][$day][$class_id][$subject_name][(int)$tid] = true;
  }
}