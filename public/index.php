<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();

// ดึงปีการศึกษาปัจจุบัน
$activeYear = $pdo->query('SELECT id, year_label FROM academic_years WHERE is_active = 1 ORDER BY year_label DESC LIMIT 1')->fetch();
if (!$activeYear) {
  $activeYear = $pdo->query('SELECT id, year_label FROM academic_years ORDER BY year_label DESC LIMIT 1')->fetch();
}
$year_id = (int)($activeYear['id'] ?? 0);
$year_label = $activeYear['year_label'] ?? '-';
$term_no = (int)($_GET['term_no'] ?? 1);

// ตัวเลขพื้นฐาน
$teachersCount = (int)$pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$classesCount  = (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$subjectsCount = (int)$pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn();

// นับรายการกำลังสอน
$stLoadsCount = $pdo->prepare('SELECT COUNT(*) FROM teaching_loads WHERE academic_year_id = ? AND term_no = ?');
$stLoadsCount->execute([$year_id, $term_no]);
$loadsCount = (int)$stLoadsCount->fetchColumn();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 py-6">
  <!-- Header -->
  <div class="mb-6">
    <h1 class="text-3xl font-bold text-slate-900">ระบบจัดตารางเรียน</h1>
    <p class="text-slate-600 mt-2">ปีการศึกษา <?= htmlspecialchars($year_label) ?> เทอม <?= $term_no ?></p>
  </div>

  <!-- Quick Stats -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-blue-500">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-slate-600">ครูทั้งหมด</p>
          <p class="text-3xl font-bold text-slate-900 mt-1"><?= number_format($teachersCount) ?></p>
        </div>
        <div class="text-4xl">👨‍🏫</div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-purple-500">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-slate-600">ชั้นเรียน</p>
          <p class="text-3xl font-bold text-slate-900 mt-1"><?= number_format($classesCount) ?></p>
        </div>
        <div class="text-4xl">🏫</div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-pink-500">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-slate-600">รายวิชา</p>
          <p class="text-3xl font-bold text-slate-900 mt-1"><?= number_format($subjectsCount) ?></p>
        </div>
        <div class="text-4xl">📚</div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-6 border-l-4 border-orange-500">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-slate-600">กำลังสอน</p>
          <p class="text-3xl font-bold text-slate-900 mt-1"><?= number_format($loadsCount) ?></p>
          <p class="text-xs text-slate-500 mt-1">รายการ</p>
        </div>
        <div class="text-4xl">📋</div>
      </div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="text-lg font-semibold text-slate-900 mb-4">เมนูหลัก</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <a href="<?= url('timetable.php?year_id='.$year_id.'&term_no='.$term_no); ?>" 
         class="block p-4 border-2 border-slate-200 rounded-lg hover:border-slate-900 hover:bg-slate-50 transition-all">
        <div class="text-2xl mb-2">📅</div>
        <div class="font-semibold text-slate-900">ดูตารางเรียน</div>
        <div class="text-sm text-slate-600 mt-1">ตารางเรียนแต่ละชั้น</div>
      </a>

      <a href="<?= url('loads_summary.php?year_id='.$year_id.'&term_no='.$term_no); ?>" 
         class="block p-4 border-2 border-slate-200 rounded-lg hover:border-slate-900 hover:bg-slate-50 transition-all">
        <div class="text-2xl mb-2">📊</div>
        <div class="font-semibold text-slate-900">สรุปกำลังสอน</div>
        <div class="text-sm text-slate-600 mt-1">ข้อมูลการจัดสรรครู</div>
      </a>

      <a href="<?= url('report.php?year_id='.$year_id.'&term_no='.$term_no); ?>" 
         class="block p-4 border-2 border-slate-200 rounded-lg hover:border-slate-900 hover:bg-slate-50 transition-all">
        <div class="text-2xl mb-2">🖨️</div>
        <div class="font-semibold text-slate-900">พิมพ์ตาราง</div>
        <div class="text-sm text-slate-600 mt-1">ออกรายงานตารางเรียน</div>
      </a>
    </div>
  </div>

  <!-- Switch Term -->
  <div class="mt-6 flex justify-center gap-3">
    <a href="<?= url('index.php?term_no=1'); ?>" 
       class="px-6 py-3 rounded-lg border-2 text-sm font-semibold transition-all
              <?= $term_no===1 ? 'bg-slate-900 text-white border-slate-900' : 'bg-white hover:bg-slate-50 border-slate-300'; ?>">
      เทอม 1
    </a>
    <a href="<?= url('index.php?term_no=2'); ?>" 
       class="px-6 py-3 rounded-lg border-2 text-sm font-semibold transition-all
              <?= $term_no===2 ? 'bg-slate-900 text-white border-slate-900' : 'bg-white hover:bg-slate-50 border-slate-300'; ?>">
      เทอม 2
    </a>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
