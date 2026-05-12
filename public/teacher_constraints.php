<?php
// public/teacher_constraints.php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireAdmin();

// ===== helpers ในหน้านี้ =====
function th_dow($n){
  static $m=[1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];
  return $m[(int)$n] ?? '-';
}

// ===== ดึงข้อมูลพื้นฐาน =====
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

// ดึงครูพร้อม subject_group
$teachersRaw = $pdo->query('
  SELECT id, teacher_code, first_name, last_name, subject_group 
  FROM teachers 
  ORDER BY 
    CASE WHEN subject_group IS NULL THEN 1 ELSE 0 END,
    subject_group, 
    teacher_code, 
    first_name, 
    last_name
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

// period_slots: ใช้กำหนดจำนวนคาบ และเวลาแสดงหัวตาราง
$periods = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll();
if (!$periods) {
  flash_set('error', 'ยังไม่ได้กำหนดคาบเรียนในเมนู "คาบเรียน"'); redirect('periods.php');
}

// ===== รับพารามิเตอร์เลือกชุด =====
// ใช้ปี Active เป็นค่าเริ่มต้น
$year_id = (int)($_GET['year_id'] ?? $activeYearId);

$termOptions = tt_terms_list($pdo, $year_id);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);

$teacher_id = (int)($_GET['teacher_id'] ?? ($teachers[0]['id'] ?? 0));

// ===== การกระทำ (POST) =====
$err = ''; $did_clear = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'save';
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $year_id = (int)($_POST['year_id'] ?? 0);
    $term_no = (int)($_POST['term_no'] ?? 1);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);

    if ($year_id > 0) $term_no = tt_validate_term_no($pdo, $year_id, $term_no);

    try {
      if ($action === 'clear_all') {
        // ลบข้อจำกัดของครูทุกคน เฉพาะปี/เทอมที่เลือก
        if (!$year_id || !$term_no) throw new Exception('กรุณาเลือก ปี/เทอม ให้ครบ');
        $stmt = $pdo->prepare('DELETE FROM teacher_constraints WHERE academic_year_id=? AND term_no=?');
        $stmt->execute([$year_id, $term_no]);
        $deleted = $stmt->rowCount();
        flash_set('success', "ลบข้อจำกัดทั้งหมดของครูในปี/เทอมนี้แล้ว ({$deleted} รายการ)");
        redirect('teacher_constraints.php?year_id='.$year_id.'&term_no='.$term_no.'&teacher_id='.$teacher_id);
      } elseif ($action === 'clear') {
        if (!$year_id || !$term_no || !$teacher_id) throw new Exception('กรุณาเลือก ปี/เทอม/ครู ให้ครบ');
        $del = $pdo->prepare('DELETE FROM teacher_constraints WHERE teacher_id=? AND academic_year_id=? AND term_no=?');
        $del->execute([$teacher_id, $year_id, $term_no]);
        flash_set('success', 'ล้างข้อจำกัดทั้งหมดของครูชุดที่เลือกแล้ว');
        redirect('teacher_constraints.php?year_id='.$year_id.'&term_no='.$term_no.'&teacher_id='.$teacher_id);
      } else {
        if (!$year_id || !$term_no || !$teacher_id) throw new Exception('กรุณาเลือก ปี/เทอม/ครู ให้ครบ');
        // save: ลบของเดิมก่อน แล้วใส่ใหม่ตามที่ติ๊กไว้
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM teacher_constraints WHERE teacher_id=? AND academic_year_id=? AND term_no=?');
        $del->execute([$teacher_id, $year_id, $term_no]);

        // โครงสร้างโพสต์: constraints[day][period] = "on", reasons[day][period] = "ข้อความ"
        $constraints = (array)($_POST['constraints'] ?? []);
        $reasons = (array)($_POST['reasons'] ?? []);

        $ins = $pdo->prepare('INSERT INTO teacher_constraints(teacher_id, academic_year_id, term_no, day_of_week, period_no, reason) VALUES (?,?,?,?,?,?)');

        $count = 0;
        foreach ($constraints as $day => $periodMap) {
          $day = (int)$day;
          if ($day < 1 || $day > 5) continue; // จันทร์-ศุกร์
          foreach ((array)$periodMap as $pno => $on) {
            $pno = (int)$pno;
            if ($pno <= 0) continue;
            $reason = $reasons[$day][$pno] ?? '';
            $reason = trim((string)$reason);
            $ins->execute([$teacher_id, $year_id, $term_no, $day, $pno, $reason !== '' ? $reason : null]);
            $count++;
          }
        }
        $pdo->commit();
        flash_set('success', "บันทึกข้อจำกัดสำเร็จ ({$count} ช่อง)");
        redirect('teacher_constraints.php?year_id='.$year_id.'&term_no='.$term_no.'&teacher_id='.$teacher_id);
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = 'ผิดพลาด: '.$e->getMessage();
    }
  }
}

// ===== โหลดข้อจำกัดเดิมของชุดที่เลือก =====
$stmt = $pdo->prepare('SELECT day_of_week, period_no, COALESCE(reason, "") AS reason FROM teacher_constraints WHERE teacher_id=? AND academic_year_id=? AND term_no=?');
$stmt->execute([$teacher_id, $year_id, $term_no]);
$existing = $stmt->fetchAll();

