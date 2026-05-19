<?php
/**
 * shift_holiday_export.php — รายงานเวรวันหยุดนักขัตฤกษ์ (พิมพ์ / PDF)
 */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();

/* ── helpers ── */
function phde_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function phde_date_th(?string $ymd): string {
    if (!$ymd) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return $ymd;
    static $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
        'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    static $days   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
    return $days[(int)$dt->format('w')] . ' ' . (int)$dt->format('j') . ' ' .
        $months[(int)$dt->format('n')] . ' ' . ((int)$dt->format('Y') + 543);
}

/* ── params ── */
$years        = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll(PDO::FETCH_ASSOC);
$activeYearId = tt_active_year_id($pdo);
if ($activeYearId <= 0 && !empty($years)) $activeYearId = (int)$years[0]['id'];
$year_id = (int)($_GET['year_id'] ?? $activeYearId);
if ($year_id <= 0 && !empty($years)) $year_id = (int)$years[0]['id'];

$yearLabel = '';
foreach ($years as $y) {
    if ((int)$y['id'] === $year_id) { $yearLabel = $y['year_label']; break; }
}

/* Buildings filter */
tt_buildings_init($pdo);
$buildings   = tt_buildings_list($pdo);
$building_id = (int)($_GET['building_id'] ?? 0);
$buildingLabel = '';
if ($building_id > 0) {
    foreach ($buildings as $b) {
        if ((int)$b['id'] === $building_id) { $buildingLabel = $b['building_name']; break; }
    }
}

/* teacher→building map */
$teacherBldMap = []; // teacher_id => [building_id,...]
if (!empty($buildings)) {
    $tbRows = $pdo->query('SELECT teacher_id, building_id FROM teacher_buildings ORDER BY teacher_id, building_id')
                  ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tbRows as $r) {
        $teacherBldMap[(int)$r['teacher_id']][] = (int)$r['building_id'];
    }
}

/* ── Query report data ── */
$sql = 'SELECT d.id AS date_id, d.holiday_date, d.holiday_name,
    a.id AS assignment_id, a.teacher_id, a.building_id AS assign_bld,
    t.teacher_code, t.first_name, t.last_name,
    s.to_teacher_id,
    t2.first_name AS sub_first, t2.last_name AS sub_last,
    b.building_name
    FROM phd_dates d
    LEFT JOIN phd_assignments a ON a.phd_date_id=d.id
    LEFT JOIN teachers t ON t.id=a.teacher_id
    LEFT JOIN phd_substitutions s ON s.assignment_id=a.id
    LEFT JOIN teachers t2 ON t2.id=s.to_teacher_id
    LEFT JOIN duty_buildings b ON b.id=a.building_id
    WHERE d.academic_year_id=?';
$params = [$year_id];
if ($building_id > 0) {
    $sql .= ' AND a.building_id=?';
    $params[] = $building_id;
}
$sql .= ' ORDER BY d.holiday_date, b.sort_order, b.building_name, t.teacher_code, t.first_name';

$rStmt = $pdo->prepare($sql);
$rStmt->execute($params);
$rRows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

/* Group: date → [building → [teachers]] */
$report   = []; // date => {name, buildings: {bld_name => [items]}, nobld: [items]}
$dateInfo = []; // date => holiday_name
foreach ($rRows as $rr) {
    $dt = $rr['holiday_date'];
    if (!isset($report[$dt])) {
        $report[$dt] = ['name' => $rr['holiday_name'], 'groups' => []];
    }
    if (!$rr['teacher_id']) continue;
    $grpKey = $rr['building_name'] ?? ($rr['assign_bld'] ? 'อาคาร #'.$rr['assign_bld'] : '—');
    $report[$dt]['groups'][$grpKey][] = $rr;
}

/* Teacher summary */
$tSummary = []; // teacher_id => {name, count, building}
foreach ($rRows as $rr) {
    if (!$rr['teacher_id']) continue;
    // skip if building filter active and teacher not in that building
    if ($building_id > 0 && (int)($rr['assign_bld'] ?? 0) !== $building_id) continue;
    $tid = (int)$rr['teacher_id'];
    if (!isset($tSummary[$tid])) {
        $blds = $teacherBldMap[$tid] ?? [];
        $bldNames = [];
        foreach ($buildings as $b) {
            if (in_array((int)$b['id'], $blds, true)) $bldNames[] = $b['building_name'];
        }
        $tSummary[$tid] = [
            'name'     => ($rr['teacher_code'] ? $rr['teacher_code'] . ' – ' : '') . $rr['first_name'] . ' ' . $rr['last_name'],
            'count'    => 0,
            'building' => implode(' / ', $bldNames) ?: '—',
        ];
    }
    $tSummary[$tid]['count']++;
}
uasort($tSummary, fn($a, $b) => $b['count'] - $a['count']);

