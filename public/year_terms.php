<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();
requireAdmin();

tt_terms_init($pdo);

$year_id = (int)($_GET['year_id'] ?? ($_POST['year_id'] ?? 0));
if ($year_id <= 0) {
  flash_set('error', 'ไม่พบปีการศึกษา');
  redirect('years.php');
}

$yearStmt = $pdo->prepare('SELECT id, year_label, is_active FROM academic_years WHERE id = ? LIMIT 1');
$yearStmt->execute([$year_id]);
$year = $yearStmt->fetch(PDO::FETCH_ASSOC);
if (!$year) {
  flash_set('error', 'ไม่พบปีการศึกษา');
  redirect('years.php');
}

function tt_month_name_th(int $m): string {
  static $months = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน',
    7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
  ];
  return $months[$m] ?? '-';
}

function tt_term_range_text(?int $start, ?int $end): string {
  if (!$start || !$end) return '—';
  $s = tt_month_name_th($start);
  $e = tt_month_name_th($end);
  return $s . ' - ' . $e;
}

function tt_safe_count(PDO $pdo, string $sql, array $params): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';

    try {
      if ($action === 'default_2_terms') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
          'INSERT INTO terms(academic_year_id, term_no, term_name, start_month, end_month)
           VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE term_name=VALUES(term_name), start_month=VALUES(start_month), end_month=VALUES(end_month)'
        );
        $stmt->execute([$year_id, 1, 'เทอม 1', 4, 9]);
        $stmt->execute([$year_id, 2, 'เทอม 2', 10, 3]);
        $pdo->commit();
        flash_set('success', 'ตั้งค่าเริ่มต้น 2 เทอม (เม.ย.-ก.ย. / ต.ค.-มี.ค.) เรียบร้อย');
        redirect('year_terms.php?year_id=' . $year_id);
      }

      if ($action === 'create') {
        $term_no = (int)($_POST['term_no'] ?? 0);
        $term_name = trim((string)($_POST['term_name'] ?? ''));
        $start_month = ($_POST['start_month'] ?? '') !== '' ? (int)$_POST['start_month'] : null;
        $end_month = ($_POST['end_month'] ?? '') !== '' ? (int)$_POST['end_month'] : null;

        if ($term_no <= 0 || $term_no > 255) throw new Exception('เลขเทอมต้องอยู่ระหว่าง 1-255');
        if ($term_name === '') $term_name = 'เทอม ' . $term_no;
        if (($start_month !== null && ($start_month < 1 || $start_month > 12)) || ($end_month !== null && ($end_month < 1 || $end_month > 12))) {
          throw new Exception('เดือนไม่ถูกต้อง');
        }
        if (($start_month === null) !== ($end_month === null)) {
          throw new Exception('กรุณาระบุเดือนเริ่มต้นและเดือนสิ้นสุดให้ครบ หรือเว้นว่างทั้งคู่');
        }

        $stmt = $pdo->prepare('INSERT INTO terms(academic_year_id, term_no, term_name, start_month, end_month) VALUES (?,?,?,?,?)');
        $stmt->execute([$year_id, $term_no, $term_name, $start_month, $end_month]);

        flash_set('success', 'เพิ่มเทอม ' . $term_no . ' เรียบร้อย');
        redirect('year_terms.php?year_id=' . $year_id);
      }

      if ($action === 'update') {
        $term_id = (int)($_POST['term_id'] ?? 0);
        $term_name = trim((string)($_POST['term_name'] ?? ''));
        $start_month = ($_POST['start_month'] ?? '') !== '' ? (int)$_POST['start_month'] : null;
        $end_month = ($_POST['end_month'] ?? '') !== '' ? (int)$_POST['end_month'] : null;

        if ($term_id <= 0) throw new Exception('ไม่พบเทอม');
        if (($start_month !== null && ($start_month < 1 || $start_month > 12)) || ($end_month !== null && ($end_month < 1 || $end_month > 12))) {
          throw new Exception('เดือนไม่ถูกต้อง');
        }
        if (($start_month === null) !== ($end_month === null)) {
          throw new Exception('กรุณาระบุเดือนเริ่มต้นและเดือนสิ้นสุดให้ครบ หรือเว้นว่างทั้งคู่');
        }

        // Keep term_no immutable to avoid breaking existing data.
        $stmt = $pdo->prepare('UPDATE terms SET term_name=?, start_month=?, end_month=? WHERE id=? AND academic_year_id=?');
        $stmt->execute([$term_name, $start_month, $end_month, $term_id, $year_id]);

        flash_set('success', 'บันทึกเทอมเรียบร้อย');
        redirect('year_terms.php?year_id=' . $year_id);
      }

      if ($action === 'delete') {
        $term_id = (int)($_POST['term_id'] ?? 0);
        if ($term_id <= 0) throw new Exception('ไม่พบเทอม');

        $stmt = $pdo->prepare('SELECT term_no FROM terms WHERE id=? AND academic_year_id=? LIMIT 1');
        $stmt->execute([$term_id, $year_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('ไม่พบเทอม');
        $term_no = (int)$row['term_no'];

        // Safety: do not allow deleting a term that already has data.
        $used = 0;
        $used += tt_safe_count($pdo, 'SELECT COUNT(*) FROM teaching_loads WHERE academic_year_id=? AND term_no=?', [$year_id, $term_no]);
        $used += tt_safe_count($pdo, 'SELECT COUNT(*) FROM timetable_slots WHERE academic_year_id=? AND term_no=?', [$year_id, $term_no]);
        $used += tt_safe_count($pdo, 'SELECT COUNT(*) FROM teacher_time_constraints WHERE academic_year_id=? AND term_no=?', [$year_id, $term_no]);
        $used += tt_safe_count($pdo, 'SELECT COUNT(*) FROM activity_groups WHERE academic_year_id=? AND term_no=?', [$year_id, $term_no]);

        if ($used > 0) {
          throw new Exception('ลบไม่ได้: เทอมนี้มีข้อมูลใช้งานแล้ว (กำลังสอน/ตาราง/ข้อจำกัด/กิจกรรม) แนะนำให้ “เปลี่ยนชื่อ” หรือ “ปรับช่วงเดือน” แทน');
        }

        $del = $pdo->prepare('DELETE FROM terms WHERE id=? AND academic_year_id=?');
        $del->execute([$term_id, $year_id]);

        flash_set('success', 'ลบเทอม ' . $term_no . ' เรียบร้อย');
        redirect('year_terms.php?year_id=' . $year_id);
      }

      throw new Exception('คำสั่งไม่ถูกต้อง');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}

// Load terms (with id)
$terms = [];
try {
  $stmt = $pdo->prepare('SELECT id, term_no, term_name, start_month, end_month FROM terms WHERE academic_year_id=? ORDER BY term_no ASC');
  $stmt->execute([$year_id]);
  $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // Fallback if schema update isn't possible
  $stmt = $pdo->prepare('SELECT id, term_no FROM terms WHERE academic_year_id=? ORDER BY term_no ASC');
  $stmt->execute([$year_id]);
  $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>

<div class="max-w-4xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <div>
      <h1 class="text-xl font-semibold">กำหนดเทอม: ปีการศึกษา <?= htmlspecialchars($year['year_label']); ?></h1>
      <div class="text-sm text-slate-500">กำหนดชื่อเทอม + ช่วงเดือน (เพื่อใช้เป็นค่าเริ่มต้นตามเดือน)</div>
    </div>
    <a href="<?= url('years.php'); ?>" class="px-3 py-2 rounded-xl border text-sm">กลับ</a>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-6 mb-6">
    <div class="flex items-center justify-between">
      <div>
        <div class="font-medium">ตั้งค่าเริ่มต้นแบบเดิม (2 เทอม)</div>
        <div class="text-sm text-slate-500">เทอม 1: เม.ย.-ก.ย. | เทอม 2: ต.ค.-มี.ค. (ไม่ลบเทอมอื่น)</div>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
        <input type="hidden" name="action" value="default_2_terms">
        <button class="px-3 py-2 rounded-xl bg-slate-900 text-white text-sm hover:opacity-90">ตั้งค่าเริ่มต้น 2 เทอม</button>
      </form>
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow p-6 mb-6">
    <div class="font-semibold mb-3">เทอมที่มีอยู่</div>

    <?php if (!$terms): ?>
      <div class="text-slate-500">ยังไม่มีเทอม</div>
    <?php endif; ?>

    <div class="grid gap-4">
      <?php foreach ($terms as $t): ?>
        <?php
          $tno = (int)($t['term_no'] ?? 0);
          $tname = trim((string)($t['term_name'] ?? ''));
          if ($tname === '') $tname = 'เทอม ' . $tno;
          $sm = isset($t['start_month']) ? (int)$t['start_month'] : null;
          $em = isset($t['end_month']) ? (int)$t['end_month'] : null;
        ?>
        <div class="rounded-xl border p-4">
          <div class="grid md:grid-cols-12 gap-3 items-end">
            <form method="post" class="contents">
            <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
            <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="term_id" value="<?= (int)$t['id']; ?>">

            <div class="md:col-span-2">
              <label class="block text-xs mb-1 text-slate-600">เลขเทอม</label>
              <div class="px-3 py-2 rounded-lg bg-slate-50 border text-sm"><?= $tno; ?></div>
            </div>

            <div class="md:col-span-4">
              <label class="block text-xs mb-1 text-slate-600">ชื่อเทอม</label>
              <input name="term_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" value="<?= htmlspecialchars($tname); ?>" placeholder="เช่น เทอม1-Summer / เทอม 1 - เมษายน">
            </div>

            <div class="md:col-span-2">
              <label class="block text-xs mb-1 text-slate-600">เริ่มเดือน</label>
              <select name="start_month" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
                <option value="">—</option>
                <?php for ($m=1; $m<=12; $m++): ?>
                  <option value="<?= $m; ?>" <?= ($sm===$m)?'selected':''; ?>><?= $m . ' - ' . htmlspecialchars(tt_month_name_th($m)); ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="md:col-span-2">
              <label class="block text-xs mb-1 text-slate-600">ถึงเดือน</label>
              <select name="end_month" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
                <option value="">—</option>
                <?php for ($m=1; $m<=12; $m++): ?>
                  <option value="<?= $m; ?>" <?= ($em===$m)?'selected':''; ?>><?= $m . ' - ' . htmlspecialchars(tt_month_name_th($m)); ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="md:col-span-2 flex gap-2">
              <button class="px-3 py-2 rounded-xl bg-slate-900 text-white text-sm hover:opacity-90">บันทึก</button>
            </div>

            </form>

            <div class="md:col-span-2">
              <form method="post" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันลบเทอม <?= $tno; ?>', text: 'ยืนยันลบเทอม <?= $tno; ?> ? (ลบได้เฉพาะเทอมที่ยังไม่มีข้อมูลใช้งาน)', confirmButtonText: 'ลบ' });" class="flex justify-end">
                <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="term_id" value="<?= (int)$t['id']; ?>">
                <button class="px-3 py-2 rounded-xl border border-rose-200 text-rose-700 text-sm hover:bg-rose-50">ลบ</button>
              </form>
            </div>

            <div class="md:col-span-12 text-xs text-slate-500">
              ช่วงเดือนปัจจุบัน: <?= htmlspecialchars(tt_term_range_text($sm, $em)); ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow border border-slate-200 p-6">
    <div class="font-semibold mb-3">เพิ่มเทอม</div>

    <form method="post" class="grid md:grid-cols-12 gap-3 items-end">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="year_id" value="<?= (int)$year_id; ?>">
      <input type="hidden" name="action" value="create">

      <div class="md:col-span-2">
        <label class="block text-xs mb-1 text-slate-600">เลขเทอม</label>
        <input name="term_no" type="number" min="1" max="255" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
      </div>

      <div class="md:col-span-4">
        <label class="block text-xs mb-1 text-slate-600">ชื่อเทอม</label>
        <input name="term_name" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" placeholder="เช่น เทอม 3 / Summer / เทอม1-เมษายน">
      </div>

      <div class="md:col-span-2">
        <label class="block text-xs mb-1 text-slate-600">เริ่มเดือน</label>
        <select name="start_month" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="">—</option>
          <?php for ($m=1; $m<=12; $m++): ?>
            <option value="<?= $m; ?>"><?= $m . ' - ' . htmlspecialchars(tt_month_name_th($m)); ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="md:col-span-2">
        <label class="block text-xs mb-1 text-slate-600">ถึงเดือน</label>
        <select name="end_month" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
          <option value="">—</option>
          <?php for ($m=1; $m<=12; $m++): ?>
            <option value="<?= $m; ?>"><?= $m . ' - ' . htmlspecialchars(tt_month_name_th($m)); ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="md:col-span-2">
        <button class="px-3 py-2 rounded-xl bg-slate-900 text-white text-sm hover:opacity-90">เพิ่ม</button>
      </div>

      <div class="md:col-span-12 text-xs text-slate-500">
        หมายเหตุ: ถ้าต้องการให้ระบบ “เลือกเทอมเริ่มต้นตามเดือน” ได้ถูกต้อง แนะนำให้กำหนดช่วงเดือนให้ครบทุกเทอม
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
