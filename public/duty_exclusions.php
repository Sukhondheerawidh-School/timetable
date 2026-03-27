<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

tt_duty_init($pdo);

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$activeYearId = 0;
foreach ($years as $y) { if (!empty($y['is_active'])) { $activeYearId = (int)$y['id']; break; } }
if (!$activeYearId && !empty($years)) $activeYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $activeYearId);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);

$err = '';

// Find previous term (same year previous term, or previous academic year last term)
$prev_year_id = 0;
$prev_term_no = 0;
$termList = tt_terms_list($pdo, $year_id);
$termNos = [];
foreach ($termList as $t) { $termNos[] = (int)$t['term_no']; }
sort($termNos);
$idx = array_search($term_no, $termNos, true);
if ($idx !== false && $idx > 0) {
  $prev_year_id = $year_id;
  $prev_term_no = (int)$termNos[$idx - 1];
} else {
  // previous academic year = next item in years array (years sorted DESC)
  $pos = null;
  foreach ($years as $i => $y) {
    if ((int)$y['id'] === $year_id) { $pos = $i; break; }
  }
  if ($pos !== null && isset($years[$pos + 1])) {
    $prev_year_id = (int)$years[$pos + 1]['id'];
    $prevTerms = tt_terms_list($pdo, $prev_year_id);
    $prevTermNos = [];
    foreach ($prevTerms as $t) { $prevTermNos[] = (int)$t['term_no']; }
    sort($prevTermNos);
    if ($prevTermNos) $prev_term_no = (int)$prevTermNos[count($prevTermNos) - 1];
  }
}

$prevLabel = '';
if ($prev_year_id && $prev_term_no) {
  $yLabel = '';
  foreach ($years as $y) {
    if ((int)$y['id'] === $prev_year_id) { $yLabel = (string)$y['year_label']; break; }
  }
  $tName = 'เทอม ' . $prev_term_no;
  foreach (tt_terms_list($pdo, $prev_year_id) as $t) {
    if ((int)$t['term_no'] === $prev_term_no) { $tName = (string)$t['term_name']; break; }
  }
  $prevLabel = trim(($yLabel !== '' ? $yLabel : (string)$prev_year_id) . ' · ' . $tName);
}

// Selectable source term for copy (defaults to previous term)
$src_year_id = (int)($_GET['src_year_id'] ?? ($prev_year_id ?: 0));
if ($src_year_id <= 0 && !empty($years)) $src_year_id = (int)$years[0]['id'];

$src_term_no = isset($_GET['src_term_no']) && $_GET['src_term_no'] !== ''
  ? (int)$_GET['src_term_no']
  : ($prev_term_no ?: 0);
if ($src_year_id > 0) {
  $src_term_no = $src_term_no > 0 ? tt_validate_term_no($pdo, $src_year_id, $src_term_no) : tt_default_term_no_for_year($pdo, $src_year_id);
}

