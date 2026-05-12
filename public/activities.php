<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

requireAdmin();

function th_dow($n){
  $m=[1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];
  return $m[(int)$n] ?? '-';
}

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

// ✅ กำหนดค่าเริ่มต้นเทอมตามเดือนปัจจุบันถ้าไม่ส่งมา (อิงเทอมที่กำหนดในปี)
if (isset($_GET['term_no']) && $_GET['term_no'] !== '') {
  $term_no = tt_validate_term_no($pdo, $year_id, (int)$_GET['term_no']);
} else {
  $term_no = tt_validate_term_no($pdo, $year_id, tt_default_term_no_for_year($pdo, $year_id));
}

$termOptions = tt_terms_list($pdo, $year_id);

$rooms = $pdo->query('SELECT id, room_code, room_name FROM rooms ORDER BY room_code')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

$sql = <<<SQL
SELECT ag.*, ps.start_time, ps.end_time
FROM activity_groups ag
LEFT JOIN period_slots ps ON ps.period_no = ag.period_no
WHERE ag.academic_year_id = :y AND ag.term_no = :t
ORDER BY ag.day_of_week, ag.period_no, ag.activity_name
SQL;
$st = $pdo->prepare($sql); $st->execute(['y'=>$year_id,'t'=>$term_no]); $rows = $st->fetchAll();

/* โหลด classes & teachers ของแต่ละ activity */
$clsMap=[]; $tchMap=[];
if ($rows){
  $ids = implode(',', array_map('intval', array_column($rows,'id')));

  $r1 = $pdo->query("SELECT ac.activity_id, c.class_name FROM activity_classes ac JOIN classes c ON c.id=ac.class_id WHERE ac.activity_id IN ($ids) ORDER BY c.class_name")->fetchAll();
  foreach($r1 as $r){ $clsMap[$r['activity_id']][] = $r['class_name']; }

  $r2 = $pdo->query("SELECT at.activity_id, t.first_name, t.last_name FROM activity_teachers at JOIN teachers t ON t.id=at.teacher_id WHERE at.activity_id IN ($ids) ORDER BY t.first_name, t.last_name")->fetchAll();
  foreach($r2 as $r){ $tchMap[$r['activity_id']][] = $r['first_name'].' '.$r['last_name']; }
}

$flash = flash_get();