$selected = []; // $selected[day][period] = true
$reasonMap = []; // $reasonMap[day][period] = 'ข้อความ'
foreach ($existing as $r) {
  $d = (int)$r['day_of_week'];
  $p = (int)$r['period_no'];
  $selected[$d][$p] = true;
  if ((string)$r['reason'] !== '') $reasonMap[$d][$p] = $r['reason'];
}

$flash = flash_get();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">ข้อจำกัดการสอนของครู (สอนไม่ได้)</h1>
    <div class="flex items-center gap-2">
      <div class="text-sm text-slate-500">กำหนดตาม ปีการศึกษา / เทอม / ครู</div>
      <button type="button" id="btnClearAll" class="px-3 py-2 rounded-xl border border-rose-600 text-rose-600 hover:bg-rose-50 text-sm">
        🗑️ ลบข้อจำกัดทั้งหมด (ปี/เทอมนี้)
      </button>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
  <?php endif; ?>

  <!-- ตัวเลือกชุด ปี/เทอม/ครู -->
  <form method="get" id="filterForm" class="bg-white rounded-2xl shadow p-4 mb-6 grid grid-cols-1 md:grid-cols-4 gap-3">
    <div>
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" onchange="document.getElementById('filterForm').submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>>
            <?= htmlspecialchars($y['year_label']).($y['is_active']?' 🟢':''); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" onchange="document.getElementById('filterForm').submit()">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$term_no) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-2">
      <label class="block text-xs mb-1">ครู (จัดกลุ่มตามกลุ่มสาระ)</label>
      <select name="teacher_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" onchange="document.getElementById('filterForm').submit()">
        <?php 
        $currentSubject = '';
        foreach ($teachers as $t): 
          // แสดง optgroup เมื่อเปลี่ยนกลุ่มสาระ
          if ($currentSubject !== $t['subject_name']) {
            if ($currentSubject !== '') echo '</optgroup>';
            $currentSubject = $t['subject_name'];
            echo '<optgroup label="'.htmlspecialchars($currentSubject).'">';
          }
        ?>
          <option value="<?= (int)$t['id']; ?>" <?= (int)$t['id']===$teacher_id?'selected':''; ?>>
            <?= htmlspecialchars(($t['teacher_code'] ? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name']); ?>
          </option>
        <?php endforeach; ?>
        <?php if ($currentSubject !== '') echo '</optgroup>'; ?>
      </select>
    </div>
  </form>

  <!-- ตารางเมทริกซ์ จันทร์-ศุกร์ x คาบ -->
  <form method="post" class="bg-white rounded-2xl shadow p-4 overflow-x-auto">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
    <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
    <input type="hidden" name="teacher_id" value="<?= (int)$teacher_id; ?>">

    <table class="min-w-full text-sm">
      <thead>
        <tr>
          <th class="text-left px-4 py-3">วัน \ คาบ</th>
          <?php foreach ($periods as $p): ?>
            <th class="text-center px-2 py-3">
              คาบ <?= (int)$p['period_no']; ?><br>
              <span class="text-xs text-slate-500"><?= substr($p['start_time'],0,5); ?>–<?= substr($p['end_time'],0,5); ?></span>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php for ($day=1; $day<=5; $day++): ?>
          <tr class="border-t align-top">
            <td class="px-4 py-3 font-medium"><?= th_dow($day); ?></td>
            <?php foreach ($periods as $p):
              $pno = (int)$p['period_no'];
              $checked = !empty($selected[$day][$pno]);
              $reasonVal = $reasonMap[$day][$pno] ?? '';
            ?>
              <td class="px-2 py-2">
                <label class="inline-flex items-center gap-2">
                  <input type="checkbox" name="constraints[<?= (int)$day; ?>][<?= (int)$pno; ?>]" <?= $checked?'checked':''; ?>>
                  <span>สอนไม่ได้</span>
                </label>
                <input type="text" name="reasons[<?= (int)$day; ?>][<?= (int)$pno; ?>]"
                       class="mt-1 w-full border rounded-lg px-2 py-1 text-xs"
                       placeholder="เหตุผล (ตัวเลือก)"
                       value="<?= htmlspecialchars($reasonVal); ?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <div class="flex items-center gap-2 mt-4">
      <button name="action" value="save" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <button type="button" id="btnClearSingle" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ล้างทั้งหมด</button>
      <a href="<?= url('loads.php?year_id='.(int)$year_id.'&term_no='.(int)$term_no); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">กลับไปกำลังสอน</a>
    </div>

    <p class="text-xs text-slate-500 mt-3">
      หมายเหตุ: ระบบจะใช้ข้อมูลนี้เพื่อหลีกเลี่ยงการจัดคาบให้ครูในช่วงเวลาที่ไม่สะดวก/สอนไม่ได้
    </p>
  </form>

  <!-- ฟอร์มซ่อนสำหรับลบข้อจำกัดครูคนเดียว -->
  <form id="clearSingleForm" method="post" style="display:none;">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <input type="hidden" name="action" value="clear">
    <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
    <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
    <input type="hidden" name="teacher_id" value="<?= (int)$teacher_id; ?>">
  </form>

  <!-- ฟอร์มซ่อนสำหรับลบทั้งหมด -->
  <form id="clearAllForm" method="post" style="display:none;">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <input type="hidden" name="action" value="clear_all">
    <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
    <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
    <input type="hidden" name="teacher_id" value="<?= (int)$teacher_id; ?>">
  </form>
</div>

<!-- ✅ Custom Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
    <div class="bg-rose-600 px-6 py-4">
      <h3 class="text-white font-semibold text-lg flex items-center gap-2">
        <span class="text-2xl">⚠️</span>
        ยืนยันการลบข้อมูล
      </h3>
    </div>
    <div class="p-6">
      <p class="text-slate-700 mb-4" id="modalMessage"></p>
      <p class="text-sm text-slate-500 bg-slate-50 p-3 rounded-lg" id="modalWarning"></p>
    </div>
    <div class="flex gap-3 px-6 pb-6">
      <button id="modalCancel" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 transition text-sm font-medium">
        ยกเลิก
      </button>
      <button id="modalConfirm" class="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 text-white hover:bg-rose-700 transition text-sm font-medium">
        ยืนยันการลบ
      </button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const btnClearAll = document.getElementById('btnClearAll');
  const btnClearSingle = document.getElementById('btnClearSingle');
  const clearAllForm = document.getElementById('clearAllForm');
  const clearSingleForm = document.getElementById('clearSingleForm');
  const deleteModal = document.getElementById('deleteModal');
  const modalCancel = document.getElementById('modalCancel');
  const modalConfirm = document.getElementById('modalConfirm');
  const modalMessage = document.getElementById('modalMessage');
  const modalWarning = document.getElementById('modalWarning');
  
  let confirmStep = 0;
  let currentAction = null; // 'all' หรือ 'single'
  
  // ✅ ฟังก์ชันเปิด Modal
  function openModal(action) {
    currentAction = action;
    confirmStep = 0;
    
    if (action === 'all') {
      modalMessage.textContent = 'คุณแน่ใจหรือไม่ที่จะลบข้อจำกัดของครูทุกคน สำหรับปีการศึกษาและเทอมที่เลือก?';
      modalWarning.innerHTML = '<strong>หมายเหตุ:</strong> การกระทำนี้จะลบข้อมูลของครูทุกคนในปี/เทอมนี้';
    } else {
      modalMessage.textContent = 'คุณแน่ใจหรือไม่ที่จะล้างข้อจำกัดทั้งหมดของครูที่เลือก?';
      modalWarning.innerHTML = '<strong>หมายเหตุ:</strong> การกระทำนี้จะลบข้อจำกัดทั้งหมดของครู/ปี/เทอมนี้';
    }
    
    modalConfirm.textContent = 'ยืนยันการลบ';
    modalConfirm.classList.remove('bg-rose-700');
    modalConfirm.classList.add('bg-rose-600');
    deleteModal.classList.remove('hidden');
  }
  
  // ✅ ปุ่มลบทั้งหมด (ทุกครู)
  if (btnClearAll && clearAllForm) {
    btnClearAll.addEventListener('click', function () {
      openModal('all');
    });
  }
  
  // ✅ ปุ่มล้างทั้งหมด (ครูคนเดียว)
  if (btnClearSingle && clearSingleForm) {
    btnClearSingle.addEventListener('click', function () {
      openModal('single');
    });
  }
  
  // ✅ ปุ่มยกเลิก
  modalCancel.addEventListener('click', () => {
    deleteModal.classList.add('hidden');
    confirmStep = 0;
    currentAction = null;
  });
  
  // ✅ ปุ่มยืนยัน (2 ขั้นตอน)
  modalConfirm.addEventListener('click', () => {
    if (confirmStep === 0) {
      // ขั้นที่ 1: แสดงการยืนยันครั้งที่ 2
      confirmStep = 1;
      modalMessage.textContent = '❗ ยืนยันอีกครั้ง: การกระทำนี้ไม่สามารถกู้คืนได้!';
      modalWarning.innerHTML = '<strong class="text-rose-600">คำเตือน:</strong> ข้อมูลจะถูกลบถาวรและไม่สามารถกู้คืนได้';
      modalConfirm.textContent = 'ยืนยันการลบอีกครั้ง';
      modalConfirm.classList.remove('bg-rose-600');
      modalConfirm.classList.add('bg-rose-700');
    } else {
      // ขั้นที่ 2: ส่งฟอร์ม
      deleteModal.classList.add('hidden');
      if (currentAction === 'all') {
        clearAllForm.submit();
      } else if (currentAction === 'single') {
        clearSingleForm.submit();
      }
    }
  });
  
  // ✅ ปิด modal เมื่อคลิกพื้นหลัง
  deleteModal.addEventListener('click', (e) => {
    if (e.target === deleteModal) {
      deleteModal.classList.add('hidden');
      confirmStep = 0;
      currentAction = null;
    }
  });
});
</script>
