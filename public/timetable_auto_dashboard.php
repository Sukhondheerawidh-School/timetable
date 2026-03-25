<?php
// filepath: c:\xampp\htdocs\timetable\public\timetable_auto_dashboard.php
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/helpers.php';
require_once __DIR__.'/../app/db.php';
requireLogin(); requireAdmin();

$years = $pdo->query("SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC")->fetchAll();
$year_id = (int)($_GET['year_id'] ?? ($years[0]['id'] ?? 0));
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);
$termOptions = tt_terms_list($pdo, $year_id);

// ✅ Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20; // แสดง 20 รายการต่อหน้า
$offset = ($page - 1) * $per_page;

// ✅ นับจำนวนทั้งหมด
$countStmt = $pdo->prepare("
  WITH loads AS (
    SELECT tl.id, tl.class_id, tl.teacher_id, tl.subject_id, tl.room_id, tl.periods_per_week,
           c.class_name, c.grade_label,
           CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name
                ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS label
    FROM teaching_loads tl
    JOIN subjects s ON s.id=tl.subject_id
    JOIN classes  c ON c.id=tl.class_id
    WHERE tl.academic_year_id=? AND tl.term_no=?
  ),
  used AS (
    SELECT st.teacher_id, ts.class_id, ts.subject_name, COUNT(DISTINCT ts.id) used_cnt
    FROM timetable_slots ts
    JOIN timetable_slot_teachers st ON st.slot_id=ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=?
    GROUP BY st.teacher_id, ts.class_id, ts.subject_name
  )
  SELECT COUNT(*) as total
  FROM loads l
  LEFT JOIN used u
    ON u.teacher_id=l.teacher_id
   AND u.class_id = l.class_id
   AND u.subject_name=l.label
");
$countStmt->execute([$year_id, $term_no, $year_id, $term_no]);
$total_records = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));

