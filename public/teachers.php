<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_teachers_init($pdo);

// รับพารามิเตอร์ฟิลเตอร์
$kw    = trim($_GET['q'] ?? '');
$group = (isset($_GET['group']) && $_GET['group'] !== '') ? (int)$_GET['group'] : null;

// query string ที่จะส่งกลับมาหลังแก้ไข/ลบ
$_filter_parts = [];
if ($kw !== '') $_filter_parts['q'] = $kw;
if ($group !== null) $_filter_parts['group'] = $group;
$_filter_qs = $_filter_parts ? '?' . http_build_query($_filter_parts) : '';

// สร้าง SQL + เงื่อนไข
$sql = 'SELECT id, teacher_code, username, title, first_name, last_name, first_name_en, last_name_en, email, password_hash, password_plain, subject_group, created_at
        FROM teachers WHERE 1=1';
$params = [];

if ($kw !== '') {
  $sql .= ' AND (teacher_code LIKE :kw OR username LIKE :kw OR first_name LIKE :kw OR last_name LIKE :kw OR first_name_en LIKE :kw OR last_name_en LIKE :kw OR email LIKE :kw)';
  $params['kw'] = '%'.$kw.'%';
}
if ($group !== null) {
  $sql .= ' AND subject_group = :grp';
  $params['grp'] = $group;
}

// เรียงตามรหัสครู
$sql .= ' ORDER BY teacher_code, first_name, last_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

// ระดับชั้นที่สอน: auto (จากตารางสอนปีปัจจุบัน) + manual (ติ๊กเอง) — ดึงครั้งเดียวแบบ bulk
$activeYearId = tt_active_year_id($pdo);
$gradeMaps    = tt_teacher_grade_levels_maps($pdo, $activeYearId);
$gradeOrder   = tt_grade_levels_all($pdo); // ลำดับไว้เรียง chip
$gradeRank    = array_flip($gradeOrder);

$flash = flash_get();

