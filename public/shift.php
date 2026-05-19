<?php
/**
 * shift.php — เวรวันหยุดปกติ (ปรับปรุง UX/UI + รองรับอาคาร)
 * - ไม่มีจุดเวร (posts ซ่อนจาก UI)
 * - วันเวรใช้ร่วมกันทุกอาคาร
 * - ทีม/บุคคล เลือกตามอาคาร หรือไม่สนอาคาร
 */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_holiday_duty_init($pdo);

// ── Schema upgrades (idempotent) ──
foreach ([
    "ALTER TABLE holiday_duty_assignments DROP FOREIGN KEY fk_hda_post",
    "ALTER TABLE holiday_duty_assignments MODIFY COLUMN holiday_duty_post_id INT UNSIGNED NULL",
    "ALTER TABLE holiday_duty_assignments DROP KEY uniq_date_post_teacher",
    "ALTER TABLE holiday_duty_teams ADD COLUMN building_id INT UNSIGNED NULL AFTER team_name",
    "ALTER TABLE holiday_duty_assignments ADD COLUMN building_id INT UNSIGNED NULL",
    "ALTER TABLE holiday_duty_assignments ADD UNIQUE KEY uniq_date_teacher (holiday_duty_date_id, teacher_id)",
] as $_sql) { try { $pdo->exec($_sql); } catch (Throwable $_e) {} }

// ── Helpers ──
function shd_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function shd_date_long(?string $ymd): string {
    if (!$ymd) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return (string)$ymd;
    static $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
        'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    static $days = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
    return $days[(int)$dt->format('w')] . ' ' . (int)$dt->format('j') . ' ' .
        $months[(int)$dt->format('n')] . ' ' . ((int)$dt->format('Y') + 543);
}
function shd_date_short(?string $ymd): string {
    if (!$ymd) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return (string)$ymd;
    static $m = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
        'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return (int)$dt->format('j') . ' ' . $m[(int)$dt->format('n')] . ' ' . ((int)$dt->format('Y') + 543);
}
function shd_parse_be(string $be): string {
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($be), $m)) return '';
    $yAd = (int)$m[3] - 543;
    if ($yAd < 1900 || $yAd > 2500 || !checkdate((int)$m[2], (int)$m[1], $yAd)) return '';
    return sprintf('%04d-%02d-%02d', $yAd, (int)$m[2], (int)$m[1]);
}
function shd_date_be(?string $ymd): string {
    if (!$ymd) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return (string)$ymd;
    return sprintf('%02d/%02d/%d', (int)$dt->format('d'), (int)$dt->format('m'), (int)$dt->format('Y') + 543);
}
function shd_redirect(string $url): void { header('Location: ' . $url); exit; }

// ── Core data ──
$user    = currentUser();
$isAdmin = is_array($user) && in_array($user['role'] ?? '', ['admin', 'superuser'], true);

$years        = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll(PDO::FETCH_ASSOC);
$activeYearId = tt_active_year_id($pdo);
if ($activeYearId <= 0 && !empty($years)) $activeYearId = (int)$years[0]['id'];
$year_id = (int)($_GET['year_id'] ?? $activeYearId);
if ($year_id <= 0 && !empty($years)) $year_id = (int)$years[0]['id'];

$yearLabel = '';
foreach ($years as $y) { if ((int)$y['id'] === $year_id) { $yearLabel = $y['year_label']; break; } }

$validTabs = ['dates', 'teams', 'assign', 'substitute', 'report'];
$tab = (string)($_GET['tab'] ?? 'dates');
if (!in_array($tab, $validTabs, true)) $tab = 'dates';

// ── Buildings ──
tt_buildings_init($pdo);
$buildings = tt_buildings_list($pdo);
$teacherBldMap = []; // teacher_id => [bld_id, ...]
if (!empty($buildings)) {
    $tbRows = $pdo->query('SELECT teacher_id, building_id FROM teacher_buildings ORDER BY teacher_id, building_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tbRows as $r) {
        $teacherBldMap[(int)$r['teacher_id']][] = (int)$r['building_id'];
    }
}
$selectedBldId = (int)($_GET['building_id'] ?? 0);
if ($selectedBldId <= 0 && !empty($buildings)) $selectedBldId = (int)$buildings[0]['id'];
$selectedBld = null;
foreach ($buildings as $b) { if ((int)$b['id'] === $selectedBldId) { $selectedBld = $b; break; } }

$err = '';