// ✅ ดึงข้อมูลแบบ Pagination - ใช้ setAttribute สำหรับ LIMIT/OFFSET
$summaryStmt = $pdo->prepare("
  WITH loads AS (
    SELECT tl.id, tl.class_id, tl.teacher_id, tl.subject_id, tl.room_id, tl.periods_per_week,
           c.class_name, c.grade_label,
           CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name
                ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS label
    FROM teaching_loads tl
    JOIN subjects s ON s.id=tl.subject_id
    JOIN classes  c ON c.id=tl.class_id
    WHERE tl.academic_year_id=? AND tl.term_no=?
  ),
  used AS (
    SELECT st.teacher_id, ts.class_id, ts.subject_name, COUNT(DISTINCT ts.id) used_cnt
    FROM timetable_slots ts
    JOIN timetable_slot_teachers st ON st.slot_id=ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=?
    GROUP BY st.teacher_id, ts.class_id, ts.subject_name
  )
  SELECT
    l.class_name,
    l.label,
    COALESCE(u.used_cnt,0) AS used_cnt,
    CAST(l.periods_per_week AS SIGNED) AS quota,
    GREATEST(
      CAST(l.periods_per_week AS SIGNED) - CAST(COALESCE(u.used_cnt,0) AS SIGNED), 0
    ) AS remain
  FROM loads l
  LEFT JOIN used u
    ON u.teacher_id=l.teacher_id
   AND u.class_id = l.class_id
   AND u.subject_name=l.label
  ORDER BY l.class_name, l.label
  LIMIT $per_page OFFSET $offset
");
$summaryStmt->execute([$year_id, $term_no, $year_id, $term_no]);
$summary = $summaryStmt->fetchAll();

// ✅ สรุปภาพรวม
$statsStmt = $pdo->prepare("
  WITH loads AS (
    SELECT tl.id, tl.class_id, tl.teacher_id, tl.subject_id, tl.room_id, tl.periods_per_week,
           c.class_name, c.grade_label,
           CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name
                ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS label
    FROM teaching_loads tl
    JOIN subjects s ON s.id=tl.subject_id
    JOIN classes  c ON c.id=tl.class_id
    WHERE tl.academic_year_id=? AND tl.term_no=?
  ),
  used AS (
    SELECT st.teacher_id, ts.class_id, ts.subject_name, COUNT(DISTINCT ts.id) used_cnt
    FROM timetable_slots ts
    JOIN timetable_slot_teachers st ON st.slot_id=ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=?
    GROUP BY st.teacher_id, ts.class_id, ts.subject_name
  )
  SELECT
    SUM(COALESCE(u.used_cnt,0)) AS total_used,
    SUM(CAST(l.periods_per_week AS SIGNED)) AS total_quota,
    SUM(GREATEST(CAST(l.periods_per_week AS SIGNED) - CAST(COALESCE(u.used_cnt,0) AS SIGNED), 0)) AS total_remain
  FROM loads l
  LEFT JOIN used u
    ON u.teacher_id=l.teacher_id
   AND u.class_id = l.class_id
   AND u.subject_name=l.label
");
$statsStmt->execute([$year_id, $term_no, $year_id, $term_no]);
$stats = $statsStmt->fetch();

include __DIR__.'/../partials/head.php';
include __DIR__.'/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-xl font-semibold">จัดตารางอัตโนมัติ</h1>
  </div>

  <!-- แถบตัวกรอง -->
  <form method="get" id="filterForm"
        class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
    <input type="hidden" name="page" value="<?= $page ?>">
    <div class="md:col-span-4">
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" id="selectYear" class="w-full border rounded px-3 py-2">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= $year_id===(int)$y['id']?'selected':''; ?>>
            <?= htmlspecialchars($y['year_label']) ?><?= $y['is_active']?' (ใช้งาน)':'' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-3">
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" id="selectTerm" class="w-full border rounded px-3 py-2">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$term_no) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <!-- Action buttons -->
  <div class="bg-white rounded-2xl shadow p-4 mb-4 flex justify-end gap-2">
    <a href="<?= url('timetable_auto_missing.php?year_id='.$year_id.'&term_no='.$term_no) ?>"
       class="px-3 py-2 rounded border hover:bg-slate-50">
      📌 รายงานวิชาที่ยังลงไม่ได้
    </a>
    <button type="button" id="btnAutoSchedule"
       class="px-3 py-2 rounded bg-slate-900 text-white hover:bg-slate-800">
      🚀 จัดตารางอัตโนมัติ
    </button>
    <button type="button" id="btnCopy"
            class="px-3 py-2 rounded border hover:bg-slate-50">
      คัดลอกจากเทอมก่อน
    </button>
    <button type="button" id="btnClearAuto"
            class="px-3 py-2 rounded border text-orange-600 hover:bg-orange-50">
      ลบคาบอัตโนมัติทั้งหมด
    </button>
    <button type="button" id="btnClearAll"
            class="px-3 py-2 rounded border text-rose-600 hover:bg-rose-50">
      🗑️ ลบตารางทั้งหมด
    </button>
    <a href="<?= url('timetable.php?view=class&year_id='.$year_id.'&term_no='.$term_no) ?>"
       class="px-3 py-2 rounded border hover:bg-slate-50">
      กลับไปยังตาราง
    </a>
  </div>

  <!-- ✅ สรุปภาพรวม -->
  <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl shadow p-6 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div>
        <div class="text-sm text-slate-600 mb-1">รวมทั้งหมด</div>
        <div class="text-2xl font-bold text-slate-900"><?= number_format($total_records) ?></div>
        <div class="text-xs text-slate-500">รายการ (ห้อง × วิชา)</div>
      </div>
      <div>
        <div class="text-sm text-slate-600 mb-1">คาบที่จัดแล้ว</div>
        <div class="text-2xl font-bold text-emerald-600"><?= number_format((int)$stats['total_used']) ?></div>
        <div class="text-xs text-slate-500">คาบ</div>
      </div>
      <div>
        <div class="text-sm text-slate-600 mb-1">กำลังสอนทั้งหมด</div>
        <div class="text-2xl font-bold text-blue-600"><?= number_format((int)$stats['total_quota']) ?></div>
        <div class="text-xs text-slate-500">คาบ</div>
      </div>
      <div>
        <div class="text-sm text-slate-600 mb-1">คงเหลือ</div>
        <div class="text-2xl font-bold text-rose-600"><?= number_format((int)$stats['total_remain']) ?></div>
        <div class="text-xs text-slate-500">คาบ</div>
      </div>
    </div>
    <!-- Progress Bar -->
    <?php
    $total_quota = (int)$stats['total_quota'];
    $total_used = (int)$stats['total_used'];
    $percentage = $total_quota > 0 ? round(($total_used / $total_quota) * 100, 1) : 0;
    ?>
    <div class="mt-4">
      <div class="flex items-center justify-between text-sm mb-1">
        <span class="text-slate-600">ความคืบหน้า</span>
        <span class="font-semibold text-slate-900"><?= $percentage ?>%</span>
      </div>
      <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
        <div class="bg-gradient-to-r from-emerald-500 to-emerald-600 h-3 rounded-full transition-all duration-500"
             style="width: <?= min($percentage, 100) ?>%"></div>
      </div>
    </div>
  </div>

  <!-- ตารางสรุป -->
  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 font-medium border-b flex items-center justify-between">
      <span>สถานะการลงคาบ (รวมตาม ห้อง × วิชา)</span>
      <span class="text-sm text-slate-500">หน้า <?= $page ?> / <?= $total_pages ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 sticky top-0">
          <tr>
            <th class="text-left px-3 py-2 font-semibold">ห้อง</th>
            <th class="text-left px-3 py-2 font-semibold">วิชา</th>
            <th class="text-right px-3 py-2 font-semibold">ใช้ไป</th>
            <th class="text-right px-3 py-2 font-semibold">กำลัง</th>
            <th class="text-right px-3 py-2 font-semibold">คงเหลือ</th>
            <th class="text-center px-3 py-2 font-semibold">สถานะ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($summary)): ?>
            <tr>
              <td colspan="6" class="px-3 py-8 text-center text-slate-500">
                <div class="text-4xl mb-2">📭</div>
                ไม่มีข้อมูลกำลังสอน
              </td>
            </tr>
          <?php else: ?>
            <?php foreach($summary as $row): ?>
              <?php
              $used = (int)$row['used_cnt'];
              $quota = (int)$row['quota'];
              $remain = (int)$row['remain'];
              $progress = $quota > 0 ? round(($used / $quota) * 100) : 0;
              
              // สีสถานะ
              if ($remain === 0) {
                $statusColor = 'bg-emerald-100 text-emerald-700';
                $statusText = '✓ เสร็จสิ้น';
              } elseif ($used > 0) {
                $statusColor = 'bg-blue-100 text-blue-700';
                $statusText = '⏳ ดำเนินการ';
              } else {
                $statusColor = 'bg-slate-100 text-slate-600';
                $statusText = '○ รอดำเนินการ';
              }
              ?>
              <tr class="border-t hover:bg-slate-50">
                <td class="px-3 py-2 font-medium"><?= htmlspecialchars($row['class_name']) ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($row['label']) ?></td>
                <td class="px-3 py-2 text-right"><?= $used ?></td>
                <td class="px-3 py-2 text-right"><?= $quota ?></td>
                <td class="px-3 py-2 text-right font-medium <?= $remain>0?'text-rose-600':'text-emerald-600' ?>">
                  <?= $remain ?>
                </td>
                <td class="px-3 py-2">
                  <div class="flex items-center justify-center gap-2">
                    <span class="text-xs px-2 py-1 rounded-full <?= $statusColor ?> whitespace-nowrap">
                      <?= $statusText ?>
                    </span>
                    <span class="text-xs text-slate-500"><?= $progress ?>%</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ✅ Pagination -->
    <?php if ($total_pages > 1): ?>
      <div class="px-4 py-3 border-t bg-slate-50">
        <div class="flex items-center justify-between">
          <div class="text-sm text-slate-600">
            แสดง <?= $offset + 1 ?> - <?= min($offset + $per_page, $total_records) ?> จากทั้งหมด <?= number_format($total_records) ?> รายการ
          </div>
          <div class="flex gap-1">
            <!-- ปุ่มหน้าแรก -->
            <?php if ($page > 1): ?>
              <a href="?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>&page=1"
                 class="px-3 py-1.5 rounded border hover:bg-white text-sm">
                « แรก
              </a>
              <a href="?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>&page=<?= $page - 1 ?>"
                 class="px-3 py-1.5 rounded border hover:bg-white text-sm">
                ‹ ก่อนหน้า
              </a>
            <?php endif; ?>

            <!-- ปุ่มหน้าถัดไป -->
            <?php if ($page < $total_pages): ?>
              <a href="?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>&page=<?= $page + 1 ?>"
                 class="px-3 py-1.5 rounded border hover:bg-white text-sm">
                ถัดไป ›
              </a>
              <a href="?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>&page=<?= $total_pages ?>"
                 class="px-3 py-1.5 rounded border hover:bg-white text-sm">
                สุดท้าย »
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ✅ Custom Confirmation Modal -->
<div id="confirmModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
    <div id="modalHeader" class="px-6 py-4">
      <h3 class="text-white font-semibold text-lg flex items-center gap-2" id="modalTitle"></h3>
    </div>
    <div class="p-6">
      <p class="text-slate-700 mb-4" id="modalMessage"></p>
      <div id="modalDetails" class="text-sm text-slate-600 bg-slate-50 p-3 rounded-lg mb-4"></div>
      <p class="text-sm text-slate-500 bg-amber-50 border border-amber-200 p-3 rounded-lg" id="modalWarning"></p>
    </div>
    <div class="flex gap-3 px-6 pb-6">
      <button id="modalCancel" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 transition text-sm font-medium">
        ยกเลิก
      </button>
      <button id="modalConfirm" class="flex-1 px-4 py-2.5 rounded-xl transition text-sm font-medium"></button>
    </div>
  </div>
</div>

<!-- ✅ Custom Alert Modal (สำหรับแสดงผลลัพธ์) -->
<div id="alertModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
    <div id="alertHeader" class="px-6 py-4">
      <h3 class="text-white font-semibold text-lg flex items-center gap-2" id="alertTitle"></h3>
    </div>
    <div class="p-6">
      <p class="text-slate-700" id="alertMessage"></p>
    </div>
    <div class="flex justify-end px-6 pb-6">
      <button id="alertClose" class="px-6 py-2.5 rounded-xl bg-slate-900 text-white hover:bg-slate-800 transition text-sm font-medium">
        ตลอด
      </button>
    </div>
  </div>
</div>

<script>
const year_id = <?= json_encode($year_id) ?>;
const term_no = <?= json_encode($term_no) ?>;

async function callAPI(url, payload){
  const r = await fetch(url, {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'Cache-Control':'no-cache'},
    body: JSON.stringify(payload)
  });
  const text = await r.text();
  try {
    return JSON.parse(text);
  } catch(e) {
    throw new Error("คำตอบที่ได้รับไม่ใช่ JSON:\n\n" + text.substring(0,1200));
  }
}