/* ── ข้อมูลสำหรับ panel "ดูตามครู" ── */
$allTeachers = $pdo->query("SELECT id, teacher_code, first_name, last_name FROM teachers ORDER BY teacher_code, first_name")->fetchAll();
$tchActivityMap = []; // teacher_id => [ [activity_name, classes[]] ]
if ($allTeachers) {
  $st2 = $pdo->prepare("
    SELECT at2.teacher_id, ag.activity_name, ag.day_of_week, ag.period_no, ag.is_all_day,
           GROUP_CONCAT(c.class_name ORDER BY c.class_name SEPARATOR ', ') AS classes
    FROM activity_teachers at2
    JOIN activity_groups ag ON ag.id = at2.activity_id
    LEFT JOIN activity_classes ac ON ac.activity_id = ag.id
    LEFT JOIN classes c ON c.id = ac.class_id
    WHERE ag.academic_year_id = ? AND ag.term_no = ?
    GROUP BY at2.teacher_id, ag.id
    ORDER BY at2.teacher_id, ag.day_of_week, ag.period_no
  ");
  $st2->execute([$year_id, $term_no]);
  foreach ($st2->fetchAll() as $row) {
    $tchActivityMap[$row['teacher_id']][] = $row;
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">🎯 วิชากิจกรรม (เรียนรวม)</h1>
    <div class="flex gap-2">
      <button type="button" id="btn-view-activity" class="px-4 py-2 rounded-xl bg-teal-50 border border-teal-300 text-teal-700 hover:bg-teal-100 text-sm font-medium transition">📋 ดูกิจกรรม</button>
      <button type="button" id="btn-view-teacher"  class="px-4 py-2 rounded-xl bg-violet-50 border border-violet-300 text-violet-700 hover:bg-violet-100 text-sm font-medium transition">👨‍🏫 ดูตามครู</button>
      <a href="<?= url('activity_create.php'); ?>" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">➕ เพิ่มกิจกรรม</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <!-- ✅ Filter - Auto Submit -->
  <form method="get" id="filter-form" class="bg-white rounded-2xl shadow p-4 mb-6 grid grid-cols-1 md:grid-cols-3 gap-3">
    <div>
      <label class="block text-xs mb-1 font-semibold">📅 ปีการศึกษา</label>
      <select name="year_id" class="w-full border-2 rounded-lg px-3 py-2 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" onchange="this.form.submit()">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y['id'];?>" <?= (int)$y['id']===$year_id?'selected':''; ?>>
            <?= htmlspecialchars($y['year_label']).($y['is_active']?' 🟢':'');?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs mb-1 font-semibold">📚 เทอม</label>
      <select name="term_no" class="w-full border-2 rounded-lg px-3 py-2 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" onchange="this.form.submit()">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$term_no === (int)$t['term_no']) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end">
      <button type="submit" class="w-full px-3 py-2 rounded-xl border-2 border-slate-300 hover:bg-slate-50 font-semibold transition-colors">
        🔍 กรอง
      </button>
    </div>
  </form>

  <div class="overflow-x-auto bg-white rounded-2xl shadow">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr>
          <th class="text-left px-4 py-3 font-bold">🎯 กิจกรรม</th>
          <th class="text-left px-4 py-3 font-bold">📆 วัน/คาบ (เวลา)</th>
          <th class="text-left px-4 py-3 font-bold">🎓 ชั้น/ห้องที่ร่วม</th>
          <th class="text-left px-4 py-3 font-bold">👨‍🏫 ครูผู้สอน</th>
          <th class="text-left px-4 py-3 font-bold">📍 สถานที่</th>
          <th class="text-right px-4 py-3 font-bold">⚙️ การทำงาน</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr class="border-t hover:bg-slate-50 transition-colors">
            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($r['activity_name']); ?></td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div>
                  <?php if ($r['is_all_day']): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-purple-100 border border-purple-300 text-purple-900 text-xs font-semibold">🌅 ทั้งวัน</span>
                    <div class="text-xs text-slate-500 mt-1"><?= th_dow($r['day_of_week']); ?></div>
                  <?php else: ?>
                    <div><?= th_dow($r['day_of_week']); ?> / คาบ <?= (int)$r['period_no']; ?></div>
                    <?php if ($r['start_time'] && $r['end_time']): ?>
                      <div class="text-xs text-slate-500">(<?= substr($r['start_time'],0,5); ?>–<?= substr($r['end_time'],0,5); ?>)</div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td class="px-4 py-3">
              <?php if (!empty($clsMap[$r['id']])): ?>
                <div class="flex flex-wrap gap-1">
                  <?php foreach ($clsMap[$r['id']] as $cn): ?>
                    <span class="inline-flex px-2 py-1 rounded-lg bg-blue-100 border border-blue-300 text-blue-900 text-xs font-medium"><?= htmlspecialchars($cn); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if (!empty($tchMap[$r['id']])): ?>
                <div class="flex flex-wrap gap-1">
                  <?php foreach ($tchMap[$r['id']] as $tn): ?>
                    <span class="inline-flex px-2 py-1 rounded-lg bg-slate-100 border border-slate-300 text-slate-800 text-xs font-medium"><?= htmlspecialchars($tn); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if ($r['room_id'] && isset($rooms[$r['room_id']])): ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-amber-100 border border-amber-300 text-amber-900 text-xs font-medium">
                  <span>🚪</span>
                  <span><?= htmlspecialchars($rooms[$r['room_id']]['room_code'].' - '.$rooms[$r['room_id']]['room_name']); ?></span>
                </span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right">
              <a class="text-blue-600 hover:underline font-semibold mr-3" href="<?= url('activity_edit.php?id='.(int)$r['id']); ?>">✏️ แก้ไข</a>
              <form action="<?= url('activity_delete.php'); ?>" method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'⚠️ ต้องการลบกิจกรรมนี้?', confirmButtonText:'ลบ'});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id']; ?>">
                <button class="text-rose-600 hover:underline font-semibold">🗑️ ลบ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="6" class="px-4 py-12 text-center">
              <div class="text-slate-400 text-4xl mb-2">📭</div>
              <div class="text-slate-500">ยังไม่มีข้อมูลกิจกรรม</div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<!-- ════════════════════════════════════════════════
     PANEL 1 — ดูกิจกรรม
════════════════════════════════════════════════ -->
<div id="panel-activity" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" id="panel-activity-backdrop"></div>
  <div class="absolute top-0 right-0 h-full w-full max-w-2xl bg-white shadow-2xl flex flex-col">
    <div class="flex items-center justify-between px-5 py-4 border-b bg-teal-50">
      <h2 class="font-semibold text-teal-800">📋 สรุปกิจกรรมทั้งหมด</h2>
      <button id="panel-activity-close" class="text-slate-500 hover:text-slate-800 text-xl leading-none">&times;</button>
    </div>
    <div class="overflow-y-auto flex-1 p-5 space-y-4">
      <?php if (!$rows): ?>
        <p class="text-slate-400 text-sm text-center py-10">ยังไม่มีกิจกรรมในปี/เทอมนี้</p>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <div class="border border-slate-200 rounded-xl p-4 bg-white shadow-sm">
            <div class="font-semibold text-slate-800 mb-2">🎯 <?= htmlspecialchars($r['activity_name']); ?></div>
            <div class="text-xs text-slate-500 mb-2">
              <?php if ($r['is_all_day']): ?>
                🌅 ทั้งวัน — <?= th_dow($r['day_of_week']); ?>
              <?php else: ?>
                📆 <?= th_dow($r['day_of_week']); ?> คาบ <?= (int)$r['period_no']; ?>
                <?php if ($r['start_time'] && $r['end_time']): ?>
                  (<?= substr($r['start_time'],0,5); ?>–<?= substr($r['end_time'],0,5); ?>)
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="mb-2">
              <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">ชั้น/ห้อง</span><br>
              <?php if (!empty($clsMap[$r['id']])): ?>
                <div class="flex flex-wrap gap-1 mt-1">
                  <?php foreach ($clsMap[$r['id']] as $cn): ?>
                    <span class="px-2 py-0.5 rounded-lg bg-blue-100 text-blue-800 text-xs font-medium border border-blue-200"><?= htmlspecialchars($cn); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-slate-400 text-xs">—</span>
              <?php endif; ?>
            </div>
            <div>
              <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">ครูผู้สอน</span><br>
              <?php if (!empty($tchMap[$r['id']])): ?>
                <div class="flex flex-wrap gap-1 mt-1">
                  <?php foreach ($tchMap[$r['id']] as $tn): ?>
                    <span class="px-2 py-0.5 rounded-lg bg-slate-100 text-slate-800 text-xs font-medium border border-slate-200">👨‍🏫 <?= htmlspecialchars($tn); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-slate-400 text-xs">— ยังไม่มีครู</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════
     PANEL 2 — ดูตามครู
