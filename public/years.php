<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();
requireAdmin();

tt_terms_init($pdo);

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      if ($action === 'set_active_year_term') {
        $activeYearId = (int)($_POST['active_year_id'] ?? 0);
        $activeTermNo = (int)($_POST['active_term_no'] ?? 0);
        if ($activeYearId <= 0) throw new Exception('กรุณาเลือกปีการศึกษา');

        // Ensure settings table exists BEFORE any transactional work.
        // (CREATE TABLE is DDL and can implicitly commit, causing "There is no active transaction".)
        tt_app_settings_init($pdo);

        $chk = $pdo->prepare('SELECT 1 FROM academic_years WHERE id=? LIMIT 1');
        $chk->execute([$activeYearId]);
        if (!$chk->fetchColumn()) throw new Exception('ไม่พบปีการศึกษาที่เลือก');

        $activeTermNo = tt_validate_term_no($pdo, $activeYearId, $activeTermNo);

        // keep existing behavior: one active year in table
        $pdo->exec('UPDATE academic_years SET is_active = 0');
        $st = $pdo->prepare('UPDATE academic_years SET is_active = 1 WHERE id = ?');
        $st->execute([$activeYearId]);

        tt_app_setting_set($pdo, 'active_year_id', (string)$activeYearId);
        tt_app_setting_set($pdo, 'active_term_no', (string)$activeTermNo);
        flash_set('success', 'ตั้งค่า Active ปี/เทอม แล้ว');
        redirect('years.php');
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = 'ผิดพลาด: ' . $e->getMessage();
    }
  }
}

// ดึงปีทั้งหมด
$years = $pdo->query('SELECT id, year_label, is_active, created_at FROM academic_years ORDER BY year_label DESC')->fetchAll();

$active = tt_active_year_term($pdo);
$activeYearId = (int)($active['year_id'] ?? 0);
$activeTermNo = (int)($active['term_no'] ?? 1);

$activeYearLabel = '';
if ($activeYearId > 0) {
  foreach ($years as $y) {
    if ((int)$y['id'] === $activeYearId) { $activeYearLabel = (string)$y['year_label']; break; }
  }
}
$activeTermLabel = ($activeYearId > 0) ? tt_term_label_from_no($pdo, $activeYearId, $activeTermNo) : ('เทอม '.$activeTermNo);

// For the form: allow previewing term list for chosen year via GET
$formYearId = (int)($_GET['active_year_id'] ?? ($activeYearId ?: 0));
if ($formYearId <= 0 && !empty($years)) $formYearId = (int)$years[0]['id'];
$formTermNo = (int)($_GET['active_term_no'] ?? ($activeYearId === $formYearId ? $activeTermNo : 0));
$formTermNo = $formYearId > 0 ? tt_validate_term_no($pdo, $formYearId, $formTermNo) : 1;
$formTerms = $formYearId > 0 ? tt_terms_list($pdo, $formYearId) : [];

