<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();

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

// ปีที่เลือกในฟอร์ม (ใช้กำหนดตัวเลือกเทอม + ค่าเริ่มต้น)
$selectedYearId = (int)($_POST['academic_year_id'] ?? $activeYearId);
$termOptions = tt_terms_list($pdo, $selectedYearId);
$defaultTerm = tt_default_term_no_for_year($pdo, $selectedYearId);
$selectedTermNo = isset($_POST['term_no']) ? (int)$_POST['term_no'] : $defaultTerm;
$selectedTermNo = tt_validate_term_no($pdo, $selectedYearId, $selectedTermNo);

// ดึงครูจาก teachers.subject_group (จัดกลุ่มตามกลุ่มสาระ และเรียงภายในกลุ่มด้วยรหัสครู)
$teachersRaw = $pdo->query('
  SELECT 
    id, 
    teacher_code,
    first_name, 
    last_name,
    subject_group
  FROM teachers
  ORDER BY 
    CASE WHEN subject_group IS NULL THEN 1 ELSE 0 END,
    subject_group
')->fetchAll();

$teacherGroups = [];
$groupOrder = [];
foreach ($teachersRaw as $t) {
  $groupKey = ($t['subject_group'] === null) ? 'null' : (string)(int)$t['subject_group'];
  if (!isset($teacherGroups[$groupKey])) {
    $teacherGroups[$groupKey] = [
      'subject_group' => $t['subject_group'],
      'subject_name'  => teacher_group_label((int)$t['subject_group']),
      'items'         => []
    ];
    $groupOrder[] = $groupKey;
  }
  $teacherGroups[$groupKey]['items'][] = [
    'id' => $t['id'],
    'teacher_code' => $t['teacher_code'],
    'first_name' => $t['first_name'],
    'last_name' => $t['last_name'],
    'subject_group' => $t['subject_group'],
    'subject_name' => $teacherGroups[$groupKey]['subject_name']
  ];
}

foreach ($groupOrder as $gk) {
  usort($teacherGroups[$gk]['items'], function($a, $b) {
    $ac = trim((string)($a['teacher_code'] ?? ''));
    $bc = trim((string)($b['teacher_code'] ?? ''));
    if ($ac === '' && $bc !== '') return 1;
    if ($ac !== '' && $bc === '') return -1;
    $cmp = strnatcasecmp($ac, $bc);
    if ($cmp !== 0) return $cmp;
    $cmp = strnatcasecmp((string)$a['first_name'], (string)$b['first_name']);
    if ($cmp !== 0) return $cmp;
    return strnatcasecmp((string)$a['last_name'], (string)$b['last_name']);
  });
}

$teachers = [];
foreach ($groupOrder as $gk) {
  foreach ($teacherGroups[$gk]['items'] as $t) {
    $teachers[] = $t;
  }
}

$subjects = $pdo->query('SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code')->fetchAll();
$classes  = $pdo->query('SELECT id, class_name FROM classes ORDER BY class_name')->fetchAll();
$rooms    = $pdo->query('SELECT id, room_code, room_name FROM rooms ORDER BY room_code')->fetchAll();

$selectedClassIds = array_map('intval', (array)($_POST['class_ids'] ?? []));

$lc = function(string $s): string {
  return function_exists('mb_strtolower') ? (string)mb_strtolower($s, 'UTF-8') : strtolower($s);
};

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    $year_id = (int)($_POST['academic_year_id'] ?? 0);
    $term_no = (int)($_POST['term_no'] ?? 1);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $class_ids = array_map('intval', (array)($_POST['class_ids'] ?? []));
    $room_id = ($_POST['room_id'] ?? '') !== '' ? (int)$_POST['room_id'] : null;
    $periods = (int)($_POST['periods_per_week'] ?? 0);
    $consec  = min(2, max(1, (int)($_POST['consecutive_slots'] ?? 1)));

    if (!$year_id || !$term_no || !$teacher_id || !$subject_id || !$class_ids) {
      $err = 'กรอกข้อมูลให้ครบ (ปี/เทอม/ครู/วิชา/ชั้น)';
    } elseif ($periods <= 0) {
      $err = 'กรุณาระบุจำนวนคาบ/สัปดาห์ (ต้องมากกว่า 0)';
    } else {
      try {
        // ✅ ดึงชื่อเพื่อบันทึก log
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
        
        $roomName = '';
        if ($room_id) {
          foreach ($rooms as $r) {
            if ((int)$r['id'] === $room_id) {
              $roomName = $r['room_code'] . ' - ' . $r['room_name'];
              break;
            }
          }
        }
        
        $classNames = [];
        foreach ($class_ids as $cid) {
          foreach ($classes as $c) {
            if ((int)$c['id'] === $cid) {
              $classNames[] = $c['class_name'];
              break;
            }
          }
        }
        
        $yearLabel = '';
        foreach ($years as $y) {
          if ((int)$y['id'] === $year_id) {
            $yearLabel = $y['year_label'];
            break;
          }
        }
        
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
          INSERT INTO teaching_loads(academic_year_id, term_no, teacher_id, subject_id, class_id, room_id, periods_per_week, consecutive_slots)
          VALUES (?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE room_id=VALUES(room_id), periods_per_week=VALUES(periods_per_week), consecutive_slots=VALUES(consecutive_slots)
        ');
        
        $ins=0;$upd=0;
        $createdIds = [];
        
        foreach ($class_ids as $cid){
          $ok = $stmt->execute([$year_id,$term_no,$teacher_id,$subject_id,$cid,$room_id,$periods,$consec]);
          if ($ok){
            if ($stmt->rowCount()===1) {
              $ins++;
              $createdIds[] = (int)$pdo->lastInsertId();
            } else {
              $upd++;
            }
          }
        }
        
        $pdo->commit();
        
        // ✅ บันทึก log
        if ($ins > 0 || $upd > 0) {
          logActivity(
            $ins > 0 ? 'create_loads' : 'update_loads',
            'teaching_loads',
            null,
            null,
            [
              'year_label' => $yearLabel,
              'term_no' => $term_no,
              'teacher_name' => $teacherName,
              'subject' => ($subjectCode ? $subjectCode . ' - ' : '') . $subjectName,
              'classes' => $classNames,
              'room_name' => $roomName ?: '-',
              'periods_per_week' => $periods,
              'consecutive_slots' => $consec,
              'inserted' => $ins,
              'updated' => $upd,
              'created_ids' => $createdIds
            ]
          );
        }
        
        flash_set('success',"บันทึกสำเร็จ: เพิ่ม {$ins} แถว, อัปเดต {$upd} แถว");
        redirect('loads.php?year_id='.$year_id.'&term_no='.$term_no);
      } catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err='ผิดพลาด: '.$e->getMessage();
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-3xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">สร้างกำลังสอน</h1>
  <?php if ($err): ?><div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div><?php endif; ?>

  <form method="post" class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ปีการศึกษา</label>
        <select name="academic_year_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id'] === (int)$selectedYearId ? 'selected' : ''; ?>>
              <?= htmlspecialchars($y['year_label']).($y['is_active'] ? ' 🟢' : ''); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">เทอม</label>
        <select name="term_no" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($termOptions as $t): ?>
            <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$selectedTermNo) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ครู (จัดกลุ่มตามกลุ่มสาระ)</label>
        <select name="teacher_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php 
          $currentSubject = '';
          foreach ($teachers as $t): 
            // แสดง optgroup เมื่อเปลี่ยนกลุ่มสาระ
            if ($currentSubject !== $t['subject_name']) {
              if ($currentSubject !== '') echo '</optgroup>';
              $currentSubject = $t['subject_name'];
              echo '<optgroup label="'.htmlspecialchars($currentSubject).'">';
            }
          ?>
            <option value="<?= (int)$t['id']; ?>">
              <?= htmlspecialchars(($t['teacher_code'] ? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name']); ?>
            </option>
          <?php endforeach; ?>
          <?php if ($currentSubject !== '') echo '</optgroup>'; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">วิชา</label>
        <select name="subject_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id']; ?>"><?= htmlspecialchars($s['subject_code'].' - '.$s['subject_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1.5">ชั้น/ห้อง (เลือกหลายห้องได้)</label>

      <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-2">
        <div class="flex-1">
          <input id="tt-class-search" type="text" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="ค้นหาชั้น/ห้อง เช่น ม.1/1">
        </div>
        <div class="flex items-center gap-3 text-sm">
          <span class="text-slate-500">รูปแบบ:</span>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="class_pick_mode" id="tt-mode-checkbox" value="checkbox" class="h-4 w-4" checked>
            <span>Checkbox</span>
          </label>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="class_pick_mode" id="tt-mode-select" value="select" class="h-4 w-4">
            <span>Ctrl/Shift เลือก</span>
          </label>
        </div>
      </div>

      <div class="flex items-center gap-2 mb-2">
        <button type="button" id="tt-class-select-all" class="px-3 py-2 rounded-lg border hover:bg-slate-50 text-sm">เลือกทั้งหมด</button>
        <button type="button" id="tt-class-clear" class="px-3 py-2 rounded-lg border hover:bg-slate-50 text-sm">ล้าง</button>
      </div>

      <div id="tt-class-mode-checkbox" class="border rounded-lg p-3 max-h-72 overflow-auto bg-white">
        <div class="grid grid-cols-1 gap-2" id="tt-class-list">
          <?php foreach ($classes as $c):
            $cid = (int)$c['id'];
            $cname = (string)$c['class_name'];
            $checked = in_array($cid, $selectedClassIds, true);
          ?>
            <label class="tt-class-item flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-50" data-text="<?= htmlspecialchars($lc($cname)); ?>">
              <input type="checkbox" class="tt-class-checkbox h-4 w-4" name="class_ids[]" value="<?= $cid; ?>" <?= $checked ? 'checked' : ''; ?>>
              <span class="text-sm text-slate-800"><?= htmlspecialchars($cname); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="tt-class-mode-select" class="hidden">
        <select id="tt-class-select" name="class_ids[]" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" multiple size="10">
          <?php foreach ($classes as $c):
            $cid = (int)$c['id'];
            $cname = (string)$c['class_name'];
            $selected = in_array($cid, $selectedClassIds, true);
          ?>
            <option value="<?= $cid; ?>" <?= $selected ? 'selected' : ''; ?>><?= htmlspecialchars($cname); ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500 mt-1">กด Ctrl/Shift เพื่อเลือกหลายรายการ</p>
      </div>

      <div class="flex items-center justify-between mt-2">
        <p class="text-xs text-slate-500">พิมพ์เพื่อค้นหา แล้วติ๊กเลือกหลายห้องได้</p>
        <p class="text-xs text-slate-500"><span id="tt-class-count">0</span> ห้องที่เลือก</p>
      </div>
      <p id="tt-class-error" class="hidden text-xs text-rose-600 mt-1">กรุณาเลือกชั้น/ห้องอย่างน้อย 1 รายการ</p>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ห้องเรียน (ถ้ามี)</label>
        <select name="room_id" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="">— ใช้ค่าห้องประจำ/ไม่กำหนด —</option>
          <?php foreach ($rooms as $r): ?>
            <option value="<?= (int)$r['id']; ?>"><?= htmlspecialchars($r['room_code'].' - '.$r['room_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">คาบ/สัปดาห์ <span class="text-rose-600">*</span></label>
        <input type="number" name="periods_per_week" min="1" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" value="0" required>
        <p class="text-xs text-rose-600 mt-1">กรุณาระบุจำนวนคาบ (ต้องมากกว่า 0)</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">คาบติดกัน</label>
        <select name="consecutive_slots" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="1">ไม่ติดกัน</option>
          <option value="2">2 คาบติด</option>
        </select>
      </div>
    </div>

    <div class="flex items-center gap-2">
      <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
      <a href="<?= url('loads.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
    </div>
  </form>
</div>

<script>
  (function(){
    const searchEl = document.getElementById('tt-class-search');
    const listEl = document.getElementById('tt-class-list');
    const modeCheckboxEl = document.getElementById('tt-mode-checkbox');
    const modeSelectEl = document.getElementById('tt-mode-select');
    const modeCheckboxWrap = document.getElementById('tt-class-mode-checkbox');
    const modeSelectWrap = document.getElementById('tt-class-mode-select');
    const selectEl = document.getElementById('tt-class-select');
    const countEl = document.getElementById('tt-class-count');
    const errEl = document.getElementById('tt-class-error');
    const selectAllBtn = document.getElementById('tt-class-select-all');
    const clearBtn = document.getElementById('tt-class-clear');
    if (!listEl) return;

    const checkboxes = Array.from(listEl.querySelectorAll('.tt-class-checkbox'));
    const items = Array.from(listEl.querySelectorAll('.tt-class-item'));

    function currentMode(){
      return modeSelectEl && modeSelectEl.checked ? 'select' : 'checkbox';
    }

    function setMode(mode){
      const isSelect = mode === 'select';
      if (modeCheckboxWrap) modeCheckboxWrap.classList.toggle('hidden', isSelect);
      if (modeSelectWrap) modeSelectWrap.classList.toggle('hidden', !isSelect);

      checkboxes.forEach(cb => cb.disabled = isSelect);
      if (selectEl) selectEl.disabled = !isSelect;

      const help = modeSelectWrap ? modeSelectWrap.querySelector('p') : null;
      if (help) help.classList.toggle('hidden', !isSelect);
      updateCount();
    }

    function selectedIdsFromCheckboxes(){
      return checkboxes.filter(cb => cb.checked).map(cb => cb.value);
    }

    function selectedIdsFromSelect(){
      if (!selectEl) return [];
      return Array.from(selectEl.selectedOptions).map(o => o.value);
    }

    function syncSelectFromCheckboxes(){
      if (!selectEl) return;
      const selected = new Set(selectedIdsFromCheckboxes());
      Array.from(selectEl.options).forEach(opt => {
        opt.selected = selected.has(opt.value);
      });
    }

    function syncCheckboxesFromSelect(){
      if (!selectEl) return;
      const selected = new Set(selectedIdsFromSelect());
      checkboxes.forEach(cb => {
        cb.checked = selected.has(cb.value);
      });
    }

    function updateCount(){
      const n = currentMode() === 'select'
        ? selectedIdsFromSelect().length
        : selectedIdsFromCheckboxes().length;
      if (countEl) countEl.textContent = String(n);
      if (errEl) errEl.classList.toggle('hidden', n > 0);
      return n;
    }

    function applyFilter(q){
      const query = (q || '').trim().toLowerCase();
      items.forEach(item => {
        const text = (item.getAttribute('data-text') || '');
        item.classList.toggle('hidden', query && !text.includes(query));
      });

      if (selectEl) {
        Array.from(selectEl.options).forEach(opt => {
          const t = (opt.textContent || '').toLowerCase();
          opt.hidden = !!(query && !t.includes(query));
        });
      }
    }

    checkboxes.forEach(cb => cb.addEventListener('change', () => {
      syncSelectFromCheckboxes();
      updateCount();
    }));

    if (selectEl) {
      selectEl.addEventListener('change', () => {
        syncCheckboxesFromSelect();
        updateCount();
      });
    }

    // start state: checkbox mode
    syncSelectFromCheckboxes();
    setMode('checkbox');

    if (searchEl) {
      searchEl.addEventListener('input', (e) => applyFilter(e.target.value));
    }

    if (modeCheckboxEl) modeCheckboxEl.addEventListener('change', () => setMode('checkbox'));
    if (modeSelectEl) modeSelectEl.addEventListener('change', () => setMode('select'));

    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', () => {
        if (currentMode() === 'select' && selectEl) {
          Array.from(selectEl.options).forEach(opt => {
            if (opt.hidden) return;
            opt.selected = true;
          });
          syncCheckboxesFromSelect();
        } else {
          items.forEach((item, idx) => {
            if (item.classList.contains('hidden')) return;
            checkboxes[idx].checked = true;
          });
          syncSelectFromCheckboxes();
        }
        updateCount();
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        if (selectEl) {
          Array.from(selectEl.options).forEach(opt => opt.selected = false);
        }
        checkboxes.forEach(cb => cb.checked = false);
        updateCount();
      });
    }

    const form = listEl.closest('form');
    if (form) {
      form.addEventListener('submit', (e) => {
        const n = updateCount();
        if (n <= 0) {
          e.preventDefault();
          if (errEl) errEl.classList.remove('hidden');
          const target = currentMode() === 'select'
            ? (selectEl || listEl)
            : (listEl.querySelector('.tt-class-item:not(.hidden)') || listEl);
          if (target && target.scrollIntoView) target.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
      });
    }
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
