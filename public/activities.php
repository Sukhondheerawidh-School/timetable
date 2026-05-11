<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

requireLogin();

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
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">🎯 วิชากิจกรรม (เรียนรวม)</h1>
    <div class="flex gap-2">
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
