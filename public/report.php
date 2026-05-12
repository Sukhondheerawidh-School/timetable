<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();

$vendor = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendor)) require_once $vendor;

use Dompdf\Dompdf;
use Dompdf\Options;

/* --------------------------
   Helpers
---------------------------*/
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function thaidate($ymd){
  if(!$ymd) return '';
  $ts = strtotime($ymd);
  $m = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  return (int)date('j',$ts).' '.$m[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
}

function thaiDateTextFromDMY($dmy){
  $dmy = trim((string)$dmy);
  if ($dmy === '') return '';

  // Accept dd/mm/yyyy or dd-mm-yyyy
  if (!preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})$/', $dmy, $m)) {
    return $dmy;
  }
  $day = (int)$m[1];
  $mon = (int)$m[2];
  $year = (int)$m[3];

  if ($year < 100) {
    $year += ($year >= 70 ? 1900 : 2000);
  }

  if ($day < 1 || $day > 31 || $mon < 1 || $mon > 12) return $dmy;

  $thaiMonths = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $beYear = ($year >= 2400) ? $year : ($year + 543);
  return $day.' '.$thaiMonths[$mon].' '.$beYear;
}

/* --------------------------
   รับค่าฟิลเตอร์ + ค่าเริ่มต้น
---------------------------*/
$view          = $_GET['view'] ?? 'class';
$year_id       = (int)($_GET['year_id'] ?? 0);

// term_no จะถูกกำหนดหลังจากรู้ปีที่ใช้งาน (เพื่ออิงช่วงเดือนได้ถูกต้อง)
$term_no = (isset($_GET['term_no']) && $_GET['term_no'] !== '') ? (int)$_GET['term_no'] : 0;

$class_id      = $_GET['class_id'] ?? 'all';
$room_id       = $_GET['room_id'] ?? 'all';
$teacher_group = $_GET['teacher_group'] ?? 'all';
$teacher_id    = $_GET['teacher_id'] ?? 'all';
$only_has_timetable = !empty($_GET['only_has_timetable']);
$only_has_timetable_class = !empty($_GET['only_has_timetable_class']);
$only_has_timetable_room  = !empty($_GET['only_has_timetable_room']);
$school_name   = $_GET['school_name'] ?? '';
if ($school_name === '') $school_name = 'โรงเรียนสุคนธีรวิทย์';
$printed_at    = $_GET['printed_at'] ?? date('Y-m-d');

// ✅ ผู้อำนวยการ (สำหรับช่องอนุมัติในหน้าพิมพ์)
$u = currentUser();
$uid = (int)($u['id'] ?? 0);
$isAdmin = (($u['role'] ?? '') === 'admin');

// Persist report prefs per-user in DB (cross-device)
function tt_report_prefs_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_report_prefs (
    user_id INT UNSIGNED NOT NULL,
    director_name VARCHAR(190) NOT NULL DEFAULT '',
    director_date VARCHAR(32) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_report_prefs_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  $done = true;
}

function tt_report_prefs_load(PDO $pdo, int $userId): array {
  if ($userId <= 0) return [];
  try {
    tt_report_prefs_init($pdo);
    $stmt = $pdo->prepare('SELECT director_name, director_date FROM user_report_prefs WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  } catch (Throwable $e) {
    // If table/constraint cannot be created due to permissions, just fall back to session/cookie.
    return [];
  }
}

function tt_report_prefs_save(PDO $pdo, int $userId, string $directorName, string $directorDate): void {
  if ($userId <= 0) return;
  try {
    tt_report_prefs_init($pdo);
    $stmt = $pdo->prepare(
      'INSERT INTO user_report_prefs (user_id, director_name, director_date) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE director_name=VALUES(director_name), director_date=VALUES(director_date)'
    );
    $stmt->execute([$userId, $directorName, $directorDate]);
  } catch (Throwable $e) {
    // ignore
  }
}

$dbReportPrefs = tt_report_prefs_load($pdo, $uid);

// Global (admin-defined) defaults for all users
// (Uses shared app_settings helpers in app/helpers.php)
function tt_report_global_prefs_load(PDO $pdo): array {
  $name = tt_app_setting_get($pdo, 'report_director_name');
  $date = tt_app_setting_get($pdo, 'report_director_date');
  $out = [];
  if ($name !== null) $out['director_name'] = $name;
  if ($date !== null) $out['director_date'] = $date;
  return $out;
}

$globalReportPrefs = tt_report_global_prefs_load($pdo);

$cookiePath = (defined('BASE_URL') && (string)BASE_URL !== '') ? (string)BASE_URL : '/';

$cookieReportPrefs = [];
$cookieName = $uid > 0 ? ('timetable_report_prefs_u'.$uid) : '';
if ($cookieName !== '' && isset($_COOKIE[$cookieName])) {
  $raw = urldecode((string)$_COOKIE[$cookieName]);
  $tmp = json_decode($raw, true);
  if (is_array($tmp)) $cookieReportPrefs = $tmp;
}

$sessionReportPrefs = $_SESSION['report_prefs'] ?? [];

$director_name = array_key_exists('director_name', $_GET)
  ? (string)$_GET['director_name']
  : (array_key_exists('director_name', $sessionReportPrefs)
      ? (string)$sessionReportPrefs['director_name']
      : (array_key_exists('director_name', $dbReportPrefs)
          ? (string)$dbReportPrefs['director_name']
      : (array_key_exists('director_name', $globalReportPrefs)
        ? (string)$globalReportPrefs['director_name']
        : (string)($cookieReportPrefs['director_name'] ?? ''))));

$director_date = array_key_exists('director_date', $_GET)
  ? (string)$_GET['director_date']
  : (array_key_exists('director_date', $sessionReportPrefs)
      ? (string)$sessionReportPrefs['director_date']
      : (array_key_exists('director_date', $dbReportPrefs)
          ? (string)$dbReportPrefs['director_date']
      : (array_key_exists('director_date', $globalReportPrefs)
        ? (string)$globalReportPrefs['director_date']
        : (string)($cookieReportPrefs['director_date'] ?? ''))));

$applied = isset($_GET['__apply']);

// ✅ บันทึกข้อมูลหัวกระดาษอย่างเดียว (ไม่ render report) แล้ว redirect กลับ
if (isset($_GET['__save_header'])) {
  $school_name_save = trim((string)($_GET['school_name'] ?? ''));
  $year_text_save   = trim((string)($_GET['year_text'] ?? ''));
  $term_text_save   = trim((string)($_GET['term_text'] ?? ''));
  $dir_name_save    = trim((string)($_GET['director_name'] ?? ''));
  $dir_date_save    = trim((string)($_GET['director_date'] ?? ''));
  $printed_at_save  = trim((string)($_GET['printed_at'] ?? ''));

  $_SESSION['report_prefs'] = array_merge($sessionReportPrefs, [
    'director_name' => $dir_name_save,
    'director_date' => $dir_date_save,
  ]);
  tt_report_prefs_save($pdo, $uid, $dir_name_save, $dir_date_save);
  if ($isAdmin) {
    tt_app_setting_set($pdo, 'report_director_name', $dir_name_save);
    tt_app_setting_set($pdo, 'report_director_date', $dir_date_save);
  }
  if ($cookieName !== '') {
    $cookieValue = json_encode(['director_name' => $dir_name_save, 'director_date' => $dir_date_save], JSON_UNESCAPED_UNICODE);
    if ($cookieValue !== false) setcookie($cookieName, rawurlencode($cookieValue), time() + 31536000, $cookiePath);
  }

  // Rebuild URL โดยเอา __save_header ออก แล้ว redirect
  $params = $_GET;
  unset($params['__save_header'], $params['__apply']);
  $params['school_name']    = $school_name_save;
  $params['year_text']      = $year_text_save;
  $params['term_text']      = $term_text_save;
  $params['director_name']  = $dir_name_save;
  $params['director_date']  = $dir_date_save;
  $params['printed_at']     = $printed_at_save;
  $params['__saved']        = '1'; // แสดง toast สำเร็จ
  header('Location: ' . url('report.php') . '?' . http_build_query($params));
  exit;
}

// ✅ จำค่าที่ใช้บ่อย (ต่อผู้ใช้) เมื่อกดแสดงตัวอย่าง/พิมพ์
if ($applied) {
  $_SESSION['report_prefs'] = array_merge($sessionReportPrefs, [
    'director_name' => $director_name,
    'director_date' => $director_date,
  ]);

  // Save to DB for cross-device persistence
  tt_report_prefs_save($pdo, $uid, $director_name, $director_date);

  // If admin applies, also save as global defaults for all users
  if ($isAdmin) {
    tt_app_setting_set($pdo, 'report_director_name', $director_name);
    tt_app_setting_set($pdo, 'report_director_date', $director_date);
  }

  if ($cookieName !== '') {
    $cookieValue = json_encode([
      'director_name' => $director_name,
      'director_date' => $director_date,
    ], JSON_UNESCAPED_UNICODE);
    if ($cookieValue !== false) {
      setcookie($cookieName, rawurlencode($cookieValue), time() + 31536000, $cookiePath);
    }
  }
}

$show_subject  = $applied ? isset($_GET['show_subject']) : true;
$show_code     = $applied ? isset($_GET['show_code'])    : false;
$show_room     = $applied ? isset($_GET['show_room'])     : true;
$show_teacher  = $applied ? isset($_GET['show_teacher'])  : true;
$show_room_code = $applied ? isset($_GET['show_room_code']) : false;
$show_approval = $applied ? isset($_GET['show_approval']) : true;

/* ✅ เพิ่มตัวแปร export */
$export = $_GET['export'] ?? '';

/* --------------------------
   อัปโหลดโลโก้
---------------------------*/
$logoPath = __DIR__ . '/report_logo.png';
$logoUrl  = 'report_logo.png';
@mkdir(__DIR__, 0777, true);
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_logo']) && isset($_FILES['logo'])) {
  $f = $_FILES['logo'];
  if (is_uploaded_file($f['tmp_name'])) {
    $info = @getimagesize($f['tmp_name']);
    if ($info !== false) {
      $raw = file_get_contents($f['tmp_name']);
      $im  = @imagecreatefromstring($raw);
      if ($im !== false) {
        @unlink($logoPath);
        imagepng($im, $logoPath);
        imagedestroy($im);
      }
    }
  }
  header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($_GET)); exit;
}
$hasLogo  = file_exists($logoPath);
$logoVers = $hasLogo ? $logoUrl.'?v='.filemtime($logoPath) : '';

