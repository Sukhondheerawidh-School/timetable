<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin(); requireAdmin();

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    $code = trim($_POST['subject_code'] ?? '');
    $name = trim($_POST['subject_name'] ?? '');
    if ($code==='' || $name==='') $err='กรอก รหัสวิชา และ ชื่อวิชา ให้ครบ';
    else {
      try {
        $stmt = $pdo->prepare('INSERT INTO subjects(subject_code, subject_name) VALUES (?,?)');
        $stmt->execute([$code,$name]);

        logCreate('subjects', (int)$pdo->lastInsertId(), [
          'subject_code' => $code,
          'subject_name' => $name,
        ]);
        flash_set('success','เพิ่มรายวิชาสำเร็จ');
        redirect('subjects.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(),'Duplicate')) $err='รหัสวิชานี้มีอยู่แล้ว';
        else $err='ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มรายวิชา</h1>

  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm mb-1">รหัสวิชา</label>
      <input name="subject_code" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['subject_code'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">ชื่อวิชา</label>
      <input name="subject_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['subject_name'] ?? ''); ?>">
    </div>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('subjects.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
