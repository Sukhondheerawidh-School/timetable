<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$years = $pdo->query('SELECT id, year_label FROM academic_years ORDER BY year_label DESC')->fetchAll();

$err='';

// ===== Selection state (GET-based to refresh term lists safely) =====
$target_year_id = (int)($_GET['target_year_id'] ?? ($years[0]['id'] ?? 0));
$target_term_no = isset($_GET['target_term_no']) && $_GET['target_term_no'] !== ''
  ? (int)$_GET['target_term_no']
  : tt_default_term_no_for_year($pdo, $target_year_id);
$target_term_no = tt_validate_term_no($pdo, $target_year_id, $target_term_no);
$targetTermOptions = tt_terms_list($pdo, $target_year_id);

/** เดาแหล่งที่มาเริ่มต้น */
$default_src_year = $target_year_id;
$default_src_term = $target_term_no;

$targetTermNos = [];
foreach ($targetTermOptions as $t) $targetTermNos[] = (int)$t['term_no'];
sort($targetTermNos);

$prevSameYear = null;
foreach ($targetTermNos as $n) {
  if ($n < $target_term_no) $prevSameYear = $n;
}

if ($prevSameYear !== null) {
  $default_src_year = $target_year_id;
  $default_src_term = (int)$prevSameYear;
} else {
  $foundIdx = 0;
  foreach ($years as $i=>$y) if ((int)$y['id']===$target_year_id) { $foundIdx=$i; break; }
  $default_src_year = (int)($years[$foundIdx+1]['id'] ?? $target_year_id);
  $srcTermOptions0 = tt_terms_list($pdo, $default_src_year);
  $maxTerm = 1;
  foreach ($srcTermOptions0 as $t) $maxTerm = max($maxTerm, (int)$t['term_no']);
  $default_src_term = $maxTerm;
}

$src_year_id = isset($_GET['src_year_id']) && $_GET['src_year_id'] !== ''
  ? (int)$_GET['src_year_id']
  : $default_src_year;
$src_term_no = isset($_GET['src_term_no']) && $_GET['src_term_no'] !== ''
  ? (int)$_GET['src_term_no']
  : $default_src_term;
$src_term_no = tt_validate_term_no($pdo, $src_year_id, $src_term_no);
$srcTermOptions = tt_terms_list($pdo, $src_year_id);
$report='';

// ===== Execute copy (POST only when action=copy) =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'copy'){
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  else {
    $target_year_id = (int)($_POST['target_year_id'] ?? 0);
    $target_term_no = (int)($_POST['target_term_no'] ?? 0);
    $src_year_id = (int)($_POST['src_year_id'] ?? 0);
    $src_term_no = (int)($_POST['src_term_no'] ?? 0);

    if ($target_year_id > 0) $target_term_no = tt_validate_term_no($pdo, $target_year_id, $target_term_no);
    if ($src_year_id > 0) $src_term_no = tt_validate_term_no($pdo, $src_year_id, $src_term_no);

    if (!$target_year_id || !$target_term_no || !$src_year_id || !$src_term_no) $err='เลือกปี/เทอมให้ครบ';
  }

  if (!$err) {
    try{
      $pdo->beginTransaction();

      // ดึงข้อมูลต้นทางทั้งหมด
      $stmt = $pdo->prepare('
        SELECT teacher_id, subject_id, class_id, room_id, periods_per_week, consecutive_slots
        FROM teaching_loads
        WHERE academic_year_id=? AND term_no=?
      ');
      $stmt->execute([$src_year_id,$src_term_no]);
      $rows = $stmt->fetchAll();

      // เตรียม insert-ignore (ไม่ทับถ้า unique ซ้ำ)
      $ins = $pdo->prepare('
        INSERT INTO teaching_loads(academic_year_id, term_no, teacher_id, subject_id, class_id, room_id, periods_per_week, consecutive_slots)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE id=id
      ');

      $added = 0; $skipped=0;
      foreach ($rows as $r){
        $ok = $ins->execute([$target_year_id,$target_term_no,$r['teacher_id'],$r['subject_id'],$r['class_id'],$r['room_id'],$r['periods_per_week'],$r['consecutive_slots']]);
        // rowCount() จะเป็น 1 เฉพาะเพิ่มใหม่
        if ($ok && $ins->rowCount()===1) $added++; else $skipped++;
      }

      $pdo->commit();
      flash_set('success',"คัดลอกสำเร็จ: เพิ่ม {$added} แถว, ข้าม (มีอยู่แล้ว) {$skipped} แถว");
      redirect('loads.php?year_id='.$target_year_id.'&term_no='.$target_term_no);
    }catch(Throwable $e){
      $pdo->rollBack(); $err='ผิดพลาด: '.$e->getMessage();
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">คัดลอกกำลังสอนจากเทอมก่อน</h1>
  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <form method="get" id="filterForm" class="bg-white rounded-2xl shadow p-6 space-y-4">
    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm mb-1">คัดลอกจากปี</label>
        <select name="src_year_id" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id']===$src_year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm mb-1">เทอม</label>
        <select name="src_term_no" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <?php foreach ($srcTermOptions as $t): ?>
            <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$src_term_no) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm mb-1">ไปยังปี</label>
        <select name="target_year_id" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id']; ?>" <?= (int)$y['id']===$target_year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm mb-1">เทอม</label>
        <select name="target_term_no" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <?php foreach ($targetTermOptions as $t): ?>
            <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$target_term_no) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <p class="text-xs text-slate-500">
      ระบบจะคัดลอกรายการ “ครู-วิชา-ชั้น/ห้อง-คาบ/สัปดาห์-คาบติดกัน-ห้องเรียน” ทั้งหมดจากแหล่งที่มา → ปลายทาง
      <br>ถ้ามีรายการซ้ำอยู่แล้วในปลายทาง จะถูกข้ามโดยอัตโนมัติ
    </p>

  </form>

  <form method="post" class="mt-3 flex items-center gap-2">
    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
    <input type="hidden" name="action" value="copy">
    <input type="hidden" name="src_year_id" value="<?= (int)$src_year_id; ?>">
    <input type="hidden" name="src_term_no" value="<?= (int)$src_term_no; ?>">
    <input type="hidden" name="target_year_id" value="<?= (int)$target_year_id; ?>">
    <input type="hidden" name="target_term_no" value="<?= (int)$target_term_no; ?>">

    <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">คัดลอก</button>
    <a href="<?= url('loads.php?year_id='.(int)$target_year_id.'&term_no='.(int)$target_term_no); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
