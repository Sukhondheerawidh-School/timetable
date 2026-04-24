<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
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

        $oldData = $teacher;
        $oldData['building_ids'] = $currentBuildingIds;
        $newData = $teacher;
        $newData['teacher_code'] = $code;
        $newData['title'] = $title;
        $newData['first_name'] = $first;
        $newData['last_name'] = $last;
        $newData['subject_group'] = $group;
        $newData['building_ids'] = $buildingIds;
        logUpdate('teachers', $id, $oldData, $newData);

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
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">รหัสประจำตัว</label>
      <input name="teacher_code" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['teacher_code'] ?? $teacher['teacher_code']); ?>">
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">อาคารที่ไปประกฎ (เลือกได้ไม่เกิน 2)</label>
      <?php $selB = array_map('intval', (array)($_POST['building_ids'] ?? $currentBuildingIds)); ?>
      <select name="building_ids[]" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" multiple size="<?= max(3, min(6, count($buildings))); ?>">
        <?php foreach ($buildings as $b): ?>
          <option value="<?= (int)$b['id']; ?>" <?= in_array((int)$b['id'], $selB, true) ? 'selected' : ''; ?>>
            <?= htmlspecialchars((string)$b['building_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="text-xs text-slate-500 mt-1">กด Ctrl/Command เพื่อเลือกหลายรายการ</div>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">คำนำหน้า</label>
      <input name="title" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" value="<?= htmlspecialchars($_POST['title'] ?? $teacher['title']); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อ</label>
      <input name="first_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['first_name'] ?? $teacher['first_name']); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">นามสกุล</label>
      <input name="last_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['last_name'] ?? $teacher['last_name']); ?>">
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">กลุ่มสาระการเรียนรู้</label>
      <?php $cur = $_POST['subject_group'] ?? $teacher['subject_group']; ?>
      <select name="subject_group" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
        <option value="">— ไม่ระบุ —</option>
        <?php foreach (teacher_group_options() as $k => $v): ?>
          <option value="<?= (int)$k; ?>" <?= ((string)$cur !== '' && (int)$cur === (int)$k)?'selected':''; ?>>
            <?= htmlspecialchars($v); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <a href="<?= url('teachers.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
