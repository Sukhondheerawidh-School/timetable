<?php
// filepath: c:\xampp\htdocs\timetable\public\class_weekends.php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$msg = '';

// บันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $msg = '<div class="bg-red-50 text-red-700 px-4 py-3 rounded-lg">CSRF token ไม่ถูกต้อง</div>';
  } else {
    try {
      $pdo->beginTransaction();
      
      // Reset ทั้งหมดก่อน
      $pdo->exec('UPDATE classes SET has_saturday = 0, has_sunday = 0');
      
      // อัปเดตตามที่เลือก
      $saturday = $_POST['saturday'] ?? [];
      $sunday = $_POST['sunday'] ?? [];
      
      if (!empty($saturday)) {
        $ids = array_map('intval', $saturday);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE classes SET has_saturday = 1 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
      }
      
      if (!empty($sunday)) {
        $ids = array_map('intval', $sunday);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE classes SET has_sunday = 1 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
      }
      
      $pdo->commit();
      $msg = '<div class="bg-green-50 text-green-700 px-4 py-3 rounded-lg">✅ บันทึกสำเร็จ</div>';
    } catch (Exception $e) {
      $pdo->rollBack();
      $msg = '<div class="bg-red-50 text-red-700 px-4 py-3 rounded-lg">เกิดข้อผิดพลาด: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
  }
}

// ดึงข้อมูลชั้นเรียนทั้งหมด
$classes = $pdo->query('SELECT id, class_name, grade_label, has_saturday, has_sunday FROM classes ORDER BY class_name')->fetchAll();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-4xl mx-auto px-4 py-6">
  <div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">📅 กำหนดชั้นเรียนเสาร์-อาทิตย์</h1>
    <p class="text-sm text-slate-600 mt-1">เลือกชั้นที่มีเรียนวันเสาร์และอาทิตย์</p>
  </div>

  <?php if ($msg): ?>
    <div class="mb-4"><?= $msg ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow-sm p-6">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b">
              <th class="text-left py-3 px-4">ชั้นเรียน</th>
              <th class="text-center py-3 px-4 w-32">
                <div class="flex items-center justify-center gap-2">
                  <input type="checkbox" id="check-all-sat" class="rounded">
                  <label for="check-all-sat">เสาร์</label>
                </div>
              </th>
              <th class="text-center py-3 px-4 w-32">
                <div class="flex items-center justify-center gap-2">
                  <input type="checkbox" id="check-all-sun" class="rounded">
                  <label for="check-all-sun">อาทิตย์</label>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classes as $class): ?>
            <tr class="border-b hover:bg-slate-50">
              <td class="py-3 px-4">
                <div class="font-medium"><?= htmlspecialchars($class['class_name']) ?></div>
                <div class="text-sm text-slate-500"><?= htmlspecialchars($class['grade_label']) ?></div>
              </td>
              <td class="text-center py-3 px-4">
                <input type="checkbox" 
                       name="saturday[]" 
                       value="<?= $class['id'] ?>" 
                       class="rounded saturday-check"
                       <?= $class['has_saturday'] ? 'checked' : '' ?>>
              </td>
              <td class="text-center py-3 px-4">
                <input type="checkbox" 
                       name="sunday[]" 
                       value="<?= $class['id'] ?>" 
                       class="rounded sunday-check"
                       <?= $class['has_sunday'] ? 'checked' : '' ?>>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-6 flex gap-3">
        <button type="submit" class="px-6 py-3 bg-slate-900 text-white rounded-lg hover:bg-slate-800 font-medium">
          💾 บันทึก
        </button>
        <a href="<?= url('index.php') ?>" class="px-6 py-3 border rounded-lg hover:bg-slate-50 font-medium">
          ยกเลิก
        </a>
      </div>
    </form>
  </div>
</div>

<script>
// Check all Saturday
document.getElementById('check-all-sat').addEventListener('change', function() {
  document.querySelectorAll('.saturday-check').forEach(cb => cb.checked = this.checked);
});

// Check all Sunday
document.getElementById('check-all-sun').addEventListener('change', function() {
  document.querySelectorAll('.sunday-check').forEach(cb => cb.checked = this.checked);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>