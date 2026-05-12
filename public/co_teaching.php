<?php
// public/co_teaching.php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();

$pageTitle = 'จับคู่สอนร่วม (Co-Teaching)';
function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$err = '';
$success = '';

// ---------- โหลดค่าพื้นฐาน ----------
$years = $pdo->query("SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC")->fetchAll();
$classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll();
$subjects = $pdo->query("SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code")->fetchAll();

// ปี default = ปีที่ใช้งานอยู่
$default_year_id = (int)($pdo->query("SELECT id FROM academic_years WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if (!$default_year_id && $years) $default_year_id = (int)$years[0]['id'];

// รับตัวกรอง
$year_id = (int)($_GET['year_id'] ?? $default_year_id);

$termOptions = tt_terms_list($pdo, $year_id);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
    ? (int)$_GET['term_no']
    : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);

// ✅ ตัวกรองเพิ่มเติม
$filter_class_id = (int)($_GET['filter_class_id'] ?? 0);
$filter_subject_id = (int)($_GET['filter_subject_id'] ?? 0);

// ---------- จัดการ POST (สร้าง/ลบ) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'CSRF ไม่ถูกต้อง';
    } elseif (!canEditSection('timetable')) {
        $err = '🔒 ระบบปิดการแก้ไขชั่วคราว กรุณาติดต่อ Superuser';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create_multiple') {
            // รับข้อมูลคู่ที่เลือก
            $pairs_data = $_POST['pairs'] ?? [];
            
            if (empty($pairs_data)) {
                $err = 'กรุณาเลือกกำลังสอนที่ต้องการจับคู่อย่างน้อย 1 คู่';
            } else {
                try {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare("
                        INSERT IGNORE INTO co_teaching_pairs
                        (subject_id, year_id, term_no, class_id, main_load_id, co_load_id, note)
                        VALUES (?,?,?,?,?,?,?)
                    ");
                    
                    $created = 0;
                    foreach ($pairs_data as $pair_str) {
                        // Format: "load_id1,load_id2,note"
                        $parts = explode('|', $pair_str);
                        if (count($parts) < 2) continue;
                        
                        $load_ids = array_map('intval', explode(',', $parts[0]));
                        $note = trim($parts[1] ?? '');
                        
                        if (count($load_ids) < 2) continue;
                        
                        // ดึงข้อมูลเพื่อตรวจสอบ
                        $in = implode(',', array_fill(0, count($load_ids), '?'));
                        $chk = $pdo->prepare("
                            SELECT DISTINCT academic_year_id, term_no, class_id, subject_id
                            FROM teaching_loads WHERE id IN ($in)
                        ");
                        $chk->execute($load_ids);
                        $meta = $chk->fetch();
                        
                        if (!$meta) continue;
                        
                        $y = (int)$meta['academic_year_id'];
                        $t = (int)$meta['term_no'];
                        $c = (int)$meta['class_id'];
                        $s = (int)$meta['subject_id'];
                        
                        // สร้างคู่ (ทุกคู่ที่เลือก)
                        for ($i = 0; $i < count($load_ids); $i++) {
                            for ($j = 0; $j < count($load_ids); $j++) {
                                if ($i === $j) continue;
                                $ins->execute([$s, $y, $t, $c, $load_ids[$i], $load_ids[$j], $note !== '' ? $note : null]);
                            }
                        }
                        $created++;
                    }
                    
                    $pdo->commit();
                    $success = "บันทึกคู่สอนร่วมเรียบร้อยแล้ว ({$created} กลุ่ม)";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $err = 'บันทึกไม่ได้: '.$e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $pair_id = (int)($_POST['id'] ?? 0);
            try {
                $pdo->prepare("DELETE FROM co_teaching_pairs WHERE id=?")->execute([$pair_id]);
                $success = 'ลบคู่สอนร่วมแล้ว';
            } catch (Throwable $e) {
                $err = 'ลบไม่ได้: '.$e->getMessage();
            }
        } elseif ($action === 'delete_all') {
            try {
                $del = $pdo->prepare("DELETE FROM co_teaching_pairs WHERE year_id=? AND term_no=?");
                $del->execute([$year_id, $term_no]);
                $deleted = $del->rowCount();
                $success = "ลบคู่สอนร่วมทั้งหมดแล้ว ({$deleted} รายการ)";
            } catch (Throwable $e) {
                $err = 'ลบไม่ได้: '.$e->getMessage();
            }
        }
    }
}

// ---------- ดึงกำลังสอนทั้งหมด จัดกลุ่มตาม ชั้น/วิชา ----------
$loads_grouped = [];
if ($year_id && $term_no) {
    $sql = "
        SELECT
            tl.id,
            tl.subject_id,
            tl.class_id,
            s.subject_code,
            s.subject_name,
            c.class_name,
            COALESCE(CONCAT(t.first_name,' ',t.last_name), '-') AS teacher_name
        FROM teaching_loads tl
        JOIN subjects s ON s.id = tl.subject_id
        JOIN classes c ON c.id = tl.class_id
        LEFT JOIN teachers t ON t.id = tl.teacher_id
        WHERE tl.academic_year_id = ? AND tl.term_no = ?
    ";
    
    $params = [$year_id, $term_no];
    
    // ✅ กรองตามชั้น
    if ($filter_class_id) {
        $sql .= " AND tl.class_id = ?";
        $params[] = $filter_class_id;
    }
    
    // ✅ กรองตามวิชา
    if ($filter_subject_id) {
        $sql .= " AND tl.subject_id = ?";
        $params[] = $filter_subject_id;
    }
    
    $sql .= " ORDER BY c.class_name, s.subject_code, s.subject_name, tl.id";
    
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $all_loads = $st->fetchAll();
    
    // จัดกลุ่มตาม class_id + subject_id
    foreach ($all_loads as $load) {
        $key = $load['class_id'] . '_' . $load['subject_id'];
        if (!isset($loads_grouped[$key])) {
            $loads_grouped[$key] = [
                'class_name' => $load['class_name'],
                'subject_code' => $load['subject_code'],
                'subject_name' => $load['subject_name'],
                'loads' => []
            ];
        }
        $loads_grouped[$key]['loads'][] = $load;
    }
    
    // กรองเฉพาะกลุ่มที่มี >= 2 ครู
    $loads_grouped = array_filter($loads_grouped, fn($g) => count($g['loads']) >= 2);
}

// ---------- ดึงคู่ที่บันทึกแล้ว ----------
$pairs = [];
if ($year_id && $term_no) {
    $q = "
        SELECT
            p.id,
            p.class_id,
            p.subject_id,
            cm.class_name,
            sm.subject_code,
            sm.subject_name,
            COALESCE(CONCAT(tm.first_name,' ',tm.last_name), '-') AS main_teacher,
            COALESCE(CONCAT(tc.first_name,' ',tc.last_name), '-') AS co_teacher,
            p.note
        FROM co_teaching_pairs p
        JOIN teaching_loads m ON m.id = p.main_load_id
        JOIN teaching_loads c2 ON c2.id = p.co_load_id
        JOIN subjects sm ON sm.id = m.subject_id
        JOIN classes cm ON cm.id = m.class_id
        LEFT JOIN teachers tm ON tm.id = m.teacher_id
        LEFT JOIN teachers tc ON tc.id = c2.teacher_id
        WHERE p.year_id=? AND p.term_no=?
    ";
    
    $params = [$year_id, $term_no];
    
    // ✅ กรองตามชั้น
    if ($filter_class_id) {
        $q .= " AND p.class_id = ?";
        $params[] = $filter_class_id;
    }
    
    // ✅ กรองตามวิชา
    if ($filter_subject_id) {
        $q .= " AND p.subject_id = ?";
        $params[] = $filter_subject_id;
    }
    
    $q .= " ORDER BY cm.class_name, sm.subject_code, p.id DESC";
    
    $st = $pdo->prepare($q);
    $st->execute($params);
    $pairs = $st->fetchAll();
}

// ---------- load IDs ที่มีคู่แล้ว (สำหรับ pre-check checkbox) ----------
$paired_load_ids = [];
if ($year_id && $term_no) {
    $stPaired = $pdo->prepare("
        SELECT DISTINCT main_load_id, co_load_id
        FROM co_teaching_pairs
        WHERE year_id = ? AND term_no = ?
    ");
    $stPaired->execute([$year_id, $term_no]);
    foreach ($stPaired->fetchAll() as $row) {
        $paired_load_ids[(int)$row['main_load_id']] = true;
        $paired_load_ids[(int)$row['co_load_id']] = true;
    }
}

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-7xl mx-auto px-4 mt-8">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">🤝 จับคู่สอนร่วม (Co-Teaching)</h1>
        <button type="button" id="btnDeleteAll" class="px-3 py-2 rounded-xl border border-rose-600 text-rose-600 hover:bg-rose-50 text-sm">
            🗑️ ลบคู่ทั้งหมด
        </button>
    </div>

    <?php if ($err): ?>
        <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-2"><span>❌</span><span><?= esc($err) ?></span></div>
    <?php elseif ($success): ?>
        <div class="mb-3 p-3 rounded bg-emerald-50 text-emerald-700"><?= esc($success) ?></div>
    <?php endif; ?>

    <!-- ✅ ฟอร์มตัวกรอง (ปี/เทอม/ชั้น/วิชา) -->
    <form method="get" class="bg-white rounded-2xl shadow p-4 mb-6 grid grid-cols-1 md:grid-cols-5 gap-3">
        <div>
            <label class="block text-xs mb-1">ปีการศึกษา</label>
            <select name="year_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?= (int)$y['id'] ?>" <?= ((int)$y['id']===$year_id?'selected':'') ?>>
                        <?= esc($y['year_label']) ?><?= $y['is_active'] ? ' (ใช้งาน)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs mb-1">เทอม</label>
            <select name="term_no" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                <?php foreach ($termOptions as $t): ?>
                    <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$term_no) ? 'selected' : ''; ?>><?= esc($t['term_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs mb-1">กรองตามชั้น</label>
            <select name="filter_class_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                <option value="">ทุกชั้น</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id']===$filter_class_id?'selected':'') ?>>
                        <?= esc($c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs mb-1">กรองตามวิชา</label>
            <select name="filter_subject_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                <option value="">ทุกวิชา</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$filter_subject_id?'selected':'') ?>>
                        <?= esc(($s['subject_code'] ? $s['subject_code'].' - ' : '').$s['subject_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end">
            <?php if ($filter_class_id || $filter_subject_id): ?>
                <a href="?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>" 
                   class="text-xs text-blue-600 hover:underline">
                    ✕ ล้างตัวกรอง
                </a>
            <?php else: ?>
                <div class="text-xs text-slate-500">
                    💡 เลือกครูที่สอนร่วมกัน
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!$loads_grouped): ?>
        <div class="bg-white rounded-2xl shadow p-6 text-center text-slate-500">
            <?php if ($filter_class_id || $filter_subject_id): ?>
                ไม่พบกำลังสอนที่มีครู 2 คนขึ้นไปตามตัวกรองที่เลือก
            <?php else: ?>
                ไม่มีกำลังสอนที่มีครู 2 คนขึ้นไปในปี/เทอมนี้
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- ฟอร์มเลือกคู่สอนร่วม -->
        <form method="post" class="bg-white rounded-2xl shadow p-4 mb-6" id="coTeachingForm">
            <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
            <input type="hidden" name="action" value="create_multiple">
            
            <div class="font-medium mb-3 flex items-center justify-between">
                <span>เลือกครูที่ต้องการจับคู่สอนร่วม (เลือกได้หลายคู่พร้อมกัน)</span>
                <span class="text-xs text-slate-500">พบ <?= count($loads_grouped) ?> กลุ่ม</span>
            </div>
            
            <div class="space-y-4">
                <?php foreach ($loads_grouped as $key => $group): ?>
                    <div class="border rounded-xl p-4 bg-slate-50">
                        <div class="font-medium mb-3 flex items-center gap-2">
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs"><?= esc($group['class_name']) ?></span>
                            <span><?= esc($group['subject_code'] ? $group['subject_code'].' - ' : '') ?><?= esc($group['subject_name']) ?></span>
                            <span class="text-xs text-slate-500">(<?= count($group['loads']) ?> ครู)</span>
                        </div>
                        
                        <div class="bg-white rounded-lg p-3 space-y-2">
                            <?php foreach ($group['loads'] as $load): ?>
                                <label class="flex items-center gap-2 p-2 hover:bg-slate-50 rounded cursor-pointer">
                                    <input type="checkbox" 
                                           class="pair-checkbox" 
                                           data-group="<?= esc($key) ?>"
                                           data-load-id="<?= (int)$load['id'] ?>"
                                           value="<?= (int)$load['id'] ?>"
                                           <?= isset($paired_load_ids[(int)$load['id']]) ? 'checked' : '' ?>>
                                    <div class="text-sm flex-1">
                                        <span class="font-medium">โหลด #<?= (int)$load['id'] ?></span> – 
                                        ครู: <?= esc($load['teacher_name']) ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                            
                            <div class="pt-2 border-t">
                                <label class="block text-xs mb-1">หมายเหตุสำหรับกลุ่มนี้ (ไม่บังคับ)</label>
                                <input type="text" 
                                       class="group-note w-full border rounded px-2 py-1 text-sm" 
                                       data-group="<?= esc($key) ?>"
                                       placeholder="เช่น กลุ่มรวม, ทีมสอน">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
                    💾 บันทึกคู่สอนร่วมที่เลือก
                </button>
                <span id="selected-summary" class="text-sm text-slate-500"></span>
            </div>
        </form>

        <!-- รายการคู่ที่บันทึกแล้ว -->
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="font-medium mb-3">คู่สอนร่วมที่บันทึกไว้แล้ว</div>
            <?php if (!$pairs): ?>
                <div class="text-slate-500 text-sm">
                    <?php if ($filter_class_id || $filter_subject_id): ?>
                        ไม่พบคู่สอนร่วมตามตัวกรองที่เลือก
                    <?php else: ?>
                        ยังไม่มีการจับคู่
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="text-left px-3 py-2">ชั้น</th>
                                <th class="text-left px-3 py-2">วิชา</th>
                                <th class="text-left px-3 py-2">ครูคนที่ 1</th>
                                <th class="text-left px-3 py-2">ครูคนที่ 2</th>
                                <th class="text-left px-3 py-2">หมายเหตุ</th>
                                <th class="text-center px-3 py-2">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pairs as $p): ?>
                                <tr class="border-t">
                                    <td class="px-3 py-2"><?= esc($p['class_name']) ?></td>
                                    <td class="px-3 py-2"><?= esc(($p['subject_code']?$p['subject_code'].' ':'').$p['subject_name']) ?></td>
                                    <td class="px-3 py-2"><?= esc($p['main_teacher']) ?></td>
                                    <td class="px-3 py-2"><?= esc($p['co_teacher']) ?></td>
                                    <td class="px-3 py-2"><?= esc($p['note'] ?? '-') ?></td>
                                    <td class="px-3 py-2 text-center">
                                        <form method="post" onsubmit="return ttConfirmSubmit(this,{text:'ลบคู่สอนร่วมนี้?', confirmButtonText:'ลบ'});" class="inline">
                                            <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button class="text-rose-600 hover:underline text-xs">ลบ</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ฟอร์มซ่อนสำหรับลบทั้งหมด -->
<form id="deleteAllForm" method="post" style="display:none;">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <input type="hidden" name="action" value="delete_all">
</form>

<!-- ✅ Custom Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
    <div class="bg-rose-600 px-6 py-4">
      <h3 class="text-white font-semibold text-lg flex items-center gap-2">
        <span class="text-2xl">⚠️</span>
        ยืนยันการลบข้อมูล
      </h3>
    </div>
    <div class="p-6">
      <p class="text-slate-700 mb-4" id="modalMessage"></p>
      <p class="text-sm text-slate-500 bg-slate-50 p-3 rounded-lg" id="modalWarning"></p>
    </div>
    <div class="flex gap-3 px-6 pb-6">
      <button id="modalCancel" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-300 hover:bg-slate-50 transition text-sm font-medium">
        ยกเลิก
      </button>
      <button id="modalConfirm" class="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 text-white hover:bg-rose-700 transition text-sm font-medium">
        ยืนยันการลบ
      </button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('coTeachingForm');
    const checkboxes = document.querySelectorAll('.pair-checkbox');
    const summary = document.getElementById('selected-summary');
    
    // อัปเดตสรุปการเลือก
    function updateSummary() {
        const groups = {};
        
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const group = cb.dataset.group;
                if (!groups[group]) groups[group] = [];
                groups[group].push(cb.dataset.loadId);
            }
        });
        
        const groupCount = Object.keys(groups).length;
        if (groupCount === 0) {
            summary.textContent = '';
        } else {
            summary.textContent = `✓ เลือกแล้ว ${groupCount} กลุ่ม`;
        }
    }
    
    checkboxes.forEach(cb => cb.addEventListener('change', updateSummary));
    updateSummary();
    
    // เมื่อ submit: รวมข้อมูลคู่ที่เลือกเป็น hidden input
    form.addEventListener('submit', function(e) {
        const groups = {};
        
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const group = cb.dataset.group;
                if (!groups[group]) groups[group] = [];
                groups[group].push(cb.dataset.loadId);
            }
        });
        
        // สร้าง hidden input สำหรับแต่ละกลุ่ม
        for (const [groupKey, loadIds] of Object.entries(groups)) {
            if (loadIds.length < 2) continue;
            
            const noteInput = document.querySelector(`.group-note[data-group="${groupKey}"]`);
            const note = noteInput ? noteInput.value : '';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'pairs[]';
            input.value = loadIds.join(',') + '|' + note;
            form.appendChild(input);
        }
    });
    
    // ✅ Custom Modal Logic สำหรับลบทั้งหมด
    const btnDeleteAll = document.getElementById('btnDeleteAll');
    const deleteAllForm = document.getElementById('deleteAllForm');
    const deleteModal = document.getElementById('deleteModal');
    const modalCancel = document.getElementById('modalCancel');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalMessage = document.getElementById('modalMessage');
    const modalWarning = document.getElementById('modalWarning');
    
    let confirmStep = 0;
    
    // ✅ ฟังก์ชันเปิด Modal
    function openDeleteModal() {
        confirmStep = 0;
        modalMessage.textContent = 'คุณแน่ใจหรือไม่ที่จะลบคู่สอนร่วมทั้งหมดในปีการศึกษาและเทอมนี้?';
        modalWarning.innerHTML = '<strong>หมายเหตุ:</strong> การกระทำนี้จะลบคู่สอนร่วมทั้งหมดในปี/เทอมที่เลือก';
        modalConfirm.textContent = 'ยืนยันการลบ';
        modalConfirm.classList.remove('bg-rose-700');
        modalConfirm.classList.add('bg-rose-600');
        deleteModal.classList.remove('hidden');
    }
    
    // ✅ ปุ่มลบทั้งหมด
    if (btnDeleteAll && deleteAllForm) {
        btnDeleteAll.addEventListener('click', function() {
            openDeleteModal();
        });
    }
    
    // ✅ ปุ่มยกเลิก
    modalCancel.addEventListener('click', () => {
        deleteModal.classList.add('hidden');
        confirmStep = 0;
    });
    
    // ✅ ปุ่มยืนยัน (2 ขั้นตอน)
    modalConfirm.addEventListener('click', () => {
        if (confirmStep === 0) {
            // ขั้นที่ 1: แสดงการยืนยันครั้งที่ 2
            confirmStep = 1;
            modalMessage.textContent = '❗ ยืนยันอีกครั้ง: การกระทำนี้ไม่สามารถกู้คืนได้!';
            modalWarning.innerHTML = '<strong class="text-rose-600">คำเตือน:</strong> คู่สอนร่วมทั้งหมดจะถูกลบถาวรและไม่สามารถกู้คืนได้';
            modalConfirm.textContent = 'ยืนยันการลบอีกครั้ง';
            modalConfirm.classList.remove('bg-rose-600');
            modalConfirm.classList.add('bg-rose-700');
        } else {
            // ขั้นที่ 2: ส่งฟอร์มลบ
            deleteModal.classList.add('hidden');
            deleteAllForm.submit();
        }
    });
    
    // ✅ ปิด modal เมื่อคลิกพื้นหลัง
    deleteModal.addEventListener('click', (e) => {
        if (e.target === deleteModal) {
            deleteModal.classList.add('hidden');
            confirmStep = 0;
        }
    });
    
    // ✅ แก้ไข: ใช้ Custom Modal สำหรับลบคู่เดียว
    const deleteSingleForms = document.querySelectorAll('form[onsubmit*="confirm"]');
    deleteSingleForms.forEach(form => {
        form.removeAttribute('onsubmit');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            confirmStep = 0;
            modalMessage.textContent = 'คุณแน่ใจหรือไม่ที่จะลบคู่สอนร่วมนี้?';
            modalWarning.innerHTML = '<strong>หมายเหตุ:</strong> การกระทำนี้ไม่สามารถกู้คืนได้';
            modalConfirm.textContent = 'ยืนยันการลบ';
            modalConfirm.classList.remove('bg-rose-700');
            modalConfirm.classList.add('bg-rose-600');
            deleteModal.classList.remove('hidden');
            
            // เก็บ reference ของฟอร์มที่ต้องการส่ง
            modalConfirm.onclick = function() {
                if (confirmStep === 0) {
                    confirmStep = 1;
                    modalMessage.textContent = '❗ ยืนยันอีกครั้ง: การลบไม่สามารถกู้คืนได้!';
                    modalWarning.innerHTML = '<strong class="text-rose-600">คำเตือน:</strong> ข้อมูลจะถูกลบถาวร';
                    modalConfirm.textContent = 'ยืนยันการลบอีกครั้ง';
                    this.classList.remove('bg-rose-600');
                    this.classList.add('bg-rose-700');
                } else {
                    deleteModal.classList.add('hidden');
                    form.submit();
                }
            };
        });
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
