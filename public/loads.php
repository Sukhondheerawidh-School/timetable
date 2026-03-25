<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';

requireLogin();

$isAdmin = (currentUser()['role'] ?? '') === 'admin';

/** ดึงปีการศึกษาและเทอม */
$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();

// ✅ หาปีที่ตั้งเป็น Active (is_active = 1) ก่อน
$activeYearId = 0;
foreach ($years as $y) {
  if ($y['is_active']) {
    $activeYearId = (int)$y['id'];
    break;
  }
}
// ถ้าไม่มีปี Active ให้ใช้ปีแรกสุด
if (!$activeYearId && !empty($years)) {
  $activeYearId = (int)$years[0]['id'];
}

// ใช้ปี Active เป็นค่าเริ่มต้น
$year_id = (int)($_GET['year_id'] ?? $activeYearId);

/** กำหนดค่าเริ่มต้นเทอมตามเดือนปัจจุบันถ้าไม่ส่งมา (อิงเทอมที่กำหนดในปี) */
if (isset($_GET['term_no']) && $_GET['term_no'] !== '') {
  $term_no = tt_validate_term_no($pdo, $year_id, (int)$_GET['term_no']);
} else {
  $term_no = tt_validate_term_no($pdo, $year_id, tt_default_term_no_for_year($pdo, $year_id));
}

$termOptions = tt_terms_list($pdo, $year_id);

/** ฟิลเตอร์เสริม */
$group = isset($_GET['group']) && $_GET['group'] !== '' ? (int)$_GET['group'] : null;
$teacher_id = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? (int)$_GET['teacher_id'] : null;

// ✅ Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// ✅ สร้าง query parameters สำหรับส่งต่อ
$currentFilterParams = [
  'year_id' => $year_id,
  'term_no' => $term_no
];
if ($group !== null) $currentFilterParams['group'] = $group;
if ($teacher_id !== null) $currentFilterParams['teacher_id'] = $teacher_id;
if ($page > 1) $currentFilterParams['page'] = $page;

// ✅ จัดการการลบทั้งหมด
$flash = flash_get();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
  if (!$isAdmin) {
    flash_set('error', 'เฉพาะผู้ดูแลระบบเท่านั้นที่ลบทั้งหมดได้');
    redirect('loads.php?year_id='.$year_id.'&term_no='.$term_no);
  }
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    try {
      $pdo->beginTransaction();
      
      // ✅ ดึงข้อมูลก่อนลบเพื่อบันทึก log
      $stmt = $pdo->prepare("
        SELECT tl.id, t.first_name, t.last_name, s.subject_name, c.class_name, tl.periods_per_week
        FROM teaching_loads tl
        JOIN teachers t ON t.id = tl.teacher_id
        JOIN subjects s ON s.id = tl.subject_id
        JOIN classes c ON c.id = tl.class_id
        WHERE tl.academic_year_id = ? AND tl.term_no = ?
      ");
      $stmt->execute([$year_id, $term_no]);
      $loadsToDelete = $stmt->fetchAll();
      
      // ลบกำลังสอนทั้งหมดของปี/เทอมนี้
      $stmt = $pdo->prepare("DELETE FROM teaching_loads WHERE academic_year_id = ? AND term_no = ?");
      $stmt->execute([$year_id, $term_no]);
      $deleted = $stmt->rowCount();
      
      $pdo->commit();
      
      // ✅ บันทึก log การลบทั้งหมด
      $yearLabel = '';
      foreach ($years as $y) {
        if ((int)$y['id'] === $year_id) {
          $yearLabel = $y['year_label'];
          break;
        }
      }
      
      logActivity(
        'delete_all_loads',
        'teaching_loads',
        null,
        [
          'year_id' => $year_id,
          'year_label' => $yearLabel,
          'term_no' => $term_no,
          'deleted_count' => $deleted,
          'loads' => array_map(function($load) {
            return [
              'id' => $load['id'],
              'teacher' => $load['first_name'] . ' ' . $load['last_name'],
              'subject' => $load['subject_name'],
              'class' => $load['class_name'],
              'periods' => $load['periods_per_week']
            ];
          }, $loadsToDelete)
        ],
        null
      );
      
      flash_set('success', "ลบกำลังสอนทั้งหมดเรียบร้อย ({$deleted} รายการ)");
      redirect('loads.php?year_id='.$year_id.'&term_no='.$term_no);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'เกิดข้อผิดพลาด: '.$e->getMessage());
    }
  }
}

