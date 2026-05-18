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
    } elseif (!in_array($role, ['admin','user','superuser'], true)) {
      $err = 'สิทธิ์ไม่ถูกต้อง';
    } elseif ($role === 'superuser' && !isSuperuser()) {
      $err = 'เฉพาะ Superuser เท่านั้นที่สามารถกำหนด role นี้ได้';
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
      <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อ-นามสกุล</label>
        <input name="name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['name'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อผู้ใช้</label>
        <input name="username" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required placeholder="เช่น teacher01" value="<?= htmlspecialchars($_POST['username'] ?? ''); ?>">
        <p class="text-xs text-slate-500 mt-1">a-z, 0-9, . _ - (3–32 ตัว)</p>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">รหัสผ่าน</label>
          <input type="password" name="password" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">ยืนยันรหัสผ่าน</label>
          <input type="password" name="password2" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">สิทธิ์</label>
        <select name="role" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="user" <?= (($_POST['role'] ?? '')==='user')?'selected':''; ?>>user</option>
          <option value="admin" <?= (($_POST['role'] ?? '')==='admin')?'selected':''; ?>>admin</option>
          <option value="superuser" <?= (($_POST['role'] ?? '')==='superuser')?'selected':''; ?>>superuser</option>
        </select>
      </div>

      <div class="flex items-center gap-2">
        <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
        <a href="<?= url('users.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
      </div>
    </form>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