// ✅ Auto-submit filter
const filterForm = document.getElementById('filterForm');
const selectYear = document.getElementById('selectYear');
const selectTerm = document.getElementById('selectTerm');

selectYear.addEventListener('change', () => filterForm.submit());
selectTerm.addEventListener('change', () => filterForm.submit());

// ✅ Custom Alert Modal Logic
const alertModal = document.getElementById('alertModal');
const alertHeader = document.getElementById('alertHeader');
const alertTitle = document.getElementById('alertTitle');
const alertMessage = document.getElementById('alertMessage');
const alertClose = document.getElementById('alertClose');

function showAlert(config) {
  // ตั้งค่าสี header (success = green, error = red, info = blue)
  const headerColors = {
    success: 'bg-emerald-600',
    error: 'bg-rose-600',
    info: 'bg-blue-600',
    warning: 'bg-amber-600'
  };
  
  const icons = {
    success: '✅',
    error: '❌',
    info: 'ℹ️',
    warning: '⚠️'
  };
  
  alertHeader.className = `px-6 py-4 ${headerColors[config.type] || 'bg-slate-600'}`;
  alertTitle.innerHTML = `<span class="text-2xl">${icons[config.type] || '💬'}</span>${config.title || 'แจ้งเตือน'}`;
  alertMessage.textContent = config.message;
  
  alertModal.classList.remove('hidden');
}

