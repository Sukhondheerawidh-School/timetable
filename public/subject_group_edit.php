<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, name, display_order, is_active FROM subject_groups WHERE id = ?');
$stmt->execute([$id]);
$group = $stmt->fetch();

if (!$group) {
  flash_set('error', 'ไม่พบกลุ่มสาระ');
  redirect('subject_groups.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $name = trim($_POST['name'] ?? '');
    $display_order = (int)($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
      $err = 'กรุณากรอกชื่อกลุ่มสาระ';
    } else {
      try {
        $stmt = $pdo->prepare('UPDATE subject_groups SET name = ?, display_order = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$name, $display_order, $is_active, $id]);
        flash_set('success', 'อัปเดตกลุ่มสาระสำเร็จ');
        redirect('subject_groups.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $err = 'ชื่อกลุ่มสาระนี้มีอยู่แล้ว';
        } else {
          $err = 'ผิดพลาด: ' . $e->getMessage();
        }
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-2xl mx-auto px-4">
  <div class="mt-8 mb-6">
    <h1 class="text-2xl font-bold text-slate-800">✏️ แก้ไขกลุ่มสาระการเรียนรู้</h1>
    <p class="text-sm text-slate-500 mt-1">อัปเดตข้อมูลกลุ่มสาระ</p>
  </div>

  <?php if ($err): ?>
    <div class="mb-6 p-4 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 text-sm font-medium shadow-sm">
      ❌ <?= htmlspecialchars($err); ?>
    </div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow-xl border border-slate-200 p-8 space-y-6">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm mb-1">ชื่อกลุ่มสาระ <span class="text-rose-500">*</span></label>
      <input 
        name="name" 
        class="w-full border rounded-lg px-3 py-2" 
        required 
        value="<?= htmlspecialchars($_POST['name'] ?? $group['name'] ?? ''); ?>">
      <p class="text-xs text-slate-500 mt-1">ชื่อกลุ่มสาระต้องไม่ซ้ำกัน</p>
    </div>

    <div>
      <label class="block text-sm mb-1">ลำดับการแสดงผล</label>
      <input 
        name="display_order" 
        type="number" 
        min="0"
        class="w-full border rounded-lg px-3 py-2" 
        value="<?= (int)($_POST['display_order'] ?? $group['display_order'] ?? 0); ?>">
      <p class="text-xs text-slate-500 mt-1">ใช้สำหรับเรียงลำดับในรายการต่างๆ (เลขน้อยขึ้นก่อน)</p>
    </div>

    <div>
      <label class="flex items-center gap-2">
        <input 
          type="checkbox" 
          name="is_active" 
          value="1"
          class="rounded"
          <?php 
          $is_active = $_POST['is_active'] ?? $group['is_active'] ?? 1;
          echo $is_active ? 'checked' : ''; 
          ?>>
        <span class="text-sm">เปิดใช้งาน</span>
      </label>
      <p class="text-xs text-slate-500 mt-1">กลุ่มสาระที่ไม่เปิดใช้งานจะไม่แสดงในตัวเลือก</p>
    </div>

    <div class="flex items-center gap-2 pt-2">
      <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">
        บันทึก
      </button>
      <a href="<?= url('subject_groups.php'); ?>" class="px-4 py-2 rounded-xl border hover:bg-slate-50">
        ยกเลิก
      </a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
