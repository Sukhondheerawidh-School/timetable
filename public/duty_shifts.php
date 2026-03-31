<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin(); requireAdmin();

tt_duty_init($pdo);

// Buildings (context/filter)
$buildings = tt_buildings_list($pdo, true);
$building_id_param = $_GET['building_id'] ?? null;
$building_id = $building_id_param === null ? 0 : (int)$building_id_param;
if ($building_id_param === null && !empty($buildings)) {
  // default to first building when user hasn't chosen yet
  $building_id = (int)$buildings[0]['id'];
}

// Master data: ensure master slots exist from periods
tt_duty_master_sync_from_periods($pdo);

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    $action = $_POST['action'] ?? '';
    $building_id = (int)($_POST['building_id'] ?? $building_id);
    try {
      if ($action === 'create_range') {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $dayInput = $_POST['day_of_week'] ?? 0;
        $days = is_array($dayInput) ? array_map('intval', $dayInput) : [(int)$dayInput];
        $days = array_values(array_unique(array_filter($days, fn($d) => $d >= 1 && $d <= 7)));
        $slot_from = (int)($_POST['slot_from'] ?? 0);
        $slot_to = (int)($_POST['slot_to'] ?? 0);
        $required = max(1, (int)($_POST['required_count'] ?? 1));

        if (!$post_id) throw new Exception('กรุณาเลือกเวร/จุด');
        if (!$days) throw new Exception('กรุณาเลือกวัน');
        if (!$slot_from || !$slot_to) throw new Exception('กรุณาเลือกช่วงเวลา');

        // If a building is selected, validate the post belongs to that building
        if ($building_id > 0) {
          $chk = $pdo->prepare('SELECT building_id FROM duty_master_posts WHERE id=? LIMIT 1');
          $chk->execute([$post_id]);
          $pb = $chk->fetchColumn();
          if ($pb === false) throw new Exception('ไม่พบเวร/จุด');
          if ($pb === null) throw new Exception('เวร/จุดนี้ยังไม่ได้กำหนดอาคาร');
          if ((int)$pb !== $building_id) throw new Exception('เวร/จุดนี้ไม่อยู่ในอาคารที่เลือก');
        }

        $slotMeta = $pdo->prepare('SELECT id, sort_order FROM duty_master_time_slots WHERE id IN (?,?)');
        $slotMeta->execute([$slot_from, $slot_to]);
        $rows = $slotMeta->fetchAll(PDO::FETCH_ASSOC);
        $sortMap = [];
        foreach ($rows as $r) {
          $sortMap[(int)$r['id']] = (int)$r['sort_order'];
        }

        // Allow selecting a single slot (from == to)
        if ($slot_from === $slot_to) {
          if (!isset($sortMap[$slot_from])) throw new Exception('ไม่พบช่วงเวลา');
          $sortFrom = $sortMap[$slot_from];
          $sortTo = $sortFrom;
        } else {
          if (!isset($sortMap[$slot_from]) || !isset($sortMap[$slot_to])) throw new Exception('ไม่พบช่วงเวลา');
          $sortFrom = $sortMap[$slot_from];
          $sortTo = $sortMap[$slot_to];
        }

        $lo = min($sortFrom, $sortTo);
        $hi = max($sortFrom, $sortTo);

        $slotsStmt = $pdo->prepare('SELECT id FROM duty_master_time_slots WHERE is_active=1 AND sort_order BETWEEN ? AND ? ORDER BY sort_order');
        $slotsStmt->execute([$lo, $hi]);
        $slotIds = $slotsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$slotIds) throw new Exception('ไม่มีช่วงเวลาเวรที่เปิดใช้งานในช่วงที่เลือก');

        $ins = $pdo->prepare('INSERT INTO duty_master_shifts(day_of_week, duty_time_slot_id, duty_post_id, required_count, is_active) VALUES (?,?,?,?,1)');
        $created = 0;
        foreach ($days as $day) {
          foreach ($slotIds as $sid) {
            try {
              $ins->execute([(int)$day, (int)$sid, $post_id, $required]);
              $created++;
            } catch (Throwable $e) {
              // ignore duplicates (unique key)
            }
          }
        }

        logActivity('duty_master_shift_create_range', 'duty_master_shifts', null, null, [
          'building_id' => $building_id,
          'duty_post_id' => $post_id,
          'day_of_week' => $days,
          'slot_ids' => array_map('intval', $slotIds),
          'required_count' => $required,
          'created_count' => $created,
        ]);

        flash_set('success', 'สร้างเวรแล้ว ('.$created.' รายการ)');
        redirect('duty_shifts.php'.($building_id>0 ? ('?building_id='.$building_id) : ''));
      } elseif ($action === 'update_required') {
        $id = (int)($_POST['id'] ?? 0);
        $required = (int)($_POST['required_count'] ?? 1);
        $required = max(1, min(20, $required));

        if (!$id) throw new Exception('ไม่พบรายการเวร');

        $oldStmt = $pdo->prepare(
          'SELECT ms.id, ms.required_count, dp.building_id '
          .'FROM duty_master_shifts ms '
          .'JOIN duty_master_posts dp ON dp.id=ms.duty_post_id '
          .'WHERE ms.id=? '
          .'LIMIT 1'
        );
        $oldStmt->execute([$id]);
        $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) throw new Exception('ไม่พบเวร');

        if ($building_id > 0 && (int)$oldRow['building_id'] !== $building_id) {
          throw new Exception('เวรนี้ไม่อยู่ในอาคารที่เลือก');
        }

        $oldRequired = (int)$oldRow['required_count'];
        if ($required < $oldRequired) {
          $maxStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(t.cnt),0) '
            .'FROM ( '
            .'  SELECT COUNT(*) AS cnt '
            .'  FROM duty_term_assignments '
            .'  WHERE duty_master_shift_id=? '
            .'  GROUP BY academic_year_id, term_no '
            .') t'
          );
          $maxStmt->execute([$id]);
          $maxAssigned = (int)$maxStmt->fetchColumn();
          if ($maxAssigned > $required) {
            throw new Exception('ลดจำนวนไม่ได้: มีการจัดครูแล้วสูงสุด '.$maxAssigned.' คนในบางเทอม (โปรดถอนการจัดครูก่อน)');
          }
        }

        if ($required !== $oldRequired) {
          $upd = $pdo->prepare('UPDATE duty_master_shifts SET required_count=? WHERE id=?');
          $upd->execute([$required, $id]);
          logUpdate('duty_master_shifts', $id, ['required_count' => $oldRequired], ['required_count' => $required]);
        }

        flash_set('success', 'บันทึกจำนวนครูแล้ว');
        redirect('duty_shifts.php'.($building_id>0 ? ('?building_id='.$building_id) : ''));
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $oldStmt = $pdo->prepare('SELECT * FROM duty_master_shifts WHERE id=?');
        $oldStmt->execute([$id]);
        $oldRow = $oldStmt->fetch();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM duty_term_assignments WHERE duty_master_shift_id=?');
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) throw new Exception('ลบไม่ได้: มีการจัดครูลงเวรนี้ในบางเทอมแล้ว');
        $del = $pdo->prepare('DELETE FROM duty_master_shifts WHERE id=?');
        $del->execute([$id]);

        if ($oldRow) {
          logDelete('duty_master_shifts', $id, $oldRow);
        }
        flash_set('success', 'ลบเวรแล้ว');
        redirect('duty_shifts.php'.($building_id>0 ? ('?building_id='.$building_id) : ''));
      } elseif ($action === 'delete_all') {
        // Destructive: remove master shifts (and cascades term assignments via FK)
        $shiftCount = 0;
        $asCount = 0;

        if ($building_id > 0) {
          $c1 = $pdo->prepare('SELECT COUNT(*)
            FROM duty_master_shifts ms
            JOIN duty_master_posts dp ON dp.id=ms.duty_post_id
            WHERE dp.building_id=?');
          $c1->execute([$building_id]);
          $shiftCount = (int)$c1->fetchColumn();

          $c2 = $pdo->prepare('SELECT COUNT(*)
            FROM duty_term_assignments ta
            JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
            JOIN duty_master_posts dp ON dp.id=ms.duty_post_id
            WHERE dp.building_id=?');
          $c2->execute([$building_id]);
          $asCount = (int)$c2->fetchColumn();

          $pdo->beginTransaction();
          $pdo->prepare('DELETE ta
            FROM duty_term_assignments ta
            JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
            JOIN duty_master_posts dp ON dp.id=ms.duty_post_id
            WHERE dp.building_id=?')->execute([$building_id]);
          $pdo->prepare('DELETE ms
            FROM duty_master_shifts ms
            JOIN duty_master_posts dp ON dp.id=ms.duty_post_id
            WHERE dp.building_id=?')->execute([$building_id]);
          $pdo->commit();

          flash_set('success', 'ลบเวรทั้งหมดของอาคารนี้แล้ว (เวร '.$shiftCount.' รายการ, การจัดครู '.$asCount.' รายการ)');
          redirect('duty_shifts.php?building_id='.$building_id);
        }

        $c1 = $pdo->query('SELECT COUNT(*) FROM duty_master_shifts');
        $shiftCount = (int)$c1->fetchColumn();
        $c2 = $pdo->query('SELECT COUNT(*) FROM duty_term_assignments');
        $asCount = (int)$c2->fetchColumn();

        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM duty_term_assignments');
        $pdo->exec('DELETE FROM duty_master_shifts');
        $pdo->commit();

        flash_set('success', 'ลบเวรทั้งหมดแล้ว (เวร '.$shiftCount.' รายการ, การจัดครู '.$asCount.' รายการ)');
        redirect('duty_shifts.php');
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $e2) { /* ignore */ }
      }
      $err = 'ผิดพลาด: '.$e->getMessage();
    }
  }
}