alertClose.addEventListener('click', () => {
  alertModal.classList.add('hidden');
});

alertModal.addEventListener('click', (e) => {
  if (e.target === alertModal) {
    alertModal.classList.add('hidden');
  }
});

// ✅ Custom Confirmation Modal Logic
const confirmModal = document.getElementById('confirmModal');
const modalHeader = document.getElementById('modalHeader');
const modalTitle = document.getElementById('modalTitle');
const modalMessage = document.getElementById('modalMessage');
const modalDetails = document.getElementById('modalDetails');
const modalWarning = document.getElementById('modalWarning');
const modalCancel = document.getElementById('modalCancel');
const modalConfirm = document.getElementById('modalConfirm');

let confirmStep = 0;
let currentCallback = null;

function showModal(config) {
  confirmStep = 0;
  currentCallback = config.onConfirm;
  
  modalHeader.className = `px-6 py-4 ${config.headerColor || 'bg-blue-600'}`;
  modalTitle.innerHTML = `<span class="text-2xl">${config.icon || '❓'}</span>${config.title}`;
  modalMessage.textContent = config.message;
  
  if (config.details) {
    modalDetails.innerHTML = config.details;
    modalDetails.classList.remove('hidden');
  } else {
    modalDetails.classList.add('hidden');
  }
  
  modalWarning.innerHTML = config.warning;
  modalConfirm.textContent = config.confirmText || 'ยืนยัน';
  modalConfirm.className = `flex-1 px-4 py-2.5 rounded-xl transition text-sm font-medium ${config.confirmClass || 'bg-blue-600 text-white hover:bg-blue-700'}`;
  
  confirmModal.classList.remove('hidden');
}

