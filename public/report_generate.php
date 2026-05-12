<?php
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/helpers.php';
require_once __DIR__.'/../app/db.php';
requireLogin(); requireAdmin();

$action = $_GET['action'] ?? 'preview';
$year_id = (int)($_GET['year_id'] ?? 0);
$term_no = (int)($_GET['term_no'] ?? 1);
$mode = $_GET['mode'] ?? 'class';
$class_id = (int)($_GET['class_id'] ?? 0);
$teacher_id = (int)($_GET['teacher_id'] ?? 0);
$school_name = trim($_GET['school_name'] ?? 'โรงเรียนของเรา');
$print_date = $_GET['print_date'] ?? date('Y-m-d');

// ดึง reference
$periods = $pdo->query("SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
$rooms = $pdo->query("SELECT id, room_name FROM rooms")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);
$classes = $pdo->query("SELECT id, class_name, grade_label, homeroom_room_id FROM classes")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
$teachers = $pdo->query("SELECT id, first_name, last_name FROM teachers")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

// โหลด map: ห้องประจำ
$homeroomName = function(int $cid) use ($classes, $rooms){
  $hid = (int)($classes[$cid]['homeroom_room_id'] ?? 0);
  if ($hid && !empty($rooms[$hid])) return $rooms[$hid];
  return '';
};

// map ห้องจากกำลังสอน (load.room_id → room_name)
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

