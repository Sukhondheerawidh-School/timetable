<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';

requireLogin();
requireAdmin();

tt_terms_init($pdo);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $year_label = trim($_POST['year_label'] ?? '');
    $set_active = isset($_POST['is_active']) ? 1 : 0;

    if ($year_label === '' || !preg_match('/^[0-9]{4}$/', $year_label)) {
      $err = 'ปีการศึกษาต้องเป็นเลข 4 หลัก เช่น 2025';
    } else {
      try {
        $pdo->beginTransaction();

        // ถ้า set active -> ปิด active ของทุกปีอื่น
        if ($set_active) {
          $pdo->exec('UPDATE academic_years SET is_active = 0');
        }

        // สร้างปี
        $stmt = $pdo->prepare('INSERT INTO academic_years(year_label, is_active) VALUES (?, ?)');
        $stmt->execute([$year_label, $set_active]);
        $year_id = (int)$pdo->lastInsertId();

        // สร้างเทอมเริ่มต้น (แบบเดิม 2 เทอม) พร้อมช่วงเดือน
        try {
          $stmtT = $pdo->prepare('INSERT INTO terms(academic_year_id, term_no, term_name, start_month, end_month) VALUES (?,?,?,?,?)');
          $stmtT->execute([$year_id, 1, 'เทอม 1', 4, 9]);
          $stmtT->execute([$year_id, 2, 'เทอม 2', 10, 3]);
        } catch (Throwable $e) {
          // Fallback: old schema (term_no only)
          $stmtT = $pdo->prepare('INSERT INTO terms(academic_year_id, term_no) VALUES (?, ?)');
          $stmtT->execute([$year_id, 1]);
          $stmtT->execute([$year_id, 2]);
        }

        $pdo->commit();

        logCreate('academic_years', $year_id, [
          'year_label' => $year_label,
          'is_active' => $set_active,
        ]);
        flash_set('success', 'สร้างปีการศึกษา '.$year_label.' สำเร็จ (พร้อมเทอม 1/2)');
        redirect('years.php');
      } catch (Throwable $e) {
        $pdo->rollBack();
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $err = 'ปีการศึกษานี้มีอยู่แล้ว';
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
    <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มปีการศึกษา</h1>

    <?php if ($err): ?>
      <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

      <div>
        <label class="block text-sm mb-1">ปีการศึกษา</label>
        <input name="year_label" class="w-full border rounded-lg px-3 py-2" required placeholder="เช่น 2025" value="<?= htmlspecialchars($_POST['year_label'] ?? ''); ?>">
        <p class="text-xs text-slate-500 mt-1">ตัวเลข 4 หลัก (เช่น 2025)</p>
      </div>

      <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" class="w-4 h-4" <?= isset($_POST['is_active'])?'checked':''; ?>>
        <span class="text-sm">ตั้งเป็น Active (ปีที่ใช้งานในระบบตอนนี้)</span>
      </label>

      <div class="flex items-center gap-2 pt-2">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
        <a href="<?= url('years.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
      </div>
    </form>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
