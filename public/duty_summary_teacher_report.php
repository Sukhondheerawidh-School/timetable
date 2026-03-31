<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_duty_init($pdo);

// Buildings (optional filter)
$building_id_param = $_GET['building_id'] ?? null;
$building_id = $building_id_param === null ? 0 : (int)$building_id_param;

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();
$activeYearId = 0;
foreach ($years as $y) { if (!empty($y['is_active'])) { $activeYearId = (int)$y['id']; break; } }
if (!$activeYearId && !empty($years)) $activeYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $activeYearId);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);

// Ensure master slots exist (templates depend on this)
tt_duty_master_sync_from_periods($pdo);

$yearLabel = '';
foreach ($years as $y) {
  if ((int)$y['id'] === $year_id) { $yearLabel = (string)$y['year_label']; break; }
}
$termName = 'เทอม ' . $term_no;
foreach (tt_terms_list($pdo, $year_id) as $t) {
  if ((int)$t['term_no'] === $term_no) { $termName = (string)$t['term_name']; break; }
}

$buildingLabel = $building_id > 0 ? tt_building_label($pdo, $building_id) : '';

// Teachers with duty assignments only (exclude term exclusions)
$tsql = 'SELECT DISTINCT t.id, t.first_name, t.last_name
  FROM duty_term_assignments ta
  JOIN teachers t ON t.id=ta.teacher_id
  JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
  JOIN duty_master_posts mp ON mp.id=ms.duty_post_id
  WHERE ta.academic_year_id=? AND ta.term_no=?';
$tparams = [$year_id, $term_no];
if ($building_id > 0) {
  $tsql .= ' AND mp.building_id=?';
  $tparams[] = $building_id;
}
$tsql .= '
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )
  ORDER BY t.first_name, t.last_name';

$tstmt = $pdo->prepare($tsql);
$tstmt->execute($tparams);
$trows = $tstmt->fetchAll(PDO::FETCH_ASSOC);

$teachers = []; // [teacher_id] => ['label'=>..., 'items'=>[]]
foreach ($trows as $tr) {
  $tid = (int)$tr['id'];
  $name = trim((string)$tr['first_name'] . ' ' . (string)$tr['last_name']);
  $label = $name;
  $teachers[$tid] = [
    'label' => $label !== '' ? $label : ('ครู #' . $tid),
    'items' => [], // keep for compatibility (not used in grid rendering)
  ];
}

// Duty details (filtered by building if provided)
$sql = 'SELECT ta.teacher_id,
    ms.day_of_week,
    mts.id AS slot_id, mts.sort_order AS slot_sort, mts.slot_label, mts.start_time, mts.end_time,
    mp.post_name, mp.building_id,
    b.building_name
  FROM duty_term_assignments ta
  JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
  JOIN duty_master_time_slots mts ON mts.id=ms.duty_time_slot_id
  JOIN duty_master_posts mp ON mp.id=ms.duty_post_id
  LEFT JOIN duty_buildings b ON b.id=mp.building_id
  WHERE ta.academic_year_id=? AND ta.term_no=?';
