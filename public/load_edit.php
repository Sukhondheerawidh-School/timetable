<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);

// ✅ รับค่าฟิลเตอร์จาก GET
$return_year_id = isset($_GET['year_id']) && $_GET['year_id'] !== '' ? (int)$_GET['year_id'] : 0;
$return_term_no = isset($_GET['term_no']) && $_GET['term_no'] !== '' ? (int)$_GET['term_no'] : 0;
$return_group = isset($_GET['group']) && $_GET['group'] !== '' ? (int)$_GET['group'] : '';
$return_teacher_id = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? (int)$_GET['teacher_id'] : '';
$return_page = isset($_GET['page']) && $_GET['page'] !== '' ? (int)$_GET['page'] : 1;

if (!$id) {
  flash_set('error', 'ไม่พบ ID');
  redirect('loads.php');
}

$stmt = $pdo->prepare('SELECT * FROM teaching_loads WHERE id = ?');
$stmt->execute([$id]);
$load = $stmt->fetch();

if (!$load) {
  flash_set('error', 'ไม่พบกำลังสอน');
  redirect('loads.php');
}

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$teachers = $pdo->query('SELECT id, first_name, last_name FROM teachers ORDER BY first_name, last_name')->fetchAll();
$subjects = $pdo->query('SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code')->fetchAll();
$classes = $pdo->query('SELECT id, class_name FROM classes ORDER BY class_name')->fetchAll();
$rooms = $pdo->query('SELECT id, room_code, room_name FROM rooms ORDER BY room_code')->fetchAll();