modalCancel.addEventListener('click', () => {
  confirmModal.classList.add('hidden');
  confirmStep = 0;
  currentCallback = null;
});

modalConfirm.addEventListener('click', () => {
  if (confirmStep === 0) {
    confirmStep = 1;
    modalMessage.textContent = '❗ ยืนยันอีกครั้ง: การกระทำนี้ไม่สามารถกู้คืนได้!';
    modalDetails.classList.add('hidden');
    modalWarning.innerHTML = '<strong class="text-rose-600">คำเตือน:</strong> การกระทำนี้มีผลถาวรและไม่สามารถย้อนกลับได้';
    modalConfirm.textContent = 'ยืนยันอีกครั้ง';
    modalConfirm.className = 'flex-1 px-4 py-2.5 rounded-xl bg-rose-700 text-white hover:bg-rose-800 transition text-sm font-medium';
  } else {
    confirmModal.classList.add('hidden');
    if (currentCallback) currentCallback();
    confirmStep = 0;
    currentCallback = null;
  }
});

confirmModal.addEventListener('click', (e) => {
  if (e.target === confirmModal) {
    confirmModal.classList.add('hidden');
    confirmStep = 0;
    currentCallback = null;
  }
});

// ✅ ปุ่มจัดตารางอัตโนมัติ
const btnAutoSchedule = document.getElementById('btnAutoSchedule');
btnAutoSchedule.addEventListener('click', () => {
  showModal({
    icon: '🚀',
    title: 'จัดตารางอัตโนมัติ',
    headerColor: 'bg-blue-600',
    message: 'เริ่มจัดตารางอัตโนมัติสำหรับปีการศึกษาและเทอมนี้?',
    details: `
      <ul class="list-disc list-inside space-y-1">
        <li>ระบบจะพยายามจัดตารางให้ครบตามกำลังสอน</li>
        <li>จะไม่ลบตารางที่จัดด้วยตนเอง</li>
        <li>คาบที่ระบบจัดให้สามารถแก้ไขหรือลบได้</li>
      </ul>
    `,
    warning: '<strong>หมายเหตุ:</strong> กระบวนการอาจใช้เวลาสักครู่',
    confirmText: 'เริ่มจัดตาราง',
    confirmClass: 'bg-blue-600 text-white hover:bg-blue-700',
    onConfirm: () => {
      window.location.href = `timetable_auto_process.php?year_id=${year_id}&term_no=${term_no}`;
    }
  });
});

// ✅ ปุ่มคัดลอกจากเทอมก่อน
const btnCopy = document.getElementById('btnCopy');
btnCopy.addEventListener('click', () => {
  showModal({
    icon: '📋',
    title: 'คัดลอกตารางจากเทอมก่อน',
    headerColor: 'bg-indigo-600',
    message: 'คัดลอกตารางสอนจากเทอมก่อนหน้ามายังเทอมนี้?',
    details: `
      <ul class="list-disc list-inside space-y-1">
        <li>ระบบจะตรวจสอบกำลังสอนก่อนคัดลอก</li>
        <li>จะคัดลอกเฉพาะคาบที่ตรงกัน</li>
        <li>ไม่ลบตารางเดิมที่มีอยู่</li>
      </ul>
    `,
    warning: '<strong>หมายเหตุ:</strong> ควรตรวจสอบตารางหลังคัดลอก',
    confirmText: 'คัดลอก',
    confirmClass: 'bg-indigo-600 text-white hover:bg-indigo-700',
    onConfirm: async () => {
      btnCopy.disabled = true;
      btnCopy.textContent = 'กำลังคัดลอก...';
      
      try {
        const data = await callAPI('timetable_auto_run.php?action=copy_prev', { year_id, term_no });
        if (data.error) {
          showAlert({
            type: 'error',
            title: 'เกิดข้อผิดพลาด',
            message: data.error
          });
          return;
        }
        showAlert({
          type: 'success',
          title: 'คัดลอกสำเร็จ',
          message: data.message || 'คัดลอกตารางจากเทอมก่อนเรียบร้อยแล้ว'
        });
        setTimeout(() => location.reload(), 1500);
      } catch(e) {
        showAlert({
          type: 'error',
          title: 'เกิดข้อผิดพลาด',
          message: e.message
        });
      } finally {
        btnCopy.disabled = false;
        btnCopy.textContent = 'คัดลอกจากเทอมก่อน';
      }
    }
  });
});

