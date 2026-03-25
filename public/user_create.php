<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $name = trim($_POST['name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $role = $_POST['role'] ?? 'user';
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if ($name === '' || $username === '' || $pass === '' || $pass2 === '') {
      $err = 'กรอกข้อมูลให้ครบ';
    } elseif (!valid_username($username)) {
      $err = 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, 0-9, . _ - ความยาว 3-32 ตัวอักษร';
    } elseif (strlen($pass) < 8) {
      $err = 'รหัสผ่านอย่างน้อย 8 ตัวอักษร';
    } elseif ($pass !== $pass2) {
      $err = 'ยืนยันรหัสผ่านไม่ตรงกัน';
    } elseif (!in_array($role, ['admin','user'], true)) {
      $err = 'สิทธิ์ไม่ถูกต้อง';
    } else {
      try {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO users(name,username,password_hash,role) VALUES (?,?,?,?)');
        $stmt->execute([$name, $username, $hash, $role]);
        flash_set('success', 'สร้างผู้ใช้สำเร็จ');
        redirect('users.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $err = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
        } else {
          $err = 'ผิดพลาด: '.$e->getMessage();
        }
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

  <div class="max-w-xl mx-auto px-4">
    <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มผู้ใช้</h1>

    <?php if ($err): ?>
      <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <div>
        <label class="block text-sm mb-1">ชื่อ-นามสกุล</label>
        <input name="name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['name'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm mb-1">ชื่อผู้ใช้</label>
        <input name="username" class="w-full border rounded-lg px-3 py-2" required placeholder="เช่น teacher01" value="<?= htmlspecialchars($_POST['username'] ?? ''); ?>">
        <p class="text-xs text-slate-500 mt-1">a-z, 0-9, . _ - (3–32 ตัว)</p>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm mb-1">รหัสผ่าน</label>
          <input type="password" name="password" class="w-full border rounded-lg px-3 py-2" required>
        </div>
        <div>
          <label class="block text-sm mb-1">ยืนยันรหัสผ่าน</label>
          <input type="password" name="password2" class="w-full border rounded-lg px-3 py-2" required>
        </div>
      </div>
      <div>
        <label class="block text-sm mb-1">สิทธิ์</label>
        <select name="role" class="w-full border rounded-lg px-3 py-2">
          <option value="user" <?= (($_POST['role'] ?? '')==='user')?'selected':''; ?>>user</option>
          <option value="admin" <?= (($_POST['role'] ?? '')==='admin')?'selected':''; ?>>admin</option>
        </select>
      </div>

      <div class="flex items-center gap-2">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
        <a href="<?= url('users.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
      </div>
    </form>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
