<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

tt_teachers_init($pdo);
tt_buildings_init($pdo);
$buildings = tt_buildings_list($pdo, true);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $code     = trim($_POST['teacher_code'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name'] ?? '');
    $firstEn  = trim($_POST['first_name_en'] ?? '');
    $lastEn   = trim($_POST['last_name_en'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $group = ($_POST['subject_group'] ?? '') !== '' ? (int)$_POST['subject_group'] : null;
    $buildingIds = array_map('intval', (array)($_POST['building_ids'] ?? []));

    if ($code === '' || $first === '' || $last === '') {
      $err = 'กรอก รหัสประจำตัว, ชื่อ, นามสกุล ให้ครบ';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (count($buildingIds) > 2) {
      $err = 'เลือกอาคารได้ไม่เกิน 2 อาคาร';
    } else {
      try {
        $passHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $stmt = $pdo->prepare('INSERT INTO teachers(teacher_code, title, first_name, last_name, first_name_en, last_name_en, email, password_hash, subject_group) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$code, $title, $first, $last, ($firstEn !== '' ? $firstEn : null), ($lastEn !== '' ? $lastEn : null), ($email !== '' ? $email : null), $passHash, $group]);

        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) {
          tt_teacher_buildings_set($pdo, $newId, $buildingIds);
        }

        logCreate('teachers', $newId, [
          'teacher_code' => $code,
          'title' => $title,
          'first_name' => $first,
          'last_name' => $last,
          'first_name_en' => $firstEn,
          'last_name_en' => $lastEn,
          'email' => $email,
          'has_password' => $password !== '',
          'subject_group' => $group,
          'building_ids' => $buildingIds,
        ]);

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
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">รหัสประจำตัว</label>
      <input name="teacher_code" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['teacher_code'] ?? ''); ?>">
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">อาคารที่ประจำ (เลือกได้ไม่เกิน 2 · ไม่ต้องเลือกก็ได้)</label>
      <?php $selB = array_map('intval', (array)($_POST['building_ids'] ?? [])); ?>
      <?php if (empty($buildings)): ?>
        <div class="text-xs text-slate-400">— ยังไม่มีอาคาร —</div>
      <?php else: ?>
      <div class="flex flex-wrap gap-3">
        <?php foreach ($buildings as $b): ?>
          <label class="inline-flex items-center gap-1.5 cursor-pointer">
            <input type="checkbox" name="building_ids[]" value="<?= (int)$b['id']; ?>"
              <?= in_array((int)$b['id'], $selB, true) ? 'checked' : ''; ?>
              class="w-4 h-4 rounded border-slate-300 accent-indigo-600">
            <span class="text-sm text-slate-700"><?= htmlspecialchars((string)$b['building_name']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="text-xs text-slate-500 mt-1">ถ้าไม่ประจำอาคารใด ไม่ต้องติ๊กก็ได้</div>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">คำนำหน้า</label>
      <input name="title" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="นาย / นาง / นางสาว / ครู / อ. ฯลฯ" value="<?= htmlspecialchars($_POST['title'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อ</label>
      <input name="first_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['first_name'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">นามสกุล</label>
      <input name="last_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required value="<?= htmlspecialchars($_POST['last_name'] ?? ''); ?>">
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ชื่อจริง (ภาษาอังกฤษ)</label>
        <input name="first_name_en" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="เช่น Somchai" value="<?= htmlspecialchars($_POST['first_name_en'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">นามสกุล (ภาษาอังกฤษ)</label>
        <input name="last_name_en" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="เช่น Jaidee" value="<?= htmlspecialchars($_POST['last_name_en'] ?? ''); ?>">
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">อีเมล</label>
      <input type="email" name="email" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="เช่น somchai@example.com" value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>">
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">รหัสผ่าน <span class="text-slate-400 font-normal">(สำหรับเชื่อมต่อ API ระบบอื่น)</span></label>
      <input type="password" name="password" autocomplete="new-password" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="เว้นว่างได้ถ้ายังไม่กำหนด">
      <p class="text-xs text-slate-500 mt-1">ระบบจะเก็บเป็นค่าที่เข้ารหัส (hash) ไม่สามารถดูย้อนหลังได้</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">กลุ่มสาระการเรียนรู้</label>
      <select name="subject_group" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
        <option value="">— ไม่ระบุ —</option>
        <?php foreach (teacher_group_options() as $k => $v): ?>
          <option value="<?= (int)$k; ?>" <?= (isset($_POST['subject_group']) && (int)$_POST['subject_group']===(int)$k)?'selected':''; ?>>
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
