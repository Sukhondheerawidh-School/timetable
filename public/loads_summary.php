<?php
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/helpers.php';
requireLogin();

// ====== ตัวกรอง ======
$years = $pdo->query("SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC")->fetchAll();
$activeYearId = 0;
foreach ($years as $y) {
  if (!empty($y['is_active'])) { $activeYearId = (int)$y['id']; break; }
}
if (!$activeYearId && $years) $activeYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $activeYearId);
if (isset($_GET['term_no']) && $_GET['term_no'] !== '') {
  $term_no = tt_validate_term_no($pdo, $year_id, (int)$_GET['term_no']);
} else {
  $term_no = tt_validate_term_no($pdo, $year_id, tt_default_term_no_for_year($pdo, $year_id));
}

$termOptions = tt_terms_list($pdo, $year_id);
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// ====== เงื่อนไขค้นหา ======
$kwSql = '';
$kwParams = [];
if ($q !== '') {
  $kwSql = " AND (CONCAT(t.first_name,' ',t.last_name) LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ?)";
  $like = "%{$q}%";
  $kwParams = [$like, $like, $like];
}

// ====== นับจำนวนครูทั้งหมด ======
$countSql = "
  WITH loads AS (
    SELECT DISTINCT tl.teacher_id
    FROM teaching_loads tl
    WHERE tl.academic_year_id=? AND tl.term_no=?
  ),
  teachers_list AS (
    SELECT t.id
    FROM teachers t
    JOIN loads l ON l.teacher_id = t.id
    WHERE 1=1 {$kwSql}
  )
  SELECT COUNT(*) FROM teachers_list
";
$countParams = array_merge([$year_id, $term_no], $kwParams);
$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// ====== ดึงรายการแบบแบ่งหน้า ======
$listSql = "
  WITH loads AS (
    SELECT tl.teacher_id,
           SUM(CAST(tl.periods_per_week AS SIGNED)) AS quota
    FROM teaching_loads tl
    WHERE tl.academic_year_id=? AND tl.term_no=?
    GROUP BY tl.teacher_id
  ),
  used AS (
    SELECT st.teacher_id,
           COUNT(DISTINCT ts.id) AS used_cnt
    FROM timetable_slots ts
    JOIN timetable_slot_teachers st ON st.slot_id = ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=?
    GROUP BY st.teacher_id
  )
  SELECT
    t.id,
    t.teacher_code,
    t.first_name, t.last_name,
    t.subject_group,
    COALESCE(l.quota,0) AS quota,
    COALESCE(u.used_cnt,0) AS used_cnt,
    GREATEST(CAST(COALESCE(l.quota,0) AS SIGNED) - CAST(COALESCE(u.used_cnt,0) AS SIGNED), 0) AS remain
  FROM teachers t
  LEFT JOIN loads l ON l.teacher_id = t.id
  LEFT JOIN used  u ON u.teacher_id = t.id
  WHERE l.teacher_id IS NOT NULL
    {$kwSql}
  ORDER BY t.teacher_code, t.first_name, t.last_name
  LIMIT {$per_page} OFFSET {$offset}
";
$listParams = array_merge([$year_id, $term_no, $year_id, $term_no], $kwParams);
$stmt = $pdo->prepare($listSql);
$stmt->execute($listParams);
$rows = $stmt->fetchAll();

// ====== สรุปภาพรวม ======
$sumSql = "
  WITH loads AS (
    SELECT tl.teacher_id,
           SUM(CAST(tl.periods_per_week AS SIGNED)) AS quota
    FROM teaching_loads tl
    WHERE tl.academic_year_id=? AND tl.term_no=?
    GROUP BY tl.teacher_id
  ),
  used AS (
    SELECT st.teacher_id,
           COUNT(DISTINCT ts.id) AS used_cnt
    FROM timetable_slots ts
    JOIN timetable_slot_teachers st ON st.slot_id = ts.id
    WHERE ts.academic_year_id=? AND ts.term_no=?
    GROUP BY st.teacher_id
  )
  SELECT
    COALESCE(SUM(l.quota),0) AS quota_sum,
    COALESCE(SUM(u.used_cnt),0) AS used_sum
  FROM loads l
  LEFT JOIN used u ON u.teacher_id=l.teacher_id
";
$s = $pdo->prepare($sumSql);
$s->execute([$year_id,$term_no,$year_id,$term_no]);
$sum = $s->fetch() ?: ['quota_sum'=>0,'used_sum'=>0];

