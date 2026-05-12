<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();

function th_dow_opts(){ return [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์']; }

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM activity_groups WHERE id=?'); $st->execute([$id]);
$act = $st->fetch();
if(!$act){ flash_set('error','ไม่พบข้อมูล'); redirect('activities.php'); }

$years    = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$periods  = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll();
$classes  = $pdo->query('SELECT id, class_name FROM classes ORDER BY class_name')->fetchAll();

$lc = function(string $s): string {
  return function_exists('mb_strtolower') ? (string)mb_strtolower($s, 'UTF-8') : strtolower($s);
};

// ✅ ดึงครูพร้อมรหัสครู เรียงตามรหัส (เหมือนหน้า create)
$teachersRaw = $pdo->query('
  SELECT 
    id, 
    teacher_code,
    first_name, 
    last_name,
    subject_group
  FROM teachers
  ORDER BY teacher_code, first_name, last_name
')->fetchAll();

// เพิ่มชื่อกลุ่มสาระให้แต่ละครู
$teachers = [];
foreach ($teachersRaw as $t) {
  $teachers[] = [
    'id' => $t['id'],
    'teacher_code' => $t['teacher_code'],
    'first_name' => $t['first_name'],
    'last_name' => $t['last_name'],
    'subject_group' => $t['subject_group'],
    'subject_name' => teacher_group_label((int)$t['subject_group'])
  ];
}

$rooms    = $pdo->query('SELECT id, room_code, room_name FROM rooms ORDER BY room_code')->fetchAll();
$formYearId = (int)($_POST['academic_year_id'] ?? $act['academic_year_id']);
$formTermNo = (int)($_POST['term_no'] ?? $act['term_no']);
$termOptions = tt_terms_list($pdo, $formYearId);
$formTermNo = tt_validate_term_no($pdo, $formYearId, $formTermNo);

$clsSel = $pdo->prepare('SELECT class_id FROM activity_classes WHERE activity_id=?'); $clsSel->execute([$id]);
$selClasses = array_map('intval', array_column($clsSel->fetchAll(),'class_id'));

$tchSel = $pdo->prepare('SELECT teacher_id FROM activity_teachers WHERE activity_id=?'); $tchSel->execute([$id]);
$selTeachers = array_map('intval', array_column($tchSel->fetchAll(),'teacher_id'));

$err=''; if($_SERVER['REQUEST_METHOD']==='POST'){
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err='CSRF ไม่ถูกต้อง';
  } else {
    // ── AJAX check_only (read-only, ข้ามการตรวจ canEdit) ──────────────
    if (($_POST['check_only'] ?? '') === '1') {
      header('Content-Type: application/json; charset=utf-8');
      $ck_year   = (int)($_POST['academic_year_id'] ?? 0);
      $ck_term   = $ck_year > 0 ? tt_validate_term_no($pdo, $ck_year, (int)($_POST['term_no'] ?? 1)) : 1;
      $ck_dow    = (int)($_POST['day_of_week'] ?? 0);
      $ck_allday = (int)($_POST['is_all_day'] ?? 0) === 1;
      $ck_pno    = $ck_allday ? null : ((int)($_POST['period_no'] ?? 0) ?: null);
      $ck_tids   = array_map('intval', (array)($_POST['teacher_ids'] ?? []));
      $conflicts = [];
      if (!$ck_allday && !empty($ck_tids) && $ck_pno !== null) {
        $ph = implode(',', array_fill(0, count($ck_tids), '?'));
        $stTS = $pdo->prepare("
          SELECT DISTINCT t.first_name, t.last_name, c.class_name, s.subject_name, 'คาบสอน' AS ctype
          FROM timetable_slots s JOIN teachers t ON t.id=s.teacher_id JOIN classes c ON c.id=s.class_id
          WHERE s.academic_year_id=? AND s.term_no=? AND s.day_of_week=? AND s.period_no=? AND s.teacher_id IN ($ph)
          UNION
          SELECT DISTINCT t.first_name, t.last_name, c.class_name, s.subject_name, 'คาบสอน' AS ctype
          FROM timetable_slot_teachers tst JOIN timetable_slots s ON s.id=tst.slot_id
          JOIN teachers t ON t.id=tst.teacher_id JOIN classes c ON c.id=s.class_id
          WHERE s.academic_year_id=? AND s.term_no=? AND s.day_of_week=? AND s.period_no=? AND tst.teacher_id IN ($ph)
        ");
        $stTS->execute(array_merge([$ck_year,$ck_term,$ck_dow,$ck_pno],$ck_tids,[$ck_year,$ck_term,$ck_dow,$ck_pno],$ck_tids));
        foreach ($stTS->fetchAll() as $cf) {
          $conflicts[] = ['name'=>$cf['first_name'].' '.$cf['last_name'],'ctype'=>$cf['ctype'],'detail'=>$cf['class_name'].' ('.$cf['subject_name'].')'];
        }
        $stAG = $pdo->prepare("
          SELECT DISTINCT t.first_name, t.last_name, ag.activity_name, 'กิจกรรมอื่น' AS ctype
          FROM activity_teachers atr JOIN activity_groups ag ON ag.id=atr.activity_id JOIN teachers t ON t.id=atr.teacher_id
          WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.day_of_week=? AND ag.period_no=? AND ag.is_all_day=0 AND ag.id<>? AND atr.teacher_id IN ($ph)
        ");
        $stAG->execute(array_merge([$ck_year,$ck_term,$ck_dow,$ck_pno,$id],$ck_tids));
        foreach ($stAG->fetchAll() as $cf) {
          $conflicts[] = ['name'=>$cf['first_name'].' '.$cf['last_name'],'ctype'=>$cf['ctype'],'detail'=>$cf['activity_name']];
        }
      }
      echo json_encode(['ok' => empty($conflicts), 'conflicts' => $conflicts]);
      exit;
    }
    // ──────────────────────────────────────────────────────────────────
    if (!canEditSection('activities')) {
      $err='🔒 ระบบปิดการแก้ไขชั่วคราว กรุณาติดต่อ Superuser';
    } else {
    $year_id  = (int)($_POST['academic_year_id'] ?? $act['academic_year_id']);
    $term_no  = (int)($_POST['term_no'] ?? 1);
    if ($year_id > 0) $term_no = tt_validate_term_no($pdo, $year_id, $term_no);
    $name     = trim($_POST['activity_name'] ?? '');
    $dow      = (int)($_POST['day_of_week'] ?? 0);
    $is_all_day = (int)($_POST['is_all_day'] ?? 0) === 1 ? 1 : 0;
    $pno      = $is_all_day ? null : ((int)($_POST['period_no'] ?? 0) ?: null);
    $room_id  = ($_POST['room_id'] ?? '')!=='' ? (int)$_POST['room_id'] : null;
    $class_ids   = array_map('intval', (array)($_POST['class_ids'] ?? []));
    $teacher_ids = array_map('intval', (array)($_POST['teacher_ids'] ?? []));

    // For all-day activities, teacher is optional; for regular, teacher is required
    $validTeachers = $is_all_day ? true : !empty($teacher_ids);
    $validPeriod = $is_all_day ? true : $pno !== null;

    if(!$year_id || !$term_no || $name==='' || !$dow || !$class_ids || !$validTeachers || !$validPeriod){
      if ($is_all_day) {
        $err='กรอกข้อมูลให้ครบ (ปี/เทอม/ชื่อกิจกรรม/วัน/ชั้น) - ครูเป็นตัวเลือก';
      } else {
        $err='กรอกข้อมูลให้ครบ (ปี/เทอม/ชื่อกิจกรรม/วัน/คาบ/ชั้น/ครู)';
      }
    }else{
      try{
        // ===== ตรวจ conflict ครู (เฉพาะกิจกรรมมีคาบ, ยกเว้นตัวเอง) =====
        if (!$is_all_day && !empty($teacher_ids) && $pno !== null) {
          $ph = implode(',', array_fill(0, count($teacher_ids), '?'));
          $sqlTS = "
            SELECT DISTINCT t.first_name, t.last_name, c.class_name, s.subject_name, 'คาบสอน' AS ctype
            FROM timetable_slots s JOIN teachers t ON t.id=s.teacher_id JOIN classes c ON c.id=s.class_id
            WHERE s.academic_year_id=? AND s.term_no=? AND s.day_of_week=? AND s.period_no=? AND s.teacher_id IN ($ph)
            UNION
            SELECT DISTINCT t.first_name, t.last_name, c.class_name, s.subject_name, 'คาบสอน' AS ctype
            FROM timetable_slot_teachers tst JOIN timetable_slots s ON s.id=tst.slot_id
            JOIN teachers t ON t.id=tst.teacher_id JOIN classes c ON c.id=s.class_id
            WHERE s.academic_year_id=? AND s.term_no=? AND s.day_of_week=? AND s.period_no=? AND tst.teacher_id IN ($ph)
          ";
          $stTS = $pdo->prepare($sqlTS);
          $stTS->execute(array_merge([$year_id,$term_no,$dow,$pno],$teacher_ids,[$year_id,$term_no,$dow,$pno],$teacher_ids));
          $cfsTS = $stTS->fetchAll();
          $sqlAG = "
            SELECT DISTINCT t.first_name, t.last_name, ag.activity_name AS class_name, ag.activity_name AS subject_name, 'กิจกรรมอื่น' AS ctype
            FROM activity_teachers atr JOIN activity_groups ag ON ag.id=atr.activity_id JOIN teachers t ON t.id=atr.teacher_id
            WHERE ag.academic_year_id=? AND ag.term_no=? AND ag.day_of_week=? AND ag.period_no=? AND ag.is_all_day=0 AND ag.id<>? AND atr.teacher_id IN ($ph)
          ";
          $stAG = $pdo->prepare($sqlAG);
          $stAG->execute(array_merge([$year_id,$term_no,$dow,$pno,$id],$teacher_ids));
          $cfsAG = $stAG->fetchAll();
          $allCfs = array_merge($cfsTS,$cfsAG);
          if (!empty($allCfs)) {
            $lines = ['ไม่สามารถบันทึกได้ เนื่องจากครูบางคนมีตารางซ้อนในวัน/คาบที่เลือก:'];
            foreach ($allCfs as $cf) {
              $tName = $cf['first_name'].' '.$cf['last_name'];
              if ($cf['ctype']==='คาบสอน') $lines[] = '  • '.$tName.' — ติดคาบสอน '.$cf['class_name'].' ('.$cf['subject_name'].')';
              else $lines[] = '  • '.$tName.' — ติดกิจกรรม "'.$cf['class_name'].'"';
            }
            throw new Exception(implode("\n", $lines));
          }
        }
        // ===================================================================
        $pdo->beginTransaction();

        // กันซ้ำ (ยกเว้นตัวเอง)
        if ($is_all_day) {
          $chk = $pdo->prepare('SELECT id FROM activity_groups WHERE academic_year_id=? AND term_no=? AND day_of_week=? AND activity_name=? AND is_all_day=1 AND id<>? LIMIT 1');
          $chk->execute([$year_id,$term_no,$dow,$name,$id]);
        } else {
          $chk = $pdo->prepare('SELECT id FROM activity_groups WHERE academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? AND activity_name=? AND is_all_day=0 AND id<>? LIMIT 1');
          $chk->execute([$year_id,$term_no,$dow,$pno,$name,$id]);
        }
        if ($chk->fetch()) throw new Exception('ช่วงเวลาเดียวกันมีชื่อกิจกรรมนี้อยู่แล้ว');

        $up = $pdo->prepare('UPDATE activity_groups SET academic_year_id=?, term_no=?, activity_name=?, day_of_week=?, period_no=?, room_id=?, is_all_day=? WHERE id=?');
        $up->execute([$year_id,$term_no,$name,$dow,$pno,$room_id,$is_all_day,$id]);

        // อัปเดต classes
        $pdo->prepare('DELETE FROM activity_classes WHERE activity_id=?')->execute([$id]);
        $insC = $pdo->prepare('INSERT INTO activity_classes(activity_id,class_id) VALUES (?,?)');
        foreach ($class_ids as $cid){ $insC->execute([$id,$cid]); }

        // อัปเดต teachers (only if provided)
        $pdo->prepare('DELETE FROM activity_teachers WHERE activity_id=?')->execute([$id]);
        if (!empty($teacher_ids)){
          $insT = $pdo->prepare('INSERT INTO activity_teachers(activity_id,teacher_id) VALUES (?,?)');
          foreach ($teacher_ids as $tid){ $insT->execute([$id,$tid]); }
        }

        $pdo->commit();

        $oldData = $act;
        $oldData['class_ids'] = $selClasses;
        $oldData['teacher_ids'] = $selTeachers;
        $newData = $act;
        $newData['academic_year_id'] = $year_id;
        $newData['term_no'] = $term_no;
        $newData['activity_name'] = $name;
        $newData['day_of_week'] = $dow;
        $newData['period_no'] = $pno;
        $newData['room_id'] = $room_id;
        $newData['class_ids'] = $class_ids;
        $newData['teacher_ids'] = $teacher_ids;
        logUpdate('activity_groups', $id, $oldData, $newData);
        flash_set('success','อัปเดตสำเร็จ');
        redirect('activities.php?year_id='.$year_id.'&term_no='.$term_no);
      }catch(Throwable $e){
        $pdo->rollBack(); $err='ผิดพลาด: '.$e->getMessage();
      }
    }
    } // close canEdit else
  } // close csrf else
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-3xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขวิชากิจกรรม</h1>
  <?php if($err): ?><div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ปีการศึกษา</label>
        <select name="academic_year_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <?php foreach($years as $y): ?>
            <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id']===(int)$formYearId?'selected':''; ?>>
              <?= htmlspecialchars($y['year_label']).($y['is_active']?' (ใช้งาน)':''); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">เทอม</label>
        <select name="term_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <?php foreach ($termOptions as $t): ?>
            <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$formTermNo) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อกิจกรรม</label>
      <input name="activity_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['activity_name'] ?? $act['activity_name']); ?>">
    </div>

    <!-- ✅ Checkbox for all-day activity -->
    <div>
      <label class="flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-50 border border-blue-200 cursor-pointer w-fit">
        <input 
          type="checkbox" 
          name="is_all_day" 
          value="1" 
          id="is-all-day-checkbox"
          class="w-4 h-4"
          <?= ((int)($_POST['is_all_day'] ?? $act['is_all_day']) === 1) ? 'checked' : ''; ?>
        >
        <span class="text-sm font-medium text-blue-900">กิจกรรมทั้งวัน (เรียนตลอดวัน ข้ามคาบพัก)</span>
      </label>
      <p class="text-xs text-slate-500 mt-1">💡 ติ๊กเลือกหากกิจกรรมนี้ใช้เวลาตลอดวันเช่น Track, ฝึกอาชีพ ฯลฯ</p>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">วัน</label>
        <select name="day_of_week" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <?php foreach (th_dow_opts() as $k=>$v): ?>
            <option value="<?= (int)$k; ?>" <?= ((int)($_POST['day_of_week'] ?? $act['day_of_week'])===$k)?'selected':''; ?>><?= $v; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="period-field">
        <label class="block text-sm font-medium text-slate-700 mb-1.5">คาบ <span class="text-red-500">*</span></label>
        <select name="period_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach($periods as $p): ?>
            <option value="<?= (int)$p['period_no']; ?>" <?= ((int)($_POST['period_no'] ?? $act['period_no'])===(int)$p['period_no'])?'selected':''; ?>>
              คาบ <?= (int)$p['period_no']; ?> (<?= substr($p['start_time'],0,5); ?>–<?= substr($p['end_time'],0,5); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">สถานที่รวม (ถ้ามี)</label>
        <select name="room_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="">— ไม่กำหนด —</option>
          <?php foreach($rooms as $r): ?>
            <option value="<?= (int)$r['id']; ?>" <?= ((string)($_POST['room_id'] ?? $act['room_id']) !== '' && (int)$r['id']===(int)($_POST['room_id'] ?? $act['room_id']))?'selected':''; ?>>
              <?= htmlspecialchars($r['room_code'].' - '.$r['room_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชั้น/ห้องที่ร่วม (เลือกหลายรายการ)</label>

      <input 
        type="text" 
        id="class-search" 
        class="w-full border-2 rounded-lg px-3 py-2 mb-2" 
        placeholder="🔍 พิมพ์ชื่อชั้น/ห้องเพื่อค้นหา เช่น ม.1/1"
      >

      <div class="flex gap-2 text-sm text-slate-600 mb-2">
        <button type="button" id="class-select-visible" class="px-2 py-1 rounded border">เลือกทั้งหมดที่แสดง</button>
        <button type="button" id="class-clear-all" class="px-2 py-1 rounded border">ล้างที่เลือก</button>
        <span class="ml-auto text-xs text-slate-500">เลือกแล้ว <span id="class-count">0</span> ห้อง</span>
      </div>

      <div id="class-list" class="border-2 rounded-lg p-2 max-h-64 overflow-auto space-y-1">
        <?php 
          $postedClassIds = array_map('intval',(array)($_POST['class_ids'] ?? $selClasses));
          foreach($classes as $c):
            $cid = (int)$c['id'];
            $cname = (string)$c['class_name'];
            $checked = in_array($cid, $postedClassIds, true) ? 'checked' : '';
        ?>
          <label class="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-50 class-item" 
                 data-search="<?= htmlspecialchars($lc($cname)); ?>">
            <input type="checkbox" name="class_ids[]" value="<?= $cid; ?>" <?= $checked; ?>>
            <span class="text-sm"><?= htmlspecialchars($cname); ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <p id="class-error" class="hidden text-xs text-rose-600 mt-1">กรุณาเลือกชั้น/ห้องอย่างน้อย 1 รายการ</p>
      <p class="text-xs text-slate-500 mt-1">💡 ติ๊กเลือกได้หลายห้อง ไม่ต้องกด Ctrl/Cmd | ใช้ช่องค้นหาเพื่อกรองรายการ</p>
    </div>

    <!-- ✅ ครู - แบบเช็กบ็อกซ์ + ค้นหา (ลอกจากหน้า create) -->
    <div id="teacher-field">
      <label class="block text-sm font-semibold text-slate-700 mb-1.5">ครูผู้สอน (เลือกได้หลายคน) <span class="text-red-500">*</span></label>

      <!-- ช่องค้นหา -->
      <input 
        type="text" 
        id="teacher-search" 
        class="w-full border-2 rounded-lg px-3 py-2 mb-2" 
        placeholder="🔍 พิมพ์รหัสครู หรือชื่อครูเพื่อค้นหา..."
      >

      <!-- ปุ่มช่วยเลือก -->
      <div class="flex gap-2 text-sm text-slate-600 mb-2">
        <button type="button" id="select-visible" class="px-2 py-1 rounded border">เลือกทั้งหมดที่แสดง</button>
        <button type="button" id="clear-all" class="px-2 py-1 rounded border">ล้างที่เลือก</button>
      </div>

      <!-- รายการครูแบบเช็กบ็อกซ์ -->
      <div id="teacher-list" class="border-2 rounded-lg p-2 max-h-64 overflow-auto space-y-1">
        <?php 
          $postedTeacherIds = array_map('intval',(array)($_POST['teacher_ids'] ?? $selTeachers));
          foreach($teachers as $t): 
            $checked = in_array((int)$t['id'], $postedTeacherIds, true) ? 'checked' : '';
            $label = trim(($t['teacher_code'] ? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name']);
        ?>
          <label class="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-50 teacher-item" 
                 data-search="<?= strtolower(($t['teacher_code'] ?? '').' '.$t['first_name'].' '.$t['last_name'].' '.$t['subject_name']); ?>">
            <input type="checkbox" name="teacher_ids[]" value="<?= (int)$t['id']; ?>" <?= $checked; ?>>
            <span class="text-sm"><?= htmlspecialchars($label); ?></span>
            <span class="ml-auto text-xs text-slate-500"><?= htmlspecialchars($t['subject_name']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <p class="text-xs text-slate-500 mt-1">
        💡 ติ๊กเลือกได้หลายคน ไม่ต้องกด Ctrl/Cmd | ใช้ช่องค้นหาเพื่อกรองรายชื่อ
      </p>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <a href="<?= url('activities.php?year_id='.(int)$act['academic_year_id'].'&term_no='.(int)$act['term_no']); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
    </div>
  </form>
</div>

<script>
// ✅ ค้นหา/เลือกชั้น (เช็กบ็อกซ์)
document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('class-search');
  const list = document.getElementById('class-list');
  if (!list) return;
  const items = Array.from(list.querySelectorAll('.class-item'));
  const btnSelectVisible = document.getElementById('class-select-visible');
  const btnClearAll = document.getElementById('class-clear-all');
  const countEl = document.getElementById('class-count');
  const errEl = document.getElementById('class-error');

  function updateCount() {
    const n = items.reduce((acc, item) => {
      const cb = item.querySelector('input[type="checkbox"]');
      return acc + (cb && cb.checked ? 1 : 0);
    }, 0);
    if (countEl) countEl.textContent = String(n);
    if (errEl) errEl.classList.toggle('hidden', n > 0);
    return n;
  }

  function filter() {
    const kw = (searchInput.value || '').toLowerCase().trim();
    let visibleCount = 0;
    items.forEach(item => {
      const txt = item.dataset.search || '';
      const show = kw === '' || txt.includes(kw);
      item.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });
    if (btnSelectVisible) btnSelectVisible.disabled = visibleCount === 0;
  }

  if (searchInput) searchInput.addEventListener('input', filter);
  items.forEach(item => {
    const cb = item.querySelector('input[type="checkbox"]');
    if (cb) cb.addEventListener('change', updateCount);
  });

  if (btnSelectVisible) {
    btnSelectVisible.addEventListener('click', function() {
      items.forEach(item => {
        if (item.style.display !== 'none') {
          const cb = item.querySelector('input[type="checkbox"]');
          if (cb) cb.checked = true;
        }
      });
      updateCount();
    });
  }

  if (btnClearAll) {
    btnClearAll.addEventListener('click', function() {
      items.forEach(item => {
        const cb = item.querySelector('input[type="checkbox"]');
        if (cb) cb.checked = false;
      });
      updateCount();
    });
  }

  const form = list.closest('form');
  if (form) {
    form.addEventListener('submit', function(e) {
      const n = updateCount();
      if (n <= 0) {
        e.preventDefault();
        if (errEl) errEl.classList.remove('hidden');
        list.scrollIntoView({behavior: 'smooth', block: 'nearest'});
      }
    });
  }

  filter();
  updateCount();
});
</script>

<!-- ✅ JavaScript ค้นหา/เลือกครู (ลอกจากหน้า create) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('teacher-search');
  const list = document.getElementById('teacher-list');
  const items = Array.from(list.querySelectorAll('.teacher-item'));
  const btnSelectVisible = document.getElementById('select-visible');
  const btnClearAll = document.getElementById('clear-all');

  function filter() {
    const kw = (searchInput.value || '').toLowerCase().trim();
    let visibleCount = 0;
    items.forEach(item => {
      const txt = item.dataset.search || '';
      const show = kw === '' || txt.includes(kw);
      item.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });
    btnSelectVisible.disabled = visibleCount === 0;
  }

  searchInput.addEventListener('input', filter);

  // Enter = ติ๊ก/ยกเลิกตัวแรกที่แสดง
  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const firstVisible = items.find(it => it.style.display !== 'none');
      if (firstVisible) {
        const cb = firstVisible.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
      }
    }
  });

  btnSelectVisible.addEventListener('click', function() {
    items.forEach(item => {
      if (item.style.display !== 'none') {
        const cb = item.querySelector('input[type="checkbox"]');
        cb.checked = true;
      }
    });
  });

  btnClearAll.addEventListener('click', function() {
    items.forEach(item => {
      const cb = item.querySelector('input[type="checkbox"]');
      cb.checked = false;
    });
  });

  filter();
});

// ✅ Handle all-day activity checkbox - show/hide period and make teachers optional
document.addEventListener('DOMContentLoaded', function() {
  const checkbox = document.getElementById('is-all-day-checkbox');
  const periodField = document.getElementById('period-field');
  const teacherField = document.getElementById('teacher-field');
  const periodSelect = document.querySelector('select[name="period_no"]');
  
  function updateFieldsDisplay() {
    const isAllDay = checkbox.checked;
    if (isAllDay) {
      periodField.style.display = 'none';
      periodSelect.removeAttribute('required');
      teacherField.querySelector('label').innerHTML = 'ครูผู้อำนวยการ (ถ้ามี) <span class="text-slate-500 text-xs font-normal">- ไม่จำเป็นต้องใส่ครู</span>';
    } else {
      periodField.style.display = 'block';
      periodSelect.setAttribute('required', 'required');
      teacherField.querySelector('label').innerHTML = 'ครูผู้สอน (เลือกได้หลายคน) <span class="text-red-500">*</span>';
    }
  }

  checkbox.addEventListener('change', updateFieldsDisplay);
  updateFieldsDisplay(); // Initial state
});
</script>

<!-- ── AJAX conflict check before submit ────────────────────────── -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('form[method="post"]');
  if (!form) return;
  let submitAllowed = false;

  form.addEventListener('submit', async function(e) {
    if (submitAllowed) return;
    e.preventDefault();

    const fd = new FormData(form);
    fd.append('check_only', '1');

    try {
      const res  = await fetch(window.location.href, { method: 'POST', body: fd });
      const data = await res.json();

      if (data.ok) {
        submitAllowed = true;
        form.submit();
      } else {
        const html = data.conflicts.map(cf => {
          const icon   = cf.ctype === 'คาบสอน' ? '📚' : '🎯';
          const detail = cf.ctype === 'คาบสอน'
            ? `ติดคาบสอน <strong>${cf.detail}</strong>`
            : `ติดกิจกรรม "<strong>${cf.detail}</strong>"`;
          return `<li class="flex gap-1.5 items-start text-left">${icon} <span><strong>${cf.name}</strong> — ${detail}</span></li>`;
        }).join('');
        Swal.fire({
          icon: 'error',
          title: '⚠️ ครูมีตารางซ้อน',
          html: '<p class="text-sm text-slate-600 mb-2">ครูต่อไปนี้มีตารางซ้อนในวัน/คาบที่เลือก:</p>' +
                '<ul class="text-sm space-y-1.5 text-left mt-2">' + html + '</ul>',
          confirmButtonText: 'ตกลง',
          confirmButtonColor: '#dc2626',
        });
      }
    } catch (_) {
      submitAllowed = true;
      form.submit();
    }
  });
});
</script>
<!-- ─────────────────────────────────────────────────────────────── -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
