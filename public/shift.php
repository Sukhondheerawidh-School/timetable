<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_holiday_duty_init($pdo);

$user = currentUser();
$isAdmin = is_array($user) && (($user['role'] ?? '') === 'admin');

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$activeYearId = tt_active_year_id($pdo);
if ($activeYearId <= 0 && !empty($years)) $activeYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $activeYearId);
if ($year_id <= 0 && !empty($years)) $year_id = (int)$years[0]['id'];

$validTabs = ['dates','posts','teams','assign','substitute','report'];
$tab = (string)($_GET['tab'] ?? 'dates');
if (!in_array($tab, $validTabs, true)) $tab = 'dates';

$err = '';

function tt_be_year_from_ad(int $adYear): int {
	return $adYear + 543;
}

function tt_ad_year_from_be(int $beYear): int {
	return $beYear - 543;
}

/**
 * Format Y-m-d (AD) to d/m/YYYY (BE)
 */
function tt_date_be(?string $ymd): string {
	$ymd = trim((string)$ymd);
	if ($ymd === '') return '';
	$dt = DateTime::createFromFormat('Y-m-d', $ymd);
	if (!$dt) return $ymd;
	$y = (int)$dt->format('Y');
	$m = (int)$dt->format('m');
	$d = (int)$dt->format('d');
	return sprintf('%02d/%02d/%d', $d, $m, tt_be_year_from_ad($y));
}

/**
 * Format Y-m-d (AD) to "j เดือน YYYY" (BE), e.g. "21 มีนาคม 2569"
 */
function tt_date_be_long(?string $ymd): string {
	$ymd = trim((string)$ymd);
	if ($ymd === '') return '';
	$dt = DateTime::createFromFormat('Y-m-d', $ymd);
	if (!$dt) return $ymd;
	$monthNames = [
		1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
		5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
		9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
	];
	$y = (int)$dt->format('Y');
	$m = (int)$dt->format('n');
	$d = (int)$dt->format('j');
	$mn = $monthNames[$m] ?? (string)$m;
	return $d . ' ' . $mn . ' ' . tt_be_year_from_ad($y);
}

/**
 * Parse d/m/YYYY (BE) to Y-m-d (AD). Returns '' if invalid.
 */
function tt_parse_be_date(string $be): string {
	$be = trim($be);
	if ($be === '') return '';
	if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $be, $m)) return '';
	$day = (int)$m[1];
	$mon = (int)$m[2];
	$yearBe = (int)$m[3];
	$yearAd = tt_ad_year_from_be($yearBe);
	if ($yearAd < 1900 || $yearAd > 2500) return '';
	if (!checkdate($mon, $day, $yearAd)) return '';
	return sprintf('%04d-%02d-%02d', $yearAd, $mon, $day);
}

function tt_redirect_shift(int $yearId, string $tab, array $extra = []): void {
	$qs = array_merge(['year_id' => $yearId, 'tab' => $tab], $extra);
	redirect('shift.php?' . http_build_query($qs));
}

