<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_buildings_init($pdo);
$buildings = tt_buildings_list($pdo, true);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $code  = trim($_POST['teacher_code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $group = ($_POST['subject_group'] ?? '') !== '' ? (int)$_POST['subject_group'] : null;
    $buildingIds = array_map('intval', (array)($_POST['building_ids'] ?? []));

    if ($code === '' || $first === '' || $last === '') {
      $err = 'กรอก รหัสประจำตัว, ชื่อ, นามสกุล ให้ครบ';
    } elseif (count($buildingIds) > 2) {
      $err = 'เลือกอาคารได้ไม่เกิน 2 อาคาร';
    } else {
      try {
        $stmt = $pdo->prepare('INSERT INTO teachers(teacher_code, title, first_name, last_name, subject_group) VALUES (?,?,?,?,?)');
        $stmt->execute([$code, $title, $first, $last, $group]);

        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) {
          tt_teacher_buildings_set($pdo, $newId, $buildingIds);
        }

        flash_set('success', 'เพิ่มครูสำเร็จ');
        redirect('teachers.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $err = 'รหัสประจำตัวนี้มีอยู่แล้ว';
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
  <h1 class="text-xl font-semibold mt-8 mb-4">เพิ่มครู</h1>

  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm mb-1">รหัสประจำตัว</label>
      <input name="teacher_code" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['teacher_code'] ?? ''); ?>">
    </div>

    <div>
      <label class="block text-sm mb-1">อาคารที่ไปประกฎ (เลือกได้ไม่เกิน 2)</label>
      <select name="building_ids[]" class="w-full border rounded-lg px-3 py-2" multiple size="<?= max(3, min(6, count($buildings))); ?>">
        <?php $selB = array_map('intval', (array)($_POST['building_ids'] ?? [])); ?>
        <?php foreach ($buildings as $b): ?>
          <option value="<?= (int)$b['id']; ?>" <?= in_array((int)$b['id'], $selB, true) ? 'selected' : ''; ?>>
            <?= htmlspecialchars((string)$b['building_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="text-xs text-slate-500 mt-1">กด Ctrl/Command เพื่อเลือกหลายรายการ</div>
    </div>
    <div>
      <label class="block text-sm mb-1">คำนำหน้า</label>
      <input name="title" class="w-full border rounded-lg px-3 py-2" placeholder="นาย / นาง / นางสาว / ครู / อ. ฯลฯ" value="<?= htmlspecialchars($_POST['title'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">ชื่อ</label>
      <input name="first_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['first_name'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">นามสกุล</label>
      <input name="last_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['last_name'] ?? ''); ?>">
    </div>

    <div>
      <label class="block text-sm mb-1">กลุ่มสาระการเรียนรู้</label>
      <select name="subject_group" class="w-full border rounded-lg px-3 py-2">
        <option value="">— ไม่ระบุ —</option>
        <?php foreach (teacher_group_options() as $k => $v): ?>
          <option value="<?= (int)$k; ?>" <?= (isset($_POST['subject_group']) && (int)$_POST['subject_group']===(int)$k)?'selected':''; ?>>
            <?= htmlspecialchars($v); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">บันทึก</button>
      <a href="<?= url('teachers.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