$teachersSql = 'SELECT id, first_name, last_name, subject_group FROM teachers';
$paramsT = [];
if ($group !== null) { $teachersSql .= ' WHERE subject_group = :grp'; $paramsT['grp'] = $group; }
$teachersSql .= ' ORDER BY first_name, last_name';
$stT = $pdo->prepare($teachersSql);
$stT->execute($paramsT);
$teachers = $stT->fetchAll();

$subjects = $pdo->query('SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code')->fetchAll();

/** ✅ นับจำนวนรายการทั้งหมดก่อน */
$countSql = <<<SQL
SELECT COUNT(*) 
FROM teaching_loads tl
JOIN teachers t ON t.id = tl.teacher_id
WHERE tl.academic_year_id = :year_id AND tl.term_no = :term_no
SQL;
$countParams = ['year_id'=>$year_id, 'term_no'=>$term_no];
if ($group !== null) { $countSql .= ' AND t.subject_group = :grp'; $countParams['grp'] = $group; }
if ($teacher_id)    { $countSql .= ' AND t.id = :tid'; $countParams['tid'] = $teacher_id; }
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$total_records = (int)$countStmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

/** ✅ รายการกำลังสอนตามฟิลเตอร์ + Pagination */
$sql = <<<SQL
SELECT tl.id, tl.periods_per_week, tl.consecutive_slots,
       t.id AS teacher_id, t.first_name, t.last_name, t.subject_group,
       s.subject_code, s.subject_name,
       c.class_name,
       r.room_name
FROM teaching_loads tl
JOIN teachers t ON t.id = tl.teacher_id
JOIN subjects s ON s.id = tl.subject_id
JOIN classes  c ON c.id = tl.class_id
LEFT JOIN rooms r ON r.id = tl.room_id
WHERE tl.academic_year_id = :year_id AND tl.term_no = :term_no
SQL;
$params = ['year_id'=>$year_id, 'term_no'=>$term_no];
if ($group !== null) { $sql .= ' AND t.subject_group = :grp'; $params['grp'] = $group; }
if ($teacher_id)    { $sql .= ' AND t.id = :tid'; $params['tid'] = $teacher_id; }
$sql .= ' ORDER BY t.first_name, t.last_name, s.subject_code, c.class_name';
$sql .= ' LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql); 
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->execute();
$loads = $stmt->fetchAll();

/** Dashboard รวมคาบ/สัปดาห์ต่อครู */
$sqlSum = <<<SQL
SELECT t.id AS teacher_id, t.first_name, t.last_name, t.subject_group,
       SUM(tl.periods_per_week) AS total_periods