// Load teachers once (used in teams/assign/substitute)
$teachers = $pdo->query('SELECT id, teacher_code, first_name, last_name FROM teachers ORDER BY teacher_code, first_name, last_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!verify_csrf($_POST['csrf'] ?? '')) {
		$err = 'CSRF ไม่ถูกต้อง';
	} else {
		$action = (string)($_POST['action'] ?? '');
		$postYearId = (int)($_POST['year_id'] ?? $year_id);
		$postTab = (string)($_POST['tab'] ?? $tab);
		if (!in_array($postTab, $validTabs, true)) $postTab = $tab;

		try {
			// Admin-only writes
			$adminActions = [
				'date_create','date_delete',
				'post_create','post_update','post_toggle','post_delete',
				'team_create','team_update','team_toggle','team_delete','team_members_set',
				'assign_teacher','assign_team','unassign',
				'assign_auto_run',
				'substitute_set','substitute_clear'
			];
			if (in_array($action, $adminActions, true) && !$isAdmin) {
				throw new Exception('ต้องเป็นผู้ดูแลระบบ (admin)');
			}

			if ($action === 'date_create') {
				$d = trim((string)($_POST['duty_date'] ?? '')); // AD Y-m-d
				$be = trim((string)($_POST['duty_date_be'] ?? '')); // BE d/m/YYYY
				if ($d === '' && $be !== '') {
					$d = tt_parse_be_date($be);
				}
				$note = trim((string)($_POST['note'] ?? ''));
				if ($d === '') throw new Exception('กรุณากรอกวันที่ (รูปแบบ 31/12/2569)');
				$st = $pdo->prepare('INSERT INTO holiday_duty_dates(academic_year_id, duty_date, note) VALUES (?,?,?)');
				$st->execute([$postYearId, $d, $note !== '' ? $note : null]);
				flash_set('success', 'เพิ่มวันเวรแล้ว');
				tt_redirect_shift($postYearId, 'dates');
			}

			if ($action === 'date_delete') {
				$id = (int)($_POST['id'] ?? 0);
				$st = $pdo->prepare('DELETE FROM holiday_duty_dates WHERE id=? AND academic_year_id=?');
				$st->execute([$id, $postYearId]);
				flash_set('success', 'ลบวันเวรแล้ว');
				tt_redirect_shift($postYearId, 'dates');
			}

			if ($action === 'post_create') {
				$name = trim((string)($_POST['post_name'] ?? ''));
				$req = (int)($_POST['required_count'] ?? 1);
				if ($name === '') throw new Exception('กรุณากรอกชื่อจุดเวร');
				if ($req <= 0) $req = 1;
				$st = $pdo->prepare('INSERT INTO holiday_duty_posts(academic_year_id, post_name, required_count, is_active, sort_order) VALUES (?,?,?,?,?)');
				$st->execute([$postYearId, $name, $req, 1, 0]);
				flash_set('success', 'เพิ่มจุดเวรแล้ว');
				tt_redirect_shift($postYearId, 'posts');
			}

			if ($action === 'post_update') {
				$id = (int)($_POST['id'] ?? 0);
				$name = trim((string)($_POST['post_name'] ?? ''));
				$req = (int)($_POST['required_count'] ?? 1);
				if ($id <= 0) throw new Exception('ไม่พบ ID');
				if ($name === '') throw new Exception('กรุณากรอกชื่อจุดเวร');
				if ($req <= 0) $req = 1;
				$st = $pdo->prepare('UPDATE holiday_duty_posts SET post_name=?, required_count=? WHERE id=? AND academic_year_id=?');
				$st->execute([$name, $req, $id, $postYearId]);
				flash_set('success', 'แก้ไขจุดเวรแล้ว');
				tt_redirect_shift($postYearId, 'posts');
			}

			if ($action === 'post_toggle') {
				$id = (int)($_POST['id'] ?? 0);
				$enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
				$st = $pdo->prepare('UPDATE holiday_duty_posts SET is_active=? WHERE id=? AND academic_year_id=?');
				$st->execute([$enabled, $id, $postYearId]);
				flash_set('success', 'บันทึกสถานะแล้ว');
				tt_redirect_shift($postYearId, 'posts');
			}

			if ($action === 'post_delete') {
				$id = (int)($_POST['id'] ?? 0);
				$cnt = $pdo->prepare('SELECT COUNT(*) FROM holiday_duty_assignments a JOIN holiday_duty_posts p ON p.id=a.holiday_duty_post_id WHERE p.id=? AND p.academic_year_id=?');
				$cnt->execute([$id, $postYearId]);
				if ((int)$cnt->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีรายการลงเวรที่อ้างอิงจุดนี้อยู่');
				$st = $pdo->prepare('DELETE FROM holiday_duty_posts WHERE id=? AND academic_year_id=?');
				$st->execute([$id, $postYearId]);
				flash_set('success', 'ลบจุดเวรแล้ว');
				tt_redirect_shift($postYearId, 'posts');
			}

			if ($action === 'team_create') {
				$name = trim((string)($_POST['team_name'] ?? ''));
				if ($name === '') throw new Exception('กรุณากรอกชื่อทีม');
				$st = $pdo->prepare('INSERT INTO holiday_duty_teams(academic_year_id, team_name, is_active, sort_order) VALUES (?,?,?,?)');
				$st->execute([$postYearId, $name, 1, 0]);
				flash_set('success', 'เพิ่มทีมเวรแล้ว');
				tt_redirect_shift($postYearId, 'teams');
			}

			if ($action === 'team_update') {
				$id = (int)($_POST['id'] ?? 0);
				$name = trim((string)($_POST['team_name'] ?? ''));
				if ($id <= 0) throw new Exception('ไม่พบ ID');
				if ($name === '') throw new Exception('กรุณากรอกชื่อทีม');
				$st = $pdo->prepare('UPDATE holiday_duty_teams SET team_name=? WHERE id=? AND academic_year_id=?');
				$st->execute([$name, $id, $postYearId]);
				flash_set('success', 'แก้ไขชื่อทีมแล้ว');
				tt_redirect_shift($postYearId, 'teams');
			}

			if ($action === 'team_toggle') {
				$id = (int)($_POST['id'] ?? 0);
				$enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
				$st = $pdo->prepare('UPDATE holiday_duty_teams SET is_active=? WHERE id=? AND academic_year_id=?');
				$st->execute([$enabled, $id, $postYearId]);
				flash_set('success', 'บันทึกสถานะแล้ว');
				tt_redirect_shift($postYearId, 'teams');
			}

			if ($action === 'team_delete') {
				$id = (int)($_POST['id'] ?? 0);
				$cnt = $pdo->prepare('SELECT COUNT(*) FROM holiday_duty_assignments WHERE team_id=?');
				$cnt->execute([$id]);
				if ((int)$cnt->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีรายการลงเวรที่อ้างอิงทีมนี้อยู่');
				$st = $pdo->prepare('DELETE FROM holiday_duty_teams WHERE id=? AND academic_year_id=?');
				$st->execute([$id, $postYearId]);
				flash_set('success', 'ลบทีมแล้ว');
				tt_redirect_shift($postYearId, 'teams');
			}

			if ($action === 'team_members_set') {
				$teamId = (int)($_POST['team_id'] ?? 0);
				if ($teamId <= 0) throw new Exception('ไม่พบทีม');

				$chk = $pdo->prepare('SELECT 1 FROM holiday_duty_teams WHERE id=? AND academic_year_id=? LIMIT 1');
				$chk->execute([$teamId, $postYearId]);
				if (!$chk->fetchColumn()) throw new Exception('ไม่พบทีมในปีการศึกษานี้');

				$ids = $_POST['teacher_ids'] ?? [];
				if (!is_array($ids)) $ids = [];
				$clean = [];
				foreach ($ids as $tid) {
					$tid = (int)$tid;
					if ($tid > 0) $clean[$tid] = true;
				}
				$cleanIds = array_keys($clean);

				$pdo->prepare('DELETE FROM holiday_duty_team_members WHERE team_id=?')->execute([$teamId]);
				if ($cleanIds) {
					$ins = $pdo->prepare('INSERT INTO holiday_duty_team_members(team_id, teacher_id) VALUES (?,?)');
					foreach ($cleanIds as $tid) {
						$ins->execute([$teamId, (int)$tid]);
					}
				}
				flash_set('success', 'บันทึกสมาชิกทีมแล้ว');
				tt_redirect_shift($postYearId, 'teams', ['team_id' => $teamId]);
			}

			if ($action === 'assign_teacher') {
				$dateId = (int)($_POST['date_id'] ?? 0);
				$postId = (int)($_POST['post_id'] ?? 0);
				$teacherId = (int)($_POST['teacher_id'] ?? 0);
				if ($dateId <= 0 || $postId <= 0 || $teacherId <= 0) throw new Exception('เลือกข้อมูลให้ครบ');

				$meta = $pdo->prepare('SELECT d.id AS date_id, d.academic_year_id, p.id AS post_id, p.required_count
					FROM holiday_duty_dates d
					JOIN holiday_duty_posts p ON p.academic_year_id=d.academic_year_id
					WHERE d.id=? AND p.id=? AND d.academic_year_id=? AND p.is_active=1 LIMIT 1');
				$meta->execute([$dateId, $postId, $postYearId]);
				$m = $meta->fetch();
				if (!$m) throw new Exception('ไม่พบวันเวร/จุดเวร หรือจุดเวรถูกปิดใช้งาน');

				$cnt = $pdo->prepare('SELECT COUNT(*) FROM holiday_duty_assignments WHERE holiday_duty_date_id=? AND holiday_duty_post_id=?');
				$cnt->execute([$dateId, $postId]);
				if ((int)$cnt->fetchColumn() >= (int)$m['required_count']) throw new Exception('จุดนี้ครบจำนวนแล้ว');

				$ins = $pdo->prepare('INSERT IGNORE INTO holiday_duty_assignments(holiday_duty_date_id, holiday_duty_post_id, teacher_id, team_id, created_by_user_id) VALUES (?,?,?,?,?)');
				$ins->execute([$dateId, $postId, $teacherId, null, (int)($user['id'] ?? 0) ?: null]);
				if ((int)$ins->rowCount() <= 0) throw new Exception('ครูคนนี้ถูกลงเวรในจุดนี้แล้ว');
				flash_set('success', 'ลงเวรแล้ว');
				tt_redirect_shift($postYearId, 'assign', ['date_id' => $dateId]);
			}

			if ($action === 'assign_team') {
				$dateId = (int)($_POST['date_id'] ?? 0);
				$postId = (int)($_POST['post_id'] ?? 0);
				$teamId = (int)($_POST['team_id'] ?? 0);
				if ($dateId <= 0 || $postId <= 0 || $teamId <= 0) throw new Exception('เลือกข้อมูลให้ครบ');

				$meta = $pdo->prepare('SELECT d.id AS date_id, d.academic_year_id, p.id AS post_id, p.required_count
					FROM holiday_duty_dates d
					JOIN holiday_duty_posts p ON p.academic_year_id=d.academic_year_id
					WHERE d.id=? AND p.id=? AND d.academic_year_id=? AND p.is_active=1 LIMIT 1');
				$meta->execute([$dateId, $postId, $postYearId]);
				$m = $meta->fetch();
				if (!$m) throw new Exception('ไม่พบวันเวร/จุดเวร หรือจุดเวรถูกปิดใช้งาน');

				$teamChk = $pdo->prepare('SELECT 1 FROM holiday_duty_teams WHERE id=? AND academic_year_id=? AND is_active=1 LIMIT 1');
				$teamChk->execute([$teamId, $postYearId]);
				if (!$teamChk->fetchColumn()) throw new Exception('ไม่พบทีม หรือทีมถูกปิดใช้งาน');

				$mem = $pdo->prepare('SELECT teacher_id FROM holiday_duty_team_members WHERE team_id=? ORDER BY teacher_id');
				$mem->execute([$teamId]);
				$members = array_map('intval', $mem->fetchAll(PDO::FETCH_COLUMN));
				if (!$members) throw new Exception('ทีมนี้ยังไม่มีสมาชิก');

				$cnt = $pdo->prepare('SELECT COUNT(*) FROM holiday_duty_assignments WHERE holiday_duty_date_id=? AND holiday_duty_post_id=?');
				$cnt->execute([$dateId, $postId]);
				$current = (int)$cnt->fetchColumn();
				$left = max(0, (int)$m['required_count'] - $current);
				if ($left <= 0) throw new Exception('จุดนี้ครบจำนวนแล้ว');

				$ins = $pdo->prepare('INSERT IGNORE INTO holiday_duty_assignments(holiday_duty_date_id, holiday_duty_post_id, teacher_id, team_id, created_by_user_id) VALUES (?,?,?,?,?)');
				$added = 0;
				foreach ($members as $tid) {
					if ($added >= $left) break;
					$ins->execute([$dateId, $postId, (int)$tid, $teamId, (int)($user['id'] ?? 0) ?: null]);
					$added += (int)$ins->rowCount();
				}
				flash_set('success', 'ลงทั้งทีมแล้ว (เพิ่ม '.$added.' คน)');
				tt_redirect_shift($postYearId, 'assign', ['date_id' => $dateId]);
			}

			if ($action === 'assign_auto_run') {
				// Copy assignments from the first contiguous filled days to the remaining empty days (cyclic).
				// Example: dates 1..11 filled -> fill 12..end using 1..11 pattern.
				$datesStmt2 = $pdo->prepare('SELECT d.id, d.duty_date,
						(SELECT COUNT(*) FROM holiday_duty_assignments a WHERE a.holiday_duty_date_id=d.id) AS as_cnt
					FROM holiday_duty_dates d
					WHERE d.academic_year_id=?
					ORDER BY d.duty_date');
				$datesStmt2->execute([$postYearId]);
				$allDates = $datesStmt2->fetchAll();
				if (!$allDates) throw new Exception('ยังไม่มีวันเวร');

				$dateIds = [];
				$counts = [];
				foreach ($allDates as $dd) {
					$id = (int)$dd['id'];
					$dateIds[] = $id;
					$counts[] = (int)($dd['as_cnt'] ?? 0);
				}

				$filledCount = 0;
				for ($i = 0; $i < count($counts); $i++) {
					if ($counts[$i] > 0) $filledCount++;
					else break;
				}
				if ($filledCount <= 0) throw new Exception('ยังไม่มีเวรที่ลงไว้ในช่วงต้น (วันแรกๆ) เพื่อใช้เป็นต้นแบบ');
				if ($filledCount >= count($dateIds)) throw new Exception('ทุกวันมีการลงเวรแล้ว ไม่มีวันที่ให้รันต่อ');

				// Build source assignments map for the first filledCount days
				$srcIds = array_slice($dateIds, 0, $filledCount);
				$in = implode(',', array_fill(0, count($srcIds), '?'));
				$srcStmt = $pdo->prepare(
					'SELECT a.holiday_duty_date_id AS date_id, a.holiday_duty_post_id AS post_id, a.teacher_id, a.team_id\n'
					.'FROM holiday_duty_assignments a\n'
					.'WHERE a.holiday_duty_date_id IN ('.$in.')\n'
					.'ORDER BY a.holiday_duty_date_id, a.holiday_duty_post_id, a.teacher_id'
				);
				$srcStmt->execute($srcIds);
				$srcMap = []; // date_id => list
				while ($r = $srcStmt->fetch(PDO::FETCH_ASSOC)) {
					$did = (int)$r['date_id'];
					if (!isset($srcMap[$did])) $srcMap[$did] = [];
					$srcMap[$did][] = [
						'post_id' => (int)$r['post_id'],
						'teacher_id' => (int)$r['teacher_id'],
						'team_id' => $r['team_id'] === null ? null : (int)$r['team_id'],
					];
				}
				foreach ($srcIds as $sid) {
					if (empty($srcMap[(int)$sid])) {
						throw new Exception('ช่วงต้นมีบางวันที่นับว่า "ลงเวรแล้ว" แต่ไม่มีรายการ (โปรดตรวจสอบ)');
					}
				}

				$ins = $pdo->prepare('INSERT IGNORE INTO holiday_duty_assignments(holiday_duty_date_id, holiday_duty_post_id, teacher_id, team_id, created_by_user_id) VALUES (?,?,?,?,?)');
				$createdBy = (int)$user['id'];
				$filledDays = 0;
				$filledRows = 0;

				$pdo->beginTransaction();
				try {
					for ($i = $filledCount; $i < count($dateIds); $i++) {
						// Fill only empty days to avoid overwriting
						if ($counts[$i] > 0) continue;

						$targetDateId = (int)$dateIds[$i];
						$srcIndex = $i % $filledCount;
						$srcDateId = (int)$dateIds[$srcIndex];
						$srcList = $srcMap[$srcDateId] ?? [];
						if (!$srcList) continue;

						$dayAdded = 0;
						foreach ($srcList as $it) {
							$ins->execute([$targetDateId, (int)$it['post_id'], (int)$it['teacher_id'], $it['team_id'], $createdBy]);
							$dayAdded += (int)$ins->rowCount();
						}

						if ($dayAdded > 0) {
							$filledDays++;
							$filledRows += $dayAdded;
						}
					}
					$pdo->commit();
				} catch (Throwable $e2) {
					$pdo->rollBack();
					throw $e2;
				}

				flash_set('success', 'รันเวรแล้ว: เติม '.$filledDays.' วัน (เพิ่ม '.$filledRows.' รายการ)');
				tt_redirect_shift($postYearId, 'assign');
			}

			if ($action === 'unassign') {
				$assignmentId = (int)($_POST['assignment_id'] ?? 0);
				$dateId = (int)($_POST['date_id'] ?? 0);
				if ($assignmentId <= 0) throw new Exception('ไม่พบรายการ');
				$st = $pdo->prepare('DELETE a FROM holiday_duty_assignments a
					JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id
					WHERE a.id=? AND d.academic_year_id=?');
				$st->execute([$assignmentId, $postYearId]);
				flash_set('success', 'ลบการลงเวรแล้ว');
				tt_redirect_shift($postYearId, 'assign', ['date_id' => $dateId]);
			}

			if ($action === 'substitute_set') {
				$assignmentId = (int)($_POST['assignment_id'] ?? 0);
				$toTeacherId = (int)($_POST['to_teacher_id'] ?? 0);
				$dateId = (int)($_POST['date_id'] ?? 0);
				$reason = trim((string)($_POST['reason'] ?? ''));
				if ($assignmentId <= 0 || $toTeacherId <= 0) throw new Exception('เลือกข้อมูลให้ครบ');

				$as = $pdo->prepare('SELECT a.id, a.teacher_id
					FROM holiday_duty_assignments a
					JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id
					WHERE a.id=? AND d.academic_year_id=? LIMIT 1');
				$as->execute([$assignmentId, $postYearId]);
				$a = $as->fetch();
				if (!$a) throw new Exception('ไม่พบรายการลงเวร');
				$fromTeacherId = (int)$a['teacher_id'];
				if ($fromTeacherId === $toTeacherId) throw new Exception('ครูผู้แทนต้องไม่ใช่คนเดิม');

				$st = $pdo->prepare('INSERT INTO holiday_duty_substitutions(assignment_id, from_teacher_id, to_teacher_id, reason, created_by_user_id)
					VALUES (?,?,?,?,?)
					ON DUPLICATE KEY UPDATE to_teacher_id=VALUES(to_teacher_id), reason=VALUES(reason), updated_at=CURRENT_TIMESTAMP');
				$st->execute([$assignmentId, $fromTeacherId, $toTeacherId, $reason !== '' ? $reason : null, (int)($user['id'] ?? 0) ?: null]);
				flash_set('success', 'บันทึกการแทนเวรแล้ว');
				tt_redirect_shift($postYearId, 'substitute', ['date_id' => $dateId]);
			}

			if ($action === 'substitute_clear') {
				$assignmentId = (int)($_POST['assignment_id'] ?? 0);
				$dateId = (int)($_POST['date_id'] ?? 0);
				if ($assignmentId <= 0) throw new Exception('ไม่พบรายการ');
				$st = $pdo->prepare('DELETE s FROM holiday_duty_substitutions s
					JOIN holiday_duty_assignments a ON a.id=s.assignment_id
					JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id
					WHERE s.assignment_id=? AND d.academic_year_id=?');
				$st->execute([$assignmentId, $postYearId]);
				flash_set('success', 'ล้างการแทนเวรแล้ว');
				tt_redirect_shift($postYearId, 'substitute', ['date_id' => $dateId]);
			}

			throw new Exception('ไม่รองรับ action นี้');
		} catch (Throwable $e) {
			$err = 'ผิดพลาด: ' . $e->getMessage();
		}
	}
}

$flash = flash_get();

// Data for tabs
$datesStmt = $pdo->prepare('SELECT d.id, d.duty_date, d.note,
		(SELECT COUNT(*) FROM holiday_duty_assignments a WHERE a.holiday_duty_date_id=d.id) AS as_cnt
	FROM holiday_duty_dates d
	WHERE d.academic_year_id=?
	ORDER BY d.duty_date');
$datesStmt->execute([$year_id]);
$dates = $datesStmt->fetchAll();

// Precompute: contiguous filled days from the start (used by auto-run helper)
$ttFilledLeadingDays = 0;
foreach ($dates as $d0) {
	if ((int)($d0['as_cnt'] ?? 0) > 0) $ttFilledLeadingDays++;
	else break;
}
$ttTotalDays = count($dates);
$ttRemainingDays = max(0, $ttTotalDays - $ttFilledLeadingDays);

$postsStmt = $pdo->prepare('SELECT id, post_name, required_count, is_active
	FROM holiday_duty_posts
	WHERE academic_year_id=?
	ORDER BY is_active DESC, sort_order, post_name');
$postsStmt->execute([$year_id]);
$posts = $postsStmt->fetchAll();

$teamsStmt = $pdo->prepare('SELECT t.id, t.team_name, t.is_active,
		(SELECT COUNT(*) FROM holiday_duty_team_members m WHERE m.team_id=t.id) AS member_cnt
	FROM holiday_duty_teams t
	WHERE t.academic_year_id=?
	ORDER BY t.is_active DESC, t.sort_order, t.team_name');
$teamsStmt->execute([$year_id]);
$teams = $teamsStmt->fetchAll();

$teamMembers = []; // team_id => [teacher_id => true]
$tmStmt = $pdo->prepare('SELECT m.team_id, m.teacher_id FROM holiday_duty_team_members m JOIN holiday_duty_teams t ON t.id=m.team_id WHERE t.academic_year_id=?');
$tmStmt->execute([$year_id]);
while ($r = $tmStmt->fetch(PDO::FETCH_ASSOC)) {
	$teamMembers[(int)$r['team_id']][(int)$r['teacher_id']] = true;
}

$selectedDateId = (int)($_GET['date_id'] ?? 0);
$dateIdSet = [];
foreach ($dates as $d0) { $dateIdSet[(int)$d0['id']] = true; }
if ($selectedDateId <= 0 || ($selectedDateId > 0 && empty($dateIdSet[$selectedDateId]))) {
	$selectedDateId = !empty($dates) ? (int)$dates[0]['id'] : 0;
}

// Assignments for selected date
$assignmentsByPost = []; // post_id => list
$subByAssignment = [];  // assignment_id => row

if ($selectedDateId > 0) {
	$as = $pdo->prepare('SELECT a.id AS assignment_id, a.holiday_duty_post_id AS post_id, a.teacher_id, a.team_id,
			p.post_name, p.required_count,
			t.teacher_code, t.first_name, t.last_name,
			s.to_teacher_id,
			t2.teacher_code AS sub_code, t2.first_name AS sub_first, t2.last_name AS sub_last,
			s.reason
		FROM holiday_duty_assignments a
		JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id
		JOIN holiday_duty_posts p ON p.id=a.holiday_duty_post_id
		JOIN teachers t ON t.id=a.teacher_id
		LEFT JOIN holiday_duty_substitutions s ON s.assignment_id=a.id
		LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
		WHERE a.holiday_duty_date_id=? AND d.academic_year_id=?
		ORDER BY p.sort_order, p.post_name, t.teacher_code, t.first_name, t.last_name');
	$as->execute([$selectedDateId, $year_id]);
	while ($r = $as->fetch(PDO::FETCH_ASSOC)) {
		$pid = (int)$r['post_id'];
		if (!isset($assignmentsByPost[$pid])) $assignmentsByPost[$pid] = [];
		$assignmentsByPost[$pid][] = $r;
		if (!empty($r['to_teacher_id'])) {
			$subByAssignment[(int)$r['assignment_id']] = $r;
		}
	}
}

$pageTitle = 'เวรวันหยุด';
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';

$baseTab = 'px-3 py-2 rounded-xl transition whitespace-nowrap';
$inactiveTab = $baseTab . ' hover:bg-white text-slate-700';
$activeTab = $baseTab . ' bg-slate-900 text-white';
?>

<div class="max-w-6xl mx-auto px-4 mt-8">
	<div class="mb-3">
		<h1 class="text-xl font-semibold">🗓️ เวรวันหยุด (เฝ้าเวร)</h1>
		<div class="text-sm text-slate-500">กำหนดวัน/จุด/ทีม และลงเวรรายวัน พร้อมระบบแทนเวรและรายงานสรุป</div>
	</div>

	<?php if ($flash): ?>
		<div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
			<?= htmlspecialchars($flash['msg']); ?>
		</div>
	<?php endif; ?>
	<?php if ($err): ?>
		<div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><?= htmlspecialchars($err) ?></div>
	<?php endif; ?>

	<form method="get" class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
		<input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
		<?php if ($selectedDateId > 0): ?>
			<input type="hidden" name="date_id" value="<?= (int)$selectedDateId ?>">
		<?php endif; ?>
		<div class="md:col-span-4">
			<label class="block text-xs mb-1">ปีการศึกษา</label>
			<select name="year_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
				<?php foreach ($years as $y): ?>
					<option value="<?= (int)$y['id'] ?>" <?= (int)$y['id']===$year_id?'selected':''; ?>><?= htmlspecialchars($y['year_label']).(!empty($y['is_active'])?' (ใช้งาน)':'') ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="md:col-span-8 flex items-end justify-end">
			<?php if (!$isAdmin): ?>
				<div class="text-xs text-slate-500">หมายเหตุ: การเพิ่ม/แก้ไขข้อมูลต้องเป็น admin</div>
			<?php endif; ?>
		</div>
	</form>

	<div class="bg-white rounded-2xl shadow p-4 mb-4">
		<div class="inline-flex flex-wrap gap-2 p-1 rounded-2xl bg-slate-50 border">
			<a class="<?= $tab==='dates' ? $activeTab : $inactiveTab; ?>" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'dates'])) ?>">วันเวร</a>
			<a class="<?= $tab==='posts' ? $activeTab : $inactiveTab; ?>" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'posts'])) ?>">จุดเวร</a>
			<a class="<?= $tab==='teams' ? $activeTab : $inactiveTab; ?>" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'teams'])) ?>">ทีมเวร</a>
			<a class="<?= $tab==='assign' ? $activeTab : $inactiveTab; ?>" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'assign','date_id'=>$selectedDateId])) ?>">ลงเวร</a>
			<a class="<?= $tab==='substitute' ? $activeTab : $inactiveTab; ?>" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'substitute','date_id'=>$selectedDateId])) ?>">แทนเวร</a>
			<a class="<?= $tab==='report' ? $activeTab : $inactiveTab; ?>" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'report'])) ?>">รายงาน</a>
		</div>
	</div>

	<?php if ($tab === 'dates'): ?>
		<div class="bg-white rounded-2xl shadow p-4 mb-4">
			<div class="font-medium mb-3">กำหนดวันที่ต้องมีเวร (ปีการศึกษา)</div>
			<?php if ($isAdmin): ?>
				<form method="post" class="flex flex-col md:flex-row gap-2">
					<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
					<input type="hidden" name="action" value="date_create">
					<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
					<input type="hidden" name="tab" value="dates">
					<input type="hidden" name="duty_date" id="ttDutyDateAd" value="">
					<input type="text" name="duty_date_be" id="ttDutyDateBe" class="border rounded px-3 py-2" placeholder="วัน/เดือน/พ.ศ. เช่น 18/03/2569" required>
					<input type="date" id="ttDutyDatePicker" class="border rounded px-3 py-2" title="เลือกจากปฏิทิน (จะแปลงเป็น พ.ศ. ให้อัตโนมัติ)">
					<input name="note" class="flex-1 border rounded px-3 py-2" placeholder="หมายเหตุ (ถ้ามี)">
					<button class="px-4 py-2 rounded bg-slate-900 text-white">เพิ่ม</button>
				</form>
				<div class="mt-2 text-xs text-slate-500">ระบบบันทึกเป็น ค.ศ. แต่แสดง/กรอกเป็น พ.ศ.</div>
			<?php else: ?>
				<div class="text-xs text-slate-500">ดูรายการได้ แต่เพิ่ม/ลบได้เฉพาะ admin</div>
			<?php endif; ?>
		</div>

		<div class="bg-white rounded-2xl shadow overflow-hidden">
			<div class="px-4 py-3 border-b font-medium">รายการวันเวร</div>
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm">
					<thead class="bg-slate-50">
						<tr>
							<th class="text-left px-3 py-2">วันที่</th>
							<th class="text-left px-3 py-2">หมายเหตุ</th>
							<th class="text-right px-3 py-2">ลงเวรแล้ว</th>
							<th class="text-right px-3 py-2">จัดการ</th>
						</tr>
					</thead>
					<tbody>
						<?php if (!$dates): ?>
							<tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">ยังไม่มีวันเวร</td></tr>
						<?php endif; ?>
						<?php foreach ($dates as $d): ?>
							<tr class="border-t">
								<td class="px-3 py-2 whitespace-nowrap"><?= htmlspecialchars(tt_date_be((string)$d['duty_date'])) ?></td>
								<td class="px-3 py-2"><?= htmlspecialchars((string)($d['note'] ?? '')) ?></td>
								<td class="px-3 py-2 text-right"><?= (int)$d['as_cnt'] ?></td>
								<td class="px-3 py-2 text-right">
									<?php if ($isAdmin): ?>
										<form method="post" class="inline" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันลบวันเวร', text: 'ลบวันเวรนี้? รายการลงเวรจะถูกลบด้วย', confirmButtonText: 'ลบ' });">
											<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
											<input type="hidden" name="action" value="date_delete">
											<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
											<input type="hidden" name="tab" value="dates">
											<input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
											<button class="px-3 py-1 rounded bg-rose-600 text-white">ลบ</button>
										</form>
									<?php else: ?>
										<span class="text-slate-400">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($tab === 'posts'): ?>
		<div class="bg-white rounded-2xl shadow p-4 mb-4">
			<div class="font-medium mb-3">กำหนดจุดที่ต้องอยู่เวร และจำนวนคนที่ต้องอยู่</div>
			<?php if ($isAdmin): ?>
				<form method="post" class="flex flex-col md:flex-row gap-2">
					<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
					<input type="hidden" name="action" value="post_create">
					<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
					<input type="hidden" name="tab" value="posts">
					<input name="post_name" class="flex-1 border rounded px-3 py-2" placeholder="เช่น หน้าอาคาร / เวรอำนวยการ" required>
					<input name="required_count" type="number" min="1" class="w-32 border rounded px-3 py-2" value="1" required>
					<button class="px-4 py-2 rounded bg-slate-900 text-white">เพิ่ม</button>
				</form>
			<?php else: ?>
				<div class="text-xs text-slate-500">ดูรายการได้ แต่เพิ่ม/แก้ไขได้เฉพาะ admin</div>
			<?php endif; ?>
		</div>

		<div class="bg-white rounded-2xl shadow overflow-hidden">
			<div class="px-4 py-3 border-b font-medium">รายการจุดเวร</div>
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm">
					<thead class="bg-slate-50">
						<tr>
							<th class="text-left px-3 py-2">สถานะ</th>
							<th class="text-left px-3 py-2">ชื่อจุดเวร</th>
							<th class="text-right px-3 py-2">ต้องการคน</th>
							<th class="text-right px-3 py-2">จัดการ</th>
						</tr>
					</thead>
					<tbody>
						<?php if (!$posts): ?>
							<tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">ยังไม่มีจุดเวร</td></tr>
						<?php endif; ?>
						<?php foreach ($posts as $p): ?>
							<tr class="border-t">
								<td class="px-3 py-2"><?= (int)$p['is_active'] ? 'เปิด' : 'ปิด'; ?></td>
								<td class="px-3 py-2">
									<?php if ($isAdmin): ?>
										<form method="post" class="flex gap-2 items-center">
											<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
											<input type="hidden" name="action" value="post_update">
											<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
											<input type="hidden" name="tab" value="posts">
											<input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
											<input name="post_name" class="w-full border rounded px-2 py-1" value="<?= htmlspecialchars((string)$p['post_name']) ?>" required>
											<input name="required_count" type="number" min="1" class="w-24 border rounded px-2 py-1" value="<?= (int)$p['required_count'] ?>" required>
											<button class="px-3 py-1 rounded bg-slate-900 text-white">บันทึก</button>
										</form>
									<?php else: ?>
										<?= htmlspecialchars((string)$p['post_name']) ?>
									<?php endif; ?>
								</td>
								<td class="px-3 py-2 text-right"><?= (int)$p['required_count'] ?></td>
								<td class="px-3 py-2 text-right">
									<?php if ($isAdmin): ?>
										<form method="post" class="inline">
											<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
											<input type="hidden" name="action" value="post_toggle">
											<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
											<input type="hidden" name="tab" value="posts">
											<input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
											<input type="hidden" name="enabled" value="<?= (int)$p['is_active'] ? 0 : 1 ?>">
											<button class="px-3 py-1 rounded <?= (int)$p['is_active'] ? 'bg-slate-200' : 'bg-emerald-600 text-white' ?>">
												<?= (int)$p['is_active'] ? 'ปิด' : 'เปิด' ?>
											</button>
										</form>
										<form method="post" class="inline" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันลบจุดเวร', text: 'ลบจุดเวรนี้?', confirmButtonText: 'ลบ' });">
											<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
											<input type="hidden" name="action" value="post_delete">
											<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
											<input type="hidden" name="tab" value="posts">
											<input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
											<button class="px-3 py-1 rounded bg-rose-600 text-white">ลบ</button>
										</form>
									<?php else: ?>
										<span class="text-slate-400">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($tab === 'teams'): ?>
		<div class="bg-white rounded-2xl shadow p-4 mb-4">
			<div class="font-medium mb-3">สร้างทีมเวร (ลงทั้งทีมได้ / ลงรายคนได้)</div>
			<?php if ($isAdmin): ?>
				<form method="post" class="flex flex-col md:flex-row gap-2">
					<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
					<input type="hidden" name="action" value="team_create">
					<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
					<input type="hidden" name="tab" value="teams">
					<input name="team_name" class="flex-1 border rounded px-3 py-2" placeholder="เช่น ทีมA" required>
					<button class="px-4 py-2 rounded bg-slate-900 text-white">เพิ่มทีม</button>
				</form>
			<?php else: ?>
				<div class="text-xs text-slate-500">ดูรายการได้ แต่เพิ่ม/แก้ไขได้เฉพาะ admin</div>
			<?php endif; ?>
		</div>

		<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
			<?php if (!$teams): ?>
				<div class="bg-white rounded-2xl shadow p-6 text-center text-slate-500">ยังไม่มีทีม</div>
			<?php endif; ?>
			<?php foreach ($teams as $t): ?>
				<div class="bg-white rounded-2xl shadow p-4">
					<div class="flex items-start justify-between gap-3">
						<div>
							<div class="font-medium"><?= htmlspecialchars((string)$t['team_name']) ?></div>
							<div class="text-xs text-slate-500">สมาชิก <?= (int)$t['member_cnt'] ?> คน • สถานะ: <?= (int)$t['is_active'] ? 'เปิด' : 'ปิด' ?></div>
						</div>
						<?php if ($isAdmin): ?>
							<div class="flex gap-2">
								<form method="post" class="inline flex gap-2">
									<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
									<input type="hidden" name="action" value="team_update">
									<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
									<input type="hidden" name="tab" value="teams">
									<input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
									<input name="team_name" class="w-28 border rounded px-2 py-1" value="<?= htmlspecialchars((string)$t['team_name']) ?>" title="แก้ไขชื่อทีม" required>
									<button class="px-3 py-1 rounded bg-slate-900 text-white">แก้ไข</button>
								</form>
								<form method="post" class="inline">
									<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
									<input type="hidden" name="action" value="team_toggle">
									<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
									<input type="hidden" name="tab" value="teams">
									<input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
									<input type="hidden" name="enabled" value="<?= (int)$t['is_active'] ? 0 : 1 ?>">
									<button class="px-3 py-1 rounded <?= (int)$t['is_active'] ? 'bg-slate-200' : 'bg-emerald-600 text-white' ?>">
										<?= (int)$t['is_active'] ? 'ปิด' : 'เปิด' ?>
									</button>
								</form>
								<form method="post" class="inline" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันลบทีม', text: 'ลบทีมนี้?', confirmButtonText: 'ลบ' });">
									<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
									<input type="hidden" name="action" value="team_delete">
									<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
									<input type="hidden" name="tab" value="teams">
									<input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
									<button class="px-3 py-1 rounded bg-rose-600 text-white">ลบ</button>
								</form>
							</div>
						<?php endif; ?>
					</div>

					<?php if ($isAdmin): ?>
						<div class="mt-3">
							<form method="post">
								<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
								<input type="hidden" name="action" value="team_members_set">
								<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
								<input type="hidden" name="tab" value="teams">
								<input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
								<label class="block text-xs mb-2">เลือกสมาชิกทีม (ติ๊กเลือก) — เลือกแล้วจะขึ้นเป็นการ์ด</label>

								<div id="ttTeamSelected-<?= (int)$t['id'] ?>" class="flex flex-wrap gap-2 mb-3"></div>

								<div class="border rounded-2xl p-3">
									<div class="text-xs text-slate-500 mb-2">รายชื่อครู</div>
									<div class="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-72 overflow-auto">
										<?php foreach ($teachers as $tc): ?>
											<?php
												$tid = (int)$tc['id'];
												$sel = !empty($teamMembers[(int)$t['id']][$tid]);
												$label = trim((string)($tc['teacher_code'] ?? '').' '.$tc['first_name'].' '.$tc['last_name']);
												$cbId = 'ttTeam-'.$t['id'].'-t-'.$tid;
											?>
											<label class="flex items-center gap-2 px-2 py-1 rounded-xl hover:bg-slate-50">
												<input
													type="checkbox"
													name="teacher_ids[]"
													value="<?= $tid ?>"
													id="<?= htmlspecialchars($cbId) ?>"
													data-tt-team="<?= (int)$t['id'] ?>"
													data-tt-name="<?= htmlspecialchars($label) ?>"
													<?= $sel ? 'checked' : '' ?>
													class="h-4 w-4"
												>
												<span class="text-sm"><?= htmlspecialchars($label) ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</div>

								<div class="mt-3 flex items-center justify-between">
									<div class="text-xs text-slate-500">ปุ่ม “ลบ” บนการ์ดจะยกเลิกติ๊ก</div>
									<button class="px-4 py-2 rounded bg-slate-900 text-white">บันทึกสมาชิก</button>
								</div>
							</form>
						</div>
					<?php else: ?>
						<div class="mt-3 text-xs text-slate-500">สิทธิ์ admin เท่านั้นในการจัดสมาชิกทีม</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ($tab === 'assign'): ?>
		<div class="bg-white rounded-2xl shadow p-4 mb-4">
			<div class="font-medium mb-3">ลงเวรตามวันที่</div>
			<form method="get" class="grid grid-cols-1 md:grid-cols-12 gap-3">
				<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
				<input type="hidden" name="tab" value="assign">
				<div class="md:col-span-6">
					<label class="block text-xs mb-1">เลือกวันเวร</label>
					<select name="date_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
						<?php foreach ($dates as $d): ?>
							<option value="<?= (int)$d['id'] ?>" <?= (int)$d['id']===$selectedDateId ? 'selected' : '' ?>><?= htmlspecialchars(tt_date_be_long((string)$d['duty_date'])) ?><?= ($d['note'] ? ' - '.$d['note'] : '') ?></option>
						<?php endforeach; ?>
					</select>
					<?php if (!$dates): ?>
						<div class="text-xs text-rose-600 mt-2">กรุณาเพิ่ม “วันเวร” ก่อน</div>
					<?php endif; ?>
				</div>
				<div class="md:col-span-6 flex items-end justify-end">
					<div class="flex flex-wrap gap-2 justify-end">
						<a class="px-4 py-2 rounded border" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'dates'])) ?>">ไปเพิ่มวันเวร</a>
						<?php if ($isAdmin && $ttFilledLeadingDays > 0 && $ttRemainingDays > 0): ?>
							<button form="ttAssignAutoRunForm" class="px-4 py-2 rounded bg-slate-900 text-white">รันเวรต่อ</button>
						<?php endif; ?>
					</div>
				</div>
			</form>
			<?php if ($isAdmin && $ttFilledLeadingDays > 0 && $ttRemainingDays > 0): ?>
				<form id="ttAssignAutoRunForm" method="post" class="hidden" onsubmit="return ttConfirmSubmit(this, { title: 'รันเวรต่ออัตโนมัติ', text: 'ระบบจะคัดลอกเวรจากวัน 1–<?= (int)$ttFilledLeadingDays ?> ไปเติมวันถัดไปที่ยังว่างแบบวนลูป (ไม่ทับวันที่มีข้อมูลแล้ว) ต้องการดำเนินการหรือไม่?', confirmButtonText: 'รันเวร' });">
					<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
					<input type="hidden" name="action" value="assign_auto_run">
					<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
					<input type="hidden" name="tab" value="assign">
				</form>
			<?php endif; ?>
			<?php if ($ttTotalDays > 0): ?>
				<div class="mt-3 text-xs text-slate-500">
					สถานะ: ลงเวรแล้วต่อเนื่อง <?= (int)$ttFilledLeadingDays ?> / <?= (int)$ttTotalDays ?> วัน
					<?php if ($ttRemainingDays > 0): ?> • เหลือ <?= (int)$ttRemainingDays ?> วัน <?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ($selectedDateId > 0): ?>
			<div class="bg-white rounded-2xl shadow overflow-hidden">
				<div class="px-4 py-3 border-b font-medium">จุดเวร (ลงเวรได้ทั้งทีม/รายคน)</div>
				<div class="overflow-x-auto">
					<table class="min-w-full text-sm">
						<thead class="bg-slate-50">
							<tr>
								<th class="text-left px-3 py-2">จุดเวร</th>
								<th class="text-right px-3 py-2">ต้องการ</th>
								<th class="text-left px-3 py-2">รายชื่อ</th>
								<th class="text-right px-3 py-2">ลงเวร</th>
							</tr>
						</thead>
						<tbody>
							<?php if (!$posts): ?>
								<tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">กรุณาเพิ่ม “จุดเวร” ก่อน</td></tr>
							<?php endif; ?>
							<?php foreach ($posts as $p): ?>
								<?php if (!(int)$p['is_active']) continue; ?>
								<?php
									$pid = (int)$p['id'];
									$list = $assignmentsByPost[$pid] ?? [];
									$count = count($list);
								?>
								<tr class="border-t align-top">
									<td class="px-3 py-2 whitespace-nowrap">
										<div class="font-medium"><?= htmlspecialchars((string)$p['post_name']) ?></div>
										<div class="text-xs text-slate-500"><?= $count >= (int)$p['required_count'] ? 'ครบแล้ว' : 'ยังขาด '.((int)$p['required_count'] - $count).' คน' ?></div>
									</td>
									<td class="px-3 py-2 text-right whitespace-nowrap"><?= (int)$p['required_count'] ?></td>
									<td class="px-3 py-2">
										<?php if (!$list): ?>
											<div class="text-slate-500">—</div>
										<?php else: ?>
											<div class="flex flex-wrap gap-2">
												<?php foreach ($list as $a): ?>
													<div class="px-2 py-1 rounded bg-slate-50 border">
														<span><?= htmlspecialchars(($a['teacher_code'] ?? '').' '.$a['first_name'].' '.$a['last_name']) ?></span>
														<?php if (!empty($a['to_teacher_id'])): ?>
															<span class="text-xs text-amber-700">(แทน: <?= htmlspecialchars(($a['sub_code'] ?? '').' '.$a['sub_first'].' '.$a['sub_last']) ?>)</span>
														<?php endif; ?>
														<?php if ($isAdmin): ?>
															<form method="post" class="inline" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันลบการลงเวร', text: 'ลบการลงเวรนี้?', confirmButtonText: 'ลบ' });">
																<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
																<input type="hidden" name="action" value="unassign">
																<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
																<input type="hidden" name="tab" value="assign">
																<input type="hidden" name="date_id" value="<?= (int)$selectedDateId ?>">
																<input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
																<button class="ml-1 text-rose-600">ลบ</button>
															</form>
														<?php endif; ?>
													</div>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
									</td>
									<td class="px-3 py-2 text-right whitespace-nowrap">
										<?php if ($isAdmin): ?>
											<div class="flex flex-col gap-2 items-end">
												<form method="post" class="flex gap-2">
													<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
													<input type="hidden" name="action" value="assign_team">
													<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
													<input type="hidden" name="tab" value="assign">
													<input type="hidden" name="date_id" value="<?= (int)$selectedDateId ?>">
													<input type="hidden" name="post_id" value="<?= (int)$pid ?>">
													<select name="team_id" class="border rounded px-2 py-1">
														<option value="">— ทีม —</option>
														<?php foreach ($teams as $t): ?>
															<?php if (!(int)$t['is_active']) continue; ?>
															<option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars((string)$t['team_name']) ?></option>
														<?php endforeach; ?>
													</select>
													<button class="px-3 py-1 rounded bg-slate-900 text-white">ลงทั้งทีม</button>
												</form>

												<form method="post" class="flex gap-2">
													<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
													<input type="hidden" name="action" value="assign_teacher">
													<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
													<input type="hidden" name="tab" value="assign">
													<input type="hidden" name="date_id" value="<?= (int)$selectedDateId ?>">
													<input type="hidden" name="post_id" value="<?= (int)$pid ?>">
													<select name="teacher_id" class="border rounded px-2 py-1" required>
														<option value="">— เลือกครู —</option>
														<?php foreach ($teachers as $tc): ?>
															<option value="<?= (int)$tc['id'] ?>"><?= htmlspecialchars(($tc['teacher_code'] ?? '').' '.$tc['first_name'].' '.$tc['last_name']) ?></option>
														<?php endforeach; ?>
													</select>
													<button class="px-3 py-1 rounded bg-emerald-600 text-white">ลงรายคน</button>
												</form>
											</div>
										<?php else: ?>
											<span class="text-slate-400">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ($tab === 'substitute'): ?>
		<div class="bg-white rounded-2xl shadow p-4 mb-4">
			<div class="font-medium mb-3">แทนเวร (บันทึกว่าใครมาแทนใคร)</div>
			<form method="get" class="grid grid-cols-1 md:grid-cols-12 gap-3">
				<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
				<input type="hidden" name="tab" value="substitute">
				<div class="md:col-span-6">
					<label class="block text-xs mb-1">เลือกวันเวร</label>
					<select name="date_id" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
						<?php foreach ($dates as $d): ?>
							<option value="<?= (int)$d['id'] ?>" <?= (int)$d['id']===$selectedDateId ? 'selected' : '' ?>><?= htmlspecialchars(tt_date_be_long((string)$d['duty_date'])) ?><?= ($d['note'] ? ' - '.$d['note'] : '') ?></option>
						<?php endforeach; ?>
					</select>
					<?php if (!$dates): ?>
						<div class="text-xs text-rose-600 mt-2">กรุณาเพิ่ม “วันเวร” ก่อน</div>
					<?php endif; ?>
				</div>
				<div class="md:col-span-6 flex items-end justify-end">
					<a class="px-4 py-2 rounded border" href="<?= url('shift.php?'.http_build_query(['year_id'=>$year_id,'tab'=>'assign','date_id'=>$selectedDateId])) ?>">ไปหน้า “ลงเวร”</a>
				</div>
			</form>
		</div>

		<div class="bg-white rounded-2xl shadow overflow-hidden">
			<div class="px-4 py-3 border-b font-medium">รายการลงเวรของวัน</div>
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm">
					<thead class="bg-slate-50">
						<tr>
							<th class="text-left px-3 py-2">จุดเวร</th>
							<th class="text-left px-3 py-2">ครูเดิม</th>
							<th class="text-left px-3 py-2">ครูผู้แทน</th>
							<th class="text-left px-3 py-2">เหตุผล</th>
							<th class="text-right px-3 py-2">จัดการ</th>
						</tr>
					</thead>
					<tbody>
						<?php
							$rows = [];
							foreach ($assignmentsByPost as $pid => $list) {
								foreach ($list as $a) $rows[] = $a;
							}
						?>
						<?php if (!$rows): ?>
							<tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">ยังไม่มีการลงเวรในวันนี้</td></tr>
						<?php endif; ?>
						<?php foreach ($rows as $a): ?>
							<tr class="border-t">
								<td class="px-3 py-2"><?= htmlspecialchars((string)$a['post_name']) ?></td>
								<td class="px-3 py-2"><?= htmlspecialchars(($a['teacher_code'] ?? '').' '.$a['first_name'].' '.$a['last_name']) ?></td>
								<td class="px-3 py-2">
									<?= !empty($a['to_teacher_id']) ? htmlspecialchars(($a['sub_code'] ?? '').' '.$a['sub_first'].' '.$a['sub_last']) : '—' ?>
								</td>
								<td class="px-3 py-2"><?= htmlspecialchars((string)($a['reason'] ?? '')) ?></td>
								<td class="px-3 py-2 text-right">
									<?php if ($isAdmin): ?>
										<form method="post" class="flex flex-col md:flex-row gap-2 justify-end">
											<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
											<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
											<input type="hidden" name="tab" value="substitute">
											<input type="hidden" name="date_id" value="<?= (int)$selectedDateId ?>">
											<input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
											<input type="hidden" name="action" value="substitute_set">

											<select name="to_teacher_id" class="border rounded px-2 py-1" required>
												<option value="">— เลือกครูผู้แทน —</option>
												<?php foreach ($teachers as $tc): ?>
													<?php if ((int)$tc['id'] === (int)$a['teacher_id']) continue; ?>
													<option value="<?= (int)$tc['id'] ?>" <?= !empty($a['to_teacher_id']) && (int)$tc['id']===(int)$a['to_teacher_id'] ? 'selected' : '' ?>><?= htmlspecialchars(($tc['teacher_code'] ?? '').' '.$tc['first_name'].' '.$tc['last_name']) ?></option>
												<?php endforeach; ?>
											</select>
											<input name="reason" class="border rounded px-2 py-1" placeholder="เหตุผล (ถ้ามี)" value="<?= htmlspecialchars((string)($a['reason'] ?? '')) ?>">
											<button class="px-3 py-1 rounded bg-slate-900 text-white">บันทึก</button>
										</form>
										<?php if (!empty($a['to_teacher_id'])): ?>
											<form method="post" class="inline" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันล้างการแทนเวร', text: 'ล้างการแทนเวรนี้?', confirmButtonText: 'ล้าง' });">
												<input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
												<input type="hidden" name="action" value="substitute_clear">
												<input type="hidden" name="year_id" value="<?= (int)$year_id ?>">
												<input type="hidden" name="tab" value="substitute">
												<input type="hidden" name="date_id" value="<?= (int)$selectedDateId ?>">
												<input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
												<button class="mt-1 text-rose-600">ล้าง</button>
											</form>
										<?php endif; ?>
									<?php else: ?>
										<span class="text-slate-400">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($tab === 'report'): ?>
		<?php
			$activePosts = [];
			foreach ($posts as $p) {
				if ((int)($p['is_active'] ?? 0) !== 1) continue;
				$activePosts[] = $p;
			}

			// Prebuild: every date should show every active post (even if no assignments yet)
			$report = []; // dateKey => ['note'=>..., 'posts'=> [post_id => ['name'=>, 'required'=>, 'items'=>[]]]]
			foreach ($dates as $d0) {
				$dateKey = (string)$d0['duty_date'];
				$report[$dateKey] = ['note' => $d0['note'] ?? null, 'posts' => []];
				foreach ($activePosts as $p0) {
					$pid = (int)$p0['id'];
					$report[$dateKey]['posts'][$pid] = [
						'name' => (string)$p0['post_name'],
						'required' => (int)$p0['required_count'],
						'items' => [],
					];
				}
			}

			$detail = $pdo->prepare('SELECT d.duty_date, d.note,
					p.id AS post_id, p.post_name, p.required_count,
					a.id AS assignment_id, a.teacher_id,
					t.teacher_code, t.first_name, t.last_name,
					s.to_teacher_id,
					t2.teacher_code AS sub_code, t2.first_name AS sub_first, t2.last_name AS sub_last
				FROM holiday_duty_assignments a
				JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id
				JOIN holiday_duty_posts p ON p.id=a.holiday_duty_post_id
				JOIN teachers t ON t.id=a.teacher_id
				LEFT JOIN holiday_duty_substitutions s ON s.assignment_id=a.id
				LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
				WHERE d.academic_year_id=? AND p.is_active=1
				ORDER BY d.duty_date, p.sort_order, p.post_name, t.teacher_code, t.first_name, t.last_name');
			$detail->execute([$year_id]);
			while ($r = $detail->fetch(PDO::FETCH_ASSOC)) {
				$dateKey = (string)$r['duty_date'];
				$pid = (int)$r['post_id'];
				if (!isset($report[$dateKey]['posts'][$pid])) continue;
				$report[$dateKey]['posts'][$pid]['items'][] = $r;
			}

			$sumStmt = $pdo->prepare('SELECT x.teacher_id, x.duty_cnt, t.teacher_code, t.first_name, t.last_name
				FROM (
					SELECT COALESCE(s.to_teacher_id, a.teacher_id) AS teacher_id, COUNT(*) AS duty_cnt
					FROM holiday_duty_assignments a
					JOIN holiday_duty_dates d ON d.id=a.holiday_duty_date_id
					LEFT JOIN holiday_duty_substitutions s ON s.assignment_id=a.id
					WHERE d.academic_year_id=?
					GROUP BY COALESCE(s.to_teacher_id, a.teacher_id)
				) x
				JOIN teachers t ON t.id=x.teacher_id
				ORDER BY x.duty_cnt DESC, t.teacher_code, t.first_name, t.last_name');
			$sumStmt->execute([$year_id]);
			$sumRows = $sumStmt->fetchAll();
		?>

		<div class="bg-white rounded-2xl shadow p-4 mb-4">
			<div class="font-medium mb-1">รายงานสรุปเวรวันหยุด</div>
			<div class="text-xs text-slate-500">นับ “คนที่มาปฏิบัติจริง” (ถ้ามีแทนเวร จะนับเป็นผู้แทน)</div>
		</div>

		<div class="bg-white rounded-2xl shadow overflow-hidden mb-4">
			<div class="px-4 py-3 border-b font-medium">สรุปจำนวนเวรต่อครู</div>
			<div class="overflow-x-auto">
				<table class="min-w-full text-sm">
					<thead class="bg-slate-50">
						<tr>
							<th class="text-left px-3 py-2">ครู</th>
							<th class="text-right px-3 py-2">จำนวนเวร</th>
						</tr>
					</thead>
					<tbody>
						<?php if (!$sumRows): ?>
							<tr><td colspan="2" class="px-3 py-6 text-center text-slate-500">ยังไม่มีข้อมูลลงเวร</td></tr>
						<?php endif; ?>
						<?php foreach ($sumRows as $r): ?>
							<tr class="border-t">
								<td class="px-3 py-2"><?= htmlspecialchars(($r['teacher_code'] ?? '').' '.$r['first_name'].' '.$r['last_name']) ?></td>
								<td class="px-3 py-2 text-right"><?= (int)$r['duty_cnt'] ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="bg-white rounded-2xl shadow overflow-hidden">
			<div class="px-4 py-3 border-b font-medium">รายละเอียดรายวัน</div>
			<div class="p-4 space-y-4">
				<?php if (!$dates): ?>
					<div class="text-slate-500 text-center">ยังไม่มีวันเวร</div>
				<?php endif; ?>
				<?php foreach ($dates as $d0): ?>
					<?php $dateKey = (string)$d0['duty_date']; $d = $report[$dateKey] ?? ['note'=>$d0['note'] ?? null, 'posts'=>[]]; ?>
					<div class="border rounded-2xl p-4">
						<div class="flex items-baseline justify-between gap-3">
							<div class="font-medium"><?= htmlspecialchars(tt_date_be($dateKey)) ?></div>
							<div class="text-xs text-slate-500"><?= htmlspecialchars((string)($d['note'] ?? '')) ?></div>
						</div>
						<div class="mt-3 space-y-2">
								<?php if (!$activePosts): ?>
									<div class="text-slate-500">ยังไม่มีการตั้งค่า “จุดเวร”</div>
								<?php endif; ?>
								<?php foreach (($d['posts'] ?? []) as $postId => $p): ?>
									<?php $items = $p['items'] ?? []; $got = count($items); $req = (int)($p['required'] ?? 0); $pname = (string)($p['name'] ?? ''); ?>
								<div class="rounded-xl bg-slate-50 border p-3">
									<div class="flex items-baseline justify-between">
										<div class="font-medium"><?= htmlspecialchars((string)$pname) ?></div>
										<div class="text-xs text-slate-500"><?= $req > 0 ? ('ครบ/ต้องการ: '.$got.'/'.$req) : ('จำนวน: '.$got) ?></div>
									</div>
									<div class="mt-2 flex flex-wrap gap-2">
										<?php if (!$items): ?>
											<div class="text-slate-500">—</div>
										<?php endif; ?>
										<?php foreach ($items as $it): ?>
											<?php
												$orig = ($it['teacher_code'] ?? '').' '.$it['first_name'].' '.$it['last_name'];
												$hasSub = !empty($it['to_teacher_id']);
												$sub = ($it['sub_code'] ?? '').' '.$it['sub_first'].' '.$it['sub_last'];
												$label = $hasSub ? ($sub.' แทน '.$orig) : $orig;
											?>
											<span class="px-2 py-1 rounded bg-white border text-sm"><?= htmlspecialchars(trim((string)$label)) ?></span>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="h-10"></div>
</div>

<script>
	(function() {
		function pad2(n) { return (n < 10 ? '0' : '') + n; }
		function adToBe(ymd) {
			if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return '';
			var y = parseInt(ymd.slice(0,4), 10);
			var m = parseInt(ymd.slice(5,7), 10);
			var d = parseInt(ymd.slice(8,10), 10);
			return pad2(d) + '/' + pad2(m) + '/' + (y + 543);
		}
		function beToAd(be) {
			if (!be) return '';
			var m = be.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
			if (!m) return '';
			var d = parseInt(m[1], 10);
			var mo = parseInt(m[2], 10);
			var ybe = parseInt(m[3], 10);
			var yad = ybe - 543;
			if (yad < 1900 || yad > 2500) return '';
			return yad + '-' + pad2(mo) + '-' + pad2(d);
		}

		// Dates tab inputs
		var beInput = document.getElementById('ttDutyDateBe');
		var adHidden = document.getElementById('ttDutyDateAd');
		var picker = document.getElementById('ttDutyDatePicker');
		if (beInput && adHidden) {
			beInput.addEventListener('input', function() {
				var ad = beToAd(beInput.value);
				adHidden.value = ad;
				if (picker && ad) picker.value = ad;
			});
		}
		if (picker && beInput && adHidden) {
			picker.addEventListener('change', function() {
				var ymd = picker.value;
				adHidden.value = ymd;
				beInput.value = adToBe(ymd);
			});
		}

		// Team member cards
		function renderTeamSelected(teamId) {
			var selectedWrap = document.getElementById('ttTeamSelected-' + teamId);
			if (!selectedWrap) return;
			selectedWrap.innerHTML = '';
			var cbs = document.querySelectorAll('input[type="checkbox"][data-tt-team="' + teamId + '"]');
			var any = false;
			cbs.forEach(function(cb) {
				if (!cb.checked) return;
				any = true;
				var name = cb.getAttribute('data-tt-name') || ('ID ' + cb.value);
				var card = document.createElement('div');
				card.className = 'flex items-center gap-2 px-3 py-2 rounded-2xl bg-slate-50 border';
				var txt = document.createElement('div');
				txt.className = 'text-sm';
				txt.textContent = name;
				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'text-rose-600 text-sm';
				del.textContent = 'ลบ';
				del.addEventListener('click', function() {
					cb.checked = false;
					renderTeamSelected(teamId);
				});
				card.appendChild(txt);
				card.appendChild(del);
				selectedWrap.appendChild(card);
			});
			if (!any) {
				var empty = document.createElement('div');
				empty.className = 'text-xs text-slate-500';
				empty.textContent = 'ยังไม่ได้เลือกสมาชิกทีม';
				selectedWrap.appendChild(empty);
			}
		}

		var teamInputs = document.querySelectorAll('input[type="checkbox"][data-tt-team]');
		var seen = {};
		teamInputs.forEach(function(cb) {
			var teamId = cb.getAttribute('data-tt-team');
			if (!teamId) return;
			cb.addEventListener('change', function() { renderTeamSelected(teamId); });
			seen[teamId] = true;
		});
		Object.keys(seen).forEach(function(teamId) { renderTeamSelected(teamId); });
	})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
