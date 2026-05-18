<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

// ✅ จัดการการลบทั้งหมด
$flash = flash_get();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    try {
      $pdo->beginTransaction();
      
      // ลบห้องทั้งหมด
      $stmt = $pdo->prepare("DELETE FROM rooms");
      $stmt->execute();
      $deleted = $stmt->rowCount();
      
      $pdo->commit();
      flash_set('success', "ลบห้องทั้งหมดเรียบร้อย ({$deleted} ห้อง)");
      redirect('rooms.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'เกิดข้อผิดพลาด: '.$e->getMessage());
    }
  }
}

$stmt = $pdo->query('SELECT id, room_code, room_name, building, room_type, created_at FROM rooms ORDER BY building ASC, room_code ASC');
$rooms = $stmt->fetchAll();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">ห้องเรียน / ห้องปฏิบัติการ</h1>
    <div class="flex gap-2">
      <a href="<?= url('room_template.php'); ?>" class="px-3 py-2 rounded-xl border hover:bg-slate-50 text-sm">ดาวน์โหลดเทมเพลต CSV</a>
      <a href="<?= url('room_import.php'); ?>" class="px-3 py-2 rounded-xl border hover:bg-slate-50 text-sm">นำเข้าจาก CSV</a>
      <a href="<?= url('room_create.php'); ?>" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">+ เพิ่มห้อง</a>
      <button type="button" id="btnDeleteAll" class="px-3 py-2 rounded-xl border border-rose-600 text-rose-600 hover:bg-rose-50 text-sm">
        🗑️ ลบห้องทั้งหมด
      </button>
    </div>
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
          <th class="text-left px-4 py-3">รหัสห้อง</th>
          <th class="text-left px-4 py-3">ชื่อห้อง</th>
          <th class="text-left px-4 py-3">อาคาร</th>
          <th class="text-left px-4 py-3">ประเภท</th>
          <th class="text-left px-4 py-3">สร้างเมื่อ</th>
          <th class="text-right px-4 py-3">การทำงาน</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rooms as $r): ?>
        <tr class="border-t">
          <td class="px-4 py-3"><?= htmlspecialchars($r['room_code']); ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($r['room_name']); ?></td>
          <td class="px-4 py-3"><?= htmlspecialchars($r['building']); ?></td>
          <td class="px-4 py-3">
            <span class="inline-flex px-2 py-0.5 rounded bg-slate-100">
              <?= $r['room_type'] === 'lab' ? 'ห้องปฏิบัติการ' : 'ห้องเรียน'; ?>
            </span>
          </td>
          <td class="px-4 py-3"><?= th_date($r['created_at']); ?></td>
          <td class="px-4 py-3 text-right">
            <a class="text-slate-700 hover:underline mr-3" href="<?= url('room_edit.php?id='.(int)$r['id']); ?>">แก้ไข</a>
            <form action="<?= url('room_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'ยืนยันลบห้องนี้?'});">
              <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id']; ?>">
              <button class="text-rose-600 hover:underline">ลบ</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rooms): ?>
        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">ยังไม่มีข้อมูลห้อง</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ Hidden Form สำหรับลบทั้งหมด -->
<form id="deleteAllForm" method="post" style="display:none;">
  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
  <input type="hidden" name="action" value="delete_all">
</form>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
// ✅ ปุ่มลบห้องทั้งหมด
const btnDeleteAll = document.getElementById('btnDeleteAll');
const deleteAllForm = document.getElementById('deleteAllForm');

btnDeleteAll.addEventListener('click', () => {
  ttDoubleConfirmSubmit(
    deleteAllForm,
    { title: 'ลบห้องทั้งหมด', text: '⚠️ คุณแน่ใจหรือไม่?\n\nจะลบห้องทั้งหมด (รวมห้องเรียนและห้องปฏิบัติการ)', confirmButtonText: 'ดำเนินการต่อ' },
    { title: 'ยืนยันอีกครั้ง', text: '❗ การกระทำนี้ไม่สามารถกู้คืนได้!', confirmButtonText: 'ลบทั้งหมด' }
  );
});
</script>