// ✅ ลายเซ็นผู้อำนวยการ (อัปโหลดโดย Admin)
$directorSignPath = __DIR__ . '/director_sign.png';
$directorSignUrl  = 'director_sign.png';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_director_sign']) && isset($_FILES['director_sign'])) {
  $f = $_FILES['director_sign'];
  if (is_uploaded_file($f['tmp_name'])) {
    $info = @getimagesize($f['tmp_name']);
    if ($info !== false) {
      $raw = file_get_contents($f['tmp_name']);
      $im  = @imagecreatefromstring($raw);
      if ($im !== false) {
        @unlink($directorSignPath);
        imagepng($im, $directorSignPath);
        imagedestroy($im);
      }
    }
  }
  header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($_GET)); exit;
}
$hasDirectorSign = file_exists($directorSignPath);
$directorSignVers = $hasDirectorSign ? $directorSignUrl.'?v='.filemtime($directorSignPath) : '';

/* --------------------------
   โหลดข้อมูลอ้างอิง
---------------------------*/
$years = $pdo->query("SELECT id, year_label, is_active FROM academic_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
if(!$year_id){
  foreach($years as $y){ if($y['is_active']){$year_id=(int)$y['id']; break;} }
  if(!$year_id && $years){ $year_id=(int)$years[0]['id']; }
}

tt_terms_init($pdo);

// ✅ กำหนดค่าเริ่มต้นเทอมตามเดือนปัจจุบันถ้าไม่ส่งมา (อิงเทอมที่กำหนดในปีนั้น)
if ($term_no > 0) {
  $term_no = tt_validate_term_no($pdo, (int)$year_id, $term_no);
} else {
  $term_no = tt_default_term_no_for_year($pdo, (int)$year_id);
  $term_no = tt_validate_term_no($pdo, (int)$year_id, $term_no);
}

$termOptions = tt_terms_list($pdo, (int)$year_id);
$periods = $pdo->query("SELECT period_no,start_time,end_time FROM period_slots ORDER BY period_no")->fetchAll(PDO::FETCH_ASSOC);

$base_days = [
  1 => 'จันทร์',
  2 => 'อังคาร',
  3 => 'พุธ',
  4 => 'พฤหัสบดี',
  5 => 'ศุกร์',
];

$subjects = $pdo->query("SELECT subject_code, subject_name FROM subjects")->fetchAll(PDO::FETCH_ASSOC);
$subjectCodeByName = [];
foreach ($subjects as $s) {
  $name = trim($s['subject_name'] ?? '');
  $code = trim($s['subject_code'] ?? '');
  if ($name !== '' && $code !== '') {
    $subjectCodeByName[$name] = $code;
  }
}

$rooms = $pdo->query("SELECT id, room_name FROM rooms ORDER BY room_name")->fetchAll(PDO::FETCH_ASSOC);
$roomById = [];
foreach($rooms as $r) {
  $roomById[$r['id']] = $r;
}
$roomCodeById = [];
$roomNameById = [];
try {
  $stmt = $pdo->query("SELECT id, room_code, room_name FROM rooms");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $roomCodeById[(int)$row['id']] = $row['room_code'] ?? '';
    $roomNameById[(int)$row['id']] = $row['room_name'] ?? '';
  }
} catch(Exception $e) {
  error_log("Error loading room codes: " . $e->getMessage());
}

$yearLabel = '';
foreach($years as $y){ if((int)$y['id'] === (int)$year_id){ $yearLabel = (string)$y['year_label']; break; } }
$year_text = isset($_GET['year_text']) && $_GET['year_text'] !== '' ? (string)$_GET['year_text'] : ('ปีการศึกษา '.$yearLabel);
$defaultTermText = tt_term_label_from_no($pdo, (int)$year_id, (int)$term_no);
$term_text = isset($_GET['term_text']) && $_GET['term_text'] !== '' ? (string)$_GET['term_text'] : $defaultTermText;

$classes = $pdo->query("SELECT id,class_name,homeroom_room_id,grade_label,section_no FROM classes ORDER BY grade_label,section_no")->fetchAll(PDO::FETCH_ASSOC);
$classById=[]; foreach($classes as $c){ $classById[$c['id']]=$c; }