$params = [$year_id, $term_no];
if ($building_id > 0) {
  $sql .= ' AND mp.building_id=?';
  $params[] = $building_id;
}
$sql .= '
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )
  ORDER BY ta.teacher_id, COALESCE(b.building_name,\'\'), ms.day_of_week, mts.sort_order, mp.post_name';

$dstmt = $pdo->prepare($sql);
$dstmt->execute($params);

$slots = []; // [slot_id] => ['slot_label'=>..., 'start_time'=>..., 'end_time'=>..., 'sort_order'=>...]
$hasWeekend = false;
$grid = []; // [teacher_id][slot_id][day][key] => ['post'=>string,'building'=>string]

while ($r = $dstmt->fetch(PDO::FETCH_ASSOC)) {
  $tid = (int)$r['teacher_id'];
  if (!isset($teachers[$tid])) continue;

  $day = (int)$r['day_of_week'];
  if ($day > 5) $hasWeekend = true;

  $slotId = (int)$r['slot_id'];
  if ($slotId > 0 && !isset($slots[$slotId])) {
    $slots[$slotId] = [
      'slot_label' => (string)($r['slot_label'] ?? ''),
      'start_time' => (string)($r['start_time'] ?? ''),
      'end_time' => (string)($r['end_time'] ?? ''),
      'sort_order' => (int)($r['slot_sort'] ?? 0),
    ];
  }

  $post = (string)($r['post_name'] ?? '');
  $bname = (string)($r['building_name'] ?? '');
  $bline = ($building_id <= 0 && $bname !== '') ? $bname : '';

  if (!isset($grid[$tid])) $grid[$tid] = [];
  if (!isset($grid[$tid][$slotId])) $grid[$tid][$slotId] = [];
  if (!isset($grid[$tid][$slotId][$day])) $grid[$tid][$slotId][$day] = [];
  $cellKey = $post . '||' . $bline;
  $grid[$tid][$slotId][$day][$cellKey] = ['post' => $post, 'building' => $bline];
}

// Sort slots by sort_order
if ($slots) {
  uasort($slots, function($a, $b) {
    return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
  });
}

$dayFrom = 1;
$dayTo = $hasWeekend ? 7 : 5;

$today = date('Y-m-d');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>รายงานเวรครู (แยกตามครู) - <?= htmlspecialchars($yearLabel ?: (string)$year_id); ?> - <?= htmlspecialchars($termName); ?><?= $buildingLabel ? (' - '.htmlspecialchars($buildingLabel)) : '' ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(url('assets/logo-web.png?v=' . time())); ?>">
  <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars(url('assets/logo-web.png?v=' . time())); ?>">
  <style>
    @font-face {
      font-family: 'Sarabun';
      src: url('assets/fonts/Sarabun-Regular.ttf') format('truetype');
      font-weight: 400;
      font-style: normal;
    }
    @font-face {
      font-family: 'Sarabun';
      src: url('assets/fonts/Sarabun-Bold.ttf') format('truetype');
      font-weight: 700;
      font-style: normal;
    }
    @font-face {
      font-family: 'Sarabun';
      src: url('assets/fonts/Sarabun-Italic.ttf') format('truetype');
      font-weight: 400;
      font-style: italic;
    }
    @font-face {
      font-family: 'Sarabun';
      src: url('assets/fonts/Sarabun-BoldItalic.ttf') format('truetype');
      font-weight: 700;
      font-style: italic;
    }

    :root { --border: #e5e7eb; --muted: #475569; }
    html, body { height: 100%; }
    body { font-family: 'Sarabun', sans-serif; font-size: 12.5px; line-height: 1.45; margin: 0; background: #f1f5f9; color: #0f172a; }
    .wrap { max-width: 1100px; margin: 20px auto; padding: 0 12px; }
    .paper { background: #fff; border: 1px solid var(--border); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
    .top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 14px 16px; border-bottom: 1px solid var(--border); }
    .title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: .2px; }
    .sub { margin-top: 5px; color: var(--muted); font-size: 12.5px; }

    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn { appearance: none; border: 1px solid var(--border); background: #fff; color: #0f172a; padding: 8px 10px; border-radius: 12px; text-decoration: none; font-size: 12.5px; cursor: pointer; }
    .btn.primary { background: #0f172a; border-color: #0f172a; color: #fff; }
    .print-hint { margin-top: 7px; color: var(--muted); font-size: 12px; }

    .section { padding: 14px 16px 16px; }
    .teacher { padding: 12px 0; border-top: 1px solid var(--border); }
    .teacher:first-child { border-top: none; padding-top: 0; }
    .teacher-head { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; margin-bottom: 8px; }
    .teacher-name { font-weight: 700; font-size: 14px; }

    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { padding: 6px 6px; border-top: 1px solid var(--border); vertical-align: top; }
    thead th { background: #f1f5f9; border-top: none; text-align: left; }
    .col-time { width: 190px; white-space: nowrap; }
    .slot-label { font-weight: 700; }
    .slot-time { color: var(--muted); }
    .cell-empty { color: var(--muted); }
    .cell-duty { font-weight: 700; line-height: 1.25; word-break: break-word; }
    .cell-building { color: var(--muted); font-weight: 400; }
    .muted { color: var(--muted); }

    @media print {
      @page { size: A4; margin: 14mm; }
      html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      *, *::before, *::after { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      body { background: #fff; }
      .wrap { margin: 0; max-width: none; padding: 0; }
      .paper { border: none; border-radius: 0; box-shadow: none; }
      .actions { display: none; }
      .print-hint { display: none; }
      a { color: inherit; text-decoration: none; }
      .section { padding: 0; }
      .top { padding: 0 0 10px 0; border-bottom: 1px solid var(--border); margin-bottom: 12px; }
      .teacher { page-break-inside: avoid; }

      thead th { background: #f1f5f9 !important; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="paper">
      <div class="top">
        <div>
          <h1 class="title">รายงานเวรครู (แยกตามครู)</h1>
          <div class="sub">ปีการศึกษา: <?= htmlspecialchars($yearLabel ?: (string)$year_id); ?> · <?= htmlspecialchars($termName); ?><?= $buildingLabel ? (' · อาคาร: '.htmlspecialchars($buildingLabel)) : '' ?> · วันที่พิมพ์: <?= htmlspecialchars($today); ?></div>
        </div>
        <div class="actions">
          <button class="btn primary" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
          <a class="btn" href="<?= htmlspecialchars(url('duty_summary.php?year_id='.$year_id.'&term_no='.$term_no.($building_id>0?('&building_id='.$building_id):''))); ?>">กลับหน้าสรุป</a>
          <div class="print-hint">ถ้าพิมพ์แล้วสีไม่ออก: ในหน้าปริ้นของเบราว์เซอร์ให้เปิด “Background graphics” (พิมพ์พื้นหลัง)</div>
        </div>
      </div>

      <div class="section">
        <?php if (!$teachers || !$slots): ?>
          <div class="muted">— ยังไม่มีข้อมูลเวรในเงื่อนไขนี้ —</div>
        <?php else: ?>
          <?php foreach ($teachers as $tid => $t): ?>
            <div class="teacher">
              <div class="teacher-head">
                <div class="teacher-name"><?= htmlspecialchars((string)$t['label']); ?></div>
              </div>

              <table>
                <thead>
                  <tr>
                    <th class="col-time">ช่วงเวลา</th>
                    <?php for ($day = $dayFrom; $day <= $dayTo; $day++): ?>
                      <th><?= htmlspecialchars(tt_dow_label($day)); ?></th>
                    <?php endfor; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($slots as $slotId => $s): ?>
                    <?php
                      $slotLabel = (string)($s['slot_label'] ?? '');
                      $st = substr((string)($s['start_time'] ?? ''), 0, 5);
                      $en = substr((string)($s['end_time'] ?? ''), 0, 5);
                      $timeRange = ($st && $en) ? ($st.'–'.$en) : '';
                    ?>
                    <tr>
                      <td class="col-time">
                        <div class="slot-label"><?= htmlspecialchars($slotLabel !== '' ? $slotLabel : ('ช่วง #' . (int)$slotId)); ?></div>
                        <?php if ($timeRange !== ''): ?>
                          <div class="slot-time"><?= htmlspecialchars($timeRange); ?></div>
                        <?php endif; ?>
                      </td>
                      <?php for ($day = $dayFrom; $day <= $dayTo; $day++): ?>
                        <?php
                          $items = $grid[$tid][$slotId][$day] ?? [];
                          $items = array_values($items);
                        ?>
                        <td>
                          <?php if (!$items): ?>
                            <span class="cell-empty">—</span>
                          <?php else: ?>
                            <?php foreach ($items as $it): ?>
                              <div class="cell-duty"><?= htmlspecialchars((string)($it['post'] ?? '')); ?></div>
                              <?php if (!empty($it['building'])): ?>
                                <div class="cell-building"><?= htmlspecialchars((string)$it['building']); ?></div>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </td>
                      <?php endfor; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
<script>
  (function () {
    function shrinkIfWrapped(el) {
      try {
        var cs = window.getComputedStyle(el);
        var size = parseFloat(cs.fontSize || '0');
        if (!size) return;
        var min = 10;
        var tries = 0;
        while (tries < 8) {
          // If wraps to multiple lines, height becomes > 1 line-height.
          var lh = parseFloat(window.getComputedStyle(el).lineHeight || '0');
          if (!lh) lh = size * 1.25;
          if (el.getBoundingClientRect().height <= lh * 1.6) break;
          size = Math.max(min, size - 0.5);
          el.style.fontSize = size + 'px';
          tries++;
          if (size <= min) break;
        }
      } catch (e) {
        // ignore
      }
    }

    function run() {
      var nodes = document.querySelectorAll('.cell-duty');
      nodes.forEach(function (n) { shrinkIfWrapped(n); });
    }

    window.addEventListener('load', run);
    window.addEventListener('beforeprint', run);
  })();
</script>
</html>