$postsSql = 'SELECT id, post_name, building_id FROM duty_master_posts WHERE is_active=1';
if ($building_id > 0) {
  // strict: show posts only for selected building
  $postsSql .= ' AND building_id = ?';
}
$postsSql .= ' ORDER BY sort_order, post_name';
$postsStmt = $pdo->prepare($postsSql);
$postsStmt->execute($building_id > 0 ? [$building_id] : []);
$posts = $postsStmt->fetchAll();
$activePostsCount = count($posts);

$slotsStmt = $pdo->prepare('SELECT id, slot_label, start_time, end_time, is_active FROM duty_master_time_slots ORDER BY sort_order');
$slotsStmt->execute();
$slots = $slotsStmt->fetchAll();
$hasAnySlot = !empty($slots);
$hasAnyActiveSlot = false;
$activeSlotsCount = 0;
foreach ($slots as $s) {
  if (!empty($s['is_active'])) { $hasAnyActiveSlot = true; $activeSlotsCount++; }
}
$createDisabled = (!$hasAnySlot || !$hasAnyActiveSlot || empty($posts));

$shiftsSql = 'SELECT ms.id, ms.day_of_week, ms.required_count, dts.slot_label, dts.start_time, dts.end_time,
    dp.post_name, dp.building_id,
    b.building_name
  FROM duty_master_shifts ms
  JOIN duty_master_time_slots dts ON dts.id=ms.duty_time_slot_id
  JOIN duty_master_posts dp ON dp.id=ms.duty_post_id
  LEFT JOIN duty_buildings b ON b.id=dp.building_id
  ';