$teachers = $pdo->query("SELECT id,first_name,last_name,subject_group,teacher_code 
                         FROM teachers 
                         ORDER BY subject_group, teacher_code, first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Map: ครูที่มีคาบในตารางสอน (ตามปี/เทอม)
$teacherHasTimetable = [];
// ✅ Map: ห้องเรียน (class) ที่มีคาบในตารางสอน (ตามปี/เทอม)
$classHasTimetable = [];
// ✅ Map: ห้องเรียนจริง (room) ที่มีคาบในตารางสอน (ตามปี/เทอม)
$roomHasTimetable = [];
if ($year_id > 0 && in_array((int)$term_no, [1, 2], true)) {
  $st = $pdo->prepare("
    SELECT DISTINCT COALESCE(tst.teacher_id, ts.teacher_id) AS teacher_id
    FROM timetable_slots ts
    LEFT JOIN timetable_slot_teachers tst ON tst.slot_id = ts.id
    WHERE ts.academic_year_id = ?
      AND ts.term_no = ?
      AND COALESCE(tst.teacher_id, ts.teacher_id) IS NOT NULL
  ");
  $st->execute([$year_id, (int)$term_no]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    $teacherHasTimetable[(int)$tid] = true;
  }

  $st = $pdo->prepare("SELECT DISTINCT class_id FROM timetable_slots WHERE academic_year_id=? AND term_no=? AND class_id IS NOT NULL");
  $st->execute([$year_id, (int)$term_no]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $classHasTimetable[(int)$cid] = true;
  }

  $st = $pdo->prepare("SELECT DISTINCT room_id FROM timetable_slots WHERE academic_year_id=? AND term_no=? AND room_id IS NOT NULL");
  $st->execute([$year_id, (int)$term_no]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
    $roomHasTimetable[(int)$rid] = true;
  }
}

// ดึงกลุ่มสาระจากฐานข้อมูล
$groupMap = teacher_group_options(false); // รวมทั้งที่ปิดและเปิด

/* --------------------------
   Query ช่วยดึงข้อมูล
---------------------------*/
function getHomeroomTeachers(PDO $pdo,$class_id){
  $st=$pdo->prepare("SELECT CONCAT(t.first_name,' ',t.last_name) n
                     FROM class_teachers ct JOIN teachers t ON t.id=ct.teacher_id
                     WHERE ct.class_id=?");
  $st->execute([$class_id]); $a=array_column($st->fetchAll(PDO::FETCH_ASSOC),'n');
  return $a?implode(', ',$a):'-';
}

function getClassWeekdays(PDO $pdo, $class_id) {
  $st = $pdo->prepare("SELECT has_saturday, has_sunday FROM classes WHERE id = ?");
  $st->execute([$class_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  
  $days = [1, 2, 3, 4, 5]; // จันทร์-ศุกร์
  if ($row && (int)$row['has_saturday'] === 1) {
    $days[] = 6; // เสาร์
  }
  if ($row && (int)$row['has_sunday'] === 1) {
    $days[] = 7; // อาทิตย์
  }
  return $days;
}

function getTeacherWeekdays(PDO $pdo, $year_id, $term_no, $teacher_id) {
  // ✅ เริ่มต้นด้วยจันทร์-ศุกร์เสมอ
  $days = [1, 2, 3, 4, 5];
  
  // ✅ ตรวจสอบว่าครูสอนวันเสาร์ (6) หรือวันอาทิตย์ (7) หรือไม่
  $st = $pdo->prepare("
    SELECT DISTINCT ts.day_of_week
    FROM timetable_slots ts
    JOIN timetable_slot_teachers tst ON tst.slot_id = ts.id
    WHERE ts.academic_year_id = ? 
      AND ts.term_no = ? 
      AND tst.teacher_id = ?
      AND ts.day_of_week IN (6, 7)
    ORDER BY ts.day_of_week
  ");
  $st->execute([$year_id, $term_no, $teacher_id]);
  $extraDays = $st->fetchAll(PDO::FETCH_COLUMN);
  
  // ✅ ถ้ามีสอนเสาร์ หรือ อาทิตย์ ให้เพิ่มทั้ง 2 วันเลย
  $hasWeekend = false;
  foreach ($extraDays as $d) {
    $dayNum = (int)$d;
    if ($dayNum === 6 || $dayNum === 7) {
      $hasWeekend = true;
      break;
    }
  }
  
  if ($hasWeekend) {
    $days[] = 6; // เสาร์
    $days[] = 7; // อาทิตย์
  }
  
  return $days;
}

function gridByClass(PDO $pdo,$year_id,$term_no,$class_id){
  // ดึงวันที่ชั้นนี้เรียน
  $weekdays = getClassWeekdays($pdo, $class_id);
  $max_day = max($weekdays);
  
  // ✅ เพิ่ม ts.room_id ให้ชัดเจน
  $sql="SELECT ts.id, ts.day_of_week, ts.period_no, ts.subject_name,
               COALESCE(ts.room_id, c.homeroom_room_id) AS room_id,
               COALESCE(r.room_name, hr.room_name, c.class_name) AS display_room,
               GROUP_CONCAT(t.first_name SEPARATOR ', ') AS teachers,
               'normal' AS slot_type
        FROM timetable_slots ts
        JOIN classes c ON c.id=ts.class_id
        LEFT JOIN rooms r  ON r.id=ts.room_id
        LEFT JOIN rooms hr ON hr.id=c.homeroom_room_id
        LEFT JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
        LEFT JOIN teachers t ON t.id=tst.teacher_id
        WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.class_id=? AND ts.day_of_week BETWEEN 1 AND ?
        GROUP BY ts.day_of_week, ts.period_no, ts.id, room_id, display_room
        ORDER BY ts.day_of_week, ts.period_no";
  $st=$pdo->prepare($sql);
  $st->execute([$year_id,$term_no,$class_id,$max_day]);
  $g=[];
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    $g[(int)$r['day_of_week']][(int)$r['period_no']] = $r;
  }
  
  // ดึงวิชากิจกรรมปกติ (ไม่ใช่ทั้งวัน)
  $sqlAct = "SELECT ag.day_of_week, ag.period_no, ag.activity_name,
                    ag.room_id,
                    'activity' AS slot_type
             FROM activity_groups ag
             JOIN activity_classes ac ON ac.activity_id = ag.id
             WHERE ag.academic_year_id=? AND ag.term_no=? AND ac.class_id=? AND ag.day_of_week BETWEEN 1 AND ? AND ag.is_all_day=0";
  $stAct = $pdo->prepare($sqlAct);
  $stAct->execute([$year_id, $term_no, $class_id, $max_day]);
  while($r = $stAct->fetch(PDO::FETCH_ASSOC)){
    $day = (int)$r['day_of_week'];
    $pno = (int)$r['period_no'];
    if (!isset($g[$day][$pno])) {
      $g[$day][$pno] = [
        'id' => null,
        'day_of_week' => $day,
        'period_no' => $pno,
        'subject_name' => $r['activity_name'],
        'room_id' => $r['room_id'] ?? null,
        'display_room' => null,
        'teachers' => null,
        'slot_type' => 'activity'
      ];
    }
  }
  
  // ดึงวิชากิจกรรมทั้งวัน - กระจายข้ามหลายคาบ
    $sqlActAllDay = "SELECT ag.day_of_week, ag.activity_name, ag.room_id, ps.period_no,
                          'activity' AS slot_type
                   FROM activity_groups ag
                   JOIN activity_classes ac ON ac.activity_id = ag.id
         JOIN period_slots ps
                   WHERE ag.academic_year_id=? AND ag.term_no=? AND ac.class_id=? 
                         AND ag.day_of_week BETWEEN 1 AND ? AND ag.is_all_day=1
                   ORDER BY ag.day_of_week, ps.period_no";
  $stActAllDay = $pdo->prepare($sqlActAllDay);
    $stActAllDay->execute([$year_id, $term_no, $class_id, $max_day]);
  while($r = $stActAllDay->fetch(PDO::FETCH_ASSOC)){
    $day = (int)$r['day_of_week'];
    $pno = (int)$r['period_no'];
    if (!isset($g[$day][$pno])) {
      $g[$day][$pno] = [
        'id' => null,
        'day_of_week' => $day,
        'period_no' => $pno,
        'subject_name' => $r['activity_name'],
        'room_id' => $r['room_id'] ?? null,
        'display_room' => null,
        'teachers' => null,
        'slot_type' => 'activity'
      ];
    }
  }
  
  return $g;
}

function gridByTeacher(PDO $pdo,$year_id,$term_no,$teacher_id){
  // ดึงวันที่ครูนี้สอน
  $weekdays = getTeacherWeekdays($pdo, $year_id, $term_no, $teacher_id);
  $max_day = max($weekdays);
  
  // ✅ เพิ่ม ts.room_id ให้ชัดเจน
  $sql="SELECT ts.id, ts.day_of_week, ts.period_no, ts.subject_name, c.class_name,
               COALESCE(ts.room_id, c.homeroom_room_id) AS room_id,
               COALESCE(r.room_name, hr.room_name, c.class_name) AS display_room,
               'normal' AS slot_type
        FROM timetable_slots ts
        JOIN classes c ON c.id=ts.class_id
        LEFT JOIN rooms r  ON r.id=ts.room_id
        LEFT JOIN rooms hr ON hr.id=c.homeroom_room_id
        JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
        WHERE ts.academic_year_id=? AND ts.term_no=? AND tst.teacher_id=? AND ts.day_of_week BETWEEN 1 AND ?
        ORDER BY ts.day_of_week, ts.period_no";
  $st=$pdo->prepare($sql);
  $st->execute([$year_id,$term_no,$teacher_id,$max_day]);
  $g=[];
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    $g[(int)$r['day_of_week']][(int)$r['period_no']] = $r;
  }
  
  // ดึงวิชากิจกรรมปกติ (ไม่ใช่ทั้งวัน)
  $sqlAct = "SELECT ag.day_of_week, ag.period_no, ag.activity_name,
                    ag.room_id,
                    'activity' AS slot_type
             FROM activity_groups ag
             JOIN activity_teachers at ON at.activity_id = ag.id
             WHERE ag.academic_year_id=? AND ag.term_no=? AND at.teacher_id=? AND ag.day_of_week BETWEEN 1 AND ? AND ag.is_all_day=0";
  $stAct = $pdo->prepare($sqlAct);
  $stAct->execute([$year_id, $term_no, $teacher_id, $max_day]);
  while($r = $stAct->fetch(PDO::FETCH_ASSOC)){
    $day = (int)$r['day_of_week'];
    $pno = (int)$r['period_no'];
    if (!isset($g[$day][$pno])) {
      $g[$day][$pno] = [
        'id' => null,
        'day_of_week' => $day,
        'period_no' => $pno,
        'subject_name' => $r['activity_name'],
        'class_name' => null,
        'room_id' => $r['room_id'] ?? null,
        'display_room' => null,
        'slot_type' => 'activity'
      ];
    }
  }
  
  // ดึงวิชากิจกรรมทั้งวัน - กระจายข้ามหลายคาบ
    $sqlActAllDay = "SELECT ag.day_of_week, ag.activity_name, ag.room_id, ps.period_no,
                          'activity' AS slot_type
                   FROM activity_groups ag
                   JOIN activity_teachers at ON at.activity_id = ag.id
         JOIN period_slots ps
                   WHERE ag.academic_year_id=? AND ag.term_no=? AND at.teacher_id=?
                         AND ag.day_of_week BETWEEN 1 AND ? AND ag.is_all_day=1
                   ORDER BY ag.day_of_week, ps.period_no";
  $stActAllDay = $pdo->prepare($sqlActAllDay);
    $stActAllDay->execute([$year_id, $term_no, $teacher_id, $max_day]);
  while($r = $stActAllDay->fetch(PDO::FETCH_ASSOC)){
    $day = (int)$r['day_of_week'];
    $pno = (int)$r['period_no'];
    if (!isset($g[$day][$pno])) {
      $g[$day][$pno] = [
        'id' => null,
        'day_of_week' => $day,
        'period_no' => $pno,
        'subject_name' => $r['activity_name'],
        'class_name' => null,
        'room_id' => $r['room_id'] ?? null,
        'display_room' => null,
        'slot_type' => 'activity'
      ];
    }
  }
  
  return $g;
}

function gridByRoom(PDO $pdo,$year_id,$term_no,$room_id){
  // ✅ เพิ่ม ts.room_id ให้ชัดเจน
  $sql = "SELECT ts.id, ts.day_of_week, ts.period_no, ts.subject_name, c.class_name,
                 COALESCE(ts.room_id, c.homeroom_room_id) AS room_id,
                 COALESCE(r.room_name, hr.room_name, c.class_name) AS display_room,
                 GROUP_CONCAT(t.first_name SEPARATOR ', ') AS teachers,
                 'normal' AS slot_type
          FROM timetable_slots ts
          JOIN classes c ON c.id=ts.class_id
          LEFT JOIN rooms r  ON r.id=ts.room_id
          LEFT JOIN rooms hr ON hr.id=c.homeroom_room_id
          LEFT JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
          LEFT JOIN teachers t ON t.id=tst.teacher_id
          WHERE ts.academic_year_id=? AND ts.term_no=? AND (ts.room_id=? OR c.homeroom_room_id=?) AND ts.day_of_week BETWEEN 1 AND 5
          GROUP BY ts.day_of_week, ts.period_no, ts.id, room_id
          ORDER BY ts.day_of_week, ts.period_no";
  $st = $pdo->prepare($sql);
  $st->execute([$year_id, $term_no, $room_id, $room_id]);
  $g = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $g[(int)$r['day_of_week']][(int)$r['period_no']] = $r;
  }
  
  // ดึงวิชากิจกรรมปกติ (ไม่ใช่ทั้งวัน)
  $sqlAct = "SELECT ag.day_of_week, ag.period_no, ag.activity_name,
                    ag.room_id,
                    'activity' AS slot_type
             FROM activity_groups ag
             WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.room_id=? AND ag.day_of_week BETWEEN 1 AND 5 AND ag.is_all_day=0";
  $stAct = $pdo->prepare($sqlAct);
  $stAct->execute([$year_id, $term_no, $room_id]);
  while($r = $stAct->fetch(PDO::FETCH_ASSOC)){
    $day = (int)$r['day_of_week'];
    $pno = (int)$r['period_no'];
    if (!isset($g[$day][$pno])) {
      $g[$day][$pno] = [
        'id' => null,
        'day_of_week' => $day,
        'period_no' => $pno,
        'subject_name' => $r['activity_name'],
        'class_name' => null,
        'room_id' => $r['room_id'] ?? null,
        'display_room' => null,
        'teachers' => null,
        'slot_type' => 'activity'
      ];
    }
  }

  // ดึงวิชากิจกรรมทั้งวัน - กระจายข้ามหลายคาบ
  $sqlActAllDay = "SELECT ag.day_of_week, ag.activity_name, ag.room_id, ps.period_no,
                          'activity' AS slot_type
                   FROM activity_groups ag
                   JOIN period_slots ps
                   WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.room_id=? 
                         AND ag.day_of_week BETWEEN 1 AND 5 AND ag.is_all_day=1
                   ORDER BY ag.day_of_week, ps.period_no";
  $stActAllDay = $pdo->prepare($sqlActAllDay);
  $stActAllDay->execute([$year_id, $term_no, $room_id]);
  while($r = $stActAllDay->fetch(PDO::FETCH_ASSOC)){
    $day = (int)$r['day_of_week'];
    $pno = (int)$r['period_no'];
    if (!isset($g[$day][$pno])) {
      $g[$day][$pno] = [
        'id' => null,
        'day_of_week' => $day,
        'period_no' => $pno,
        'subject_name' => $r['activity_name'],
        'class_name' => null,
        'room_id' => $r['room_id'] ?? null,
        'display_room' => null,
        'teachers' => null,
        'slot_type' => 'activity'
      ];
    }
  }
  
  return $g;
}

/* --------------------------
   ตรวจหาคาบติดกัน
---------------------------*/
function detectConsecutiveSlots($grid, $periods, $weekdays) {
  $merged = [];
  
  // ✅ ใช้ weekdays ที่ส่งเข้ามา (จะเป็น [1,2,3,4,5] หรือ [1,2,3,4,5,6] หรือ [1,2,3,4,5,6,7])
  foreach ($weekdays as $day) {
    $skipUntil = 0;
    
    foreach ($periods as $p) {
      $pno = (int)$p['period_no'];
      
      if ($pno < $skipUntil) {
        $merged[$day][$pno] = ['skip' => true];
        continue;
      }
      
      $current = $grid[$day][$pno] ?? null;
      
      if (!$current) {
        $merged[$day][$pno] = null;
        continue;
      }
      
      $span = 1;
      $nextPno = $pno + 1;
      
      while (isset($grid[$day][$nextPno])) {
        $next = $grid[$day][$nextPno];
        
        $sameSubject = ($current['subject_name'] ?? '') === ($next['subject_name'] ?? '');
        $sameTeachers = ($current['teachers'] ?? '') === ($next['teachers'] ?? '');
        $sameRoom = ($current['display_room'] ?? '') === ($next['display_room'] ?? '');
        $sameClass = ($current['class_name'] ?? '') === ($next['class_name'] ?? '');
        $sameType = ($current['slot_type'] ?? 'normal') === ($next['slot_type'] ?? 'normal');
        
        if ($sameSubject && $sameTeachers && $sameRoom && $sameClass && $sameType) {
          $span++;
          $nextPno++;
        } else {
          break;
        }
      }
      
      $merged[$day][$pno] = $current;
      $merged[$day][$pno]['colspan'] = $span;
      
      if ($span > 1) {
        $skipUntil = $pno + $span;
      }
    }
  }
  
  return $merged;
}

/* --------------------------
   จัดการชื่อวิชา
---------------------------*/
function formatSubject($raw,$show_code,$subjectCodeByName){
  $raw = trim((string)$raw);
  if (preg_match('/^\s*([^\s\-]+)\s*-\s*(.+)$/u', $raw, $m)) {
    $code = trim($m[1]);
    $name = trim($m[2]);
    return $show_code ? ($code.' - '.$name) : $name;
  }
  if ($show_code && isset($subjectCodeByName[$raw]) && $subjectCodeByName[$raw] !== '') {
    return $subjectCodeByName[$raw].' - '.$raw;
  }
  return $raw;
}

/* --------------------------
   เรนเดอร์หนึ่งช่องในตาราง
---------------------------*/
function renderCell($item, $subjectCodeByName, $opt, $roomCodeById){
  if(!$item) return '<div class="slot empty">&nbsp;</div>';
  if (isset($item['skip']) && $item['skip']) return null;

  // ✅ ใช้ <div> ครอบเนื้อหาเพื่อให้ display: table-cell ทำงาน
  $html = '<div class="slot"><div>';

  if (isset($item['slot_type']) && $item['slot_type'] === 'activity') {
    if($opt['show_subject']){
      $html .= '<div class="line subj auto-fit">'.h($item['subject_name'] ?? '').'</div>';
    }
  } else {
    if($opt['show_subject']){
      $subjectText = formatSubject($item['subject_name'] ?? '', $opt['show_code'], $subjectCodeByName);
      if ($subjectText!=='') $html .= '<div class="line subj auto-fit">'.h($subjectText).'</div>';
    }

    // ✅ แสดงห้อง: ตรวจสอบ room_id ก่อน display_room
    if($opt['show_room']){
      $text = '';
      
      // ถ้าติ๊ก "ใช้รหัสห้อง" และมี room_id
      if (!empty($opt['show_room_code']) && isset($item['room_id'])) {
        $rid = (int)$item['room_id'];
        if ($rid > 0 && isset($roomCodeById[$rid]) && $roomCodeById[$rid] !== '') {
          $text = $roomCodeById[$rid];
        }
      }
      
      // ถ้ายังไม่มี text ให้ใช้ display_room
      if ($text === '' && !empty($item['display_room'])) {
        $text = $item['display_room'];
      }
      
      if ($text !== '') {
        $html .= '<div class="line room auto-fit">'.h($text).'</div>';
      }
    }

    if($opt['show_teacher'] && !empty($item['teachers'])){
      $arr = array_map('trim', explode(',', (string)$item['teachers']));
      $arr = array_map(fn($n) => 'ครู'.$n, $arr);
      $html .= '<div class="line teacher auto-fit">'.h(implode(', ',$arr)).'</div>';
    }

    if(!empty($item['class_name'])){
      $html .= '<div class="line class auto-fit">'.h($item['class_name']).'</div>';
    }
  }

  $html .= '</div></div>'; // ปิด inner div + outer div
  return $html;
}

/* --------------------------
   เตรียมเพจ
---------------------------*/
$pages=[];
if ($view==='class'){
  if ($class_id==='all'){
    foreach($classes as $c){
      if ($only_has_timetable_class && empty($classHasTimetable[(int)$c['id']])) continue;
      $weekdays = getClassWeekdays($pdo, $c['id']);
      $grid = gridByClass($pdo,$year_id,$term_no,$c['id']);
      $merged = detectConsecutiveSlots($grid, $periods, $weekdays);
      $pages[]=[
        'mode'=>'class',
        'title'=>'ตารางสอนห้อง '.$c['class_name'],
        'subtitle'=>'ครูประจำชั้น: '.getHomeroomTeachers($pdo,$c['id']),
        'grid'=>$merged,
        'weekdays'=>$weekdays
      ];
    }
  } else {
    $c = $classById[(int)$class_id]??null;
    $weekdays = getClassWeekdays($pdo, (int)$class_id);
    $grid = gridByClass($pdo,$year_id,$term_no,(int)$class_id);
    $merged = detectConsecutiveSlots($grid, $periods, $weekdays);
    $pages[]=[
      'mode'=>'class',
      'title'=>'ตารางสอนห้อง '.($c['class_name']??''),
      'subtitle'=>'ครูประจำชั้น: '.getHomeroomTeachers($pdo,(int)$class_id),
      'grid'=>$merged,
      'weekdays'=>$weekdays
    ];
  }

} elseif ($view==='teacher') {
  $list=[];
  foreach($teachers as $t){
    if ($only_has_timetable && empty($teacherHasTimetable[(int)$t['id']])) continue;
    if($teacher_group!=='all' && (string)$t['subject_group']!==(string)$teacher_group) continue;
    if($teacher_id!=='all' && (string)$teacher_id!==(string)$t['id']) continue;
    $list[]=$t;
  }
  if(!$list && !$only_has_timetable) $list=$teachers;

  foreach($list as $t){
    $gid = $t['subject_group'] ?? null;
    $gname = isset($groupMap[$gid]) ? $groupMap[$gid] : '-';
    $weekdays = getTeacherWeekdays($pdo, $year_id, $term_no, $t['id']);
    $grid = gridByTeacher($pdo,$year_id,$term_no,$t['id']);
    $merged = detectConsecutiveSlots($grid, $periods, $weekdays);
    $pages[]=[
      'mode'=>'teacher',
      'title'=>'ตารางสอนครู '.$t['first_name'].' '.$t['last_name'],
      'subtitle'=>'กลุ่มสาระ: '.$gname,
      'grid'=>$merged,
      'weekdays'=>$weekdays
    ];
  }

} elseif ($view==='room') {
  if ($room_id === 'all') {
    foreach($rooms as $r){
      if ($only_has_timetable_room && empty($roomHasTimetable[(int)$r['id']])) continue;
      $weekdays = [1, 2, 3, 4, 5]; // ห้องเรียนใช้แค่จันทร์-ศุกร์
      $grid = gridByRoom($pdo,$year_id,$term_no,$r['id']);
      $merged = detectConsecutiveSlots($grid, $periods, $weekdays);
      $pages[]=[
        'mode'=>'room',
        'title'=>'ตารางใช้ห้อง '.$r['room_name'],
        'subtitle'=>'',
        'grid'=>$merged,
        'weekdays'=>$weekdays
      ];
    }
  } else {
    $rid = (int)$room_id;
    $r = $roomById[$rid] ?? null;
    $weekdays = [1, 2, 3, 4, 5];
    $grid = gridByRoom($pdo,$year_id,$term_no,$rid);
    $merged = detectConsecutiveSlots($grid, $periods, $weekdays);
    $pages[]=[
      'mode'=>'room',
      'title'=>'ตารางใช้ห้อง '.($r['room_name']??''),
      'subtitle'=>'',
      'grid'=>$merged,
      'weekdays'=>$weekdays
    ];
  }
}

/* --------------------------
   CSS
---------------------------*/
$screenCss = '
@font-face{font-family:"Sarabun";src:url("fonts/Sarabun-Regular.ttf") format("truetype");font-weight:400}
@font-face{font-family:"Sarabun";src:url("fonts/Sarabun-Bold.ttf") format("truetype");font-weight:700}
*{box-sizing:border-box}
body{font-family:"Sarabun",system-ui,sans-serif}

/* Minimal Tailwind-like utilities used in report markup (print/export does not load Tailwind CDN) */
.bg-white{background:#fff}
.rounded-xl{border-radius:12px}
.shadow-sm{box-shadow:0 1px 2px rgba(0,0,0,.06)}
.p-4{padding:16px}
.border{border:1px solid #e5e7eb}
.border-gray-200{border-color:#e5e7eb}
.mb-6{margin-bottom:24px}

.header{display:flex;align-items:flex-start;gap:16px;margin-bottom:8px;justify-content:flex-start;padding-top:6px}
.header .logo{height:64px;width:auto;object-fit:contain;flex:0 0 auto}
.header .text{flex:1;text-align:center;transform:translateY(-6px)}
.header .text .school{font-size:20px;font-weight:700;line-height:1.15;margin:0 0 2px 0}
.header .text .title{font-size:16px;font-weight:600;line-height:1.15;margin:0 0 2px 0}
.header .text .subtitle{font-size:13px;color:#64748b;line-height:1.2;margin:2px 0 0 0}

.small{font-size:13px;color:#64748b}
.table{width:100%;border-collapse:collapse;table-layout:fixed;border:0}
.table-wrap{border:1px solid #334155;border-radius:12px;overflow:hidden}
.table-wrap + .table-wrap{margin-top:2px}
.table th,.table td{border:1px solid #cbd5e1;padding:4px 2px;vertical-align:middle}
.table th{background:#f8fafc;text-align:center;font-weight:700;color:#0f172a;font-size:12px}

/* Ensure the two-table layout lines up perfectly */
.table col.first-col{width:92px}
.table col.period-col{width:auto}

/* ✅ ปรับขนาดตามจำนวนคอลัมน์ (จำนวนคาบ/periods) */
.table.cols-5 th, .table.cols-5 td{font-size:14px;padding:6px 4px}
.table.cols-6 th, .table.cols-6 td{font-size:12px;padding:5px 3px}
.table.cols-7 th, .table.cols-7 td{font-size:11px;padding:4px 2px}
.table.cols-8 th, .table.cols-8 td{font-size:10px;padding:4px 2px}
.table.cols-9 th, .table.cols-9 td{font-size:9.5px;padding:3px 2px}
.table.cols-10 th, .table.cols-10 td{font-size:9px;padding:3px 1px}

/* ✅ ปรับขนาดตามจำนวนแถว (จำนวนวัน) */
.table.rows-5 .slot,.table.rows-5 .slot.empty{min-height:80px;height:80px}
.table.rows-6 .slot,.table.rows-6 .slot.empty{min-height:70px;height:70px}
.table.rows-7 .slot,.table.rows-7 .slot.empty{min-height:60px;height:60px}

/* ✅ ช่องทั้งหมดมีความสูงเท่ากัน และข้อความอยู่กลาง */
.slot{
  min-height:80px;
  height:80px;
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
  gap:2px;
}
.slot.empty{
  background:#fafafa;
  min-height:80px;
  height:80px;
}

.line{
  text-align:center;
  font-size:13px;
  line-height:1.22;
  width:100%;
}
.line.subj{font-weight:700}

.auto-fit{
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:100%;
  font-size:clamp(8px, 2.5vw, 13px);
}

@media (min-width:1024px){
  .auto-fit{
    font-size:13px;
  }
}

.footer{display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:#475569}
.table caption.meta{caption-side:top;text-align:left;padding:2px 0 6px 2px;font-size:14px;color:#475569}
.report-page{page-break-inside:avoid;break-inside:avoid;}
.page-break{page-break-after:always}

/* Print-only blocks (used to fill bottom whitespace nicely) */
.print-only{display:none}
.print-notes{margin-top:10px}
.sign-row{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}
.sign-box{flex:1;border:1px solid #111827;border-radius:8px;padding:8px 10px;min-height:90px}
.sign-box.approve-box{flex:0 0 38%;max-width:38%;margin-left:auto}
.sign-title{font-weight:700;font-size:12px;margin-bottom:8px;color:#111827}
.sign-line{border-bottom:1px solid #111827;height:42px;display:flex;align-items:center;justify-content:center}
.approve-sign{max-width:100%;max-height:38px;object-fit:contain;display:block;margin:0 auto}
.sign-meta{margin-top:6px;font-size:11px;color:#374151;display:flex;justify-content:space-between}

/* Period header formatting */
.period-head{display:flex;flex-direction:column;align-items:center;justify-content:center}
.period-no{font-weight:700;line-height:1.1}
.period-time{font-size:11px;color:#475569;line-height:1.1;margin-top:2px}

/* Two-table layout (header table + body table) */
.table-head{margin-bottom:0}
.table-body{margin-top:0}
@media print{
  @page{size:A4 landscape;margin:6mm}
  html,body{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .noprint{display:none!important}
  .shadow-sm{box-shadow:none!important}
  .report-page{page-break-after:always}
  .report-page:last-child{page-break-after:auto}
  body{background:#fff!important;color:#000!important}
  a{color:#000!important;text-decoration:none!important}
  /* Use more of the paper */
  .p-4{padding:10px!important}
  .mb-6{margin-bottom:10px!important}
  .border{border:0!important}
  .rounded-xl{border-radius:0!important}
  .header{margin-bottom:6px!important;padding-top:0!important}
  .header .logo{height:56px!important}
  .header .text{transform:none!important}
  .header .text .school{font-size:18px!important}
  .header .text .title{font-size:15px!important}
  /* B/W friendly: solid black grid */
  .table-wrap{border:1px solid #000!important;border-radius:12px!important;overflow:hidden!important}
  .table{border:0!important}
  .table th,.table td{border:1px solid #000!important;color:#000!important}
  .table-body tbody tr:first-child th,.table-body tbody tr:first-child td{border-top:0!important}
  .table th{background:#f0f0f0!important}
  .table tbody th{background:#e6e6e6!important}
  .slot.empty{background:#fff!important}
  /* Day labels a bit larger */
  .table tbody th{font-size:12px!important}
  /* Improve in-cell readability */
  .slot{gap:5px!important}
  .line{line-height:1.2!important;margin:1px 0!important}
  .auto-fit{font-size:11px}
  .print-only{display:block!important}
  .sign-box{border:1px solid #000!important;border-radius:8px!important}
  .sign-line{border-bottom:1px solid #000!important}
  .period-time{color:#000!important}
  /* tighten further in print so weekend tables fit in one page */
  .table.rows-7 .slot{gap:3px!important}
  .table.rows-7 .line{line-height:1.12!important;margin:0.5px 0!important}
  .table.rows-7 .slot,.table.rows-7 .slot.empty{min-height:48px;height:48px}
  .table.rows-6 .slot,.table.rows-6 .slot.empty{min-height:58px;height:58px}
}
';

/* --------------------------
   สร้าง HTML
---------------------------*/
function buildReportHTML(
  $pages,$periods,$base_days,$subjectCodeByName,$screenCss,
  $school_name,$printed_at,$logoVers,$hasLogo,
  $show_subject,$show_code,$show_room,$show_teacher,
  $for_pdf=false,
  $year_text='',$term_text='',$show_room_code=false,$roomCodeById=[],
  $director_name='',$director_date='',$directorSignVers='',$hasDirectorSign=false,$show_approval=true
){
  ob_start();
  if (!$for_pdf): ?>
  <style><?= $screenCss ?></style>
  <?php endif; ?>

  <?php
  $total = count($pages);
  $i = 0;
  foreach($pages as $pg){
    $i++;
    $is_last = ($i === $total);
    
    // ✅ ใช้ weekdays ของแต่ละ page
    $weekdays = $pg['weekdays'] ?? [1, 2, 3, 4, 5];
    $day_count = count($weekdays);
    $period_count = count($periods);
    // ✅ cols-* ต้องอิงจำนวนคาบ (จำนวนคอลัมน์ของตาราง) ไม่ใช่จำนวนวัน
    $col_class = 'cols-' . $period_count;
    $row_class = 'rows-' . $day_count;
    
    // สร้าง array ชื่อวัน
    $day_names = [
      1 => 'จันทร์', 2 => 'อังคาร', 3 => 'พุธ', 
      4 => 'พฤหัสบดี', 5 => 'ศุกร์', 6 => 'เสาร์', 7 => 'อาทิตย์'
    ];
  ?>
    <div class="report-page" <?= $for_pdf && !$is_last ? 'style="page-break-after:always;"' : '' ?>>
      <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-200 mb-6" style="height:100%;">
        <?php if(!$for_pdf): ?>
          <div class="header">
            <?php if($hasLogo): ?><img class="logo" src="<?= h($logoVers) ?>" alt="logo"><?php endif; ?>
            <div class="text">
              <div class="school"><?= h($school_name) ?></div>
              <div class="title"><?= h($pg['title']) ?></div>
              <?php if(!empty($pg['subtitle'])): ?><div class="subtitle small"><?= h($pg['subtitle']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <table class="pdf-header" role="presentation" cellspacing="0" cellpadding="0" width="100%">
            <tr>
              <td class="logo-cell" style="width:88px;vertical-align:middle;padding-right:8px">
                <?php if($hasLogo): ?><img src="<?= h($logoVers) ?>" alt="logo" style="max-height:80px;height:auto;width:auto;"><?php endif; ?>
              </td>
              <td class="text-cell" style="text-align:center;vertical-align:middle">
                <div class="school"><?= h($school_name) ?></div>
                <div class="title"><?= h($pg['title']) ?></div>
                <?php if(!empty($pg['subtitle'])): ?><div class="subtitle"><?= h($pg['subtitle']) ?></div><?php endif; ?>
              </td>
            </tr>
          </table>
        <?php endif; ?>

        <div class="table-wrap">
          <table class="table table-head <?= $col_class ?> <?= $row_class ?>">
            <colgroup>
              <col class="first-col">
              <?php foreach($periods as $_p): ?><col class="period-col"><?php endforeach; ?>
            </colgroup>
            <thead>
              <tr>
                <th style="width:92px">วันเวลา</th>
                <?php foreach($periods as $p): ?>
                  <th>
                    <div class="period-head">
                      <div class="period-no">คาบที่ <?= (int)$p['period_no'] ?></div>
                      <div class="period-time"><?= substr($p['start_time'],0,5) ?>–<?= substr($p['end_time'],0,5) ?></div>
                    </div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
          </table>
        </div>

        <div class="table-wrap">
          <table class="table table-body <?= $col_class ?> <?= $row_class ?>">
            <colgroup>
              <col class="first-col">
              <?php foreach($periods as $_p): ?><col class="period-col"><?php endforeach; ?>
            </colgroup>
            <tbody>
              <?php foreach($weekdays as $dno): ?>
                <tr>
                  <th style="background:#f1f5f9;text-align:center;color:#0f172a;font-weight:700"><?= h($day_names[$dno] ?? '') ?></th>
                  <?php foreach($periods as $p):
                    $pno = (int)$p['period_no'];
                    $item = $pg['grid'][$dno][$pno] ?? null;
                    
                    if ($item && isset($item['skip']) && $item['skip']) {
                      continue;
                    }
                    
                    $colspan = isset($item['colspan']) ? (int)$item['colspan'] : 1;
                    $cellHtml = renderCell($item, $subjectCodeByName, [
                      'show_subject'=>$show_subject,
                      'show_code'=>$show_code,
                      'show_room'=>$show_room,
                      'show_teacher'=>$show_teacher,
                      'show_room_code'=>$show_room_code,
                    ], $roomCodeById);
                    
                    if ($cellHtml !== null) {
                      echo '<td' . ($colspan > 1 ? ' colspan="'.$colspan.'"' : '') . '>' . $cellHtml . '</td>';
                    }
                  endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if(!empty($show_approval)): ?>
          <div class="print-only print-notes">
            <div class="sign-row">
              <div class="sign-box approve-box">
                <div class="sign-title">อนุมัติ / ผู้อำนวยการ</div>
                <div class="sign-line">
                  <?php if(!empty($hasDirectorSign)): ?>
                    <img class="approve-sign" src="<?= h($directorSignVers) ?>" alt="signature">
                  <?php endif; ?>
                </div>
                <div class="sign-meta">
                  <span><?= h($director_name !== '' ? $director_name : '(........................................)') ?></span>
                  <span>วันที่ <?= h($director_date !== '' ? $director_date : '....../....../......') ?></span>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if(!$for_pdf): ?>
          <div class="footer">
            <div class="left"><?= h($year_text) ?> · <?= h($term_text) ?></div>
            <div class="right">พิมพ์ ณ วันที่ <?= h(thaidate($printed_at)) ?></div>
          </div>
        <?php else: ?>
          <table class="pdf-footer" role="presentation" cellspacing="0" cellpadding="0" width="100%" style="margin-top:6px">
            <tr>
              <td style="font-size:12px;color:#475569;text-align:left"><?= h($year_text) ?> · <?= h($term_text) ?></td>
              <td style="font-size:12px;color:#475569;text-align:right">พิมพ์ ณ วันที่ <?= h(thaidate($printed_at)) ?></td>
            </tr>
          </table>
        <?php endif; ?>
      </div>
    </div>
  <?php } return ob_get_clean();
}

$reportHtml = buildReportHTML(
  $pages,$periods,$base_days,$subjectCodeByName,$screenCss,
  $school_name,$printed_at,$logoVers,$hasLogo,
  $show_subject,$show_code,$show_room,$show_teacher,false,
  $year_text,$term_text,$show_room_code,$roomCodeById,
  $director_name,$director_date,$directorSignVers,$hasDirectorSign,$show_approval
);

/* --------------------------
   Export / Print
---------------------------*/
if ($export==='print'){
  $title = 'พิมพ์รายงานตารางสอน';
  $favicon = url('favicon.ico?v='.(string)time());
  echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
  echo '<title>'.h($title).'</title>';
  echo '<link rel="icon" type="image/x-icon" href="'.h($favicon).'">';
  echo '<link rel="shortcut icon" type="image/x-icon" href="'.h($favicon).'">';
  echo '</head><body>';
  echo $reportHtml;
  echo '<script>window.print()</script>';
  echo '</body></html>';
  exit;
}

if ($export==='blank'){
  // สร้างตารางเปล่าตามคาบและวันที่กำหนดในระบบ
  $blankDays = $base_days; // จันทร์-ศุกร์ (หรือเพิ่ม เสาร์/อาทิตย์ถ้ามีห้องที่เรียน)
  if ($view === 'class' && $class_id !== 'all' && (int)$class_id > 0) {
    $blankDays = getClassWeekdays($pdo, (int)$class_id);
    $blankDays = array_combine($blankDays, array_map(fn($d) => [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'][$d] ?? '', $blankDays));
  }
  $favicon = url('favicon.ico?v='.(string)time());
  ob_start();
  echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">';
  echo '<title>ตารางเปล่า</title>';
  echo '<link rel="icon" type="image/x-icon" href="'.h($favicon).'">';
  echo '<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:"Sarabun",sans-serif;font-size:11px;background:#fff;color:#1e293b}
    @import url("https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap");
    .page{width:297mm;min-height:210mm;padding:8mm 8mm 6mm;background:#fff}
    .header{text-align:center;margin-bottom:5mm}
    .school-name{font-size:14px;font-weight:700}
    .sub-header{font-size:11px;color:#475569;margin-top:1mm}
    table{width:100%;border-collapse:collapse;table-layout:fixed}
    th,td{border:1px solid #94a3b8;padding:2px 3px;text-align:center;vertical-align:middle}
    thead th{background:#f1f5f9;font-weight:600;font-size:10px}
    thead th.day-header{width:18mm}
    tbody td.day-cell{background:#f8fafc;font-weight:600;font-size:10px;white-space:nowrap}
    tbody td.slot-cell{height:16mm;min-height:16mm}
    .time-label{font-size:8px;color:#64748b;display:block;margin-top:1px}
    .sign-row{display:flex;justify-content:flex-end;margin-top:5mm}
    .sign-box{border:1px solid #94a3b8;border-radius:4px;padding:3mm 6mm;min-width:60mm;text-align:center}
    .sign-title{font-size:10px;font-weight:600;margin-bottom:8mm}
    .sign-line{border-bottom:1px solid #94a3b8;margin-bottom:2mm;min-width:50mm;height:10mm}
    .sign-meta{font-size:9px;color:#475569}
    .footer{display:flex;justify-content:space-between;margin-top:3mm;font-size:9px;color:#64748b}
    @media print{body{margin:0}@page{size:A4 landscape;margin:0}}
  </style></head><body>';
  echo '<div class="page">';
  echo '<div class="header">';
  echo '<div class="school-name">'.h($school_name).'</div>';
  echo '<div class="sub-header">ตารางเรียน/สอน &nbsp;|&nbsp; '.h($year_text).' &nbsp;|&nbsp; '.h($term_text).'</div>';
  echo '</div>';
  echo '<table>';
  echo '<thead><tr>';
  echo '<th class="day-header">วัน \ คาบ</th>';
  foreach($periods as $p){
    $st = isset($p['start_time']) ? substr((string)$p['start_time'],0,5) : '';
    $et = isset($p['end_time'])   ? substr((string)$p['end_time'],0,5)   : '';
    echo '<th>คาบ '.(int)$p['period_no'].'<span class="time-label">'.h($st).'-'.h($et).'</span></th>';
  }
  echo '</tr></thead><tbody>';
  $dayNames = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];
  foreach($blankDays as $dno => $dname){
    echo '<tr>';
    echo '<td class="day-cell">'.h(is_string($dname) ? $dname : ($dayNames[(int)$dno] ?? '')).'</td>';
    foreach($periods as $p){
      echo '<td class="slot-cell"></td>';
    }
    echo '</tr>';
  }
  echo '</tbody></table>';
  if($show_approval){
    echo '<div class="sign-row"><div class="sign-box">';
    echo '<div class="sign-title">อนุมัติ / ผู้อำนวยการ</div>';
    echo '<div class="sign-line"></div>';
    echo '<div class="sign-meta"><span>'.h($director_name ?: '(........................................)').'</span><br>';
    echo '<span>วันที่ '.h($director_date ?: '....../....../......').'</span></div>';
    echo '</div></div>';
  }
  echo '<div class="footer">';
  echo '<span>'.h($year_text).' · '.h($term_text).'</span>';
  echo '<span>พิมพ์ ณ วันที่ '.h(thaidate($printed_at)).'</span>';
  echo '</div>';
  echo '</div>';
  echo '<script>window.print()</script>';
  echo '</body></html>';
  echo ob_get_clean();
  exit;
}

if ($export==='pdf'){
  // ✅ Debug: ตรวจสอบว่ามีข้อมูลหรือไม่
  error_log("=== PDF EXPORT DEBUG ===");
  error_log("View: " . $view);
  error_log("Year ID: " . $year_id);
  error_log("Term: " . $term_no);
  error_log("Class ID: " . var_export($class_id, true));
  error_log("Teacher ID: " . var_export($teacher_id, true));
  error_log("Room ID: " . var_export($room_id, true));
  error_log("Pages count: " . count($pages));
  error_log("========================");
  
  // ✅ ถ้าไม่มีข้อมูลเลย
  if (count($pages) === 0) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css">';
    echo '</head><body>';
    echo '<div class="max-w-2xl mx-auto p-10 mt-20">';
    echo '<div class="bg-yellow-50 border-2 border-yellow-300 rounded-2xl p-8 text-center">';
    echo '<div class="text-6xl mb-4">⚠️</div>';
    echo '<h3 class="text-yellow-900 font-bold text-2xl mb-4">ไม่มีข้อมูลตาราง</h3>';
    echo '<p class="text-yellow-800 mb-4">กรุณาทำตามขั้นตอนนี้:</p>';
    echo '<ol class="text-left text-yellow-800 space-y-2 mb-6 inline-block">';
    echo '<li><strong>1.</strong> เลือกฟิลเตอร์ (ปีการศึกษา, เทอม, ห้อง/ครู)</li>';
    echo '<li><strong>2.</strong> กดปุ่ม <strong class="bg-yellow-200 px-2 py-1 rounded">"แสดงตัวอย่าง"</strong></li>';
    echo '<li><strong>3.</strong> ตรวจสอบว่าเห็นตารางหรือไม่</li>';
    echo '<li><strong>4.</strong> จากนั้นกดปุ่ม <strong class="bg-yellow-200 px-2 py-1 rounded">"พิมพ์"</strong> (หรือถ้าจำเป็นค่อยใช้ PDF)</li>';
    echo '</ol>';
    echo '<div class="bg-yellow-100 rounded p-3 mb-4 text-sm text-yellow-900">';
    echo '<strong>ข้อมูลปัจจุบัน:</strong><br>';
    echo 'มุมมอง: '.h($view).' | ปี: '.$year_id.' | เทอม: '.$term_no.'<br>';
    if($view === 'class') echo 'ห้อง: '.h($class_id);
    if($view === 'teacher') echo 'ครู: '.h($teacher_id);
    if($view === 'room') echo 'ห้องเรียน: '.h($room_id);
    echo '</div>';
    echo '<a href="report.php" class="inline-block mt-4 px-6 py-3 bg-yellow-600 hover:bg-yellow-700 text-white font-bold rounded-xl transition">กลับไปหน้าหลัก</a>';
    echo '</div>';
    echo '</div>';
    echo '</body></html>';
    exit;
  }
  
  // ✅ ตรวจสอบ GD extension
  if (!extension_loaded('gd')) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css">';
    echo '</head><body>';
    echo '<div class="max-w-2xl mx-auto p-6 mt-10">';
    echo '<div class="bg-red-50 border border-red-200 rounded-xl p-5">';
    echo '<h3 class="text-red-800 font-bold text-lg mb-2">⚠️ ไม่สามารถสร้าง PDF ได้</h3>';
    echo '<p class="text-red-700 mb-3">ต้องเปิด PHP GD extension ก่อน:</p>';
    echo '<ol class="list-decimal list-inside text-red-700 space-y-1">';
    echo '<li>เปิดไฟล์ <code class="bg-red-100 px-2 py-1 rounded">php.ini</code></li>';
    echo '<li>ค้นหา <code class="bg-red-100 px-2 py-1 rounded">;extension=gd</code></li>';
    echo '<li>ลบ <code class="bg-red-100 px-2 py-1 rounded">;</code> ข้างหน้า</li>';
    echo '<li>Restart Apache</li>';
    echo '</ol>';
    echo '<p class="text-red-700 mt-3">หรือใช้ปุ่ม <strong>"พิมพ์"</strong> แทน</p>';
    echo '</div>';
    echo '</div>';
    echo '</body></html>';
    exit;
  }

  if (!class_exists(Dompdf::class)){
    echo '<div class="p-4 text-red-600">ไม่พบ dompdf</div>'; exit;
  }
  
  $options = new Options();
  $options->set('isRemoteEnabled', true);
  $options->setChroot(__DIR__);
  $options->set('defaultFont', 'Sarabun');

  $pdfCssBase = <<<'CSS'
@page{size:A4 landscape;margin:8mm}
@font-face{font-family:'Sarabun';src:url('fonts/Sarabun-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
@font-face{font-family:'Sarabun';src:url('fonts/Sarabun-Bold.ttf') format('truetype');font-weight:700;font-style:normal}
*{font-family:'Sarabun',sans-serif;box-sizing:border-box}
CSS;

  $pdfOverrides = <<<'CSS'
.small{font-size:11px;color:#64748b}
.pdf-header{width:100%;border-collapse:collapse;margin-bottom:4px}
.pdf-header .school{font-size:18px;font-weight:700;margin-bottom:2px}
.pdf-header .title{font-size:15px;font-weight:600;margin-bottom:1px}
.pdf-header .subtitle{font-size:12px;color:#64748b}

.table{width:100%;border-collapse:collapse;table-layout:fixed;border:1px solid #c1c7d0}
.table th,.table td{border:1px solid #c1c7d0;vertical-align:middle;text-align:center}

/* ✅ ใช้ display: table-cell แทน flex เพื่อให้ dompdf รองรับ */
.table tbody td{
  vertical-align:middle;
}

/* ✅ บังคับให้ .slot อยู่กลางทั้งแนวตั้งและแนวนอน */
.slot{
  display:table;
  width:100%;
  height:100%;
  text-align:center;
}

.slot > div{
  display:table-cell;
  vertical-align:middle;
  text-align:center;
}

/* ✅ ช่องขนาด 5 คอลัมน์ */
.table.cols-5 th,
.table.cols-5 td{
  padding:5px 3px;
  font-size:13px;
  height:65px;
}
.table.cols-5 .slot{
  min-height:63px;
  height:63px;
}
.table.cols-5 .line{
  font-size:13px;
  line-height:1.3;
  text-align:center;
  margin:2px 0;
  display:block;
}

/* ✅ ช่องขนาด 6 คอลัมน์ */
.table.cols-6 th,
.table.cols-6 td{
  padding:4px 2px;
  font-size:11px;
  height:60px;
}
.table.cols-6 .slot{
  min-height:58px;
  height:58px;
}
.table.cols-6 .line{
  font-size:11px;
  line-height:1.25;
  text-align:center;
  margin:2px 0;
  display:block;
}
.table.cols-6 th{
  font-size:10px;
}
.table.cols-6 .small{
  font-size:9px;
}

/* ✅ ช่องขนาด 7 คอลัมน์ */
.table.cols-7 th,
.table.cols-7 td{
  padding:3px 1px;
  font-size:10px;
  height:55px;
}
.table.cols-7 .slot{
  min-height:53px;
  height:53px;
}
.table.cols-7 .line{
  font-size:10px;
  line-height:1.2;
  text-align:center;
  margin:2px 0;
  display:block;
}
.table.cols-7 th{
  font-size:9px;
}
.table.cols-7 .small{
  font-size:8px;
}

/* ✅ ช่องขนาด 8–10 คอลัมน์ (คาบเยอะ) */
.table.cols-8 th,
.table.cols-8 td{
  padding:2px 1px;
  font-size:9px;
  height:52px;
}
.table.cols-8 .slot{
  min-height:50px;
  height:50px;
}
.table.cols-8 th{font-size:8px;}
.table.cols-8 .small{font-size:7px;}

.table.cols-9 th,
.table.cols-9 td{
  padding:2px 1px;
  font-size:8.5px;
  height:50px;
}
.table.cols-9 .slot{
  min-height:48px;
  height:48px;
}
.table.cols-9 th{font-size:7.5px;}
.table.cols-9 .small{font-size:6.8px;}

.table.cols-10 th,
.table.cols-10 td{
  padding:2px 1px;
  font-size:8px;
  height:48px;
}
.table.cols-10 .slot{
  min-height:46px;
  height:46px;
}
.table.cols-10 th{font-size:7.2px;}
.table.cols-10 .small{font-size:6.5px;}

.table th{
  background:#f8fafc;
  text-align:center;
  font-weight:700;
  color:#0f172a;
}

/* ✅ ทุกบรรทัดอยู่กลาง */
.line{
  text-align:center;
  display:block;
  margin:2px 0;
}
.line.subj{
  font-weight:700;
}

.auto-fit{
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:100%;
  display:block;
  text-align:center;
}

.pdf-footer{
  width:100%;
  border-collapse:collapse;
  margin-top:4px;
}
.pdf-footer td{
  font-size:11px;
  color:#475569;
  padding:2px 0;
}

.report-page{
  page-break-inside:avoid;
  break-inside:avoid;
}
CSS;

  $pdfCss = $pdfCssBase . "\n" . $screenCss . "\n" . $pdfOverrides;

  // ✅ สร้าง HTML สำหรับ PDF โดยส่ง $pages ที่มี weekdays ไปด้วย
  $reportHtmlPdf = buildReportHTML(
    $pages,
    $periods,
    $base_days,
    $subjectCodeByName,
    $screenCss,
    $school_name,
    $printed_at,
    $logoVers,
    $hasLogo,
    $show_subject,
    $show_code,
    $show_room,
    $show_teacher,
    true,
    $year_text,
    $term_text,
    $show_room_code,
    $roomCodeById,
    $director_name,
    $director_date,
    $directorSignVers,
    $hasDirectorSign,
    $show_approval
  );

  $html = "<style>{$pdfCss}</style>" . $reportHtmlPdf;

  $dompdf = new Dompdf($options);
  $dompdf->loadHtml($html,'UTF-8');
  $dompdf->setPaper('A4','landscape');
  $dompdf->render();
  $dompdf->stream('timetable.pdf',['Attachment'=>1]);
  exit;
}

/* --------------------------
   UI
---------------------------*/
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';

// ✅ ตรวจสอบ role ของผู้ใช้งาน
$isAdmin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
?>
<div class="max-w-6xl mx-auto px-4 py-6 space-y-6">
  <div class="bg-white border border-gray-200 rounded-2xl shadow-sm">
    <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between gap-3">
      <h2 class="text-lg font-semibold text-slate-900">📄 รายงานตารางสอน</h2>
      <?php if($applied): ?>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-1.5 rounded-full">✅ แสดงตัวอย่างแล้ว</span>
      <?php else: ?>
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold bg-slate-100 text-slate-500 border border-slate-200 px-3 py-1.5 rounded-full">⚙️ ตั้งค่าแล้วกด "แสดงตัวอย่าง"</span>
      <?php endif; ?>
    </div>

    <!-- Admin: โลโก้ + ลายเซ็น (collapsible) -->
    <?php if($isAdmin): ?>
    <details class="border-b border-gray-100">
      <summary class="px-5 py-3 cursor-pointer select-none flex items-center gap-2 hover:bg-slate-50 list-none">
        <span class="text-xs font-semibold bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">🔑 Admin</span>
        <span class="text-sm font-medium text-slate-700">จัดการโลโก้ & ลายเซ็นผู้อำนวยการ</span>
        <svg class="ml-auto h-4 w-4 text-slate-400 transition-transform details-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
      </summary>
      <div class="px-5 pb-4 pt-2 grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50/60">
        <div>
          <p class="text-xs font-semibold text-slate-500 mb-2">โลโก้โรงเรียน</p>
          <form method="post" enctype="multipart/form-data" class="flex items-center gap-3 flex-wrap">
            <?php if($hasLogo): ?>
              <img src="<?= h($logoVers) ?>" alt="logo" class="h-12 w-auto border border-gray-200 rounded-lg p-1 bg-white">
            <?php else: ?>
              <div class="h-12 w-20 border border-dashed border-gray-300 rounded-lg flex items-center justify-center text-xs text-gray-400 bg-white">ไม่มีโลโก้</div>
            <?php endif; ?>
            <input type="file" name="logo" accept="image/*" class="text-sm text-slate-600 flex-1 min-w-0">
            <button type="submit" name="upload_logo" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">อัปโหลด</button>
          </form>
          <p class="text-xs text-slate-400 mt-1.5">แปลงเป็น PNG อัตโนมัติ</p>
        </div>
        <div>
          <p class="text-xs font-semibold text-slate-500 mb-2">ลายเซ็นผู้อำนวยการ</p>
          <form method="post" enctype="multipart/form-data" class="flex items-center gap-3 flex-wrap">
            <?php if($hasDirectorSign): ?>
              <img src="<?= h($directorSignVers) ?>" alt="signature" class="h-12 w-auto border border-gray-200 rounded-lg p-1 bg-white">
            <?php else: ?>
              <div class="h-12 w-28 border border-dashed border-gray-300 rounded-lg flex items-center justify-center text-xs text-gray-400 bg-white">ไม่มีลายเซ็น</div>
            <?php endif; ?>
            <input type="file" name="director_sign" accept="image/*" class="text-sm text-slate-600 flex-1 min-w-0">
            <button type="submit" name="upload_director_sign" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">อัปโหลด</button>
          </form>
          <p class="text-xs text-slate-400 mt-1.5">แปลงเป็น PNG อัตโนมัติ</p>
        </div>
      </div>
    </details>
    <style>details[open] .details-chevron { transform: rotate(180deg); } details summary::-webkit-details-marker { display: none; }</style>
    <?php endif; ?>

    <form method="get" id="filterForm" class="p-5 space-y-5">
      <input type="hidden" name="__apply" value="1">

      <!-- Section 1: ข้อมูลหลัก -->
      <div>
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">🔍 เลือกรายงาน</p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">มุมมอง</label>
            <select name="view" id="f_view" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
              <option value="class"  <?= $view==='class'?'selected':''; ?>>📚 ตามห้อง</option>
              <option value="teacher"<?= $view==='teacher'?'selected':''; ?>>👨‍🏫 ตามครู</option>
              <option value="room"   <?= $view==='room'?'selected':''; ?>>🚪 ตามห้องเรียน</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">ปีการศึกษา</label>
            <select name="year_id" id="f_year" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
              <?php foreach($years as $y): ?>
              <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= h($y['year_label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">เทอม</label>
            <select name="term_no" id="f_term" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
              <?php foreach ($termOptions as $t): ?>
                <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$term_no === (int)$t['term_no']) ? 'selected' : ''; ?>><?= h($t['term_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if($view==='class'): ?>
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1">ห้อง</label>
              <select name="class_id" id="f_class" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
                <option value="all" <?= $class_id==='all'?'selected':''; ?>>— ทุกห้อง —</option>
                <?php foreach($classes as $c):
                  if ($only_has_timetable_class && empty($classHasTimetable[(int)$c['id']]) && (string)$class_id!==(string)$c['id']) continue; ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$class_id===(string)$c['id']?'selected':''; ?>><?= h($c['class_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="md:col-start-1 col-span-2 md:col-span-4">
              <label class="inline-flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" name="only_has_timetable_class" value="1" <?= $only_has_timetable_class?'checked':''; ?> class="rounded">
                แสดงเฉพาะห้องที่มีตารางสอน
              </label>
            </div>

          <?php elseif($view==='teacher'): ?>
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1">กลุ่มสาระ</label>
              <select name="teacher_group" id="f_group" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
                <option value="all" <?= $teacher_group==='all'?'selected':''; ?>>— ทั้งหมด —</option>
                <?php foreach($groupMap as $gid=>$gn): ?>
                <option value="<?= $gid ?>" <?= (string)$teacher_group===(string)$gid?'selected':''; ?>><?= h($gn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1">ครู</label>
              <select name="teacher_id" id="f_teacher" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
                <option value="all" <?= $teacher_id==='all'?'selected':''; ?>>— ทุกคน —</option>
                <?php foreach($teachers as $t):
                  if($only_has_timetable && empty($teacherHasTimetable[(int)$t['id']])) continue;
                  if($teacher_group!=='all' && (string)$t['subject_group']!==(string)$teacher_group) continue; ?>
                <option value="<?= (int)$t['id'] ?>" <?= (string)$teacher_id===(string)$t['id']?'selected':''; ?>><?= h($t['first_name'].' '.$t['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-span-2 md:col-span-4">
              <label class="inline-flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" name="only_has_timetable" value="1" <?= $only_has_timetable?'checked':''; ?> class="rounded">
                แสดงเฉพาะครูที่มีตารางสอน
              </label>
            </div>

          <?php elseif($view==='room'): ?>
            <div>
              <label class="block text-xs font-medium text-slate-600 mb-1">ห้องเรียน</label>
              <select name="room_id" id="f_room" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition">
                <option value="all" <?= ($_GET['room_id']??'all')==='all'?'selected':''; ?>>— ทุกห้อง —</option>
                <?php foreach($rooms as $r):
                  if ($only_has_timetable_room && empty($roomHasTimetable[(int)$r['id']]) && (string)($_GET['room_id']??'all')!==(string)$r['id']) continue; ?>
                <option value="<?= (int)$r['id'] ?>" <?= (string)($_GET['room_id']??'all')===(string)$r['id']?'selected':''; ?>><?= h($r['room_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-span-2 md:col-span-4">
              <label class="inline-flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" name="only_has_timetable_room" value="1" <?= $only_has_timetable_room?'checked':''; ?> class="rounded">
                แสดงเฉพาะห้องที่มีตารางใช้ห้อง
              </label>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <hr class="border-slate-100">

      <!-- Section 2: ตั้งค่าหัวกระดาษ -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">🏫 ข้อมูลหัวกระดาษ</p>
          <button type="submit" name="__save_header" value="1"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition shadow-sm"
            title="บันทึกข้อมูลหัวกระดาษโดยไม่ต้องแสดงตัวอย่าง">
            💾 บันทึก
          </button>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div class="col-span-2">
            <label class="block text-xs font-medium text-slate-600 mb-1">ชื่อโรงเรียน</label>
            <input type="text" name="school_name" id="f_school" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition" value="<?= h($school_name) ?>">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">ปีการศึกษา (มุมซ้าย)</label>
            <input type="text" name="year_text" id="f_year_text" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition" placeholder="ปีการศึกษา 2568" value="<?= h($year_text) ?>">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">ภาคเรียน (มุมซ้าย)</label>
            <input type="text" name="term_text" id="f_term_text" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition" placeholder="ภาคเรียนที่ 1" value="<?= h($term_text) ?>">
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-slate-600 mb-1">ชื่อผู้อำนวยการ (ช่องอนุมัติ)</label>
            <input type="text" name="director_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition" placeholder="ชื่อ-สกุล" value="<?= h($director_name) ?>">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">วันที่อนุมัติ</label>
            <input type="text" name="director_date" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition" placeholder="....../....../......" value="<?= h($director_date) ?>">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">พิมพ์ ณ วันที่</label>
            <input type="date" name="printed_at" id="f_printed" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition" value="<?= h($printed_at) ?>">
          </div>
        </div>
      </div>

      <hr class="border-slate-100">

      <!-- Section 3: แสดงข้อมูล -->
      <div>
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">👁 แสดงข้อมูลในช่อง</p>
        <div class="flex flex-wrap gap-x-6 gap-y-2">
          <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer select-none">
            <input type="checkbox" name="show_subject" value="1" <?= $show_subject?'checked':''; ?> class="rounded accent-indigo-600"> ชื่อวิชา
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer select-none">
            <input type="checkbox" name="show_code" value="1" <?= $show_code?'checked':''; ?> class="rounded accent-indigo-600"> รหัสวิชา
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer select-none">
            <input type="checkbox" name="show_room" value="1" <?= $show_room?'checked':''; ?> class="rounded accent-indigo-600"> ห้องเรียน
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer select-none">
            <input type="checkbox" name="show_teacher" value="1" <?= $show_teacher?'checked':''; ?> class="rounded accent-indigo-600"> ครูผู้สอน
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer select-none">
            <input type="checkbox" name="show_room_code" value="1" <?= $show_room_code?'checked':''; ?> class="rounded accent-indigo-600"> ใช้รหัสห้องแทนชื่อ
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer select-none">
            <input type="checkbox" name="show_approval" value="1" <?= $show_approval?'checked':''; ?> class="rounded accent-indigo-600"> แสดงช่องอนุมัติ (ผอ.)
          </label>
        </div>
      </div>

      <hr class="border-slate-100">

      <!-- Action buttons -->
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
          👁 แสดงตัวอย่าง
        </button>
        <button type="submit" name="export" value="print" formtarget="_blank"
          class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
          🖨️ พิมพ์ (แท็บใหม่)
        </button>
        <button type="submit" name="export" value="blank" formtarget="_blank"
          class="inline-flex items-center gap-2 px-5 py-2.5 bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
          📋 ตารางเปล่า
        </button>
        <a href="<?= h($_SERVER['PHP_SELF']) ?>"
          class="inline-flex items-center gap-2 px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">
          ↺ ล้างค่า
        </a>
      </div>
    </form>
  </div>

  <?= $reportHtml ?>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
(function(){
  // ✅ แสดง toast เมื่อบันทึกหัวกระดาษสำเร็จ
  <?php if (isset($_GET['__saved'])): ?>
  if (window.Swal) {
    Swal.fire({ icon:'success', title:'บันทึกสำเร็จ', text:'ข้อมูลหัวกระดาษถูกบันทึกแล้ว', timer:2000, showConfirmButton:false, toast:true, position:'top-end' });
  }
  <?php endif; ?>

  const form = document.getElementById('filterForm');
  
  const yearSel = document.getElementById('f_year');
  const yearText = document.getElementById('f_year_text');
  const termSel = document.getElementById('f_term');
  const termText = document.getElementById('f_term_text');
  
  const viewSel = document.getElementById('f_view');
  const groupSel = document.getElementById('f_group');

  // ✅ เก็บสถานะว่าผู้ใช้แก้ไขเอง
  if (yearText) yearText.addEventListener('input', ()=> yearText.dataset.touched = '1');
  if (termText) termText.addEventListener('input', ()=> termText.dataset.touched = '1');

  // ✅ Auto-update text อย่างเดียว (ไม่ submit)
  if (yearSel) yearSel.addEventListener('change', ()=>{
    if (yearText && !yearText.dataset.touched) {
      const lbl = yearSel.options[yearSel.selectedIndex]?.textContent.trim() || yearSel.value;
      yearText.value = 'ปีการศึกษา ' + lbl;
    }
  });

  if (termSel) termSel.addEventListener('change', ()=>{
    if (termText && !termText.dataset.touched) {
      termText.value = 'ภาคเรียนที่ ' + termSel.value;
    }
  });

  // ✅ Auto-submit เมื่อเปลี่ยนมุมมอง
  if (viewSel) {
    viewSel.addEventListener('change', ()=> {
      form.submit();
    });
  }

  // ✅ Auto-submit เมื่อเปลี่ยนกลุ่มสาระ (เฉพาะมุมมองครู)
  if (groupSel) {
    groupSel.addEventListener('change', ()=> {
      form.submit();
    });
  }

  // ✅ Loading overlay
  const ov = document.getElementById('loadingOverlay');
  if (form && ov) {
    form.addEventListener('submit', ()=> ov.classList.remove('hidden'));
  }
  // ซ่อน overlay เมื่อกด Back (pageshow จาก bfcache)
  window.addEventListener('pageshow', (e)=>{
    if (ov) ov.classList.add('hidden');
  });
})();
</script>

<div id="loadingOverlay" class="hidden fixed inset-0 bg-white/80 backdrop-blur-sm flex items-center justify-center z-50">
  <div class="flex flex-col items-center gap-4">
    <svg class="animate-spin h-10 w-10 text-indigo-500" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
    </svg>
    <p class="text-sm font-medium text-slate-600">กำลังสร้างรายงาน...</p>
  </div>
</div>
