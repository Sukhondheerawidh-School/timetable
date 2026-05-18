<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin(); requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM period_slots WHERE id=?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row){ flash_set('error','ไม่พบคาบ'); redirect('periods.php'); }

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
        // กันหมายเลขคาบชนกับแถวอื่น
        $chk = $pdo->prepare('SELECT id FROM period_slots WHERE period_no=? AND id<>?');
        $chk->execute([$no,$id]);
        if ($chk->fetch()) throw new Exception('หมายเลขคาบนี้มีอยู่แล้ว');

        $up=$pdo->prepare('UPDATE period_slots SET period_no=?, start_time=?, end_time=? WHERE id=?');
        $up->execute([$no,$st,$et,$id]);

        $oldData = $row;
        $newData = $row;
        $newData['period_no'] = $no;
        $newData['start_time'] = $st;
        $newData['end_time'] = $et;
        logUpdate('period_slots', $id, $oldData, $newData);
        flash_set('success','อัปเดตคาบสำเร็จ');
        redirect('periods.php');
      }catch(Throwable $e){ $err='ผิดพลาด: '.$e->getMessage(); }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-md mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขคาบ</h1>
  <?php if ($err): ?><div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">คาบที่</label>
      <input type="number" name="period_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" min="1" required
        value="<?= htmlspecialchars($_POST['period_no'] ?? $row['period_no']); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">เวลาเริ่ม</label>
      <input type="time" name="start_time" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required
        value="<?= htmlspecialchars($_POST['start_time'] ?? substr($row['start_time'],0,5)); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">เวลาสิ้นสุด</label>
      <input type="time" name="end_time" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required
        value="<?= htmlspecialchars($_POST['end_time'] ?? substr($row['end_time'],0,5)); ?>">
    </div>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <a href="<?= url('periods.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
