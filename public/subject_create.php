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

  <?php if ($err): ?><div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">รหัสวิชา</label>
      <input name="subject_code" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['subject_code'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อวิชา</label>
      <input name="subject_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['subject_name'] ?? ''); ?>">
    </div>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <a href="<?= url('subjects.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