FROM teaching_loads tl
JOIN teachers t ON t.id = tl.teacher_id
WHERE tl.academic_year_id = :year_id AND tl.term_no = :term_no
SQL;
$params2 = ['year_id'=>$year_id, 'term_no'=>$term_no];
if ($group !== null) { $sqlSum .= ' AND t.subject_group = :grp'; $params2['grp'] = $group; }
if ($teacher_id)    { $sqlSum .= ' AND t.id = :tid'; $params2['tid'] = $teacher_id; }
$sqlSum .= ' GROUP BY t.id, t.first_name, t.last_name, t.subject_group ORDER BY t.first_name, t.last_name';
$stmt2 = $pdo->prepare($sqlSum); $stmt2->execute($params2);
$summary = $stmt2->fetchAll();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <div>
      <h1 class="text-xl font-semibold">กำหนดกำลังสอน</h1>
      <?php if ($total_records > 0): ?>
        <p class="text-sm text-slate-500 mt-1">
          แสดง <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $per_page, $total_records)) ?> 
          จากทั้งหมด <?= number_format($total_records) ?> รายการ
        </p>
      <?php endif; ?>
    </div>
    <div class="flex gap-2">
      <a href="<?= url('load_copy.php?target_year_id='.(int)$year_id.'&target_term_no='.(int)$term_no); ?>" class="px-3 py-2 rounded-xl border hover:bg-slate-50 text-sm">คัดลอกจากเทอมก่อน</a>
      <a href="<?= url('load_create.php'); ?>" class="px-3 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90 text-sm">+ สร้างกำลังสอน</a>
      <?php if ($isAdmin): ?>
        <button type="button" id="btnDeleteAll" class="px-3 py-2 rounded-xl border border-rose-600 text-rose-600 hover:bg-rose-50 text-sm">
          🗑️ ลบกำลังสอนทั้งหมด
        </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <!-- Filter -->
  <form id="filterForm" class="bg-white rounded-2xl shadow p-4 mb-6 grid grid-cols-1 md:grid-cols-5 gap-3" method="get">
    <input type="hidden" name="page" value="1">
    <div>
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border rounded-lg px-3 py-2">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>>
            <?= htmlspecialchars($y['year_label']).($y['is_active']?' 🟢':''); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border rounded-lg px-3 py-2">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$term_no === (int)$t['term_no']) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1">กลุ่มสาระ</label>
      <select name="group" class="w-full border rounded-lg px-3 py-2" id="groupSelect">
        <option value="">ทั้งหมด</option>
        <?php foreach (teacher_group_options() as $k=>$v): ?>
          <option value="<?= (int)$k; ?>" <?= ($group!==null && (int)$group===$k)?'selected':''; ?>><?= htmlspecialchars($v); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1">ครู</label>
      <select name="teacher_id" class="w-full border rounded-lg px-3 py-2" id="teacherSelect">
        <option value="">ทั้งหมด</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= (int)$t['id']; ?>" <?= ($teacher_id && (int)$teacher_id===(int)$t['id'])?'selected':''; ?>>
            <?= htmlspecialchars($t['first_name'].' '.$t['last_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end">
      <button class="w-full px-3 py-2 rounded-xl border">แสดง</button>
    </div>
  </form>

  <!-- สรุปคาบ/สัปดาห์ตามครู -->
  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="flex items-center justify-between">
      <div>
        <div class="font-medium">สรุปคาบ/สัปดาห์ตามครู</div>
      </div>
      <?php
        $link = url('loads_summary.php?year_id='.(int)$year_id.'&term_no='.(int)$term_no);
      ?>
      <a href="<?= $link ?>" class="px-3 py-2 rounded border hover:bg-slate-50">เปิดสรุปในหน้าใหม่</a>
    </div>
  </div>

  <!-- Table -->
  <div class="bg-white rounded-2xl shadow">
    <div class="p-4 border-b flex flex-col md:flex-row md:items-center gap-3 md:justify-between">
      <div class="flex-1">
        <label for="tt-load-search" class="block text-xs mb-1 text-slate-600">ค้นหาในตาราง</label>
        <input id="tt-load-search" type="text" class="w-full border rounded-lg px-3 py-2" placeholder="พิมพ์เพื่อกรอง เช่น ชื่อครู / วิชา / ห้อง / ชั้น">
      </div>
      <div class="flex items-center gap-2">
        <button type="button" id="tt-load-search-clear" class="px-3 py-2 rounded-lg border hover:bg-slate-50 text-sm">ล้าง</button>
        <div id="tt-load-search-count" class="text-xs text-slate-500 whitespace-nowrap"></div>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table id="tt-loads-table" class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr>
          <th class="text-left px-4 py-3">ครู</th>
          <th class="text-left px-4 py-3">กลุ่มสาระ</th>
          <th class="text-left px-4 py-3">วิชา</th>
          <th class="text-left px-4 py-3">ชั้น/ห้อง</th>
          <th class="text-left px-4 py-3">ห้องเรียน</th>
          <th class="text-left px-4 py-3">คาบ/สัปดาห์</th>
          <th class="text-left px-4 py-3">คาบติดกัน</th>
          <th class="text-right px-4 py-3">การทำงาน</th>
        </tr>
      </thead>
      <tbody id="tt-loads-tbody">
        <?php foreach ($loads as $row): ?>
          <tr class="border-t tt-load-row">
            <td class="px-4 py-3"><?= htmlspecialchars($row['first_name'].' '.$row['last_name']); ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars(teacher_group_label((int)$row['subject_group'])); ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['subject_code'].' - '.$row['subject_name']); ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['class_name']); ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars($row['room_name'] ?? '—'); ?></td>
            <td class="px-4 py-3"><?= (int)$row['periods_per_week']; ?></td>
            <td class="px-4 py-3"><?= (int)$row['consecutive_slots']; ?></td>
            <td class="px-4 py-3 text-right">
              <!-- ✅ ส่งค่าฟิลเตอร์ไปยังหน้าแก้ไข -->
              <a class="text-slate-700 hover:underline mr-3" href="<?= url('load_edit.php?id='.(int)$row['id'].'&'.http_build_query($currentFilterParams)); ?>">แก้ไข</a>
              
              <!-- ✅ ส่งค่าฟิลเตอร์ผ่าน hidden input -->
              <form action="<?= url('load_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'ยืนยันลบรายการนี้?'});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$row['id']; ?>">
                <input type="hidden" name="year_id" value="<?= $year_id; ?>">
                <input type="hidden" name="term_no" value="<?= $term_no; ?>">
                <input type="hidden" name="group" value="<?= $group !== null ? $group : ''; ?>">
                <input type="hidden" name="teacher_id" value="<?= $teacher_id !== null ? $teacher_id : ''; ?>">
                <input type="hidden" name="page" value="<?= $page; ?>">
                <button class="text-rose-600 hover:underline">ลบ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($loads): ?>
          <tr id="tt-loads-no-match" class="border-t hidden">
            <td colspan="8" class="px-4 py-6 text-center text-slate-500">ไม่พบรายการที่ตรงกับคำค้นหา</td>
          </tr>
        <?php endif; ?>
        <?php if (!$loads): ?>
          <tr><td colspan="8" class="px-4 py-6 text-center text-slate-500">ยังไม่มีข้อมูล</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- ✅ Pagination -->
    <?php if ($total_pages > 1): ?>
      <div class="border-t px-4 py-3 flex items-center justify-between bg-slate-50">
        <div class="text-sm text-slate-600">
          หน้า <?= $page ?> จาก <?= $total_pages ?>
        </div>
        <div class="flex gap-2">
          <?php if ($page > 1): ?>
            <?php
            $prevParams = $currentFilterParams;
            $prevParams['page'] = $page - 1;
            ?>
            <a href="?<?= http_build_query($prevParams) ?>" 
               class="px-3 py-1.5 rounded-lg border bg-white hover:bg-slate-50 text-sm">
              ← ก่อนหน้า
            </a>
          <?php endif; ?>
          
          <!-- แสดงเลขหน้า -->
          <?php
          $start = max(1, $page - 2);
          $end = min($total_pages, $page + 2);
          
          if ($start > 1): ?>
            <?php
            $firstParams = $currentFilterParams;
            $firstParams['page'] = 1;
            ?>
            <a href="?<?= http_build_query($firstParams) ?>" 
               class="px-3 py-1.5 rounded-lg border bg-white hover:bg-slate-50 text-sm">
              1
            </a>
            <?php if ($start > 2): ?>
              <span class="px-2 py-1.5 text-slate-400">...</span>
            <?php endif; ?>
          <?php endif; ?>
          
          <?php for ($i = $start; $i <= $end; $i++): ?>
            <?php
            $pageParams = $currentFilterParams;
            $pageParams['page'] = $i;
            ?>
            <a href="?<?= http_build_query($pageParams) ?>" 
               class="px-3 py-1.5 rounded-lg border text-sm <?= $i === $page ? 'bg-slate-900 text-white' : 'bg-white hover:bg-slate-50' ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>
          
          <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?>
              <span class="px-2 py-1.5 text-slate-400">...</span>
            <?php endif; ?>
            <?php
            $lastParams = $currentFilterParams;
            $lastParams['page'] = $total_pages;
            ?>
            <a href="?<?= http_build_query($lastParams) ?>" 
               class="px-3 py-1.5 rounded-lg border bg-white hover:bg-slate-50 text-sm">
              <?= $total_pages ?>
            </a>
          <?php endif; ?>
          
          <?php if ($page < $total_pages): ?>
            <?php
            $nextParams = $currentFilterParams;
            $nextParams['page'] = $page + 1;
            ?>
            <a href="?<?= http_build_query($nextParams) ?>" 
               class="px-3 py-1.5 rounded-lg border bg-white hover:bg-slate-50 text-sm">
              ถัดไป →
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAdmin): ?>
  <!-- ✅ Hidden Form สำหรับลบทั้งหมด -->
  <form id="deleteAllForm" method="post" style="display:none;">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <input type="hidden" name="action" value="delete_all">
  </form>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