$srcLabel = '';
if ($src_year_id > 0 && $src_term_no > 0) {
  $yLabel = '';
  foreach ($years as $y) {
    if ((int)$y['id'] === $src_year_id) { $yLabel = (string)$y['year_label']; break; }
  }
  $tName = 'เทอม ' . $src_term_no;
  foreach (tt_terms_list($pdo, $src_year_id) as $t) {
    if ((int)$t['term_no'] === $src_term_no) { $tName = (string)$t['term_name']; break; }
  }
  $srcLabel = trim(($yLabel !== '' ? $yLabel : (string)$src_year_id) . ' · ' . $tName);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $year_id = (int)($_POST['year_id'] ?? $year_id);
    $term_no = tt_validate_term_no($pdo, $year_id, (int)($_POST['term_no'] ?? $term_no));

    $action = $_POST['action'] ?? '';

    try {
      if ($action === 'add') {
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!$teacher_id) throw new Exception('กรุณาเลือกครู');

        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO duty_term_exclusions(academic_year_id, term_no, teacher_id, reason) VALUES (?,?,?,?)');
        $ins->execute([$year_id, $term_no, $teacher_id, ($reason === '' ? null : $reason)]);
        $newId = (int)$pdo->lastInsertId();

        // Ensure excluded teachers do not occupy duty slots for the term
        $delAs = $pdo->prepare('DELETE FROM duty_term_assignments WHERE academic_year_id=? AND term_no=? AND teacher_id=?');
        $delAs->execute([$year_id, $term_no, $teacher_id]);
        $pdo->commit();

        logActivity('duty_exclusion_add', 'duty_term_exclusions', $newId ?: null, null, [
          'academic_year_id' => $year_id,
          'term_no' => $term_no,
          'teacher_id' => $teacher_id,
          'reason' => ($reason === '' ? null : $reason),
        ]);

        flash_set('success', 'บันทึกการละเว้นเวรแล้ว');
        redirect('duty_exclusions.php?year_id='.$year_id.'&term_no='.$term_no);
      } elseif ($action === 'copy_from' || $action === 'copy_prev') {
        // Backward compatible: copy_prev uses computed previous term
        if ($action === 'copy_prev') {
          $srcYearId = (int)$prev_year_id;
          $srcTermNo = (int)$prev_term_no;
        } else {
          $srcYearId = (int)($_POST['src_year_id'] ?? 0);
          $srcTermNo = (int)($_POST['src_term_no'] ?? 0);
        }

        if ($srcYearId <= 0) throw new Exception('กรุณาเลือกปีต้นทาง');
        $srcTermNo = tt_validate_term_no($pdo, $srcYearId, $srcTermNo);
        if ($srcYearId === $year_id && $srcTermNo === $term_no) throw new Exception('กรุณาเลือกเทอมต้นทางที่ต่างจากเทอมนี้');

        $pdo->beginTransaction();
        $copy = $pdo->prepare('INSERT IGNORE INTO duty_term_exclusions(academic_year_id, term_no, teacher_id, reason)
          SELECT ?, ?, e.teacher_id, e.reason
          FROM duty_term_exclusions e
          WHERE e.academic_year_id=? AND e.term_no=?');
        $copy->execute([$year_id, $term_no, $srcYearId, $srcTermNo]);
        $insCount = (int)$copy->rowCount();

        // Ensure excluded teachers do not occupy duty slots for the term
        $delAs = $pdo->prepare('DELETE FROM duty_term_assignments
          WHERE academic_year_id=? AND term_no=?
            AND teacher_id IN (
              SELECT teacher_id FROM duty_term_exclusions WHERE academic_year_id=? AND term_no=?
            )');
        $delAs->execute([$year_id, $term_no, $year_id, $term_no]);

        $pdo->commit();

        logActivity('duty_exclusion_copy', 'duty_term_exclusions', null, null, [
          'src_academic_year_id' => $srcYearId,
          'src_term_no' => $srcTermNo,
          'academic_year_id' => $year_id,
          'term_no' => $term_no,
          'added_count' => $insCount,
        ]);

        flash_set('success', 'คัดลอกการละเว้นเวรแล้ว (เพิ่ม '.$insCount.' รายการ)');
        redirect('duty_exclusions.php?year_id='.$year_id.'&term_no='.$term_no.'&src_year_id='.$srcYearId.'&src_term_no='.$srcTermNo);
      } elseif ($action === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        $oldStmt = $pdo->prepare('SELECT * FROM duty_term_exclusions WHERE id=? AND academic_year_id=? AND term_no=?');
        $oldStmt->execute([$id, $year_id, $term_no]);
        $oldRow = $oldStmt->fetch();
        $del = $pdo->prepare('DELETE FROM duty_term_exclusions WHERE id=? AND academic_year_id=? AND term_no=?');
        $del->execute([$id, $year_id, $term_no]);

        if ($oldRow) {
          logDelete('duty_term_exclusions', $id, $oldRow);
        }

        flash_set('success', 'ลบรายการละเว้นแล้ว');
        redirect('duty_exclusions.php?year_id='.$year_id.'&term_no='.$term_no);
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $msg = $e->getMessage();
      if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uniq_term_teacher') !== false) {
        $err = 'ผิดพลาด: ครูคนนี้ถูกละเว้นไว้แล้ว';
      } else {
        $err = 'ผิดพลาด: '.$msg;
      }
    }
  }
}

