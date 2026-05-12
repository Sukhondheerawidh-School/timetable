<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';

requireSuperuser();

$flash = null;

// section definitions: key => [icon, label, pages]
$sections = [
    'timetable'  => ['icon'=>'📝', 'label'=>'จัดตารางสอน',  'pages'=>'timetable · co-teaching · จัดอัตโนมัติ'],
    'loads'      => ['icon'=>'📊', 'label'=>'กำลังสอน',      'pages'=>'loads · เพิ่ม/แก้ไข/ลบ กำลังสอน'],
    'activities' => ['icon'=>'🎯', 'label'=>'กิจกรรม',       'pages'=>'activities · เพิ่ม/แก้ไข/ลบ กิจกรรม'],
    'duty'       => ['icon'=>'🧑‍🏫','label'=>'จัดเวรครู',    'pages'=>'duty_assign · จัดเวรครู'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'CSRF ไม่ถูกต้อง'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'toggle_section') {
            $sec = $_POST['section'] ?? '';
            if (isset($sections[$sec])) {
                $key     = 'lock_' . $sec;
                $current = tt_app_setting_get($pdo, $key, '0');
                $newVal  = ($current === '1') ? '0' : '1';
                tt_app_setting_set($pdo, $key, $newVal);
                $label = $sections[$sec]['label'];
                $stateLabel = $newVal === '1' ? 'ปิดการแก้ไข' : 'เปิดการแก้ไข';
                logActivity('superuser_toggle_section_lock', 'app_settings', null, null, [
                    'section'      => $sec,
                    'lock_key'     => $key,
                    'locked'       => $newVal,
                    'action_label' => "$stateLabel ($label)",
                ]);
                $flash = ['type' => 'success', 'msg' => "$stateLabel ส่วน \"{$label}\" เรียบร้อย"];
            }
        }
    }
}

// โหลดสถานะปัจจุบันของแต่ละ section
$sectionStatus = [];
foreach ($sections as $key => $cfg) {
    $sectionStatus[$key] = tt_app_setting_get($pdo, 'lock_' . $key, '0') === '1';
}

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>

<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    toast: true,
    position: 'top-end',
    icon: <?= $flash['type'] === 'success' ? "'success'" : "'error'" ?>,
    title: <?= json_encode($flash['msg'], JSON_UNESCAPED_UNICODE) ?>,
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
  });
});
</script>
<?php endif; ?>

<div class="max-w-2xl mx-auto px-4 mt-10 pb-16">
  <div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-800">⚙️ Superuser Settings</h1>
    <p class="text-sm text-slate-500 mt-1">ควบคุมการแก้ไขแต่ละส่วนของระบบ — Superuser แก้ไขได้เสมอ</p>
  </div>

  <!-- Section Lock Cards -->
  <div class="space-y-4">
    <?php foreach ($sections as $secKey => $cfg):
      $locked = $sectionStatus[$secKey];
    ?>
    <div class="bg-white rounded-2xl shadow-sm border <?= $locked ? 'border-rose-200' : 'border-slate-100' ?> p-5">
      <div class="flex items-center justify-between gap-4">

        <!-- Left: info -->
        <div class="flex items-center gap-4 min-w-0">
          <div class="flex-shrink-0 w-11 h-11 rounded-xl flex items-center justify-center text-xl
            <?= $locked ? 'bg-rose-50' : 'bg-emerald-50' ?>">
            <?= $cfg['icon'] ?>
          </div>
          <div class="min-w-0">
            <div class="font-semibold text-slate-800"><?= htmlspecialchars($cfg['label']) ?></div>
            <div class="text-xs text-slate-400 truncate"><?= htmlspecialchars($cfg['pages']) ?></div>
          </div>
        </div>

        <!-- Right: status + button -->
        <div class="flex items-center gap-3 flex-shrink-0">
          <!-- Status badge -->
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold
            <?= $locked
              ? 'bg-rose-100 text-rose-700 border border-rose-200'
              : 'bg-emerald-100 text-emerald-700 border border-emerald-200' ?>">
            <span class="w-1.5 h-1.5 rounded-full <?= $locked ? 'bg-rose-500' : 'bg-emerald-500' ?> inline-block"></span>
            <?= $locked ? 'ปิดอยู่' : 'เปิดอยู่' ?>
          </span>

          <!-- Toggle button -->
          <form method="post" class="tt-toggle-form">
            <input type="hidden" name="csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="action"  value="toggle_section">
            <input type="hidden" name="section" value="<?= htmlspecialchars($secKey) ?>">
            <button type="button"
              data-locked="<?= $locked ? '1' : '0' ?>"
              data-label="<?= htmlspecialchars($cfg['label'], ENT_QUOTES) ?>"
              class="tt-toggle-btn px-4 py-2 rounded-xl text-sm font-semibold transition-colors
                <?= $locked
                  ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                  : 'bg-rose-600 hover:bg-rose-700 text-white' ?>">
              <?= $locked ? '🔓 เปิด' : '🔒 ปิด' ?>
            </button>
          </form>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="mt-6 text-xs text-slate-400 text-center">
    Superuser สามารถแก้ไขได้ทุกส่วนเสมอ ไม่ว่าจะล็อกหรือไม่ก็ตาม
  </p>
</div>

<script>
document.querySelectorAll('.tt-toggle-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var locked  = btn.dataset.locked === '1';
    var label   = btn.dataset.label;
    var form    = btn.closest('form');

    Swal.fire({
      icon: locked ? 'question' : 'warning',
      title: locked ? 'เปิดการแก้ไข?' : 'ปิดการแก้ไข?',
      html: locked
        ? 'Admin และ User จะสามารถแก้ไขส่วน <strong>' + label + '</strong> ได้อีกครั้ง'
        : 'Admin และ User จะ<strong>ไม่สามารถแก้ไข</strong>ส่วน <strong>' + label + '</strong> ได้',
      icon: locked ? 'question' : 'warning',
      showCancelButton: true,
      confirmButtonText: locked ? '🔓 เปิดเลย' : '🔒 ปิดเลย',
      cancelButtonText: 'ยกเลิก',
      confirmButtonColor: locked ? '#059669' : '#dc2626',
      cancelButtonColor: '#94a3b8',
      reverseButtons: true,
    }).then(function (result) {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
