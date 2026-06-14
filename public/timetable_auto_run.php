<?php
// filepath: c:\xampp\htdocs\timetable\public\timetable_auto_run.php
// กัน whitespace/warning ทำลาย JSON
ob_start();
ini_set('display_errors', '0');
error_reporting(0); // ✅ ปิดทุก error
set_error_handler(function($errno, $errstr, $errfile, $errline) {
  // เก็บ error ไว้ แต่ไม่แสดง
  return true;
});

require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/helpers.php';
requireLogin(); 

// เช็ค role - ซ่อนจาก user ธรรมดา และตรวจสอบล็อก
$currentUser = currentUser();
$isAdmin = in_array($currentUser['role'] ?? '', ['admin', 'superuser'], true);
if (!$isAdmin) {
  ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'คุณไม่มีสิทธิ์ใช้งานฟีเจอร์นี้']);
  exit;
}
if (!canEditSection('timetable')) {
  ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => '🔒 ระบบปิดการแก้ไขชั่วคราว กรุณาติดต่อ Superuser']);
  exit;
}

// flush header JSON
if (isset($_GET['ping'])) {
  ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true, 'ping'=>'pong']); 
  exit;
}

$action = $_GET['action'] ?? 'run';

// อ่านค่าได้ทั้ง JSON และแบบฟอร์ม
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];
$year_id = (int)($input['year_id'] ?? $_POST['year_id'] ?? $_GET['year_id'] ?? 0);
$term_no = (int)($input['term_no'] ?? $_POST['term_no'] ?? $_GET['term_no'] ?? 1);
$fallback = (int)($_POST['fallback'] ?? 0);
$max_passes_input = (int)($input['max_passes'] ?? $_POST['max_passes'] ?? $_GET['max_passes'] ?? 5);
$MAX_PASSES = max(1, min(10, $max_passes_input));

// ตั้ง header ตามโหมด
if (!$fallback) {
  ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
}

// ฟังก์ชันช่วยตอบกลับ
function respond($arr) {
  global $fallback;
  if ($fallback) {
    echo "<pre style='padding:16px;font:12px/1.6 ui-monospace,Consolas,monospace'>".htmlspecialchars(print_r($arr,true))."</pre>";
  } else {
    while (ob_get_level()>0) ob_end_clean();
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  }
  exit;
}

if (!$year_id) respond(['error'=>'year_id ไม่ถูกต้อง']);
if (!in_array($term_no,[1,2],true)) respond(['error'=>'term_no ต้องเป็น 1 หรือ 2']);

