<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';

requireLogin();

/* =========================
   Helpers
========================= */
function th_dow($n){ static $m=[1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์']; return $m[(int)$n] ?? '-'; }

// ✅ แก้ไข: ฟังก์ชันสำหรับแสดงชื่อวิชาเต็ม (มีรหัส) - ใช้สำหรับ title/tooltip
function subj_label($code,$name){ 
  $code=trim((string)$code); 
  return $code!=='' ? ($code.' - '.$name) : $name; 
}

// ✅ เพิ่ม: ฟังก์ชันสำหรับแสดงเฉพาะชื่อวิชา (ไม่มีรหัส)
function subj_name_only($name){ 
  return trim((string)$name); 
}

// ✅ แก้ไข: ฟังก์ชันสำหรับ dropdown - แสดงเฉพาะชื่อวิชา
function load_label_for_dropdown($view,$ld){
  $label = subj_name_only($ld['subject_name']); // ✅ เอาเฉพาะชื่อวิชา
  if ($view==='class') $label .= ' / ครู '.$ld['first_name'].' '.$ld['last_name'];
  else $label .= ' / ห้อง '.$ld['class_name'];
  return $label;
}

// ✅ เพิ่ม: ฟังก์ชันสำหรับ title (tooltip) - แสดงข้อมูลเต็ม
function load_label_full($view,$ld){
  $label = subj_label($ld['subject_code'] ?? '', $ld['subject_name']); // มีรหัสวิชา
  if ($view==='class') $label .= ' / ครู '.$ld['first_name'].' '.$ld['last_name'];
  else $label .= ' / ห้อง '.$ld['class_name'];
  return $label;
}

function fetch_teachers_for_slots(PDO $pdo, array $slotIds) {
  if (!$slotIds) return [];
  $in = implode(',', array_map('intval',$slotIds));
  $sql = "SELECT st.slot_id, t.first_name, t.last_name
          FROM timetable_slot_teachers st
          JOIN teachers t ON t.id=st.teacher_id
          WHERE st.slot_id IN ($in)
          ORDER BY t.first_name, t.last_name";
  $map = [];
  foreach ($pdo->query($sql) as $r) $map[(int)$r['slot_id']][] = $r['first_name'].' '.$r['last_name'];
  return $map;
}

$user = currentUser();
$isAdmin = ($user['role'] ?? '') === 'admin';

/* =========================
   Filters
========================= */
$view = $_GET['view'] ?? 'class';
$view = in_array($view, ['class','teacher'], true) ? $view : 'class';

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();

// ✅ หาปีที่ตั้งเป็น Active (is_active = 1) ก่อน
$activeYearId = 0;
foreach ($years as $y) {
  if ($y['is_active']) {
    $activeYearId = (int)$y['id'];
    break;
  }
}
// ถ้าไม่มีปี Active ให้ใช้ปีแรกสุด
if (!$activeYearId && !empty($years)) {
  $activeYearId = (int)$years[0]['id'];
}

// ใช้ปี Active เป็นค่าเริ่มต้น
$year_id = (int)($_GET['year_id'] ?? $activeYearId);

// ✅ กำหนดค่าเริ่มต้นเทอมตามเดือนปัจจุบัน (อิงจากเทอมที่กำหนดไว้ของปี)
if (isset($_GET['term_no']) && $_GET['term_no'] !== '') {
  $term_no = (int)$_GET['term_no'];
  $term_no = tt_validate_term_no($pdo, $year_id, $term_no);
} else {
  $term_no = tt_default_term_no_for_year($pdo, $year_id);
  $term_no = tt_validate_term_no($pdo, $year_id, $term_no);
}

$termOptions = tt_terms_list($pdo, $year_id);

// ดึงกลุ่มสาระจากฐานข้อมูล
$groupMap = teacher_group_options(false); // รวมทั้งที่ปิดและเปิด

$classes = $pdo->query('SELECT id, class_name, grade_label, homeroom_room_id, has_saturday, has_sunday FROM classes ORDER BY class_name')
          ->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

$teachers= $pdo->query('SELECT id, first_name, last_name, subject_group FROM teachers ORDER BY first_name, last_name')->fetchAll();

$teacher_group = $_GET['teacher_group'] ?? 'all';

$periods = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll();
if (!$periods) { flash_set('error','ยังไม่ได้กำหนดคาบเรียน'); redirect('periods.php'); }

// ✅ เก็บคาบสูงสุดสำหรับเช็คการล้น
$maxPeriod = max(array_map(fn($p) => (int)$p['period_no'], $periods));

if ($view==='class'){ 
  $class_id   = (int)($_GET['class_id']??(array_key_first($classes) ?? 0)); 
  $teacher_id = 0; 
} else {
  $teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
  if ($teacher_id === 0) {
    $first = null;
    foreach ($teachers as $t) {
      if ($teacher_group==='all' || (string)$t['subject_group']===(string)$teacher_group) { $first=$t; break; }
    }
    if ($first) $teacher_id = (int)$first['id'];
  }
  $class_id = 0;
}

$baseQuery=['view'=>$view,'year_id'=>$year_id,'term_no'=>$term_no];
if ($view==='class') $baseQuery['class_id']=$class_id;
else { $baseQuery['teacher_group']=$teacher_group; $baseQuery['teacher_id']=$teacher_id; }
$returnUrl='timetable.php?'.http_build_query($baseQuery);

$rooms = $pdo->query('SELECT id, room_name FROM rooms ORDER BY room_name')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);

// ✅ เพิ่ม: ดึงข้อมูลว่าชั้นนี้เรียนเสาร์/อาทิตย์หรือไม่
$has_saturday = false;
$has_sunday = false;

if ($view === 'class' && $class_id) {
  $classInfo = $pdo->prepare('SELECT has_saturday, has_sunday FROM classes WHERE id = ?');
  $classInfo->execute([$class_id]);
  $classData = $classInfo->fetch();
  if ($classData) {
    $has_saturday = (bool)$classData['has_saturday'];
    $has_sunday = (bool)$classData['has_sunday'];
  }
} elseif ($view === 'teacher' && $teacher_id) {
  // ✅ ใหม่: ตรวจสอบว่าครูคนนี้สอนชั้นที่มีเรียนเสาร์/อาทิตย์หรือไม่
  $teacherClassInfo = $pdo->prepare("
    SELECT DISTINCT c.has_saturday, c.has_sunday
    FROM teaching_loads tl
    JOIN classes c ON c.id = tl.class_id
    WHERE tl.academic_year_id = ? 
      AND tl.term_no = ? 
      AND tl.teacher_id = ?
      AND (c.has_saturday = 1 OR c.has_sunday = 1)
  ");
  $teacherClassInfo->execute([$year_id, $term_no, $teacher_id]);
  $teacherClasses = $teacherClassInfo->fetchAll(PDO::FETCH_ASSOC);
  
  // ถ้ามีชั้นไหนเรียนเสาร์/อาทิตย์ ให้แสดงคอลัมน์นั้น
  foreach ($teacherClasses as $tc) {
    if ((int)$tc['has_saturday'] === 1) $has_saturday = true;
    if ((int)$tc['has_sunday'] === 1) $has_sunday = true;
  }
}

/* =========================
   POST (add / delete)
========================= */
$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';
    if ($action==='add') {
      try {
        $load_id   = (int)($_POST['load_id'] ?? 0);
        $day       = (int)($_POST['day_of_week'] ?? 0);
        $period_no = (int)($_POST['period_no'] ?? 0);
        $span      = max(1,(int)($_POST['span'] ?? 1));
        $picked_room_id = isset($_POST['room_id']) && $_POST['room_id']!=='' ? (int)$_POST['room_id'] : null;
        $force_add = isset($_POST['force_add']) && $_POST['force_add'] === '1'; // ✅ เพิ่ม: รับค่า force_add

        if (!$load_id || !$day || !$period_no) throw new Exception('กรอกข้อมูลไม่ครบ');

        // ✅ **เช็คว่าคาบที่จะลงไม่เกินขอบเขต**
        if ($period_no + $span - 1 > $maxPeriod) {
          throw new Exception('คาบติดกันเกินขอบเขต (คาบสูงสุดคือ '.$maxPeriod.')');
        }

        $st=$pdo->prepare('
          SELECT tl.*, s.subject_code, s.subject_name, c.class_name, c.grade_label, c.homeroom_room_id, c.has_saturday, c.has_sunday
          FROM teaching_loads tl
          JOIN subjects s ON s.id=tl.subject_id
          JOIN classes  c ON c.id=tl.class_id
          WHERE tl.id=? AND tl.academic_year_id=? AND tl.term_no=?');
        $st->execute([$load_id,$year_id,$term_no]);
        $L=$st->fetch();
        if(!$L) throw new Exception('ไม่พบกำลังสอน');

        // ✅ เพิ่ม: ตรวจสอบว่าชั้นนี้เรียนวันที่เลือกหรือไม่
        $class_has_saturday = (int)$L['has_saturday'] === 1;
        $class_has_sunday = (int)$L['has_sunday'] === 1;
        
        if ($day === 6 && !$class_has_saturday) {
          throw new Exception('ห้อง ' . $L['class_name'] . ' ไม่ได้เรียนวันเสาร์');
        }
        if ($day === 7 && !$class_has_sunday) {
          throw new Exception('ห้อง ' . $L['class_name'] . ' ไม่ได้เรียนวันอาทิตย์');
        }

        $class_of = (int)$L['class_id'];
        $grade    = $L['grade_label'];
        $subject  = subj_label($L['subject_code'],$L['subject_name']);
        $leadId   = (int)$L['teacher_id']; // ครูหลัก

        // ใช้ห้อง: ตามที่เลือก > ห้องจากกำลังสอน > NULL (เพื่อไป fallback ตอนแสดง)
        $default_room_id = !empty($L['room_id']) ? (int)$L['room_id'] : null;
        $room_id_to_use  = $picked_room_id ?: $default_room_id;

        // ครูร่วม (จาก co_teaching_pairs)
        $teacher_ids = $leadId ? [$leadId] : [];
        $pairStmt = $pdo->prepare("
          SELECT DISTINCT
            CASE WHEN p.main_load_id=? THEN p.co_load_id
                 WHEN p.co_load_id=? THEN p.main_load_id END AS other_load_id
          FROM co_teaching_pairs p
          WHERE p.year_id=? AND p.term_no=? AND p.class_id=? AND p.subject_id=? AND (p.main_load_id=? OR p.co_load_id=?)
        ");
        $pairStmt->execute([$load_id,$load_id,$year_id,$term_no,(int)$L['class_id'],(int)$L['subject_id'],$load_id,$load_id]);
        $paired_load_ids = array_map('intval', array_filter($pairStmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($paired_load_ids) {
          $getOtherTeacher = $pdo->prepare("SELECT teacher_id FROM teaching_loads WHERE id=? LIMIT 1");
          foreach ($paired_load_ids as $plid) {
            $getOtherTeacher->execute([$plid]);
            $tid = (int)$getOtherTeacher->fetchColumn();
            if ($tid) $teacher_ids[] = $tid;
          }
        }
        $teacher_ids = array_values(array_unique($teacher_ids));
        if (!$teacher_ids) throw new Exception('กำลังสอนนี้ยังไม่ได้กำหนดครู');

        $qCnt=$pdo->prepare("
          SELECT COUNT(DISTINCT ts.id)
          FROM timetable_slots ts
          JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=?
          WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.class_id=? AND ts.subject_name=?
        ");
        $qCnt->execute([$leadId,$year_id,$term_no,$class_of,$subject]);
        $used=(int)$qCnt->fetchColumn();
        if ($used >= (int)$L['periods_per_week']) throw new Exception('ครบกำลังสอนแล้ว ('.$used.'/'.$L['periods_per_week'].')');

        $chkBreak=$pdo->prepare('SELECT 1 FROM grade_breaks WHERE grade_label=? AND period_no=? LIMIT 1');
        $chkDup  =$pdo->prepare('SELECT id FROM timetable_slots WHERE academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? AND class_id=? LIMIT 1');
        $chkCons=$pdo->prepare('SELECT 1 FROM teacher_constraints WHERE teacher_id=? AND academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? LIMIT 1');
        $chkTT  =$pdo->prepare('SELECT 1 FROM timetable_slots ts WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week=? AND ts.period_no=? AND ts.teacher_id=? LIMIT 1');
        $chkActT=$pdo->prepare('SELECT 1
                                FROM activity_groups ag JOIN activity_teachers at ON at.activity_id=ag.id
                                WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.day_of_week=? AND ag.period_no=? AND at.teacher_id=? LIMIT 1');
        $chkActC=$pdo->prepare('SELECT 1
                                FROM activity_groups ag JOIN activity_classes ac ON ac.activity_id=ag.id
                                WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.day_of_week=? AND ag.period_no=? AND ac.class_id=? LIMIT 1');

        $chkActC->execute([$year_id,$term_no,$day,$period_no,$class_of]); 
        if ($chkActC->fetch()) throw new Exception('ทับกิจกรรมรวมของชั้น');

        // ✅ **แก้ไข: เช็ควิชาซ้ำในวันเดียวกัน - ถ้าไม่ force ให้ส่งกลับไปยืนยัน**
        $chkSameSubjToday = $pdo->prepare("
          SELECT 1
          FROM timetable_slots ts
          WHERE ts.academic_year_id=? 
            AND ts.term_no=? 
            AND ts.day_of_week=? 
            AND ts.class_id=? 
            AND ts.subject_name=?
          LIMIT 1
        ");
        $chkSameSubjToday->execute([$year_id, $term_no, $day, $class_of, $subject]);
        if ($chkSameSubjToday->fetch() && !$force_add) {
          // ✅ ส่งข้อมูลกลับไปให้ JavaScript แสดง confirm
          $_SESSION['confirm_add_data'] = [
            'load_id' => $load_id,
            'day_of_week' => $day,
            'period_no' => $period_no,
            'span' => $span,
            'room_id' => $picked_room_id,
            'subject' => $subject,
            'day_name' => th_dow($day)
          ];
          flash_set('warning', 'วิชา "'.$subject.'" มีในวัน'.th_dow($day).'แล้ว');
          redirect($returnUrl);
        }

        $insSlot=$pdo->prepare('INSERT INTO timetable_slots
          (academic_year_id,term_no,day_of_week,period_no,class_id,teacher_id,subject_name,room_id,source)
          VALUES (?,?,?,?,?,?,?,?,?)');
        $insMap =$pdo->prepare('INSERT INTO timetable_slot_teachers (slot_id, teacher_id) VALUES (?,?)');

        $pdo->beginTransaction();
        $createdSlotIds = [];
        
        // ✅ ดึงชื่อครูทั้งหมด
        $teacherNames = [];
        if ($teacher_ids) {
          $teacherIn = implode(',', $teacher_ids);
          $teacherStmt = $pdo->query("SELECT id, first_name, last_name FROM teachers WHERE id IN ($teacherIn)");
          foreach ($teacherStmt as $tr) {
            $teacherNames[(int)$tr['id']] = $tr['first_name'] . ' ' . $tr['last_name'];
          }
        }
        
        for ($i=0;$i<$span;$i++) {
          $pp=$period_no+$i;
          
          if ($pp > $maxPeriod) {
            throw new Exception('คาบ '.$pp.' เกินขอบเขต (คาบสูงสุดคือ '.$maxPeriod.')');
          }
          
          $chkBreak->execute([$grade,$pp]); if ($chkBreak->fetch()) throw new Exception('คาบที่เลือกติดคาบพัก');
          $chkDup  ->execute([$year_id,$term_no,$day,$pp,$class_of]); if ($chkDup->fetch()) throw new Exception('มีคาบในช่องนี้อยู่แล้ว');

          foreach ($teacher_ids as $tid) {
            $chkTT->execute([$year_id,$term_no,$day,$pp,$tid]);  if ($chkTT->fetch())  throw new Exception('ครูติดคาบช่วงนี้');
            $chkActT->execute([$year_id,$term_no,$day,$pp,$tid]); if ($chkActT->fetch()) throw new Exception('ครูติดกิจกรรมรวม');
            $chkCons->execute([$tid,$year_id,$term_no,$day,$pp]); if ($chkCons->fetch()) throw new Exception('ครูติดข้อจำกัดช่วงนี้');
          }

          if ($used >= (int)$L['periods_per_week']) throw new Exception('ครบกำลังสอนแล้ว');

          $insSlot->execute([$year_id,$term_no,$day,$pp,$class_of,$leadId,$subject,$room_id_to_use,'manual']);
          $slot_id = (int)$pdo->lastInsertId();
          $createdSlotIds[] = $slot_id;
          
          foreach ($teacher_ids as $tid) $insMap->execute([$slot_id,$tid]);
          $used++;
        }
        $pdo->commit();
        
        // ✅ ลบข้อมูล confirm หลังจากบันทึกสำเร็จ
        unset($_SESSION['confirm_add_data']);
        
        // บันทึก log
        $yearLabel = '';
        foreach ($years as $y) {
          if ((int)$y['id'] === $year_id) {
            $yearLabel = $y['year_label'];
            break;
          }
        }
        
        $roomName = '';
        if ($room_id_to_use && isset($rooms[$room_id_to_use])) {
          $roomName = $rooms[$room_id_to_use];
        }
        
        logActivity(
          'add_timetable_slot',
          'timetable_slots',
          null,
          null,
          [
            'view' => $view,
            'year_label' => $yearLabel,
            'term_no' => $term_no,
            'class_name' => $L['class_name'],
            'day_of_week' => th_dow($day),
            'period_start' => $period_no,
            'period_end' => $period_no + $span - 1,
            'span' => $span,
            'subject' => $subject,
            'teachers' => array_values($teacherNames),
            'room_name' => $roomName ?: '-',
            'source' => 'manual',
            'slot_ids' => $createdSlotIds,
            'forced' => $force_add ? 'yes' : 'no' // ✅ บันทึกว่าเป็นการบังคับลงหรือไม่
          ]
        );
        
        flash_set('success','บันทึกคาบเรียบร้อย'); redirect($returnUrl);
      } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = 'เพิ่มไม่ได้: '.$e->getMessage();
      }
    } elseif ($action==='delete') {
      try {
        $slot_id=(int)($_POST['slot_id'] ?? 0);
        
        // ✅ เพิ่มส่วนนี้ทั้งหมด (ก่อน DELETE)
        $slotStmt = $pdo->prepare("
          SELECT ts.*, c.class_name,
                 GROUP_CONCAT(CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ') as teacher_names
          FROM timetable_slots ts
          JOIN classes c ON c.id = ts.class_id
          LEFT JOIN timetable_slot_teachers st ON st.slot_id = ts.id
          LEFT JOIN teachers t ON t.id = st.teacher_id
          WHERE ts.id = ?
          GROUP BY ts.id
        ");
        $slotStmt->execute([$slot_id]);
        $slotData = $slotStmt->fetch();
        
        if ($slotData) {
          $yearLabel = '';
          foreach ($years as $y) {
            if ((int)$y['id'] === (int)$slotData['academic_year_id']) {
              $yearLabel = $y['year_label'];
              break;
            }
          }
          
          $roomName = '';
          if ($slotData['room_id'] && isset($rooms[$slotData['room_id']])) {
            $roomName = $rooms[$slotData['room_id']];
          }
          
          logDelete('timetable_slots', $slot_id, [
            'view' => $view,
            'year_label' => $yearLabel,
            'term_no' => $slotData['term_no'],
            'class_name' => $slotData['class_name'],
            'day_of_week' => th_dow((int)$slotData['day_of_week']),
            'period_no' => $slotData['period_no'],
            'subject' => $slotData['subject_name'],
            'teachers' => $slotData['teacher_names'] ?? '-',
            'room_name' => $roomName ?: '-',
            'source' => $slotData['source']
          ]);
        }
        
        $pdo->prepare('DELETE FROM timetable_slots WHERE id=?')->execute([$slot_id]);
        flash_set('success','ลบคาบแล้ว'); redirect($returnUrl);
      } catch(Throwable $e) {
        $err='ลบไม่สำเร็จ: '.$e->getMessage();
      }
    }
  }
}

// ✅ เพิ่ม: เช็คว่ามีข้อมูล confirm หรือไม่
$confirmData = $_SESSION['confirm_add_data'] ?? null;
unset($_SESSION['confirm_add_data']);

/* =========================
   Fetch slots for view
========================= */
if ($view==='class') {
  $q=$pdo->prepare('SELECT ts.*, t.first_name, t.last_name, r.room_name
                    FROM timetable_slots ts
                    LEFT JOIN teachers t ON t.id=ts.teacher_id
                    LEFT JOIN rooms r ON r.id=ts.room_id
                    WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.class_id=?');
  $q->execute([$year_id,$term_no,$class_id]);
  $slots=$q->fetchAll();
} else {
  $q=$pdo->prepare('SELECT DISTINCT ts.*, c.class_name, r.room_name
                    FROM timetable_slots ts
                    JOIN timetable_slot_teachers st ON st.slot_id = ts.id AND st.teacher_id = ?
                    JOIN classes c ON c.id=ts.class_id
                    LEFT JOIN rooms r ON r.id=ts.room_id
                    WHERE ts.academic_year_id=? AND ts.term_no=? 
                    ORDER BY ts.day_of_week, ts.period_no');
  $q->execute([$teacher_id,$year_id,$term_no]);
  $slots=$q->fetchAll();
}
$cell=[]; foreach($slots as $s){ $cell[(int)$s['day_of_week']][(int)$s['period_no']][]=$s; }
$slotTeacherNames = fetch_teachers_for_slots($pdo, array_map(fn($r)=> (int)$r['id'],$slots));

/* =========================
   Map: room ของกำลังสอน (fallback)
========================= */
$loadRoomMap = [];
$stLR = $pdo->prepare("
  SELECT tl.class_id, tl.teacher_id, tl.room_id, r.room_name,
         CASE WHEN IFNULL(s.subject_code,'')=''
              THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS subj_lbl
  FROM teaching_loads tl
  JOIN subjects s ON s.id=tl.subject_id
  LEFT JOIN rooms r ON r.id=tl.room_id
  WHERE tl.academic_year_id=? AND tl.term_no=?
");
$stLR->execute([$year_id,$term_no]);
foreach ($stLR as $row) {
  $key = $year_id.'|'.$term_no.'|'.$row['class_id'].'|'.$row['teacher_id'].'|'.$row['subj_lbl'];
  $loadRoomMap[$key] = $row['room_name'] ?? '';
}

/* =========================
   Breaks & Activities
========================= */
$grade_label_for_view=null;
if ($view==='class'){ $grade_label_for_view = $classes[$class_id]['grade_label'] ?? null; }
$breakPeriods=[];
if ($grade_label_for_view){
  $st=$pdo->prepare('SELECT period_no FROM grade_breaks WHERE grade_label=?'); $st->execute([$grade_label_for_view]);
  $breakPeriods=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}
$activityCell=[];
if ($view==='class'){
  $st=$pdo->prepare('SELECT ag.day_of_week,ag.period_no,ag.activity_name
                     FROM activity_groups ag JOIN activity_classes ac ON ac.activity_id=ag.id
                     WHERE ag.academic_year_id=? AND ag.term_no=? AND ac.class_id=?');
  $st->execute([$year_id,$term_no,$class_id]);
  foreach($st as $r) $activityCell[(int)$r['day_of_week']][(int)$r['period_no']]=$r['activity_name'];
}else{
  $st=$pdo->prepare('SELECT ag.day_of_week,ag.period_no,ag.activity_name
                     FROM activity_groups ag JOIN activity_teachers at ON at.activity_id=ag.id
                     WHERE ag.academic_year_id=? AND ag.term_no=? AND at.teacher_id=?');
  $st->execute([$year_id,$term_no,$teacher_id]);
  foreach($st as $r) $activityCell[(int)$r['day_of_week']][(int)$r['period_no']]=$r['activity_name'];
}

/* =========================
   Loads for add-form
========================= */
if ($view==='class') {
  $loadsStmt=$pdo->prepare('
    SELECT 
      tl.id, 
      tl.periods_per_week, 
      tl.room_id, 
      tl.consecutive_slots, 
      s.subject_code, 
      s.subject_name, 
      t.first_name, 
      t.last_name,
      (
        SELECT COUNT(DISTINCT ts.id)
        FROM timetable_slots ts
        JOIN timetable_slot_teachers st ON st.slot_id = ts.id AND st.teacher_id = tl.teacher_id
        WHERE ts.academic_year_id = tl.academic_year_id
          AND ts.term_no = tl.term_no
          AND ts.class_id = tl.class_id
          AND ts.subject_name = CASE 
            WHEN IFNULL(s.subject_code, "") = "" 
            THEN s.subject_name 
            ELSE CONCAT(s.subject_code, " - ", s.subject_name) 
          END
      ) AS used_count
    FROM teaching_loads tl
    JOIN subjects s ON s.id = tl.subject_id
    JOIN teachers t ON t.id = tl.teacher_id
    WHERE tl.academic_year_id = ? 
      AND tl.term_no = ? 
      AND tl.class_id = ?
    HAVING used_count < tl.periods_per_week
    ORDER BY s.subject_code, s.subject_name
  ');
  $loadsStmt->execute([$year_id,$term_no,$class_id]);
  $loads=$loadsStmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
} else {
  $loadsStmt=$pdo->prepare('
    SELECT 
      tl.id, 
      tl.periods_per_week, 
      tl.room_id, 
      tl.consecutive_slots, 
      s.subject_code, 
      s.subject_name, 
      c.class_name,
      (
        SELECT COUNT(DISTINCT ts.id)
        FROM timetable_slots ts
        JOIN timetable_slot_teachers st ON st.slot_id = ts.id AND st.teacher_id = ?
        WHERE ts.academic_year_id = tl.academic_year_id
          AND ts.term_no = tl.term_no
          AND ts.class_id = tl.class_id
          AND ts.subject_name = CASE 
            WHEN IFNULL(s.subject_code, "") = "" 
            THEN s.subject_name 
            ELSE CONCAT(s.subject_code, " - ", s.subject_name) 
          END
      ) AS used_count
    FROM teaching_loads tl
    JOIN subjects s ON s.id = tl.subject_id
    JOIN classes c ON c.id = tl.class_id
    WHERE tl.academic_year_id = ? 
      AND tl.term_no = ? 
      AND tl.teacher_id = ?
    HAVING used_count < tl.periods_per_week
    ORDER BY c.class_name, s.subject_code, s.subject_name
  ');
  $loadsStmt->execute([$teacher_id,$year_id,$term_no,$teacher_id]);
  $loads=$loadsStmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
}

/* =========================
   Remain panel
========================= */
$remainRows=[];
if ($view==='class' && $class_id) {
  $sql="
    SELECT
      tl.id AS load_id,
      tl.periods_per_week,
      s.subject_code, s.subject_name,
      tl.teacher_id AS lead_id,
      (
        SELECT COUNT(DISTINCT ts.id)
        FROM timetable_slots ts
        JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=tl.teacher_id
        WHERE ts.academic_year_id=tl.academic_year_id
          AND ts.term_no=tl.term_no
          AND ts.class_id=tl.class_id
          AND ts.subject_name = CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END
      ) AS used_count
    FROM teaching_loads tl
    JOIN subjects s ON s.id=tl.subject_id
    WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.class_id=?
    ORDER BY s.subject_name, tl.id
  ";
  $st=$pdo->prepare($sql); $st->execute([$year_id,$term_no,$class_id]); $remainRows=$st->fetchAll();
} elseif ($view==='teacher' && $teacher_id) {
  $sql="
    SELECT
      tl.id AS load_id,
      tl.periods_per_week,
      s.subject_code, s.subject_name, c.class_name,
      (
        SELECT COUNT(DISTINCT ts.id)
        FROM timetable_slots ts
        JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=? 
        WHERE ts.academic_year_id=tl.academic_year_id
          AND ts.term_no=tl.term_no
          AND ts.class_id=tl.class_id
          AND ts.subject_name = CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END
      ) AS used_count
    FROM teaching_loads tl
    JOIN subjects s ON s.id=tl.subject_id
    JOIN classes  c ON c.id=tl.class_id
    WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.teacher_id=?
    ORDER BY c.class_name, s.subject_name, tl.id
  ";
  $st=$pdo->prepare($sql); $st->execute([$teacher_id,$year_id,$term_no,$teacher_id]); $remainRows=$st->fetchAll();
}

$flash = flash_get();

/* =========================
   View
========================= */
include __DIR__.'/../partials/head.php';
include __DIR__.'/../partials/navbar.php';
?>

<!-- Modal ยืนยันการลงวิชาซ้ำ -->
<?php if ($confirmData): ?>
<div id="confirmModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
    <div class="flex items-start gap-3 mb-4">
      <div class="flex-shrink-0 w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
      </div>
      <div class="flex-1">
        <h3 class="text-lg font-semibold text-slate-900 mb-2">พบวิชาซ้ำในวันเดียวกัน</h3>
        <p class="text-sm text-slate-600 mb-3">
          วิชา <strong class="text-amber-700"><?= htmlspecialchars($confirmData['subject']); ?></strong> 
          มีอยู่ในวัน<strong><?= htmlspecialchars($confirmData['day_name']); ?></strong>แล้ว
        </p>
        <p class="text-sm text-slate-600">
          คุณต้องการลงคาบนี้ต่อหรือไม่?
        </p>
      </div>
    </div>
    
    <form method="post" id="forceAddForm">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="force_add" value="1">
      <input type="hidden" name="load_id" value="<?= (int)$confirmData['load_id']; ?>">
      <input type="hidden" name="day_of_week" value="<?= (int)$confirmData['day_of_week']; ?>">
      <input type="hidden" name="period_no" value="<?= (int)$confirmData['period_no']; ?>">
      <input type="hidden" name="span" value="<?= (int)$confirmData['span']; ?>">
      <input type="hidden" name="room_id" value="<?= $confirmData['room_id'] ? (int)$confirmData['room_id'] : ''; ?>">
      
      <div class="flex gap-3 mt-6">
        <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-amber-600 text-white font-medium hover:bg-amber-700 transition-colors">
          ✅ ใช่, ลงต่อ
        </button>
        <button type="button" onclick="closeConfirmModal()" class="flex-1 px-4 py-2.5 rounded-xl border-2 border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
          ❌ ไม่ลง
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  function closeConfirmModal() {
    document.getElementById('confirmModal').remove();
  }
  
  // ปิด modal เมื่อกด ESC
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeConfirmModal();
    }
  });
</script>
<?php endif; ?>

<style>
/* ✅ ตารางแบบ responsive - ไม่มี scroll */
.timetable-container {
  width: 100%;
  overflow: visible;
}

.timetable-table {
  table-layout: fixed;
  width: 100%;
  border-collapse: collapse;
}

/* ✅ คอลัมน์วัน */
.timetable-table th:first-child,
.timetable-table td:first-child {
  width: 100px;
  position: sticky;
  left: 0;
  background: white;
  z-index: 10;
  box-shadow: 2px 0 4px rgba(0,0,0,0.05);
}

.timetable-table thead th:first-child {
  background: #f1f5f9;
  z-index: 20;
}

/* ✅ คอลัมน์คาบ - ปรับขนาดอัตโนมัติ */
.timetable-table th:not(:first-child),
.timetable-table td:not(:first-child) {
  width: auto;
  min-width: 140px;
  vertical-align: top;
}

/* ✅ เนื้อหาในเซลล์ */
.cell-content {
  max-height: 450px;
  overflow-y: auto;
  overflow-x: hidden;
  min-height: 60px;
  padding: 2px;
}

/* ✅ การ์ดวิชา */
.slot-card {
  position: relative;
  word-wrap: break-word;
  overflow-wrap: break-word;
  width: 100%;
  box-sizing: border-box;
  font-size: 0.75rem;
}

/* ✅ ชื่อวิชา - แสดง 2 บรรทัด */
.subject-name {
  font-size: 0.875rem; /* ✅ เพิ่มจาก 0.813rem */
  line-height: 1.2;
  font-weight: 600;
  color: #1e293b;
  word-break: break-word;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 0.25rem;
  min-height: 2em;
}

/* ✅ Info badges - เพิ่มขนาด */
.info-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem; /* ✅ เพิ่มจาก 0.2rem */
  margin-bottom: 0.3rem;
}

.info-badge {
  font-size: 10px; /* ✅ เพิ่มจาก 8px */
  padding: 0.15rem 0.4rem; /* ✅ เพิ่ม padding */
  border-radius: 9999px;
  font-weight: 600; /* ✅ เพิ่มจาก 500 */
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  line-height: 1.2;
}

.room-badge {
  background: #fef3c7;
  border: 1px solid #f59e0b;
  color: #78350f;
}

.class-badge {
  background: #ddd6fe;
  border: 1px solid #8b5cf6;
  color: #4c1d95;
}

.teacher-badge {
  background: #e2e8f0;
  border: 1px solid #64748b;
  color: #334155;
  max-width: 110px; /* ✅ เพิ่มจาก 90px */
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ✅ Footer การ์ด */
.slot-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 0.3rem;
  padding-top: 0.3rem;
  border-top: 1px solid #e2e8f0;
}

.source-badge {
  font-size: 9px; /* ✅ เพิ่มจาก 8px */
  font-weight: 600; /* ✅ เพิ่มจาก 500 */
  color: #64748b;
}

.delete-btn {
  font-size: 9px; /* ✅ เพิ่มจาก 8px */
  font-weight: 700; /* ✅ เพิ่มจาก 600 */
  white-space: nowrap;
  padding: 0.15rem 0.3rem; /* ✅ เพิ่ม padding */
  border-radius: 0.25rem;
  transition: background-color 0.2s;
}

/* ✅ ฟอร์มเพิ่ม - กะทัดรัด */
.add-form {
  background: #f8fafc;
  border-radius: 0.5rem;
  padding: 0.4rem;
  margin-top: 0.5rem;
}

.add-form label {
  display: block;
  font-size: 10px; /* ✅ เพิ่มจาก 9px */
  font-weight: 600;
  color: #475569;
  margin-bottom: 0.2rem;
}

.add-form select,
.add-form input {
  width: 100%;
  font-size: 11px; /* ✅ เพิ่มจาก 10px */
  padding: 0.35rem 0.45rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.375rem;
  box-sizing: border-box;
}

.add-form select:focus,
.add-form input:focus {
  outline: none;
  border-color: #3b82f6;
}

.add-form button {
  font-size: 10px; /* ✅ เพิ่มจาก 9px */
  font-weight: 700; /* ✅ เพิ่มจาก 600 */
  padding: 0.45rem 0.5rem;
  border-radius: 0.375rem;
  transition: all 0.2s;
}

/* ✅ Summary */
details summary {
  cursor: pointer;
  padding: 0.5rem;
  border-radius: 0.5rem;
  border: 2px dashed #cbd5e1;
  text-align: center;
  font-size: 0.813rem; /* ✅ เพิ่มจาก 0.75rem */
  font-weight: 600; /* ✅ เพิ่มจาก 500 */
  color: #64748b;
  transition: all 0.2s;
  user-select: none;
}

details summary:hover {
  background: #f1f5f9;
  border-color: #94a3b8;
  color: #475569;
}

details[open] summary {
  border-style: solid;
  background: #f1f5f9;
  margin-bottom: 0.5rem;
}

/* Scrollbar */
.cell-content::-webkit-scrollbar {
  width: 4px;
}

.cell-content::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 2px;
}

.cell-content::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 2px;
}

.cell-content::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

/* ✅ Responsive - จัดการหน้าจอเล็ก */
@media (max-width: 1536px) {
  .timetable-table th:not(:first-child),
  .timetable-table td:not(:first-child) {
    min-width: 130px;
  }
  
  .subject-name {
    font-size: 0.813rem;
  }
  
  .info-badge {
    font-size: 9px;
  }
}

@media (max-width: 1280px) {
  .timetable-table th:first-child,
  .timetable-table td:first-child {
    width: 80px;
  }
  
  .timetable-table th:not(:first-child),
  .timetable-table td:not(:first-child) {
    min-width: 120px;
  }
  
  .subject-name {
    font-size: 0.75rem;
    -webkit-line-clamp: 2;
  }
  
  .info-badge {
    font-size: 8px;
    padding: 0.1rem 0.3rem;
  }
  
  .cell-content {
    max-height: 400px;
  }
}

@media (max-width: 1024px) {
  /* ✅ หน้าจอเล็กกว่า 1024px ให้ scroll */
  .timetable-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  
  .timetable-table {
    min-width: 1200px;
  }
}

/* ✅ ป้องกัน text selection ขณะ interact */
.timetable-table {
  user-select: none;
  -webkit-user-select: none;
}

.subject-name {
  user-select: text;
  -webkit-user-select: text;
}
</style>

<div class="max-w-7xl mx-auto px-4 mt-8">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-xl font-semibold">ตารางสอน</h1>
    <?php if ($isAdmin): ?>
      <a href="<?= url('timetable_auto_dashboard.php?year_id='.$year_id.'&term_no='.$term_no); ?>" class="px-3 py-2 rounded-xl border text-sm">เปิดหน้าจัดอัตโนมัติ</a>
    <?php endif; ?>
  </div>

  <?php if($flash): ?><div class="mb-3 p-3 <?= $flash['type']==='success'?'bg-emerald-50 text-emerald-700':'bg-rose-50 text-rose-700'; ?> rounded"><?= htmlspecialchars($flash['msg']); ?></div><?php endif; ?>
  <?php if($err): ?><div class="mb-3 p-3 bg-rose-50 text-rose-700 rounded"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <!-- Filters -->
  <form id="filterForm" method="get" class="bg-white rounded-2xl shadow p-4 mb-6 grid grid-cols-1 md:grid-cols-6 gap-3">
    <div>
      <label class="block text-xs mb-1">มุมมอง</label>
      <select name="view" id="viewSel" class="w-full border rounded px-3 py-2 auto-submit">
        <option value="class"   <?= $view==='class'?'selected':''; ?>>ตามห้อง</option>
        <option value="teacher" <?= $view==='teacher'?'selected':''; ?>>ตามครู</option>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border rounded px-3 py-2 auto-submit">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).($y['is_active']?' (ใช้งาน)':''); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border rounded px-3 py-2 auto-submit">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$term_no) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php
      $classOptions = [];
      foreach ($classes as $cid => $cv) $classOptions[] = ['id'=>$cid,'class_name'=>$cv['class_name']];
    ?>
    <div id="classBox" class="md:col-span-2" <?= $view==='teacher'?'style="display:none"':''; ?>>
      <label class="block text-xs mb-1">ห้องเรียน</label>
      <select name="class_id" class="w-full border rounded px-3 py-2 auto-submit">
        <?php foreach($classOptions as $c): ?>
          <option value="<?= (int)$c['id']; ?>" <?= ((int)$c['id'] === $class_id) ? 'selected' : ''; ?>><?= htmlspecialchars($c['class_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="groupBox" <?= $view==='class'?'style="display:none"':''; ?>>
      <label class="block text-xs mb-1">กลุ่มสาระ</label>
      <select name="teacher_group" id="groupSel" class="w-full border rounded px-3 py-2 auto-submit">
        <option value="all" <?= $teacher_group==='all'?'selected':''; ?>>— ทั้งหมด —</option>
        <?php foreach($groupMap as $gid=>$gn): ?>
          <option value="<?= $gid ?>" <?= (string)$teacher_group===(string)$gid?'selected':''; ?>><?= htmlspecialchars($gn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="teacherBox" class="md:col-span-2" <?= $view==='class'?'style="display:none"':''; ?>>
      <label class="block text-xs mb-1">ครู</label>
      <select name="teacher_id" id="teacherSel" class="w-full border rounded px-3 py-2 auto-submit">
        <?php foreach($teachers as $t):
          $tg = (string)($t['subject_group'] ?? '');
          $visible = ($teacher_group==='all' || $tg===$teacher_group);
          ?>
          <option value="<?= (int)$t['id']; ?>" data-group="<?= htmlspecialchars($tg) ?>"
            <?= ((int)$t['id'] === $teacher_id) ? 'selected' : ''; ?>
            <?= $visible ? '' : 'style="display:none"'; ?>>
            <?= htmlspecialchars($t['first_name'].' '.$t['last_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="md:col-span-6">
      <button class="px-3 py-2 rounded border">แสดง</button>
    </div>
  </form>

  <script>
    const viewSel = document.getElementById('viewSel');
    const form    = document.getElementById('filterForm');
    const classBox= document.getElementById('classBox');
    const groupBox= document.getElementById('groupBox');
    const teacherBox=document.getElementById('teacherBox');

    viewSel.addEventListener('change', function(){
      if (this.value==='class') {
        classBox.style.display='';
        groupBox.style.display='none';
        teacherBox.style.display='none';
      } else {
        classBox.style.display='none';
        groupBox.style.display='';
        teacherBox.style.display='';
      }
      form.submit();
    });

    document.querySelectorAll('#filterForm select.auto-submit').forEach(function(el){
      if (el.id === 'groupSel') return;
      el.addEventListener('change', function(){ form.submit(); });
    });

    const groupSel   = document.getElementById('groupSel');
    const teacherSel = document.getElementById('teacherSel');
    if (groupSel && teacherSel) {
      groupSel.addEventListener('change', function(){
        const g = this.value;
        let firstShown = null;
        [...teacherSel.options].forEach(op=>{
          const og = op.getAttribute('data-group') || '';
          const show = (g==='all' || g===og);
          op.style.display = show ? '' : 'none';
          if (show && !firstShown) firstShown = op;
        });
        if (firstShown) teacherSel.value = firstShown.value;
        form.submit();
      });
    }
  </script>

  <!-- ✅ ตารางจัดตารางสอน (ย้ายมาไว้ก่อน) -->
  <div class="timetable-container bg-white rounded-2xl shadow mb-6">
    <table class="timetable-table text-sm border-collapse" style="--period-count: <?= $periodCount ?>;">
      <thead class="bg-slate-100">
        <tr>
          <th class="text-left px-4 py-3 border-b-2 border-slate-300 font-bold">วัน \ คาบ</th>
          <?php foreach($periods as $p): ?>
            <th class="text-center px-2 py-3 border-b-2 border-slate-300 font-semibold">
              <?= (int)$p['period_no']; ?><br>
              <span class="text-xs text-slate-600 font-normal">
                <?= substr($p['start_time'],0,5); ?>–<?= substr($p['end_time'],0,5); ?>
              </span>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php 
        $daysToShow = [1, 2, 3, 4, 5];
        if ($has_saturday) $daysToShow[] = 6;
        if ($has_sunday) $daysToShow[] = 7;
        
        $dayColors = [
          1 => 'bg-yellow-50 border-l-4 border-yellow-400',
          2 => 'bg-pink-50 border-l-4 border-pink-400',
          3 => 'bg-green-50 border-l-4 border-green-400',
          4 => 'bg-orange-50 border-l-4 border-orange-400',
          5 => 'bg-blue-50 border-l-4 border-blue-400',
          6 => 'bg-purple-50 border-l-4 border-purple-400',
          7 => 'bg-red-50 border-l-4 border-red-400',
        ];
        
        $dayNames = [
          1 => 'จันทร์', 
          2 => 'อังคาร', 
          3 => 'พุธ', 
          4 => 'พฤหัสบดี', 
          5 => 'ศุกร์',
          6 => 'เสาร์',
          7 => 'อาทิตย์'
        ];
        
        foreach ($daysToShow as $d): 
        ?>
          <tr class="border-t border-slate-200">
            <td class="px-4 py-3 font-bold text-slate-800 <?= $dayColors[$d] ?? 'bg-slate-50' ?>">
              <?= $dayNames[$d] ?? '' ?>
            </td>
            <?php foreach ($periods as $p):
              $pp=(int)$p['period_no']; $items=$cell[$d][$pp] ?? [];
            ?>
              <td class="px-2 py-2 border-l border-slate-200 align-top">
                <div class="cell-content">
                  <?php
                  if ($view==='class' && in_array($pp,$breakPeriods)) {
                    echo '<div class="bg-yellow-100 border-2 border-yellow-400 rounded-lg p-2 text-center text-xs text-yellow-900 font-semibold">💤 พัก</div>';
                  } elseif (isset($activityCell[$d][$pp])) {
                    echo '<div class="bg-sky-100 border-2 border-sky-400 rounded-lg p-2 text-center text-xs text-sky-900 font-semibold">🎯 '.htmlspecialchars((string)$activityCell[$d][$pp]).'</div>';
                  } elseif ($items) {
                    foreach($items as $it){
                      $names = $slotTeacherNames[$it['id']] ?? [];

                      $resolveHomeroom = function(int $clsId) use ($classes, $rooms) {
                        $hid = (int)($classes[$clsId]['homeroom_room_id'] ?? 0);
                        if ($hid && !empty($rooms[$hid])) return $rooms[$hid];
                        if ($hid) return 'ห้อง #'.$hid;
                        return '';
                      };
                      $resolveClassName = function(int $clsId) use ($classes) {
                        return $classes[$clsId]['class_name'] ?? '';
                      };

                      $roomNameToShow = $it['room_name'] ?? '';

                      if ($roomNameToShow === '' || $roomNameToShow === null) {
                        $loadKey = $year_id.'|'.$term_no.'|'.$it['class_id'].'|'.$it['teacher_id'].'|'.$it['subject_name'];
                        $loadRoomName = $loadRoomMap[$loadKey] ?? '';

                        if ($loadRoomName !== '') {
                          $roomNameToShow = $loadRoomName;
                        } else {
                          $roomNameToShow = ($view==='class')
                            ? $resolveHomeroom((int)$class_id)
                            : $resolveHomeroom((int)$it['class_id']);

                          if ($roomNameToShow === '') {
                            $roomNameToShow = ($view==='class')
                              ? $resolveClassName((int)$class_id)
                              : $resolveClassName((int)$it['class_id']);
                          }
                        }
                      }
                      
                      // ✅ แยกชื่อวิชาออกจากรหัส (ถ้ามี)
                      $subjectFullName = (string)$it['subject_name'];
                      $subjectDisplayName = $subjectFullName;
                      
                      // ตัด " - " ออก ถ้ามีรหัสวิชา
                      if (strpos($subjectFullName, ' - ') !== false) {
                        $parts = explode(' - ', $subjectFullName, 2);
                        $subjectDisplayName = $parts[1]; // เอาเฉพาะชื่อวิชา
                      }
                      
                      // ✅ สร้าง tooltip แบบละเอียด
                      $tooltipParts = [];
                      $tooltipParts[] = '📚 ' . $subjectFullName;
                      if (!empty($roomNameToShow)) {
                        $tooltipParts[] = '📍 ห้อง: ' . $roomNameToShow;
                      }
                      if ($view === 'teacher' && !empty($it['class_name'])) {
                        $tooltipParts[] = '🎓 ชั้น: ' . $it['class_name'];
                      }
                      if (!empty($names)) {
                        $tooltipParts[] = '👥 ครู: ' . implode(', ', $names);
                      }
                      $tooltipParts[] = ($it['source']==='auto' ? '🤖 จัดอัตโนมัติ' : '✏️ จัดด้วยตนเอง');
                      
                      $tooltip = implode("\n", $tooltipParts);
                  ?>
                      <!-- ✅ การ์ดปรับปรุงใหม่ -->
                      <div class="slot-card border-2 border-slate-300 rounded-lg p-2 mb-1.5 bg-white hover:bg-slate-50 transition-all shadow-sm hover:shadow" 
                           title="<?= htmlspecialchars($tooltip); ?>">
                        
                        <!-- ชื่อวิชา - แสดงเฉพาะชื่อ ไม่มีรหัส -->
                        <div class="subject-name">
                          <?= htmlspecialchars($subjectDisplayName); ?>
                        </div>

                        <!-- Info badges แนวนอน -->
                        <div class="info-row">
                          <?php if (!empty($roomNameToShow)): ?>
                            <span class="info-badge room-badge" title="ห้อง: <?= htmlspecialchars($roomNameToShow); ?>">
                              📍 <?= htmlspecialchars($roomNameToShow); ?>
                            </span>
                          <?php endif; ?>
                          
                          <?php if ($view === 'teacher' && !empty($it['class_name'])): ?>
                            <span class="info-badge class-badge" title="ชั้น: <?= htmlspecialchars($it['class_name']); ?>">
                              🎓 <?= htmlspecialchars($it['class_name']); ?>
                            </span>
                          <?php endif; ?>
                          
                          <?php foreach ($names as $n): ?>
                            <span class="info-badge teacher-badge" title="ครู: <?= htmlspecialchars($n) ?>">
                              👤 <?= htmlspecialchars($n) ?>
                            </span>
                          <?php endforeach; ?>
                        </div>

                        <!-- Footer -->
                        <div class="slot-footer">
                          <span class="source-badge">
                            <?= $it['source']==='auto' ? '🤖 AUTO' : '✏️ MANUAL'; ?>
                          </span>
                          <form method="post" onsubmit="return ttConfirmSubmit(this,{text:'ลบคาบนี้?'});" style="display: inline; margin: 0;">
                            <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="slot_id" value="<?= (int)$it['id']; ?>">
                            <button type="submit" class="delete-btn text-rose-600 hover:text-rose-700 hover:bg-rose-50">
                              🗑️ ลบ
                            </button>
                          </form>
                        </div>
                      </div>
    <?php
                    }
                  } else {
                  ?>
                    <details>
                      <summary class="cursor-pointer text-slate-600 hover:text-slate-900 font-medium text-xs">➕ เพิ่ม</summary>
                      <form method="post" class="add-form mt-2 space-y-1">
                        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="day_of_week" value="<?= (int)$d; ?>">
                        <input type="hidden" name="period_no" value="<?= (int)$pp; ?>">

                        <label class="block text-[10px] font-semibold mb-0.5">กำลังสอน</label>
                        <select name="load_id" class="w-full border border-slate-300 rounded px-1.5 py-1.5 text-[11px]" required onchange="ttFillDefaults(this, <?= $maxPeriod ?>, <?= $pp ?>)">
                          <option value="">-- เลือก --</option>
                          <?php foreach ($loads as $lid => $ld): ?>
                            <?php 
                              $default_room = !empty($ld['room_id']) ? (int)$ld['room_id'] : 0; 
                              $default_consec = !empty($ld['consecutive_slots']) ? (int)$ld['consecutive_slots'] : 1;
                              
                              // ✅ แสดงเฉพาะชื่อวิชา (ไม่มีรหัส)
                              $displayLabel = load_label_for_dropdown($view, $ld);
                              
                              // ✅ tooltip แสดงข้อมูลเต็ม (มีรหัสวิชา)
                              $fullLabel = load_label_full($view, $ld);
                              
                              // ตัดชื่อยาวเกิน 40 ตัวอักษรสำหรับแสดง
                              $shortLabel = $displayLabel;
                              if (mb_strlen($shortLabel) > 40) {
                                $shortLabel = mb_substr($shortLabel, 0, 37) . '...';
                              }
                            ?>
                            <option value="<?= (int)$lid; ?>" 
                                    data-default-room="<?= $default_room; ?>"
                                    data-default-consec="<?= $default_consec; ?>"
                                    title="<?= htmlspecialchars($fullLabel); ?>">
                              <?= htmlspecialchars($shortLabel); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>

                        <label class="block text-[10px] font-semibold mb-0.5">ห้อง</label>
                        <select name="room_id" class="w-full border border-slate-300 rounded px-1.5 py-1.5 text-[11px]">
                          <option value="">— ไม่กำหนด —</option>
                          <?php foreach ($rooms as $rid => $rname): ?>
                            <option value="<?= (int)$rid; ?>"><?= htmlspecialchars($rname); ?></option>
                          <?php endforeach; ?>
                        </select>

                        <label class="block text-[10px] font-semibold mb-0.5">คาบติด</label>
                        <input type="number" name="span" value="1" min="1" max="<?= $maxPeriod - $pp + 1 ?>" class="border border-slate-300 rounded w-full text-center text-xs">

                        <div class="flex gap-1 pt-1">
                          <button type="submit" class="flex-1 px-2 py-1 rounded bg-slate-900 text-white text-[10px] font-bold hover:bg-slate-800">
                            💾 บันทึก
                          </button>
                          <button type="button" onclick="this.closest('details').removeAttribute('open')" 
                                  class="flex-1 px-2 py-1 rounded border border-slate-300 text-slate-700 text-[10px] font-bold hover:bg-slate-100">
                            ❌ ยกเลิก
                          </button>
                        </div>
                      </form>
                    </details>
                  <?php } ?>
                </div>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ✅ ตารางรวมกำลัง (ย้ายมาไว้ด้านล่าง) -->
  <div class="bg-white rounded-2xl shadow p-4">
    <div class="font-medium mb-2 text-lg">
      📊 <?= $view==='class' ? 'วิชาที่ต้องลงให้ห้องนี้' : 'วิชาที่ต้องลงสำหรับครูนี้'; ?>
    </div>
    <?php if(!$remainRows): ?>
      <div class="text-slate-500 text-sm py-6 text-center">
        ✅ ไม่มีวิชาที่ต้องลงแล้ว (ครบทุกคาบ)
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <?php if($view==='teacher'): ?><th class="text-left px-3 py-2 font-semibold">ห้อง</th><?php endif; ?>
              <th class="text-left px-3 py-2 font-semibold">วิชา</th>
              <th class="text-center px-3 py-2 font-semibold">ใช้ไป</th>
              <th class="text-center px-3 py-2 font-semibold">กำลัง</th>
              <th class="text-center px-3 py-2 font-semibold">คงเหลือ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($remainRows as $r):
              $label = subj_label($r['subject_code'],$r['subject_name']);
              $used = (int)$r['used_count'];
              $ppw  = (int)$r['periods_per_week'];
              $left = max(0,$ppw-$used);
            ?>
              <tr class="border-t hover:bg-slate-50">
                <?php if($view==='teacher'): ?>
                  <td class="px-3 py-2"><?= htmlspecialchars((string)($r['class_name'] ?? '')) ?></td>
                <?php endif; ?>
                <td class="px-3 py-2 font-medium"><?= htmlspecialchars($label) ?></td>
                <td class="px-3 py-2 text-center"><?= $used ?></td>
                <td class="px-3 py-2 text-center"><?= $ppw ?></td>
                <td class="px-3 py-2 text-center">
                  <?php if ($left > 0): ?>
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 font-bold">
                      <?= $left ?>
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-slate-100 text-slate-500 font-bold">
                      ✓
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  function ttFillDefaults(sel, maxPeriod, currentPeriod){
    const form = sel.closest('form');
    const roomSel = form.querySelector('select[name="room_id"]');
    const spanInput = form.querySelector('input[name="span"]');
    const opt = sel.selectedOptions[0];
    if (!opt) return;
    
    const defRoom = opt.getAttribute('data-default-room');
    if (defRoom && defRoom !== '0') roomSel.value = defRoom;
    else roomSel.value = '';
    
    const defConsec = parseInt(opt.getAttribute('data-default-consec')) || 1;
    const maxSpan = Math.max(1, maxPeriod - currentPeriod + 1);
    const safeSpan = Math.min(defConsec, maxSpan);
    
    spanInput.value = safeSpan;
    spanInput.max = maxSpan;
    
    if (defConsec > maxSpan) {
      spanInput.classList.add('border-orange-400');
      spanInput.title = `ตั้งค่าเดิม ${defConsec} คาบ แต่ลงได้สูงสุด ${maxSpan} คาบ`;
    } else {
      spanInput.classList.remove('border-orange-400');
      spanInput.title = '';
    }
  }
</script>

<?php include __DIR__.'/../partials/footer.php'; ?>
