<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

$path = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
function active_cls($file, $current) {
  return $file === $current ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100';
}

function group_open(array $files, $current) {
  return in_array($current, $files, true);
}

$user = currentUser();
$isAdmin      = in_array($user['role'] ?? '', ['admin', 'superuser'], true);
$isSuperuser  = ($user['role'] ?? '') === 'superuser';
$editingLocked = isEditingLocked();

// ตรวจ section locks สำหรับ banner
$lockedSectionLabels = [];
foreach (['timetable' => 'ตารางสอน', 'loads' => 'กำลังสอน', 'activities' => 'กิจกรรม', 'duty' => 'จัดเวร'] as $sk => $sl) {
    if (isSectionLocked('lock_' . $sk)) $lockedSectionLabels[] = $sl;
}
$anyLocked = $editingLocked || !empty($lockedSectionLabels);

$settingsFiles = ['teachers.php','subject_groups.php','years.php','periods.php','subjects.php','rooms.php','classes.php','class_weekends.php'];
$systemFiles   = ['activity_logs.php','backup.php'];
$planFiles     = ['activities.php','loads.php','teacher_constraints.php'];
$dutyFiles     = ['duty_assign.php','duty_summary.php','duty_exclusions.php','duty_summary_report.php','duty_slots.php','duty_posts.php','duty_shifts.php','buildings.php','teacher_buildings.php','shift.php'];
$timetableFiles= ['co_teaching.php','timetable.php'];
$reportFiles   = ['report.php'];
$superFiles    = ['superuser_settings.php'];

$openSettings  = $isAdmin && group_open($settingsFiles, $path);
$openSystem    = $isAdmin && group_open($systemFiles, $path);
$openPlan      = group_open($planFiles, $path);
$openTimetable = group_open($timetableFiles, $path);
$openDuty      = $isAdmin && group_open($dutyFiles, $path);
$openReport    = group_open($reportFiles, $path);
$openSuper     = $isSuperuser && group_open($superFiles, $path);
?>

<style>
  details.tt-nav > summary { list-style: none; }
  details.tt-nav > summary::-webkit-details-marker { display: none; }
  details.tt-nav[open] > summary .tt-chevron { transform: rotate(180deg); }

  /* Typography: make group titles clearer, keep submenu a bit smaller */
  .tt-nav-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #334155; /* slate-700 */
  }
  .tt-nav-links .tt-nav-item {
    font-size: 0.875rem;
  }
  .tt-nav-links .tt-nav-item span:first-child {
    font-size: 1.05rem;
    line-height: 1;
  }
  .tt-nav-subtitle {
    font-size: 0.75rem;
    font-weight: 700;
    color: #94a3b8; /* slate-400 */
  }

  /* Keep open groups looking "selected" even after navigation */
  details.tt-nav[open] > summary {
    background: #f8fafc; /* slate-50 */
    box-shadow: inset 0 0 0 1px #cbd5e1; /* slate-300 */
  }
  details.tt-nav > summary:focus { outline: none; }
  details.tt-nav > summary:focus-visible {
    outline: none;
    box-shadow: inset 0 0 0 1px #cbd5e1, 0 0 0 3px rgba(15, 23, 42, 0.15);
  }
