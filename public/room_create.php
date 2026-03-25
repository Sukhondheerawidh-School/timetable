<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) $err = 'CSRF ไม่ถูกต้อง';
  else {
    $code = trim($_POST['room_code'] ?? '');
    $name = trim($_POST['room_name'] ?? '');
    $building = trim($_POST['building'] ?? '');
    $type = $_POST['room_type'] ?? 'classroom';

    if ($code === '' || $name === '') $err = 'กรอก รหัสห้อง และ ชื่อห้อง ให้ครบ';
    elseif (!in_array($type, ['classroom','lab'], true)) $err = 'ประเภทไม่ถูกต้อง';
    else {
      try {
        $stmt = $pdo->prepare('INSERT INTO rooms(room_code, room_name, building, room_type) VALUES (?,?,?,?)');
        $stmt->execute([$code, $name, $building, $type]);
        flash_set('success','เพิ่มห้องสำเร็จ');
        redirect('rooms.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) $err = 'รหัสห้องนี้มีอยู่แล้ว';
        else $err = 'ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มห้อง</h1>

  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm mb-1">รหัสห้อง</label>
      <input name="room_code" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['room_code'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">ชื่อห้อง</label>
      <input name="room_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['room_name'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">อาคาร</label>
      <input name="building" class="w-full border rounded-lg px-3 py-2" placeholder="เช่น อาคาร 1" value="<?= htmlspecialchars($_POST['building'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">ประเภท</label>
      <select name="room_type" class="w-full border rounded-lg px-3 py-2">
        <?php $rt = $_POST['room_type'] ?? 'classroom'; ?>
        <option value="classroom" <?= $rt==='classroom'?'selected':''; ?>>ห้องเรียน</option>
        <option value="lab" <?= $rt==='lab'?'selected':''; ?>>ห้องปฏิบัติการ</option>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('rooms.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
