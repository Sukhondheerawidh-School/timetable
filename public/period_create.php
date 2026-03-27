<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin(); requireAdmin();

$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    $no = (int)($_POST['period_no'] ?? 0);
    $st = trim($_POST['start_time'] ?? '');
    $et = trim($_POST['end_time'] ?? '');
    if ($no<1 || $st==='' || $et==='') $err='กรอกข้อมูลให้ครบ';
    elseif (strtotime($st) >= strtotime($et)) $err='เวลาเริ่มต้องน้อยกว่าเวลาสิ้นสุด';
    else {
      try{
        $stmt=$pdo->prepare('INSERT INTO period_slots(period_no,start_time,end_time) VALUES (?,?,?)');
        $stmt->execute([$no,$st,$et]);

        logCreate('period_slots', (int)$pdo->lastInsertId(), [
          'period_no' => $no,
          'start_time' => $st,
          'end_time' => $et,
        ]);
        flash_set('success','เพิ่มคาบสำเร็จ');
        redirect('periods.php');
      }catch(Throwable $e){
        if (str_contains($e->getMessage(),'Duplicate')) $err='หมายเลขคาบนี้มีอยู่แล้ว';
        else $err='ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-md mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มคาบ</h1>
  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm mb-1">คาบที่ (ตัวเลข)</label>
      <input type="number" name="period_no" class="w-full border rounded-lg px-3 py-2" min="1" required value="<?= htmlspecialchars($_POST['period_no'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">เวลาเริ่ม</label>
      <input type="time" name="start_time" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['start_time'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">เวลาสิ้นสุด</label>
      <input type="time" name="end_time" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['end_time'] ?? ''); ?>">
    </div>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('periods.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