</style>
<nav class="min-h-screen flex">
  <aside class="hidden md:flex w-64 flex-col border-r border-slate-200 bg-white sticky top-0 h-screen self-start shrink-0">
    <div class="h-16 flex items-center px-4 border-b">
      <a href="<?= url('index.php'); ?>" class="flex items-center gap-3">
        <img src="<?= url('assets/logo-web.png'); ?>" alt="Logo" class="h-9 w-9 object-contain">
        <div>
          <div class="font-semibold">โรงเรียนสุคนธีรวิทย์</div>
          <div class="text-xs text-slate-500">ระบบจัดตารางสอน</div>
        </div>
      </a>
    </div>

    <div class="px-3 py-3 space-y-2 overflow-y-auto flex-1">

      <?php if ($anyLocked): ?>
      <div class="mx-1 mb-1 px-3 py-2 rounded-xl bg-rose-50 border border-rose-300 flex items-center gap-2">
        <span class="text-base">🔒</span>
        <div>
          <div class="text-xs font-bold text-rose-700">ปิดการแก้ไข</div>
          <?php if ($editingLocked): ?>
          <div class="text-[10px] text-rose-500">ทุกส่วน (Global)</div>
          <?php else: ?>
          <div class="text-[10px] text-rose-500"><?= implode(' · ', array_map('htmlspecialchars', $lockedSectionLabels)) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($isSuperuser): ?>
      <a href="<?= url('superuser_settings.php'); ?>" class="flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('superuser_settings.php', $path); ?> bg-violet-50 border border-violet-200 hover:bg-violet-100">
        <span class="text-lg">🛡️</span>
        <span class="text-sm font-semibold text-violet-700">Superuser Settings</span>
      </a>
      <?php endif; ?>

      <details class="tt-nav rounded-xl" open>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">ทั่วไป</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 space-y-1">
          <a href="<?= url('index.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('index.php', $path); ?>">
            <span class="text-lg">🏠</span>
            <span>แดชบอร์ด</span>
          </a>
          <?php if ($isAdmin): ?>
          <a href="<?= url('users.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('users.php', $path); ?>">
            <span class="text-lg">👥</span>
            <span>ผู้ใช้</span>
          </a>
          <?php endif; ?>
        </div>
      </details>

      <?php if ($isAdmin): ?>
      <details class="tt-nav rounded-xl" <?= $openSettings ? 'open' : ''; ?>>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">⚙️ ตั้งค่า</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 pl-2 border-l border-slate-200 space-y-1">
          <a href="<?= url('teachers.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('teachers.php', $path); ?>">
            <span class="text-lg">👨‍🏫</span>
            <span>ครู</span>
          </a>
          <a href="<?= url('subject_groups.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('subject_groups.php', $path); ?>">
            <span class="text-lg">📖</span>
            <span>กลุ่มสาระ</span>
          </a>
          <a href="<?= url('years.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('years.php', $path); ?>">
            <span class="text-lg">📅</span>
            <span>ปีการศึกษา</span>
          </a>
          <a href="<?= url('periods.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('periods.php', $path); ?>">
            <span class="text-lg">⏰</span>
            <span>คาบเรียน</span>
          </a>
          <a href="<?= url('subjects.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('subjects.php', $path); ?>">
            <span class="text-lg">📚</span>
            <span>รายวิชา</span>
          </a>
          <a href="<?= url('rooms.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('rooms.php', $path); ?>">
            <span class="text-lg">🚪</span>
            <span>ห้องเรียน</span>
          </a>
          <a href="<?= url('classes.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('classes.php', $path); ?>">
            <span class="text-lg">🎓</span>
            <span>ชั้นเรียน</span>
          </a>
          <a href="<?= url('class_weekends.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('class_weekends.php', $path); ?>">
            <span class="text-lg">📆</span>
            <span>เสาร์-อาทิตย์</span>
          </a>
        </div>
      </details>

      <details class="tt-nav rounded-xl" <?= $openSystem ? 'open' : ''; ?>>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">🔧 ระบบ</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 pl-2 border-l border-slate-200 space-y-1">
          <a href="<?= url('activity_logs.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('activity_logs.php', $path); ?>">
            <span class="text-lg">📋</span>
            <span>Activity Logs</span>
          </a>
          <a href="<?= url('backup.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('backup.php', $path); ?>">
            <span class="text-lg">💾</span>
            <span>สำรองข้อมูล</span>
          </a>
        </div>
      </details>
      <?php endif; ?>

      <details class="tt-nav rounded-xl" <?= $openPlan ? 'open' : ''; ?>>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">📋 กำหนดตารางสอน</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 pl-2 border-l border-slate-200 space-y-1">
          <?php if ($isAdmin): ?>
          <a href="<?= url('activities.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('activities.php', $path); ?>">
            <span class="text-lg">🎯</span>
            <span>วิชากิจกรรม</span>
          </a>
          <?php endif; ?>
          <a href="<?= url('loads.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('loads.php', $path); ?>">
            <span class="text-lg">📊</span>
            <span>กำลังสอน</span>
          </a>
          <?php if ($isAdmin): ?>
          <a href="<?= url('teacher_constraints.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('teacher_constraints.php', $path); ?>">
            <span class="text-lg">🚫</span>
            <span>ข้อจำกัดครู</span>
          </a>
          <?php endif; ?>
        </div>
      </details>

      <details class="tt-nav rounded-xl" <?= $openTimetable ? 'open' : ''; ?>>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">✏️ จัดตารางสอน</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 pl-2 border-l border-slate-200 space-y-1">
          <a href="<?= url('co_teaching.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('co_teaching.php', $path); ?>">
            <span class="text-lg">🤝</span>
            <span>Co‑teaching</span>
          </a>
          <a href="<?= url('timetable.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('timetable.php', $path); ?>">
            <span class="text-lg">📝</span>
            <span>จัดตารางสอน</span>
          </a>
        </div>
      </details>

      <?php if ($isAdmin): ?>
      <details class="tt-nav rounded-xl" <?= $openDuty ? 'open' : ''; ?>>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">🧑‍🏫 จัดเวร</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 pl-2 border-l border-slate-200 space-y-1">
          <a href="<?= url('shift.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('shift.php', $path); ?>">
            <span class="text-lg">🗓️</span>
            <span>เวรวันหยุด</span>
          </a>
          <a href="<?= url('duty_assign.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('duty_assign.php', $path); ?>">
            <span class="text-lg">🧑‍🏫</span>
            <span>จัดเวรครู</span>
          </a>
          <a href="<?= url('duty_summary.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('duty_summary.php', $path); ?>">
            <span class="text-lg">📌</span>
            <span>สรุปเวร</span>
          </a>
          <a href="<?= url('duty_exclusions.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('duty_exclusions.php', $path); ?>">
            <span class="text-lg">🚫</span>
            <span>ละเว้นเวร</span>
          </a>

          <?php if ($isAdmin): ?>
            <div class="pt-2 mt-2 border-t border-slate-200"></div>
            <a href="<?= url('buildings.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('buildings.php', $path); ?>">
              <span class="text-lg">🏢</span>
              <span>อาคาร</span>
            </a>
            <a href="<?= url('teacher_buildings.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('teacher_buildings.php', $path); ?>">
              <span class="text-lg">🏢</span>
              <span>ครูประจำอาคาร</span>
            </a>
            <a href="<?= url('duty_slots.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('duty_slots.php', $path); ?>">
              <span class="text-lg">⏱️</span>
              <span>ช่วงเวลาเวร</span>
            </a>
            <a href="<?= url('duty_posts.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('duty_posts.php', $path); ?>">
              <span class="text-lg">📍</span>
              <span>ชื่อเวร/จุด</span>
            </a>
            <a href="<?= url('duty_shifts.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('duty_shifts.php', $path); ?>">
              <span class="text-lg">🧩</span>
              <span>กำหนดเวร</span>
            </a>
          <?php endif; ?>
        </div>
      </details>
      <?php endif; ?>

      <details class="tt-nav rounded-xl" <?= $openReport ? 'open' : ''; ?>>
        <summary class="flex items-center justify-between px-3 py-2 rounded-xl cursor-pointer select-none hover:bg-slate-50">
          <span class="tt-nav-title">📄 รายงาน</span>
          <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="tt-nav-links mt-1 pl-2 border-l border-slate-200 space-y-1">
          <a href="<?= url('report.php'); ?>" class="tt-nav-item flex items-center gap-3 px-3 py-2 rounded-xl <?= active_cls('report.php', $path); ?>">
            <span class="text-lg">🖨️</span>
            <span>พิมพ์/ส่งออก</span>
          </a>
        </div>
      </details>

      <?php if (isLoggedIn()): ?>
        <div class="pt-2 mt-2 border-t border-slate-200">
          <div class="px-3 text-xs text-slate-500">
            กำลังใช้งาน:
            <span class="font-semibold text-slate-700">@<?= htmlspecialchars(($user['username'] ?? '') ?: 'unknown'); ?></span>
          </div>
          <a href="<?= url('logout.php'); ?>" class="mt-2 flex items-center gap-3 px-3 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700" aria-label="ออกจากระบบ" title="ออกจากระบบ">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M10 17l-1 0c-2 0-3-1-3-3V10c0-2 1-3 3-3h1" />
              <path d="M15 7l5 5-5 5" />
              <path d="M20 12H10" />
            </svg>
            <span class="font-medium">ออกจากระบบ</span>
          </a>
        </div>
      <?php endif; ?>
    </div>

    <div class="p-3 text-xs text-slate-500 border-t">
      <div>© <?= date('Y'); ?> Timetable</div>
    </div>
  </aside>

  <!-- Mobile topbar -->
  <header class="md:hidden sticky top-0 z-30 w-full backdrop-blur bg-white/80 border-b border-slate-200">
    <div class="h-14 px-4 flex items-center justify-between">
      <a href="<?= url('index.php'); ?>" class="flex items-center gap-2">
        <img src="<?= url('assets/logo-web.png'); ?>" alt="Logo" class="h-8 w-8 object-contain">
        <span class="font-semibold">Timetable</span>
      </a>
      <button id="tt-side-btn" class="p-2 rounded-lg hover:bg-slate-100" aria-label="Menu">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 8h16M4 16h16" stroke-width="1.5"/></svg>
      </button>
    </div>

    <div id="tt-side-menu" class="hidden border-t bg-white">
      <nav class="p-3 grid gap-2">
        <details class="tt-nav rounded-lg" open>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">ทั่วไป</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1">
            <a href="<?= url('index.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('index.php', $path); ?>">
              <span>🏠</span>
              <span>แดชบอร์ด</span>
            </a>
            <?php if ($isAdmin): ?>
              <a href="<?= url('users.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('users.php', $path); ?>">
                <span>👥</span><span>ผู้ใช้</span>
              </a>
            <?php endif; ?>
          </div>
        </details>

        <?php if ($isAdmin): ?>
        <details class="tt-nav rounded-lg" <?= $openSettings ? 'open' : ''; ?>>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">⚙️ ตั้งค่า</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1 pl-2 border-l border-slate-200">
            <a href="<?= url('teachers.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('teachers.php', $path); ?>"><span>👨‍🏫</span><span>ครู</span></a>
            <a href="<?= url('subject_groups.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('subject_groups.php', $path); ?>"><span>📖</span><span>กลุ่มสาระ</span></a>
            <a href="<?= url('years.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('years.php', $path); ?>"><span>📅</span><span>ปีการศึกษา</span></a>
            <a href="<?= url('periods.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('periods.php', $path); ?>"><span>⏰</span><span>คาบเรียน</span></a>
            <a href="<?= url('subjects.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('subjects.php', $path); ?>"><span>📚</span><span>รายวิชา</span></a>
            <a href="<?= url('rooms.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('rooms.php', $path); ?>"><span>🚪</span><span>ห้องเรียน</span></a>
            <a href="<?= url('classes.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('classes.php', $path); ?>"><span>🎓</span><span>ชั้นเรียน</span></a>
            <a href="<?= url('class_weekends.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('class_weekends.php', $path); ?>"><span>📆</span><span>เสาร์-อาทิตย์</span></a>
          </div>
        </details>

        <details class="tt-nav rounded-lg" <?= $openSystem ? 'open' : ''; ?>>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">🔧 ระบบ</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1 pl-2 border-l border-slate-200">
            <a href="<?= url('activity_logs.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('activity_logs.php', $path); ?>"><span>📋</span><span>Activity Logs</span></a>
            <a href="<?= url('backup.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('backup.php', $path); ?>"><span>💾</span><span>สำรองข้อมูล</span></a>
          </div>
        </details>
        <?php endif; ?>

        <details class="tt-nav rounded-lg" <?= $openPlan ? 'open' : ''; ?>>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">📋 กำหนดตารางสอน</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1 pl-2 border-l border-slate-200">
            <a href="<?= url('activities.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('activities.php', $path); ?>"><span>🎯</span><span>วิชากิจกรรม</span></a>
            <a href="<?= url('loads.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('loads.php', $path); ?>"><span>📊</span><span>กำลังสอน</span></a>
            <a href="<?= url('teacher_constraints.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('teacher_constraints.php', $path); ?>"><span>🚫</span><span>ข้อจำกัดครู</span></a>
          </div>
        </details>

        <details class="tt-nav rounded-lg" <?= $openTimetable ? 'open' : ''; ?>>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">✏️ จัดตารางสอน</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1 pl-2 border-l border-slate-200">
            <a href="<?= url('co_teaching.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('co_teaching.php', $path); ?>"><span>🤝</span><span>Co‑teaching</span></a>
            <a href="<?= url('timetable.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('timetable.php', $path); ?>"><span>📝</span><span>จัดตารางสอน</span></a>
          </div>
        </details>

        <?php if ($isAdmin): ?>
        <details class="tt-nav rounded-lg" <?= $openDuty ? 'open' : ''; ?>>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">🧑‍🏫 จัดเวร</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1 pl-2 border-l border-slate-200">
            <a href="<?= url('duty_assign.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('duty_assign.php', $path); ?>"><span>🧑‍🏫</span><span>จัดเวรครู</span></a>
            <a href="<?= url('duty_summary.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('duty_summary.php', $path); ?>"><span>📌</span><span>สรุปเวร</span></a>
            <a href="<?= url('duty_exclusions.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('duty_exclusions.php', $path); ?>"><span>🚫</span><span>ละเว้นเวร</span></a>

            <?php if ($isAdmin): ?>
              <div class="pt-1 mt-1 border-t border-slate-200"></div>
              <a href="<?= url('buildings.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('buildings.php', $path); ?>"><span>🏢</span><span>อาคาร</span></a>
              <a href="<?= url('teacher_buildings.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('teacher_buildings.php', $path); ?>"><span>🏢</span><span>ครูประจำอาคาร</span></a>
              <a href="<?= url('duty_slots.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('duty_slots.php', $path); ?>"><span>⏱️</span><span>ช่วงเวลาเวร</span></a>
              <a href="<?= url('duty_posts.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('duty_posts.php', $path); ?>"><span>📍</span><span>ชื่อเวร/จุด</span></a>
              <a href="<?= url('duty_shifts.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('duty_shifts.php', $path); ?>"><span>🧩</span><span>กำหนดเวร</span></a>
            <?php endif; ?>
          </div>
        </details>
        <?php endif; ?>

        <details class="tt-nav rounded-lg" <?= $openReport ? 'open' : ''; ?>>
          <summary class="flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer select-none hover:bg-slate-50">
            <span class="tt-nav-title">📄 รายงาน</span>
            <svg class="tt-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.24 4.5a.75.75 0 0 1-1.08 0l-4.24-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
          </summary>
          <div class="tt-nav-links mt-1 grid gap-1 pl-2 border-l border-slate-200">
            <a href="<?= url('report.php'); ?>" class="tt-nav-item flex items-center gap-2 px-3 py-2 rounded-lg <?= active_cls('report.php', $path); ?>"><span>🖨️</span><span>พิมพ์/ส่งออก</span></a>
          </div>
        </details>

        <?php if (isLoggedIn()): ?>
          <div class="pt-2 mt-2 border-t border-slate-200">
            <div class="px-3 text-xs text-slate-500">
              กำลังใช้งาน:
              <span class="font-semibold text-slate-700">@<?= htmlspecialchars(($user['username'] ?? '') ?: 'unknown'); ?></span>
            </div>
            <a href="<?= url('logout.php'); ?>" class="mt-2 flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700" aria-label="ออกจากระบบ" title="ออกจากระบบ">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10 17l-1 0c-2 0-3-1-3-3V10c0-2 1-3 3-3h1" />
                <path d="M15 7l5 5-5 5" />
                <path d="M20 12H10" />
              </svg>
              <span class="font-medium">ออกจากระบบ</span>
            </a>
          </div>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <!-- Main Content Wrapper (เริ่ม content ที่นี่) -->
  <main class="flex-1 min-w-0">
  <script>
    // mobile menu toggle
    (function(){
      const btn = document.getElementById('tt-side-btn');
      const menu = document.getElementById('tt-side-menu');
      if (!btn || !menu) return;
      btn.addEventListener('click', ()=> {
        menu.classList.toggle('hidden');
      });
      // close menu when clicking outside on small screens
      document.addEventListener('click', (e)=> {
        if (!menu.classList.contains('hidden')) {
          const inside = menu.contains(e.target) || btn.contains(e.target);
          if (!inside) menu.classList.add('hidden');
        }
      });
    })();
  </script>