(function () {
  const form = document.getElementById('filterForm');
  if (!form) return;
  const selYear = form.querySelector('select[name="year_id"]');
  const selTerm = form.querySelector('select[name="term_no"]');
  const selGroup = document.getElementById('groupSelect');
  const selTeacher = document.getElementById('teacherSelect');
  const apiUrl = 'api_teachers.php';

  function submitForm() { 
    form.querySelector('input[name="page"]').value = 1;
    form.submit(); 
  }

  let suppressTeacherChangeSubmit = false;

  selYear.addEventListener('change', submitForm);
  selTerm.addEventListener('change', submitForm);
  selTeacher.addEventListener('change', function () {
    if (suppressTeacherChangeSubmit) return;
    submitForm();
  });

  selGroup.addEventListener('change', async () => {
    const grp = selGroup.value;
    const prevTeacher = selTeacher.value;

    selTeacher.innerHTML = '';
    const optLoading = document.createElement('option');
    optLoading.textContent = 'กำลังโหลด...';
    optLoading.value = '';
    selTeacher.appendChild(optLoading);
    selTeacher.disabled = true;

    suppressTeacherChangeSubmit = true;
    try {
      const res = await fetch(apiUrl + (grp !== '' ? ('?group=' + encodeURIComponent(grp)) : ''), {
        credentials: 'same-origin'
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const list = await res.json();

      selTeacher.innerHTML = '';
      const optAll = document.createElement('option');
      optAll.value = '';
      optAll.textContent = 'ทั้งหมด';
      selTeacher.appendChild(optAll);

      let foundPrev = false;
      list.forEach(t => {
        const opt = document.createElement('option');
        opt.value = String(t.id);
        opt.textContent = (t.first_name + ' ' + t.last_name).trim();
        if (prevTeacher && String(t.id) === String(prevTeacher)) {
          opt.selected = true;
          foundPrev = true;
        }
        selTeacher.appendChild(opt);
      });

      if (!foundPrev) selTeacher.value = '';

      selTeacher.disabled = false;
      suppressTeacherChangeSubmit = false;

      submitForm();
    } catch (e) {
      selTeacher.innerHTML = '';
      const optAll = document.createElement('option');
      optAll.value = '';
      optAll.textContent = 'ทั้งหมด';
      selTeacher.appendChild(optAll);
      selTeacher.value = '';
      selTeacher.disabled = false;
      suppressTeacherChangeSubmit = false;

      console.error(e);
      submitForm();
    }
  });

  // ✅ Delete all (admin only)
  const btnDeleteAll = document.getElementById('btnDeleteAll');
  const deleteAllForm = document.getElementById('deleteAllForm');
  if (btnDeleteAll && deleteAllForm) {
    btnDeleteAll.addEventListener('click', () => {
      ttDoubleConfirmSubmit(
        deleteAllForm,
        { title: 'ลบกำลังสอนทั้งหมด', text: '⚠️ คุณแน่ใจหรือไม่ที่จะลบกำลังสอนทั้งหมดของปีการศึกษาและเทอมนี้?', confirmButtonText: 'ดำเนินการต่อ' },
        { title: 'ยืนยันอีกครั้ง', text: '❗ การกระทำนี้ไม่สามารถกู้คืนได้!', confirmButtonText: 'ลบทั้งหมด' }
      );
    });
  }
})();
</script>

<script>
(function () {
  const input = document.getElementById('tt-load-search');
  const clearBtn = document.getElementById('tt-load-search-clear');
  const tbody = document.getElementById('tt-loads-tbody');
  const countEl = document.getElementById('tt-load-search-count');
  const noMatchRow = document.getElementById('tt-loads-no-match');
  if (!input || !tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr.tt-load-row'));
  if (!rows.length) {
    if (countEl) countEl.textContent = '';
    return;
  }

  function norm(s) {
    return String(s || '')
      .toLowerCase()
      .replace(/\s+/g, ' ')
      .trim();
  }

  rows.forEach(tr => {
    tr.dataset.search = norm(tr.textContent || '');
  });

  function render() {
    const q = norm(input.value);
    let visible = 0;
    rows.forEach(tr => {
      const ok = !q || (tr.dataset.search || '').includes(q);
      tr.classList.toggle('hidden', !ok);
      if (ok) visible++;
    });
    if (noMatchRow) noMatchRow.classList.toggle('hidden', visible !== 0);
    if (countEl) {
      countEl.textContent = q ? `พบ ${visible} / ${rows.length}` : `ทั้งหมด ${rows.length}`;
    }
  }

  input.addEventListener('input', render);
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      input.value = '';
      input.focus();
      render();
    });
  }

  render();
})();
</script>
