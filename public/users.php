<?php
// public/users.php  (หรือ user.php ถ้าคุณใช้ชื่อเดิม)
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();
requireAdmin();

// ✅ เรียงตามชื่อผู้ใช้ (username)
$stmt = $pdo->query('SELECT id, name, username, role, created_at FROM users ORDER BY username ASC');
$users = $stmt->fetchAll();

// ดึง flash ถ้ามี
$flash = flash_get();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

  <div class="max-w-6xl mx-auto px-4">
    <div class="flex items-center justify-between mt-8 mb-4">
      <h1 class="text-xl font-semibold">ผู้ใช้</h1>
      <a href="<?= url('user_create.php'); ?>" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">+ เพิ่มผู้ใช้</a>
    </div>

    <?php if ($flash): ?>
      <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
        <?= htmlspecialchars($flash['msg']); ?>
      </div>
    <?php endif; ?>

    <div class="overflow-x-auto bg-white rounded-2xl shadow">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <!-- ✅ เอาคอลัมน์ # ออก -->
            <th class="text-left px-4 py-3">ชื่อผู้ใช้</th>
            <th class="text-left px-4 py-3">ชื่อ</th>
            <th class="text-left px-4 py-3">สิทธิ์</th>
            <th class="text-left px-4 py-3">สร้างเมื่อ</th>
            <th class="text-right px-4 py-3">การทำงาน</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr class="border-t">
            <!-- ✅ เอาคอลัมน์ # ออก และสลับตำแหน่งชื่อผู้ใช้กับชื่อ -->
            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($u['username']); ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($u['name']); ?></td>
            <td class="px-4 py-3">
              <?php
              $roleColors = [
                'superuser' => 'bg-violet-100 text-violet-700',
                'admin' => 'bg-purple-100 text-purple-700',
                'user' => 'bg-slate-100 text-slate-700'
              ];
              $roleColor = $roleColors[$u['role']] ?? 'bg-slate-100 text-slate-700';
              ?>
              <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?= $roleColor ?>">
                <?= htmlspecialchars($u['role']); ?>
              </span>
            </td>
            <td class="px-4 py-3 text-slate-600"><?= date('d/m/Y H:i', strtotime($u['created_at'])); ?></td>
            <td class="px-4 py-3 text-right">
              <a class="text-blue-600 hover:underline mr-3" href="<?= url('user_edit.php'); ?>?id=<?= (int)$u['id']; ?>">แก้ไข</a>
              <?php if ((int)$u['id'] !== (int)currentUser()['id']): ?>
              <form action="<?= url('user_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'ยืนยันลบผู้ใช้นี้?'});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$u['id']; ?>">
                <button class="text-rose-600 hover:underline">ลบ</button>
              </form>
              <?php else: ?>
                <span class="text-slate-400 text-xs">👤 คุณเอง</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?>
          <tr>
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">
              ไม่พบผู้ใช้
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