if ($building_id > 0) {
  $shiftsSql .= ' WHERE dp.building_id = ?';
}
$shiftsSql .= ' ORDER BY ms.day_of_week, dts.sort_order, dp.post_name';
$shiftsStmt = $pdo->prepare($shiftsSql);
$shiftsStmt->execute($building_id > 0 ? [$building_id] : []);
$shifts = $shiftsStmt->fetchAll();

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>
<div class="max-w-6xl mx-auto px-4 mt-8">
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">กำหนดเวร (Master)</h1>
      <p class="text-sm text-slate-500 mt-1">ตั้งค่า “จุด × วัน × ช่วงเวลา” เป็นแม่แบบ ใช้ร่วมทุกเทอม</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border bg-white text-sm">
        <span class="text-slate-500">จุดเวร</span>
        <span class="font-semibold text-slate-900"><?= number_format($activePostsCount); ?></span>
      </span>
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border bg-white text-sm">
        <span class="text-slate-500">ช่วงเวลา (เปิดใช้)</span>
        <span class="font-semibold text-slate-900"><?= number_format($activeSlotsCount); ?></span>
      </span>
      <?php if ($createDisabled): ?>
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border bg-amber-50 text-amber-800 text-sm border-amber-200">ยังไม่พร้อมสร้างเวร</span>
      <?php else: ?>
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border bg-emerald-50 text-emerald-800 text-sm border-emerald-200">พร้อมใช้งาน</span>
      <?php endif; ?>
    </div>
  </div>

  <?php $ttDutyActive = 'shifts'; include __DIR__ . '/../partials/duty_tabs.php'; ?>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="flex items-center justify-between gap-3 mb-3">
      <div>
        <div class="text-lg font-semibold">สร้างเวรแบบช่วง</div>
        <div class="text-xs text-slate-500 mt-1">สร้างหลายวันได้ในครั้งเดียว เช่น จันทร์–ศุกร์</div>
      </div>
      <div class="flex flex-col items-end gap-1">
        <div class="flex items-center gap-2">
          <span class="text-xs font-semibold text-slate-500">อาคาร</span>
          <form method="get" class="flex items-center gap-2">
            <select name="building_id" class="border rounded-xl px-3 py-2 bg-white text-sm" onchange="this.form.submit()" <?= empty($buildings) ? 'disabled' : '' ?>>
              <option value="0" <?= $building_id===0?'selected':''; ?>>ทุกอาคาร</option>
              <?php foreach ($buildings as $b): ?>
                <option value="<?= (int)$b['id']; ?>" <?= (int)$b['id']===$building_id?'selected':''; ?>><?= htmlspecialchars((string)$b['building_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="text-xs text-slate-500">
          <a class="underline" href="<?= url('teacher_buildings.php'); ?>">กำหนดครูประจำอาคาร</a>
          <span class="mx-1">·</span>
          <a class="underline" href="<?= url('buildings.php'); ?>">จัดการอาคาร</a>
        </div>
      </div>
    </div>

    <?php if (!$hasAnySlot): ?>
      <div class="mb-3 p-3 rounded-xl border bg-rose-50 text-rose-700 text-sm">
        ยังไม่มี “ช่วงเวลาเวร (Master)” ในระบบ — กรุณาไปตั้งค่าที่หน้า
        <a class="underline" href="<?= url('duty_slots.php') ?>">ช่วงเวลาเวร</a>
      </div>
    <?php elseif (!$hasAnyActiveSlot): ?>
      <div class="mb-3 p-3 rounded-xl border bg-amber-50 text-amber-800 text-sm">
        มีช่วงเวลาแล้ว แต่ยังไม่ได้ “เปิดใช้เวร” ในช่วงเวลาใดเลย — ไปเปิดใช้งานได้ที่หน้า
        <a class="underline" href="<?= url('duty_slots.php') ?>">ช่วงเวลาเวร</a>
      </div>
    <?php endif; ?>

    <form method="post" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="create_range">
      <input type="hidden" name="building_id" value="<?= (int)$building_id; ?>">

      <div class="md:col-span-6">
        <label class="block text-xs mb-1 font-semibold text-slate-600">เวร/จุด</label>
        <select name="post_id" class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
          <option value="">-- เลือก --</option>
          <?php foreach ($posts as $p): ?>
            <option value="<?= (int)$p['id']; ?>"><?= htmlspecialchars($p['post_name']); ?></option>
          <?php endforeach; ?>
        </select>

      </div>

      <div class="md:col-span-3">
        <label class="block text-xs mb-1 font-semibold text-slate-600">จำนวนครู</label>
        <input type="number" name="required_count" class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-200" value="1" min="1" max="20">
      </div>

      <div class="md:col-span-3">
        <label class="block text-xs mb-1 font-semibold text-slate-600">วัน (เลือกได้หลายวัน)</label>
        <div class="border rounded-2xl p-3 bg-slate-50/50">
          <?php
            $dowShort = [
              1 => 'จ',
              2 => 'อ',
              3 => 'พ',
              4 => 'พฤ',
              5 => 'ศ',
              6 => 'ส',
              7 => 'อา',
            ];
          ?>
          <div class="grid grid-cols-7 gap-2">
            <?php for($d=1;$d<=7;$d++): ?>
              <label class="inline-flex">
                <input type="checkbox" class="tt-day-cb peer sr-only" name="day_of_week[]" value="<?= $d ?>" <?= ($d>=1 && $d<=5) ? 'checked' : ''; ?>>
                <span
                  title="<?= htmlspecialchars(tt_dow_label($d)); ?>"
                  class="w-full text-center px-0 py-2 rounded-xl border bg-white text-sm font-semibold cursor-pointer select-none peer-checked:bg-slate-900 peer-checked:text-white peer-checked:border-slate-900 hover:bg-slate-50"
                >
                  <?= htmlspecialchars($dowShort[$d] ?? (string)$d); ?>
                </span>
              </label>
            <?php endfor; ?>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3">
            <button type="button" id="tt-day-weekdays" class="px-2.5 py-1.5 rounded-lg border text-xs hover:bg-white">จ–ศ</button>
            <button type="button" id="tt-day-all" class="px-2.5 py-1.5 rounded-lg border text-xs hover:bg-white">ทุกวัน</button>
            <button type="button" id="tt-day-clear" class="px-2.5 py-1.5 rounded-lg border text-xs hover:bg-white">ล้าง</button>
            <span class="ml-auto text-xs text-slate-500">เลือก <span id="tt-day-count" class="font-semibold text-slate-700">0</span> วัน</span>
          </div>
        </div>
      </div>

      <div class="md:col-span-3">
        <label class="block text-xs mb-1 font-semibold text-slate-600">จาก</label>
        <select name="slot_from" class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
          <?php foreach ($slots as $s): ?>
            <option value="<?= (int)$s['id']; ?>" <?= (int)$s['is_active']? '' : 'disabled'; ?>><?= htmlspecialchars($s['slot_label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="md:col-span-3">
        <label class="block text-xs mb-1 font-semibold text-slate-600">ถึง</label>
        <select name="slot_to" class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
          <?php foreach ($slots as $s): ?>
            <option value="<?= (int)$s['id']; ?>" <?= (int)$s['is_active']? '' : 'disabled'; ?>><?= htmlspecialchars($s['slot_label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="md:col-span-12">
        <button
          class="w-full md:w-auto px-5 py-2.5 rounded-xl font-semibold text-white <?= $createDisabled ? 'bg-slate-400 cursor-not-allowed' : 'bg-slate-900 hover:opacity-90' ?>"
          <?= $createDisabled ? 'disabled' : '' ?>
        >
          สร้างเวร
        </button>
        <?php if ($createDisabled): ?>
          <p class="text-xs text-slate-500 mt-2">ต้องมี “ชื่อเวร/จุด” อย่างน้อย 1 รายการ และต้องมี “ช่วงเวลาเวร” ที่เปิดใช้งาน</p>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="font-semibold">รายการเวร</div>
        <div class="flex flex-col sm:flex-row gap-2">
          <div class="relative">
            <input id="tt-shift-search" type="text" class="w-full sm:w-72 border rounded-xl px-3 py-2 pl-9 text-sm focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="ค้นหาเวร/จุด หรือช่วงเวลา">
            <span class="absolute left-3 top-2.5 text-slate-400">🔎</span>
          </div>
          <select id="tt-shift-day" class="w-full sm:w-44 border rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-200">
            <option value="">ทุกวัน</option>
            <?php for($d=1;$d<=7;$d++): ?>
              <option value="<?= $d ?>"><?= tt_dow_label($d) ?></option>
            <?php endfor; ?>
          </select>

          <form method="post" class="sm:ml-2" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบทั้งหมด', text: 'การลบจะลบ “เวร (Master)” และจะลบ “การจัดครู” ที่อ้างอิงเวรเหล่านี้ในทุกเทอมด้วย\n\nต้องการลบทั้งหมดหรือไม่?', confirmButtonText: 'ลบทั้งหมด' });">
            <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
            <input type="hidden" name="action" value="delete_all">
            <input type="hidden" name="building_id" value="<?= (int)$building_id; ?>">
            <button class="w-full sm:w-auto px-3 py-2 rounded-xl border border-rose-200 text-rose-700 hover:bg-rose-50 text-sm">
              ลบทั้งหมด<?= $building_id>0 ? ' (อาคารนี้)' : '' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2">วัน</th>
            <th class="text-left px-3 py-2">ช่วงเวลา</th>
            <th class="text-left px-3 py-2">อาคาร</th>
            <th class="text-left px-3 py-2">เวร/จุด</th>
            <th class="text-right px-3 py-2">จำนวน</th>
            <th class="text-right px-3 py-2">จัดการ</th>
          </tr>
        </thead>
        <tbody id="tt-shift-tbody">
          <?php foreach ($shifts as $s): ?>
            <?php $timeRange = substr((string)$s['start_time'],0,5).'–'.substr((string)$s['end_time'],0,5); ?>
            <tr class="border-t tt-shift-row" data-day="<?= (int)$s['day_of_week']; ?>" data-search="<?= htmlspecialchars(strtolower($s['post_name'].' '.$timeRange)); ?>">
              <td class="px-3 py-2"><?= tt_dow_label((int)$s['day_of_week']); ?></td>
              <td class="px-3 py-2"><span class="font-medium"><?= htmlspecialchars($timeRange); ?></span></td>
              <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($s['building_name'] ?? '—'); ?></td>
              <td class="px-3 py-2 font-medium"><?= htmlspecialchars($s['post_name']); ?></td>
              <td class="px-3 py-2 text-right">
                <form method="post" class="inline-flex items-center justify-end gap-2">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="update_required">
                  <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                  <input type="hidden" name="building_id" value="<?= (int)$building_id; ?>">
                  <input
                    type="number"
                    name="required_count"
                    value="<?= (int)$s['required_count']; ?>"
                    min="1"
                    max="20"
                    class="w-20 border rounded-lg px-2 py-1 text-right text-sm focus:outline-none focus:ring-2 focus:ring-slate-200"
                    aria-label="จำนวนครู"
                  >
                  <span class="text-xs text-slate-500">คน</span>
                  <button class="px-3 py-1.5 rounded-lg border hover:bg-slate-50">บันทึก</button>
                </form>
              </td>
              <td class="px-3 py-2 text-right">
                <form method="post" onsubmit="return ttConfirmSubmit(this, { title: 'ยืนยันการลบ', text: 'ลบเวรนี้?', confirmButtonText: 'ลบ' });" class="inline">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                  <input type="hidden" name="building_id" value="<?= (int)$building_id; ?>">
                  <button class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50">ลบ</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  (function(){
    // day picker helpers
    const cbs = Array.from(document.querySelectorAll('.tt-day-cb'));
    const btnWeekdays = document.getElementById('tt-day-weekdays');
    const btnAll = document.getElementById('tt-day-all');
    const btnClear = document.getElementById('tt-day-clear');
    const dayCount = document.getElementById('tt-day-count');

    function updateDayCount(){
      if (!dayCount) return;
      const n = cbs.filter(cb => cb.checked).length;
      dayCount.textContent = String(n);
    }

    function setChecked(values){
      const set = new Set(values.map(String));
      cbs.forEach(cb => cb.checked = set.has(cb.value));
      updateDayCount();
    }

    if (cbs.length) {
      cbs.forEach(cb => cb.addEventListener('change', updateDayCount));
      updateDayCount();
      if (btnWeekdays) btnWeekdays.addEventListener('click', () => setChecked([1,2,3,4,5]));
      if (btnAll) btnAll.addEventListener('click', () => setChecked([1,2,3,4,5,6,7]));
      if (btnClear) btnClear.addEventListener('click', () => setChecked([]));
    }

    // list filtering
    const searchEl = document.getElementById('tt-shift-search');
    const dayEl = document.getElementById('tt-shift-day');
    const rows = Array.from(document.querySelectorAll('.tt-shift-row'));
    if (!rows.length) return;

    // persist filters across refresh (per building)
    const buildingId = String(<?= (int)$building_id; ?>);
    const LS_KEY = 'tt:duty_shifts:filter:' + buildingId;

    function loadFilter(){
      try {
        const raw = localStorage.getItem(LS_KEY);
        if (!raw) return null;
        const obj = JSON.parse(raw);
        if (!obj || typeof obj !== 'object') return null;
        return {
          q: typeof obj.q === 'string' ? obj.q : '',
          day: typeof obj.day === 'string' ? obj.day : ''
        };
      } catch (e) {
        return null;
      }
    }

    function saveFilter(){
      try {
        localStorage.setItem(LS_KEY, JSON.stringify({
          q: String(searchEl?.value || ''),
          day: String(dayEl?.value || '')
        }));
      } catch (e) {
        // ignore
      }
    }

    function applyShiftFilter(){
      const q = (searchEl?.value || '').toLowerCase().trim();
      const day = (dayEl?.value || '').trim();
      rows.forEach(r => {
        const rDay = r.getAttribute('data-day') || '';
        const rText = r.getAttribute('data-search') || '';
        const okDay = !day || day === rDay;
        const okText = !q || rText.includes(q);
        r.classList.toggle('hidden', !(okDay && okText));
      });
    }

    // restore previous filter if available
    const saved = loadFilter();
    if (saved) {
      if (searchEl && typeof saved.q === 'string') searchEl.value = saved.q;
      if (dayEl && typeof saved.day === 'string') dayEl.value = saved.day;
    }

    applyShiftFilter();

    if (searchEl) searchEl.addEventListener('input', () => { applyShiftFilter(); saveFilter(); });
    if (dayEl) dayEl.addEventListener('change', () => { applyShiftFilter(); saveFilter(); });
  })();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
