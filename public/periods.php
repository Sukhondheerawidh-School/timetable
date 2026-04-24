<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$stmt = $pdo->query('SELECT id, period_no, start_time, end_time, created_at FROM period_slots ORDER BY period_no ASC');
$periods = $stmt->fetchAll();
$flash = flash_get();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-4xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">คาบเรียน</h1>
    <div class="flex gap-2">
      <a href="<?= url('period_breaks.php'); ?>" class="px-3 py-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm <?= active_cls('period_breaks.php', $path); ?>">คาบพักตามชั้น</a>
      <a href="<?= url('period_create.php'); ?>" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">+ เพิ่มคาบ</a>
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
          <th class="text-left px-4 py-3">คาบที่</th>
          <th class="text-left px-4 py-3">เริ่ม</th>
          <th class="text-left px-4 py-3">สิ้นสุด</th>
          <th class="text-left px-4 py-3">สร้างเมื่อ</th>
          <th class="text-right px-4 py-3">การทำงาน</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($periods as $p): ?>
          <tr class="border-t">
            <td class="px-4 py-3 font-medium"><?= (int)$p['period_no']; ?></td>
            <td class="px-4 py-3"><?= substr($p['start_time'],0,5); ?></td>
            <td class="px-4 py-3"><?= substr($p['end_time'],0,5); ?></td>
            <td class="px-4 py-3"><?= th_date($p['created_at']); ?></td>
            <td class="px-4 py-3 text-right">
              <a class="text-slate-700 hover:underline mr-3" href="<?= url('period_edit.php?id='.(int)$p['id']); ?>">แก้ไข</a>
              <form action="<?= url('period_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'ลบคาบที่ <?= (int)$p['period_no']; ?> ?'});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">
                <button class="text-rose-600 hover:underline">ลบ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$periods): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">ยังไม่ได้กำหนดคาบ</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <p class="text-xs text-slate-500 mt-3">
    แนะนำให้การกำหนดเวลาไม่ซ้อนทับกัน และเรียงลำดับคาบที่ต่อเนื่องกัน (ระบบจัดตารางจะใช้ช่วงเวลาเหล่านี้)
  </p>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
