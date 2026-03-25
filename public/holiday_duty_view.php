<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

function shift_check_session_timeout(): void {
  if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed = time() - (int)$_SESSION['LAST_ACTIVITY'];
    if (defined('SESSION_TIMEOUT') && $elapsed > SESSION_TIMEOUT) {
      $_SESSION = [];
      session_destroy();
      session_start();
      $_SESSION['timeout_message'] = 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
      header('Location: ' . url('shift_login'));
      exit;
    }
  }
  $_SESSION['LAST_ACTIVITY'] = time();
}

function shift_require_login(): void {
  shift_check_session_timeout();
  if (empty($_SESSION['user'])) {
    header('Location: ' . url('shift_login'));
    exit;
  }
}

shift_require_login();

tt_holiday_duty_init($pdo);

$pageTitle = 'เวรวันหยุด (ดู)';

$year_id = tt_active_year_id($pdo);
$year_label = null;
if ($year_id > 0) {
  try {
    $ys = $pdo->prepare('SELECT year_label FROM academic_years WHERE id=? LIMIT 1');
    $ys->execute([$year_id]);
    $yl = $ys->fetchColumn();
    $year_label = $yl === false ? null : (string)$yl;
  } catch (Throwable $e) {
    $year_label = null;
  }
}

$dutyDates = [];
if ($year_id > 0) {
  try {
    $ds = $pdo->prepare('SELECT id, duty_date, note FROM holiday_duty_dates WHERE academic_year_id=? ORDER BY duty_date DESC');
    $ds->execute([$year_id]);
    $dutyDates = $ds->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $dutyDates = [];
  }
}

$dateSet = [];
foreach ($dutyDates as $d) {
  $k = (string)($d['duty_date'] ?? '');
  if ($k !== '') $dateSet[$k] = true;
}

$rawDate = trim((string)($_GET['date'] ?? ''));
$selectedDate = '';
if ($rawDate !== '' && isset($dateSet[$rawDate])) {
  $selectedDate = $rawDate;
} else {
  $today = date('Y-m-d');
  if (isset($dateSet[$today])) {
    $selectedDate = $today;
  } elseif (!empty($dutyDates)) {
    $selectedDate = (string)$dutyDates[0]['duty_date'];
  } else {
    $selectedDate = $today;
  }
}

$info = null;
$rows = [];
$groups = [];

