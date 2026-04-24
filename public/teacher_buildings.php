<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

tt_buildings_init($pdo);

$err = '';

// Load buildings (include inactive so existing mappings don't get lost)
$buildings = tt_buildings_list($pdo, false);
$hasBuildings = !empty($buildings);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } else {
    try {
      $pdo->beginTransaction();

      $buildingIdsByTeacher = (array)($_POST['building_ids'] ?? []); // teacher_id => [building_id...]

      $teachers = $pdo->query('SELECT id, teacher_code FROM teachers ORDER BY teacher_code')->fetchAll(PDO::FETCH_ASSOC);
      foreach ($teachers as $t) {
        $tid = (int)$t['id'];
        $tidKey = (string)$tid;
        $raw = $buildingIdsByTeacher[$tidKey] ?? $buildingIdsByTeacher[$tid] ?? [];
        if (!is_array($raw)) $raw = [$raw];
        $sel = array_values(array_unique(array_filter(array_map('intval', $raw), fn($v) => $v > 0)));

        if (count($sel) > 2) {
          throw new Exception('ครูรหัส '.$t['teacher_code'].' เลือกอาคารเกิน 2 อาคาร');
        }

        tt_teacher_buildings_set($pdo, $tid, $sel);
      }

      $pdo->commit();
      flash_set('success', 'บันทึกอาคารของครูเรียบร้อย');
      redirect('teacher_buildings.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $e2) { /* ignore */ }
      }
      $err = 'ผิดพลาด: ' . $e->getMessage();
    }
  }
}

// Teachers
$teachers = $pdo->query('SELECT id, teacher_code, first_name, last_name FROM teachers ORDER BY teacher_code, first_name, last_name')->fetchAll(PDO::FETCH_ASSOC);

// Mappings (bulk)
$tb = [];
try {
  $rows = $pdo->query('SELECT teacher_id, building_id FROM teacher_buildings')->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $tid = (int)$r['teacher_id'];
    $bid = (int)$r['building_id'];
    if (!isset($tb[$tid])) $tb[$tid] = [];
    $tb[$tid][] = $bid;
  }
} catch (Throwable $e) {
  // ignore
}

$flash = flash_get();

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>

<div class="max-w-6xl mx-auto px-4 mt-8">
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">กำหนดครูประจำอาคาร (เวร)</h1>
      <p class="text-sm text-slate-500 mt-1">เลือกได้สูงสุด 2 อาคารต่อครู (ใช้ในการกรองตอนจัดเวร)</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <?php if (!$hasBuildings): ?>
    <div class="mb-4 p-4 rounded-2xl border bg-amber-50 text-amber-800">
      ยังไม่มีอาคารในระบบ — กรุณาไปสร้างที่หน้า <a class="underline" href="<?= url('buildings.php'); ?>">อาคาร</a>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b flex items-center justify-between gap-3">
      <div class="font-semibold">รายการครู</div>
      <div class="text-xs text-slate-500">ติ๊กได้สูงสุด 2 อาคารต่อครู · บันทึกเป็นชุด</div>
    </div>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left px-3 py-2">ครู</th>
              <th class="text-left px-3 py-2">อาคาร (เลือกได้ไม่เกิน 2)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($teachers as $t): ?>
              <?php
                $tid = (int)$t['id'];
                $cur = array_values(array_unique(array_map('intval', $tb[$tid] ?? [])));
                $name = trim(($t['first_name'] ?? '').' '.($t['last_name'] ?? ''));
              ?>
              <tr class="border-t">
                <td class="px-3 py-2">
                  <div class="font-medium"><?= htmlspecialchars((string)$t['teacher_code']); ?> <?= htmlspecialchars($name); ?></div>
                </td>

                <td class="px-3 py-2">
                  <?php if (!$hasBuildings): ?>
                    <div class="text-xs text-slate-400">—</div>
                  <?php else: ?>
                    <div class="flex flex-wrap gap-2" data-tt-building-row="1" data-teacher-id="<?= $tid; ?>">
                      <?php foreach ($buildings as $b): ?>
                        <?php
                          $bid = (int)$b['id'];
                          $label = (string)$b['building_name'];
                          if (empty($b['is_active'])) $label .= ' (ปิดใช้งาน)';
                          $checked = in_array($bid, $cur, true);
                        ?>
                        <label class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border bg-white hover:bg-slate-50 cursor-pointer select-none">
                          <input
                            type="checkbox"
                            name="building_ids[<?= $tid; ?>][]"
                            value="<?= $bid; ?>"
                            <?= $checked ? 'checked' : ''; ?>
                            class="tt-bld-cb h-4 w-4"
                          >
                          <span class="text-sm"><?= htmlspecialchars($label); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="px-4 py-4 border-t flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
        <div class="text-xs text-slate-500">
          ถ้าติ๊กเกิน 2 อาคาร ระบบจะไม่ให้เลือกเพิ่ม
        </div>
        <button class="px-5 py-2.5 rounded-xl font-semibold text-white bg-slate-900 hover:opacity-90" <?= $hasBuildings ? '' : 'disabled' ?>>
          บันทึกทั้งหมด
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  (function(){
    const rows = Array.from(document.querySelectorAll('[data-tt-building-row]'));
    rows.forEach(row => {
      const cbs = Array.from(row.querySelectorAll('input.tt-bld-cb'));
      cbs.forEach(cb => {
        cb.addEventListener('change', () => {
          const checked = cbs.filter(x => x.checked);
          if (checked.length > 2) {
            cb.checked = false;

            if (typeof window.ttAlert === 'function') {
              window.ttAlert({ icon: 'warning', title: 'แจ้งเตือน', text: 'เลือกได้สูงสุด 2 อาคารต่อครู' });
            }
          }
        });
      });
    });
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
