<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

$rawGetId = $_GET['id'] ?? null;
$rawPostId = $_POST['id'] ?? null;
$id = (int)($rawPostId ?? $rawGetId ?? 0);
if ($id <= 0) {
  flash_set('error', 'ไม่พบรหัสผู้ใช้ (id)');
  redirect('users.php');
}

$stmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id = ?');
$stmt->execute([$id]);
$editUser = $stmt->fetch();
if (!$editUser) { flash_set('error','ไม่พบผู้ใช้'); redirect('users.php'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $name = trim($_POST['name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $role = $_POST['role'] ?? $editUser['role'];
    $newpass = $_POST['new_password'] ?? '';
    $newpass2 = $_POST['new_password2'] ?? '';

    if ($name === '' || $username === '') {
      $err = 'กรอกชื่อและชื่อผู้ใช้';
    } elseif (!valid_username($username)) {
      $err = 'ชื่อผู้ใช้ไม่ถูกต้อง (a-z, 0-9, . _ - 3–32 ตัว)';
    } elseif (!in_array($role, ['admin','user','superuser'], true)) {
      $err = 'สิทธิ์ไม่ถูกต้อง';
    } elseif ($role === 'superuser' && !isSuperuser()) {
      $err = 'เฉพาะ Superuser เท่านั้นที่สามารถกำหนด role นี้ได้';
    } elseif ($newpass !== '' && (strlen($newpass) < 8 || $newpass !== $newpass2)) {
      $err = 'รหัสผ่านใหม่อย่างน้อย 8 ตัว และต้องยืนยันให้ตรงกัน';
    } else {
      try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE users SET name=?, username=?, role=? WHERE id=?');
        $stmt->execute([$name, $username, $role, $id]);

        if ($newpass !== '') {
          $hash = password_hash($newpass, PASSWORD_BCRYPT);
          $stmt = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
          $stmt->execute([$hash, $id]);
        }
        $pdo->commit();

        if ((int)$id === (int)currentUser()['id']) {
          $_SESSION['user']['name'] = $name;
          $_SESSION['user']['username'] = $username;
          $_SESSION['user']['role'] = $role;
        }

        flash_set('success', 'อัปเดตข้อมูลสำเร็จ');
        redirect('users.php');
      } catch (Throwable $e) {
        $pdo->rollBack();
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
    <h1 class="text-xl font-semibold mt-8 mb-1">แก้ไขผู้ใช้</h1>
    <div class="text-sm text-slate-500 mb-4">กำลังแก้ไข: <span class="font-medium text-slate-700"><?= htmlspecialchars($editUser['username']); ?></span></div>

    <?php if ($debug): ?>
      <div class="mb-4 p-3 rounded bg-amber-50 text-amber-900 text-xs overflow-x-auto">
        <div class="font-semibold mb-1">Debug (user_edit.php)</div>
        <div><span class="font-medium">REQUEST_URI:</span> <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? ''); ?></div>
        <div><span class="font-medium">GET id:</span> <?= htmlspecialchars(var_export($rawGetId, true)); ?></div>
        <div><span class="font-medium">POST id:</span> <?= htmlspecialchars(var_export($rawPostId, true)); ?></div>
        <div><span class="font-medium">Computed $id:</span> <?= (int)$id; ?></div>
        <div><span class="font-medium">Active DB:</span> <?= htmlspecialchars((string)($activeDb ?? '')); ?></div>
        <div><span class="font-medium">Fetched user:</span> id=<?= (int)$editUser['id']; ?>, username=<?= htmlspecialchars($editUser['username']); ?></div>
        <div class="mt-2"><span class="font-medium">Direct check:</span>
          <pre class="whitespace-pre-wrap"><?= htmlspecialchars(var_export($userDirectDbg ?? null, true)); ?></pre>
        </div>
        <div class="mt-2"><span class="font-medium">Prepared check:</span>
          <pre class="whitespace-pre-wrap"><?= htmlspecialchars(var_export($userPrepDbg ?? null, true)); ?></pre>
        </div>
        <?php if (!empty($stmtDbgDump ?? '')): ?>
          <div class="mt-2"><span class="font-medium">debugDumpParams:</span>
            <pre class="whitespace-pre-wrap"><?= htmlspecialchars($stmtDbgDump); ?></pre>
          </div>
        <?php endif; ?>
        <div><span class="font-medium">Current user:</span> id=<?= (int)(currentUser()['id'] ?? 0); ?>, username=<?= htmlspecialchars(currentUser()['username'] ?? ''); ?></div>
      </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
    <?php endif; ?>

    <form method="post" action="<?= url('user_edit.php'); ?>?id=<?= (int)$id; ?>" autocomplete="off" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="id" value="<?= (int)$id; ?>">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อ-นามสกุล</label>
        <input name="name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['name'] ?? $editUser['name'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อผู้ใช้</label>
        <input name="username" autocomplete="off" autocapitalize="none" spellcheck="false" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['username'] ?? $editUser['username'] ?? ''); ?>">
        <p class="text-xs text-slate-500 mt-1">a-z, 0-9, . _ - (3–32 ตัว)</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">สิทธิ์</label>
        <?php $roleVal = $_POST['role'] ?? $editUser['role'] ?? 'user'; ?>
        <select name="role" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="user" <?= $roleVal==='user' ? 'selected':''; ?>>user</option>
          <option value="admin" <?= $roleVal==='admin' ? 'selected':''; ?>>admin</option>
          <option value="superuser" <?= $roleVal==='superuser' ? 'selected':''; ?>>superuser</option>
        </select>
      </div>

      <div class="border-t pt-4">
        <div class="text-sm font-medium mb-2">เปลี่ยนรหัสผ่าน (ไม่บังคับ)</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">รหัสผ่านใหม่</label>
            <input type="password" name="new_password" autocomplete="new-password" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="ปล่อยว่างถ้าไม่เปลี่ยน">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">ยืนยันรหัสผ่านใหม่</label>
            <input type="password" name="new_password2" autocomplete="new-password" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="ปล่อยว่างถ้าไม่เปลี่ยน">
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
        <a href="<?= url('users.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
      </div>
    </form>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