// ── Teachers ──
$teachers = $pdo->query('SELECT id, teacher_code, first_name, last_name FROM teachers ORDER BY teacher_code, first_name, last_name')->fetchAll(PDO::FETCH_ASSOC);

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $err = 'CSRF ไม่ถูกต้อง';
    } else {
        $action     = (string)($_POST['action'] ?? '');
        $postYearId = (int)($_POST['year_id'] ?? $year_id);
        $postTab    = (string)($_POST['tab'] ?? $tab);
        if (!in_array($postTab, $validTabs, true)) $postTab = $tab;
        $postBldId  = (int)($_POST['building_id'] ?? 0);
        $userId     = (int)($user['id'] ?? 0) ?: null;
        $bldParam   = $postBldId > 0 ? '&building_id=' . $postBldId : '';

        $adminActions = [
            'date_create', 'date_delete',
            'team_create', 'team_update', 'team_toggle', 'team_delete', 'team_members_set',
            'assign_team', 'assign_teacher', 'unassign',
            'substitute_set', 'substitute_clear',
        ];
        if (in_array($action, $adminActions, true) && !$isAdmin) {
            $err = 'ต้องเป็นผู้ดูแลระบบ';
        } else {
            try {
                // ── date_create ──
                if ($action === 'date_create') {
                    $d  = trim((string)($_POST['duty_date'] ?? ''));
                    $be = trim((string)($_POST['duty_date_be'] ?? ''));
                    if ($d === '' && $be !== '') $d = shd_parse_be($be);
                    $note = trim((string)($_POST['note'] ?? ''));
                    if ($d === '') throw new Exception('กรุณากรอกวันที่ (รูปแบบ 31/12/2569)');
                    $pdo->prepare('INSERT INTO holiday_duty_dates(academic_year_id,duty_date,note) VALUES(?,?,?)')
                        ->execute([$postYearId, $d, $note !== '' ? $note : null]);
                    flash_set('success', 'เพิ่มวันเวรแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=dates');
                }

                // ── date_delete ──
                if ($action === 'date_delete') {
                    $id = (int)($_POST['id'] ?? 0);
                    $pdo->prepare('DELETE FROM holiday_duty_dates WHERE id=? AND academic_year_id=?')
                        ->execute([$id, $postYearId]);
                    flash_set('success', 'ลบวันเวรแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=dates');
                }

                // ── team_create ──
                if ($action === 'team_create') {
                    $name = trim((string)($_POST['team_name'] ?? ''));
                    if ($name === '') throw new Exception('กรุณากรอกชื่อทีม');
                    $bld = $postBldId > 0 ? $postBldId : null;
                    $pdo->prepare('INSERT INTO holiday_duty_teams(academic_year_id,team_name,building_id,is_active,sort_order) VALUES(?,?,?,1,0)')
                        ->execute([$postYearId, $name, $bld]);
                    flash_set('success', 'เพิ่มทีมแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=teams');
                }

                // ── team_update ──
                if ($action === 'team_update') {
                    $id   = (int)($_POST['id'] ?? 0);
                    $name = trim((string)($_POST['team_name'] ?? ''));
                    if ($id <= 0 || $name === '') throw new Exception('ข้อมูลไม่ครบ');
                    $bld = $postBldId > 0 ? $postBldId : null;
                    $pdo->prepare('UPDATE holiday_duty_teams SET team_name=?,building_id=? WHERE id=? AND academic_year_id=?')
                        ->execute([$name, $bld, $id, $postYearId]);
                    flash_set('success', 'แก้ไขทีมแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=teams');
                }

                // ── team_toggle ──
                if ($action === 'team_toggle') {
                    $id      = (int)($_POST['id'] ?? 0);
                    $enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
                    $pdo->prepare('UPDATE holiday_duty_teams SET is_active=? WHERE id=? AND academic_year_id=?')
                        ->execute([$enabled, $id, $postYearId]);
                    flash_set('success', 'บันทึกสถานะแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=teams');
                }

                // ── team_delete ──
                if ($action === 'team_delete') {
                    $id = (int)($_POST['id'] ?? 0);
                    $cnt = $pdo->prepare('SELECT COUNT(*) FROM holiday_duty_assignments WHERE team_id=?');
                    $cnt->execute([$id]);
                    if ((int)$cnt->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีรายการลงเวรที่อ้างอิงทีมนี้');
                    $pdo->prepare('DELETE FROM holiday_duty_teams WHERE id=? AND academic_year_id=?')
                        ->execute([$id, $postYearId]);
                    flash_set('success', 'ลบทีมแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=teams');
                }

                // ── team_members_set ──
                if ($action === 'team_members_set') {
                    $teamId = (int)($_POST['team_id'] ?? 0);
                    if ($teamId <= 0) throw new Exception('ไม่พบทีม');
                    $chk = $pdo->prepare('SELECT 1 FROM holiday_duty_teams WHERE id=? AND academic_year_id=? LIMIT 1');
                    $chk->execute([$teamId, $postYearId]);
                    if (!$chk->fetchColumn()) throw new Exception('ไม่พบทีมในปีการศึกษานี้');
                    $ids   = is_array($_POST['teacher_ids'] ?? null) ? (array)$_POST['teacher_ids'] : [];
                    $clean = array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
                    $pdo->prepare('DELETE FROM holiday_duty_team_members WHERE team_id=?')->execute([$teamId]);
                    if ($clean) {
                        $ins = $pdo->prepare('INSERT INTO holiday_duty_team_members(team_id,teacher_id) VALUES(?,?)');
                        foreach ($clean as $tid) $ins->execute([$teamId, $tid]);
                    }
                    flash_set('success', 'บันทึกสมาชิกทีมแล้ว (' . count($clean) . ' คน)');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=teams&team_id=' . $teamId);
                }

                // ── assign_team ──
                if ($action === 'assign_team') {
                    $dateId = (int)($_POST['date_id'] ?? 0);
                    $teamId = (int)($_POST['team_id'] ?? 0);
                    $bldId  = $postBldId > 0 ? $postBldId : null;
                    if ($dateId <= 0 || $teamId <= 0) throw new Exception('เลือกข้อมูลให้ครบ');
                    $chk = $pdo->prepare('SELECT 1 FROM holiday_duty_dates WHERE id=? AND academic_year_id=? LIMIT 1');
                    $chk->execute([$dateId, $postYearId]);
                    if (!$chk->fetchColumn()) throw new Exception('ไม่พบวันเวร');
                    $mem = $pdo->prepare('SELECT teacher_id FROM holiday_duty_team_members WHERE team_id=? ORDER BY teacher_id');
                    $mem->execute([$teamId]);
                    $members = array_map('intval', $mem->fetchAll(PDO::FETCH_COLUMN));
                    if (!$members) throw new Exception('ทีมนี้ยังไม่มีสมาชิก');
                    $ins   = $pdo->prepare('INSERT IGNORE INTO holiday_duty_assignments(holiday_duty_date_id,teacher_id,team_id,building_id,created_by_user_id) VALUES(?,?,?,?,?)');
                    $added = 0;
                    foreach ($members as $tid) {
                        $ins->execute([$dateId, $tid, $teamId, $bldId, $userId]);
                        $added += (int)$ins->rowCount();
                    }
                    flash_set('success', 'ลงทั้งทีมแล้ว (' . $added . ' คน)');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=assign&date_id=' . $dateId . $bldParam);
                }

                // ── assign_teacher ──
                if ($action === 'assign_teacher') {
                    $dateId = (int)($_POST['date_id'] ?? 0);
                    $bldId  = $postBldId > 0 ? $postBldId : null;
                    $ids    = is_array($_POST['teacher_ids'] ?? null)
                        ? array_map('intval', (array)$_POST['teacher_ids'])
                        : [(int)($_POST['teacher_id'] ?? 0)];
                    $ids = array_unique(array_filter($ids, fn($x) => $x > 0));
                    if ($dateId <= 0 || !$ids) throw new Exception('เลือกข้อมูลให้ครบ');
                    $chk = $pdo->prepare('SELECT 1 FROM holiday_duty_dates WHERE id=? AND academic_year_id=? LIMIT 1');
                    $chk->execute([$dateId, $postYearId]);
                    if (!$chk->fetchColumn()) throw new Exception('ไม่พบวันเวร');
                    $ins   = $pdo->prepare('INSERT IGNORE INTO holiday_duty_assignments(holiday_duty_date_id,teacher_id,team_id,building_id,created_by_user_id) VALUES(?,?,NULL,?,?)');
                    $added = 0;
                    foreach ($ids as $tid) {
                        $ins->execute([$dateId, $tid, $bldId, $userId]);
                        $added += (int)$ins->rowCount();
                    }
                    if ($added <= 0) throw new Exception('ครูที่เลือกทั้งหมดถูกลงเวรในวันนี้แล้ว');
                    flash_set('success', 'ลงเวรแล้ว (' . $added . ' คน)');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=assign&date_id=' . $dateId . $bldParam);
                }

                // ── unassign ──
                if ($action === 'unassign') {
                    $assignId = (int)($_POST['assignment_id'] ?? 0);
                    $dateId   = (int)($_POST['date_id'] ?? 0);
                    if ($assignId <= 0) throw new Exception('ไม่พบรายการ');
                    $pdo->prepare('DELETE a FROM holiday_duty_assignments a JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id WHERE a.id=? AND d.academic_year_id=?')
                        ->execute([$assignId, $postYearId]);
                    flash_set('success', 'ลบการลงเวรแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=assign&date_id=' . $dateId . $bldParam);
                }

                // ── substitute_set ──
                if ($action === 'substitute_set') {
                    $assignId = (int)($_POST['assignment_id'] ?? 0);
                    $toTid    = (int)($_POST['to_teacher_id'] ?? 0);
                    $dateId   = (int)($_POST['date_id'] ?? 0);
                    $reason   = trim((string)($_POST['reason'] ?? ''));
                    if ($assignId <= 0 || $toTid <= 0) throw new Exception('เลือกข้อมูลให้ครบ');
                    $as = $pdo->prepare('SELECT a.id,a.teacher_id FROM holiday_duty_assignments a JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id WHERE a.id=? AND d.academic_year_id=? LIMIT 1');
                    $as->execute([$assignId, $postYearId]);
                    $a = $as->fetch();
                    if (!$a) throw new Exception('ไม่พบรายการลงเวร');
                    if ((int)$a['teacher_id'] === $toTid) throw new Exception('ครูผู้แทนต้องไม่ใช่คนเดิม');
                    $pdo->prepare('INSERT INTO holiday_duty_substitutions(assignment_id,from_teacher_id,to_teacher_id,reason,created_by_user_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE to_teacher_id=VALUES(to_teacher_id),reason=VALUES(reason),updated_at=CURRENT_TIMESTAMP')
                        ->execute([$assignId, (int)$a['teacher_id'], $toTid, $reason !== '' ? $reason : null, $userId]);
                    flash_set('success', 'บันทึกการแทนเวรแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=substitute&date_id=' . $dateId);
                }

                // ── substitute_clear ──
                if ($action === 'substitute_clear') {
                    $assignId = (int)($_POST['assignment_id'] ?? 0);
                    $dateId   = (int)($_POST['date_id'] ?? 0);
                    if ($assignId <= 0) throw new Exception('ไม่พบรายการ');
                    $pdo->prepare('DELETE s FROM holiday_duty_substitutions s JOIN holiday_duty_assignments a ON a.id=s.assignment_id JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id WHERE s.assignment_id=? AND d.academic_year_id=?')
                        ->execute([$assignId, $postYearId]);
                    flash_set('success', 'ล้างการแทนเวรแล้ว');
                    shd_redirect(url('shift.php') . '?year_id=' . $postYearId . '&tab=substitute&date_id=' . $dateId);
                }

                throw new Exception('ไม่รองรับ action: ' . shd_h($action));
            } catch (Throwable $e) {
                $err = 'ผิดพลาด: ' . $e->getMessage();
            }
        }
    }
}