$totalDates  = count($report);
$totalAssign = array_sum(array_map(fn($d) => array_sum(array_map('count', $d['groups'])), $report));
$subCount    = 0;
foreach ($rRows as $rr) { if (!empty($rr['to_teacher_id'])) $subCount++; }

$today = (new DateTime())->format('d/m/') . ((int)(new DateTime())->format('Y') + 543);
$hasBuildings = !empty($buildings);
$backUrl = url('shift_holiday.php') . '?tab=report&year_id=' . $year_id;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>รายงานเวรวันหยุดนักขัตฤกษ์ – <?= phde_h($yearLabel) ?></title>
<style>
  :root {
    --head-bg: #eef2ff;
    --border:  #d1d5db;
    --muted:   #6b7280;
    --green:   #d1fae5;
    --amber:   #fef3c7;
    --font:    'Sarabun', 'Noto Sans Thai', sans-serif;
  }
  * { box-sizing: border-box; margin:0; padding:0; }
  html, body { font-family: var(--font); font-size:14px; background:#f1f5f9; color:#1e293b; }
  .wrap  { max-width:960px; margin:24px auto; padding:0 16px 40px; }
  .paper { background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:0 2px 12px #0001; padding:28px 32px; }

  /* header */
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:16px;
         padding-bottom:16px; border-bottom:2px solid var(--border); margin-bottom:20px; flex-wrap:wrap; }
  .title { font-size:20px; font-weight:700; }
  .sub   { font-size:13px; color:var(--muted); margin-top:4px; }
  .actions { display:flex; flex-direction:column; gap:8px; align-items:flex-end; }
  .btn { display:inline-block; padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600;
         cursor:pointer; text-decoration:none; border:1px solid var(--border); background:#f8fafc; color:#374151; }
  .btn.primary { background:#4f46e5; color:#fff; border-color:#4f46e5; }
  .btn.primary:hover { background:#4338ca; }
  .print-hint { font-size:11px; color:var(--muted); text-align:right; max-width:200px; }

  /* summary cards */
  .cards { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
  .card  { border:1px solid var(--border); border-radius:10px; padding:14px; text-align:center; }
  .card-num { font-size:22px; font-weight:700; }
  .card-lbl { font-size:11px; color:var(--muted); margin-top:4px; }
  .indigo { color:#4f46e5; } .green { color:#059669; } .amber { color:#d97706; } .slate { color:#475569; }

  /* building filter bar */
  .bld-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; align-items:center; }
  .bld-bar span { font-size:12px; color:var(--muted); }
  .bld-link { padding:5px 12px; border-radius:6px; font-size:12px; font-weight:600; text-decoration:none;
              border:1px solid var(--border); color:#374151; }
  .bld-link.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }

  /* section */
  .section { margin-bottom:28px; }
  .section-title { font-size:15px; font-weight:700; margin-bottom:12px; }

  /* main table */
  table  { width:100%; border-collapse:collapse; }
  th, td { padding:7px 10px; border:1px solid var(--border); vertical-align:top; }
  thead th { background:var(--head-bg); font-weight:700; font-size:13px; text-align:center; }
  th.col-date { width:170px; text-align:left; }
  th.col-name { width:155px; text-align:left; }
  .dt-day  { font-size:12px; color:var(--muted); }
  .bld-tag { display:inline-block; font-size:11px; font-weight:700; color:#4f46e5; margin-right:6px; }
  .chip     { display:inline-block; padding:2px 8px; border-radius:20px; font-size:12px;
               font-weight:600; margin:2px 3px 2px 0; }
  .chip-ok  { background:var(--green); color:#065f46; }
  .chip-sub { background:var(--amber); color:#92400e; }
  .chip-sub .arrow { opacity:.65; }

  /* teacher summary table */
  table.summary th { text-align:left; }
  table.summary td.cnt { text-align:center; font-weight:700; color:#4f46e5; }

  /* print */
  @media print {
    @page { size:A4 portrait; margin:12mm 10mm; }
    html,body { background:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; font-size:12px; }
    .wrap { margin:0; max-width:none; padding:0; }
    .paper { border:none; border-radius:0; box-shadow:none; padding:0; }
    .actions, .bld-bar, .print-hint { display:none !important; }
    .top { padding-bottom:10px; margin-bottom:14px; }
    .cards { grid-template-columns:repeat(4,1fr); }
    table { page-break-inside:auto; }
    tr { page-break-inside:avoid; }
    thead { display:table-header-group; }
  }
</style>
</head>
<body>
<div class="wrap">
<div class="paper">

  <!-- Header -->
  <div class="top">
    <div>
      <div class="title">🎌 รายงานเวรวันหยุดนักขัตฤกษ์</div>
      <div class="sub">
        ปีการศึกษา: <?= phde_h($yearLabel ?: (string)$year_id) ?>
        <?= $buildingLabel ? ' &nbsp;·&nbsp; อาคาร: ' . phde_h($buildingLabel) : '' ?>
        &nbsp;·&nbsp; วันที่พิมพ์: <?= $today ?>
      </div>
    </div>
    <div class="actions">
      <button class="btn primary" onclick="window.print()">🖨 พิมพ์ / PDF</button>
      <a class="btn" href="<?= phde_h($backUrl) ?>">← กลับ</a>
      <div class="print-hint">ถ้าสีไม่ออก: เปิด "Background graphics" ในตัวเลือกการพิมพ์</div>
    </div>
  </div>

  <!-- Building filter -->
  <?php if ($hasBuildings): ?>
  <div class="bld-bar">
    <span>กรองอาคาร:</span>
    <a href="?year_id=<?= $year_id ?>" class="bld-link <?= $building_id === 0 ? 'active' : '' ?>">ทุกอาคาร</a>
    <?php foreach ($buildings as $b): ?>
      <a href="?year_id=<?= $year_id ?>&building_id=<?= (int)$b['id'] ?>"
         class="bld-link <?= $building_id === (int)$b['id'] ? 'active' : '' ?>">
        <?= phde_h($b['building_name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Summary cards -->
  <div class="cards">
    <div class="card"><div class="card-num indigo"><?= $totalDates ?></div><div class="card-lbl">วันหยุดทั้งหมด</div></div>
    <div class="card"><div class="card-num slate"><?= $totalAssign ?></div><div class="card-lbl">รายการลงเวร</div></div>
    <div class="card"><div class="card-num green"><?= count($tSummary) ?></div><div class="card-lbl">ครูที่ลงเวร</div></div>
    <div class="card"><div class="card-num amber"><?= $subCount ?></div><div class="card-lbl">รายการแทนเวร</div></div>
  </div>

  <!-- Detail table -->
  <div class="section">
    <div class="section-title">📋 รายละเอียดเวรแต่ละวัน</div>
    <?php if (empty($report)): ?>
      <p style="color:var(--muted);">ยังไม่มีข้อมูลเวร</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th class="col-date">วันที่</th>
          <th class="col-name">ชื่อวันหยุด</th>
          <th>ครูลงเวร<?= $hasBuildings ? ' (แยกอาคาร)' : '' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($report as $dt => $dr): ?>
        <tr>
          <td>
            <div><?= phde_h(phde_date_th($dt)) ?></div>
          </td>
          <td style="font-weight:600;"><?= phde_h($dr['name']) ?></td>
          <td>
            <?php if (empty($dr['groups'])): ?>
              <span style="color:#d1d5db;font-size:12px;">— ยังไม่มีครู —</span>
            <?php else:
              foreach ($dr['groups'] as $grpName => $items):
                $showGrp = $hasBuildings && count($dr['groups']) > 1;
            ?>
              <?php if ($showGrp): ?><span class="bld-tag">🏫 <?= phde_h($grpName) ?></span><?php endif; ?>
              <?php foreach ($items as $it):
                $hasSub = !empty($it['to_teacher_id']);
              ?>
                <span class="chip <?= $hasSub ? 'chip-sub' : 'chip-ok' ?>">
                  <?= phde_h($it['first_name'] . ' ' . $it['last_name']) ?>
                  <?php if ($hasSub): ?>
                    <span class="arrow">→ <?= phde_h($it['sub_first'] . ' ' . $it['sub_last']) ?></span>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
              <?php if ($showGrp): ?><br><?php endif; ?>
            <?php endforeach; endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Teacher summary -->
  <?php if (!empty($tSummary)): ?>
  <div class="section">
    <div class="section-title">👩‍🏫 สรุปจำนวนเวรต่อครู</div>
    <table class="summary">
      <thead>
        <tr>
          <th style="width:40px;text-align:center;">ลำดับ</th>
          <th>ชื่อ-สกุล (รหัส)</th>
          <?php if ($hasBuildings): ?><th style="width:140px;">อาคาร</th><?php endif; ?>
          <th style="width:90px;text-align:center;">จำนวนเวร</th>
        </tr>
      </thead>
      <tbody>
        <?php $rank = 1; foreach ($tSummary as $ts): ?>
        <tr>
          <td style="text-align:center;color:var(--muted);"><?= $rank++ ?></td>
          <td><?= phde_h($ts['name']) ?></td>
          <?php if ($hasBuildings): ?><td style="font-size:12px;color:var(--muted);"><?= phde_h($ts['building']) ?></td><?php endif; ?>
          <td class="cnt"><?= $ts['count'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div><!-- /paper -->
</div><!-- /wrap -->
</body>
</html>
