<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

// ✅ จัดการการลบทั้งหมด
$flash = flash_get();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    try {
      $pdo->beginTransaction();
      
      // ลบข้อมูลที่เกี่ยวข้อง
      $pdo->exec("DELETE FROM class_teachers"); // ลบครูประจำชั้น
      $pdo->exec("DELETE FROM classes");        // ลบชั้นเรียน
      
      $pdo->commit();
      flash_set('success', 'ลบชั้นเรียนทั้งหมดเรียบร้อย');
      redirect('classes.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'เกิดข้อผิดพลาด: '.$e->getMessage());
    }
  }
}

// ✅ เพิ่มห้องเดี่ยว
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_single') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    $grade = trim($_POST['grade_label'] ?? '');
    $section = (int)($_POST['section_no'] ?? 0);
    if ($grade === '' || $section < 1) {
      flash_set('error', 'กรอกชื่อชั้นและหมายเลขห้องให้ถูกต้อง');
    } else {
      try {
        $name = $grade . '/' . $section;
        $stmt = $pdo->prepare('INSERT INTO classes(grade_label, section_no, class_name) VALUES (?,?,?)');
        $stmt->execute([$grade, $section, $name]);
        flash_set('success', "เพิ่ม {$name} เรียบร้อย");
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          flash_set('error', "ห้อง {$grade}/{$section} มีอยู่แล้ว");
        } else {
          flash_set('error', 'เกิดข้อผิดพลาด: '.$e->getMessage());
        }
      }
    }
  }
  redirect('classes.php');
}

/** ดึงรายการชั้นเรียน: เรียงตาม grade_label แล้วต่อด้วย section_no */
$sql = <<<SQL
SELECT
  c.id, c.grade_label, c.section_no, c.class_name, c.class_alias,
  c.homeroom_room_id, c.created_at, r.room_name
FROM classes c
LEFT JOIN rooms r ON r.id = c.homeroom_room_id
ORDER BY
  FIELD(LEFT(c.grade_label, 1), 'ต','อ','ป','ม'),
  c.grade_label ASC,
  c.section_no ASC
SQL;

$stmt = $pdo->query($sql);
$classes = $stmt->fetchAll();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">ชั้นเรียน</h1>
    <div class="flex gap-2">
      <button type="button" id="btnAddSingle" class="px-3 py-2 rounded-xl border border-slate-700 text-slate-700 hover:bg-slate-50 text-sm">+ เพิ่มห้องเดี่ยว</button>
      <a href="<?= url('class_create.php'); ?>" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">+ สร้างชั้น/ห้องแบบรวดเร็ว</a>
      <button type="button" id="btnDeleteAll" class="px-3 py-2 rounded-xl border border-rose-600 text-rose-600 hover:bg-rose-50 text-sm">
        🗑️ ลบชั้นทั้งหมด
      </button>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <div class="overflow-x-auto bg-white rounded-2xl shadow">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr>
          <th class="text-left px-4 py-3">ชั้น/ห้อง</th>
          <th class="text-left px-4 py-3">ชื่อห้อง (Alias)</th>
          <th class="text-left px-4 py-3">ห้องเรียนประจำ</th>
          <th class="text-left px-4 py-3">ครูประจำชั้น (สูงสุด 4)</th>
          <th class="text-left px-4 py-3">สร้างเมื่อ</th>
          <th class="text-right px-4 py-3">การทำงาน</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classes as $c): ?>
          <?php
            // ดึงครูของห้องนี้ (ชื่อสั้น ๆ)
            $stmtT = $pdo->prepare('
              SELECT t.first_name, t.last_name FROM class_teachers ct
              JOIN teachers t ON t.id = ct.teacher_id
              WHERE ct.class_id = ?
              ORDER BY t.first_name, t.last_name
            ');
            $stmtT->execute([$c['id']]);
            $ts = $stmtT->fetchAll();
          ?>
          <tr class="border-t">
            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($c['class_name']); ?></td>
            <td class="px-4 py-3">
              <?php if (!empty($c['class_alias'])): ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 text-xs font-medium"><?= htmlspecialchars($c['class_alias']); ?></span>
              <?php else: ?>
                <span class="text-slate-300 text-xs">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3"><?= htmlspecialchars($c['room_name'] ?? '—'); ?></td>
            <td class="px-4 py-3">
              <?php if ($ts): ?>
                <div class="flex flex-wrap gap-1">
                  <?php foreach ($ts as $t): ?>
                    <span class="inline-flex px-2 py-0.5 rounded bg-slate-100"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-slate-400">ยังไม่ตั้งครู</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3"><?= th_date($c['created_at']); ?></td>
            <td class="px-4 py-3 text-right">
              <a class="text-slate-700 hover:underline mr-3" href="<?= url('class_edit.php?id='.(int)$c['id']); ?>">แก้ไข</a>
              <form action="<?= url('class_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text: <?= json_encode('ยืนยันลบ '.$c['class_name'].' ?', JSON_UNESCAPED_UNICODE); ?>, confirmButtonText:'ลบ'});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                <button class="text-rose-600 hover:underline">ลบ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$classes): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">ยังไม่มีชั้นเรียน</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ Hidden Form สำหรับลบทั้งหมด -->
<form id="deleteAllForm" method="post" style="display:none;">
  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
  <input type="hidden" name="action" value="delete_all">
</form>

<!-- ✅ Modal เพิ่มห้องเดี่ยว -->
<div id="modalAddSingle" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-sm mx-4">
    <h2 class="text-lg font-semibold mb-4">เพิ่มห้องเดี่ยว</h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="add_single">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อชั้น</label>
        <input name="grade_label" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required placeholder="เช่น อ2, ป1, ม.1">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">หมายเลขห้อง</label>
        <input type="number" name="section_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" min="1" max="99" required placeholder="เช่น 3">
      </div>
      <p class="text-xs text-slate-500">ตัวอย่าง: ชั้น อ2 ห้อง 3 → อ2/3</p>
      <div class="flex items-center gap-2 pt-1">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm hover:opacity-90">เพิ่ม</button>
        <button type="button" id="btnCloseModal" class="px-4 py-2 rounded-xl border text-sm">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
// ✅ ปุ่มลบห้องทั้งหมด
const btnDeleteAll = document.getElementById('btnDeleteAll');
const deleteAllForm = document.getElementById('deleteAllForm');

// ✅ Modal เพิ่มห้องเดี่ยว
const modal = document.getElementById('modalAddSingle');
document.getElementById('btnAddSingle').addEventListener('click', () => {
  modal.classList.remove('hidden');
  modal.querySelector('[name="grade_label"]').focus();
});
document.getElementById('btnCloseModal').addEventListener('click', () => modal.classList.add('hidden'));
modal.addEventListener('click', e => { if (e.target === modal) modal.classList.add('hidden'); });

btnDeleteAll.addEventListener('click', () => {
  ttDoubleConfirmSubmit(
    deleteAllForm,
    { title: 'ลบชั้นเรียนทั้งหมด', text: '⚠️ คุณแน่ใจหรือไม่?\n\nจะลบชั้นเรียนทั้งหมด (รวมครูประจำชั้น)', confirmButtonText: 'ดำเนินการต่อ' },
    { title: 'ยืนยันอีกครั้ง', text: '❗ การกระทำนี้ไม่สามารถกู้คืนได้!', confirmButtonText: 'ลบทั้งหมด' }
  );
});
</script>
