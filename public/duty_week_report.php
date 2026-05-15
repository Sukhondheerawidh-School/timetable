<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_duty_init($pdo);

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

// Week grid query (same as duty_summary.php week view)
$weekSql = 'SELECT
    mts.sort_order AS slot_sort, mts.slot_label, mts.period_no, mts.start_time, mts.end_time,
    ms.id AS shift_id, ms.day_of_week, ms.required_count,
    mp.post_name,
    t.teacher_code, t.first_name, t.last_name
  FROM duty_master_time_slots mts
  JOIN duty_master_shifts ms ON ms.duty_time_slot_id = mts.id AND ms.is_active = 1
  JOIN duty_master_posts mp ON mp.id = ms.duty_post_id AND mp.is_active = 1
  LEFT JOIN duty_term_assignments ta ON ta.duty_master_shift_id = ms.id
    AND ta.academic_year_id = ? AND ta.term_no = ?
    AND NOT EXISTS (
      SELECT 1 FROM duty_term_exclusions e
      WHERE e.academic_year_id = ta.academic_year_id AND e.term_no = ta.term_no AND e.teacher_id = ta.teacher_id
    )
  LEFT JOIN teachers t ON t.id = ta.teacher_id
  WHERE mts.is_active = 1';
$weekParams = [$year_id, $term_no];
if ($building_id > 0) { $weekSql .= ' AND mp.building_id = ?'; $weekParams[] = $building_id; }
if ($post_id > 0)     { $weekSql .= ' AND mp.id = ?';          $weekParams[] = $post_id; }
$weekSql .= ' ORDER BY mts.sort_order, mts.start_time, ms.day_of_week, mp.sort_order, mp.post_name, t.teacher_code, t.first_name';
$weekStmt = $pdo->prepare($weekSql);
$weekStmt->execute($weekParams);

$weekGrid  = [];
$weekSlots = [];
while ($wr = $weekStmt->fetch(PDO::FETCH_ASSOC)) {
  $sk = (int)$wr['slot_sort'].'_'.(string)$wr['start_time'];
  if (!isset($weekGrid[$sk])) {
    $weekGrid[$sk] = [
      'slot_label' => $wr['slot_label'],
      'period_no'  => $wr['period_no'],
      'start_time' => $wr['start_time'],
      'end_time'   => $wr['end_time'],
      'days'       => [],
    ];
    $weekSlots[] = $sk;
  }
  $dow = (int)$wr['day_of_week'];
  $sid = (int)$wr['shift_id'];
  if (!isset($weekGrid[$sk]['days'][$dow])) $weekGrid[$sk]['days'][$dow] = [];
  if (!isset($weekGrid[$sk]['days'][$dow][$sid])) {
    $weekGrid[$sk]['days'][$dow][$sid] = [
      'post_name'      => $wr['post_name'],
      'required_count' => (int)$wr['required_count'],
      'teachers'       => [],
    ];
  }
  if ($wr['first_name'] !== null) {
    $weekGrid[$sk]['days'][$dow][$sid]['teachers'][] =
      trim(($wr['teacher_code'] ? $wr['teacher_code'].' ' : '').$wr['first_name'].' '.$wr['last_name']);
  }
}

$backQs = 'year_id='.$year_id.'&term_no='.$term_no
  .($building_id>0?'&building_id='.$building_id:'')
  .($post_id>0?'&post_id='.$post_id:'')
  .'&view_mode=week';

