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
    $start = max(1, (int)($_POST['section_start'] ?? 1));
    $count = (int)($_POST['room_count'] ?? 0);

    if ($grade === '' || $count < 1 || $count > 50) {
      $err = 'กรอกชื่อชั้น และจำนวนห้อง 1–50';
    } else {
      try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO classes(grade_label, section_no, class_name) VALUES (?,?,?)');
        $created = 0;
        for ($i=$start; $i < $start + $count; $i++) {
          $name = $grade . '/' . $i;
          // กันชนกับ unique: ถ้ามีอยู่แล้วข้าม
          try {
            $stmt->execute([$grade, $i, $name]);
            $created++;
          } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate')) throw $e;
          }
        }
        $pdo->commit();
        flash_set('success', "สร้างชั้น {$grade} จำนวน {$created} ห้องเรียบร้อย (เริ่มจากห้อง {$start})");
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

  <?php if ($err): ?><div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อชั้น</label>
      <input name="grade_label" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required placeholder="เช่น ป1, ป2, ม.1" value="<?= htmlspecialchars($_POST['grade_label'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">จำนวนห้อง</label>
      <input type="number" name="room_count" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" min="1" max="50" required value="<?= htmlspecialchars($_POST['room_count'] ?? '4'); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">เริ่มจากห้องที่ <span class="text-slate-400">(optional, เริ่มต้น = 1)</span></label>
      <input type="number" name="section_start" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" min="1" max="99" value="<?= htmlspecialchars($_POST['section_start'] ?? '1'); ?>">
    </div>
    <p class="text-xs text-slate-500">ตัวอย่าง: ชั้น อ2 เริ่มจากห้อง 3 จำนวน 2 → สร้าง อ2/3, อ2/4</p>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">สร้าง</button>
      <a href="<?= url('classes.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
