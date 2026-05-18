<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, name FROM subject_groups WHERE id = ?');
$stmt->execute([$id]);
$group = $stmt->fetch();

if (!$group) {
  flash_set('error', 'ไม่พบกลุ่มสาระ');
  redirect('subject_groups.php');
}

// ตรวจสอบว่ามีครูใช้งานกลุ่มสาระนี้หรือไม่
$check_stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM teachers WHERE subject_group = ?');
$check_stmt->execute([$id]);
$teacher_count = (int)$check_stmt->fetch()['cnt'];

if ($teacher_count > 0) {
  flash_set('error', 'ไม่สามารถลบกลุ่มสาระ "' . $group['name'] . '" ได้ เนื่องจากมีครู ' . $teacher_count . ' คนใช้งานอยู่');
  redirect('subject_groups.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF ไม่ถูกต้อง');
    redirect('subject_groups.php');
  }

  try {
    $stmt = $pdo->prepare('DELETE FROM subject_groups WHERE id = ?');
    $stmt->execute([$id]);
    flash_set('success', 'ลบกลุ่มสาระสำเร็จ');
    redirect('subject_groups.php');
  } catch (Throwable $e) {
    flash_set('error', 'ผิดพลาด: ' . $e->getMessage());
    redirect('subject_groups.php');
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">ลบกลุ่มสาระการเรียนรู้</h1>

  <div class="bg-white rounded-2xl shadow border border-slate-200 p-6">
    <div class="mb-6">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-12 h-12 rounded-full bg-rose-100 flex items-center justify-center">
          <svg class="w-6 h-6 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        </div>
        <div>
          <h2 class="font-semibold text-lg">ยืนยันการลบ</h2>
          <p class="text-sm text-slate-600">การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
        </div>
      </div>

      <div class="bg-slate-50 rounded-lg p-4 mb-4">
        <div class="text-sm text-slate-600 mb-1">กลุ่มสาระที่จะลบ:</div>
        <div class="font-semibold text-lg"><?= htmlspecialchars($group['name']); ?></div>
      </div>

      <p class="text-sm text-slate-600">
        คุณแน่ใจหรือไม่ว่าต้องการลบกลุ่มสาระนี้? 
        กลุ่มสาระจะถูกลบออกจากระบบอย่างถาวร
      </p>
    </div>

    <form method="post" class="flex items-center gap-2">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 text-white hover:bg-rose-700">
        ยืนยันการลบ
      </button>
      <a href="<?= url('subject_groups.php'); ?>" class="px-4 py-2 rounded-xl border hover:bg-slate-50">
        ยกเลิก
      </a>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
