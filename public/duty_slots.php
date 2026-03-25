<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

tt_duty_init($pdo);

// Master data: sync periods -> master duty slots (shared across all terms)
tt_duty_master_sync_from_periods($pdo);

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';

    try {
      if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
        $st = $pdo->prepare('UPDATE duty_master_time_slots SET is_active=? WHERE id=?');
        $st->execute([$enabled, $id]);
        flash_set('success', 'บันทึกแล้ว');
        redirect('duty_slots.php');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $label = trim((string)($_POST['slot_label'] ?? ''));
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $periodNoRaw = $_POST['period_no'] ?? null;
        $periodNo = ($periodNoRaw === '' || $periodNoRaw === null) ? null : (int)$periodNoRaw;
        if ($periodNo !== null && ($periodNo < 0 || $periodNo > 99)) $periodNo = null;

        if ($label === '') throw new Exception('กรุณากรอกชื่อช่วงเวลา');
        if ($start === '' || $end === '') throw new Exception('กรุณากรอกเวลาเริ่ม/สิ้นสุด');

        $st = $pdo->prepare('UPDATE duty_master_time_slots SET slot_label=?, start_time=?, end_time=?, period_no=? WHERE id=?');
        $st->execute([$label, $start, $end, $periodNo, $id]);
        flash_set('success', 'อัปเดตช่วงเวลาแล้ว');
        redirect('duty_slots.php');
      } elseif ($action === 'create_custom') {
        $label = trim((string)($_POST['slot_label'] ?? ''));
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $periodNoRaw = $_POST['period_no'] ?? null;
        $periodNo = ($periodNoRaw === '' || $periodNoRaw === null) ? null : (int)$periodNoRaw;
        if ($periodNo !== null && ($periodNo < 0 || $periodNo > 99)) $periodNo = null;

        if ($label === '') throw new Exception('กรุณากรอกชื่อช่วงเวลา');
        if ($start === '' || $end === '') throw new Exception('กรุณากรอกเวลาเริ่ม/สิ้นสุด');

        $maxSortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM duty_master_time_slots');
        $maxSortStmt->execute();
        $maxSort = (int)$maxSortStmt->fetchColumn();

        $slotKey = 'X' . date('His') . random_int(10, 99);
        $sort = $maxSort + 10;

        $ins = $pdo->prepare('INSERT INTO duty_master_time_slots(slot_key, slot_label, period_no, start_time, end_time, is_active, sort_order) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([$slotKey, $label, $periodNo, $start, $end, 1, $sort]);

        flash_set('success', 'เพิ่มช่วงเวลาเวรแล้ว');
        redirect('duty_slots.php');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // only allow deleting custom slots (not period-based)
        $chk = $pdo->prepare('SELECT slot_key, period_no FROM duty_master_time_slots WHERE id=?');
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) throw new Exception('ไม่พบช่วงเวลา');
        $slotKey = (string)$row['slot_key'];
        $pno = $row['period_no'] === null ? null : (int)$row['period_no'];
        if (substr($slotKey, 0, 1) === 'P' && $pno !== null && $pno > 0) {
          throw new Exception('คาบที่มาจากตารางเรียนลบไม่ได้ (ให้ปิดการใช้งานแทน)');
        }

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM duty_master_shifts WHERE duty_time_slot_id=?');
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีเวรที่อ้างอิงช่วงเวลานี้อยู่');

        $del = $pdo->prepare('DELETE FROM duty_master_time_slots WHERE id=?');
        $del->execute([$id]);
        flash_set('success', 'ลบช่วงเวลาแล้ว');
        redirect('duty_slots.php');
      }
    } catch (Throwable $e) {
      $err = 'ผิดพลาด: '.$e->getMessage();
    }
  }
}

