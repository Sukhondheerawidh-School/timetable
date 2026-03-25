<?php
// Duty tabs partial
// Expected variables (optional):
// - $ttDutyActive: 'slots'|'posts'|'shifts'|'assign'|'summary'|'exclusions'
// - $ttDutyYearId, $ttDutyTermNo: append ?year_id=..&term_no=.. for term pages links
// - $ttDutyBuildingId: optional building_id to preserve filter

$ttDutyActive = $ttDutyActive ?? '';
$ttDutyYearId = isset($ttDutyYearId) ? (int)$ttDutyYearId : 0;
$ttDutyTermNo = isset($ttDutyTermNo) ? (int)$ttDutyTermNo : 0;
$ttDutyBuildingId = isset($ttDutyBuildingId) ? (int)$ttDutyBuildingId : 0;

$ttDutyQs = '';
if ($ttDutyYearId > 0 && $ttDutyTermNo > 0) {
  $qs = ['year_id' => $ttDutyYearId, 'term_no' => $ttDutyTermNo];
  if ($ttDutyBuildingId > 0) $qs['building_id'] = $ttDutyBuildingId;
  $ttDutyQs = '?' . http_build_query($qs);
}

$ttDutyBase = 'px-3 py-2 rounded-xl transition whitespace-nowrap';
$ttDutyInactive = $ttDutyBase . ' hover:bg-white text-slate-700';
$ttDutyActiveCls = $ttDutyBase . ' bg-slate-900 text-white';

$ttDutyIsAdmin = false;
if (function_exists('currentUser')) {
  $u = currentUser();
  $ttDutyIsAdmin = is_array($u) && (($u['role'] ?? '') === 'admin');
}

$ttDutyMasterCol = $ttDutyIsAdmin ? 'md:col-span-7' : 'md:col-span-12';
$ttDutyTermCol   = $ttDutyIsAdmin ? 'md:col-span-5' : 'md:col-span-12';
?>
<div class="bg-white rounded-2xl shadow p-4 mb-4">
  <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
    <?php if ($ttDutyIsAdmin): ?>
    <div class="<?= $ttDutyMasterCol; ?>">
      <div class="text-xs font-semibold text-slate-500 mb-2">กำหนดข้อมูล (Master)</div>
      <div class="inline-flex flex-wrap gap-2 p-1 rounded-2xl bg-slate-50 border">
        <a class="<?= $ttDutyActive==='slots' ? $ttDutyActiveCls : $ttDutyInactive; ?>" <?= $ttDutyActive==='slots' ? 'aria-current="page"' : '' ?> href="<?= url('duty_slots.php') ?>">ช่วงเวลาเวร</a>
        <a class="<?= $ttDutyActive==='posts' ? $ttDutyActiveCls : $ttDutyInactive; ?>" <?= $ttDutyActive==='posts' ? 'aria-current="page"' : '' ?> href="<?= url('duty_posts.php') ?>">ชื่อเวร/จุด</a>
        <a class="<?= $ttDutyActive==='shifts' ? $ttDutyActiveCls : $ttDutyInactive; ?>" <?= $ttDutyActive==='shifts' ? 'aria-current="page"' : '' ?> href="<?= url('duty_shifts.php') ?>">กำหนดเวร</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="<?= $ttDutyTermCol; ?>">
      <div class="text-xs font-semibold text-slate-500 mb-2">การใช้งาน (รายเทอม)</div>
      <div class="inline-flex flex-wrap gap-2 p-1 rounded-2xl bg-slate-50 border">
        <a class="<?= $ttDutyActive==='assign' ? $ttDutyActiveCls : $ttDutyInactive; ?>" <?= $ttDutyActive==='assign' ? 'aria-current="page"' : '' ?> href="<?= url('duty_assign.php'.$ttDutyQs) ?>">จัดเวร</a>
        <a class="<?= $ttDutyActive==='summary' ? $ttDutyActiveCls : $ttDutyInactive; ?>" <?= $ttDutyActive==='summary' ? 'aria-current="page"' : '' ?> href="<?= url('duty_summary.php'.$ttDutyQs) ?>">สรุปเวร</a>
        <a class="<?= $ttDutyActive==='exclusions' ? $ttDutyActiveCls : $ttDutyInactive; ?>" <?= $ttDutyActive==='exclusions' ? 'aria-current="page"' : '' ?> href="<?= url('duty_exclusions.php'.$ttDutyQs) ?>">ละเว้นเวร</a>
      </div>
    </div>
  </div>
</div>
