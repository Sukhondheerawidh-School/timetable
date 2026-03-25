<?php
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/helpers.php';
requireLogin(); requireAdmin();

$autoload = __DIR__.'/../vendor/autoload.php';
if (!file_exists($autoload)) die('ไม่พบ dompdf: composer require dompdf/dompdf');
require_once $autoload;
use Dompdf\Dompdf;

$year_id   = (int)($_GET['year_id'] ?? 0);
$term_no   = (int)($_GET['term_no'] ?? 1);
$view      = $_GET['view'] ?? 'class';
$class_id  = (int)($_GET['class_id'] ?? 0);
$teacher_id= (int)($_GET['teacher_id'] ?? 0);

$opt = [
  'show_subj_name'=> isset($_GET['show_subj_name']) ? 1:0,
  'show_subj_code'=> isset($_GET['show_subj_code']) ? 1:0,
  'show_room'     => isset($_GET['show_room']) ? 1:0,
  'show_teachers' => isset($_GET['show_teachers']) ? 1:0,
];

// periods
try {
  $periods = $pdo->query("SELECT id,period_no,start_time,end_time FROM period_slots ORDER BY period_no")->fetchAll();
} catch (PDOException $e) {
  $periods = $pdo->query("SELECT id,period_no,start_time,end_time FROM periods ORDER BY period_no")->fetchAll();
}
$days = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์'];

// grid
function fetch_grid(PDO $pdo, $year_id, $term_no, $view, $class_id, $teacher_id){
  $sql_with_co = "
    SELECT ts.id, ts.day_of_week, ts.period_no,
           ts.class_id, c.class_name,
           ts.room_id, r.room_name,
           ts.subject_name AS subject_name,
           NULL AS subject_code,
           TRIM(BOTH ' +' FROM COALESCE(
             NULLIF(GROUP_CONCAT(DISTINCT CONCAT(t1.first_name,' ',t1.last_name) ORDER BY t1.first_name SEPARATOR ' + '),''),
             CONCAT(t2.first_name,' ',t2.last_name)
           )) AS teachers
    FROM timetable_slots ts
    LEFT JOIN classes c ON c.id=ts.class_id
    LEFT JOIN rooms   r ON r.id=ts.room_id
    LEFT JOIN timetable_slot_teachers st ON st.slot_id=ts.id
    LEFT JOIN teachers t1 ON t1.id=st.teacher_id
    LEFT JOIN teachers t2 ON t2.id=ts.teacher_id
    WHERE ts.academic_year_id=? AND ts.term_no=?
      ".($view==='class' ? " AND ts.class_id=?" : " AND (st.teacher_id=? OR ts.teacher_id=?)")."
    GROUP BY ts.id
    ORDER BY ts.day_of_week, ts.period_no
  ";
  $sql_simple = "
    SELECT ts.id, ts.day_of_week, ts.period_no,
           ts.class_id, c.class_name,
           ts.room_id, r.room_name,
           ts.subject_name AS subject_name,
           NULL AS subject_code,
           CONCAT(t2.first_name,' ',t2.last_name) AS teachers
    FROM timetable_slots ts
    LEFT JOIN classes c ON c.id=ts.class_id
    LEFT JOIN rooms   r ON r.id=ts.room_id
    LEFT JOIN teachers t2 ON t2.id=ts.teacher_id
    WHERE ts.academic_year_id=? AND ts.term_no=?
      ".($view==='class' ? " AND ts.class_id=?" : " AND ts.teacher_id=?")."
    GROUP BY ts.id
    ORDER BY ts.day_of_week, ts.period_no
  ";
  try {
    $stmt=$pdo->prepare($sql_with_co);
    if ($view==='class') $stmt->execute([$year_id,$term_no,$class_id]);
    else                 $stmt->execute([$year_id,$term_no,$teacher_id,$teacher_id]);
    $rows=$stmt->fetchAll();
  } catch(PDOException $e) {
    $stmt=$pdo->prepare($sql_simple);
    if ($view==='class') $stmt->execute([$year_id,$term_no,$class_id]);
    else                 $stmt->execute([$year_id,$term_no,$teacher_id]);
    $rows=$stmt->fetchAll();
  }
  $grid=[]; foreach($rows as $r){ $grid[(int)$r['day_of_week']][(int)$r['period_no']]=$r; } return $grid;
}
$grid = fetch_grid($pdo,$year_id,$term_no,$view,$class_id,$teacher_id);

