<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM subjects WHERE id=?');
$stmt->execute([$id]);
$subject = $stmt->fetch();
if (!$subject) { flash_set('error','ไม่พบรายวิชา'); redirect('subjects.php'); }

$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    $code = trim($_POST['subject_code'] ?? '');
    $name = trim($_POST['subject_name'] ?? '');
    if ($code==='' || $name==='') $err='กรอก รหัสวิชา และ ชื่อวิชา ให้ครบ';
    else {
      try {
        // กันรหัสซ้ำกับวิชาอื่น
        $chk = $pdo->prepare('SELECT id FROM subjects WHERE subject_code=? AND id<>? LIMIT 1');
        $chk->execute([$code,$id]);
        if ($chk->fetch()) throw new Exception('รหัสวิชานี้มีอยู่แล้ว');

        $up = $pdo->prepare('UPDATE subjects SET subject_code=?, subject_name=? WHERE id=?');
        $up->execute([$code,$name,$id]);
        flash_set('success','อัปเดตรายวิชาสำเร็จ');
        redirect('subjects.php');
      } catch (Throwable $e) {
        $err='ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขรายวิชา</h1>

  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm mb-1">รหัสวิชา</label>
      <input name="subject_code" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['subject_code'] ?? $subject['subject_code']); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">ชื่อวิชา</label>
      <input name="subject_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['subject_name'] ?? $subject['subject_name']); ?>">
    </div>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('subjects.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
