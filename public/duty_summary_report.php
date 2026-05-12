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
$post_id = (int)($_GET['post_id'] ?? 0);
$postLabel = '';
if ($post_id > 0) {
  $pl = $pdo->prepare('SELECT post_name FROM duty_master_posts WHERE id=? LIMIT 1');
  $pl->execute([$post_id]);
  $postLabel = (string)($pl->fetchColumn() ?: '');
}
$sql = 'SELECT ms.day_of_week,
    mts.id AS slot_id, mts.sort_order, mts.slot_label, mts.start_time, mts.end_time,
    mp.post_name,
  t.first_name, t.last_name
  FROM duty_term_assignments ta
  JOIN duty_master_shifts ms ON ms.id=ta.duty_master_shift_id
  JOIN duty_master_time_slots mts ON mts.id=ms.duty_time_slot_id
  JOIN duty_master_posts mp ON mp.id=ms.duty_post_id
  JOIN teachers t ON t.id=ta.teacher_id
  WHERE ta.academic_year_id=? AND ta.term_no=?
    ';
$params = [$year_id, $term_no];
if ($building_id > 0) {
  $sql .= ' AND mp.building_id=?';
  $params[] = $building_id;
}
if ($post_id > 0) {
  $sql .= ' AND ms.duty_post_id=?';
  $params[] = $post_id;
}
$sql .= '
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id=ta.academic_year_id AND e.term_no=ta.term_no AND e.teacher_id=ta.teacher_id
    )
  ORDER BY ms.day_of_week, mts.sort_order, mp.post_name, t.first_name, t.last_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = []; // [day][slot_id][post_name] => ['slot'=>..., 'teachers'=>[]]
foreach ($rows as $r) {
  $day = (int)$r['day_of_week'];
  $slotId = (int)$r['slot_id'];
  $post = (string)$r['post_name'];

  if (!isset($groups[$day])) $groups[$day] = [];
  if (!isset($groups[$day][$slotId])) {
    $groups[$day][$slotId] = [
      '_slot' => [
        'slot_label' => (string)$r['slot_label'],
        'start_time' => (string)$r['start_time'],
        'end_time' => (string)$r['end_time'],
        'sort_order' => (int)$r['sort_order'],
      ],
      '_posts' => []
    ];
  }
  if (!isset($groups[$day][$slotId]['_posts'][$post])) {
    $groups[$day][$slotId]['_posts'][$post] = [];
  }

  $teacherLabel = trim((string)$r['first_name'] . ' ' . (string)$r['last_name']);
  $groups[$day][$slotId]['_posts'][$post][] = $teacherLabel;
}

