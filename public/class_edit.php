<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM classes WHERE id = ?');
$stmt->execute([$id]);
$class = $stmt->fetch();
if (!$class) { flash_set('error','ไม่พบชั้นเรียน'); redirect('classes.php'); }

/** ดึงห้องเรียนทั้งหมดเพื่อเลือกเป็นห้องประจำ */
$rooms = $pdo->query('SELECT id, room_code, room_name FROM rooms ORDER BY room_code')->fetchAll();

/** ดึงครูทั้งหมดเพื่อเลือกเป็นครูประจำ (เพิ่มรหัส/กลุ่มสาระ) */
$teachersRaw = $pdo->query('
  SELECT id, teacher_code, first_name, last_name, subject_group
  FROM teachers
  ORDER BY teacher_code, first_name, last_name
')->fetchAll();

$teachers = [];
foreach ($teachersRaw as $t) {
  $teachers[] = [
    'id' => $t['id'],
    'teacher_code' => $t['teacher_code'],
    'first_name' => $t['first_name'],
    'last_name' => $t['last_name'],
    'subject_group' => $t['subject_group'],
    'subject_name' => teacher_group_label((int)$t['subject_group']),
  ];
}

/** ดึงครูของห้องนี้ (id list) */
$stmtT = $pdo->prepare('SELECT teacher_id FROM class_teachers WHERE class_id = ?');
$stmtT->execute([$id]);
$teacher_ids = array_column($stmtT->fetchAll(), 'teacher_id');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $grade = trim($_POST['grade_label'] ?? '');
    $section = (int)($_POST['section_no'] ?? 0);
    $room_id = (int)($_POST['homeroom_room_id'] ?? 0);
    $sel_teachers = array_filter(array_map('intval', (array)($_POST['teacher_ids'] ?? [])));

    if ($grade === '' || $section < 1) {
      $err = 'กรอกชื่อชั้นและเลขห้องย่อยให้ถูกต้อง';
    } elseif (count($sel_teachers) > 4) {
      $err = 'เลือกครูได้สูงสุด 4 คน';
    } else {
      try {
        $pdo->beginTransaction();

        // อัปเดตข้อมูลชั้น
        $class_name = $grade . '/' . $section;
        $room_id = $room_id > 0 ? $room_id : null;

        // หากมีโอกาสชน uniq ให้เช็คก่อน
        $stmtChk = $pdo->prepare('SELECT id FROM classes WHERE grade_label=? AND section_no=? AND id<>? LIMIT 1');
        $stmtChk->execute([$grade, $section, $id]);
        if ($stmtChk->fetch()) throw new Exception('ชั้น/ห้องนี้มีอยู่แล้ว');

        $stmtU = $pdo->prepare('UPDATE classes SET grade_label=?, section_no=?, class_name=?, homeroom_room_id=? WHERE id=?');
        $stmtU->execute([$grade, $section, $class_name, $room_id, $id]);

        // อัปเดตครูประจำชั้น: ลบเก่า→ใส่ใหม่
        $pdo->prepare('DELETE FROM class_teachers WHERE class_id=?')->execute([$id]);
        if ($sel_teachers) {
          $stmtIns = $pdo->prepare('INSERT INTO class_teachers(class_id, teacher_id) VALUES (?, ?)');
          foreach ($sel_teachers as $tid) {
            $stmtIns->execute([$id, $tid]);
          }
        }

        $pdo->commit();
        flash_set('success','อัปเดตข้อมูลชั้นเรียนสำเร็จ');
        redirect('classes.php');
      } catch (Throwable $e) {
        $pdo->rollBack();
        $err = 'ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-3xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขชั้นเรียน</h1>

  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm mb-1">ชื่อชั้น</label>
        <input name="grade_label" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['grade_label'] ?? $class['grade_label']); ?>">
      </div>
      <div>
        <label class="block text-sm mb-1">เลขห้องย่อย</label>
        <input type="number" name="section_no" class="w-full border rounded-lg px-3 py-2" min="1" required value="<?= htmlspecialchars($_POST['section_no'] ?? $class['section_no']); ?>">
      </div>
      <div>
        <label class="block text-sm mb-1">ชั้น/ห้อง</label>
        <input class="w-full border rounded-lg px-3 py-2 bg-slate-50" disabled value="<?=
          htmlspecialchars( (($_POST['grade_label'] ?? $class['grade_label']).'/'.($_POST['section_no'] ?? $class['section_no'])) ); ?>">
      </div>
    </div>

    <div>
      <label class="block text-sm mb-1">ห้องเรียนประจำ</label>
      <select name="homeroom_room_id" class="w-full border rounded-lg px-3 py-2">
        <option value="">— ยังไม่กำหนด —</option>
        <?php
          $selRoom = $_POST['homeroom_room_id'] ?? $class['homeroom_room_id'];
          foreach ($rooms as $r):
        ?>
          <option value="<?= (int)$r['id']; ?>" <?= ((int)$selRoom === (int)$r['id'])?'selected':''; ?>>
            <?= htmlspecialchars($r['room_code'].' - '.$r['room_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-sm mb-2 font-semibold">ครูประจำชั้น (เลือกได้สูงสุด 4)</label>

      <?php $selTeachers = array_map('intval', (array)($_POST['teacher_ids'] ?? $teacher_ids)); ?>

      <!-- ช่องค้นหา -->
      <input
        type="text"
        id="teacher-search"
        class="w-full border-2 rounded-lg px-3 py-2 mb-2"
        placeholder="🔍 พิมพ์รหัสครู หรือชื่อครูเพื่อค้นหา..."
      >

      <!-- ปุ่มช่วยเลือก + ตัวนับ -->
      <div class="flex items-center justify-between mb-2">
        <div class="flex gap-2 text-sm text-slate-600">
          <button type="button" id="select-visible" class="px-2 py-1 rounded border">เลือกทั้งหมดที่แสดง</button>
          <button type="button" id="clear-all" class="px-2 py-1 rounded border">ล้างที่เลือก</button>
        </div>
        <div id="teacher-count" class="text-xs text-slate-600"></div>
      </div>

      <!-- รายการครูแบบเช็กบ็อกซ์ -->
      <div id="teacher-list" class="border-2 rounded-lg p-2 max-h-64 overflow-auto space-y-1">
        <?php foreach ($teachers as $t):
          $checked = in_array((int)$t['id'], $selTeachers, true) ? 'checked' : '';
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
        💡 ติ๊กเลือกได้ ไม่ต้องกด Ctrl/Cmd | จำกัดสูงสุด 4 คน
      </p>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('classes.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const MAX = 4;
  const searchInput = document.getElementById('teacher-search');
  const list = document.getElementById('teacher-list');
  const items = Array.from(list.querySelectorAll('.teacher-item'));
  const btnSelectVisible = document.getElementById('select-visible');
  const btnClearAll = document.getElementById('clear-all');
  const counter = document.getElementById('teacher-count');

  function countSelected() {
    return list.querySelectorAll('input[type="checkbox"]:checked').length;
  }

  function updateCounter() {
    counter.textContent = `เลือกแล้ว ${countSelected()}/${MAX}`;
  }

  function enforceLimit() {
    const selected = countSelected();
    // ปิดเช็กบ็อกซ์ที่ยังไม่ได้ติ๊กเมื่อครบโควตา
    items.forEach(item => {
      const cb = item.querySelector('input[type="checkbox"]');
      if (!cb.checked) cb.disabled = selected >= MAX;
    });
    btnSelectVisible.disabled = selected >= MAX;
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
    // อนุญาตปุ่มเลือกที่แสดงเมื่อมีรายการและยังไม่เต็มโควตา
    btnSelectVisible.disabled = visibleCount === 0 || countSelected() >= MAX;
  }

  // ติดตามการคลิกเพื่อบังคับโควตา
  list.addEventListener('change', function(e) {
    if (e.target.matches('input[type="checkbox"]')) {
      const selected = countSelected();
      if (selected > MAX) {
        // ยกเลิกเช็กตัวล่าสุดถ้าเกิน
        e.target.checked = false;
        // เอฟเฟกต์เตือนเล็กน้อย
        searchInput.classList.add('ring-2','ring-rose-400');
        setTimeout(() => searchInput.classList.remove('ring-2','ring-rose-400'), 500);
      }
      enforceLimit();
      updateCounter();
      filter();
    }
  });

  searchInput.addEventListener('input', filter);

  // Enter = ติ๊ก/ยกเลิกตัวแรกที่แสดง (เคารพโควตา)
  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const firstVisible = items.find(it => it.style.display !== 'none');
      if (firstVisible) {
        const cb = firstVisible.querySelector('input[type="checkbox"]');
        if (cb.checked) {
          cb.checked = false;
        } else if (countSelected() < MAX) {
          cb.checked = true;
        } else {
          searchInput.classList.add('ring-2','ring-rose-400');
          setTimeout(() => searchInput.classList.remove('ring-2','ring-rose-400'), 500);
        }
        // ทริกเกอร์อัปเดตสถานะ
        list.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  });

  btnSelectVisible.addEventListener('click', function() {
    let selected = countSelected();
    for (const item of items) {
      if (item.style.display === 'none') continue;
      if (selected >= MAX) break;
      const cb = item.querySelector('input[type="checkbox"]');
      if (!cb.checked) {
        cb.checked = true;
        selected++;
      }
    }
    enforceLimit();
    updateCounter();
    filter();
  });

  btnClearAll.addEventListener('click', function() {
    items.forEach(item => {
      const cb = item.querySelector('input[type="checkbox"]');
      cb.checked = false;
      cb.disabled = false;
    });
    enforceLimit();
    updateCounter();
    filter();
  });

  // เริ่มต้น
  enforceLimit();
  updateCounter();
  filter();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