/* ------------ ลบคาบอัตโนมัติทั้งหมด ------------- */
if ($action==='clear') {
  $stmt = $pdo->prepare("DELETE ts, st
    FROM timetable_slots ts
    LEFT JOIN timetable_slot_teachers st ON st.slot_id=ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.source='auto'");
  $stmt->execute([$year_id,$term_no]);
  respond(['ok'=>true,'message'=>'ลบคาบอัตโนมัติทั้งหมดแล้ว']);
}

/* ------------ ลบตารางทั้งหมด (ทุกประเภท) ------------- */
if ($action==='clear_all') {
  try {
    $pdo->beginTransaction();
    
    $stmt1 = $pdo->prepare("
      DELETE st 
      FROM timetable_slot_teachers st
      JOIN timetable_slots ts ON ts.id = st.slot_id
      WHERE ts.academic_year_id=? AND ts.term_no=?
    ");
    $stmt1->execute([$year_id, $term_no]);
    
    $stmt2 = $pdo->prepare("
      DELETE FROM timetable_slots 
      WHERE academic_year_id=? AND term_no=?
    ");
    $stmt2->execute([$year_id, $term_no]);
    
    $pdo->commit();
    respond(['ok'=>true,'message'=>'ลบตารางสอนทั้งหมดเรียบร้อย (ทั้ง manual และ auto)']);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
  }
}

/* ------------ ✅ คัดลอกจากเทอมก่อน ------------- */
if ($action === 'copy_prev') {
  try {
    $pdo->beginTransaction();

    // 1. หาเทอมต้นทาง
    if ($term_no === 1) {
      $stmtPrevYear = $pdo->prepare("
        SELECT id FROM academic_years 
        WHERE year_label < (SELECT year_label FROM academic_years WHERE id = ?)
        ORDER BY year_label DESC LIMIT 1
      ");
      $stmtPrevYear->execute([$year_id]);
      $source_year_id = (int)$stmtPrevYear->fetchColumn();
      $source_term_no = 2;
    } else {
      $source_year_id = $year_id;
      $source_term_no = 1;
    }

    if (!$source_year_id) {
      $pdo->rollBack();
      respond(['error' => '❌ ไม่พบข้อมูลเทอมต้นทาง']);
    }

    // 2. ตรวจสอบว่ามีกำลังสอนในปีปัจจุบันหรือไม่
    $stmtCheckCurrent = $pdo->prepare("
      SELECT COUNT(*) FROM teaching_loads 
      WHERE academic_year_id = ? AND term_no = ?
    ");
    $stmtCheckCurrent->execute([$year_id, $term_no]);
    $currentCount = (int)$stmtCheckCurrent->fetchColumn();

    if ($currentCount === 0) {
      $pdo->rollBack();
      respond(['error' => '❌ ยังไม่มีกำลังสอนในปี/เทอมปัจจุบัน\n\n💡 กรุณาไปสร้างกำลังสอนก่อน (หน้า "กำหนดกำลังสอน")']);
    }

    // 3. ตรวจสอบว่ามีกำลังสอนในเทอมต้นทางหรือไม่
    $stmtCheckSource = $pdo->prepare("
      SELECT COUNT(*) FROM teaching_loads 
      WHERE academic_year_id = ? AND term_no = ?
    ");
    $stmtCheckSource->execute([$source_year_id, $source_term_no]);
    $sourceCount = (int)$stmtCheckSource->fetchColumn();

    if ($sourceCount === 0) {
      $pdo->rollBack();
      respond(['error' => '❌ ไม่มีกำลังสอนในเทอมต้นทาง\n\n💡 ไม่มีข้อมูลให้คัดลอก']);
    }

    // 4. ตรวจสอบว่ากำลังสอนเหมือนกันหรือไม่
    $stmtCurrentLoads = $pdo->prepare("
      SELECT class_id, teacher_id, subject_id, periods_per_week
      FROM teaching_loads 
      WHERE academic_year_id = ? AND term_no = ?
      ORDER BY class_id, teacher_id, subject_id
    ");
    
    $stmtCurrentLoads->execute([$year_id, $term_no]);
    $currentLoads = $stmtCurrentLoads->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtCurrentLoads->execute([$source_year_id, $source_term_no]);
    $sourceLoads = $stmtCurrentLoads->fetchAll(PDO::FETCH_ASSOC);

    // สร้าง signature
    $makeSig = function($loads) {
      $sigs = [];
      foreach ($loads as $l) {
        $sigs[] = sprintf('%d-%d-%d-%d', 
          (int)$l['class_id'], 
          (int)$l['teacher_id'], 
          (int)$l['subject_id'],
          (int)$l['periods_per_week']
        );
      }
      sort($sigs);
      return $sigs;
    };

    $currentSig = $makeSig($currentLoads);
    $sourceSig = $makeSig($sourceLoads);

    if ($currentSig !== $sourceSig) {
      $pdo->rollBack();
      
      $missing = array_diff($sourceSig, $currentSig);
      $extra = array_diff($currentSig, $sourceSig);
      
      $msg = "⚠️ กำลังสอนไม่เหมือนกัน ไม่สามารถคัดลอกได้\n\n";
      
      if (!empty($missing)) {
        $msg .= "🔴 กำลังสอนที่หายไป: " . count($missing) . " รายการ\n";
        $samples = array_slice($missing, 0, 3);
        foreach ($samples as $sig) {
          list($cid, $tid, $sid, $pw) = explode('-', $sig);
          $stmtDetail = $pdo->prepare("
            SELECT c.class_name, t.first_name, t.last_name, s.subject_name
            FROM classes c, teachers t, subjects s
            WHERE c.id = ? AND t.id = ? AND s.id = ?
          ");
          $stmtDetail->execute([$cid, $tid, $sid]);
          $detail = $stmtDetail->fetch();
          if ($detail) {
            $msg .= sprintf("  • %s - %s (%s %s) - %d คาบ/สัปดาห์\n",
              $detail['class_name'],
              $detail['subject_name'],
              $detail['first_name'],
              $detail['last_name'],
              $pw
            );
          }
        }
        if (count($missing) > 3) {
          $msg .= "  ... และอีก " . (count($missing) - 3) . " รายการ\n";
        }
      }
      
      if (!empty($extra)) {
        $msg .= "\n🟢 กำลังสอนที่เพิ่มมา: " . count($extra) . " รายการ\n";
      }
      
      $msg .= "\n💡 กรุณาไปหน้ากำหนดกำลังสอน และสร้างให้เหมือนกันก่อนคัดลอก";
      
      respond(['error' => $msg]);
    }

    // 5. ตรวจสอบว่ามีตารางให้คัดลอกหรือไม่
    $stmtCheckSlots = $pdo->prepare("
      SELECT COUNT(*) FROM timetable_slots
      WHERE academic_year_id = ? AND term_no = ?
    ");
    $stmtCheckSlots->execute([$source_year_id, $source_term_no]);
    $slotsCount = (int)$stmtCheckSlots->fetchColumn();

    if ($slotsCount === 0) {
      $pdo->rollBack();
      respond(['error' => '⚠️ ไม่มีตารางสอนในเทอมต้นทาง\n\n💡 ยังไม่มีข้อมูลตารางให้คัดลอก']);
    }

    // 6. ลบตารางปลายทางก่อน
    $pdo->prepare("
      DELETE st 
      FROM timetable_slot_teachers st
      JOIN timetable_slots ts ON ts.id = st.slot_id
      WHERE ts.academic_year_id = ? AND ts.term_no = ?
    ")->execute([$year_id, $term_no]);
    
    $pdo->prepare("
      DELETE FROM timetable_slots 
      WHERE academic_year_id = ? AND term_no = ?
    ")->execute([$year_id, $term_no]);

    // 7. คัดลอก timetable_slots
    $stmtSlots = $pdo->prepare("
      SELECT * FROM timetable_slots
      WHERE academic_year_id = ? AND term_no = ?
      ORDER BY id
    ");
    $stmtSlots->execute([$source_year_id, $source_term_no]);
    $slots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

    $stmtInsSlot = $pdo->prepare("
      INSERT INTO timetable_slots 
        (academic_year_id, term_no, day_of_week, period_no, class_id, 
         teacher_id, subject_name, room_id, source)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtSlotTeachers = $pdo->prepare("
      SELECT teacher_id FROM timetable_slot_teachers WHERE slot_id = ?
    ");

    $stmtInsSlotTeacher = $pdo->prepare("
      INSERT INTO timetable_slot_teachers (slot_id, teacher_id) VALUES (?, ?)
    ");

    foreach ($slots as $slot) {
      $oldSlotId = (int)$slot['id'];
      
      $stmtInsSlot->execute([
        $year_id,
        $term_no,
        (int)$slot['day_of_week'],
        (int)$slot['period_no'],
        (int)$slot['class_id'],
        (int)$slot['teacher_id'],
        $slot['subject_name'],
        $slot['room_id'] ? (int)$slot['room_id'] : null,
        'manual'
      ]);
      
      $newSlotId = (int)$pdo->lastInsertId();

      $stmtSlotTeachers->execute([$oldSlotId]);
      $teachers = $stmtSlotTeachers->fetchAll(PDO::FETCH_COLUMN);
      
      foreach ($teachers as $tid) {
        $stmtInsSlotTeacher->execute([$newSlotId, (int)$tid]);
      }
    }

    $pdo->commit();
    
    respond([
      'ok' => true, 
      'message' => sprintf(
        "✅ คัดลอกเรียบร้อย\n\n📦 คัดลอกมา: %d คาบ\n✓ กำลังสอนตรงกัน 100%%",
        count($slots)
      )
    ]);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage()]);
  }
}

/* --------------- ENGINE ---------------- */
// ✅ ป้องกัน timeout และ memory สำหรับการจัดตาราง (จำเป็นต้องตั้งก่อน action guard)
set_time_limit(300);           // 5 นาที (พอสำหรับโรงเรียนใหญ่)
ignore_user_abort(true);       // ไม่หยุดถ้า browser ปิด — transaction ต้อง commit ให้ครบ
ini_set('memory_limit', '256M'); // default 128M อาจไม่พอสำหรับข้อมูลขนาดใหญ่

// ✅ ป้องกัน admin กด "จัดอัตโนมัติ" พร้อมกัน — ใช้ lock file ต่อ year+term
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tt_run_{$year_id}_{$term_no}.lock";
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
  if ($lockFp) fclose($lockFp);
  respond(['error' => '⏳ กำลังจัดตารางอยู่แล้ว (โดย admin คนอื่น) กรุณารอสักครู่แล้วลองใหม่']);
}
// คืน lock อัตโนมัติเมื่อ script จบ (ทั้ง commit, error, หรือ timeout)
register_shutdown_function(function() use ($lockFp, $lockFile) {
  flock($lockFp, LOCK_UN);
  fclose($lockFp);
  @unlink($lockFile);
});

$optimizerPath = __DIR__.'/../app/timetable_optimizer.php';
if (!file_exists($optimizerPath)) {
  respond(['error' => "ไม่พบไฟล์: $optimizerPath"]);
}
require_once $optimizerPath;

$periods = $pdo->query("SELECT period_no FROM period_slots ORDER BY period_no")->fetchAll(PDO::FETCH_COLUMN);
if (!$periods) respond(['error'=>'ยังไม่ได้กำหนดคาบเรียน']);

$classes  = $pdo->query("SELECT c.id, c.class_name, c.grade_label, COALESCE(r.building,'') AS class_building FROM classes c LEFT JOIN rooms r ON r.id = c.homeroom_room_id")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
$teachers = $pdo->query("SELECT id, first_name, last_name FROM teachers")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

// ✅ สร้าง Maps แทน prepared statements
$maps = buildTimetableMaps($pdo, $year_id, $term_no);

// ดึงคาบพักทั้งหมด
$gradeBreaksAll = $pdo->query("SELECT grade_label, period_no FROM grade_breaks")->fetchAll();
$breakCache = [];
foreach ($gradeBreaksAll as $br) {
  $breakCache[$br['grade_label']][] = (int)$br['period_no'];
}

$LUNCH_SET = [4,5,6];
$MAX_CONSEC_TEACH = 4;

$getBreaks=function(string $g) use(&$breakCache){
  return $breakCache[$g] ?? [];
};

// ✅ ใช้ Maps แทน queries
$canPlaceConsecutive = function(int $cid, array $tids, int $day, int $startPeriod, int $span, array $breaks, $room_id) use (&$maps) {
  for ($i = 0; $i < $span; $i++) {
    $p = $startPeriod + $i;
    
    if (in_array($p, $breaks, true)) return false;
    if ($room_id && isset($maps['roomBusy'][$day][$p][$room_id])) return false;
    if (isset($maps['classBusy'][$day][$p][$cid])) return false;
    
    foreach ($tids as $tid) {
      // ✅ ตรวจสอบ Teacher Constraints
      if (isset($maps['teacherConstraints'][$tid])) {
        $constraints = $maps['teacherConstraints'][$tid];
        
        // 1. วันที่ครูไม่ว่างทั้งวัน
        if (in_array($day, $constraints['unavailable_days'] ?? [], true)) {
          return false;
        }
        
        // 2. ตรวจคาบที่ครูไม่ว่างทุกวัน
        if (in_array($p, $constraints['unavailable_periods'] ?? [], true)) {
          return false;
        }
        
        // 3. slot เฉพาะ (วัน + คาบ)
        if (isset($constraints['unavailable_slots'][$day][$p])) {
          return false;
        }
      }
      
      // ตรวจสอบปกติ
      if (isset($maps['teacherBusy'][$day][$p][$tid])) return false;
      if (isset($maps['teacherActivity'][$day][$p][$tid])) return false;
      if (isset($maps['teacherDuty'][$day][$p][$tid])) return false; // ครูติดเวร
    }
    
    if (isset($maps['classActivity'][$day][$p][$cid])) return false;
  }
  
  return true;
};

$violatesConsec = function(int $tid,int $day,array $newPeriods) use(&$maps, $MAX_CONSEC_TEACH){
  $arr = [];
  foreach (($maps['teacherBusy'][$day] ?? []) as $p => $teachers) {
    if (isset($teachers[$tid])) $arr[] = $p;
  }
  // BUG FIX: รวมคาบกิจกรรมด้วย ครูทำงานต่อเนื่องรวมกิจกรรมต้องนับด้วย
  foreach (($maps['teacherActivity'][$day] ?? []) as $p => $teachers) {
    if (isset($teachers[$tid])) $arr[] = $p;
  }
  $arr = array_values(array_unique(array_merge($arr, $newPeriods)));
  sort($arr);
  $max=$run=1; 
  for($i=1;$i<count($arr);$i++){ 
    $run=($arr[$i]===$arr[$i-1]+1)?$run+1:1; 
    $max=max($max,$run); 
  }
  return $max>$MAX_CONSEC_TEACH;
};
$violatesLunch = function(int $tid,int $day,array $newPeriods) use(&$maps, $LUNCH_SET){
  $arr = [];
  foreach (($maps['teacherBusy'][$day] ?? []) as $p => $teachers) {
    if (isset($teachers[$tid])) $arr[] = $p;
  }
  // BUG FIX: รวมคาบกิจกรรมด้วย กิจกรรมช่วง lunch ก็ต้องนับ
  foreach (($maps['teacherActivity'][$day] ?? []) as $p => $teachers) {
    if (isset($teachers[$tid])) $arr[] = $p;
  }
  $arr = array_values(array_unique(array_merge($arr, $newPeriods)));
  $cnt=0; 
  foreach($arr as $p) if(in_array($p,$LUNCH_SET,true)) $cnt++;
  return $cnt>=3;
};

// ตรวจว่าครูต้องข้ามตึกในคาบติดกัน
// ใช้ rooms.building ของห้องเรียนประจำ (classes.homeroom_room_id → rooms.building)
// และ building ของสถานที่กิจกรรม (activity_groups.room_id → rooms.building)
// ถ้า curBuilding ว่างเปล่า (ไม่ได้กำหนดอาคาร) จะข้ามการตรวจไป
$violatesCross = function(int $tid, int $day, int $p, string $curBuilding) use (&$maps) {
  if ($curBuilding === '') return false;
  $neighbors = [max(1, $p - 1), $p + 1];
  foreach ($neighbors as $np) {
    if (isset($maps['teacherPeriodBuilding'][$day][$np][$tid])) {
      $b = $maps['teacherPeriodBuilding'][$day][$np][$tid];
      if ($b !== '' && $b !== $curBuilding) return true;
    }
    if (isset($maps['teacherActivityBuilding'][$day][$np][$tid])) {
      $b = $maps['teacherActivityBuilding'][$day][$np][$tid];
      if ($b !== '' && $b !== $curBuilding) return true;
    }
  }
  return false;
};

$pairStmt = $pdo->prepare("SELECT DISTINCT CASE WHEN p.main_load_id=? THEN p.co_load_id WHEN p.co_load_id=? THEN p.main_load_id END AS other_load_id FROM co_teaching_pairs p WHERE p.year_id=? AND p.term_no=? AND p.class_id=? AND p.subject_id=? AND (p.main_load_id=? OR p.co_load_id=?)");
$getTeacherByLoad=$pdo->prepare("SELECT teacher_id FROM teaching_loads WHERE id=? LIMIT 1");
$coTeachers=function(array $L) use($pairStmt,$getTeacherByLoad,$year_id,$term_no){
  $ids=[(int)$L['teacher_id']];
  $pairStmt->execute([(int)$L['id'],(int)$L['id'],$year_id,$term_no,(int)$L['class_id'],(int)$L['subject_id'],(int)$L['id'],(int)$L['id']]);
  foreach(array_filter($pairStmt->fetchAll(PDO::FETCH_COLUMN)) as $lid){
    $getTeacherByLoad->execute([(int)$lid]);
    $tid=(int)$getTeacherByLoad->fetchColumn(); if($tid) $ids[]=$tid;
  }
  return array_values(array_unique($ids));
};

$loadsStmt = $pdo->prepare("SELECT tl.id, tl.class_id, tl.teacher_id, tl.subject_id, tl.room_id, tl.periods_per_week, tl.consecutive_slots, c.class_name, c.grade_label, COALESCE(hr.building,'') AS class_building, CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS label FROM teaching_loads tl JOIN subjects s ON s.id=tl.subject_id JOIN classes c ON c.id=tl.class_id LEFT JOIN rooms hr ON hr.id = c.homeroom_room_id WHERE tl.academic_year_id=? AND tl.term_no=? ORDER BY c.class_name, s.subject_name");
$loadsStmt->execute([$year_id,$term_no]);
$loads=$loadsStmt->fetchAll();

// ✅ เพิ่ม $usedCountStmt
$usedCountStmt = $pdo->prepare("
  SELECT COUNT(DISTINCT ts.id) 
  FROM timetable_slots ts 
  WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.class_id=? AND ts.subject_name=?
");

$processedPairs = [];
$remain=[];
foreach($loads as $L){
  $pairKey = (int)$L['class_id'] . '_' . (int)$L['subject_id'];
  if (isset($processedPairs[$pairKey])) continue;
  
  $usedCountStmt->execute([$year_id,$term_no,(int)$L['class_id'],$L['label']]);
  $used=(int)$usedCountStmt->fetchColumn();
  $left=max(0,((int)$L['periods_per_week'])-$used);
  
  if($left>0){ 
    $L['left']=$left; 
    $remain[]=$L; 
    $processedPairs[$pairKey] = true;
  }
}

usort($remain, function($a, $b) {
  if ($a['room_id'] && !$b['room_id']) return -1;
  if (!$a['room_id'] && $b['room_id']) return 1;
  $consec = ((int)($b['consecutive_slots'] ?? 1)) <=> ((int)($a['consecutive_slots'] ?? 1));
  if ($consec !== 0) return $consec;
  return ((int)($b['left'] ?? 0)) <=> ((int)($a['left'] ?? 0));
});

$insSlot=$pdo->prepare("INSERT INTO timetable_slots (academic_year_id,term_no,day_of_week,period_no,class_id,teacher_id,subject_name,room_id,source) VALUES (?,?,?,?,?,?,?,?,?)");
$insMap =$pdo->prepare("INSERT INTO timetable_slot_teachers (slot_id, teacher_id) VALUES (?,?)");

$placed=0; $attempt=count($remain); $fails=[]; $logs=[];
$failedLoads=[]; // เก็บรายการที่ยังลงไม่ครบหลังครบทุก pass

// BUG FIX: Precompute total load ต่อครูจาก $loads (คงที่ตลอด run)
// เดิมใช้ &$remain ซึ่งลดลงเรื่อยๆ ทำให้ scoring กระจาย 5 วัน อ่อนลงในช่วงหลัง
$teacherTotalLoads = [];
foreach ($loads as $_L) {
  $_tid = (int)$_L['teacher_id'];
  $teacherTotalLoads[$_tid] = ($teacherTotalLoads[$_tid] ?? 0) + (int)$_L['periods_per_week'];
}

// ✅ ฟังก์ชันนับจำนวนวันที่ครูสอน (รวมวันที่มีกิจกรรมด้วย)
$getTeacherDayDistribution = function(int $tid) use (&$maps) {
  $daysWithClasses = [];
  foreach (($maps['teacherBusy'] ?? []) as $day => $periods) {
    foreach ($periods as $p => $teachers) {
      if (isset($teachers[$tid])) {
        $daysWithClasses[$day] = true;
        break;
      }
    }
  }
  // BUG FIX: รวมวันที่มีกิจกรรมด้วย เพื่อ scoring กระจาย 5 วันจะถูกต้อง
  foreach (($maps['teacherActivity'] ?? []) as $day => $periods) {
    foreach ($periods as $p => $teachers) {
      if (isset($teachers[$tid])) {
        $daysWithClasses[$day] = true;
        break;
      }
    }
  }
  return array_keys($daysWithClasses);
};

// ✅ ฟังก์ชันนับภาระรวมของครู (ใช้ค่าคงที่จาก precompute ไม่ใช่ $remain ที่ลดลง)
$getTotalTeacherLoad = function(int $tid) use ($teacherTotalLoads) {
  return $teacherTotalLoads[$tid] ?? 0;
};

// ✅ Phase 1: Multi-Criteria Weighted Scoring
$scoreSlot = function(int $cid, int $tid, string $subj, int $day, int $period, bool $hasSameSubjectToday=false) use (&$maps, &$getTeacherDayDistribution, &$getTotalTeacherLoad) {
  $score = 0;
  
  // 1. กระจายวัน
  $sameDayCount = 0;
  foreach (($maps['subjectDay'][$day][$cid] ?? []) as $s => $v) {
    if ($s === $subj) $sameDayCount++;
  }
  $score += $sameDayCount * 10;
  
  // 2. กระจายคาบ
  $samePeriodCount = 0;
  foreach (($maps['classBusy'] ?? []) as $d => $periods) {
    if (isset($periods[$period][$cid])) $samePeriodCount++;
  }
  $score += $samePeriodCount * 5;
  
  // 3. หลีกเลี่ยงคาบเช้า/เย็น
  if ($period === 1 || $period >= 7) $score += 3;
  
  // 4. ส่งเสริมคาบกลางวัน
  if ($period >= 3 && $period <= 5) $score -= 2;
  
  // ✅ 5. กระจายภาระครู (ปรับปรุงแล้ว)
  $teacherDailyLoad = 0;
  foreach (($maps['teacherBusy'][$day] ?? []) as $p => $teachers) {
    if (isset($teachers[$tid])) {
      $teacherDailyLoad++;
    }
  }
  if ($teacherDailyLoad >= 5) $score += 7;
  
  // ✅ 5.1 บังคับกระจายวัน - ถ้าครูมีคาบสอน > 5 แต่ยังไม่ครบ 5 วัน
  $totalLoad = $getTotalTeacherLoad($tid);
  $currentDays = $getTeacherDayDistribution($tid);
  $numDays = count($currentDays);
  
  // ถ้ามีภาระ > 5 คาบ ควรกระจาย 5 วัน
  if ($totalLoad > 5) {
    // ถ้ายังสอนไม่ครบ 5 วัน และวันนี้ยังไม่เคยสอน → ให้คะแนนดีมาก
    if ($numDays < 5 && !in_array($day, $currentDays, true)) {
      $score -= 20; // ลดคะแนนมาก = ดีมาก (ส่งเสริมการกระจาย)
    }
    // ถ้าสอนครบ 5 วันแล้ว หรือวันนี้เคยสอนแล้ว → ลดการลงซ้ำในวันเดิม
    elseif ($numDays >= 5 && in_array($day, $currentDays, true)) {
      $score -= 3; // ลงซ้ำในวันที่เคยสอนแล้ว
    }
    // ถ้าสอนครบ 5 วันแล้ว แต่พยายามเพิ่มวันที่ 6 → ลงโทษเบา
    elseif ($numDays >= 5 && !in_array($day, $currentDays, true)) {
      $score += 5; // ไม่ควรขยายเกิน 5 วัน
    }
  }
  
  // ✅ 6. ส่งเสริมคาบติดกัน (น้ำหนัก -5 = ดีมาก)
  $hasAdjacentSameSubject = false;
  
  // ตรวจคาบก่อนหน้า (period - 1)
  if ($period > 1 && isset($maps['subjectDay'][$day][$cid][$subj])) {
    foreach (($maps['classBusy'][$day][$period - 1] ?? []) as $c => $v) {
      if ($c === $cid) {
        $hasAdjacentSameSubject = true;
        break;
      }
    }
  }
  
  // ตรวจคาบถัดไป (period + 1)
  if (!$hasAdjacentSameSubject && isset($maps['classBusy'][$day][$period + 1])) {
    foreach (($maps['classBusy'][$day][$period + 1] ?? []) as $c => $v) {
      if ($c === $cid) {
        $hasAdjacentSameSubject = true;
        break;
      }
    }
  }
  
  // ถ้ามีคาบติดกัน → ให้คะแนนดี
  if ($hasAdjacentSameSubject) {
    $score -= 5; // ลดคะแนน = ดีขึ้น
  }

  // ✅ 7. (Soft) ถ้าวิชาเดียวกันในวันเดียวกันแล้ว ให้ลงโทษหนัก
  // ใช้ร่วมกับโหมดผ่อนคลายในรอบสุดท้าย เพื่อเพิ่มโอกาสสำเร็จแต่ยังพยายามหลีกเลี่ยง
  if ($hasSameSubjectToday) {
    $score += 50;
  }

  // ✅ 8. (Soft) เลี่ยงวิชากลุ่มเดียวกัน (หลัก/เสริม) เรียนติดกัน
  // เช่น คาบนี้คณิตหลัก คาบติดกันไม่ควรเป็นคณิตเสริม — ให้เด็กพักสมองอย่างน้อย 1 คาบ
  // ลงโทษหนักแต่เป็น soft (ยังลงได้ถ้าไม่มีทางเลือกอื่น เพื่อไม่ให้จัดล้มเหลว)
  $family = tt_subject_family($subj);
  if ($family !== null) {
    foreach ([$period - 1, $period + 1] as $adjP) {
      $neighbor = $maps['classPeriodSubject'][$day][$adjP][$cid] ?? null;
      if ($neighbor !== null && $neighbor !== $subj && tt_subject_family($neighbor) === $family) {
        $score += 40;
        break;
      }
    }
  }

  return $score;
};

// ✅ บังคับเงื่อนไข: วิชาเดียวกัน + ครูคนเดิม (รวม co-teaching) ห้ามซ้ำในวันเดียวกัน (ต่อห้อง)
$hasSameSubjectTeacherToday = function(int $day, int $classId, string $subjectLabel, array $teacherIds) use (&$maps): bool {
  foreach ($teacherIds as $tid) {
    if (isset($maps['subjectTeacherDay'][$day][$classId][$subjectLabel][(int)$tid])) return true;
  }
  return false;
};

// ✅ บังคับเงื่อนไข: วิชาเดิมของห้องเดียวกันห้ามลงซ้ำในวันเดียวกัน
// (ยกเลิกโหมดผ่อนคลายในรอบสุดท้าย เพื่อไม่ให้เกิดวิชาซ้ำวันเดียวกัน)
$RELAX_SUBJECT_DAY_ON_LAST_PASS = false;

// ✅ บังคับเงื่อนไข: วิชาเรียนติดกันได้สูงสุด 1 คาบ (เพื่อกันซ้ำวันเดียวกัน)
$MAX_CONSECUTIVE_SLOTS = 1;

// ✅ Repair phase: เปิดเพื่อเพิ่มโอกาสสำเร็จ โดยย้ายคาบ auto ที่ขวางอยู่ (จำกัดขอบเขต)
$ENABLE_REPAIR = true;
$REPAIR_MAX_LOADS = 50;          // ซ่อมสูงสุดกี่รายการที่ล้มเหลว (ป้องกันช้าเกินไป)
$REPAIR_MAX_CANDIDATES = 250;    // สำรวจ slot ได้สูงสุดต่อ “หนึ่งครั้งวาง”
$REPAIR_MAX_BLOCKER_TRIES = 200; // จำนวนครั้งหาที่ว่างใหม่ให้คาบที่ขวาง

// ✅ MRV (Minimum Remaining Values): จัด “งานยากก่อน” โดยประเมินจำนวนช่องที่ลงได้
$estimateFeasibleCount = function(array $L, int $passNo, int $maxPasses) use (
  &$maps,
  $coTeachers,
  $getBreaks,
  $periods,
  $canPlaceConsecutive,
  $violatesConsec,
  $violatesLunch,
  $violatesCross,
  &$classes,
  &$teachers,
  $RELAX_SUBJECT_DAY_ON_LAST_PASS,
  $MAX_CONSECUTIVE_SLOTS,
  $hasSameSubjectTeacherToday
) {
  $tids = $coTeachers($L);
  $room_id = !empty($L['room_id']) ? (int)$L['room_id'] : null;
  $breaks = $getBreaks($L['grade_label']);
  $consec = min($MAX_CONSECUTIVE_SLOTS, max(1, (int)($L['consecutive_slots'] ?? 1)));
  $slotsNeeded = (int)($L['left'] ?? 0);
  $span = min($consec, $slotsNeeded > 0 ? $slotsNeeded : $consec);
  if ($span <= 0) $span = 1;

  $maxPeriod = max(array_map('intval', $periods));
  $count = 0;
  $days = [1,2,3,4,5];

  foreach ($days as $d) {
    foreach ($periods as $pRaw) {
      $p = (int)$pRaw;

      if ($span > 1) {
        if ($p + $span - 1 > $maxPeriod) continue;
        if (!$canPlaceConsecutive((int)$L['class_id'], $tids, $d, $p, $span, $breaks, $room_id)) continue;
      } else {
        if (in_array($p, $breaks, true)) continue;
        if ($room_id && isset($maps['roomBusy'][$d][$p][$room_id])) continue;
        if (isset($maps['classBusy'][$d][$p][(int)$L['class_id']])) continue;

        foreach ($tids as $tid) {
          if (isset($maps['teacherConstraints'][$tid])) {
            $constraints = $maps['teacherConstraints'][$tid];
            if (in_array($d, $constraints['unavailable_days'] ?? [], true)) continue 2;
            if (in_array($p, $constraints['unavailable_periods'] ?? [], true)) continue 2;
            if (isset($constraints['unavailable_slots'][$d][$p])) continue 2;
          }
          if (isset($maps['teacherBusy'][$d][$p][$tid])) continue 2;
          if (isset($maps['teacherActivity'][$d][$p][$tid])) continue 2;
          if (isset($maps['teacherDuty'][$d][$p][$tid])) continue 2; // ครูติดเวร
        }
        if (isset($maps['classActivity'][$d][$p][(int)$L['class_id']])) continue;
      }

      $periodsToCheck = range($p, $p + $span - 1);
      foreach ($tids as $tid) {
        if ($violatesConsec($tid, $d, $periodsToCheck)) continue 2;
        if ($violatesLunch($tid, $d, $periodsToCheck)) continue 2;
        if ($violatesCross($tid, $d, $p, (string)($L['class_building'] ?? ''))) continue 2;
      }

      $hasSameSubjectToday = isset($maps['subjectDay'][$d][(int)$L['class_id']][$L['label']]);
      if ($hasSameSubjectTeacherToday((int)$d, (int)$L['class_id'], (string)$L['label'], $tids)) {
        continue;
      }

      $count++;
    }
  }

  return $count;
};

try{
  $pdo->beginTransaction();

  $passCount = 0;

  while ($passCount < $MAX_PASSES && count($remain) > 0) {
    $passCount++;
    $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    $logs[] = "🔄 รอบที่ $passCount (เหลือ ".count($remain)." รายการ)";
    $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

    // ✅ จัดลำดับใหม่ทุก pass: งานยากก่อน (MRV) เพื่อเพิ่มโอกาสสำเร็จ
    foreach ($remain as &$tmpL) {
      $tmpL['_mrv'] = $estimateFeasibleCount($tmpL, $passCount, $MAX_PASSES);
    }
    unset($tmpL);

    usort($remain, function($a, $b) {
      $am = (int)($a['_mrv'] ?? 0);
      $bm = (int)($b['_mrv'] ?? 0);
      if ($am !== $bm) return $am <=> $bm; // ช่องน้อยก่อน

      if (!empty($a['room_id']) && empty($b['room_id'])) return -1;
      if (empty($a['room_id']) && !empty($b['room_id'])) return 1;
      $consec = ((int)($b['consecutive_slots'] ?? 1)) <=> ((int)($a['consecutive_slots'] ?? 1));
      if ($consec !== 0) return $consec;
      return ((int)($b['left'] ?? 0)) <=> ((int)($a['left'] ?? 0));
    });

    // โหมดผ่อนคลายวิชาเดิมซ้ำวันเดียวกันถูกปิดไว้โดยตั้งใจ
    
    $newRemain = [];
    $successCountThisPass = 0;

    foreach($remain as $idx => $L){
      $tids=$coTeachers($L);
      $room_id=!empty($L['room_id'])?(int)$L['room_id']:null;
      $breaks=$getBreaks($L['grade_label']);
      $consec = min($MAX_CONSECUTIVE_SLOTS, max(1, (int)($L['consecutive_slots'] ?? 1)));
      $slotsNeeded = (int)$L['left'];
      
      $timesToPlace = (int)ceil($slotsNeeded / $consec);
      
      $logs[] = sprintf("[P%d-%d/%d] %s · %s · ครู %s · เหลือ %d คาบ", 
        $passCount, $idx+1, count($remain), $L['class_name'], $L['label'], 
        implode(', ', array_map(fn($t) => ($teachers[$t]['first_name'] ?? ''), $tids)),
        $slotsNeeded
      );

      $successThisLoad = false;

      for($k=0; $k < $timesToPlace; $k++){
        $alreadyPlaced = $k * $consec;
        $slotsThisRound = min($consec, $slotsNeeded - $alreadyPlaced);
        if ($slotsThisRound <= 0) break;

        $ok=false; $why='';
        $candidates = [];
        
        $days = [1,2,3,4,5];
        shuffle($days);
        
        foreach($days as $d){
          $periodsToTry = $periods;
          shuffle($periodsToTry);
          
          foreach($periodsToTry as $p){
            $p=(int)$p;
            
            if ($slotsThisRound > 1) {
              $maxPeriod = max(array_map('intval', $periods));
              if ($p + $slotsThisRound - 1 > $maxPeriod) continue;
              if (!$canPlaceConsecutive((int)$L['class_id'], $tids, $d, $p, $slotsThisRound, $breaks, $room_id)) continue;
            } else {
              // ✅ ตรวจสอบข้อจำกัดพื้นฐาน
              if(in_array($p,$breaks,true)){ $why='คาบพักของชั้น'; continue; }
              if($room_id && isset($maps['roomBusy'][$d][$p][$room_id])){ $why='ห้องเฉพาะชน'; continue; }
              if(isset($maps['classBusy'][$d][$p][(int)$L['class_id']])){ $why='ห้องเรียนชน'; continue; }
              
              $conf=false; $whyT='';
              foreach($tids as $tid){
                // ✅ 1. ตรวจสอบวันที่ครูไม่ว่าง
                if (isset($maps['teacherConstraints'][$tid])) {
                  $constraints = $maps['teacherConstraints'][$tid];
                  
                  // 1. วันที่ครูไม่ว่างทั้งวัน
                  if (in_array($d, $constraints['unavailable_days'] ?? [], true)) {
                    $conf = true;
                    $whyT = 'ครูไม่ว่างในวันนี้';
                    break;
                  }
                  
                  // 2. คาบที่ครูไม่ว่างทุกวัน
                  if (in_array($p, $constraints['unavailable_periods'] ?? [], true)) {
                    $conf = true;
                    $whyT = 'ครูไม่ว่างในคาบนี้';
                    break;
                  }
                  
                  // 3. slot เฉพาะ (วัน + คาบ)
                  if (isset($constraints['unavailable_slots'][$d][$p])) {
                    $conf = true;
                    $whyT = 'ครูไม่ว่างในวัน-คาบนี้';
                    break;
                  }
                }
                
                // ตรวจสอบปกติ
                if(isset($maps['teacherBusy'][$d][$p][$tid])){ $conf=true; $whyT='ครูติดคาบ'; break; }
                if(isset($maps['teacherActivity'][$d][$p][$tid])){ $conf=true; $whyT='ติดกิจกรรมครู'; break; }
                if(isset($maps['teacherDuty'][$d][$p][$tid])){ $conf=true; $whyT='ครูติดเวร'; break; }
              }
              if($conf){ $why=$whyT; continue; }
              if(isset($maps['classActivity'][$d][$p][(int)$L['class_id']])){ $why='ติดกิจกรรมห้อง'; continue; }
            }

            $periodsToCheck = range($p, $p + $slotsThisRound - 1);
            $conf=false; $whyT='';
            foreach($tids as $tid){
              if($violatesConsec($tid,$d,$periodsToCheck)){ $conf=true; $whyT='เกิน 4 คาบติดกัน'; break; }
              if($violatesLunch($tid,$d,$periodsToCheck)){ $conf=true; $whyT='คาบ 4-5-6 ไม่มีว่าง'; break; }
              if($violatesCross($tid,$d,$p,(string)($L['class_building'] ?? ''))){ $conf=true; $whyT='ข้ามตึกคาบติดกัน'; break; }
            }
            if($conf){ $why=$whyT; continue; }

            $hasSameSubjectToday = isset($maps['subjectDay'][$d][(int)$L['class_id']][$L['label']]);
            if ($hasSameSubjectTeacherToday((int)$d, (int)$L['class_id'], (string)$L['label'], $tids)) {
              $why='วิชา+ครูซ้ำวันเดียวกัน';
              continue;
            }

            $score = $scoreSlot((int)$L['class_id'], (int)$L['teacher_id'], $L['label'], $d, $p, $hasSameSubjectToday);
            $candidates[] = ['day' => $d, 'period' => $p, 'score' => $score, 'span' => $slotsThisRound];
          }
        }

        usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);

        if (!empty($candidates)) {
          $best = $candidates[0];
          $d = $best['day'];
          $p = $best['period'];
          $span = $best['span'];
          
          for ($i = 0; $i < $span; $i++) {
            $currentPeriod = $p + $i;
            $insSlot->execute([$year_id, $term_no, $d, $currentPeriod, (int)$L['class_id'], (int)$L['teacher_id'], $L['label'], $room_id, 'auto']);
            $slot = (int)$pdo->lastInsertId();
            foreach ($tids as $tid) $insMap->execute([$slot, $tid]);
            
            // ✅ อัปเดท Maps หลัง insert (ส่ง grade_label เพื่ออัป teacherPeriodGrade)
            updateMapsAfterInsert($maps, $d, $currentPeriod, (int)$L['class_id'], $tids, $room_id, $L['label'], (string)($L['class_building'] ?? ''));
            
            $placed++;
          }
          $ok = true;
          $successThisLoad = true;
          $logs[] = "  ✓ ลงสำเร็จ: วัน $d คาบ $p-".($p+$span-1)." (คะแนน: {$best['score']})";
        }

        if(!$ok){
          $logs[] = "  ⚠️ ไม่พบช่องว่าง: ".$why;
          break;
        }
      }

      if ($successThisLoad) {
        $successCountThisPass++;
      } else {
        if ($passCount < $MAX_PASSES) {
          $newRemain[] = $L;
          $logs[] = "  ⏸️ เก็บไว้ลองรอบหน้า";
        } else {
          // เก็บไว้ทำ Repair phase (ยังไม่สรุปเป็น fails จนกว่าจะจบ repair)
          $failedLoads[] = ['L' => $L, 'tids' => $tids, 'why' => $why];
          $logs[] = "  ✗ ติดเงื่อนไขหลังครบ $MAX_PASSES รอบ: ".$why;
        }
      }
    }
    
    $logs[] = "📊 รอบที่ $passCount สรุป: ลงสำเร็จ $successCountThisPass รายการ, เหลือ ".count($newRemain)." รายการ";
    
    $remain = $newRemain;
    
    if (count($remain) === 0) {
      $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
      $logs[] = "🎉 สำเร็จ! ลงครบทุกรายการ";
      $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
      break;
    }
    
    if ($passCount > 1 && count($newRemain) > 0) {
      shuffle($newRemain);
      $logs[] = "🔀 สับเปลี่ยนลำดับรายการสำหรับรอบหน้า";
    }
  }


  /* ---------------- REPAIR PHASE ---------------- */
  if ($ENABLE_REPAIR && !empty($failedLoads)) {
    $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    $logs[] = "🛠️ Repair phase: พยายามซ่อมรายการที่ลงไม่ครบ (ย้ายคาบ auto ที่ขวาง)";
    $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

    // จำกัดจำนวนรายการ เพื่อกันช้าในข้อมูลใหญ่มาก
    if (count($failedLoads) > $REPAIR_MAX_LOADS) {
      $logs[] = "⚠️ จำกัดการซ่อม: ซ่อมแค่ $REPAIR_MAX_LOADS จาก ".count($failedLoads)." รายการ";
      $failedLoads = array_slice($failedLoads, 0, $REPAIR_MAX_LOADS);
    }

    // helper: อ่าน slot ที่ชน (เฉพาะช่องเวลานั้น)
    $qClassSlot = $pdo->prepare("SELECT id, source FROM timetable_slots WHERE academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? AND class_id=? LIMIT 5");
    $qRoomSlot  = $pdo->prepare("SELECT id, source FROM timetable_slots WHERE academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? AND room_id=? LIMIT 5");
    $qTeacherSlot = $pdo->prepare("SELECT DISTINCT ts.id, ts.source FROM timetable_slots ts JOIN timetable_slot_teachers st ON st.slot_id=ts.id WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week=? AND ts.period_no=? AND st.teacher_id=? LIMIT 10");
    $qSlot = $pdo->prepare("SELECT id, academic_year_id, term_no, day_of_week, period_no, class_id, teacher_id, subject_name, room_id, source FROM timetable_slots WHERE id=? LIMIT 1");
    $qSlotTeachers = $pdo->prepare("SELECT teacher_id FROM timetable_slot_teachers WHERE slot_id=?");

    $isMovableAutoSlot = function(?array $row) {
      if (!$row) return false;
      return ($row['source'] ?? '') === 'auto';
    };

    $getBlockingAutoSlotId = function(int $day, int $period, int $classId, array $tids, ?int $roomId) use ($qClassSlot, $qRoomSlot, $qTeacherSlot, $year_id, $term_no) {
      $blockingIds = [];

      // class blocker
      $qClassSlot->execute([$year_id, $term_no, $day, $period, $classId]);
      $classRows = $qClassSlot->fetchAll(PDO::FETCH_ASSOC);
      foreach ($classRows as $r) {
        if (($r['source'] ?? '') !== 'auto') return [null, true]; // hard block
        $blockingIds[] = (int)$r['id'];
      }

      // room blocker
      if ($roomId) {
        $qRoomSlot->execute([$year_id, $term_no, $day, $period, $roomId]);
        $roomRows = $qRoomSlot->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roomRows as $r) {
          if (($r['source'] ?? '') !== 'auto') return [null, true];
          $blockingIds[] = (int)$r['id'];
        }
      }

      // teacher blocker
      foreach ($tids as $tid) {
        $qTeacherSlot->execute([$year_id, $term_no, $day, $period, (int)$tid]);
        $tRows = $qTeacherSlot->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tRows as $r) {
          if (($r['source'] ?? '') !== 'auto') return [null, true];
          $blockingIds[] = (int)$r['id'];
        }
      }

      $blockingIds = array_values(array_unique($blockingIds));
      if (count($blockingIds) === 0) return [null, false];
      if (count($blockingIds) === 1) return [$blockingIds[0], false];

      // มีมากกว่า 1 slot ที่ชน → repair แบบง่าย (ย้าย 1 ตัว) ยังไม่รองรับ
      return [null, true];
    };

    $findNewSlotForBlocker = function(array $slotRow, array $slotTeacherIds) use (
      &$maps,
      $getBreaks,
      $periods,
      $violatesConsec,
      $violatesLunch,
      $violatesCross,
      $RELAX_SUBJECT_DAY_ON_LAST_PASS,
      $REPAIR_MAX_BLOCKER_TRIES,
      &$classes,
      $hasSameSubjectTeacherToday
    ) {
      $cid = (int)$slotRow['class_id'];
      $roomId = !empty($slotRow['room_id']) ? (int)$slotRow['room_id'] : null;
      $subj = (string)$slotRow['subject_name'];
      $grade = (string)($classes[$cid]['grade_label'] ?? '');
      $building = (string)($classes[$cid]['class_building'] ?? '');
      $breaks = $getBreaks($grade);

      $days = [1,2,3,4,5];
      shuffle($days);
      $periodsToTry = array_map('intval', $periods);
      shuffle($periodsToTry);

      $tries = 0;
      foreach ($days as $d) {
        foreach ($periodsToTry as $p) {
          $tries++;
          if ($tries > $REPAIR_MAX_BLOCKER_TRIES) break 2;

          // หลีกเลี่ยงที่เดิม
          if ($d === (int)$slotRow['day_of_week'] && $p === (int)$slotRow['period_no']) continue;
          if (in_array($p, $breaks, true)) continue;
          if ($roomId && isset($maps['roomBusy'][$d][$p][$roomId])) continue;
          if (isset($maps['classBusy'][$d][$p][$cid])) continue;
          if (isset($maps['classActivity'][$d][$p][$cid])) continue;

          // subject+teacher per-day constraint (strict)
          if ($hasSameSubjectTeacherToday((int)$d, (int)$cid, (string)$subj, $slotTeacherIds)) continue;

          // teacher constraints + busy + activity
          foreach ($slotTeacherIds as $tid) {
            if (isset($maps['teacherConstraints'][$tid])) {
              $c = $maps['teacherConstraints'][$tid];
              if (in_array($d, $c['unavailable_days'] ?? [], true)) continue 2;
              if (in_array($p, $c['unavailable_periods'] ?? [], true)) continue 2;
              if (isset($c['unavailable_slots'][$d][$p])) continue 2;
            }
            if (isset($maps['teacherBusy'][$d][$p][$tid])) continue 2;
            if (isset($maps['teacherActivity'][$d][$p][$tid])) continue 2;
            if (isset($maps['teacherDuty'][$d][$p][$tid])) continue 2; // ครูติดเวร
          }

          $periodsToCheck = [$p];
          foreach ($slotTeacherIds as $tid) {
            if ($violatesConsec($tid, $d, $periodsToCheck)) continue 2;
            if ($violatesLunch($tid, $d, $periodsToCheck)) continue 2;
            if ($violatesCross($tid, $d, $p, $building)) continue 2;
          }

          return ['day' => (int)$d, 'period' => (int)$p];
        }
      }

      return null;
    };

    $repairPlacedLoads = 0;
    $repairMoves = 0;
    $stillFailed = [];

    foreach ($failedLoads as $i => $item) {
      $L = $item['L'];
      $tids = $coTeachers($L);
      $room_id = !empty($L['room_id']) ? (int)$L['room_id'] : null;
      $breaks = $getBreaks($L['grade_label']);
      $consec = min($MAX_CONSECUTIVE_SLOTS, max(1, (int)($L['consecutive_slots'] ?? 1)));
      $slotsNeeded = (int)($L['left'] ?? 0);
      $timesToPlace = (int)ceil($slotsNeeded / $consec);

      $sp = 'sp_repair_'.$i;
      $pdo->exec("SAVEPOINT $sp");
      $placedBefore = $placed;

      $okAll = true;
      $logs[] = sprintf("[REPAIR-%d] %s · %s · เหลือ %d คาบ", $i+1, $L['class_name'], $L['label'], $slotsNeeded);

      for ($k=0; $k < $timesToPlace; $k++) {
        $alreadyPlaced = $k * $consec;
        $slotsThisRound = min($consec, $slotsNeeded - $alreadyPlaced);
        if ($slotsThisRound <= 0) break;

        // Repair phase รองรับ relocation เฉพาะ span=1 เพื่อคุมความซับซ้อน
        if ($slotsThisRound > 1) {
          $okAll = false;
          $logs[] = "  ⚠️ ข้าม repair สำหรับคาบติดกัน (span=$slotsThisRound)";
          break;
        }

        $candidates = [];
        $days = [1,2,3,4,5];
        shuffle($days);
        $periodsToTry = array_map('intval', $periods);
        shuffle($periodsToTry);

        $scanned = 0;
        foreach ($days as $d) {
          foreach ($periodsToTry as $p) {
            $scanned++;
            if ($scanned > $REPAIR_MAX_CANDIDATES) break 2;

            if (in_array($p, $breaks, true)) continue;
            if (isset($maps['classActivity'][$d][$p][(int)$L['class_id']])) continue;

            // teacher constraints + activity (hard)
            $conf = false;
            foreach ($tids as $tid) {
              if (isset($maps['teacherConstraints'][$tid])) {
                $c = $maps['teacherConstraints'][$tid];
                if (in_array($d, $c['unavailable_days'] ?? [], true)) { $conf=true; break; }
                if (in_array($p, $c['unavailable_periods'] ?? [], true)) { $conf=true; break; }
                if (isset($c['unavailable_slots'][$d][$p])) { $conf=true; break; }
              }
              if (isset($maps['teacherActivity'][$d][$p][$tid])) { $conf=true; break; }
              if (isset($maps['teacherDuty'][$d][$p][$tid])) { $conf=true; break; } // ครูติดเวร
            }
            if ($conf) continue;

            $periodsToCheck = [$p];
            foreach ($tids as $tid) {
              if ($violatesConsec($tid, $d, $periodsToCheck)) { $conf=true; break; }
              if ($violatesLunch($tid, $d, $periodsToCheck)) { $conf=true; break; }
              if ($violatesCross($tid, $d, $p, (string)($L['class_building'] ?? ''))) { $conf=true; break; }
            }
            if ($conf) continue;

            // ห้ามซ้ำวันเดียวกัน (วิชา+ครู)
            if ($hasSameSubjectTeacherToday((int)$d, (int)$L['class_id'], (string)$L['label'], $tids)) continue;

            $hasSameSubjectToday = isset($maps['subjectDay'][$d][(int)$L['class_id']][$L['label']]);

            // หา blocker (ถ้ามี) เฉพาะ auto 1 ตัว
            [$blockerId, $hardBlock] = $getBlockingAutoSlotId((int)$d, (int)$p, (int)$L['class_id'], $tids, $room_id);
            if ($hardBlock) continue;

            $score = $scoreSlot((int)$L['class_id'], (int)$L['teacher_id'], $L['label'], (int)$d, (int)$p, $hasSameSubjectToday);
            if ($blockerId) $score += 15; // มีค่าใช้จ่ายในการย้าย blocker
            $candidates[] = ['day' => (int)$d, 'period' => (int)$p, 'score' => $score, 'blocker_id' => $blockerId, 'hasSameSubjectToday' => $hasSameSubjectToday];
          }
        }

        usort($candidates, fn($a,$b) => $a['score'] <=> $b['score']);

        $placedThisRound = false;
        foreach ($candidates as $cand) {
          $d = (int)$cand['day'];
          $p = (int)$cand['period'];
          $blockerId = $cand['blocker_id'] ? (int)$cand['blocker_id'] : null;

          if ($blockerId) {
            // ดึงข้อมูล slot ที่ขวาง + ครูใน slot นั้น
            $qSlot->execute([$blockerId]);
            $blocker = $qSlot->fetch(PDO::FETCH_ASSOC);
            if (!$blocker || ($blocker['source'] ?? '') !== 'auto') continue;

            $qSlotTeachers->execute([$blockerId]);
            $blockerTids = array_map('intval', $qSlotTeachers->fetchAll(PDO::FETCH_COLUMN));
            if (!$blockerTids) {
              $blockerTids = !empty($blocker['teacher_id']) ? [(int)$blocker['teacher_id']] : [];
            }

            $newPos = $findNewSlotForBlocker($blocker, $blockerTids);
            if (!$newPos) continue;

            // ย้าย blocker
            $upd = $pdo->prepare("UPDATE timetable_slots SET day_of_week=?, period_no=? WHERE id=?");
            $upd->execute([(int)$newPos['day'], (int)$newPos['period'], $blockerId]);
            $repairMoves++;

            // rebuild maps เพื่อความถูกต้อง (subjectDay เป็น boolean ทำให้ลบยาก)
            $maps = buildTimetableMaps($pdo, $year_id, $term_no);
          }

          // ตรวจอีกครั้งว่าช่องว่างจริงหลังย้าย
          if ($room_id && isset($maps['roomBusy'][$d][$p][$room_id])) continue;
          if (isset($maps['classBusy'][$d][$p][(int)$L['class_id']])) continue;
          foreach ($tids as $tid) {
            if (isset($maps['teacherBusy'][$d][$p][$tid])) continue 2;
          }

          // ลงคาบ
          $insSlot->execute([$year_id, $term_no, $d, $p, (int)$L['class_id'], (int)$L['teacher_id'], $L['label'], $room_id, 'auto']);
          $slotId = (int)$pdo->lastInsertId();
          foreach ($tids as $tid) $insMap->execute([$slotId, (int)$tid]);
          updateMapsAfterInsert($maps, $d, $p, (int)$L['class_id'], $tids, $room_id, $L['label'], (string)($L['class_building'] ?? ''));
          $placed++;
          $placedThisRound = true;
          $logs[] = "  ✓ ซ่อมลงได้: วัน $d คาบ $p";
          break;
        }

        if (!$placedThisRound) {
          $okAll = false;
          $logs[] = "  ✗ ซ่อมไม่สำเร็จในรอบนี้";
          break;
        }
      }

      if ($okAll) {
        $pdo->exec("RELEASE SAVEPOINT $sp");
        $repairPlacedLoads++;
      } else {
        // rollback การซ่อมของรายการนี้ทั้งหมด
        $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
        $pdo->exec("RELEASE SAVEPOINT $sp");
        $placed = $placedBefore;
        $maps = buildTimetableMaps($pdo, $year_id, $term_no);
        $stillFailed[] = $item;
      }
    }

    $failedLoads = $stillFailed;
    $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    $logs[] = "🧾 Repair สรุป: ซ่อมสำเร็จ $repairPlacedLoads รายการ, ย้ายคาบ auto $repairMoves ครั้ง, คงเหลือ ".count($failedLoads)." รายการ";
    $logs[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
  }

  // สร้าง fails ตามรายการที่ยังเหลือหลัง repair
  $fails = [];
  foreach ($failedLoads as $item) {
    $L = $item['L'];
    $tids = $item['tids'] ?? [];
    $tname = implode(', ', array_map(fn($t) => ($teachers[$t]['first_name'] ?? '').' '.($teachers[$t]['last_name'] ?? ''), $tids));
    $fails[] = $L['class_name'].' · '.$L['label'].' · '.$tname.' — '.($item['why'] ?? 'ไม่พบช่องว่าง');
  }

  $pdo->commit();
  
  $successRate = $attempt > 0 ? round((($attempt - count($fails)) / $attempt) * 100, 1) : 100;
  
  // ✅ คาบ $logs ไว้แค่  200 บรรทัดท้ายเพื่อไม่ให้ JSON response ใหญ่เกินไป
  if (count($logs) > 200) {
    $logs = array_merge(
      array_slice($logs, 0, 100),
      ['... (ตัดลง '.( count($logs) - 200).' บรรทัด) ...'],
      array_slice($logs, -100)
    );
  }
  
  respond([
    'ok'=>true,
    'placed'=>$placed,
    'attempt'=>$attempt,
    'fails'=>$fails,
    'logs'=>$logs,
    'passes'=>$passCount,
    'success_rate'=>$successRate
  ]);

}catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  respond(['error'=>$e->getMessage()]);
}

respond(['error'=>'ไม่รู้จัก action: '.$action]);