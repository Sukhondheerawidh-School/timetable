<?php
/**
 * shift_holiday.php — เวรวันหยุดนักขัตฤกษ์
 * จัดการเวรสำหรับวันหยุดราชการ/นักขัตฤกษ์
 * รองรับ: ลงเวรแบบสุ่ม (spin), ลงแบบ manual, แทนเวร, รายงาน
 */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

/* ── Initialize DB tables ─────────────────────────────────────────── */
(function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS phd_dates (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        academic_year_id INT UNSIGNED NOT NULL,
        holiday_date     DATE NOT NULL,
        holiday_name     VARCHAR(200) NOT NULL DEFAULT '',
        note             VARCHAR(500) NOT NULL DEFAULT '',
        created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_year_date (academic_year_id, holiday_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS phd_assignments (
        id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        phd_date_id        INT UNSIGNED NOT NULL,
        teacher_id         INT UNSIGNED NOT NULL,
        building_id        INT UNSIGNED NULL,
        note               VARCHAR(500) NOT NULL DEFAULT '',
        created_by_user_id INT UNSIGNED NULL,
        created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_date_teacher (phd_date_id, teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Add building_id to existing tables (ignore if column already exists)
    try { $pdo->exec("ALTER TABLE phd_assignments ADD COLUMN building_id INT UNSIGNED NULL AFTER teacher_id"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS phd_substitutions (
        id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        assignment_id      INT UNSIGNED NOT NULL,
        from_teacher_id    INT UNSIGNED NOT NULL,
        to_teacher_id      INT UNSIGNED NOT NULL,
        reason             VARCHAR(500) NOT NULL DEFAULT '',
        created_by_user_id INT UNSIGNED NULL,
        created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_assignment (assignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS phd_exclusions (
        id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        academic_year_id   INT UNSIGNED NOT NULL COMMENT '0 = ถาวรทุกปี',
        teacher_id         INT UNSIGNED NOT NULL,
        reason             VARCHAR(500) NOT NULL DEFAULT '',
        created_by_user_id INT UNSIGNED NULL,
        created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_year_teacher (academic_year_id, teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
})($pdo);

/* ── Helpers ─────────────────────────────────────────────────────── */
function phd_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function phd_redirect(int $year_id, string $tab = 'dates', array $extra = []): never
{
    $p = array_merge(['tab' => $tab, 'year_id' => $year_id], $extra);
    header('Location: ' . url('shift_holiday.php') . '?' . http_build_query($p));
    exit;
}
function phd_date_th(?string $ymd): string
{
    if (!$ymd) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return $ymd;
    static $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    static $days = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    return $days[(int)$dt->format('w')] . ' ' . (int)$dt->format('j') . ' ' .
        $months[(int)$dt->format('n')] . ' ' . ((int)$dt->format('Y') + 543);
}

/* ── Auth & year ─────────────────────────────────────────────────── */
$user    = currentUser();
$isAdmin = is_array($user) && in_array($user['role'] ?? '', ['admin', 'superuser'], true);
$userId  = (int)($user['id'] ?? 0);

$years        = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll(PDO::FETCH_ASSOC);
$activeYearId = tt_active_year_id($pdo);
if ($activeYearId <= 0 && !empty($years)) $activeYearId = (int)$years[0]['id'];
$year_id = (int)($_GET['year_id'] ?? $activeYearId);
if ($year_id <= 0 && !empty($years)) $year_id = (int)$years[0]['id'];

$yearLabel = '';
foreach ($years as $y) {
    if ((int)$y['id'] === $year_id) { $yearLabel = $y['year_label']; break; }
}

$validTabs = ['dates', 'assign', 'substitute', 'report', 'exclude'];
$tab = (string)($_GET['tab'] ?? 'dates');
if (!in_array($tab, $validTabs, true)) $tab = 'dates';

/* ── Load teachers ─────────────────────────────────────────────── */
$allTeachers = $pdo->query(
    'SELECT id, teacher_code, first_name, last_name FROM teachers ORDER BY teacher_code, first_name, last_name'
)->fetchAll(PDO::FETCH_ASSOC);

/* ── Load buildings & teacher→building map ──────────────────────── */
$buildings          = tt_buildings_list($pdo); // [{id, building_name, ...}]
$teacherBuildingMap = []; // teacher_id => [building_id, ...] (same as teacher_buildings table)
if (!empty($buildings)) {
    $tbRows = $pdo->query('SELECT teacher_id, building_id FROM teacher_buildings ORDER BY teacher_id, building_id')
                  ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tbRows as $r) {
        $teacherBuildingMap[(int)$r['teacher_id']][] = (int)$r['building_id'];
    }
}
$selectedBuildingId = (int)($_GET['building_id'] ?? 0);
if ($selectedBuildingId <= 0 && !empty($buildings)) $selectedBuildingId = (int)$buildings[0]['id'];
$selectedBuilding = null;
foreach ($buildings as $b) {
    if ((int)$b['id'] === $selectedBuildingId) { $selectedBuilding = $b; break; }
}

/* ── POST actions ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action     = (string)($_POST['action'] ?? '');
    $postYearId = (int)($_POST['year_id'] ?? $year_id);
    try {

        /* dates */
        if ($action === 'add_date') {
            $dt = trim((string)($_POST['holiday_date'] ?? ''));
            $nm = trim((string)($_POST['holiday_name'] ?? ''));
            $nt = trim((string)($_POST['note'] ?? ''));
            if (!$dt || !$nm) throw new Exception('กรุณากรอกวันที่และชื่อวันหยุด');
            $st = $pdo->prepare('INSERT INTO phd_dates(academic_year_id,holiday_date,holiday_name,note) VALUES(?,?,?,?)');
            $st->execute([$postYearId, $dt, $nm, $nt]);
            flash_set('success', 'เพิ่มวันหยุดแล้ว');
            phd_redirect($postYearId, 'dates');
        }

        if ($action === 'delete_date') {
            $id = (int)($_POST['date_id'] ?? 0);
            // clean up assignments & subs first
            $aIds = $pdo->prepare('SELECT id FROM phd_assignments WHERE phd_date_id=?');
            $aIds->execute([$id]);
            foreach ($aIds->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $pdo->prepare('DELETE FROM phd_substitutions WHERE assignment_id=?')->execute([$aid]);
            }
            $pdo->prepare('DELETE FROM phd_assignments WHERE phd_date_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM phd_dates WHERE id=? AND academic_year_id=?')->execute([$id, $postYearId]);
            flash_set('success', 'ลบวันหยุดแล้ว');
            phd_redirect($postYearId, 'dates');
        }

        /* assign */
        if ($action === 'assign_teacher') {
            $dateId     = (int)($_POST['date_id'] ?? 0);
            $bldId      = (int)($_POST['building_id'] ?? 0);
            $teacherIds = array_values(array_filter(array_map('intval', (array)($_POST['teacher_ids'] ?? []))));
            $note       = trim((string)($_POST['note'] ?? ''));
            if ($dateId <= 0 || empty($teacherIds)) throw new Exception('เลือกครูอย่างน้อย 1 คน');
            $chk = $pdo->prepare('SELECT id FROM phd_dates WHERE id=? AND academic_year_id=?');
            $chk->execute([$dateId, $postYearId]);
            if (!$chk->fetchColumn()) throw new Exception('ไม่พบวันหยุดนี้ในปีที่เลือก');
            $st    = $pdo->prepare('INSERT IGNORE INTO phd_assignments(phd_date_id,teacher_id,building_id,note,created_by_user_id) VALUES(?,?,?,?,?)');
            $added = 0;
            foreach ($teacherIds as $teacherId) {
                $st->execute([$dateId, $teacherId, $bldId ?: null, $note, $userId ?: null]);
                $added += (int)$st->rowCount();
            }
            if ($added <= 0) throw new Exception('ครูที่เลือกทั้งหมดถูกลงเวรวันนี้แล้ว');
            flash_set('success', 'ลงเวร ' . $added . ' คนแล้ว');
            phd_redirect($postYearId, 'assign', ['date_id' => $dateId, 'building_id' => $bldId]);
        }

        if ($action === 'unassign') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $dateId       = (int)($_POST['date_id'] ?? 0);
            $bldId        = (int)($_POST['building_id'] ?? 0);
            $pdo->prepare('DELETE FROM phd_substitutions WHERE assignment_id=?')->execute([$assignmentId]);
            $pdo->prepare('DELETE a FROM phd_assignments a JOIN phd_dates d ON d.id=a.phd_date_id WHERE a.id=? AND d.academic_year_id=?')->execute([$assignmentId, $postYearId]);
            flash_set('success', 'ลบการลงเวรแล้ว');
            phd_redirect($postYearId, 'assign', ['date_id' => $dateId, 'building_id' => $bldId]);
        }

        /* substitute */
        if ($action === 'substitute_set') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $toTeacherId  = (int)($_POST['to_teacher_id'] ?? 0);
            $dateId       = (int)($_POST['date_id'] ?? 0);
            $reason       = trim((string)($_POST['reason'] ?? ''));
            if ($assignmentId <= 0 || $toTeacherId <= 0) throw new Exception('เลือกข้อมูลให้ครบ');
            $as = $pdo->prepare('SELECT a.teacher_id FROM phd_assignments a JOIN phd_dates d ON d.id=a.phd_date_id WHERE a.id=? AND d.academic_year_id=? LIMIT 1');
            $as->execute([$assignmentId, $postYearId]);
            $aRow = $as->fetch(PDO::FETCH_ASSOC);
            if (!$aRow) throw new Exception('ไม่พบรายการลงเวร');
            if ((int)$aRow['teacher_id'] === $toTeacherId) throw new Exception('ครูผู้แทนต้องไม่ใช่คนเดิม');
            $st = $pdo->prepare('INSERT INTO phd_substitutions(assignment_id,from_teacher_id,to_teacher_id,reason,created_by_user_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE to_teacher_id=VALUES(to_teacher_id),reason=VALUES(reason),updated_at=CURRENT_TIMESTAMP');
            $st->execute([$assignmentId, (int)$aRow['teacher_id'], $toTeacherId, $reason, $userId ?: null]);
            flash_set('success', 'บันทึกแทนเวรแล้ว');
            phd_redirect($postYearId, 'substitute', ['date_id' => $dateId]);
        }

        if ($action === 'substitute_clear') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $dateId       = (int)($_POST['date_id'] ?? 0);
            $pdo->prepare('DELETE FROM phd_substitutions WHERE assignment_id=?')->execute([$assignmentId]);
            flash_set('success', 'ล้างการแทนเวรแล้ว');
            phd_redirect($postYearId, 'substitute', ['date_id' => $dateId]);
        }

        /* exclusions */
        if ($action === 'add_exclusion') {
            $teacherIds = array_values(array_filter(array_map('intval', (array)($_POST['teacher_ids'] ?? []))));
            $reason     = trim((string)($_POST['reason'] ?? ''));
            $permanent  = !empty($_POST['permanent']);
            if (empty($teacherIds)) throw new Exception('เลือกครูอย่างน้อย 1 คน');
            $exclYearId = $permanent ? 0 : $postYearId;
            $st    = $pdo->prepare('INSERT IGNORE INTO phd_exclusions(academic_year_id,teacher_id,reason,created_by_user_id) VALUES(?,?,?,?)');
            $added = 0;
            foreach ($teacherIds as $tid) {
                $st->execute([$exclYearId, $tid, $reason, $userId ?: null]);
                $added += (int)$st->rowCount();
            }
            flash_set('success', 'เพิ่มยกเว้น ' . $added . ' คนแล้ว');
            phd_redirect($postYearId, 'exclude');
        }

        if ($action === 'remove_exclusion') {
            $exclId = (int)($_POST['excl_id'] ?? 0);
            $pdo->prepare('DELETE FROM phd_exclusions WHERE id=?')->execute([$exclId]);
            flash_set('success', 'ลบรายการยกเว้นแล้ว');
            phd_redirect($postYearId, 'exclude');
        }

        throw new Exception('ไม่รองรับ action นี้');
    } catch (Throwable $e) {
        flash_set('error', 'ผิดพลาด: ' . $e->getMessage());
        phd_redirect($postYearId, $tab, $selectedDateId ?? [] ? ['date_id' => (int)($_POST['date_id'] ?? 0)] : []);
    }
}

$flash = flash_get(); // ['type'=>..., 'msg'=>...]

/* ── Load dates ────────────────────────────────────────────────── */
$datesStmt = $pdo->prepare('SELECT d.id, d.holiday_date, d.holiday_name, d.note,
    (SELECT COUNT(*) FROM phd_assignments a WHERE a.phd_date_id=d.id) AS assign_cnt
    FROM phd_dates d WHERE d.academic_year_id=? ORDER BY d.holiday_date');
$datesStmt->execute([$year_id]);
$dates = $datesStmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Selected date ─────────────────────────────────────────────── */
$selectedDateId = (int)($_GET['date_id'] ?? 0);
if ($selectedDateId <= 0 && !empty($dates)) $selectedDateId = (int)$dates[0]['id'];
$selectedDate = null;
foreach ($dates as $d0) {
    if ((int)$d0['id'] === $selectedDateId) { $selectedDate = $d0; break; }
}

/* ── Teachers already assigned this year ───────────────────────── */
$assignedThisYear = []; // teacher_id => count
$aySt = $pdo->prepare('SELECT a.teacher_id, COUNT(*) AS cnt FROM phd_assignments a JOIN phd_dates d ON d.id=a.phd_date_id WHERE d.academic_year_id=? GROUP BY a.teacher_id');
$aySt->execute([$year_id]);
while ($r = $aySt->fetch(PDO::FETCH_ASSOC)) {
    $assignedThisYear[(int)$r['teacher_id']] = (int)$r['cnt'];
}

/* ── Assignments for selected date ─────────────────────────────── */
$assignments    = [];
$assignedOnDate = []; // teacher_id => true
if ($selectedDateId > 0) {
    // Filter by building only on the assign tab (substitute tab shows all)
    $bldFilterActive = $tab === 'assign' && $selectedBuildingId > 0 && !empty($buildings);
    $bldWhere  = $bldFilterActive ? ' AND a.building_id=?' : '';
    $bldParams = $bldFilterActive ? [$selectedDateId, $selectedBuildingId] : [$selectedDateId];
    $asSt = $pdo->prepare('SELECT a.id AS assignment_id, a.teacher_id, a.building_id, a.note,
        t.teacher_code, t.first_name, t.last_name,
        s.to_teacher_id, s.reason,
        t2.teacher_code AS sub_code, t2.first_name AS sub_first, t2.last_name AS sub_last
        FROM phd_assignments a
        JOIN teachers t ON t.id=a.teacher_id
        LEFT JOIN phd_substitutions s ON s.assignment_id=a.id
        LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
        WHERE a.phd_date_id=?' . $bldWhere . '
        ORDER BY t.teacher_code, t.first_name');
    $asSt->execute($bldParams);
    while ($r = $asSt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[]                       = $r;
        $assignedOnDate[(int)$r['teacher_id']] = true;
    }
}

/* ── Load exclusions ──────────────────────────────────────────────── */
$exclRecords       = []; // excl_id => row
$excludedTeacherIds = []; // teacher_id => true
$exclSt = $pdo->prepare('SELECT e.id, e.academic_year_id, e.teacher_id, e.reason,
    t.teacher_code, t.first_name, t.last_name
    FROM phd_exclusions e JOIN teachers t ON t.id=e.teacher_id
    WHERE e.academic_year_id=? OR e.academic_year_id=0
    ORDER BY e.academic_year_id DESC, t.teacher_code, t.first_name');
$exclSt->execute([$year_id]);
while ($r = $exclSt->fetch(PDO::FETCH_ASSOC)) {
    $r['permanent'] = (int)$r['academic_year_id'] === 0;
    $exclRecords[(int)$r['id']] = $r;
    $excludedTeacherIds[(int)$r['teacher_id']] = true;
}

$pageTitle = 'เวรวันหยุดนักขัตฤกษ์';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= phd_h($pageTitle) ?> – ระบบตารางสอน</title>
<?php include __DIR__ . '/../partials/head.php'; ?>
<style>
/* Spin display */
#spin-display {
    transition: color 0.06s;
    word-break: break-all;
    padding: .5rem 1rem;
    text-align: center;
    font-size: 1.35rem;
    font-weight: 700;
    min-height: 5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 1rem;
    border: 2px solid #c7d2fe;
    background: linear-gradient(135deg,#eef2ff,#f5f3ff);
    color: #1e1b4b;
    width: 100%;
    max-width: 22rem;
    margin: 0 auto;
}
@keyframes phdSpinDone {
    0%   { transform: scale(1); }
    35%  { transform: scale(1.09); }
    65%  { transform: scale(0.96); }
    100% { transform: scale(1); }
}
.spin-done {
    animation: phdSpinDone .5s ease-out forwards;
    background: linear-gradient(135deg,#d1fae5,#a7f3d0) !important;
    border-color: #10b981 !important;
    color: #064e3b !important;
}
</style>
</head>
<body class="bg-slate-50">
<?php include __DIR__ . '/../partials/navbar.php'; ?>
<main class="flex-1 p-4 md:p-6 max-w-6xl mx-auto w-full">

<?php if (!empty($flash['msg'])): ?>
<div class="mb-4 px-4 py-3 rounded-xl text-sm font-medium <?= ($flash['type'] ?? '') === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' ?>">
  <?= phd_h($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
  <h1 class="text-xl font-semibold">🎌 <?= phd_h($pageTitle) ?></h1>
  <form method="get" class="flex items-center gap-2">
    <input type="hidden" name="tab" value="<?= phd_h($tab) ?>">
    <?php if ($selectedDateId && $tab !== 'dates'): ?>
    <input type="hidden" name="date_id" value="<?= $selectedDateId ?>">
    <?php endif; ?>
    <select name="year_id" onchange="this.form.submit()"
      class="border border-slate-200 rounded-xl px-3 py-2 bg-white text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id'] === $year_id ? 'selected' : '' ?>>
          <?= phd_h($y['year_label']) ?><?= $y['is_active'] ? ' ★' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-6 bg-slate-100 p-1 rounded-2xl w-fit flex-wrap">
  <?php
  $tabDefs = [
    'dates'      => ['🗓️', 'จัดการวันหยุด'],
    'assign'     => ['✅', 'ลงเวร'],
    'substitute' => ['🔄', 'บันทึกแทนเวร'],
    'report'     => ['📊', 'รายงาน'],
    'exclude'    => ['🚫', 'ยกเว้นเวร (' . count($excludedTeacherIds) . ')'],
  ];
  foreach ($tabDefs as $tk => [$ico, $lbl]):
    $isActive = $tab === $tk;
    $href = '?tab=' . $tk . '&year_id=' . $year_id . ($selectedDateId && $tk !== 'dates' ? '&date_id=' . $selectedDateId : '');
  ?>
    <a href="<?= $href ?>"
       class="px-4 py-2 rounded-xl text-sm font-semibold transition
              <?= $isActive ? 'bg-white shadow text-slate-900' : 'text-slate-500 hover:text-slate-800' ?>">
      <?= $ico ?> <?= $lbl ?>
    </a>
  <?php endforeach; ?>
</div>

<?php /* ═══════════════ TAB: DATES ═══════════════ */ if ($tab === 'dates'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <?php if ($isAdmin): ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-4">➕ เพิ่มวันหยุดนักขัตฤกษ์</h2>
    <form method="post" class="space-y-3">
      <input type="hidden" name="action"  value="add_date">
      <input type="hidden" name="year_id" value="<?= $year_id ?>">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">วันที่</label>
        <input type="date" name="holiday_date" required
          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">ชื่อวันหยุด</label>
        <input type="text" name="holiday_name" required placeholder="เช่น วันมาฆบูชา"
          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">หมายเหตุ (ถ้ามี)</label>
        <input type="text" name="note" placeholder="ไม่บังคับ"
          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
      </div>
      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition">
        💾 บันทึก
      </button>
    </form>
  </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-4">📋 รายการวันหยุด (<?= count($dates) ?> วัน) – <?= phd_h($yearLabel) ?></h2>
    <?php if (empty($dates)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีวันหยุด กรุณาเพิ่มทางซ้าย</p>
    <?php else: ?>
      <div class="space-y-2 max-h-[28rem] overflow-y-auto pr-1">
        <?php foreach ($dates as $d0):
          $cnt = (int)$d0['assign_cnt'];
        ?>
        <div class="flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-slate-300 transition">
          <div class="min-w-0 flex-1">
            <div class="font-medium text-sm truncate"><?= phd_h($d0['holiday_name']) ?></div>
            <div class="text-xs text-slate-500"><?= phd_date_th($d0['holiday_date']) ?></div>
            <?php if ($d0['note']): ?><div class="text-xs text-slate-400 truncate"><?= phd_h($d0['note']) ?></div><?php endif; ?>
          </div>
          <div class="flex items-center gap-2 ml-2 flex-shrink-0">
            <span class="text-xs px-2 py-0.5 rounded-full <?= $cnt > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500' ?>">
              <?= $cnt ?> คน
            </span>
            <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= (int)$d0['id'] ?>"
               class="text-xs px-2 py-1 bg-indigo-50 text-indigo-600 rounded-lg hover:bg-indigo-100 transition whitespace-nowrap">ลงเวร</a>
            <?php if ($isAdmin): ?>
            <form method="post" data-confirm="ลบวันหยุดนี้และรายการเวรทั้งหมด?">
              <input type="hidden" name="action"  value="delete_date">
              <input type="hidden" name="year_id" value="<?= $year_id ?>">
              <input type="hidden" name="date_id" value="<?= (int)$d0['id'] ?>">
              <button type="submit" class="text-xs px-2 py-1 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition">ลบ</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php /* ═══════════════ TAB: ASSIGN ═══════════════ */ elseif ($tab === 'assign'): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- Sidebar date list -->
  <div class="bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">วันหยุด</div>
    <?php if (empty($dates)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีวันหยุด <a href="?tab=dates&year_id=<?= $year_id ?>" class="text-indigo-600 underline">เพิ่มก่อน</a></p>
    <?php else: ?>
      <div class="space-y-1">
        <?php foreach ($dates as $d0):
          $isSel = (int)$d0['id'] === $selectedDateId;
          $cnt   = (int)$d0['assign_cnt'];
        ?>
          <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= (int)$d0['id'] ?>&building_id=<?= $selectedBuildingId ?>"
             class="flex justify-between items-center px-3 py-2 rounded-xl text-sm transition
                    <?= $isSel ? 'bg-indigo-600 text-white' : 'hover:bg-slate-50 text-slate-700' ?>">
            <div class="min-w-0">
              <div class="font-medium truncate"><?= phd_h($d0['holiday_name']) ?></div>
              <div class="text-xs opacity-75 truncate"><?= phd_date_th($d0['holiday_date']) ?></div>
            </div>
            <span class="ml-1 flex-shrink-0 text-xs font-bold px-1.5 py-0.5 rounded-full
                         <?= $isSel ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500' ?>">
              <?= $cnt ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Main panel -->
  <div class="lg:col-span-2 space-y-5">
    <?php if (!$selectedDate): ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400">เลือกวันหยุดทางซ้าย</div>
    <?php else: ?>

    <!-- Building selector -->
    <?php if (!empty($buildings)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-3">
      <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">เลือกอาคาร</div>
      <div class="flex gap-2 flex-wrap">
        <?php foreach ($buildings as $b):
          $isBld = (int)$b['id'] === $selectedBuildingId; ?>
        <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= $selectedDateId ?>&building_id=<?= (int)$b['id'] ?>"
           class="px-4 py-2 rounded-xl text-sm font-semibold transition
                  <?= $isBld ? 'bg-indigo-600 text-white shadow' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
          🏫 <?= phd_h($b['building_name']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Current assignments -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <h2 class="font-semibold"><?= phd_h($selectedDate['holiday_name']) ?></h2>
      <p class="text-sm text-slate-500 mb-4"><?= phd_date_th($selectedDate['holiday_date']) ?></p>
      <?php if (empty($assignments)): ?>
        <p class="text-sm text-slate-400">ยังไม่มีครูลงเวร</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($assignments as $a): ?>
          <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-100">
            <div>
              <span class="font-medium text-sm">
                <?= phd_h(($a['teacher_code'] ? $a['teacher_code'] . ' – ' : '') . $a['first_name'] . ' ' . $a['last_name']) ?>
              </span>
              <?php if (!empty($a['to_teacher_id'])): ?>
                <span class="ml-2 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">
                  แทน → <?= phd_h($a['sub_first'] . ' ' . $a['sub_last']) ?>
                </span>
              <?php endif; ?>
              <?php if ($a['note']): ?><div class="text-xs text-slate-400 mt-0.5"><?= phd_h($a['note']) ?></div><?php endif; ?>
            </div>
            <?php if ($isAdmin): ?>
            <form method="post" data-confirm="ลบครูนี้ออกจากเวร?">
              <input type="hidden" name="action"        value="unassign">
              <input type="hidden" name="year_id"       value="<?= $year_id ?>">
              <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
              <input type="hidden" name="date_id"       value="<?= $selectedDateId ?>">
              <input type="hidden" name="building_id"   value="<?= $selectedBuildingId ?>">
              <button type="submit" class="text-xs text-red-500 hover:text-red-700 transition font-medium">✕ ลบ</button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Assign modes -->
    <?php if ($isAdmin): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <div class="flex gap-2 mb-5">
        <button onclick="phdShowMode('random')" id="btn-random"
          class="px-4 py-2 text-sm font-semibold rounded-xl bg-indigo-600 text-white transition">
          🎲 สุ่ม (Random)
        </button>
        <button onclick="phdShowMode('manual')" id="btn-manual"
          class="px-4 py-2 text-sm font-semibold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition">
          ✍️ เลือกเอง (Manual)
        </button>
      </div>

      <!-- ── Random mode ── -->
      <div id="mode-random">
        <label class="inline-flex items-center gap-2 text-sm mb-5 cursor-pointer select-none">
          <input type="checkbox" id="chk-exclude-year" class="rounded accent-indigo-600" checked>
          ไม่รวมครูที่เคยลงเวรแล้วในปีนี้
        </label>
        <div class="flex flex-col items-center gap-4 py-2">
          <div id="spin-display">กด "สุ่ม" เพื่อเริ่ม</div>
          <p id="spin-chosen-name" class="text-sm text-slate-500 hidden"></p>
          <div class="flex gap-3 flex-wrap justify-center">
            <button onclick="phdDoSpin()" id="spin-btn"
              class="px-7 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition shadow">
              🎲 สุ่ม!
            </button>
            <form method="post" id="spin-confirm-form">
              <input type="hidden" name="action"     value="assign_teacher">
              <input type="hidden" name="year_id"    value="<?= $year_id ?>">
              <input type="hidden" name="date_id"    value="<?= $selectedDateId ?>">
              <input type="hidden" name="teacher_ids[]" id="spin-teacher-id" value="">
              <input type="hidden" name="building_id" value="<?= $selectedBuildingId ?>">
              <button type="submit" id="spin-confirm"
                class="hidden px-7 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl transition shadow">
                ✅ ยืนยัน
              </button>
            </form>
            <button onclick="phdResetSpin()" id="spin-reset"
              class="hidden px-4 py-3 bg-slate-200 hover:bg-slate-300 text-slate-700 font-semibold rounded-xl transition">
              🔁 สุ่มใหม่
            </button>
          </div>
        </div>
      </div>

      <!-- ── Manual mode ── -->
      <div id="mode-manual" class="hidden">
        <label class="inline-flex items-center gap-2 text-sm mb-4 cursor-pointer select-none">
          <input type="checkbox" id="chk-manual-exclude" class="rounded accent-indigo-600" checked>
          ไม่รวมครูที่เคยลงเวรแล้วในปีนี้
        </label>
        <form method="post" class="space-y-3" id="manual-assign-form">
          <input type="hidden" name="action"  value="assign_teacher">
          <input type="hidden" name="year_id" value="<?= $year_id ?>">
          <input type="hidden" name="date_id" value="<?= $selectedDateId ?>">
          <input type="hidden" name="building_id" value="<?= $selectedBuildingId ?>">
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="text-xs font-medium text-slate-600">เลือกครู <span class="text-slate-400">(เลือกได้หลายคน)</span></label>
              <label class="inline-flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
                <input type="checkbox" id="chk-select-all" class="rounded accent-indigo-600">
                เลือกทั้งหมดที่มี
              </label>
            </div>
            <div id="manual-teacher-list"
              class="max-h-64 overflow-y-auto border border-slate-200 rounded-xl divide-y divide-slate-100">
              <?php foreach ($allTeachers as $t):
                $tid        = (int)$t['id'];
                $yearCnt    = $assignedThisYear[$tid] ?? 0;
                $today      = isset($assignedOnDate[$tid]);
                $isExcluded = isset($excludedTeacherIds[$tid]);
              ?>
              <label class="flex items-center gap-3 px-3 py-2 hover:bg-indigo-50 cursor-pointer manual-teacher-item transition"
                data-year-cnt="<?= $yearCnt ?>"
                data-today="<?= $today ? 1 : 0 ?>"
                data-excluded="<?= $isExcluded ? 1 : 0 ?>"
                data-buildings="<?= implode(',', $teacherBuildingMap[$tid] ?? []) ?>">
                <input type="checkbox" name="teacher_ids[]" value="<?= $tid ?>"
                  class="rounded accent-indigo-600 flex-shrink-0 manual-teacher-chk">
                <span class="text-sm flex-1 min-w-0">
                  <?= phd_h(($t['teacher_code'] ? $t['teacher_code'] . ' – ' : '') . $t['first_name'] . ' ' . $t['last_name']) ?>
                  <?php if ($isExcluded): ?>
                    <span class="text-xs text-red-500 ml-1">🚫 ยกเว้น</span>
                  <?php elseif ($yearCnt): ?>
                    <span class="text-xs text-slate-400 ml-1">[เคยลง <?= $yearCnt ?> ครั้ง]</span>
                  <?php endif; ?>
                  <?php if ($today): ?>
                    <span class="text-xs text-amber-500 ml-1">⚠️ ลงวันนี้แล้ว</span>
                  <?php endif; ?>
                </span>
              </label>
              <?php endforeach; ?>
            </div>
            <p id="manual-selected-count" class="text-xs text-slate-500 mt-1.5">เลือก 0 คน</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">หมายเหตุ (ถ้ามี)</label>
            <input type="text" name="note" placeholder="ไม่บังคับ"
              class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
          </div>
          <button type="submit" id="manual-submit-btn" disabled
            class="w-full bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed text-white font-semibold text-sm px-4 py-2 rounded-xl transition">
            ✅ ลงเวร (<span id="manual-submit-count">0</span> คน)
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // selectedDate ?>
  </div>

</div>

<?php /* ═══════════════ TAB: SUBSTITUTE ═══════════════ */ elseif ($tab === 'substitute'): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- Sidebar -->
  <div class="bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">วันหยุด</div>
    <?php if (empty($dates)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีวันหยุด</p>
    <?php else: ?>
      <div class="space-y-1">
        <?php foreach ($dates as $d0):
          $isSel = (int)$d0['id'] === $selectedDateId;
        ?>
          <a href="?tab=substitute&year_id=<?= $year_id ?>&date_id=<?= (int)$d0['id'] ?>"
             class="flex justify-between items-center px-3 py-2 rounded-xl text-sm transition
                    <?= $isSel ? 'bg-indigo-600 text-white' : 'hover:bg-slate-50 text-slate-700' ?>">
            <div class="min-w-0">
              <div class="font-medium truncate"><?= phd_h($d0['holiday_name']) ?></div>
              <div class="text-xs opacity-75 truncate"><?= phd_date_th($d0['holiday_date']) ?></div>
            </div>
            <span class="ml-1 flex-shrink-0 text-xs px-1.5 py-0.5 rounded-full
                         <?= $isSel ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500' ?>">
              <?= (int)$d0['assign_cnt'] ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Substitute panel -->
  <div class="lg:col-span-2 space-y-4">
    <?php if (!$selectedDate): ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400">เลือกวันหยุดทางซ้าย</div>
    <?php elseif (empty($assignments)): ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center text-slate-400">
        วันนี้ยังไม่มีครูลงเวร<br>
        <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= $selectedDateId ?>" class="text-indigo-600 text-sm underline mt-2 inline-block">ไปลงเวรก่อน</a>
      </div>
    <?php else: ?>
      <h2 class="font-semibold text-lg"><?= phd_h($selectedDate['holiday_name']) ?> — <?= phd_date_th($selectedDate['holiday_date']) ?></h2>
      <?php foreach ($assignments as $a):
        $hasSub = !empty($a['to_teacher_id']);
        $aid    = (int)$a['assignment_id'];
      ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-sm flex-shrink-0">
            <?= mb_substr($a['first_name'], 0, 1, 'UTF-8') ?>
          </div>
          <div class="min-w-0">
            <div class="font-semibold text-sm"><?= phd_h(($a['teacher_code'] ? $a['teacher_code'] . ' – ' : '') . $a['first_name'] . ' ' . $a['last_name']) ?></div>
            <?php if ($hasSub): ?>
              <div class="text-xs text-amber-600 font-medium">แทนโดย: <?= phd_h($a['sub_first'] . ' ' . $a['sub_last']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($hasSub): ?>
            <span class="ml-auto flex-shrink-0 px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-medium rounded-full">มีผู้แทน</span>
          <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
        <form method="post" class="space-y-2 border-t border-slate-100 pt-4">
          <input type="hidden" name="year_id"       value="<?= $year_id ?>">
          <input type="hidden" name="date_id"       value="<?= $selectedDateId ?>">
          <input type="hidden" name="assignment_id" value="<?= $aid ?>">
          <div class="flex gap-2 flex-wrap">
            <select name="to_teacher_id" required
              class="flex-1 min-w-0 border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
              <option value="">— เลือกผู้แทน —</option>
              <?php foreach ($allTeachers as $t2):
                if ((int)$t2['id'] === (int)$a['teacher_id']) continue;
              ?>
                <option value="<?= (int)$t2['id'] ?>"
                  <?= ($hasSub && (int)$a['to_teacher_id'] === (int)$t2['id']) ? 'selected' : '' ?>>
                  <?= phd_h(($t2['teacher_code'] ? $t2['teacher_code'] . ' – ' : '') . $t2['first_name'] . ' ' . $t2['last_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="reason" placeholder="เหตุผล (ถ้ามี)"
              value="<?= phd_h($a['reason'] ?? '') ?>"
              class="flex-1 min-w-0 border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
          </div>
          <div class="flex gap-2">
            <button type="submit" name="action" value="substitute_set"
              class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition">
              💾 บันทึกแทนเวร
            </button>
            <?php if ($hasSub): ?>
            <button type="submit" name="action" value="substitute_clear"
              data-confirm="ล้างผู้แทนเวรนี้?"
              class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-semibold rounded-xl transition">
              ✕ ล้าง
            </button>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<?php /* ═══════════════ TAB: REPORT ═══════════════ */ elseif ($tab === 'report'):
  // Build report data
  $rStmt = $pdo->prepare('SELECT d.holiday_date, d.holiday_name,
      a.id AS assignment_id, a.teacher_id,
      t.teacher_code, t.first_name, t.last_name,
      s.to_teacher_id,
      t2.first_name AS sub_first, t2.last_name AS sub_last
      FROM phd_dates d
      LEFT JOIN phd_assignments a ON a.phd_date_id=d.id
      LEFT JOIN teachers t ON t.id=a.teacher_id
      LEFT JOIN phd_substitutions s ON s.assignment_id=a.id
      LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
      WHERE d.academic_year_id=?
      ORDER BY d.holiday_date, t.teacher_code, t.first_name');
  $rStmt->execute([$year_id]);
  $rRows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

  // Group by date
  $report = [];
  foreach ($rRows as $rr) {
      $dt = $rr['holiday_date'];
      if (!isset($report[$dt])) {
          $report[$dt] = ['name' => $rr['holiday_name'], 'items' => []];
      }
      if ($rr['teacher_id']) $report[$dt]['items'][] = $rr;
  }

  // Teacher summary
  $tSummary = [];
  foreach ($rRows as $rr) {
      if (!$rr['teacher_id']) continue;
      $tid = (int)$rr['teacher_id'];
      if (!isset($tSummary[$tid])) {
          $tSummary[$tid] = [
              'name'  => ($rr['teacher_code'] ? $rr['teacher_code'] . ' – ' : '') . $rr['first_name'] . ' ' . $rr['last_name'],
              'count' => 0,
          ];
      }
      $tSummary[$tid]['count']++;
  }
  uasort($tSummary, fn($a, $b) => $b['count'] - $a['count']);

  $totalAssign = array_sum(array_column($dates, 'assign_cnt'));
  $subCount    = 0;
  foreach ($rRows as $rr) { if (!empty($rr['to_teacher_id'])) $subCount++; }
  $exportUrl = url('shift_holiday_export.php') . '?year_id=' . $year_id;
?>
<div class="space-y-6">

  <!-- Export bar -->
  <div class="flex flex-wrap gap-2 items-center justify-between">
    <div class="text-sm font-semibold text-slate-600">📊 รายงานเวรวันหยุดนักขัตฤกษ์ ปี <?= phd_h($yearLabel) ?></div>
    <div class="flex gap-2 flex-wrap">
      <?php if (!empty($buildings)): ?>
        <?php foreach ($buildings as $b): ?>
          <a href="<?= phd_h($exportUrl . '&building_id=' . (int)$b['id']) ?>" target="_blank"
             class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-xl border border-indigo-200 transition">
            🖨 พิมพ์ <?= phd_h($b['building_name']) ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
      <a href="<?= phd_h($exportUrl) ?>" target="_blank"
         class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white text-xs font-semibold rounded-xl transition">
        🖨 พิมพ์ทุกอาคาร
      </a>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-indigo-600"><?= count($dates) ?></div>
      <div class="text-xs text-slate-500 mt-1">วันหยุดทั้งหมด</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-slate-700"><?= $totalAssign ?></div>
      <div class="text-xs text-slate-500 mt-1">รายการลงเวรทั้งหมด</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-green-600"><?= count($tSummary) ?></div>
      <div class="text-xs text-slate-500 mt-1">ครูที่เคยลงเวร</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-amber-600"><?= $subCount ?></div>
      <div class="text-xs text-slate-500 mt-1">รายการแทนเวร</div>
    </div>
  </div>

  <!-- Detail table -->
  <div class="bg-white rounded-2xl border border-slate-200 p-5 overflow-x-auto">
    <h2 class="font-semibold mb-4">📋 รายละเอียดเวรแต่ละวัน</h2>
    <?php if (empty($report)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีข้อมูล</p>
    <?php else: ?>
    <table class="w-full text-sm border-collapse">
      <thead>
        <tr class="border-b-2 border-slate-200">
          <th class="text-left py-2 pr-4 font-semibold text-slate-600 whitespace-nowrap">วันที่</th>
          <th class="text-left py-2 pr-4 font-semibold text-slate-600 whitespace-nowrap">ชื่อวันหยุด</th>
          <th class="text-left py-2 font-semibold text-slate-600">ครูลงเวร</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($report as $dt => $dr): ?>
        <tr class="border-b border-slate-100 hover:bg-slate-50">
          <td class="py-2 pr-4 text-slate-500 align-top whitespace-nowrap"><?= phd_date_th($dt) ?></td>
          <td class="py-2 pr-4 font-medium align-top whitespace-nowrap"><?= phd_h($dr['name']) ?></td>
          <td class="py-2 align-top">
            <?php if (empty($dr['items'])): ?>
              <span class="text-slate-300 text-xs">— ยังไม่มีครู —</span>
            <?php else: ?>
              <div class="flex flex-wrap gap-1.5">
                <?php foreach ($dr['items'] as $it):
                  $hasSub = !empty($it['to_teacher_id']);
                ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                               <?= $hasSub ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' ?>">
                    <?= phd_h($it['first_name'] . ' ' . $it['last_name']) ?>
                    <?php if ($hasSub): ?>
                      <span class="opacity-60">→ <?= phd_h($it['sub_first'] . ' ' . $it['sub_last']) ?></span>
                    <?php endif; ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Teacher summary -->
  <?php if (!empty($tSummary)): ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-4">👩‍🏫 สรุปจำนวนเวรต่อครู</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
      <?php foreach ($tSummary as $tid => $ts): ?>
        <div class="flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-slate-300 transition">
          <span class="text-sm font-medium truncate"><?= phd_h($ts['name']) ?></span>
          <span class="ml-2 flex-shrink-0 text-sm font-bold text-indigo-600"><?= $ts['count'] ?> ครั้ง</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php /* ═══════════════ TAB: EXCLUDE ═══════════════ */ elseif ($tab === 'exclude'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- Add exclusion form -->
  <?php if ($isAdmin): ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-1">🚫 เพิ่มรายการยกเว้นเวร</h2>
    <p class="text-xs text-slate-400 mb-4">ครูที่อยู่ในรายการนี้จะไม่ถูกสุ่มและไม่ปรากฏในตัวเลือกลงเวร</p>
    <form method="post" class="space-y-3">
      <input type="hidden" name="action"  value="add_exclusion">
      <input type="hidden" name="year_id" value="<?= $year_id ?>">
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="text-xs font-medium text-slate-600">เลือกครู <span class="text-slate-400">(เลือกได้หลายคน)</span></label>
          <label class="inline-flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
            <input type="checkbox" id="excl-select-all" class="rounded accent-red-600">
            เลือกทั้งหมด
          </label>
        </div>
        <div id="excl-teacher-list"
          class="max-h-64 overflow-y-auto border border-slate-200 rounded-xl divide-y divide-slate-100">
          <?php foreach ($allTeachers as $t):
            $tid         = (int)$t['id'];
            $alreadyExcl = isset($excludedTeacherIds[$tid]);
            $lbl = ($t['teacher_code'] ? $t['teacher_code'] . ' – ' : '') . $t['first_name'] . ' ' . $t['last_name'];
          ?>
          <label class="flex items-center gap-3 px-3 py-2 cursor-pointer excl-teacher-item transition
            <?= $alreadyExcl ? 'opacity-40 bg-slate-50' : 'hover:bg-red-50' ?>">
            <input type="checkbox" name="teacher_ids[]" value="<?= $tid ?>"
              class="rounded accent-red-600 flex-shrink-0 excl-teacher-chk"
              <?= $alreadyExcl ? 'disabled' : '' ?>>
            <span class="text-sm flex-1 min-w-0">
              <?= phd_h($lbl) ?>
              <?php if ($alreadyExcl): ?>
                <span class="text-xs text-slate-400 ml-1">[ยกเว้นอยู่แล้ว]</span>
              <?php endif; ?>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
        <p id="excl-selected-count" class="text-xs text-slate-500 mt-1.5">เลือก 0 คน</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">เหตุผล (ถ้ามี)</label>
        <input type="text" name="reason" placeholder="เช่น ผู้บริหาร, ลาออก, สุขภาพ"
          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
      </div>
      <div class="space-y-1">
        <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
          <input type="checkbox" name="permanent" value="1" class="rounded accent-red-600">
          <span>ยกเว้นถาวร <span class="text-slate-400">(ทุกปีการศึกษา)</span></span>
        </label>
        <p class="text-xs text-slate-400 pl-5">หากไม่ติ๊ก จะยกเว้นเฉพาะปี <?= phd_h($yearLabel) ?> เท่านั้น</p>
      </div>
      <button type="submit" id="excl-submit-btn" disabled
        class="w-full bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-semibold px-4 py-2 rounded-xl transition">
        🚫 เพิ่มยกเว้น (<span id="excl-submit-count">0</span> คน)
      </button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Exclusion list -->
  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-4">📋 รายชื่อที่ยกเว้น (<?= count($excludedTeacherIds) ?> คน) – <?= phd_h($yearLabel) ?></h2>
    <?php if (empty($exclRecords)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีรายการยกเว้น</p>
    <?php else: ?>
      <div class="space-y-2 max-h-[28rem] overflow-y-auto pr-1">
        <?php foreach ($exclRecords as $excl): ?>
        <div class="flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-slate-300 transition">
          <div class="min-w-0 flex-1">
            <div class="font-medium text-sm">
              <?= phd_h(($excl['teacher_code'] ? $excl['teacher_code'] . ' – ' : '') . $excl['first_name'] . ' ' . $excl['last_name']) ?>
            </div>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
              <span class="text-xs px-2 py-0.5 rounded-full font-medium
                <?= $excl['permanent'] ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700' ?>">
                <?= $excl['permanent'] ? '🔒 ถาวร' : '📅 ปี ' . phd_h($yearLabel) ?>
              </span>
              <?php if ($excl['reason']): ?>
                <span class="text-xs text-slate-400"><?= phd_h($excl['reason']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($isAdmin): ?>
          <form method="post" data-confirm="ลบรายการยกเว้นนี้?" class="ml-2 flex-shrink-0">
            <input type="hidden" name="action"  value="remove_exclusion">
            <input type="hidden" name="year_id" value="<?= $year_id ?>">
            <input type="hidden" name="excl_id" value="<?= (int)$excl['id'] ?>">
            <button type="submit" class="text-xs px-2 py-1 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition">ลบ</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
<?php endif; ?>

</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>

<?php if ($tab === 'assign' && $selectedDate && $isAdmin): ?>
<script>
/* ── Teacher data from PHP ── */
const phdTeachers = <?= json_encode(array_values(array_map(fn($t) => [
    'id'        => (int)$t['id'],
    'name'      => ($t['teacher_code'] ? $t['teacher_code'] . ' – ' : '') . $t['first_name'] . ' ' . $t['last_name'],
    'yearCount' => $assignedThisYear[(int)$t['id']] ?? 0,
    'today'     => isset($assignedOnDate[(int)$t['id']]) ? 1 : 0,
    'excluded'  => isset($excludedTeacherIds[(int)$t['id']]) ? 1 : 0,
    'buildings'  => $teacherBuildingMap[(int)$t['id']] ?? [],
], $allTeachers)), JSON_UNESCAPED_UNICODE) ?>;
const phdSelBld = <?= $selectedBuildingId ?>;

function phdGetCandidates(excludeYear) {
    return phdTeachers.filter(t => {
        if (t.excluded || t.today) return false;
        if (excludeYear && t.yearCount > 0) return false;
        if (phdSelBld > 0 && !t.buildings.includes(phdSelBld)) return false;
        return true;
    });
}

/* ── Mode switching ── */
function phdShowMode(mode) {
    ['random','manual'].forEach(m => {
        document.getElementById('mode-'+m).classList.toggle('hidden', m !== mode);
    });
    document.getElementById('btn-random').className = mode === 'random'
        ? 'px-4 py-2 text-sm font-semibold rounded-xl bg-indigo-600 text-white transition'
        : 'px-4 py-2 text-sm font-semibold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition';
    document.getElementById('btn-manual').className = mode === 'manual'
        ? 'px-4 py-2 text-sm font-semibold rounded-xl bg-indigo-600 text-white transition'
        : 'px-4 py-2 text-sm font-semibold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition';
}

/* ── Spin ── */
const spinDisplay  = document.getElementById('spin-display');
const spinConfirm  = document.getElementById('spin-confirm');
const spinReset    = document.getElementById('spin-reset');
const spinHiddenId = document.getElementById('spin-teacher-id');
const spinBtn      = document.getElementById('spin-btn');
const spinChosen   = document.getElementById('spin-chosen-name');
let phdSpinning = false;

function phdDoSpin() {
    if (phdSpinning) return;
    const excludeYear = document.getElementById('chk-exclude-year').checked;
    const pool = phdGetCandidates(excludeYear);
    if (!pool.length) {
        spinDisplay.textContent = '⚠️ ไม่มีครูในรายการ';
        spinDisplay.style.color = '#dc2626';
        return;
    }
    phdSpinning = true;
    spinBtn.disabled = true;
    spinConfirm.classList.add('hidden');
    spinReset.classList.add('hidden');
    spinChosen.classList.add('hidden');
    spinDisplay.style.color = '';
    spinDisplay.classList.remove('spin-done');

    // Build easing: fast start → slow stop
    const totalSteps = 55;
    const delays = Array.from({length: totalSteps}, (_, i) => {
        const p = i / (totalSteps - 1);
        const eased = 1 - Math.pow(1 - p, 3); // ease-out cubic
        return Math.round(35 + eased * 350);
    });

    let step = 0;
    let lastPick = null;

    (function tick() {
        lastPick = pool[Math.floor(Math.random() * pool.length)];
        spinDisplay.textContent = lastPick.name;
        step++;
        if (step < totalSteps) {
            setTimeout(tick, delays[step]);
        } else {
            // Done
            phdSpinning = false;
            spinBtn.disabled = false;
            spinHiddenId.value = lastPick.id;
            spinDisplay.classList.add('spin-done');
            spinChosen.textContent = '✅ ผลการสุ่ม: ' + lastPick.name;
            spinChosen.classList.remove('hidden');
            spinConfirm.classList.remove('hidden');
            spinReset.classList.remove('hidden');
        }
    })();
}

function phdResetSpin() {
    spinDisplay.textContent = 'กด "สุ่ม" เพื่อเริ่ม';
    spinDisplay.classList.remove('spin-done');
    spinDisplay.style.color = '';
    spinConfirm.classList.add('hidden');
    spinReset.classList.add('hidden');
    spinChosen.classList.add('hidden');
    spinHiddenId.value = '';
}

/* ── Manual multi-select ── */
const manualExcl        = document.getElementById('chk-manual-exclude');
const manualList        = document.getElementById('manual-teacher-list');
const manualCountEl     = document.getElementById('manual-selected-count');
const manualSubmitCount = document.getElementById('manual-submit-count');
const manualSubmitBtn   = document.getElementById('manual-submit-btn');
const chkSelectAll      = document.getElementById('chk-select-all');

function phdUpdateCount() {
    const n = manualList.querySelectorAll('.manual-teacher-chk:checked').length;
    manualCountEl.textContent  = 'เลือก ' + n + ' คน';
    manualSubmitCount.textContent = n;
    manualSubmitBtn.disabled = n === 0;
}

function phdFilterManual() {
    const excl = manualExcl.checked;
    manualList.querySelectorAll('.manual-teacher-item').forEach(item => {
        const today    = item.dataset.today === '1';
        const year     = parseInt(item.dataset.yearCnt, 10) > 0;
        const excluded = item.dataset.excluded === '1';
        const blds     = (item.dataset.buildings || '').split(',').filter(Boolean).map(Number);
        const hide = excluded || today || (excl && year) || (phdSelBld > 0 && !blds.includes(phdSelBld));
        item.style.display = hide ? 'none' : '';
        if (hide) item.querySelector('.manual-teacher-chk').checked = false;
    });
    chkSelectAll.checked = false;
    phdUpdateCount();
}

manualExcl.addEventListener('change', phdFilterManual);
manualList.addEventListener('change', phdUpdateCount);
chkSelectAll.addEventListener('change', e => {
    const checked = e.target.checked;
    manualList.querySelectorAll('.manual-teacher-item').forEach(item => {
        if (item.style.display === 'none') return;
        item.querySelector('.manual-teacher-chk').checked = checked;
    });
    phdUpdateCount();
});

phdFilterManual();
</script>
<?php endif; ?>
<script>
/* ── Exclusion multi-select ── */
(function () {
    const list        = document.getElementById('excl-teacher-list');
    const countEl     = document.getElementById('excl-selected-count');
    const submitBtn   = document.getElementById('excl-submit-btn');
    const submitCount = document.getElementById('excl-submit-count');
    const selectAll   = document.getElementById('excl-select-all');
    if (!list) return; // not admin or not on exclude tab
    function updateCount() {
        const n = list.querySelectorAll('.excl-teacher-chk:checked').length;
        countEl.textContent       = 'เลือก ' + n + ' คน';
        submitCount.textContent   = n;
        submitBtn.disabled        = n === 0;
    }
    list.addEventListener('change', function() {
        selectAll.checked = false;
        updateCount();
    });
    selectAll.addEventListener('change', function(e) {
        list.querySelectorAll('.excl-teacher-chk:not(:disabled)').forEach(chk => {
            chk.checked = e.target.checked;
        });
        updateCount();
    });
    updateCount();
})();
/* ── SweetAlert2 global confirm handlers ── */
document.addEventListener('submit', async e => {
    const form = e.target;
    if (!form.dataset.confirm || form.dataset.confirmed === '1') return;
    e.preventDefault();
    const r = await Swal.fire({
        title: 'ยืนยันการดำเนินการ?',
        text: form.dataset.confirm,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
    });
    if (r.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
});
document.addEventListener('click', async e => {
    const btn = e.target.closest('button[data-confirm]');
    if (!btn || btn.dataset.confirmed === '1') return;
    e.preventDefault();
    e.stopPropagation();
    const r = await Swal.fire({
        title: 'ยืนยันการดำเนินการ?',
        text: btn.dataset.confirm,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
    });
    if (r.isConfirmed) { btn.dataset.confirmed = '1'; btn.click(); }
});
</script>
</body>
</html>