// Teachers available to exclude (exclude ones already excluded)
$teachersStmt = $pdo->prepare('SELECT t.id, t.teacher_code, t.first_name, t.last_name
  FROM teachers t
  WHERE NOT EXISTS (
    SELECT 1 FROM duty_term_exclusions e
    WHERE e.academic_year_id=? AND e.term_no=? AND e.teacher_id=t.id
  )
  ORDER BY t.teacher_code, t.first_name, t.last_name');
$teachersStmt->execute([$year_id, $term_no]);
$teachers = $teachersStmt->fetchAll();

$exStmt = $pdo->prepare('SELECT e.id, e.teacher_id, e.reason, e.created_at,
    t.teacher_code, t.first_name, t.last_name
  FROM duty_term_exclusions e
  JOIN teachers t ON t.id=e.teacher_id
  WHERE e.academic_year_id=? AND e.term_no=?
  ORDER BY t.teacher_code, t.first_name, t.last_name');
$exStmt->execute([$year_id, $term_no]);
$excluded = $exStmt->fetchAll();

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8">
  <div class="mb-3">
    <h1 class="text-xl font-semibold">🚫 ละเว้นเวร (รายเทอม)</h1>
  </div>

  <?php
    $ttDutyActive = 'exclusions';
    $ttDutyYearId = $year_id;
    $ttDutyTermNo = $term_no;
    include __DIR__ . '/../partials/duty_tabs.php';
  ?>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
    <div class="md:col-span-6">
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <form method="get">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
          <div class="md:col-span-8">
            <select name="year_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
              <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).(!empty($y['is_active'])?' (ใช้งาน)':'') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="md:col-span-4">
            <select name="term_no" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
              <?php foreach (tt_terms_list($pdo, $year_id) as $t): ?>
                <option value="<?= (int)$t['term_no'] ?>" <?= (int)$t['term_no']===$term_no?'selected':''; ?>><?= htmlspecialchars($t['term_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </form>
    </div>
    <div class="md:col-span-6 text-xs text-slate-500">
      ครูที่ถูกละเว้นจะไม่แสดงในหน้า “จัดเวร” และ “สรุปเวร” ของเทอมนี้ (และจะถูกนำออกจากเวรที่เคยลงไว้ในเทอมนี้)
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="font-medium">คัดลอกจากปี/เทอมอื่น</div>
        <div class="text-xs text-slate-500">
          คัดลอกแบบ “รวม/ไม่ซ้ำ” และจะนำครูที่ถูกละเว้นออกจากเวรของเทอมนี้อัตโนมัติ
        </div>
      </div>
    </div>

    <div class="mt-3 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
      <form method="get" class="md:col-span-8 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
        <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">

        <div class="md:col-span-7">
          <label class="block text-xs mb-1">ปีต้นทาง</label>
          <select name="src_year_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$src_year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).(!empty($y['is_active'])?' (ใช้งาน)':'') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-5">
          <label class="block text-xs mb-1">เทอมต้นทาง</label>
          <select name="src_term_no" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
            <?php foreach (tt_terms_list($pdo, $src_year_id) as $t): ?>
              <option value="<?= (int)$t['term_no'] ?>" <?= (int)$t['term_no']===$src_term_no?'selected':''; ?>><?= htmlspecialchars($t['term_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <div class="md:col-span-4 flex items-end">
        <?php
          $canCopy = ($src_year_id > 0 && $src_term_no > 0 && !($src_year_id === $year_id && $src_term_no === $term_no));
          $btnCopyClass = $canCopy ? 'px-4 py-2 rounded-xl bg-slate-900 text-white' : 'px-4 py-2 rounded-xl bg-slate-400 text-white cursor-not-allowed';
          $confirmText = $srcLabel ? ('คัดลอกครูที่ละเว้นจาก "'.$srcLabel.'" มาเทอมนี้?') : 'คัดลอกครูที่ละเว้นมาเทอมนี้?';
        ?>
        <form method="post" class="w-full" onsubmit="return ttConfirmSubmit(this, { title: 'คัดลอกละเว้นเวร', text: <?= json_encode($confirmText, JSON_UNESCAPED_UNICODE); ?>, confirmButtonText: 'คัดลอก' });">
          <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
          <input type="hidden" name="action" value="copy_from">
          <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
          <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
          <input type="hidden" name="src_year_id" value="<?= (int)$src_year_id; ?>">
          <input type="hidden" name="src_term_no" value="<?= (int)$src_term_no; ?>">
          <button class="w-full <?= $btnCopyClass; ?>" <?= $canCopy ? '' : 'disabled'; ?>>คัดลอกตามที่เลือก</button>
          <?php if ($prevLabel && !$canCopy): ?>
            <div class="text-xs text-slate-500 mt-2">ค่าเริ่มต้น: <?= htmlspecialchars($prevLabel); ?></div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="font-medium mb-3">เพิ่มครูที่ละเว้นเวร</div>
    <form method="post" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
      <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">

      <div class="md:col-span-5">
        <label class="block text-xs mb-1">ครู</label>
        <select name="teacher_id" class="w-full border rounded px-3 py-2" required>
          <option value="">-- เลือกครู --</option>
          <?php foreach ($teachers as $t): ?>
            <option value="<?= (int)$t['id']; ?>"><?= htmlspecialchars(($t['teacher_code']? '['.$t['teacher_code'].'] ' : '').$t['first_name'].' '.$t['last_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$teachers): ?>
          <div class="text-xs text-slate-500 mt-1">ไม่มีครูให้เลือก (อาจถูกละเว้นครบแล้ว)</div>
        <?php endif; ?>
      </div>

      <div class="md:col-span-5">
        <label class="block text-xs mb-1">เหตุผล (ไม่บังคับ)</label>
        <input name="reason" class="w-full border rounded px-3 py-2" placeholder="เช่น ฝ่ายบริหาร / ลาคลอด / ไม่จัดเวรเทอมนี้">
      </div>

      <div class="md:col-span-2">
        <?php $btnClass = $teachers ? 'w-full px-4 py-2 rounded bg-slate-900 text-white' : 'w-full px-4 py-2 rounded bg-slate-400 text-white cursor-not-allowed'; ?>
        <button class="<?= $btnClass; ?>" <?= $teachers ? '' : 'disabled'; ?>>บันทึก</button>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b font-medium">รายการครูที่ละเว้น (<?= count($excluded) ?>)</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2">ครู</th>
            <th class="text-left px-3 py-2">เหตุผล</th>
            <th class="text-right px-3 py-2">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$excluded): ?>
            <tr class="border-t">
              <td class="px-3 py-3 text-slate-400" colspan="3">— ยังไม่มีรายการ —</td>
            </tr>
          <?php else: ?>
            <?php foreach ($excluded as $e): ?>
              <tr class="border-t">
                <td class="px-3 py-2 font-medium"><?= htmlspecialchars(($e['teacher_code']? '['.$e['teacher_code'].'] ' : '').$e['first_name'].' '.$e['last_name']); ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($e['reason'] ?? ''); ?></td>
                <td class="px-3 py-2 text-right">
                  <form method="post" class="inline" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบ', text: 'ลบรายการละเว้นเวรนี้?', confirmButtonText: 'ลบ' });">
                    <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
                    <input type="hidden" name="term_no" value="<?= (int)$term_no; ?>">
                    <input type="hidden" name="id" value="<?= (int)$e['id']; ?>">
                    <button class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50">ลบ</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
