<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();

function th_dow_opts(){ return [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์']; }

$years    = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$periods  = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll();
$classes  = $pdo->query('SELECT id, class_name FROM classes ORDER BY class_name')->fetchAll();

$lc = function(string $s): string {
  return function_exists('mb_strtolower') ? (string)mb_strtolower($s, 'UTF-8') : strtolower($s);
};

// ✅ ดึงครูพร้อมรหัสครู เรียงตามรหัส
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

// ปี/เทอมที่เลือกในฟอร์ม (เพื่อให้ค่าเริ่มต้นตามเดือนทำงานถูกต้อง)
$activeYearId = 0;
foreach ($years as $y) {
  if (!empty($y['is_active'])) { $activeYearId = (int)$y['id']; break; }
}
if (!$activeYearId && $years) $activeYearId = (int)$years[0]['id'];

$selectedYearId = (int)($_POST['academic_year_id'] ?? $activeYearId);
$termOptions = tt_terms_list($pdo, $selectedYearId);
$defaultTermNo = tt_default_term_no_for_year($pdo, $selectedYearId);
$selectedTermNo = isset($_POST['term_no']) ? (int)$_POST['term_no'] : $defaultTermNo;
$selectedTermNo = tt_validate_term_no($pdo, $selectedYearId, $selectedTermNo);

$err=''; if($_SERVER['REQUEST_METHOD']==='POST'){
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    $year_id  = (int)($_POST['academic_year_id'] ?? 0);
    $term_no  = (int)($_POST['term_no'] ?? 1);
    if ($year_id > 0) $term_no = tt_validate_term_no($pdo, $year_id, $term_no);
    $name     = trim($_POST['activity_name'] ?? '');
    $dow      = (int)($_POST['day_of_week'] ?? 0);
    $pno      = (int)($_POST['period_no'] ?? 0);
    $room_id  = ($_POST['room_id'] ?? '')!=='' ? (int)$_POST['room_id'] : null;
    $class_ids = array_map('intval', (array)($_POST['class_ids'] ?? []));
    $teacher_ids = array_map('intval', (array)($_POST['teacher_ids'] ?? []));

    if(!$year_id || !$term_no || $name==='' || !$dow || !$pno || !$class_ids || !$teacher_ids){
      $err='กรอกข้อมูลให้ครบ (ปี/เทอม/ชื่อกิจกรรม/วัน/คาบ/ชั้น/ครู)';
    }else{
      try{
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO activity_groups(academic_year_id, term_no, activity_name, day_of_week, period_no, room_id) VALUES (?,?,?,?,?,?)');
        $ins->execute([$year_id,$term_no,$name,$dow,$pno,$room_id]);
        $aid = (int)$pdo->lastInsertId();

        $insC = $pdo->prepare('INSERT INTO activity_classes(activity_id,class_id) VALUES (?,?)');
        foreach ($class_ids as $cid){ $insC->execute([$aid,$cid]); }

        $insT = $pdo->prepare('INSERT INTO activity_teachers(activity_id,teacher_id) VALUES (?,?)');
        foreach ($teacher_ids as $tid){ $insT->execute([$aid,$tid]); }

        $pdo->commit();
        flash_set('success','เพิ่มกิจกรรมสำเร็จ');
        redirect('activities.php?year_id='.$year_id.'&term_no='.$term_no);
      }catch(Throwable $e){
        $pdo->rollBack();
        if (str_contains($e->getMessage(),'uniq_activity_time')) $err='ช่วงเวลาเดียวกันมีชื่อกิจกรรมนี้อยู่แล้ว';
        else $err='ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>

<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-3xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มวิชากิจกรรม (เรียนรวม)</h1>
  <?php if($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm mb-1">ปีการศึกษา</label>
        <select name="academic_year_id" class="w-full border rounded-lg px-3 py-2" required>
          <?php foreach($years as $y): ?>
            <option value="<?= (int)$y['id']; ?>" <?= ((int)$y['id'] === (int)$selectedYearId) ? 'selected' : ''; ?>>
              <?= htmlspecialchars($y['year_label']).($y['is_active']?' (ใช้งาน)':''); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm mb-1">เทอม</label>
        <select name="term_no" class="w-full border rounded-lg px-3 py-2" required>
          <?php foreach ($termOptions as $t): ?>
            <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$selectedTermNo) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-sm mb-1">ชื่อกิจกรรม</label>
      <input name="activity_name" class="w-full border rounded-lg px-3 py-2" required placeholder="เช่น ลูกเสือ / แนะแนว / ชุมนุม" value="<?= htmlspecialchars($_POST['activity_name'] ?? ''); ?>">
    </div>

    <div class="grid md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm mb-1">วัน</label>
        <select name="day_of_week" class="w-full border rounded-lg px-3 py-2" required>
          <?php foreach (th_dow_opts() as $k=>$v): ?>
            <option value="<?= (int)$k; ?>" <?= (isset($_POST['day_of_week']) && (int)$_POST['day_of_week']===$k)?'selected':''; ?>><?= $v; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm mb-1">คาบ</label>
        <select name="period_no" class="w-full border rounded-lg px-3 py-2" required>
          <?php foreach($periods as $p): ?>
            <option value="<?= (int)$p['period_no']; ?>" <?= (isset($_POST['period_no']) && (int)$_POST['period_no']===(int)$p['period_no'])?'selected':''; ?>>
              คาบ <?= (int)$p['period_no']; ?> (<?= substr($p['start_time'],0,5); ?>–<?= substr($p['end_time'],0,5); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm mb-1">สถานที่รวม (ถ้ามี)</label>
        <select name="room_id" class="w-full border rounded-lg px-3 py-2">
          <option value="">— ไม่กำหนด —</option>
          <?php foreach($rooms as $r): ?>
            <option value="<?= (int)$r['id']; ?>"><?= htmlspecialchars($r['room_code'].' - '.$r['room_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- ✅ ชั้นเรียน -->
    <div>
      <label class="block text-sm mb-1">ชั้น/ห้องที่ร่วม (เลือกหลายรายการ)</label>

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
          $postedClassIds = array_map('intval',(array)($_POST['class_ids'] ?? []));
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

    <!-- ✅ ครู - แบบเช็กบ็อกซ์ + ค้นหา -->
    <div>
      <label class="block text-sm mb-1 font-semibold">ครูผู้สอน (เลือกได้หลายคน)</label>

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
          $postedTeacherIds = array_map('intval',(array)($_POST['teacher_ids'] ?? []));
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
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('activities.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
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

// ✅ ค้นหา/เลือกครู (เช็กบ็อกซ์)
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
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
