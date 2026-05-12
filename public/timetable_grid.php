<?php
/**
 * timetable_grid.php — AJAX endpoint สำหรับโหลด grid ตารางสอน
 * Returns JSON: { grid: "<html>", remain: "<html>" }
 * POST ยังคงไปที่ timetable.php ตามปกติ
 */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method Not Allowed']));
}

header('Content-Type: application/json; charset=utf-8');
// ป้องกัน browser cache (ข้อมูลเปลี่ยนได้ตลอด)
header('Cache-Control: no-store');

/* =========================
   Helper functions (same as timetable.php)
========================= */
function th_dow($n){ static $m=[1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์']; return $m[(int)$n] ?? '-'; }

function subj_label($code,$name){
  $code=trim((string)$code);
  return $code!=='' ? ($code.' - '.$name) : $name;
}

function subj_name_only($name){
  return trim((string)$name);
}

function load_label_for_dropdown($view,$ld){
  $label = subj_name_only($ld['subject_name']);
  if ($view==='class') $label .= ' / ครู '.$ld['first_name'].' '.$ld['last_name'];
  else $label .= ' / ห้อง '.$ld['class_name'];
  return $label;
}

function load_label_full($view,$ld){
  $label = subj_label($ld['subject_code'] ?? '', $ld['subject_name']);
  if ($view==='class') $label .= ' / ครู '.$ld['first_name'].' '.$ld['last_name'];
  else $label .= ' / ห้อง '.$ld['class_name'];
  return $label;
}

function fetch_teachers_for_slots(PDO $pdo, array $slotIds) {
  if (!$slotIds) return [];
  $in = implode(',', array_map('intval', $slotIds));
  $sql = "SELECT st.slot_id, t.first_name, t.last_name
          FROM timetable_slot_teachers st
          JOIN teachers t ON t.id=st.teacher_id
          WHERE st.slot_id IN ($in)
          ORDER BY t.first_name, t.last_name";
  $map = [];
  foreach ($pdo->query($sql) as $r) $map[(int)$r['slot_id']][] = $r['first_name'].' '.$r['last_name'];
  return $map;
}

/* =========================
   Params
========================= */
$view = $_GET['view'] ?? 'class';
$view = in_array($view, ['class','teacher'], true) ? $view : 'class';

$year_id    = (int)($_GET['year_id']    ?? 0);
$term_no    = (int)($_GET['term_no']    ?? 0);
$class_id   = (int)($_GET['class_id']   ?? 0);
$teacher_id = (int)($_GET['teacher_id'] ?? 0);

if (!$year_id || !$term_no) {
    echo json_encode(['error' => 'Missing required params (year_id, term_no)']);
    exit;
}

/* =========================
   Basic data (lightweight)
========================= */
$classes = $pdo->query('SELECT id, class_name, grade_label, homeroom_room_id, has_saturday, has_sunday FROM classes ORDER BY class_name')
               ->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

$periods = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll();
if (!$periods) {
    echo json_encode(['error' => 'ยังไม่ได้กำหนดคาบเรียน']);
    exit;
}

$maxPeriod   = max(array_map(fn($p) => (int)$p['period_no'], $periods));
$periodCount = count($periods);

$rooms = $pdo->query('SELECT id, room_name FROM rooms ORDER BY room_name')
             ->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_COLUMN);

/* =========================
   has_saturday / has_sunday
========================= */
$has_saturday = false;
$has_sunday   = false;

if ($view === 'class' && $class_id) {
    $st = $pdo->prepare('SELECT has_saturday, has_sunday FROM classes WHERE id = ?');
    $st->execute([$class_id]);
    $cd = $st->fetch();
    if ($cd) {
        $has_saturday = (bool)$cd['has_saturday'];
        $has_sunday   = (bool)$cd['has_sunday'];
    }
} elseif ($view === 'teacher' && $teacher_id) {
    $st = $pdo->prepare("
        SELECT DISTINCT c.has_saturday, c.has_sunday
        FROM teaching_loads tl
        JOIN classes c ON c.id = tl.class_id
        WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.teacher_id=?
          AND (c.has_saturday=1 OR c.has_sunday=1)
    ");
    $st->execute([$year_id, $term_no, $teacher_id]);
    foreach ($st->fetchAll() as $tc) {
        if ((int)$tc['has_saturday'] === 1) $has_saturday = true;
        if ((int)$tc['has_sunday']   === 1) $has_sunday   = true;
    }
}

/* =========================
   Fetch slots
========================= */
if ($view === 'class') {
    $q = $pdo->prepare('
        SELECT ts.*, t.first_name, t.last_name, r.room_name
        FROM timetable_slots ts
        LEFT JOIN teachers t ON t.id=ts.teacher_id
        LEFT JOIN rooms r ON r.id=ts.room_id
        WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.class_id=?
    ');
    $q->execute([$year_id, $term_no, $class_id]);
    $slots = $q->fetchAll();
} else {
    $q = $pdo->prepare('
        SELECT DISTINCT ts.*, c.class_name, r.room_name
        FROM timetable_slots ts
        JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=?
        JOIN classes c ON c.id=ts.class_id
        LEFT JOIN rooms r ON r.id=ts.room_id
        WHERE ts.academic_year_id=? AND ts.term_no=?
        ORDER BY ts.day_of_week, ts.period_no
    ');
    $q->execute([$teacher_id, $year_id, $term_no]);
    $slots = $q->fetchAll();
}

$cell = [];
foreach ($slots as $s) { $cell[(int)$s['day_of_week']][(int)$s['period_no']][] = $s; }
$slotTeacherNames = fetch_teachers_for_slots($pdo, array_map(fn($r) => (int)$r['id'], $slots));

/* =========================
   Load room map (fallback)
========================= */
$loadRoomMap = [];
$stLR = $pdo->prepare("
    SELECT tl.class_id, tl.teacher_id, r.room_name,
           CASE WHEN IFNULL(s.subject_code,'')=''
                THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS subj_lbl
    FROM teaching_loads tl
    JOIN subjects s ON s.id=tl.subject_id
    LEFT JOIN rooms r ON r.id=tl.room_id
    WHERE tl.academic_year_id=? AND tl.term_no=?
");
$stLR->execute([$year_id, $term_no]);
foreach ($stLR as $row) {
    $key = $year_id.'|'.$term_no.'|'.$row['class_id'].'|'.$row['teacher_id'].'|'.$row['subj_lbl'];
    $loadRoomMap[$key] = $row['room_name'] ?? '';
}

/* =========================
   Breaks & Activities
========================= */
$grade_label_for_view = ($view === 'class') ? ($classes[$class_id]['grade_label'] ?? null) : null;
$breakPeriods = [];
if ($grade_label_for_view) {
    $st = $pdo->prepare('SELECT period_no FROM grade_breaks WHERE grade_label=?');
    $st->execute([$grade_label_for_view]);
    $breakPeriods = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$allPeriods     = array_map('intval', array_map(fn($p) => $p['period_no'], $periods));
$nonBreakPeriods = array_diff($allPeriods, $breakPeriods);

$activityCell = [];
if ($view === 'class') {
    $st = $pdo->prepare('
        SELECT ag.day_of_week, ag.period_no, ag.activity_name
        FROM activity_groups ag JOIN activity_classes ac ON ac.activity_id=ag.id
        WHERE ag.academic_year_id=? AND ag.term_no=? AND ac.class_id=? AND ag.is_all_day=0
    ');
    $st->execute([$year_id, $term_no, $class_id]);
    foreach ($st as $r) $activityCell[(int)$r['day_of_week']][(int)$r['period_no']] = $r['activity_name'];

    $stA = $pdo->prepare('
        SELECT ag.day_of_week, ag.activity_name
        FROM activity_groups ag JOIN activity_classes ac ON ac.activity_id=ag.id
        WHERE ag.academic_year_id=? AND ag.term_no=? AND ac.class_id=? AND ag.is_all_day=1
    ');
    $stA->execute([$year_id, $term_no, $class_id]);
    foreach ($stA as $r) {
        $day = (int)$r['day_of_week'];
        foreach ($nonBreakPeriods as $pno) {
            if (!isset($activityCell[$day][$pno])) $activityCell[$day][$pno] = $r['activity_name'];
        }
    }
} else {
    $st = $pdo->prepare('
        SELECT ag.day_of_week, ag.period_no, ag.activity_name
        FROM activity_groups ag JOIN activity_teachers at2 ON at2.activity_id=ag.id
        WHERE ag.academic_year_id=? AND ag.term_no=? AND at2.teacher_id=? AND ag.is_all_day=0
    ');
    $st->execute([$year_id, $term_no, $teacher_id]);
    foreach ($st as $r) $activityCell[(int)$r['day_of_week']][(int)$r['period_no']] = $r['activity_name'];

    $stA = $pdo->prepare('
        SELECT ag.day_of_week, ag.activity_name
        FROM activity_groups ag JOIN activity_teachers at2 ON at2.activity_id=ag.id
        WHERE ag.academic_year_id=? AND ag.term_no=? AND at2.teacher_id=? AND ag.is_all_day=1
    ');
    $stA->execute([$year_id, $term_no, $teacher_id]);
    foreach ($stA as $r) {
        $day = (int)$r['day_of_week'];
        foreach ($allPeriods as $pno) {
            if (!isset($activityCell[$day][$pno])) $activityCell[$day][$pno] = $r['activity_name'];
        }
    }
}

/* =========================
   Loads for add-form
========================= */
if ($view === 'class') {
    $loadsStmt = $pdo->prepare('
        SELECT tl.id, tl.periods_per_week, tl.room_id, tl.consecutive_slots,
               s.subject_code, s.subject_name, t.first_name, t.last_name,
               (SELECT COUNT(DISTINCT ts.id)
                FROM timetable_slots ts
                JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=tl.teacher_id
                WHERE ts.academic_year_id=tl.academic_year_id AND ts.term_no=tl.term_no
                  AND ts.class_id=tl.class_id
                  AND ts.subject_name=CASE WHEN IFNULL(s.subject_code,"")="" THEN s.subject_name ELSE CONCAT(s.subject_code," - ",s.subject_name) END
               ) AS used_count
        FROM teaching_loads tl
        JOIN subjects s ON s.id=tl.subject_id
        JOIN teachers t ON t.id=tl.teacher_id
        WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.class_id=?
        HAVING used_count < tl.periods_per_week
        ORDER BY s.subject_code, s.subject_name
    ');
    $loadsStmt->execute([$year_id, $term_no, $class_id]);
} else {
    $loadsStmt = $pdo->prepare('
        SELECT tl.id, tl.periods_per_week, tl.room_id, tl.consecutive_slots,
               s.subject_code, s.subject_name, c.class_name,
               (SELECT COUNT(DISTINCT ts.id)
                FROM timetable_slots ts
                JOIN timetable_slot_teachers st ON st.slot_id=ts.id AND st.teacher_id=?
                WHERE ts.academic_year_id=tl.academic_year_id AND ts.term_no=tl.term_no
                  AND ts.class_id=tl.class_id
                  AND ts.subject_name=CASE WHEN IFNULL(s.subject_code,"")="" THEN s.subject_name ELSE CONCAT(s.subject_code," - ",s.subject_name) END
               ) AS used_count
        FROM teaching_loads tl
        JOIN subjects s ON s.id=tl.subject_id
        JOIN classes c ON c.id=tl.class_id
        WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.teacher_id=?
        HAVING used_count < tl.periods_per_week
        ORDER BY c.class_name, s.subject_code, s.subject_name
    ');
    $loadsStmt->execute([$teacher_id, $year_id, $term_no, $teacher_id]);
}
$loads = $loadsStmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

/* =========================
   Remain rows
========================= */
$remainRows = [];
if ($view === 'class' && $class_id) {
    $st = $pdo->prepare("
        SELECT tl.id AS load_id, tl.periods_per_week, s.subject_code, s.subject_name,
               (SELECT COUNT(DISTINCT ts.id)
                FROM timetable_slots ts
                JOIN timetable_slot_teachers stt ON stt.slot_id=ts.id AND stt.teacher_id=tl.teacher_id
                WHERE ts.academic_year_id=tl.academic_year_id AND ts.term_no=tl.term_no AND ts.class_id=tl.class_id
                  AND ts.subject_name=CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END
               ) AS used_count
        FROM teaching_loads tl JOIN subjects s ON s.id=tl.subject_id
        WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.class_id=?
        ORDER BY s.subject_name, tl.id
    ");
    $st->execute([$year_id, $term_no, $class_id]);
    $remainRows = $st->fetchAll();
} elseif ($view === 'teacher' && $teacher_id) {
    $st = $pdo->prepare("
        SELECT tl.id AS load_id, tl.periods_per_week, s.subject_code, s.subject_name, c.class_name,
               (SELECT COUNT(DISTINCT ts.id)
                FROM timetable_slots ts
                JOIN timetable_slot_teachers stt ON stt.slot_id=ts.id AND stt.teacher_id=?
                WHERE ts.academic_year_id=tl.academic_year_id AND ts.term_no=tl.term_no AND ts.class_id=tl.class_id
                  AND ts.subject_name=CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name ELSE CONCAT(s.subject_code,' - ',s.subject_name) END
               ) AS used_count
        FROM teaching_loads tl JOIN subjects s ON s.id=tl.subject_id JOIN classes c ON c.id=tl.class_id
        WHERE tl.academic_year_id=? AND tl.term_no=? AND tl.teacher_id=?
        ORDER BY c.class_name, s.subject_name, tl.id
    ");
    $st->execute([$teacher_id, $year_id, $term_no, $teacher_id]);
    $remainRows = $st->fetchAll();
}

/* =========================
   Render grid HTML
========================= */
$daysToShow = [1,2,3,4,5];
if ($has_saturday) $daysToShow[] = 6;
if ($has_sunday)   $daysToShow[] = 7;

$dayColors = [
    1 => 'bg-yellow-50 border-l-4 border-yellow-400',
    2 => 'bg-pink-50 border-l-4 border-pink-400',
    3 => 'bg-green-50 border-l-4 border-green-400',
    4 => 'bg-orange-50 border-l-4 border-orange-400',
    5 => 'bg-blue-50 border-l-4 border-blue-400',
    6 => 'bg-purple-50 border-l-4 border-purple-400',
    7 => 'bg-red-50 border-l-4 border-red-400',
];
$dayNames = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];

ob_start();
?>
<!-- Template: room select (rendered once, cloned per cell by JS) -->
<template id="tpl-room">
  <select name="room_id" class="w-full border border-slate-300 rounded px-1.5 py-1.5 text-[11px]">
    <option value="">— ไม่กำหนด —</option>
    <?php foreach ($rooms as $rid => $rname): ?>
      <option value="<?= (int)$rid ?>"><?= htmlspecialchars((string)$rname) ?></option>
    <?php endforeach; ?>
  </select>
</template>

<!-- Template: loads select (rendered once, cloned per cell by JS) -->
<template id="tpl-loads">
  <select name="load_id" class="w-full border border-slate-300 rounded px-1.5 py-1.5 text-[11px]" required>
    <option value="">-- เลือก --</option>
    <?php foreach ($loads as $lid => $ld):
      $dr  = !empty($ld['room_id']) ? (int)$ld['room_id'] : 0;
      $dc  = !empty($ld['consecutive_slots']) ? (int)$ld['consecutive_slots'] : 1;
      $dis = load_label_for_dropdown($view, $ld);
      $ful = load_label_full($view, $ld);
      if (mb_strlen($dis) > 40) $dis = mb_substr($dis, 0, 37) . '...';
    ?>
      <option value="<?= (int)$lid ?>"
              data-default-room="<?= $dr ?>"
              data-default-consec="<?= $dc ?>"
              title="<?= htmlspecialchars($ful) ?>">
        <?= htmlspecialchars($dis) ?>
      </option>
    <?php endforeach; ?>
  </select>
</template>

<!-- Timetable grid -->
<div class="timetable-container bg-white rounded-2xl shadow mb-6">
  <table class="timetable-table text-sm border-collapse">
    <thead class="bg-slate-100">
      <tr>
        <th class="text-left px-4 py-3 border-b-2 border-slate-300 font-bold">วัน \ คาบ</th>
        <?php foreach ($periods as $p): ?>
          <th class="text-center px-2 py-3 border-b-2 border-slate-300 font-semibold">
            <?= (int)$p['period_no'] ?><br>
            <span class="text-xs text-slate-600 font-normal">
              <?= substr($p['start_time'],0,5) ?>–<?= substr($p['end_time'],0,5) ?>
            </span>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($daysToShow as $d): ?>
      <tr class="border-t border-slate-200">
        <td class="px-4 py-3 font-bold text-slate-800 <?= $dayColors[$d] ?? 'bg-slate-50' ?>">
          <?= $dayNames[$d] ?? '' ?>
        </td>
        <?php foreach ($periods as $p):
          $pp    = (int)$p['period_no'];
          $items = $cell[$d][$pp] ?? [];
        ?>
          <td class="px-2 py-2 border-l border-slate-200 align-top">
            <div class="cell-content">
              <?php
              if ($view === 'class' && in_array($pp, $breakPeriods)) {
                  echo '<div class="bg-yellow-100 border-2 border-yellow-400 rounded-lg p-2 text-center text-xs text-yellow-900 font-semibold">💤 พัก</div>';
              } elseif (isset($activityCell[$d][$pp])) {
                  echo '<div class="bg-sky-100 border-2 border-sky-400 rounded-lg p-2 text-center text-xs text-sky-900 font-semibold">🎯 '.htmlspecialchars((string)$activityCell[$d][$pp]).'</div>';
              } elseif ($items) {
                  foreach ($items as $it) {
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

                      $subjectFullName    = (string)$it['subject_name'];
                      $subjectDisplayName = $subjectFullName;
                      if (strpos($subjectFullName, ' - ') !== false) {
                          $parts = explode(' - ', $subjectFullName, 2);
                          $subjectDisplayName = $parts[1];
                      }

                      $tooltipParts = ['📚 '.$subjectFullName];
                      if (!empty($roomNameToShow)) $tooltipParts[] = '📍 ห้อง: '.$roomNameToShow;
                      if ($view === 'teacher' && !empty($it['class_name'])) $tooltipParts[] = '🎓 ชั้น: '.$it['class_name'];
                      if (!empty($names)) $tooltipParts[] = '👥 ครู: '.implode(', ', $names);
                      $tooltipParts[] = ($it['source']==='auto' ? '🤖 จัดอัตโนมัติ' : '✏️ จัดด้วยตนเอง');
                      $tooltip = implode("\n", $tooltipParts);
                  ?>
                      <div class="slot-card slot-<?= $it['source']==='auto' ? 'auto' : 'manual' ?> border-2 rounded-lg p-2 mb-1.5 transition-all shadow-sm hover:shadow"
                           title="<?= htmlspecialchars($tooltip) ?>">
                        <div class="subject-name"><?= htmlspecialchars($subjectDisplayName) ?></div>
                        <div class="info-row">
                          <?php if (!empty($roomNameToShow)): ?>
                            <span class="info-badge room-badge" title="ห้อง: <?= htmlspecialchars($roomNameToShow) ?>">
                              📍 <?= htmlspecialchars($roomNameToShow) ?>
                            </span>
                          <?php endif; ?>
                          <?php if ($view === 'teacher' && !empty($it['class_name'])): ?>
                            <span class="info-badge class-badge" title="ชั้น: <?= htmlspecialchars($it['class_name']) ?>">
                              🎓 <?= htmlspecialchars($it['class_name']) ?>
                            </span>
                          <?php endif; ?>
                          <?php foreach ($names as $n): ?>
                            <span class="info-badge teacher-badge" title="ครู: <?= htmlspecialchars($n) ?>">
                              👤 <?= htmlspecialchars($n) ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                        <div class="slot-footer">
                          <span class="source-badge">
                            <?= $it['source']==='auto' ? '🤖 AUTO' : '✏️ MANUAL' ?>
                          </span>
                          <form method="post" onsubmit="return ttConfirmSubmit(this,{text:'ลบคาบนี้?'});" style="display:inline;margin:0">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="slot_id" value="<?= (int)$it['id'] ?>">
                            <button type="submit" class="delete-btn text-rose-600 hover:text-rose-700 hover:bg-rose-50">🗑️ ลบ</button>
                          </form>
                        </div>
                      </div>
                  <?php
                  }
              } else {
              ?>
                <details data-max-period="<?= $maxPeriod ?>" data-period="<?= $pp ?>">
                  <summary>➕ เพิ่ม</summary>
                  <form method="post" class="add-form mt-2 space-y-1">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="day_of_week" value="<?= (int)$d ?>">
                    <input type="hidden" name="period_no" value="<?= (int)$pp ?>">

                    <label class="block text-[10px] font-semibold mb-0.5">กำลังสอน</label>
                    <div class="tt-loads-ph"></div>

                    <label class="block text-[10px] font-semibold mb-0.5">ห้อง</label>
                    <div class="tt-room-ph"></div>

                    <label class="block text-[10px] font-semibold mb-0.5">คาบติด</label>
                    <input type="number" name="span" value="1" min="1" max="<?= $maxPeriod - $pp + 1 ?>"
                           class="border border-slate-300 rounded w-full text-center text-xs">

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
<?php
$grid_html = ob_get_clean();

/* =========================
   Render remain HTML
========================= */
ob_start();
?>
<div class="bg-white rounded-2xl shadow p-4">
  <div class="font-medium mb-2 text-lg">
    📊 <?= $view==='class' ? 'วิชาที่ต้องลงให้ห้องนี้' : 'วิชาที่ต้องลงสำหรับครูนี้' ?>
  </div>
  <?php if (!$remainRows): ?>
    <div class="text-slate-500 text-sm py-6 text-center">✅ ไม่มีวิชาที่ต้องลงแล้ว (ครบทุกคาบ)</div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <?php if ($view === 'teacher'): ?><th class="text-left px-3 py-2 font-semibold">ห้อง</th><?php endif; ?>
            <th class="text-left px-3 py-2 font-semibold">วิชา</th>
            <th class="text-center px-3 py-2 font-semibold">ใช้ไป</th>
            <th class="text-center px-3 py-2 font-semibold">กำลัง</th>
            <th class="text-left px-3 py-2 font-semibold">คงเหลือ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($remainRows as $r):
            $label = subj_label($r['subject_code'], $r['subject_name']);
            $used  = (int)$r['used_count'];
            $ppw   = (int)$r['periods_per_week'];
            $left  = max(0, $ppw - $used);
          ?>
            <tr class="border-t hover:bg-slate-50">
              <?php if ($view === 'teacher'): ?>
                <td class="px-3 py-2"><?= htmlspecialchars((string)($r['class_name'] ?? '')) ?></td>
              <?php endif; ?>
              <td class="px-3 py-2 font-medium"><?= htmlspecialchars($label) ?></td>
              <td class="px-3 py-2 text-center"><?= $used ?></td>
              <td class="px-3 py-2 text-center"><?= $ppw ?></td>
              <td class="px-3 py-2">
                <div class="flex items-center gap-2">
                  <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full <?= $left===0 ? 'bg-emerald-400' : 'bg-blue-400' ?>"
                         style="width:<?= min(100,(int)round($used/$ppw*100)) ?>%"></div>
                  </div>
                  <?php if ($left > 0): ?>
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-100 text-blue-700 font-bold text-xs"><?= $left ?></span>
                  <?php else: ?>
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-emerald-100 text-emerald-600 font-bold text-xs">✓</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
$remain_html = ob_get_clean();

echo json_encode([
    'grid'   => $grid_html,
    'remain' => $remain_html,
]);
