<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $grade = trim($_POST['grade_label'] ?? '');
    $count = (int)($_POST['room_count'] ?? 0);

    if ($grade === '' || $count < 1 || $count > 50) {
      $err = 'กรอกชื่อชั้น และจำนวนห้อง 1–50';
    } else {
      try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO classes(grade_label, section_no, class_name) VALUES (?,?,?)');
        for ($i=1; $i<=$count; $i++) {
          $name = $grade . '/' . $i;
          // กันชนกับ unique: ถ้ามีอยู่แล้วข้าม
          try {
            $stmt->execute([$grade, $i, $name]);
          } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate')) throw $e;
          }
        }
        $pdo->commit();
        flash_set('success', "สร้างชั้น {$grade} จำนวน {$count} ห้องเรียบร้อย");
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

<div class="max-w-xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">สร้างชั้นเรียนแบบรวดเร็ว</h1>

  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm mb-1">ชื่อชั้น</label>
      <input name="grade_label" class="w-full border rounded-lg px-3 py-2" required placeholder="เช่น ป1, ป2, ม.1" value="<?= htmlspecialchars($_POST['grade_label'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">จำนวนห้อง</label>
      <input type="number" name="room_count" class="w-full border rounded-lg px-3 py-2" min="1" max="50" required value="<?= htmlspecialchars($_POST['room_count'] ?? '4'); ?>">
    </div>
    <p class="text-xs text-slate-500">ระบบจะสร้าง: ป1/1, ป1/2, ... ตามจำนวนที่กำหนด</p>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">สร้าง</button>
      <a href="<?= url('classes.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
