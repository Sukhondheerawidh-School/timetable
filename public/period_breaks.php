<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

// เอาระดับชั้น (distinct) จาก classes
$grades = $pdo->query('SELECT DISTINCT grade_label FROM classes ORDER BY grade_label')->fetchAll(PDO::FETCH_COLUMN);
// คาบทั้งหมด
$periods = $pdo->query('SELECT period_no FROM period_slots ORDER BY period_no')->fetchAll(PDO::FETCH_COLUMN);

// ดึง break ที่เคยตั้งไว้ทั้งหมด
$stmt = $pdo->query('SELECT grade_label, period_no FROM grade_breaks');
$rows = $stmt->fetchAll();
$selected = [];
foreach ($rows as $r) {
  $g = $r['grade_label']; $p = (int)$r['period_no'];
  $selected[$g][$p] = true;
}

$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    try{
      $pdo->beginTransaction();
      // โพสต์เป็น breaks[grade][] = period_no
      $breaks = (array)($_POST['breaks'] ?? []);
      // ลบของเดิมทั้งหมดก่อน เพื่อความง่าย
      $pdo->exec('DELETE FROM grade_breaks');
      // เพิ่มใหม่
      $ins = $pdo->prepare('INSERT INTO grade_breaks(grade_label, period_no) VALUES (?,?)');
      foreach ($breaks as $grade => $plist) {
        foreach ((array)$plist as $pno) {
          $pno = (int)$pno;
          if ($pno>0) $ins->execute([$grade,$pno]);
        }
      }
      $pdo->commit();
      flash_set('success','บันทึกคาบพักสำเร็จ');
      redirect('period_breaks.php');
    }catch(Throwable $e){
      $pdo->rollBack();
      $err='ผิดพลาด: '.$e->getMessage();
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-5xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">กำหนดคาบพักตามระดับชั้น</h1>
    <div class="text-sm text-slate-500">ติ๊กคาบที่เป็น “พัก” ของแต่ละระดับชั้น แล้วกดบันทึก</div>
  </div>

  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-4 overflow-x-auto">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <table class="min-w-full text-sm">
      <thead>
        <tr>
          <th class="text-left px-4 py-3">ระดับชั้น</th>
          <?php foreach ($periods as $pno): ?>
            <th class="text-center px-2 py-3">คาบ <?= (int)$pno; ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grades as $g): ?>
          <tr class="border-t">
            <td class="px-4 py-2 font-medium"><?= htmlspecialchars($g); ?></td>
            <?php foreach ($periods as $pno): ?>
              <?php $checked = !empty($selected[$g][(int)$pno]); ?>
              <td class="px-2 py-2 text-center">
                <input type="checkbox" name="breaks[<?= htmlspecialchars($g); ?>][]" value="<?= (int)$pno; ?>" <?= $checked?'checked':''; ?>>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>

        <?php if (!$grades): ?>
          <tr><td colspan="<?= 1+count($periods); ?>" class="px-4 py-6 text-center text-slate-500">ยังไม่มีข้อมูลชั้นเรียน (โปรดสร้างชั้นในเมนู “ชั้นเรียน”)</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="flex items-center gap-2 mt-4">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('periods.php'); ?>" class="px-4 py-2 rounded-xl border">กลับไปคาบเรียน</a>
    </div>
  </form>

  <p class="text-xs text-slate-500 mt-3">
    ตัวอย่าง: ถ้าต้องการให้ “ม1” และ “ม2” พักคาบ 4 ให้ติ๊กคอลัมน์ “คาบ 4” เฉพาะแถว “ม1” และ “ม2”
  </p>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
