<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

$debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true');

// Prevent any browser/proxy caching while we chase the wrong-id issue.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$rawGetId = $_GET['id'] ?? null;
$rawPostId = $_POST['id'] ?? null;
$id = (int)($rawPostId ?? $rawGetId ?? 0);
if ($id <= 0) {
  flash_set('error', 'ไม่พบรหัสผู้ใช้ (id)');
  redirect('users.php');
}

if ($debug) {
  error_log('[user_edit debug] REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
  error_log('[user_edit debug] raw GET id=' . var_export($rawGetId, true) . ' raw POST id=' . var_export($rawPostId, true) . ' computed id=' . $id);
  $cu = currentUser();
  error_log('[user_edit debug] currentUser id=' . var_export($cu['id'] ?? null, true) . ' username=' . var_export($cu['username'] ?? null, true));
}
$stmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id = ?');
$stmt->execute([$id]);
$editUser = $stmt->fetch();
if (!$editUser) { flash_set('error','ไม่พบผู้ใช้'); redirect('users.php'); }

if ($debug) {
  try {
    $activeDb = $pdo->query('SELECT DATABASE()')->fetchColumn();
  } catch (Throwable $e) {
    $activeDb = null;
  }

  // Re-check with both direct and a fresh prepared statement
  try {
    $userDirectDbg = $pdo->query('SELECT id, username, name, role FROM users WHERE id = ' . (int)$id . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $userDirectDbg = ['error' => $e->getMessage()];
  }

  $stmtDbg = null;
  $userPrepDbg = null;
  $stmtDbgDump = '';
  try {
    $stmtDbg = $pdo->prepare('SELECT id, username, name, role FROM users WHERE id = ? LIMIT 1');
    $stmtDbg->execute([$id]);
    $userPrepDbg = $stmtDbg->fetch(PDO::FETCH_ASSOC);
    ob_start();
    $stmtDbg->debugDumpParams();
    $stmtDbgDump = trim(ob_get_clean());
  } catch (Throwable $e) {
    $userPrepDbg = ['error' => $e->getMessage()];
  }

  error_log('[user_edit debug] activeDb=' . var_export($activeDb, true));
  error_log('[user_edit debug] editUser(from first stmt)=' . json_encode($editUser, JSON_UNESCAPED_UNICODE));
  error_log('[user_edit debug] userDirectDbg=' . json_encode($userDirectDbg, JSON_UNESCAPED_UNICODE));
  error_log('[user_edit debug] userPrepDbg=' . json_encode($userPrepDbg, JSON_UNESCAPED_UNICODE));
}

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
    } elseif (!in_array($role, ['admin','user'], true)) {
      $err = 'สิทธิ์ไม่ถูกต้อง';
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
      <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <form method="post" action="<?= url('user_edit.php'); ?>?id=<?= (int)$id; ?>" autocomplete="off" class="bg-white rounded-2xl shadow p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="id" value="<?= (int)$id; ?>">
      <div>
        <label class="block text-sm mb-1">ชื่อ-นามสกุล</label>
        <input name="name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['name'] ?? $editUser['name'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm mb-1">ชื่อผู้ใช้</label>
        <input name="username" autocomplete="off" autocapitalize="none" spellcheck="false" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['username'] ?? $editUser['username'] ?? ''); ?>">
        <p class="text-xs text-slate-500 mt-1">a-z, 0-9, . _ - (3–32 ตัว)</p>
      </div>
      <div>
        <label class="block text-sm mb-1">สิทธิ์</label>
        <?php $roleVal = $_POST['role'] ?? $editUser['role'] ?? 'user'; ?>
        <select name="role" class="w-full border rounded-lg px-3 py-2">
          <option value="user" <?= $roleVal==='user' ? 'selected':''; ?>>user</option>
          <option value="admin" <?= $roleVal==='admin' ? 'selected':''; ?>>admin</option>
        </select>
      </div>

      <div class="border-t pt-4">
        <div class="text-sm font-medium mb-2">เปลี่ยนรหัสผ่าน (ไม่บังคับ)</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm mb-1">รหัสผ่านใหม่</label>
            <input type="password" name="new_password" autocomplete="new-password" class="w-full border rounded-lg px-3 py-2" placeholder="ปล่อยว่างถ้าไม่เปลี่ยน">
          </div>
          <div>
            <label class="block text-sm mb-1">ยืนยันรหัสผ่านใหม่</label>
            <input type="password" name="new_password2" autocomplete="new-password" class="w-full border rounded-lg px-3 py-2" placeholder="ปล่อยว่างถ้าไม่เปลี่ยน">
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
        <a href="<?= url('users.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
      </div>
    </form>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