// ✅ ปุ่มลบคาบอัตโนมัติ
const btnClearAuto = document.getElementById('btnClearAuto');
btnClearAuto.addEventListener('click', () => {
  showModal({
    icon: '🧹',
    title: 'ลบคาบอัตโนมัติ',
    headerColor: 'bg-orange-600',
    message: 'ลบคาบที่ระบบจัดอัตโนมัติทั้งหมดของปี/เทอมนี้?',
    details: `
      <ul class="list-disc list-inside space-y-1">
        <li>จะลบเฉพาะคาบที่ระบบจัดให้</li>
        <li>คาบที่จัดด้วยตนเองจะไม่ถูกลบ</li>
      </ul>
    `,
    warning: '<strong>หมายเหตุ:</strong> การกระทำนี้ไม่สามารถกู้คืนได้',
    confirmText: 'ลบคาบอัตโนมัติ',
    confirmClass: 'bg-orange-600 text-white hover:bg-orange-700',
    onConfirm: async () => {
      btnClearAuto.disabled = true;
      btnClearAuto.textContent = 'กำลังลบ...';
      
      try {
        const data = await callAPI('timetable_auto_run.php?action=clear', { year_id, term_no });
        if (data.error) {
          showAlert({
            type: 'error',
            title: 'เกิดข้อผิดพลาด',
            message: data.error
          });
          return;
        }
        showAlert({
          type: 'success',
          title: 'ลบสำเร็จ',
          message: data.message || 'ลบคาบอัตโนมัติเรียบร้อยแล้ว'
        });
        setTimeout(() => location.reload(), 1500);
      } catch(e) {
        showAlert({
          type: 'error',
          title: 'เกิดข้อผิดพลาด',
          message: e.message
        });
      } finally {
        btnClearAuto.disabled = false;
        btnClearAuto.textContent = 'ลบคาบอัตโนมัติทั้งหมด';
      }
    }
  });
});

// ✅ ปุ่มลบตารางทั้งหมด
const btnClearAll = document.getElementById('btnClearAll');
btnClearAll.addEventListener('click', () => {
  showModal({
    icon: '⚠️',
    title: 'ลบตารางทั้งหมด',
    headerColor: 'bg-rose-600',
    message: 'คุณแน่ใจหรือไม่ที่จะลบตารางสอนทั้งหมด (ทั้งจัดด้วยตนเองและอัตโนมัติ)?',
    details: `
      <ul class="list-disc list-inside space-y-1 text-rose-600">
        <li>จะลบตารางทั้งหมดของปี/เทอมนี้</li>
        <li>ทั้งคาบจัดด้วยตนเองและอัตโนมัติ</li>
        <li>ไม่สามารถกู้คืนได้</li>
      </ul>
    `,
    warning: '<strong class="text-rose-600">คำเตือนสำคัญ:</strong> การกระทำนี้จะลบข้อมูลถาวรและไม่สามารถกู้คืนได้',
    confirmText: 'ลบตารางทั้งหมด',
    confirmClass: 'bg-rose-600 text-white hover:bg-rose-700',
    onConfirm: async () => {
      btnClearAll.disabled = true;
      btnClearAll.textContent = '🗑️ กำลังลบ...';
      
      try {
        const data = await callAPI('timetable_auto_run.php?action=clear_all', { year_id, term_no });
        if (data.error) {
          showAlert({
            type: 'error',
            title: 'เกิดข้อผิดพลาด',
            message: data.error
          });
          return;
        }
        showAlert({
          type: 'success',
          title: 'ลบสำเร็จ',
          message: data.message || 'ลบตารางทั้งหมดเรียบร้อยแล้ว'
        });
        setTimeout(() => location.reload(), 1500);
      } catch(e) {
        showAlert({
          type: 'error',
          title: 'เกิดข้อผิดพลาด',
          message: e.message
        });
      } finally {
        btnClearAll.disabled = false;
        btnClearAll.textContent = '🗑️ ลบตารางทั้งหมด';
      }
    }
  });
});
</script>
<?php include __DIR__.'/../partials/footer.php'; ?>