include __DIR__.'/../partials/head.php';
include __DIR__.'/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8">
  <h1 class="text-xl font-semibold mb-4">สรุปคาบ/สัปดาห์ตามครู</h1>

  <form method="get" class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
    <div class="md:col-span-4">
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border rounded px-3 py-2">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= $year_id===(int)$y['id']?'selected':''; ?>>
            <?= htmlspecialchars($y['year_label']) ?><?= $y['is_active']?' (ใช้งาน)':'' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-2">
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border rounded px-3 py-2">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$term_no === (int)$t['term_no']) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-4">
      <label class="block text-xs mb-1">ค้นหาครู</label>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="w-full border rounded px-3 py-2" placeholder="พิมพ์ชื่อครู">
    </div>
    <div class="md:col-span-2 flex items-end">
      <button class="w-full px-3 py-2 rounded border">แสดง</button>
    </div>
  </form>

  <div class="grid md:grid-cols-3 gap-3 mb-4">
    <div class="bg-white rounded-2xl shadow p-4">
      <div class="text-xs text-slate-500">จำนวนครูที่มีโหลด</div>
      <div class="text-2xl font-semibold"><?= number_format($total) ?> คน</div>
    </div>
    <div class="bg-white rounded-2xl shadow p-4">
      <div class="text-xs text-slate-500">คาบรวม/สัปดาห์ (กำลัง)</div>
      <div class="text-2xl font-semibold"><?= number_format((int)$sum['quota_sum']) ?></div>
    </div>
    <div class="bg-white rounded-2xl shadow p-4">
      <div class="text-xs text-slate-500">คาบที่ลงแล้ว</div>
      <div class="text-2xl font-semibold"><?= number_format((int)$sum['used_sum']) ?></div>
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 font-medium border-b">รายการครู (หน้า <?= $page ?>/<?= $total_pages ?>)</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2">รหัสครู</th>
            <th class="text-left px-3 py-2">ชื่อ-นามสกุล</th>
            <th class="text-left px-3 py-2">กลุ่มสาระ</th>
            <th class="text-right px-3 py-2">กำลัง</th>
            <th class="text-right px-3 py-2">ที่ลงแล้ว</th>
            <th class="text-right px-3 py-2">คงเหลือ</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr class="border-t hover:bg-slate-50">
            <td class="px-3 py-2 font-medium text-slate-600">
              <?= htmlspecialchars($r['teacher_code'] ?? '-') ?>
            </td>
            <td class="px-3 py-2">
              <?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?>
            </td>
            <td class="px-3 py-2 text-slate-600">
              <?php
                // ✅ แปลงตัวเลขเป็นชื่อกลุ่มสาระ
                $groupId = isset($r['subject_group']) ? (int)$r['subject_group'] : 0;
                echo htmlspecialchars(teacher_group_label($groupId));
              ?>
            </td>
            <td class="px-3 py-2 text-right font-semibold"><?= (int)$r['quota'] ?></td>
            <td class="px-3 py-2 text-right"><?= (int)$r['used_cnt'] ?></td>
            <td class="px-3 py-2 text-right font-semibold <?= ((int)$r['remain']>0?'text-emerald-600':'text-slate-400') ?>">
              <?= (int)$r['remain'] ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?>
          <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">ไม่พบข้อมูล</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="flex gap-2 justify-center mt-4">
    <?php
      $base = 'loads_summary.php?year_id='.$year_id.'&term_no='.$term_no.'&q='.urlencode($q).'&page=';
      $prev = max(1,$page-1); $next = min($total_pages, $page+1);
    ?>
    <a class="px-3 py-1 border rounded <?= $page<=1?'pointer-events-none opacity-50':'' ?>" href="<?= $base.$prev ?>">ก่อนหน้า</a>
    <span class="px-2 py-1 text-slate-500">หน้า <?= $page ?> / <?= $total_pages ?></span>
    <a class="px-3 py-1 border rounded <?= $page>=$total_pages?'pointer-events-none opacity-50':'' ?>" href="<?= $base.$next ?>">ถัดไป</a>
  </div>

  <div class="mt-6">
    <a href="<?= url('loads.php?year_id='.$year_id.'&term_no='.$term_no) ?>" class="px-3 py-2 rounded border hover:bg-slate-50">กลับไปกำหนดกำลังสอน</a>
  </div>
</div>
<?php include __DIR__.'/../partials/footer.php'; ?>