// flash
$flash = flash_get();
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

  <div class="max-w-6xl mx-auto px-4">
    <div class="flex items-center justify-between mt-8 mb-4">
      <h1 class="text-xl font-semibold">ปีการศึกษา & เทอม</h1>
      <a href="<?= url('year_create.php'); ?>" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">+ เพิ่มปีการศึกษา</a>
    </div>

    <?php if ($flash): ?>
      <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
        <?= htmlspecialchars($flash['msg']); ?>
      </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow p-6 mb-4">
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div>
          <div class="text-lg font-semibold">ตั้งค่า Active ปี/เทอม (ค่าเริ่มต้นทั้งระบบ)</div>
          <div class="text-sm text-slate-500 mt-1">แก้ปัญหาเลือกเทอมอัตโนมัติตามเดือน (เช่น ทำงานล่วงหน้า)</div>
        </div>
        <div class="text-sm">
          <?php if ($activeYearId > 0): ?>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border bg-slate-50">
              <span class="text-slate-500">กำลังใช้งาน</span>
              <span class="font-semibold text-slate-900">ปี <?= htmlspecialchars($activeYearLabel !== '' ? $activeYearLabel : (string)$activeYearId); ?></span>
              <span class="text-slate-400">·</span>
              <span class="font-semibold text-slate-900"><?= htmlspecialchars($activeTermLabel); ?></span>
            </span>
          <?php else: ?>
            <span class="text-slate-500">ยังไม่ได้ตั้งค่า Active ปี/เทอม</span>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" class="mt-4 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="action" value="set_active_year_term">

        <div class="md:col-span-6">
          <label class="block text-xs mb-1 font-semibold text-slate-600">ปีการศึกษา</label>
          <select name="active_year_id" class="w-full border rounded-xl px-3 py-2" onchange="window.location='<?= url('years.php'); ?>?active_year_id='+encodeURIComponent(this.value)">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id'] === $formYearId ? 'selected' : ''; ?>><?= htmlspecialchars($y['year_label']); ?><?= !empty($y['is_active']) ? ' (Active เดิม)' : ''; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="md:col-span-4">
          <label class="block text-xs mb-1 font-semibold text-slate-600">เทอม</label>
          <select name="active_term_no" class="w-full border rounded-xl px-3 py-2">
            <?php foreach ($formTerms as $t): ?>
              <?php $no = (int)$t['term_no']; $name = (string)($t['term_name'] ?? ('เทอม '.$no)); ?>
              <option value="<?= (int)$no; ?>" <?= $no === $formTermNo ? 'selected' : ''; ?>><?= htmlspecialchars($name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="md:col-span-2">
          <button class="w-full px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">บันทึก</button>
        </div>
      </form>
    </div>

    <div class="grid gap-4">
      <?php if (!$years): ?>
        <div class="bg-white rounded-2xl shadow p-6 text-slate-500">ยังไม่มีปีการศึกษา</div>
      <?php endif; ?>

      <?php foreach ($years as $y): ?>
        <?php
          // ดึงเทอมของปีนี้
          try {
            $stmt = $pdo->prepare('SELECT id, term_no, term_name, start_month, end_month FROM terms WHERE academic_year_id = ? ORDER BY term_no ASC');
            $stmt->execute([$y['id']]);
            $terms = $stmt->fetchAll();
          } catch (Throwable $e) {
            $stmt = $pdo->prepare('SELECT id, term_no FROM terms WHERE academic_year_id = ? ORDER BY term_no ASC');
            $stmt->execute([$y['id']]);
            $terms = $stmt->fetchAll();
          }
        ?>
        <div class="bg-white rounded-2xl shadow border border-slate-200 p-6">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-3">
              <h2 class="text-lg font-semibold">ปีการศึกษา <?= htmlspecialchars($y['year_label']); ?></h2>
              <?php if ($y['is_active']): ?>
                <span class="text-xs px-2 py-1 rounded bg-emerald-100 text-emerald-700">Active</span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
              <?php if (!$y['is_active']): ?>
                <form action="<?= url('year_set_active.php'); ?>" method="post">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="id" value="<?= (int)$y['id']; ?>">
                  <button class="px-3 py-1.5 rounded-lg border hover:bg-slate-50 text-sm">ตั้งเป็น Active</button>
                </form>
              <?php endif; ?>
              <a href="<?= url('year_edit.php?id='.(int)$y['id']); ?>" class="px-3 py-1.5 rounded-lg border hover:bg-slate-50 text-sm">แก้ไข</a>
              <form action="<?= url('year_delete.php'); ?>" method="post" onsubmit="return ttConfirmSubmit(this,{text: <?= json_encode('ยืนยันลบปีการศึกษา '.$y['year_label'].' ? เทอมและข้อมูลที่โยงจะถูกลบด้วย', JSON_UNESCAPED_UNICODE); ?>});">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="id" value="<?= (int)$y['id']; ?>">
                <button class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50 text-sm">ลบ</button>
              </form>
            </div>
          </div>

          <div class="text-sm text-slate-600 mb-4">สร้างเมื่อ: <?= htmlspecialchars($y['created_at']); ?></div>

          <div class="bg-slate-50 rounded-xl p-4">
            <div class="font-medium mb-2">เทอม</div>
            <div class="flex flex-wrap gap-2">
              <?php if ($terms): ?>
                <?php foreach ($terms as $t): ?>
                  <?php
                    $tn = (int)$t['term_no'];
                    $name = trim((string)($t['term_name'] ?? ''));
                    if ($name === '') $name = 'เทอม ' . $tn;
                  ?>
                  <span class="inline-flex items-center px-3 py-1.5 rounded-lg bg-white border"><?= htmlspecialchars($name); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-slate-500">ยังไม่มีเทอม</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-4">
            <div class="flex items-center gap-2">
              <a class="px-3 py-1.5 rounded-lg border hover:bg-slate-50 text-sm" href="<?= url('year_terms.php?year_id='.(int)$y['id']); ?>">ตั้งค่าเทอม</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