$today = date('Y-m-d');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>รายงานเวรครู - <?= htmlspecialchars($yearLabel ?: (string)$year_id); ?> - <?= htmlspecialchars($termName); ?><?= $buildingLabel ? (' - '.htmlspecialchars($buildingLabel)) : '' ?></title>
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
    body { font-family: 'Sarabun', sans-serif; font-size: 14px; line-height: 1.5; margin: 0; background: #f1f5f9; color: #0f172a; }
    .wrap { max-width: 960px; margin: 24px auto; padding: 0 16px; }
    .paper { background: #fff; border: 1px solid var(--border); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
    .top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 16px 18px; border-bottom: 1px solid var(--border); }
    .title { font-size: 20px; font-weight: 700; margin: 0; letter-spacing: .2px; }
    .sub { margin-top: 6px; color: var(--muted); font-size: 14px; }

    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn { appearance: none; border: 1px solid var(--border); background: #fff; color: #0f172a; padding: 9px 12px; border-radius: 12px; text-decoration: none; font-size: 14px; cursor: pointer; }
    .btn.primary { background: #0f172a; border-color: #0f172a; color: #fff; }
    .print-hint { margin-top: 8px; color: var(--muted); font-size: 12.5px; }

    .section { padding: 16px 18px 18px; }
    .day { margin: 0 0 8px; font-weight: 700; font-size: 16px; }

    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px 8px; border-top: 1px solid var(--border); vertical-align: top; }
    thead th { background: #f1f5f9; border-top: none; text-align: left; }
    .slot { white-space: nowrap; width: 240px; }
    .post { width: 240px; }
    .teachers { line-height: 1.55; }
    .pill { display: inline-block; padding: 3px 9px; border: 1px solid var(--border); border-radius: 999px; margin: 0 6px 6px 0; background: #f8fafc; }

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

      thead th { background: #f1f5f9 !important; }
      .pill { background: #f8fafc !important; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="paper">
      <div class="top">
        <div>
          <h1 class="title">รายงานเวรครู</h1>
          <div class="sub">ปีการศึกษา: <?= htmlspecialchars($yearLabel ?: (string)$year_id); ?> · <?= htmlspecialchars($termName); ?><?= $buildingLabel ? (' · อาคาร: '.htmlspecialchars($buildingLabel)) : '' ?><?= $postLabel ? (' · จุดเวร: '.htmlspecialchars($postLabel)) : '' ?> · วันที่พิมพ์: <?= htmlspecialchars($today); ?></div>
        </div>
        <div class="actions">
          <button class="btn primary" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
          <a class="btn" href="<?= htmlspecialchars(url('duty_summary.php?year_id='.$year_id.'&term_no='.$term_no.($building_id>0?('&building_id='.$building_id):'').($post_id>0?('&post_id='.$post_id):''))); ?>">กลับหน้าสรุป</a>
          <div class="print-hint">ถ้าพิมพ์แล้วสีไม่ออก: ในหน้าปริ้นของเบราว์เซอร์ให้เปิด “Background graphics” (พิมพ์พื้นหลัง)</div>
        </div>
      </div>

      <div class="section">
        <?php if (!$groups): ?>
          <div style="color: var(--muted);">— ยังไม่มีข้อมูลเวรในเทอมนี้ —</div>
        <?php else: ?>
          <?php for ($day=1; $day<=7; $day++): ?>
            <?php if (empty($groups[$day])) continue; ?>
            <div style="margin-bottom: 18px;">
              <div class="day"><?= htmlspecialchars(tt_dow_label($day)); ?></div>
              <table>
                <thead>
                  <tr>
                    <th class="slot">ช่วงเวลา</th>
                    <th class="post">ชื่อเวร/จุด</th>
                    <th>รายชื่อครู</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $slotRows = $groups[$day];
                    uasort($slotRows, function($a,$b){ return ((int)$a['_slot']['sort_order']) <=> ((int)$b['_slot']['sort_order']); });
                  ?>
                  <?php foreach ($slotRows as $slotData): ?>
                    <?php
                      $slotLabel = (string)$slotData['_slot']['slot_label'];
                      $st = substr((string)$slotData['_slot']['start_time'],0,5);
                      $en = substr((string)$slotData['_slot']['end_time'],0,5);
                      $posts = $slotData['_posts'];
                      ksort($posts);
                      $first = true;
                      $rowSpan = max(1, count($posts));
                    ?>
                    <?php foreach ($posts as $postName => $teachers): ?>
                      <tr>
                        <?php if ($first): ?>
                          <td class="slot" rowspan="<?= (int)$rowSpan; ?>">
                            <div style="font-weight:700;"><?= htmlspecialchars($slotLabel); ?></div>
                            <div style="color: var(--muted); font-size: 14px;"><?= htmlspecialchars($st); ?>–<?= htmlspecialchars($en); ?></div>
                          </td>
                          <?php $first = false; ?>
                        <?php endif; ?>
                        <td class="post" style="font-weight:700;"><?= htmlspecialchars($postName); ?></td>
                        <td class="teachers">
                          <?php if (!$teachers): ?>
                            <span style="color: var(--muted);">—</span>
                          <?php else: ?>
                            <?php foreach ($teachers as $tl): ?>
                              <span class="pill"><?= htmlspecialchars($tl); ?></span>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endfor; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