// homeroom
$homeroom='';
if ($view==='class'){
  try{
    $h=$pdo->prepare("SELECT GROUP_CONCAT(CONCAT(t.first_name,' ',t.last_name) SEPARATOR ', ')
                      FROM class_homeroom_teachers cht
                      JOIN teachers t ON t.id=cht.teacher_id
                      WHERE cht.class_id=?");
    $h->execute([$class_id]);
    $homeroom=trim($h->fetchColumn() ?? '');
  }catch(PDOException $e){}
}

// title
if ($view==='class'){
  $title='ตารางสอนห้อง '.htmlspecialchars($pdo->query("SELECT class_name FROM classes WHERE id={$class_id}")->fetchColumn());
}else{
  $title='ตารางสอนครู '.htmlspecialchars($pdo->query("SELECT CONCAT(first_name,' ',last_name) FROM teachers WHERE id={$teacher_id}")->fetchColumn());
}

// logo
$logo_path = __DIR__.'/assets/report_logo.png';
$logo_html = '';
if (file_exists($logo_path)) {
  $b64 = base64_encode(file_get_contents($logo_path));
  $logo_html = '<img src="data:image/png;base64,'.$b64.'" style="height:40px;vertical-align:middle;margin-right:8px;">';
}

// css
$css='@page{margin:30px 30px 40px 30px;}
body{font-family:"Sarabun",DejaVu Sans,sans-serif;font-size:12px}
.header{display:flex;align-items:center;margin-bottom:8px}
.title{font-weight:700;font-size:16px}
.sub{font-size:12px;color:#666;margin-top:4px}
table{border-collapse:collapse;width:100%;table-layout:fixed}
th,td{border:1px solid #E5E7EB;padding:6px;text-align:center;vertical-align:middle}
th{background:#F8FAFC}
td{height:60px}
.footer{position:fixed;bottom:10px;right:0;left:0;text-align:right;font-size:10px;color:#666}';

function cell_text_pdf($row,$opt){
  if(!$row) return '';
  $p=[];
  if($opt['show_subj_name'] && !empty($row['subject_name'])) $p[]=htmlspecialchars($row['subject_name']);
  if($opt['show_subj_code'] && !empty($row['subject_code'])) $p[]=htmlspecialchars($row['subject_code']);
  if($opt['show_room'] && !empty($row['room_name']))        $p[]='ห้อง: '.htmlspecialchars($row['room_name']);
  if($opt['show_teachers'] && !empty($row['teachers']))     $p[]=htmlspecialchars($row['teachers']);
  return implode('<br>',$p);
}

ob_start(); ?>
<html><meta charset="utf-8"><style><?= $css ?></style><body>
<div class="header"><?= $logo_html ?><div class="title"><?= $title ?></div></div>
<?php if($view==='class' && $homeroom): ?><div class="sub">ครูประจำชั้น: <?= htmlspecialchars($homeroom) ?></div><?php endif; ?>

<table>
  <thead>
    <tr>
      <th style="width:90px">วัน \ คาบ</th>
      <?php foreach($periods as $p): ?>
        <th><?= (int)$p['period_no'] ?><br><span style="font-size:10px;color:#666;"><?= htmlspecialchars(substr($p['start_time'],0,5)) ?>–<?= htmlspecialchars(substr($p['end_time'],0,5)) ?></span></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach([1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์'] as $dno=>$dname): ?>
      <tr>
        <td><strong><?= $dname ?></strong></td>
        <?php foreach($periods as $p): $row=$grid[$dno][$p['period_no']] ?? null; ?>
          <td><?= cell_text_pdf($row,$opt) ?: '&nbsp;' ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="footer">พิมพ์ ณ วันที่ <?= thaidate(date('Y-m-d')) ?></div>
</body></html>
<?php
$html = ob_get_clean();
$dompdf = new Dompdf();
$dompdf->loadHtml($html,'UTF-8');
$dompdf->setPaper('A4','landscape');
$dompdf->render();
$dompdf->stream('timetable.pdf', ['Attachment'=>0]);
