<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';

requireLogin();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, year_label, is_active FROM academic_years WHERE id = ?');
$stmt->execute([$id]);
$year = $stmt->fetch();
if (!$year) { flash_set('error','ไม่พบปีการศึกษา'); redirect('years.php'); }

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

        if ($set_active) {
          $pdo->exec('UPDATE academic_years SET is_active = 0');
        }

        $stmtU = $pdo->prepare('UPDATE academic_years SET year_label = ?, is_active = ? WHERE id = ?');
        $stmtU->execute([$year_label, $set_active, $id]);

        $pdo->commit();

        $oldData = $year;
        $newData = $year;
        $newData['year_label'] = $year_label;
        $newData['is_active'] = $set_active;
        logUpdate('academic_years', $id, $oldData, $newData);
        flash_set('success', 'อัปเดตปีการศึกษาเรียบร้อย');
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
    <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขปีการศึกษา</h1>

    <?php if ($err): ?>
      <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ปีการศึกษา</label>
        <input name="year_label" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['year_label'] ?? $year['year_label']); ?>">
      </div>

      <label class="inline-flex items-center gap-2">
        <?php $checked = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : (bool)$year['is_active']; ?>
        <input type="checkbox" name="is_active" class="w-4 h-4" <?= $checked ? 'checked':''; ?>>
        <span class="text-sm">ตั้งเป็น Active</span>
      </label>

      <div class="flex items-center gap-2 pt-2">
        <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
        <a href="<?= url('years.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
      </div>
    </form>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