// ฟังก์ชันช่วย
function th_dow($n){ static $m=[1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์']; return $m[(int)$n] ?? '-'; }
function subj_label($code,$name){ $code=trim((string)$code); return $code!=='' ? ($code.' - '.$name) : $name; }
function th_date_str($ymd){
  if (!$ymd) return '';
  [$y,$m,$d]=explode('-',$ymd);
  $months=[1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $y=(int)$y+543; return ltrim($d,'0').' '.$months[(int)$m].' '.$y;
}

// ดึงตารางแบบยืดหยุ่น (ตามห้อง / ตามครู)
function fetchSlotsForClass(PDO $pdo, int $year_id, int $term_no, int $class_id){
  $q=$pdo->prepare('SELECT ts.*, r.room_name
                    FROM timetable_slots ts
                    LEFT JOIN rooms r ON r.id=ts.room_id
                    WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.class_id=?');
  $q->execute([$year_id,$term_no,$class_id]);
  $slots=$q->fetchAll();
  // ครูร่วม
  $in = implode(',', array_map(fn($r)=>(int)$r['id'],$slots) ?: [0]);
  $map=[];
  if ($in!=='0') {
    $st = $pdo->query("SELECT st.slot_id, t.first_name, t.last_name
                       FROM timetable_slot_teachers st
                       JOIN teachers t ON t.id=st.teacher_id
                       WHERE st.slot_id IN ($in)
                       ORDER BY t.first_name, t.last_name");
    foreach($st as $r) $map[(int)$r['slot_id']][] = $r['first_name'].' '.$r['last_name'];
  }
  return [$slots,$map];
}
function fetchSlotsForTeacher(PDO $pdo, int $year_id, int $term_no, int $teacher_id){
  $q=$pdo->prepare('SELECT DISTINCT ts.*, c.class_name, r.room_name
                    FROM timetable_slots ts
                    JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=?
                    JOIN classes c ON c.id=ts.class_id
                    LEFT JOIN rooms r ON r.id=ts.room_id
                    WHERE ts.academic_year_id=? AND ts.term_no=?
                    ORDER BY ts.day_of_week, ts.period_no');
  $q->execute([$teacher_id,$year_id,$term_no]);
  $slots=$q->fetchAll();
  // ครูร่วม
  $in = implode(',', array_map(fn($r)=>(int)$r['id'],$slots) ?: [0]);
  $map=[];
  if ($in!=='0') {
    $st = $pdo->query("SELECT st.slot_id, t.first_name, t.last_name
                       FROM timetable_slot_teachers st
                       JOIN teachers t ON t.id=st.teacher_id
                       WHERE st.slot_id IN ($in)
                       ORDER BY t.first_name, t.last_name");
    foreach($st as $r) $map[(int)$r['slot_id']][] = $r['first_name'].' '.$r['last_name'];
  }
  return [$slots,$map];
}

// เรนเดอร์ 1 หน้า (ตามห้องหรือครู)
function renderOnePage(array $opt){
  // $opt: school_name, print_date, mode, class, teacher, periods, slots, slotTeachers, rooms, classes, homeroomName, loadRoomMap
  extract($opt);
  ob_start();
  ?>
  <pagebreak />
  <div class="header">
    <div class="school"><?= htmlspecialchars($school_name) ?></div>
    <div class="meta">
      พิมพ์ ณ วันที่ <?= htmlspecialchars(th_date_str($print_date)) ?>
    </div>
    <div class="title">
      <?php if ($mode==='class'): ?>
        ตารางสอนห้อง <?= htmlspecialchars($class['class_name']) ?>
      <?php else: ?>
        ตารางสอนครู <?= htmlspecialchars($teacher['first_name'].' '.$teacher['last_name']) ?>
      <?php endif; ?>
      <div class="sub">
        <?php if ($mode==='class'): ?>
          ระดับชั้น: <?= htmlspecialchars($class['grade_label']) ?>
          <?php
            // ครูประจำชั้น
            // หา homeroom teacher จาก loads ที่เป็นประจำชั้น (ถ้ามีเก็บไว้) — ถ้าไม่มี อาจข้ามได้
            // ที่นี่จะดึง “ครูลงสอนห้องนี้มากที่สุด” มาแปะเป็นไกด์
            // หมายเหตุ: หากคุณมีตาราง homeroom แยก ให้ดึงตรงนั้นแทนได้
          ?>
        <?php else: ?>
          ครูผู้สอน: <?= htmlspecialchars($teacher['first_name'].' '.$teacher['last_name']) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <table class="tt">
    <thead>
      <tr>
        <th class="day">วัน \ คาบ</th>
        <?php foreach ($periods as $pno => $pv): ?>
          <th>
            <?= (int)$pno ?><br>
            <span class="small"><?= substr($pv['start_time'],0,5) ?>–<?= substr($pv['end_time'],0,5) ?></span>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php
      $DAYS=[1,2,3,4,5];
      // ทำ cell map
      $cell=[]; foreach($slots as $s){ $cell[(int)$s['day_of_week']][(int)$s['period_no']][]=$s; }

      foreach($DAYS as $d): ?>
        <tr>
          <td class="day"><?= ['','จันทร์','อังคาร','พุธ','พฤหัส','ศุกร์'][$d] ?></td>
          <?php foreach($periods as $pno => $pv):
            $list = $cell[$d][$pno] ?? [];
          ?>
          <td>
            <?php if ($list): foreach($list as $it):
              $names = $slotTeachers[$it['id']] ?? [];

              // เลือกห้องแสดง: slot → load → homeroom → class_name
              $roomNameToShow = $it['room_name'] ?? '';
              if ($roomNameToShow==='' || $roomNameToShow===null){
                $loadKey = $year_id.'|'.$term_no.'|'.$it['class_id'].'|'.$it['teacher_id'].'|'.$it['subject_name'];
                $loadRoomName = $loadRoomMap[$loadKey] ?? '';
                if ($loadRoomName!=='') {
                  $roomNameToShow = $loadRoomName;
                } else {
                  $roomNameToShow = $homeroomName((int)$it['class_id']);
                  if ($roomNameToShow==='') $roomNameToShow = $classes[$it['class_id']]['class_name'] ?? '';
                }
              }
            ?>
              <div class="slot">
                <div class="subj"><?= htmlspecialchars($it['subject_name']) ?></div>
                <?php if ($mode==='teacher'): ?>
                  <div class="class">ห้อง: <?= htmlspecialchars($classes[$it['class_id']]['class_name'] ?? '') ?></div>
                <?php endif; ?>
                <?php if (!empty($roomNameToShow)): ?>
                  <div class="room">ห้อง: <?= htmlspecialchars($roomNameToShow) ?></div>
                <?php endif; ?>
                <?php if ($mode==='class' && $names): ?>
                  <div class="teachers"><?= htmlspecialchars(implode(', ', $names)) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  return ob_get_clean();
}

// ==== สร้างเนื้อหา HTML หลายหน้า ตามโหมดที่เลือก ==== //
$pagesHTML = '';
if ($mode==='class' && $class_id>0) {
  [$slots,$slotTeachers] = fetchSlotsForClass($pdo,$year_id,$term_no,$class_id);
  $pagesHTML .= renderOnePage([
    'school_name'=>$school_name,
    'print_date'=>$print_date,
    'mode'=>'class',
    'class'=>$classes[$class_id],
    'teacher'=>null,
    'periods'=>$periods,
    'slots'=>$slots,
    'slotTeachers'=>$slotTeachers,
    'rooms'=>$rooms,
    'classes'=>$classes,
    'homeroomName'=>$homeroomName,
    'loadRoomMap'=>$loadRoomMap,
    'year_id'=>$year_id,
    'term_no'=>$term_no
  ]);

} elseif ($mode==='teacher' && $teacher_id>0) {
  [$slots,$slotTeachers] = fetchSlotsForTeacher($pdo,$year_id,$term_no,$teacher_id);
  $pagesHTML .= renderOnePage([
    'school_name'=>$school_name,
    'print_date'=>$print_date,
    'mode'=>'teacher',
    'class'=>null,
    'teacher'=>$teachers[$teacher_id],
    'periods'=>$periods,
    'slots'=>$slots,
    'slotTeachers'=>$slotTeachers,
    'rooms'=>$rooms,
    'classes'=>$classes,
    'homeroomName'=>$homeroomName,
    'loadRoomMap'=>$loadRoomMap,
    'year_id'=>$year_id,
    'term_no'=>$term_no
  ]);

} elseif ($mode==='all_class') {
  // ทุกห้อง — 1 หน้า/ห้อง
  $all = $pdo->query("SELECT id FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($all as $cid) {
    [$slots,$slotTeachers] = fetchSlotsForClass($pdo,$year_id,$term_no,(int)$cid);
    $pagesHTML .= renderOnePage([
      'school_name'=>$school_name,
      'print_date'=>$print_date,
      'mode'=>'class',
      'class'=>$classes[(int)$cid],
      'teacher'=>null,
      'periods'=>$periods,
      'slots'=>$slots,
      'slotTeachers'=>$slotTeachers,
      'rooms'=>$rooms,
      'classes'=>$classes,
      'homeroomName'=>$homeroomName,
      'loadRoomMap'=>$loadRoomMap,
      'year_id'=>$year_id,
      'term_no'=>$term_no
    ]);
  }

} elseif ($mode==='all_teacher') {
  // ทุกครู — 1 หน้า/ครู
  $all = $pdo->query("SELECT id FROM teachers ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($all as $tid) {
    [$slots,$slotTeachers] = fetchSlotsForTeacher($pdo,$year_id,$term_no,(int)$tid);
    $pagesHTML .= renderOnePage([
      'school_name'=>$school_name,
      'print_date'=>$print_date,
      'mode'=>'teacher',
      'class'=>null,
      'teacher'=>$teachers[(int)$tid],
      'periods'=>$periods,
      'slots'=>$slots,
      'slotTeachers'=>$slotTeachers,
      'rooms'=>$rooms,
      'classes'=>$classes,
      'homeroomName'=>$homeroomName,
      'loadRoomMap'=>$loadRoomMap,
      'year_id'=>$year_id,
      'term_no'=>$term_no
    ]);
  }
}

// ============== สไตล์ PDF/Preview ==============
$_fontDir = __DIR__ . '/assets/fonts/';
$_b64Regular = is_file($_fontDir.'Sarabun-Regular.ttf')
    ? 'data:font/truetype;base64,'.base64_encode(file_get_contents($_fontDir.'Sarabun-Regular.ttf'))
    : null;
$_b64Bold = is_file($_fontDir.'Sarabun-Bold.ttf')
    ? 'data:font/truetype;base64,'.base64_encode(file_get_contents($_fontDir.'Sarabun-Bold.ttf'))
    : null;
$_urlRegular = url('assets/fonts/Sarabun-Regular.ttf');
$_urlBold    = url('assets/fonts/Sarabun-Bold.ttf');

// src: base64 (ไม่ต้องดึงเน็ต) ก่อน แล้ว fallback URL ตรง
$_srcRegular = $_b64Regular
    ? "url('{$_b64Regular}') format('truetype'), url('{$_urlRegular}') format('truetype')"
    : "url('{$_urlRegular}') format('truetype')";
$_srcBold = $_b64Bold
    ? "url('{$_b64Bold}') format('truetype'), url('{$_urlBold}') format('truetype')"
    : "url('{$_urlBold}') format('truetype')";

$css = <<<CSS
@page { size: A4 landscape; margin: 18mm 14mm; }
@font-face {
  font-family: 'Sarabun';
  src: {$_srcRegular};
  font-weight: normal; font-style: normal;
}
@font-face {
  font-family: 'Sarabun';
  src: {$_srcBold};
  font-weight: bold; font-style: normal;
}
* { font-family: 'Sarabun', DejaVu Sans, sans-serif; }
.header { text-align:center; margin-bottom: 8px; }
.header .school { font-size: 20px; font-weight: bold; }
.header .meta { font-size: 12px; color:#555; margin-top: 2px; }
.header .title { font-size: 16px; margin-top: 6px; }
.header .title .sub { font-size: 12px; color:#333; margin-top: 2px; }

.tt { width:100%; border-collapse: collapse; table-layout: fixed; }
.tt th, .tt td { border:1px solid #999; padding:6px 5px; vertical-align:top; }
.tt th { background:#f5f7fa; }
.tt .day { width: 90px; font-weight:bold; }
.tt th:not(.day), .tt td { width: calc((100% - 90px)/5); }

.slot { margin-bottom:6px; }
.slot .subj { font-weight: bold; }
.slot .class, .slot .room, .slot .teachers { font-size: 12px; color:#333; }

.small { font-size: 11px; color:#666; }
CSS;

// ============== สร้าง HTML เต็ม ==============
$html  = '<!DOCTYPE html><html><head><meta charset="utf-8">';
$html .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
$html .= '<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">';
$html .= '<style>'.$css.'</style></head><body>';
// ตัด pagebreak หน้าแรกทิ้ง (เพราะเราใส่ <pagebreak /> ใน renderOnePage)
$html .= preg_replace('/^\s*<pagebreak \/>/','', $pagesHTML);
$html .= '</body></html>';

// ====== โหมด Preview ======
if ($action==='preview') {
  header('Content-Type: text/html; charset=utf-8');
  echo $html;
  exit;
}

// ====== โหมด PDF ======
require_once __DIR__.'/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$opt = new Options();
$opt->set('isRemoteEnabled', true);
$opt->set('chroot', realpath(__DIR__)); // อนุญาตโหลด fonts/ ภายใต้ public/
$dompdf = new Dompdf($opt);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'timetable_'.($mode==='class'?'class':'teacher').'_'.date('Ymd_His').'.pdf';
$dompdf->stream($filename, ['Attachment'=>true]);