if ($year_id > 0 && $selectedDate !== '' && isset($dateSet[$selectedDate])) {
  $infoStmt = $pdo->prepare('SELECT id, duty_date, note FROM holiday_duty_dates WHERE academic_year_id=? AND duty_date=? LIMIT 1');
  $infoStmt->execute([$year_id, $selectedDate]);
  $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($info) {
    $sql = 'SELECT
        p.post_name,
        p.sort_order,
        a.id AS assignment_id,
        a.teacher_id,
        a.team_id,
        tm.team_name,
        t.teacher_code,
        t.first_name,
        t.last_name,
        s.from_teacher_id,
        s.to_teacher_id,
        s.reason,
        tf.teacher_code AS from_teacher_code,
        tf.first_name AS from_first_name,
        tf.last_name AS from_last_name,
        tt.teacher_code AS to_teacher_code,
        tt.first_name AS to_first_name,
        tt.last_name AS to_last_name
      FROM holiday_duty_assignments a
      JOIN holiday_duty_posts p ON p.id=a.holiday_duty_post_id
      JOIN teachers t ON t.id=a.teacher_id
      LEFT JOIN holiday_duty_teams tm ON tm.id=a.team_id
      LEFT JOIN holiday_duty_substitutions s ON s.assignment_id=a.id
      LEFT JOIN teachers tf ON tf.id=s.from_teacher_id
      LEFT JOIN teachers tt ON tt.id=s.to_teacher_id
      WHERE a.holiday_duty_date_id=?
      ORDER BY p.sort_order, p.post_name, t.teacher_code, t.first_name, t.last_name';
    $st = $pdo->prepare($sql);
    $st->execute([(int)$info['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $postName = (string)($r['post_name'] ?? '');
      if ($postName === '') $postName = '—';
      if (!isset($groups[$postName])) {
        $groups[$postName] = [
          'sort_order' => (int)($r['sort_order'] ?? 0),
          'items' => [],
        ];
      }
      $groups[$postName]['items'][] = $r;
    }
    if ($groups) {
      uasort($groups, function ($a, $b) {
        return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
      });
    }
  }
}

function tt_teacher_label(?string $code, ?string $first, ?string $last): string {
  $code = trim((string)$code);
  $name = trim(trim((string)$first) . ' ' . trim((string)$last));
  if ($code !== '') {
    return '[' . $code . '] ' . $name;
  }
  return $name !== '' ? $name : '—';
}

include __DIR__ . '/../partials/head.php';
?>

<div class="max-w-5xl mx-auto px-4 py-8">
  <div class="rounded-3xl shadow border border-slate-100 overflow-hidden mb-5">
    <div class="bg-gradient-to-r from-primary to-primary-dark px-6 py-5 text-white">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-semibold">🗓️ เวรวันหยุด</h1>
          <div class="text-sm text-indigo-100 mt-1">ดูรายชื่อเวรวันหยุด และรายการแทนเวร</div>
          <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 border border-white/20">
              🎓 ปีการศึกษา: <?= $year_label ? htmlspecialchars($year_label) : ($year_id > 0 ? (int)$year_id : '—'); ?>
            </span>
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 border border-white/20">
              📅 วันที่: <?= htmlspecialchars(th_date($selectedDate)); ?>
            </span>
          </div>
        </div>

        <div class="shrink-0">
          <a class="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-white/10 border border-white/20 hover:bg-white/15 text-sm" href="<?= url('shift_logout'); ?>">🚪 ออกจากระบบ</a>
        </div>
      </div>
    </div>
  </div>

  <form method="get" class="bg-white rounded-3xl shadow border border-slate-100 p-5 mb-5">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
      <div class="md:col-span-7">
        <label class="block text-xs font-semibold text-slate-600 mb-2">🧭 เลือกวันที่ (จากรายการวันเวรที่สร้างไว้)</label>
        <select name="date" class="w-full border border-slate-200 rounded-2xl px-4 py-3 bg-white" <?= empty($dutyDates) ? 'disabled' : ''; ?>>
          <?php if (empty($dutyDates)): ?>
            <option value="">— ยังไม่มีวันเวร —</option>
          <?php else: ?>
            <?php foreach ($dutyDates as $d): ?>
              <?php
                $dkey = (string)$d['duty_date'];
                $lbl = th_date($dkey);
                $note = trim((string)($d['note'] ?? ''));
                if ($note !== '') $lbl .= ' · ' . $note;
              ?>
              <option value="<?= htmlspecialchars($dkey); ?>" <?= $dkey === $selectedDate ? 'selected' : ''; ?>><?= htmlspecialchars($lbl); ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>

      <div class="md:col-span-5 flex items-end gap-2">
        <button class="px-5 py-3 rounded-2xl bg-slate-900 text-white hover:bg-slate-800 text-sm font-semibold" type="submit">🔎 ดูข้อมูล</button>
        <a class="px-5 py-3 rounded-2xl border bg-white hover:bg-slate-50 text-sm font-semibold" href="<?= url('shift_view'); ?>">✨ ค่าเริ่มต้น</a>
      </div>
    </div>
  </form>

  <div class="bg-white rounded-3xl shadow border border-slate-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
      <div class="flex items-center justify-between gap-3">
        <div class="font-semibold text-slate-900">📌 รายละเอียดเวร</div>
        <?php if ($info && !empty($info['note'])): ?>
          <div class="text-xs text-slate-500">📝 <?= htmlspecialchars((string)$info['note']); ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($year_id <= 0): ?>
      <div class="p-6 text-sm text-rose-700 bg-rose-50">ยังไม่มีข้อมูลปีการศึกษาในระบบ</div>
    <?php elseif (empty($dutyDates)): ?>
      <div class="p-6">
        <div class="text-slate-900 font-semibold">ยังไม่มีรายการวันเวร</div>
        <div class="text-sm text-slate-500 mt-1">ให้ผู้ดูแลระบบเพิ่ม “วันเวรวันหยุด” ก่อน แล้วหน้านี้จะแสดงให้เลือก</div>
      </div>
    <?php elseif (!$info): ?>
      <div class="p-6">
        <div class="text-slate-900 font-semibold">ไม่มีการกำหนดเวรในวันที่นี้</div>
        <div class="text-sm text-slate-500 mt-1">ลองเลือกวันอื่นจากรายการด้านบน</div>
      </div>
    <?php elseif (!$rows): ?>
      <div class="p-6">
        <div class="text-slate-900 font-semibold">ยังไม่มีการลงเวร</div>
        <div class="text-sm text-slate-500 mt-1">มีวันที่เวรแล้ว แต่ยังไม่มีรายชื่อผู้รับเวรในระบบ</div>
      </div>
    <?php else: ?>
      <div class="p-6 space-y-4">
        <?php foreach ($groups as $postName => $g): ?>
          <?php $items = $g['items'] ?? []; ?>
          <div class="rounded-2xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 bg-gradient-to-r from-slate-50 to-indigo-50 border-b border-slate-200 flex items-center justify-between gap-3">
              <div class="font-semibold text-slate-900">📍 <?= htmlspecialchars((string)$postName); ?></div>
              <div class="text-xs text-slate-600">👥 <?= number_format(is_array($items) ? count($items) : 0); ?> รายการ</div>
            </div>

            <div class="divide-y divide-slate-100">
              <?php foreach ($items as $r): ?>
                <?php
                  $teacher = tt_teacher_label($r['teacher_code'] ?? '', $r['first_name'] ?? '', $r['last_name'] ?? '');
                  $team = trim((string)($r['team_name'] ?? ''));

                  $hasSub = !empty($r['to_teacher_id']);
                  $subText = '';
                  if ($hasSub) {
                    $from = tt_teacher_label($r['from_teacher_code'] ?? '', $r['from_first_name'] ?? '', $r['from_last_name'] ?? '');
                    $to = tt_teacher_label($r['to_teacher_code'] ?? '', $r['to_first_name'] ?? '', $r['to_last_name'] ?? '');
                    $subText = $from . ' → ' . $to;
                    $reason = trim((string)($r['reason'] ?? ''));
                    if ($reason !== '') $subText .= ' (' . $reason . ')';
                  }

                  $code = trim((string)($r['teacher_code'] ?? ''));
                  $avatar = $code !== '' ? strtoupper(substr($code, 0, 2)) : '👤';
                ?>
                <div class="px-4 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                  <div class="flex items-start gap-3">
                    <div class="h-10 w-10 rounded-2xl bg-slate-900 text-white flex items-center justify-center text-xs font-semibold shadow-sm">
                      <?= htmlspecialchars($avatar); ?>
                    </div>
                    <div>
                      <div class="font-semibold text-slate-900"><?= htmlspecialchars($teacher); ?></div>
                      <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        <?php if ($team !== ''): ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full bg-violet-50 border border-violet-200 text-violet-800">👫 ทีม: <?= htmlspecialchars($team); ?></span>
                        <?php endif; ?>
                        <?php if ($hasSub): ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full bg-amber-50 border border-amber-200 text-amber-900">🔁 มีการแทนเวร</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-900">✅ ปกติ</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <div class="md:text-right">
                    <?php if ($hasSub): ?>
                      <div class="text-sm font-semibold text-slate-900">🧑‍🏫 <?= htmlspecialchars($subText); ?></div>
                      <div class="text-xs text-slate-500 mt-1">📌 ผู้รับเวรในตาราง: <?= htmlspecialchars($teacher); ?></div>
                    <?php else: ?>
                      <div class="text-sm text-slate-500">—</div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