$today = date('Y-m-d');
$dows = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์'];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>รายงานเวรรายสัปดาห์ – <?= htmlspecialchars($yearLabel ?: (string)$year_id) ?> – <?= htmlspecialchars($termName) ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(url('assets/logo-web.png')) ?>">
  <style>
    @font-face { font-family:'Sarabun'; src:url('assets/fonts/Sarabun-Regular.ttf') format('truetype'); font-weight:400; }
    @font-face { font-family:'Sarabun'; src:url('assets/fonts/Sarabun-Bold.ttf') format('truetype'); font-weight:700; }
    @font-face { font-family:'Sarabun'; src:url('assets/fonts/Sarabun-Italic.ttf') format('truetype'); font-weight:400; font-style:italic; }
    @font-face { font-family:'Sarabun'; src:url('assets/fonts/Sarabun-BoldItalic.ttf') format('truetype'); font-weight:700; font-style:italic; }

    :root { --border:#e5e7eb; --muted:#475569; --head-bg:#f1f5f9; }
    html, body { margin:0; padding:0; }
    body { font-family:'Sarabun',sans-serif; font-size:13px; line-height:1.5; background:#f1f5f9; color:#0f172a; }

    .wrap { max-width:1100px; margin:24px auto; padding:0 16px; }
    .paper { background:#fff; border:1px solid var(--border); border-radius:16px; overflow:hidden; box-shadow:0 10px 30px rgba(15,23,42,.08); }

    .top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; padding:16px 18px; border-bottom:1px solid var(--border); }
    .title { font-size:20px; font-weight:700; margin:0; }
    .sub { margin-top:5px; color:var(--muted); font-size:13px; }

    .actions { display:flex; gap:8px; flex-wrap:wrap; flex-shrink:0; }
    .btn { appearance:none; border:1px solid var(--border); background:#fff; color:#0f172a; padding:8px 13px; border-radius:10px; text-decoration:none; font-size:13px; cursor:pointer; font-family:'Sarabun',sans-serif; white-space:nowrap; }
    .btn.primary { background:#0f172a; border-color:#0f172a; color:#fff; }
    .print-hint { margin-top:6px; color:var(--muted); font-size:12px; }

    .section { padding:16px 18px 24px; }

    /* Grid table */
    table { width:100%; border-collapse:collapse; table-layout:fixed; }
    th, td { padding:7px 9px; border:1px solid var(--border); vertical-align:top; word-break:break-word; }
    thead th { background:var(--head-bg); font-weight:700; text-align:center; font-size:13px; }
    th.slot-col { width:120px; text-align:left; }
    .slot-label { font-weight:700; }
    .slot-time  { color:var(--muted); font-size:12px; }

    .cell-post { font-weight:700; font-size:12.5px; margin-bottom:3px; }
    .cell-teacher { font-size:12.5px; color:#1e3a5f; padding-left:2px; }
    .cell-empty { color:#d1d5db; font-style:italic; font-size:12px; }
    .separator { border-top:1px solid var(--border); margin:4px 0; }

    @media print {
      @page { size:A4 landscape; margin:10mm; }
      html,body { background:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .wrap { margin:0; max-width:none; padding:0; }
      .paper { border:none; border-radius:0; box-shadow:none; }
      .actions { display:none; }
      .print-hint { display:none; }
      .top { padding:0 0 8px; border-bottom:1px solid var(--border); margin-bottom:12px; }
      .section { padding:0; }
      thead th { background:var(--head-bg) !important; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="paper">
    <div class="top">
      <div>
        <h1 class="title">รายงานเวรรายสัปดาห์</h1>
        <div class="sub">
          ปีการศึกษา: <?= htmlspecialchars($yearLabel ?: (string)$year_id) ?>
          &nbsp;·&nbsp;<?= htmlspecialchars($termName) ?>
          <?= $buildingLabel ? ' &nbsp;·&nbsp; อาคาร: '.htmlspecialchars($buildingLabel) : '' ?>
          <?= $postLabel     ? ' &nbsp;·&nbsp; จุดเวร: '.htmlspecialchars($postLabel)     : '' ?>
          &nbsp;·&nbsp; วันที่พิมพ์: <?= htmlspecialchars($today) ?>
        </div>
      </div>
      <div class="actions">
        <button class="btn primary" onclick="window.print()">🖨 พิมพ์ / PDF</button>
        <a class="btn" href="<?= htmlspecialchars(url('duty_summary.php?'.$backQs)) ?>">← กลับ</a>
        <div class="print-hint">ถ้าสีไม่ออก: เปิด "Background graphics" ในตัวเลือกการพิมพ์</div>
      </div>
    </div>

    <div class="section">
      <?php if (!$weekSlots): ?>
        <div style="color:var(--muted);padding:24px 0;">— ยังไม่มีข้อมูลเวรในเทอมนี้ —</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th class="slot-col">คาบ / เวลา</th>
            <?php foreach ($dows as $dlabel): ?>
              <th><?= $dlabel ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($weekSlots as $sk): ?>
            <?php $sg = $weekGrid[$sk]; ?>
            <tr>
              <!-- คาบ/เวลา -->
              <td>
                <?php
                  $pno = $sg['period_no'];
                  $st  = str_replace(':', '.', substr((string)$sg['start_time'], 0, 5));
                  $et  = str_replace(':', '.', substr((string)$sg['end_time'],   0, 5));
                ?>
                <div class="slot-label">
                  <?php if ($pno !== null): ?>คาบ <?= (int)$pno ?><?php else: ?><?= htmlspecialchars((string)$sg['slot_label']) ?><?php endif; ?>
                </div>
                <div class="slot-time"><?= $st ?> – <?= $et ?></div>
              </td>

              <!-- จันทร์–ศุกร์ -->
              <?php foreach (array_keys($dows) as $dow): ?>
                <td>
                  <?php $shifts = $sg['days'][$dow] ?? []; ?>
                  <?php if ($shifts): ?>
                    <?php $first = true; foreach ($shifts as $sd): ?>
                      <?php if (!$first): ?><div class="separator"></div><?php endif; $first = false; ?>
                      <div class="cell-post"><?= htmlspecialchars($sd['post_name']) ?></div>
                      <?php if ($sd['teachers']): ?>
                        <?php foreach ($sd['teachers'] as $tn): ?>
                          <div class="cell-teacher"><?= htmlspecialchars($tn) ?></div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="cell-empty">(ว่าง <?= $sd['required_count'] ?> คน)</div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span style="color:#e2e8f0;">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
