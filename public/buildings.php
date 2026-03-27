<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

// Ensure schema exists
// (Best-effort; ignores permissions/old MySQL limitations)
tt_buildings_init($pdo);

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      if ($action === 'create') {
        $name = trim((string)($_POST['building_name'] ?? ''));
        if ($name === '') throw new Exception('กรุณากรอกชื่ออาคาร');
        $st = $pdo->prepare('INSERT INTO duty_buildings(building_name, is_active, sort_order) VALUES (?,?,?)');
        $st->execute([$name, 1, 0]);

        logActivity('create', 'duty_buildings', (int)$pdo->lastInsertId(), null, [
          'building_name' => $name,
          'is_active' => 1,
          'sort_order' => 0,
        ]);
        flash_set('success', 'เพิ่มอาคารแล้ว');
        redirect('buildings.php');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['building_name'] ?? ''));
        if ($id <= 0) throw new Exception('ไม่พบ ID');
        if ($name === '') throw new Exception('กรุณากรอกชื่ออาคาร');
        $old = $pdo->prepare('SELECT * FROM duty_buildings WHERE id=?');
        $old->execute([$id]);
        $oldRow = $old->fetch();

        $st = $pdo->prepare('UPDATE duty_buildings SET building_name=? WHERE id=?');
        $st->execute([$name, $id]);

        if ($oldRow) {
          $newRow = $oldRow;
          $newRow['building_name'] = $name;
          logUpdate('duty_buildings', $id, $oldRow, $newRow);
        }
        flash_set('success', 'แก้ไขแล้ว');
        redirect('buildings.php');
      } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;

        $old = $pdo->prepare('SELECT * FROM duty_buildings WHERE id=?');
        $old->execute([$id]);
        $oldRow = $old->fetch();

        $st = $pdo->prepare('UPDATE duty_buildings SET is_active=? WHERE id=?');
        $st->execute([$enabled, $id]);

        if ($oldRow) {
          $newRow = $oldRow;
          $newRow['is_active'] = $enabled;
          logUpdate('duty_buildings', $id, $oldRow, $newRow);
        }
        flash_set('success', 'บันทึกแล้ว');
        redirect('buildings.php');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('ไม่พบ ID');

        // Prevent deletion if referenced
        $cnt1 = $pdo->prepare('SELECT COUNT(*) FROM duty_master_posts WHERE building_id=?');
        $cnt1->execute([$id]);
        if ((int)$cnt1->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีเวร/จุดที่ผูกกับอาคารนี้');

        $cnt2 = $pdo->prepare('SELECT COUNT(*) FROM teacher_buildings WHERE building_id=?');
        $cnt2->execute([$id]);
        if ((int)$cnt2->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีครูที่ผูกกับอาคารนี้');

        $old = $pdo->prepare('SELECT * FROM duty_buildings WHERE id=?');
        $old->execute([$id]);
        $oldRow = $old->fetch();

        $del = $pdo->prepare('DELETE FROM duty_buildings WHERE id=?');
        $del->execute([$id]);

        if ($oldRow) {
          logDelete('duty_buildings', $id, $oldRow);
        }
        flash_set('success', 'ลบแล้ว');
        redirect('buildings.php');
      }
    } catch (Throwable $e) {
      $err = 'ผิดพลาด: ' . $e->getMessage();
    }
  }
}

$st = $pdo->prepare('SELECT * FROM duty_buildings ORDER BY is_active DESC, sort_order ASC, building_name ASC');
$st->execute();
$buildings = $st->fetchAll(PDO::FETCH_ASSOC);

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-5xl mx-auto px-4 mt-8">
  <div class="mb-3">
    <h1 class="text-xl font-semibold">🏢 อาคาร</h1>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-4 mb-4 text-xs text-slate-500">ใช้สำหรับผูก “เวร/จุด” กับอาคาร และกำหนดครูว่าไปประกฎที่อาคารไหน (ได้สูงสุด 2 อาคาร)</div>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="font-medium mb-3">เพิ่มอาคาร</div>
    <form method="post" class="flex flex-col md:flex-row gap-2">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="create">
      <input name="building_name" class="flex-1 border rounded px-3 py-2" placeholder="เช่น อาคาร 1 / อาคารเรียน A" required>
      <button class="px-4 py-2 rounded bg-slate-900 text-white">เพิ่ม</button>
    </form>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b font-medium">รายการ</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2">สถานะ</th>
            <th class="text-left px-3 py-2">ชื่อ</th>
            <th class="text-right px-3 py-2">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($buildings as $b): ?>
            <tr class="border-t">
              <td class="px-3 py-2">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$b['id']; ?>">
                  <input type="hidden" name="enabled" value="<?= (int)!((int)$b['is_active']); ?>">
                  <button class="px-3 py-1.5 rounded-lg border <?= (int)$b['is_active']? 'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-slate-50 text-slate-600' ?>">
                    <?= (int)$b['is_active'] ? 'เปิด' : 'ปิด' ?>
                  </button>
                </form>
              </td>
              <td class="px-3 py-2">
                <div class="font-medium"><?= htmlspecialchars($b['building_name']); ?></div>
                <details class="mt-2">
                  <summary class="cursor-pointer text-xs text-blue-700 hover:underline select-none">แก้ไข</summary>
                  <form method="post" class="mt-2 grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$b['id']; ?>">

                    <div class="md:col-span-10">
                      <label class="block text-xs text-slate-500 mb-1">ชื่ออาคาร</label>
                      <input name="building_name" class="w-full border rounded px-3 py-2" required value="<?= htmlspecialchars($b['building_name']); ?>">
                    </div>
                    <div class="md:col-span-2">
                      <button class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white">บันทึก</button>
                    </div>
                  </form>
                </details>
              </td>
              <td class="px-3 py-2 text-right">
                <form method="post" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบ', text: 'ลบอาคารนี้?', confirmButtonText: 'ลบ' });" class="inline">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$b['id']; ?>">
                  <button class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50">ลบ</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
