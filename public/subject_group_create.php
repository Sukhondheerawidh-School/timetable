<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $name = trim($_POST['name'] ?? '');
    $display_order = (int)($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
      $err = 'กรุณากรอกชื่อกลุ่มสาระ';
    } else {
      try {
        $stmt = $pdo->prepare('INSERT INTO subject_groups (name, display_order, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$name, $display_order, $is_active]);
        flash_set('success', 'เพิ่มกลุ่มสาระสำเร็จ');
        redirect('subject_groups.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $err = 'ชื่อกลุ่มสาระนี้มีอยู่แล้ว';
        } else {
          $err = 'ผิดพลาด: ' . $e->getMessage();
        }
      }
    }
  }
}

// ดึงลำดับถัดไปแนะนำ
$next_order = $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM subject_groups')->fetch()['next_order'];
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-2xl mx-auto px-4">
  <div class="mt-8 mb-6">
    <h1 class="text-2xl font-bold text-slate-800">➕ เพิ่มกลุ่มสาระการเรียนรู้</h1>
    <p class="text-sm text-slate-500 mt-1">สร้างกลุ่มสาระใหม่สำหรับจัดหมวดครู</p>
  </div>

  <?php if ($err): ?>
    <div class="mb-6 p-4 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 text-sm font-medium shadow-sm">
      ❌ <?= htmlspecialchars($err); ?>
    </div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow-xl border border-slate-200 p-8 space-y-6">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm font-semibold text-slate-700 mb-2">
        📚 ชื่อกลุ่มสาระ <span class="text-rose-500">*</span>
      </label>
      <input 
        name="name" 
        class="w-full border-2 border-slate-200 rounded-xl px-4 py-3 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none" 
        required 
        placeholder="เช่น กลุ่มสาระการเรียนรู้คณิตศาสตร์"
        value="<?= htmlspecialchars($_POST['name'] ?? ''); ?>">
      <p class="text-xs text-slate-500 mt-2">💡 ชื่อกลุ่มสาระต้องไม่ซ้ำกัน</p>
    </div>

    <div>
      <label class="block text-sm font-semibold text-slate-700 mb-2">
        🔢 ลำดับการแสดงผล
      </label>
      <input 
        name="display_order" 
        type="number" 
        min="0"
        class="w-full border-2 border-slate-200 rounded-xl px-4 py-3 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none" 
        value="<?= (int)($_POST['display_order'] ?? $next_order); ?>">
      <p class="text-xs text-slate-500 mt-2">💡 ใช้สำหรับเรียงลำดับในรายการต่างๆ (เลขน้อยขึ้นก่อน)</p>
    </div>

    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
      <label class="flex items-center gap-3 cursor-pointer">
        <input 
          type="checkbox" 
          name="is_active" 
          value="1"
          class="w-5 h-5 rounded text-indigo-600 focus:ring-2 focus:ring-indigo-500"
          <?= isset($_POST['is_active']) || !isset($_POST['name']) ? 'checked' : ''; ?>>
        <div>
          <span class="text-sm font-semibold text-slate-700">✅ เปิดใช้งาน</span>
          <p class="text-xs text-slate-500 mt-0.5">กลุ่มสาระที่ไม่เปิดใช้งานจะไม่แสดงในตัวเลือก</p>
        </div>
      </label>
    </div>

    <div class="flex items-center gap-3 pt-4 border-t">
      <button type="submit" class="flex-1 px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 text-white font-medium hover:from-indigo-700 hover:to-indigo-600 shadow-lg shadow-indigo-500/30 transition-all duration-200">
        💾 บันทึก
      </button>
      <a href="<?= url('subject_groups.php'); ?>" class="px-6 py-3 rounded-xl border-2 border-slate-300 hover:bg-slate-50 transition-colors font-medium text-slate-700">
        ❌ ยกเลิก
      </a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