// ── Load data ──
$flash = flash_get();

// Dates
$datesStmt = $pdo->prepare('SELECT d.id, d.duty_date, d.note,
    (SELECT COUNT(*) FROM holiday_duty_assignments a WHERE a.holiday_duty_date_id=d.id) AS as_cnt
    FROM holiday_duty_dates d WHERE d.academic_year_id=? ORDER BY d.duty_date');
$datesStmt->execute([$year_id]);
$dates = $datesStmt->fetchAll(PDO::FETCH_ASSOC);

// Teams
$teamsStmt = $pdo->prepare('SELECT t.id, t.team_name, t.is_active, t.building_id,
    (SELECT COUNT(*) FROM holiday_duty_team_members m WHERE m.team_id=t.id) AS member_cnt,
    b.building_name
    FROM holiday_duty_teams t
    LEFT JOIN duty_buildings b ON b.id=t.building_id
    WHERE t.academic_year_id=?
    ORDER BY t.is_active DESC, t.sort_order, t.team_name');
$teamsStmt->execute([$year_id]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Team members map
$teamMembers = []; // team_id => [teacher_id => true]
$tmStmt = $pdo->prepare('SELECT m.team_id, m.teacher_id FROM holiday_duty_team_members m JOIN holiday_duty_teams t ON t.id=m.team_id WHERE t.academic_year_id=?');
$tmStmt->execute([$year_id]);
while ($r = $tmStmt->fetch(PDO::FETCH_ASSOC)) {
    $teamMembers[(int)$r['team_id']][(int)$r['teacher_id']] = true;
}

// Selected date
$selectedDateId = (int)($_GET['date_id'] ?? 0);
$dateIdSet = [];
foreach ($dates as $d0) $dateIdSet[(int)$d0['id']] = true;
if ($selectedDateId <= 0 || !isset($dateIdSet[$selectedDateId])) {
    $selectedDateId = !empty($dates) ? (int)$dates[0]['id'] : 0;
}
$selectedDate = null;
foreach ($dates as $d0) { if ((int)$d0['id'] === $selectedDateId) { $selectedDate = $d0; break; } }

// Assignments for selected date
$assignments    = [];
$assignForBld   = [];
$assignedOnDate = [];
if ($selectedDateId > 0) {
    $as = $pdo->prepare('SELECT a.id AS assignment_id, a.teacher_id, a.building_id, a.team_id,
        t.teacher_code, t.first_name, t.last_name,
        s.to_teacher_id,
        t2.first_name AS sub_first, t2.last_name AS sub_last, s.reason,
        b.building_name
        FROM holiday_duty_assignments a
        JOIN teachers t ON t.id=a.teacher_id
        LEFT JOIN holiday_duty_substitutions s ON s.assignment_id=a.id
        LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
        LEFT JOIN duty_buildings b ON b.id=a.building_id
        WHERE a.holiday_duty_date_id=?
        ORDER BY a.building_id, t.teacher_code, t.first_name');
    $as->execute([$selectedDateId]);
    $assignments = $as->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as $row) {
        $assignedOnDate[(int)$row['teacher_id']] = true;
        if ((int)($row['building_id'] ?? 0) === $selectedBldId) {
            $assignForBld[] = $row;
        }
    }
}

// Year-count for teachers
$yearCntStmt = $pdo->prepare('SELECT a.teacher_id, COUNT(*) AS cnt FROM holiday_duty_assignments a JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id WHERE d.academic_year_id=? GROUP BY a.teacher_id');
$yearCntStmt->execute([$year_id]);
$assignedThisYear = [];
while ($r = $yearCntStmt->fetch(PDO::FETCH_ASSOC)) $assignedThisYear[(int)$r['teacher_id']] = (int)$r['cnt'];

// Teams for assign tab
$teamsForBld = [];
$teamsAll    = [];
foreach ($teams as $t) {
    if (!(int)$t['is_active']) continue;
    $teamsAll[] = $t;
    if ((int)($t['building_id'] ?? 0) === $selectedBldId) $teamsForBld[] = $t;
}

$pageTitle = 'เวรวันหยุดปกติ';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>

<div class="max-w-5xl mx-auto px-4 mt-8 pb-20">

  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h1 class="text-xl font-bold text-slate-800">🗓️ เวรวันหยุดปกติ</h1>
      <p class="text-sm text-slate-500 mt-0.5">จัดทีมและลงเวรรายวัน แยกตามอาคาร</p>
    </div>
    <form method="get">
      <input type="hidden" name="tab" value="<?= shd_h($tab) ?>">
      <select name="year_id" onchange="this.form.submit()"
        class="border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white shadow-sm outline-none focus:ring-2 focus:ring-indigo-300">
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id'] === $year_id ? 'selected' : '' ?>>
            <?= shd_h($y['year_label']) ?><?= $y['is_active'] ? ' ★' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- Flash / Error -->
  <?php if ($flash): ?>
  <div class="mb-4 p-3 rounded-xl text-sm <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
    <?= shd_h($flash['msg']) ?>
  </div>
  <?php endif; ?>
  <?php if ($err): ?>
  <div class="mb-4 p-3 rounded-xl text-sm bg-rose-50 text-rose-700 border border-rose-200">⚠️ <?= shd_h($err) ?></div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="flex flex-wrap gap-1 p-1 rounded-2xl bg-slate-100 border border-slate-200 mb-6 text-sm">
    <?php
    $tabDefs = [
        'dates'      => ['📅', 'วันเวร'],
        'teams'      => ['👥', 'ทีมเวร'],
        'assign'     => ['✅', 'ลงเวร'],
        'substitute' => ['🔄', 'แทนเวร'],
        'report'     => ['📊', 'รายงาน'],
    ];
    foreach ($tabDefs as $tk => $td):
        $isAct = $tab === $tk;
        $extra = in_array($tk, ['assign','substitute'], true) && $selectedDateId > 0 ? '&date_id=' . $selectedDateId : '';
        $bExtra = $tk === 'assign' && $selectedBldId > 0 ? '&building_id=' . $selectedBldId : '';
    ?>
    <a href="<?= url('shift.php?year_id=' . $year_id . '&tab=' . $tk . $extra . $bExtra) ?>"
       class="flex items-center gap-1.5 px-3 py-2 rounded-xl font-semibold transition whitespace-nowrap
              <?= $isAct ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-white hover:text-slate-900 hover:shadow-sm' ?>">
      <?= $td[0] ?> <?= $td[1] ?>
    </a>
    <?php endforeach; ?>
  </div>

<?php /* ══ TAB: DATES ══ */ if ($tab === 'dates'): ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Add form -->
    <?php if ($isAdmin): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <h2 class="font-semibold mb-4">➕ เพิ่มวันเวร</h2>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf"      value="<?= csrf_token() ?>">
        <input type="hidden" name="action"    value="date_create">
        <input type="hidden" name="year_id"   value="<?= $year_id ?>">
        <input type="hidden" name="tab"       value="dates">
        <input type="hidden" name="duty_date" id="shdDutyDateAd" value="">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">วันที่ (วัน/เดือน/พ.ศ.)</label>
          <div class="flex gap-2">
            <input type="text" name="duty_date_be" id="shdDutyDateBe" required
              placeholder="เช่น 18/03/2569"
              class="flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
            <input type="date" id="shdDutyDatePicker" title="เลือกจากปฏิทิน"
              class="border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
          </div>
          <p class="text-xs text-slate-400 mt-1">กรอกเป็น พ.ศ. หรือเลือกจากปฏิทิน (จะแปลงให้)</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">หมายเหตุ</label>
          <input type="text" name="note" placeholder="ไม่บังคับ"
            class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
        </div>
        <button class="w-full py-2 bg-slate-900 hover:bg-slate-700 text-white font-semibold rounded-xl text-sm transition">
          เพิ่มวันเวร
        </button>
      </form>
    </div>
    <?php endif; ?>

    <!-- Date list -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <h2 class="font-semibold mb-4">📋 รายการวันเวร (<?= count($dates) ?> วัน)</h2>
      <?php if (empty($dates)): ?>
        <p class="text-slate-400 text-sm text-center py-6">ยังไม่มีวันเวร</p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($dates as $d): $cnt = (int)$d['as_cnt']; ?>
        <div class="flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-slate-200 transition">
          <div>
            <div class="font-medium text-sm"><?= shd_h(shd_date_long($d['duty_date'])) ?></div>
            <?php if ($d['note']): ?>
              <div class="text-xs text-slate-400"><?= shd_h($d['note']) ?></div>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-xs <?= $cnt > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400' ?> px-2 py-0.5 rounded-full font-semibold">
              <?= $cnt ?> คน
            </span>
            <?php if ($isAdmin): ?>
            <form method="post" data-confirm="ลบวันเวรนี้? รายการลงเวรจะถูกลบด้วย">
              <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
              <input type="hidden" name="action"  value="date_delete">
              <input type="hidden" name="year_id" value="<?= $year_id ?>">
              <input type="hidden" name="tab"     value="dates">
              <input type="hidden" name="id"      value="<?= (int)$d['id'] ?>">
              <button class="text-xs text-red-500 hover:text-red-700 font-medium transition">✕ ลบ</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php /* ══ TAB: TEAMS ══ */ elseif ($tab === 'teams'): ?>

  <!-- Create team form -->
  <?php if ($isAdmin): ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
    <h2 class="font-semibold mb-4">➕ สร้างทีมเวรใหม่</h2>
    <form method="post" class="flex flex-wrap gap-3 items-end">
      <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
      <input type="hidden" name="action"  value="team_create">
      <input type="hidden" name="year_id" value="<?= $year_id ?>">
      <input type="hidden" name="tab"     value="teams">
      <div class="flex-1 min-w-[160px]">
        <label class="block text-xs font-medium text-slate-600 mb-1">ชื่อทีม</label>
        <input name="team_name" required placeholder="เช่น ทีม A / ทีมประถม"
          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
      </div>
      <?php if (!empty($buildings)): ?>
      <div class="min-w-[160px]">
        <label class="block text-xs font-medium text-slate-600 mb-1">อาคาร</label>
        <select name="building_id"
          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
          <option value="0">— ไม่ระบุ —</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>"><?= shd_h($b['building_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button class="px-6 py-2 bg-slate-900 hover:bg-slate-700 text-white font-semibold rounded-xl text-sm transition">
        เพิ่มทีม
      </button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Team cards -->
  <?php if (empty($teams)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400">ยังไม่มีทีมเวร สร้างทีมแรกได้เลย</div>
  <?php else: ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <?php foreach ($teams as $t):
      $tid    = (int)$t['id'];
      $tBldId = (int)($t['building_id'] ?? 0);
      $tBldNm = (string)($t['building_name'] ?? '');
    ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <!-- Team header -->
      <div class="flex items-start justify-between gap-2 mb-3">
        <div>
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold"><?= shd_h($t['team_name']) ?></span>
            <?php if (!(int)$t['is_active']): ?>
              <span class="text-xs bg-slate-100 text-slate-400 px-2 py-0.5 rounded-full">ปิด</span>
            <?php endif; ?>
            <?php if ($tBldNm): ?>
              <span class="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full">🏫 <?= shd_h($tBldNm) ?></span>
            <?php endif; ?>
          </div>
          <div class="text-xs text-slate-400 mt-0.5"><?= (int)$t['member_cnt'] ?> คน</div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="flex gap-1.5 flex-shrink-0">
          <form method="post">
            <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="action"  value="team_toggle">
            <input type="hidden" name="year_id" value="<?= $year_id ?>">
            <input type="hidden" name="tab"     value="teams">
            <input type="hidden" name="id"      value="<?= $tid ?>">
            <input type="hidden" name="enabled" value="<?= (int)$t['is_active'] ? 0 : 1 ?>">
            <button class="px-2.5 py-1 text-xs rounded-xl font-semibold transition
              <?= (int)$t['is_active'] ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' ?>">
              <?= (int)$t['is_active'] ? 'ปิด' : 'เปิด' ?>
            </button>
          </form>
          <form method="post" data-confirm="ลบทีม?">
            <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="action"  value="team_delete">
            <input type="hidden" name="year_id" value="<?= $year_id ?>">
            <input type="hidden" name="tab"     value="teams">
            <input type="hidden" name="id"      value="<?= $tid ?>">
            <button class="px-2.5 py-1 text-xs rounded-xl font-semibold bg-red-50 text-red-600 hover:bg-red-100 transition">ลบ</button>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($isAdmin): ?>
      <!-- Edit name + building -->
      <form method="post" class="flex flex-wrap gap-2 items-end pb-4 mb-4 border-b border-slate-100">
        <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="action"  value="team_update">
        <input type="hidden" name="year_id" value="<?= $year_id ?>">
        <input type="hidden" name="tab"     value="teams">
        <input type="hidden" name="id"      value="<?= $tid ?>">
        <input name="team_name" required value="<?= shd_h($t['team_name']) ?>"
          class="flex-1 min-w-[110px] border border-slate-200 rounded-xl px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
        <?php if (!empty($buildings)): ?>
        <select name="building_id"
          class="border border-slate-200 rounded-xl px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
          <option value="0">— ไม่ระบุ —</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $tBldId === (int)$b['id'] ? 'selected' : '' ?>>
              <?= shd_h($b['building_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="px-3 py-1.5 text-xs bg-slate-900 hover:bg-slate-700 text-white font-semibold rounded-xl transition">บันทึก</button>
      </form>

      <!-- Members checklist -->
      <form method="post">
        <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="action"  value="team_members_set">
        <input type="hidden" name="year_id" value="<?= $year_id ?>">
        <input type="hidden" name="tab"     value="teams">
        <input type="hidden" name="team_id" value="<?= $tid ?>">

        <!-- Chips -->
        <div id="shdTeamSel-<?= $tid ?>" class="flex flex-wrap gap-1.5 min-h-[24px] mb-2"></div>

        <!-- Filter by building -->
        <?php if (!empty($buildings) && $tBldId > 0): ?>
        <label class="inline-flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer mb-2 select-none">
          <input type="checkbox" class="rounded accent-indigo-600" checked
            data-team="<?= $tid ?>" data-bld="<?= $tBldId ?>" onchange="shdTeamFilterChange(this)">
          แสดงเฉพาะ <?= shd_h($tBldNm) ?>
        </label>
        <?php endif; ?>

        <div class="border border-slate-200 rounded-xl overflow-hidden">
          <div class="bg-slate-50 px-3 py-2 text-xs font-medium text-slate-500 flex items-center justify-between">
            <span>รายชื่อครู</span>
            <label class="inline-flex items-center gap-1 cursor-pointer select-none">
              <input type="checkbox" class="rounded accent-indigo-600"
                onchange="shdSelectAll(this, <?= $tid ?>)"> เลือกทั้งหมด
            </label>
          </div>
          <div class="divide-y divide-slate-100 max-h-52 overflow-y-auto">
            <?php foreach ($teachers as $tc):
              $tcId  = (int)$tc['id'];
              $sel   = !empty($teamMembers[$tid][$tcId]);
              $label = trim(($tc['teacher_code'] ?? '') . ' ' . $tc['first_name'] . ' ' . $tc['last_name']);
              $tcBlds = $teacherBldMap[$tcId] ?? [];
              $showRow = empty($buildings) || $tBldId <= 0 || in_array($tBldId, $tcBlds, true);
            ?>
            <label class="flex items-center gap-2 px-3 py-2 hover:bg-indigo-50 cursor-pointer transition shdTeamMember"
              data-team="<?= $tid ?>"
              data-blds="<?= implode(',', $tcBlds) ?>"
              data-name="<?= shd_h($label) ?>"
              <?= !$showRow ? 'style="display:none"' : '' ?>>
              <input type="checkbox" name="teacher_ids[]" value="<?= $tcId ?>"
                class="rounded accent-indigo-600 flex-shrink-0 shdTeamChk-<?= $tid ?>"
                <?= $sel ? 'checked' : '' ?>>
              <span class="text-sm flex-1 min-w-0"><?= shd_h($label) ?></span>
              <?php if (!empty($tcBlds) && !empty($buildings)): ?>
                <?php foreach ($buildings as $bb): if (!in_array((int)$bb['id'], $tcBlds, true)) continue; ?>
                  <span class="text-xs bg-indigo-50 text-indigo-400 px-1.5 rounded"><?= shd_h($bb['building_name']) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mt-3 flex items-center justify-between">
          <span id="shdTeamCount-<?= $tid ?>" class="text-xs text-slate-400">—</span>
          <button class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl transition">
            💾 บันทึกสมาชิก
          </button>
        </div>
      </form>
      <?php else: ?>
        <div class="text-xs text-slate-400 text-center py-2">สิทธิ์ admin เท่านั้น</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php /* ══ TAB: ASSIGN ══ */ elseif ($tab === 'assign'): ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- Sidebar: date list -->
  <div class="bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">วันเวร</div>
    <?php if (empty($dates)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีวันเวร <a href="?tab=dates&year_id=<?= $year_id ?>" class="text-indigo-600 underline">เพิ่มก่อน</a></p>
    <?php else: ?>
    <div class="space-y-1">
      <?php foreach ($dates as $d0):
        $isSel = (int)$d0['id'] === $selectedDateId;
        $cnt   = (int)$d0['as_cnt'];
      ?>
        <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= (int)$d0['id'] ?>&building_id=<?= $selectedBldId ?>"
           class="flex justify-between items-center px-3 py-2 rounded-xl text-sm transition
                  <?= $isSel ? 'bg-indigo-600 text-white' : 'hover:bg-slate-50 text-slate-700' ?>">
          <div class="min-w-0">
            <div class="font-medium truncate"><?= shd_h(shd_date_short($d0['duty_date'])) ?></div>
            <?php if ($d0['note']): ?>
              <div class="text-xs opacity-70 truncate"><?= shd_h($d0['note']) ?></div>
            <?php endif; ?>
          </div>
          <span class="ml-1 flex-shrink-0 text-xs font-bold px-1.5 py-0.5 rounded-full
                       <?= $isSel ? 'bg-white/25 text-white' : ($cnt > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400') ?>">
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
      <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400">เลือกวันเวรทางซ้าย</div>
    <?php else: ?>

    <!-- Building selector tabs -->
    <?php if (!empty($buildings)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-3">
      <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">เลือกอาคาร</div>
      <div class="flex gap-2 flex-wrap">
        <?php foreach ($buildings as $b):
          $isBld = (int)$b['id'] === $selectedBldId; ?>
        <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= $selectedDateId ?>&building_id=<?= (int)$b['id'] ?>"
           class="px-4 py-2 rounded-xl text-sm font-semibold transition
                  <?= $isBld ? 'bg-indigo-600 text-white shadow' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
          🏫 <?= shd_h($b['building_name']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Current assignments -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <div class="flex items-baseline justify-between mb-4">
        <div>
          <h2 class="font-semibold"><?= shd_h(shd_date_long($selectedDate['duty_date'])) ?></h2>
          <?php if ($selectedDate['note']): ?>
            <p class="text-xs text-slate-400 mt-0.5"><?= shd_h($selectedDate['note']) ?></p>
          <?php endif; ?>
        </div>
        <?php if (!empty($buildings) && $selectedBld): ?>
          <span class="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full font-semibold flex-shrink-0">
            🏫 <?= shd_h($selectedBld['building_name']) ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if (empty($assignForBld)): ?>
        <p class="text-sm text-slate-400">ยังไม่มีครูลงเวร<?= !empty($buildings) ? 'ในอาคารนี้' : '' ?></p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($assignForBld as $a): ?>
        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-100">
          <div>
            <span class="font-medium text-sm">
              <?= shd_h(($a['teacher_code'] ? $a['teacher_code'] . ' – ' : '') . $a['first_name'] . ' ' . $a['last_name']) ?>
            </span>
            <?php if (!empty($a['to_teacher_id'])): ?>
              <span class="ml-2 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">
                แทน → <?= shd_h($a['sub_first'] . ' ' . $a['sub_last']) ?>
              </span>
            <?php endif; ?>
          </div>
          <?php if ($isAdmin): ?>
          <form method="post" data-confirm="ลบครูนี้ออกจากเวร?">
            <input type="hidden" name="csrf"          value="<?= csrf_token() ?>">
            <input type="hidden" name="action"        value="unassign">
            <input type="hidden" name="year_id"       value="<?= $year_id ?>">
            <input type="hidden" name="tab"           value="assign">
            <input type="hidden" name="date_id"       value="<?= $selectedDateId ?>">
            <input type="hidden" name="building_id"   value="<?= $selectedBldId ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
            <button class="text-xs text-red-500 hover:text-red-700 font-medium transition">✕ ลบ</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Assign forms -->
    <?php if ($isAdmin): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-5 space-y-5">

      <!-- Assign by team -->
      <div>
        <h3 class="text-sm font-semibold mb-3">👥 ลงทั้งทีม</h3>
        <form method="post" class="flex flex-wrap gap-3 items-end">
          <input type="hidden" name="csrf"        value="<?= csrf_token() ?>">
          <input type="hidden" name="action"      value="assign_team">
          <input type="hidden" name="year_id"     value="<?= $year_id ?>">
          <input type="hidden" name="tab"         value="assign">
          <input type="hidden" name="date_id"     value="<?= $selectedDateId ?>">
          <input type="hidden" name="building_id" value="<?= $selectedBldId ?>">
          <div class="flex-1 min-w-[200px]">
            <select name="team_id"
              class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
              <option value="">— เลือกทีม —</option>
              <?php if (!empty($buildings) && (!empty($teamsForBld) || !empty(array_filter($teamsAll, fn($t) => !(int)($t['building_id']??0))))): ?>
                <?php if (!empty($teamsForBld)): ?>
                  <optgroup label="🏫 <?= shd_h($selectedBld['building_name'] ?? '') ?>">
                    <?php foreach ($teamsForBld as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"><?= shd_h($t['team_name']) ?> (<?= (int)$t['member_cnt'] ?> คน)</option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <?php $otherTeams = array_filter($teamsAll, fn($t) => (int)($t['building_id']??0) !== $selectedBldId); ?>
                <?php if (!empty($otherTeams)): ?>
                  <optgroup label="ทีมอื่น">
                    <?php foreach ($otherTeams as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"><?= shd_h($t['team_name']) ?><?= $t['building_name'] ? ' [' . shd_h($t['building_name']) . ']' : '' ?> (<?= (int)$t['member_cnt'] ?> คน)</option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              <?php else: ?>
                <?php foreach ($teamsAll as $t): ?>
                  <option value="<?= (int)$t['id'] ?>"><?= shd_h($t['team_name']) ?> (<?= (int)$t['member_cnt'] ?> คน)</option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <button class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm rounded-xl transition">
            ✅ ลงทั้งทีม
          </button>
        </form>
      </div>

      <hr class="border-slate-100">

      <!-- Assign individual -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold">✍️ เลือกครูรายคน</h3>
          <?php if (!empty($buildings)): ?>
          <label class="inline-flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
            <input type="checkbox" id="shdIgnoreBld" class="rounded accent-indigo-600"
              onchange="shdApplyBldFilter()">
            ไม่สนอาคาร
          </label>
          <?php endif; ?>
        </div>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf"        value="<?= csrf_token() ?>">
          <input type="hidden" name="action"      value="assign_teacher">
          <input type="hidden" name="year_id"     value="<?= $year_id ?>">
          <input type="hidden" name="tab"         value="assign">
          <input type="hidden" name="date_id"     value="<?= $selectedDateId ?>">
          <input type="hidden" name="building_id" value="<?= $selectedBldId ?>">
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="text-xs font-medium text-slate-600">เลือกครู <span class="text-slate-400">(หลายคนได้)</span></label>
              <label class="inline-flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
                <input type="checkbox" id="shdSelectAll" class="rounded accent-indigo-600"
                  onchange="shdToggleAllTeachers(this)"> เลือกทั้งหมด
              </label>
            </div>
            <div id="shdTeacherList" class="max-h-64 overflow-y-auto border border-slate-200 rounded-xl divide-y divide-slate-100">
              <?php foreach ($teachers as $tc):
                $tcId    = (int)$tc['id'];
                $yearCnt = $assignedThisYear[$tcId] ?? 0;
                $isToday = isset($assignedOnDate[$tcId]);
                $tcBlds  = $teacherBldMap[$tcId] ?? [];
                $inBld   = !empty($buildings) && $selectedBldId > 0 && in_array($selectedBldId, $tcBlds, true);
                $label   = trim(($tc['teacher_code'] ?? '') . ' ' . $tc['first_name'] . ' ' . $tc['last_name']);
                $hide    = !empty($buildings) && $selectedBldId > 0 && !$inBld && !$isToday;
              ?>
              <label class="flex items-center gap-2 px-3 py-2 hover:bg-indigo-50 cursor-pointer transition shdTeacherItem"
                data-today="<?= $isToday ? 1 : 0 ?>"
                data-blds="<?= implode(',', $tcBlds) ?>"
                <?= $hide || $isToday ? 'style="display:none"' : '' ?>>
                <input type="checkbox" name="teacher_ids[]" value="<?= $tcId ?>"
                  class="rounded accent-indigo-600 flex-shrink-0 shdTeacherChk">
                <span class="text-sm flex-1 min-w-0">
                  <?= shd_h($label) ?>
                  <?php if ($yearCnt): ?>
                    <span class="text-xs text-slate-400 ml-1">[<?= $yearCnt ?> ครั้ง]</span>
                  <?php endif; ?>
                </span>
              </label>
              <?php endforeach; ?>
            </div>
            <p id="shdSelCount" class="text-xs text-slate-500 mt-1.5">เลือก 0 คน</p>
          </div>
          <button type="submit" id="shdIndivSubmit" disabled
            class="w-full py-2 bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed
                   text-white font-semibold text-sm rounded-xl transition">
            ✅ ลงเวร (<span id="shdIndivCnt">0</span> คน)
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // selectedDate ?>
  </div>

</div>

<?php /* ══ TAB: SUBSTITUTE ══ */ elseif ($tab === 'substitute'): ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- Sidebar: date list -->
  <div class="bg-white rounded-2xl border border-slate-200 p-4">
    <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">วันเวร</div>
    <?php if (empty($dates)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีวันเวร</p>
    <?php else: ?>
    <div class="space-y-1">
      <?php foreach ($dates as $d0):
        $isSel = (int)$d0['id'] === $selectedDateId;
        $cnt   = (int)$d0['as_cnt'];
      ?>
        <a href="?tab=substitute&year_id=<?= $year_id ?>&date_id=<?= (int)$d0['id'] ?>"
           class="flex justify-between items-center px-3 py-2 rounded-xl text-sm transition
                  <?= $isSel ? 'bg-indigo-600 text-white' : 'hover:bg-slate-50 text-slate-700' ?>">
          <div class="min-w-0">
            <div class="font-medium truncate"><?= shd_h(shd_date_short($d0['duty_date'])) ?></div>
            <?php if ($d0['note']): ?>
              <div class="text-xs opacity-70 truncate"><?= shd_h($d0['note']) ?></div>
            <?php endif; ?>
          </div>
          <span class="ml-1 flex-shrink-0 text-xs font-bold px-1.5 py-0.5 rounded-full
                       <?= $isSel ? 'bg-white/25 text-white' : ($cnt > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-400') ?>">
            <?= $cnt ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Main -->
  <div class="lg:col-span-2">
    <?php if (!$selectedDate): ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400">เลือกวันเวรทางซ้าย</div>
    <?php elseif (empty($assignments)): ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center text-slate-400">
        ยังไม่มีการลงเวรในวันนี้
        <div class="mt-3">
          <a href="?tab=assign&year_id=<?= $year_id ?>&date_id=<?= $selectedDateId ?>"
             class="text-sm text-indigo-600 underline">ไปลงเวรก่อน</a>
        </div>
      </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
      <h2 class="font-semibold mb-4"><?= shd_h(shd_date_long($selectedDate['duty_date'])) ?></h2>
      <div class="space-y-4">
        <?php foreach ($assignments as $a): ?>
        <div class="p-4 border border-slate-100 rounded-xl">
          <div class="flex items-start gap-2 mb-3 flex-wrap">
            <div class="flex-1">
              <span class="font-medium text-sm">
                <?= shd_h(($a['teacher_code'] ? $a['teacher_code'] . ' – ' : '') . $a['first_name'] . ' ' . $a['last_name']) ?>
              </span>
              <?php if ($a['building_name']): ?>
                <span class="ml-1.5 text-xs text-indigo-400">🏫 <?= shd_h($a['building_name']) ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($a['to_teacher_id'])): ?>
              <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">
                แทน: <?= shd_h($a['sub_first'] . ' ' . $a['sub_last']) ?>
                <?= $a['reason'] ? ' – ' . shd_h($a['reason']) : '' ?>
              </span>
            <?php endif; ?>
          </div>
          <?php if ($isAdmin): ?>
          <form method="post" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="csrf"          value="<?= csrf_token() ?>">
            <input type="hidden" name="action"        value="substitute_set">
            <input type="hidden" name="year_id"       value="<?= $year_id ?>">
            <input type="hidden" name="tab"           value="substitute">
            <input type="hidden" name="date_id"       value="<?= $selectedDateId ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
            <div class="flex-1 min-w-[170px]">
              <select name="to_teacher_id" required
                class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
                <option value="">— เลือกครูผู้แทน —</option>
                <?php foreach ($teachers as $tc):
                  if ((int)$tc['id'] === (int)$a['teacher_id']) continue;
                ?>
                  <option value="<?= (int)$tc['id'] ?>"
                    <?= !empty($a['to_teacher_id']) && (int)$tc['id'] === (int)$a['to_teacher_id'] ? 'selected' : '' ?>>
                    <?= shd_h(($tc['teacher_code'] ?? '') . ' ' . $tc['first_name'] . ' ' . $tc['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <input type="text" name="reason" placeholder="เหตุผล"
              value="<?= shd_h($a['reason'] ?? '') ?>"
              class="flex-1 min-w-[100px] border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-300">
            <button class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition">
              💾 บันทึก
            </button>
          </form>
          <?php if (!empty($a['to_teacher_id'])): ?>
          <form method="post" class="mt-2" data-confirm="ล้างการแทนเวรนี้?">
            <input type="hidden" name="csrf"          value="<?= csrf_token() ?>">
            <input type="hidden" name="action"        value="substitute_clear">
            <input type="hidden" name="year_id"       value="<?= $year_id ?>">
            <input type="hidden" name="tab"           value="substitute">
            <input type="hidden" name="date_id"       value="<?= $selectedDateId ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
            <button class="text-xs text-red-500 hover:text-red-700 font-medium">✕ ล้างการแทนเวร</button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php /* ══ TAB: REPORT ══ */ elseif ($tab === 'report'): ?>
<?php
$rStmt = $pdo->prepare('SELECT d.duty_date, d.note,
    a.id AS assignment_id, a.teacher_id, a.building_id,
    t.teacher_code, t.first_name, t.last_name,
    s.to_teacher_id,
    t2.first_name AS sub_first, t2.last_name AS sub_last,
    b.building_name
    FROM holiday_duty_dates d
    LEFT JOIN holiday_duty_assignments a ON a.holiday_duty_date_id=d.id
    LEFT JOIN teachers t ON t.id=a.teacher_id
    LEFT JOIN holiday_duty_substitutions s ON s.assignment_id=a.id
    LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
    LEFT JOIN duty_buildings b ON b.id=a.building_id
    WHERE d.academic_year_id=?
    ORDER BY d.duty_date, a.building_id, t.teacher_code, t.first_name');
$rStmt->execute([$year_id]);
$rRows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

$rptDates = [];
foreach ($rRows as $rr) {
    $dt = $rr['duty_date'];
    if (!isset($rptDates[$dt])) $rptDates[$dt] = ['note' => $rr['note'], 'groups' => []];
    if (!$rr['teacher_id']) continue;
    $grp = $rr['building_name'] ?? '—';
    $rptDates[$dt]['groups'][$grp][] = $rr;
}

$tSum = [];
foreach ($rRows as $rr) {
    if (!$rr['teacher_id']) continue;
    $tid = !empty($rr['to_teacher_id']) ? (int)$rr['to_teacher_id'] : (int)$rr['teacher_id'];
    if (!isset($tSum[$tid])) {
        $t2i = null;
        foreach ($teachers as $tc) { if ((int)$tc['id'] === $tid) { $t2i = $tc; break; } }
        $tSum[$tid] = [
            'name'  => $t2i ? (($t2i['teacher_code'] ? $t2i['teacher_code'] . ' – ' : '') . $t2i['first_name'] . ' ' . $t2i['last_name']) : 'ID#' . $tid,
            'count' => 0,
        ];
    }
    $tSum[$tid]['count']++;
}
uasort($tSum, fn($a, $b) => $b['count'] - $a['count']);

$totalAssign = array_sum(array_map(fn($d) => array_sum(array_map('count', $d['groups'])), $rptDates));
$subCount    = array_reduce($rRows, fn($c, $r) => $c + (!empty($r['to_teacher_id']) ? 1 : 0), 0);
?>

<div class="space-y-6">
  <!-- Export bar -->
  <div class="flex flex-wrap items-center justify-between gap-2">
    <div class="text-sm font-semibold text-slate-600">📊 รายงานเวรวันหยุดปกติ ปี <?= shd_h($yearLabel) ?></div>
    <a href="<?= shd_h(url('shift_export.php') . '?year_id=' . $year_id) ?>" target="_blank"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white text-xs font-semibold rounded-xl transition">
      🖨 พิมพ์ / Export
    </a>
  </div>

  <!-- Summary cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-indigo-600"><?= count($dates) ?></div>
      <div class="text-xs text-slate-500 mt-1">วันทั้งหมด</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-slate-700"><?= $totalAssign ?></div>
      <div class="text-xs text-slate-500 mt-1">รายการลงเวร</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-green-600"><?= count($tSum) ?></div>
      <div class="text-xs text-slate-500 mt-1">ครูที่ลงเวร</div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 text-center">
      <div class="text-2xl font-bold text-amber-600"><?= $subCount ?></div>
      <div class="text-xs text-slate-500 mt-1">แทนเวร</div>
    </div>
  </div>

  <!-- Detail table -->
  <div class="bg-white rounded-2xl border border-slate-200 p-5 overflow-x-auto">
    <h2 class="font-semibold mb-4">📋 รายละเอียดรายวัน</h2>
    <?php if (empty($rptDates)): ?>
      <p class="text-slate-400 text-sm">ยังไม่มีข้อมูล</p>
    <?php else: ?>
    <table class="w-full text-sm border-collapse">
      <thead>
        <tr class="border-b-2 border-slate-200">
          <th class="text-left py-2 pr-4 font-semibold text-slate-600 whitespace-nowrap w-40">วันที่</th>
          <th class="text-left py-2 font-semibold text-slate-600">ครูลงเวร</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rptDates as $dt => $dr): ?>
        <tr class="border-b border-slate-100 hover:bg-slate-50">
          <td class="py-2 pr-4 text-slate-600 align-top">
            <div class="font-medium"><?= shd_h(shd_date_short($dt)) ?></div>
            <?php if ($dr['note']): ?><div class="text-xs text-slate-400"><?= shd_h($dr['note']) ?></div><?php endif; ?>
          </td>
          <td class="py-2 align-top">
            <?php if (empty($dr['groups'])): ?>
              <span class="text-slate-300 text-xs">— ยังไม่มีครู —</span>
            <?php else: ?>
              <?php foreach ($dr['groups'] as $grpName => $items):
                $manyGrps = count($dr['groups']) > 1;
              ?>
                <?php if ($manyGrps): ?>
                  <span class="text-xs font-bold text-indigo-500 mr-1">🏫 <?= shd_h($grpName) ?></span>
                <?php endif; ?>
                <?php foreach ($items as $it):
                  $hasSub = !empty($it['to_teacher_id']);
                ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-1 mr-1
                               <?= $hasSub ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' ?>">
                    <?= shd_h($it['first_name'] . ' ' . $it['last_name']) ?>
                    <?php if ($hasSub): ?>
                      <span class="opacity-60">→ <?= shd_h($it['sub_first'] . ' ' . $it['sub_last']) ?></span>
                    <?php endif; ?>
                  </span>
                <?php endforeach; ?>
                <?php if ($manyGrps): ?><br><?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Teacher summary -->
  <?php if (!empty($tSum)): ?>
  <div class="bg-white rounded-2xl border border-slate-200 p-5">
    <h2 class="font-semibold mb-4">👩‍🏫 สรุปจำนวนเวรต่อครู</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
      <?php foreach ($tSum as $ts): ?>
      <div class="flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-slate-300 transition">
        <span class="text-sm font-medium truncate"><?= shd_h($ts['name']) ?></span>
        <span class="ml-2 flex-shrink-0 text-sm font-bold text-indigo-600"><?= $ts['count'] ?> ครั้ง</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

</div>

<script>
(function () {
  /* ── SweetAlert2 data-confirm delegation ── */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg  = form.getAttribute('data-confirm');
    if (!msg) return;
    if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return; }
    e.preventDefault();
    if (typeof Swal === 'undefined') { if (confirm(msg)) form.submit(); return; }
    Swal.fire({
      title: 'ยืนยัน?', text: msg, icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'ดำเนินการ', cancelButtonText: 'ยกเลิก',
      confirmButtonColor: '#1e293b',
    }).then(function (r) {
      if (r.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
    });
  });

  /* ── Dates tab: BE/AD sync ── */
  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function adToBe(ymd) {
    if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return '';
    return pad2(+ymd.slice(8)) + '/' + ymd.slice(5,7) + '/' + (+ymd.slice(0,4) + 543);
  }
  function beToAd(be) {
    var m = (be||'').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return '';
    return (+m[3]-543) + '-' + pad2(+m[2]) + '-' + pad2(+m[1]);
  }
  var beIn = document.getElementById('shdDutyDateBe');
  var adIn = document.getElementById('shdDutyDateAd');
  var pick = document.getElementById('shdDutyDatePicker');
  if (beIn && adIn) beIn.addEventListener('input', function() { var ad=beToAd(beIn.value); adIn.value=ad; if(pick&&ad) pick.value=ad; });
  if (pick && beIn && adIn) pick.addEventListener('change', function() { adIn.value=pick.value; beIn.value=adToBe(pick.value); });

  /* ── Teams tab: chips ── */
  function shdUpdateChips(teamId) {
    var wrap = document.getElementById('shdTeamSel-' + teamId);
    var cntEl = document.getElementById('shdTeamCount-' + teamId);
    if (!wrap) return;
    wrap.innerHTML = '';
    var cbs = document.querySelectorAll('.shdTeamChk-' + teamId + ':checked');
    cbs.forEach(function(cb) {
      var row = cb.closest('.shdTeamMember');
      var name = row ? row.getAttribute('data-name') : '';
      var chip = document.createElement('div');
      chip.className = 'inline-flex items-center gap-1 px-2 py-0.5 rounded-xl bg-indigo-50 border border-indigo-100 text-xs';
      chip.innerHTML = '<span>' + name + '</span><button type="button" class="text-red-400 hover:text-red-600 ml-1">✕</button>';
      chip.querySelector('button').addEventListener('click', function() { cb.checked=false; shdUpdateChips(teamId); });
      wrap.appendChild(chip);
    });
    if (cntEl) cntEl.textContent = 'เลือก ' + cbs.length + ' คน';
  }
  document.querySelectorAll('.shdTeamMember').forEach(function(row) {
    var teamId = row.getAttribute('data-team');
    var cb = row.querySelector('input[type=checkbox]');
    if (cb) cb.addEventListener('change', function() { shdUpdateChips(teamId); });
  });
  var seen = {};
  document.querySelectorAll('.shdTeamMember').forEach(function(r) { seen[r.getAttribute('data-team')] = true; });
  Object.keys(seen).forEach(function(tid) { shdUpdateChips(tid); });

  /* ── Teams tab: building filter ── */
  window.shdTeamFilterChange = function(chk) {
    var teamId = chk.getAttribute('data-team');
    var bldId  = parseInt(chk.getAttribute('data-bld')||'0',10);
    document.querySelectorAll('.shdTeamMember[data-team="'+teamId+'"]').forEach(function(row) {
      if (!chk.checked) { row.style.display=''; return; }
      var blds = (row.getAttribute('data-blds')||'').split(',').filter(Boolean).map(Number);
      row.style.display = bldId>0 && !blds.includes(bldId) ? 'none' : '';
    });
  };

  /* ── Teams tab: select all ── */
  window.shdSelectAll = function(chk, teamId) {
    document.querySelectorAll('.shdTeamMember[data-team="'+teamId+'"]').forEach(function(row) {
      if (row.style.display==='none') return;
      var cb = row.querySelector('input[type=checkbox]');
      if (cb && !cb.disabled) cb.checked = chk.checked;
    });
    shdUpdateChips(teamId);
  };

  /* ── Assign tab: individual teacher filter ── */
  var shdBldId = <?= (int)$selectedBldId ?>;
  window.shdApplyBldFilter = function() {
    var ignoreBld = document.getElementById('shdIgnoreBld');
    var ignore = ignoreBld ? ignoreBld.checked : true;
    document.querySelectorAll('.shdTeacherItem').forEach(function(row) {
      var blds  = (row.getAttribute('data-blds')||'').split(',').filter(Boolean).map(Number);
      var today = row.getAttribute('data-today')==='1';
      if (today) { row.style.display='none'; return; }
      if (ignore || shdBldId<=0) { row.style.display=''; return; }
      row.style.display = blds.includes(shdBldId) ? '' : 'none';
    });
    shdUpdateIndivCount();
  };
  function shdUpdateIndivCount() {
    var n = document.querySelectorAll('.shdTeacherChk:checked').length;
    var cnt = document.getElementById('shdIndivCnt');
    var sel = document.getElementById('shdSelCount');
    var btn = document.getElementById('shdIndivSubmit');
    if (cnt) cnt.textContent = n;
    if (sel) sel.textContent = 'เลือก ' + n + ' คน';
    if (btn) btn.disabled = n===0;
  }
  document.querySelectorAll('.shdTeacherChk').forEach(function(cb) {
    cb.addEventListener('change', function() {
      shdUpdateIndivCount();
      var all = document.getElementById('shdSelectAll');
      if (all) all.checked = false;
    });
  });
  window.shdToggleAllTeachers = function(chk) {
    document.querySelectorAll('.shdTeacherItem').forEach(function(row) {
      if (row.style.display==='none') return;
      var cb = row.querySelector('.shdTeacherChk');
      if (cb && !cb.disabled) cb.checked = chk.checked;
    });
    shdUpdateIndivCount();
  };
  shdApplyBldFilter();

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