// กลุ่มสาระ (ดึงจากฐานข้อมูล - อัปเดตอัตโนมัติเมื่อมีการแก้ไข)
$groupLabels = teacher_group_options();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-6">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">👨‍🏫 จัดการครู</h1>
      <p class="text-sm text-slate-500 mt-1">ข้อมูลครูผู้สอนทั้งหมด</p>
    </div>
    <div class="flex gap-2">
      <a href="<?= url('teacher_export.php'); ?>"
         class="px-4 py-2.5 rounded-xl border-2 border-teal-300 bg-teal-50 text-teal-700 hover:bg-teal-100 transition-colors text-sm font-medium">
        📥 Export CSV
      </a>
      <a href="<?= url('teacher_export2.php'); ?>"
         class="px-4 py-2.5 rounded-xl border-2 border-cyan-300 bg-cyan-50 text-cyan-700 hover:bg-cyan-100 transition-colors text-sm font-medium">
        📋 Export รายชื่อ
      </a>
      <a href="<?= url('teacher_template.php'); ?>" 
         class="px-4 py-2.5 rounded-xl border-2 border-slate-300 hover:bg-slate-50 transition-colors text-sm font-medium text-slate-700">
        📄 ดาวน์โหลดเทมเพลต
      </a>
      <a href="<?= url('teacher_import.php'); ?>" 
         class="px-4 py-2.5 rounded-xl border-2 border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-colors text-sm font-medium">
        📊 นำเข้า CSV
      </a>
      <a href="<?= url('teacher_create.php'); ?>" 
         class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 text-white hover:from-indigo-700 hover:to-indigo-600 shadow-lg shadow-indigo-500/30 transition-all duration-200 text-sm font-medium">
        ➕ เพิ่มครู
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-6 p-4 rounded-xl <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'; ?> text-sm font-medium shadow-sm">
      <?= $flash['type']==='success' ? '✅' : '❌'; ?> <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <!-- 🔍 ฟิลเตอร์ -->
  <form id="filterForm" method="get" class="bg-white rounded-2xl shadow-xl border border-slate-200 p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-sm font-semibold text-slate-600">🔍 ค้นหาและกรอง</h2>
      <span class="inline-flex items-center px-3 py-1.5 rounded-full bg-indigo-100 text-indigo-700 font-semibold text-sm">
        👨‍🏫 ทั้งหมด <?= count($teachers); ?> คน
      </span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="md:col-span-2">
        <label class="block text-sm font-semibold text-slate-700 mb-2">
          🔍 ค้นหา (รหัส / ชื่อ / นามสกุล)
        </label>
        <input id="filter-q" name="q" value="<?= htmlspecialchars($kw); ?>"
               class="w-full border-2 border-slate-200 rounded-xl px-4 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none" 
               placeholder="เช่น 1001 หรือ สมชาย">
      </div>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-2">
          📚 กลุ่มสาระ
        </label>
        <select id="filter-group" name="group" 
                class="w-full border-2 border-slate-200 rounded-xl px-4 py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all outline-none">
          <option value="">ทั้งหมด</option>
          <?php foreach ($groupLabels as $k=>$v): ?>
            <option value="<?= $k; ?>" <?= $group===$k ? 'selected':''; ?>><?= htmlspecialchars($v); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end">
        <button type="button" id="filter-reset" 
                class="w-full px-4 py-2.5 rounded-xl border-2 border-slate-300 hover:bg-slate-50 transition-colors font-medium text-slate-700">
          🔄 รีเซ็ต
        </button>
      </div>
    </div>
  </form>

  <div class="overflow-x-auto bg-white rounded-2xl shadow-xl border border-slate-200">
    <table class="min-w-full text-sm">
      <thead class="bg-gradient-to-r from-slate-50 to-slate-100 border-b-2 border-slate-200">
        <tr>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">🆔 รหัสประจำตัว</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">👤 ชื่อผู้ใช้</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">คำนำหน้า</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">ชื่อ</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">นามสกุล</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">✉️ อีเมล / 🔑 รหัสผ่าน</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">📚 กลุ่มสาระ</th>
          <th class="text-left px-4 py-4 text-sm font-semibold text-slate-700">🎓 ระดับชั้นที่สอน</th>
          <th class="text-right px-4 py-4 text-sm font-semibold text-slate-700">⚙️ การทำงาน</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teachers as $t): ?>
        <tr class="border-b border-slate-100 hover:bg-indigo-50/50 transition-colors duration-150 last:border-0">
          <td class="px-4 py-4">
            <span class="font-semibold text-slate-800"><?= htmlspecialchars($t['teacher_code']); ?></span>
          </td>
          <td class="px-4 py-4">
            <?php if (!empty($t['username'])): ?>
              <code class="font-mono text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-700"><?= htmlspecialchars($t['username']); ?></code>
            <?php else: ?>
              <span class="text-xs text-slate-300">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-4 text-slate-600"><?= htmlspecialchars($t['title']); ?></td>
          <td class="px-4 py-4 font-medium text-slate-800">
            <?= htmlspecialchars($t['first_name']); ?>
            <?php if (!empty($t['first_name_en'])): ?><div class="text-xs text-slate-400 font-normal"><?= htmlspecialchars($t['first_name_en']); ?></div><?php endif; ?>
          </td>
          <td class="px-4 py-4 font-medium text-slate-800">
            <?= htmlspecialchars($t['last_name']); ?>
            <?php if (!empty($t['last_name_en'])): ?><div class="text-xs text-slate-400 font-normal"><?= htmlspecialchars($t['last_name_en']); ?></div><?php endif; ?>
          </td>
          <td class="px-4 py-4">
            <?php if (!empty($t['email'])): ?>
              <div class="text-sm text-slate-600 break-all">✉️ <?= htmlspecialchars($t['email']); ?></div>
            <?php else: ?>
              <div class="text-xs text-slate-300">— ไม่มีอีเมล —</div>
            <?php endif; ?>
            <?php if (!empty($t['password_plain'])): ?>
              <div class="mt-1 flex items-center gap-1.5">
                <span class="text-xs text-amber-700">🔑</span>
                <code class="tt-pwd font-mono text-xs px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 select-all" data-pwd="<?= htmlspecialchars($t['password_plain']); ?>">••••••••</code>
                <button type="button" class="tt-pwd-toggle text-xs font-medium text-indigo-600 hover:text-indigo-800" data-shown="0">👁️ ดู</button>
              </div>
            <?php elseif (!empty($t['password_hash'])): ?>
              <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200" title="มีรหัสผ่าน แต่ตั้งไว้ก่อนรองรับการดูย้อนหลัง — ตั้งรหัสใหม่หรือ import ซ้ำเพื่อให้ดูได้">🔑 มีรหัสผ่าน (ดูไม่ได้)</span>
            <?php else: ?>
              <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-50 text-slate-400 border border-slate-200">ยังไม่ตั้งรหัสผ่าน</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 border border-indigo-200">
              <?= htmlspecialchars(teacher_group_label((int)$t['subject_group'])); ?>
            </span>
          </td>
          <td class="px-4 py-4">
            <?php
              $tid       = (int)$t['id'];
              $autoG     = $gradeMaps['auto'][$tid]   ?? [];
              $manualG   = $gradeMaps['manual'][$tid] ?? [];
              $autoSet   = array_flip($autoG);
              $allG      = array_values(array_unique(array_merge($autoG, $manualG)));
              usort($allG, fn($a, $b) => ($gradeRank[$a] ?? 999) <=> ($gradeRank[$b] ?? 999));
            ?>
            <?php if ($allG): ?>
              <div class="flex flex-wrap gap-1">
                <?php foreach ($allG as $g): ?>
                  <?php $isAuto = isset($autoSet[$g]); ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $isAuto ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200'; ?>"
                        <?= $isAuto ? 'title="สอนอยู่ในตารางสอน"' : 'title="ติ๊กเอง"'; ?>>
                    <?= htmlspecialchars($g); ?><?= $isAuto ? ' 🔒' : ''; ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span class="text-xs text-slate-300">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-4 text-right">
            <div class="flex items-center justify-end gap-2">
              <a href="<?= url('teacher_edit.php?id='.(int)$t['id'].(($kw!=='')?'&from_q='.urlencode($kw):'').(($group!==null)?'&from_group='.(int)$group:'')); ?>" 
                 class="px-3 py-1.5 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition-colors">
                ✏️ แก้ไข
              </a>
              <form action="<?= url('teacher_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'ยืนยันลบครูคนนี้?'});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$t['id']; ?>">
                <?php if ($kw !== ''): ?><input type="hidden" name="from_q" value="<?= htmlspecialchars($kw); ?>"><?php endif; ?>
                <?php if ($group !== null): ?><input type="hidden" name="from_group" value="<?= (int)$group; ?>"><?php endif; ?>
                <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 transition-colors">
                  🗑️ ลบ
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$teachers): ?>
        <tr>
          <td colspan="9" class="px-4 py-12 text-center">
            <div class="text-6xl mb-4">👨‍🏫</div>
            <p class="text-slate-500 text-lg">ยังไม่มีข้อมูลครู</p>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('filterForm');
  const q = document.getElementById('filter-q');
  const grp = document.getElementById('filter-group');
  const resetBtn = document.getElementById('filter-reset');
  let timer;

  q.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => form.submit(), 400);
  });

  grp.addEventListener('change', () => form.submit());

  resetBtn.addEventListener('click', () => {
    q.value = '';
    grp.value = '';
    form.submit();
  });

  // 👁️ สลับแสดง/ซ่อนรหัสผ่าน
  document.querySelectorAll('.tt-pwd-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const code = btn.parentElement.querySelector('.tt-pwd');
      const shown = btn.dataset.shown === '1';
      if (shown) {
        code.textContent = '••••••••';
        btn.textContent = '👁️ ดู';
        btn.dataset.shown = '0';
      } else {
        code.textContent = code.dataset.pwd;
        btn.textContent = '🙈 ซ่อน';
        btn.dataset.shown = '1';
      }
    });
  });
});
</script>