$formYearId = (int)($_POST['academic_year_id'] ?? $load['academic_year_id']);
$formTermNo = (int)($_POST['term_no'] ?? $load['term_no']);
$termOptions = tt_terms_list($pdo, $formYearId);
$formTermNo = tt_validate_term_no($pdo, $formYearId, $formTermNo);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $year_id = (int)($_POST['academic_year_id'] ?? 0);
    $term_no = (int)($_POST['term_no'] ?? 0);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $room_id = ($_POST['room_id'] ?? '') !== '' ? (int)$_POST['room_id'] : null;
    $periods = (int)($_POST['periods_per_week'] ?? 0);
    $consec = min(2, max(1, (int)($_POST['consecutive_slots'] ?? 1)));

    // ✅ รับค่าฟิลเตอร์จาก POST (hidden inputs)
    $return_year_id = isset($_POST['return_year_id']) && $_POST['return_year_id'] !== '' ? (int)$_POST['return_year_id'] : 0;
    $return_term_no = isset($_POST['return_term_no']) && $_POST['return_term_no'] !== '' ? (int)$_POST['return_term_no'] : 0;
    $return_group = isset($_POST['return_group']) && $_POST['return_group'] !== '' ? (int)$_POST['return_group'] : '';
    $return_teacher_id = isset($_POST['return_teacher_id']) && $_POST['return_teacher_id'] !== '' ? (int)$_POST['return_teacher_id'] : '';
    $return_page = isset($_POST['return_page']) && $_POST['return_page'] !== '' ? (int)$_POST['return_page'] : 1;

    if (!$year_id || !$term_no || !$teacher_id || !$subject_id || !$class_id) {
      $err = 'กรอกข้อมูลให้ครบ';
    } elseif ($periods <= 0) {
      $err = 'จำนวนคาบ/สัปดาห์ ต้องมากกว่า 0';
    } else {
      try {
        // ✅ เก็บข้อมูลเดิม
        $oldData = [
          'teacher_id' => $load['teacher_id'],
          'subject_id' => $load['subject_id'],
          'class_id' => $load['class_id'],
          'room_id' => $load['room_id'],
          'periods_per_week' => $load['periods_per_week'],
          'consecutive_slots' => $load['consecutive_slots']
        ];

        $stmt = $pdo->prepare('
          UPDATE teaching_loads 
          SET academic_year_id=?, term_no=?, teacher_id=?, subject_id=?, class_id=?, room_id=?, periods_per_week=?, consecutive_slots=?
          WHERE id=?
        ');
        $stmt->execute([$year_id, $term_no, $teacher_id, $subject_id, $class_id, $room_id, $periods, $consec, $id]);

        // ✅ ดึงข้อมูลใหม่สำหรับ log
        $teacherName = '';
        foreach ($teachers as $t) {
          if ((int)$t['id'] === $teacher_id) {
            $teacherName = $t['first_name'] . ' ' . $t['last_name'];
            break;
          }
        }

        $subjectName = '';
        $subjectCode = '';
        foreach ($subjects as $s) {
          if ((int)$s['id'] === $subject_id) {
            $subjectCode = $s['subject_code'];
            $subjectName = $s['subject_name'];
            break;
          }
        }

        $className = '';
        foreach ($classes as $c) {
          if ((int)$c['id'] === $class_id) {
            $className = $c['class_name'];
            break;
          }
        }

        $roomName = '';
        if ($room_id) {
          foreach ($rooms as $r) {
            if ((int)$r['id'] === $room_id) {
              $roomName = $r['room_code'] . ' - ' . $r['room_name'];
              break;
            }
          }
        }

        // ✅ บันทึก log
        logUpdate('teaching_loads', $id, $oldData, [
          'teacher_id' => $teacher_id,
          'teacher_name' => $teacherName,
          'subject_id' => $subject_id,
          'subject_name' => ($subjectCode ? $subjectCode . ' - ' : '') . $subjectName,
          'class_id' => $class_id,
          'class_name' => $className,
          'room_id' => $room_id,
          'room_name' => $roomName ?: '-',
          'periods_per_week' => $periods,
          'consecutive_slots' => $consec
        ]);

        flash_set('success', 'แก้ไขเรียบร้อย');
        
        // ✅ สร้าง redirect URL พร้อมฟิลเตอร์
        $queryParams = [];
        if ($return_year_id > 0) $queryParams['year_id'] = $return_year_id;
        if ($return_term_no > 0) $queryParams['term_no'] = $return_term_no;
        if ($return_group !== '') $queryParams['group'] = $return_group;
        if ($return_teacher_id !== '') $queryParams['teacher_id'] = $return_teacher_id;
        if ($return_page > 1) $queryParams['page'] = $return_page;
        
        $queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
        redirect('loads.php' . $queryString);
      } catch (Throwable $e) {
        $err = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-3xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">แก้ไขกำลังสอน</h1>
  
  <?php if ($err): ?>
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    
    <!-- ✅ ส่งค่าฟิลเตอร์กลับไปด้วย -->
    <input type="hidden" name="return_year_id" value="<?= $return_year_id ?>">
    <input type="hidden" name="return_term_no" value="<?= $return_term_no ?>">
    <input type="hidden" name="return_group" value="<?= $return_group !== '' ? $return_group : '' ?>">
    <input type="hidden" name="return_teacher_id" value="<?= $return_teacher_id !== '' ? $return_teacher_id : '' ?>">
    <input type="hidden" name="return_page" value="<?= $return_page ?>">

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ปีการศึกษา</label>
        <select name="academic_year_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id'] === (int)$formYearId ? 'selected' : ''; ?>>
              <?= htmlspecialchars($y['year_label']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">เทอม</label>
        <select name="term_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($termOptions as $t): ?>
            <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$formTermNo) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ครู</label>
        <select name="teacher_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($teachers as $t): ?>
            <option value="<?= (int)$t['id']; ?>" <?= (int)$t['id'] === (int)$load['teacher_id'] ? 'selected' : ''; ?>>
              <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">วิชา</label>
        <select name="subject_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id']; ?>" <?= (int)$s['id'] === (int)$load['subject_id'] ? 'selected' : ''; ?>>
              <?= htmlspecialchars($s['subject_code'] . ' - ' . $s['subject_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชั้น/ห้อง</label>
      <select name="class_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int)$c['id']; ?>" <?= (int)$c['id'] === (int)$load['class_id'] ? 'selected' : ''; ?>>
            <?= htmlspecialchars($c['class_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ห้องเรียน (ถ้ามี)</label>
        <select name="room_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="">— ใช้ค่าห้องประจำ/ไม่กำหนด —</option>
          <?php foreach ($rooms as $r): ?>
            <option value="<?= (int)$r['id']; ?>" <?= $load['room_id'] && (int)$r['id'] === (int)$load['room_id'] ? 'selected' : ''; ?>>
              <?= htmlspecialchars($r['room_code'] . ' - ' . $r['room_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">คาบ/สัปดาห์</label>
        <input type="number" name="periods_per_week" min="1" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" value="<?= (int)$load['periods_per_week']; ?>" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">คาบติดกัน</label>
        <select name="consecutive_slots" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="1" <?= (int)$load['consecutive_slots'] === 1 ? 'selected' : ''; ?>>ไม่ติดกัน</option>
          <option value="2" <?= (int)$load['consecutive_slots'] === 2 ? 'selected' : ''; ?>>2 คาบติด</option>
        </select>
      </div>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <?php
      // ✅ สร้าง URL สำหรับปุ่มยกเลิก
      $cancelParams = [];
      if ($return_year_id > 0) $cancelParams['year_id'] = $return_year_id;
      if ($return_term_no > 0) $cancelParams['term_no'] = $return_term_no;
      if ($return_group !== '') $cancelParams['group'] = $return_group;
      if ($return_teacher_id !== '') $cancelParams['teacher_id'] = $return_teacher_id;
      if ($return_page > 1) $cancelParams['page'] = $return_page;
      $cancelUrl = 'loads.php' . (!empty($cancelParams) ? '?' . http_build_query($cancelParams) : '');
      ?>
      <a href="<?= url($cancelUrl); ?>" class="px-4 py-2 rounded-xl border hover:bg-slate-50">ยกเลิก</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
