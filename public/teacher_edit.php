<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_buildings_init($pdo);
$buildings = tt_buildings_list($pdo, true);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM teachers WHERE id = ?');
$stmt->execute([$id]);
$teacher = $stmt->fetch();
if (!$teacher) { flash_set('error','ไม่พบข้อมูลครู'); redirect('teachers.php'); }

$currentBuildingIds = tt_teacher_buildings_get($pdo, $id);

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
    $buildingIds = array_map('intval', (array)($_POST['building_ids'] ?? $currentBuildingIds));

    if ($code === '' || $first === '' || $last === '') {
      $err = 'กรอก รหัสประจำตัว, ชื่อ, นามสกุล ให้ครบ';
    } elseif (count($buildingIds) > 2) {
      $err = 'เลือกอาคารได้ไม่เกิน 2 อาคาร';
    } else {
      try {
        $stmtU = $pdo->prepare('UPDATE teachers SET teacher_code=?, title=?, first_name=?, last_name=?, subject_group=? WHERE id=?');
        $stmtU->execute([$code, $title, $first, $last, $group, $id]);

        tt_teacher_buildings_set($pdo, $id, $buildingIds);

        flash_set('success', 'อัปเดตข้อมูลครูสำเร็จ');
        redirect('teachers.php');
      } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
          $err = 'รหัสประจำตัวนี้ซ้ำกับคนอื่น';
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
  <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขครู</h1>

  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm mb-1">รหัสประจำตัว</label>
      <input name="teacher_code" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['teacher_code'] ?? $teacher['teacher_code']); ?>">
    </div>

    <div>
      <label class="block text-sm mb-1">อาคารที่ไปประกฎ (เลือกได้ไม่เกิน 2)</label>
      <?php $selB = array_map('intval', (array)($_POST['building_ids'] ?? $currentBuildingIds)); ?>
      <select name="building_ids[]" class="w-full border rounded-lg px-3 py-2" multiple size="<?= max(3, min(6, count($buildings))); ?>">
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
      <input name="title" class="w-full border rounded-lg px-3 py-2" value="<?= htmlspecialchars($_POST['title'] ?? $teacher['title']); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">ชื่อ</label>
      <input name="first_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['first_name'] ?? $teacher['first_name']); ?>">
    </div>
    <div>
      <label class="block text-sm mb-1">นามสกุล</label>
      <input name="last_name" class="w-full border rounded-lg px-3 py-2" required value="<?= htmlspecialchars($_POST['last_name'] ?? $teacher['last_name']); ?>">
    </div>

    <div>
      <label class="block text-sm mb-1">กลุ่มสาระการเรียนรู้</label>
      <?php $cur = $_POST['subject_group'] ?? $teacher['subject_group']; ?>
      <select name="subject_group" class="w-full border rounded-lg px-3 py-2">
        <option value="">— ไม่ระบุ —</option>
        <?php foreach (teacher_group_options() as $k => $v): ?>
          <option value="<?= (int)$k; ?>" <?= ((string)$cur !== '' && (int)$cur === (int)$k)?'selected':''; ?>>
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