════════════════════════════════════════════════ -->
<div id="panel-teacher" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40" id="panel-teacher-backdrop"></div>
  <div class="absolute top-0 right-0 h-full w-full max-w-2xl bg-white shadow-2xl flex flex-col">
    <div class="flex items-center justify-between px-5 py-4 border-b bg-violet-50">
      <h2 class="font-semibold text-violet-800">👨‍🏫 กิจกรรมแยกตามครู</h2>
      <button id="panel-teacher-close" class="text-slate-500 hover:text-slate-800 text-xl leading-none">&times;</button>
    </div>
    <!-- search box -->
    <div class="px-5 py-3 border-b">
      <input id="teacher-search" type="text" placeholder="ค้นหาชื่อครู…"
             class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-violet-400 focus:ring-2 focus:ring-violet-200 outline-none transition">
    </div>
    <div class="overflow-y-auto flex-1 p-5 space-y-3" id="teacher-panel-list">
      <?php foreach ($allTeachers as $tc): ?>
        <?php
          $tid  = (int)$tc['id'];
          $tname = ($tc['teacher_code'] ? '['.$tc['teacher_code'].'] ' : '') . $tc['first_name'].' '.$tc['last_name'];
          $acts  = $tchActivityMap[$tid] ?? [];
        ?>
        <div class="teacher-row border border-slate-200 rounded-xl p-4 bg-white shadow-sm" data-name="<?= htmlspecialchars(mb_strtolower($tname)); ?>">
          <div class="font-medium text-slate-800 mb-2">👤 <?= htmlspecialchars($tname); ?></div>
          <?php if (empty($acts)): ?>
            <div class="flex items-center gap-2">
              <span class="px-2 py-1 rounded-lg bg-rose-50 border border-rose-200 text-rose-600 text-xs font-semibold">⚠️ ยังไม่ลงกิจกรรม</span>
            </div>
          <?php else: ?>
            <div class="space-y-1.5">
              <?php foreach ($acts as $a): ?>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                  <span class="font-medium text-teal-800">🎯 <?= htmlspecialchars($a['activity_name']); ?></span>
                  <span class="text-xs text-slate-500">
                    <?php if ($a['is_all_day']): ?>
                      🌅 ทั้งวัน <?= th_dow($a['day_of_week']); ?>
                    <?php else: ?>
                      📆 <?= th_dow($a['day_of_week']); ?> คาบ <?= (int)$a['period_no']; ?>
                    <?php endif; ?>
                  </span>
                  <?php if ($a['classes']): ?>
                    <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 text-xs border border-blue-200"><?= htmlspecialchars($a['classes']); ?></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$allTeachers): ?>
        <p class="text-slate-400 text-sm text-center py-10">ยังไม่มีข้อมูลครูในระบบ</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  function openPanel(id) {
    document.getElementById(id).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
  function closePanel(id) {
    document.getElementById(id).classList.add('hidden');
    document.body.style.overflow = '';
  }

  document.getElementById('btn-view-activity').addEventListener('click', () => openPanel('panel-activity'));
  document.getElementById('btn-view-teacher').addEventListener('click',  () => openPanel('panel-teacher'));

  document.getElementById('panel-activity-close').addEventListener('click',   () => closePanel('panel-activity'));
  document.getElementById('panel-activity-backdrop').addEventListener('click', () => closePanel('panel-activity'));

  document.getElementById('panel-teacher-close').addEventListener('click',    () => closePanel('panel-teacher'));
  document.getElementById('panel-teacher-backdrop').addEventListener('click',  () => closePanel('panel-teacher'));

  // ค้นหาครู
  document.getElementById('teacher-search').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.teacher-row').forEach(row => {
      row.classList.toggle('hidden', q && !row.dataset.name.includes(q));
    });
  });
})();
</script>
