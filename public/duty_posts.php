<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin(); requireAdmin();

tt_duty_init($pdo);
tt_buildings_init($pdo);

$buildings = tt_buildings_list($pdo, false);
$buildingNameById = [];
foreach ($buildings as $b) {
  $buildingNameById[(int)$b['id']] = (string)$b['building_name'];
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      if ($action === 'create') {
        $name = trim((string)($_POST['post_name'] ?? ''));
        $building_id = ($_POST['building_id'] ?? '') !== '' ? (int)$_POST['building_id'] : null;
        if ($name === '') throw new Exception('กรุณากรอกชื่อเวร/จุด');
        $st = $pdo->prepare('INSERT INTO duty_master_posts(post_name, building_id, is_active, sort_order) VALUES (?,?,?,?)');
        $st->execute([$name, $building_id, 1, 0]);

        logCreate('duty_master_posts', (int)$pdo->lastInsertId(), [
          'post_name' => $name,
          'building_id' => $building_id,
          'is_active' => 1,
          'sort_order' => 0,
        ]);
        flash_set('success', 'เพิ่มเวร/จุดแล้ว');
        redirect('duty_posts.php');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['post_name'] ?? ''));
        $building_id = ($_POST['building_id'] ?? '') !== '' ? (int)$_POST['building_id'] : null;
        if ($id <= 0) throw new Exception('ไม่พบ ID');
        if ($name === '') throw new Exception('กรุณากรอกชื่อเวร/จุด');
        $oldStmt = $pdo->prepare('SELECT * FROM duty_master_posts WHERE id=?');
        $oldStmt->execute([$id]);
        $oldRow = $oldStmt->fetch();

        $st = $pdo->prepare('UPDATE duty_master_posts SET post_name=?, building_id=? WHERE id=?');
        $st->execute([$name, $building_id, $id]);

        if ($oldRow) {
          $newRow = $oldRow;
          $newRow['post_name'] = $name;
          $newRow['building_id'] = $building_id;
          logUpdate('duty_master_posts', $id, $oldRow, $newRow);
        }
        flash_set('success', 'แก้ไขแล้ว');
        redirect('duty_posts.php');
      } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
        $oldStmt = $pdo->prepare('SELECT * FROM duty_master_posts WHERE id=?');
        $oldStmt->execute([$id]);
        $oldRow = $oldStmt->fetch();

        $st = $pdo->prepare('UPDATE duty_master_posts SET is_active=? WHERE id=?');
        $st->execute([$enabled, $id]);

        if ($oldRow) {
          $newRow = $oldRow;
          $newRow['is_active'] = $enabled;
          logUpdate('duty_master_posts', $id, $oldRow, $newRow);
        }
        flash_set('success', 'บันทึกแล้ว');
        redirect('duty_posts.php');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $oldStmt = $pdo->prepare('SELECT * FROM duty_master_posts WHERE id=?');
        $oldStmt->execute([$id]);
        $oldRow = $oldStmt->fetch();

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM duty_master_shifts WHERE duty_post_id=?');
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีรายการเวรที่อ้างอิงจุดนี้อยู่');
        $del = $pdo->prepare('DELETE FROM duty_master_posts WHERE id=?');
        $del->execute([$id]);

        if ($oldRow) {
          logDelete('duty_master_posts', $id, $oldRow);
        }
        flash_set('success', 'ลบแล้ว');
        redirect('duty_posts.php');
      }
    } catch (Throwable $e) {
      $err = 'ผิดพลาด: '.$e->getMessage();
    }
  }
}

$st = $pdo->prepare('SELECT * FROM duty_master_posts ORDER BY is_active DESC, post_name');
$st->execute();
$posts = $st->fetchAll();

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-5xl mx-auto px-4 mt-8">
  <div class="mb-3">
    <h1 class="text-xl font-semibold">📍 ตั้งค่าชื่อเวร/จุด (Master)</h1>
  </div>

  <?php $ttDutyActive = 'posts'; include __DIR__ . '/../partials/duty_tabs.php'; ?>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-4 mb-4 text-xs text-slate-500">ใช้ร่วมทุกเทอม: ตั้งชื่อจุดเวรที่ใช้ซ้ำ เช่น อาคารชั้น 1, หน้าโรงอาหาร, เวรเติมเงิน</div>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="font-medium mb-3">เพิ่มเวร/จุด</div>
    <form method="post" class="flex flex-col md:flex-row gap-2">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="create">
      <input name="post_name" class="flex-1 border rounded px-3 py-2" placeholder="เช่น หน้าอาคาร A / หน้าโรงอาหาร" required>

      <select name="building_id" class="border rounded px-3 py-2">
        <option value="">— เลือกอาคาร —</option>
        <?php foreach ($buildings as $b): ?>
          <option value="<?= (int)$b['id']; ?>"><?= htmlspecialchars((string)$b['building_name']); ?><?= (int)$b['is_active'] ? '' : ' (ปิด)' ?></option>
        <?php endforeach; ?>
      </select>
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
            <th class="text-left px-3 py-2">อาคาร</th>
            <th class="text-right px-3 py-2">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $p): ?>
            <tr class="border-t">
              <td class="px-3 py-2">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">
                  <input type="hidden" name="enabled" value="<?= (int)!((int)$p['is_active']); ?>">
                  <button class="px-3 py-1.5 rounded-lg border <?= (int)$p['is_active']? 'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-slate-50 text-slate-600' ?>">
                    <?= (int)$p['is_active'] ? 'เปิด' : 'ปิด' ?>
                  </button>
                </form>
              </td>
              <td class="px-3 py-2">
                <div class="font-medium"><?= htmlspecialchars($p['post_name']); ?></div>
                <details class="mt-2">
                  <summary class="cursor-pointer text-xs text-blue-700 hover:underline select-none">แก้ไข</summary>
                  <form method="post" class="mt-2 grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">

                    <div class="md:col-span-10">
                      <label class="block text-xs text-slate-500 mb-1">ชื่อเวร/จุด</label>
                      <input name="post_name" class="w-full border rounded px-3 py-2" required value="<?= htmlspecialchars($p['post_name']); ?>">
                    </div>
                    <div class="md:col-span-10">
                      <label class="block text-xs text-slate-500 mb-1">อาคาร</label>
                      <select name="building_id" class="w-full border rounded px-3 py-2">
                        <option value="">— ไม่ระบุ —</option>
                        <?php $curB = ($p['building_id'] ?? '') !== null ? (int)$p['building_id'] : 0; ?>
                        <?php foreach ($buildings as $b): ?>
                          <option value="<?= (int)$b['id']; ?>" <?= $curB === (int)$b['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars((string)$b['building_name']); ?><?= (int)$b['is_active'] ? '' : ' (ปิด)' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="md:col-span-2">
                      <button class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white">บันทึก</button>
                    </div>
                  </form>
                </details>
              </td>
              <td class="px-3 py-2">
                <?php $bid = ($p['building_id'] ?? '') !== null ? (int)$p['building_id'] : 0; ?>
                <span class="text-slate-700"><?= htmlspecialchars($bid > 0 ? ($buildingNameById[$bid] ?? '—') : '—'); ?></span>
              </td>
              <td class="px-3 py-2 text-right">
                <form method="post" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบ', text: 'ลบรายการนี้?', confirmButtonText: 'ลบ' });" class="inline">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">
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