$slotStmt = $pdo->prepare('SELECT * FROM duty_master_time_slots ORDER BY sort_order, id');
$slotStmt->execute();
$slots = $slotStmt->fetchAll();

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8">
  <div class="mb-3">
    <h1 class="text-xl font-semibold">🧭 ตั้งค่าช่วงเวลาเวร (Master)</h1>
  </div>

  <?php $ttDutyActive = 'slots'; include __DIR__ . '/../partials/duty_tabs.php'; ?>

  <div class="mb-4 p-4 rounded-2xl border bg-slate-50 text-sm">
    <div class="font-medium mb-1">ลำดับการตั้งค่าที่แนะนำ</div>
    <ol class="list-decimal pl-5 space-y-1 text-slate-700">
      <li>ตั้งค่า <b>ช่วงเวลาเวร (Master)</b> หน้านี้ (ใช้ร่วมทุกเทอม)</li>
      <li>ตั้งค่า <b>ชื่อเวร/จุด</b> และ <b>กำหนดเวร</b></li>
      <li>ไปที่หน้า <b>จัดเวร (รายเทอม)</b> เพื่อเลือกปี/เทอม แล้วลงครูประจำจุด</li>
    </ol>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-4 mb-4 text-xs text-slate-500">
    ระบบจะดึง “คาบเรียน” จากหน้าคาบเรียนมาเป็นช่วงเวลาเวรอัตโนมัติ และสามารถเพิ่มช่วงเวลาเวรพิเศษได้
  </div>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="font-medium mb-3">เพิ่มช่วงเวลาเวรพิเศษ</div>
    <form method="post" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="create_custom">

      <div class="md:col-span-5">
        <label class="block text-xs mb-1">ชื่อช่วงเวลา</label>
        <input name="slot_label" class="w-full border rounded px-3 py-2" placeholder="เช่น คาบ 0 (หน้าเสาธง) / เวรเช้า" required>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs mb-1">เวลาเริ่ม</label>
        <input type="time" name="start_time" class="w-full border rounded px-3 py-2" required>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs mb-1">เวลาจบ</label>
        <input type="time" name="end_time" class="w-full border rounded px-3 py-2" required>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs mb-1">หมายเลขคาบ (ไม่บังคับ)</label>
        <input type="number" name="period_no" class="w-full border rounded px-3 py-2" placeholder="เช่น 0" min="0" max="99">
      </div>
      <div class="md:col-span-1">
        <button class="w-full px-4 py-2 rounded bg-slate-900 text-white">เพิ่ม</button>
      </div>
    </form>
    <div class="mt-2 text-xs text-slate-500">ถ้ากำหนดหมายเลขคาบ = 0 จะช่วยกรองครูจาก “ข้อจำกัดครู” ได้ (ถ้าตั้งไว้)</div>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b font-medium">รายการช่วงเวลา</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2">เปิดใช้เวร</th>
            <th class="text-left px-3 py-2">ช่วงเวลา</th>
            <th class="text-left px-3 py-2">เวลา</th>
            <th class="text-left px-3 py-2">คาบ</th>
            <th class="text-right px-3 py-2">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($slots as $s): ?>
            <tr class="border-t align-top">
              <td class="px-3 py-2">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                  <input type="hidden" name="enabled" value="<?= (int)!((int)$s['is_active']); ?>">
                  <button class="px-3 py-1.5 rounded-lg border <?= (int)$s['is_active']? 'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-slate-50 text-slate-600' ?>">
                    <?= (int)$s['is_active'] ? 'เปิด' : 'ปิด' ?>
                  </button>
                </form>
              </td>
              <td class="px-3 py-2">
                <div class="font-medium"><?= htmlspecialchars($s['slot_label']); ?></div>
              </td>
              <td class="px-3 py-2">
                <?= htmlspecialchars(substr((string)$s['start_time'],0,5)); ?>–<?= htmlspecialchars(substr((string)$s['end_time'],0,5)); ?>
              </td>
              <td class="px-3 py-2"><?= $s['period_no']===null ? '—' : (int)$s['period_no']; ?></td>
              <td class="px-3 py-2 text-right">
                <details>
                  <summary class="cursor-pointer text-blue-700 hover:underline">แก้ไข</summary>
                  <div class="mt-2 p-3 rounded-xl border bg-slate-50 inline-block text-left">
                    <form method="post" class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                      <div class="md:col-span-5">
                        <label class="block text-xs mb-1">ชื่อ</label>
                        <input name="slot_label" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars($s['slot_label']); ?>">
                      </div>
                      <div class="md:col-span-2">
                        <label class="block text-xs mb-1">เริ่ม</label>
                        <input type="time" name="start_time" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars(substr((string)$s['start_time'],0,5)); ?>">
                      </div>
                      <div class="md:col-span-2">
                        <label class="block text-xs mb-1">จบ</label>
                        <input type="time" name="end_time" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars(substr((string)$s['end_time'],0,5)); ?>">
                      </div>
                      <div class="md:col-span-2">
                        <label class="block text-xs mb-1">คาบ</label>
                        <input type="number" name="period_no" class="w-full border rounded px-3 py-2" value="<?= $s['period_no']===null ? '' : (int)$s['period_no']; ?>" min="0" max="99">
                      </div>
                      <div class="md:col-span-1">
                        <button class="w-full px-3 py-2 rounded bg-slate-900 text-white">บันทึก</button>
                      </div>
                    </form>

                    <form method="post" class="mt-2" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบ', text: 'ลบช่วงเวลานี้?', confirmButtonText: 'ลบ' });">
                      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                      <button class="px-3 py-2 rounded border text-rose-700 border-rose-200 hover:bg-rose-50">ลบ (เฉพาะช่วงเวลาพิเศษ)</button>
                    </form>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
