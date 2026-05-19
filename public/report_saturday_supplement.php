<?php
/**
 * report_saturday_supplement.php
 * รายงานตารางเรียนเสริม วันเสาร์ (ป.1 – ม.3)
 * 1 หน้า = 1 ระดับชั้น  |  แถว = ห้องเรียน  |  คอลัมน์ = คาบ
 */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function thaidate_sat($ymd) {
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    $m = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
          'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    return (int)date('j',$ts).' '.$m[(int)date('n',$ts)].' '.((int)date('Y',$ts)+543);
}

// แปลง grade_label เป็นชื่อเต็ม เช่น ป.5 → ประถมศึกษาปีที่ 5
function gradeFullName(string $g): string {
    // ดึงตัวเลขออกจาก grade_label
    if (!preg_match('/([ปม])\.?(\d+)/u', $g, $m)) return $g;
    $type = $m[1];
    $num  = (int)$m[2];
    if ($type === 'ป') return 'ประถมศึกษาปีที่ ' . $num;
    if ($type === 'ม') return 'มัธยมศึกษาปีที่ ' . $num;
    return $g;
}

/* ── Parameters ──────────────────────────────────────────────────── */
$year_id     = (int)($_GET['year_id']    ?? 0);
$term_no     = (int)($_GET['term_no']    ?? 0);
$school_name = trim($_GET['school_name'] ?? '');
$year_text   = trim($_GET['year_text']   ?? '');
$term_text   = trim($_GET['term_text']   ?? '');
$printed_at  = $_GET['printed_at'] ?? date('Y-m-d');
$export      = $_GET['export'] ?? '';           // 'print' → auto-print

/* ── Academic year default ───────────────────────────────────────── */
$years = $pdo->query(
    "SELECT id, year_label, is_active FROM academic_years ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$year_id) {
    foreach ($years as $y) {
        if ($y['is_active']) { $year_id = (int)$y['id']; break; }
    }
    if (!$year_id && $years) $year_id = (int)$years[0]['id'];
}

tt_terms_init($pdo);
if ($term_no > 0) {
    $term_no = tt_validate_term_no($pdo, $year_id, $term_no);
} else {
    $term_no = tt_default_term_no_for_year($pdo, $year_id);
    $term_no = tt_validate_term_no($pdo, $year_id, $term_no);
}

if ($school_name === '') $school_name = tt_app_setting_get($pdo, 'school_name') ?? 'โรงเรียน';

$yearLabel = '';
foreach ($years as $y) {
    if ((int)$y['id'] === $year_id) { $yearLabel = $y['year_label']; break; }
}
if ($year_text === '') $year_text = 'ปีการศึกษา ' . $yearLabel;
if ($term_text === '') {
    $term_text = tt_term_label_from_no($pdo, $year_id, $term_no);
}

/* ── Reference data ──────────────────────────────────────────────── */
$periods = $pdo->query(
    "SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no"
)->fetchAll(PDO::FETCH_ASSOC);

/* ── All classes ordered by grade then section ─────────────────── */
$allClasses = $pdo->query(
    "SELECT id, class_name, class_alias, grade_label, section_no,
            CASE WHEN IFNULL(class_alias,'')='' THEN class_name
                 ELSE CONCAT(grade_label,'/',class_alias) END AS class_display_name
     FROM classes
     ORDER BY grade_label, section_no"
)->fetchAll(PDO::FETCH_ASSOC);

// Group all classes by grade_label
$classesByGrade = [];
foreach ($allClasses as $c) {
    $classesByGrade[$c['grade_label']][] = $c;
}

/* ── Saturday timetable slots (day_of_week = 6) ─────────────────── */
$satSlots   = []; // [class_id][period_no] = row
$satClassIds = []; // class_ids ที่มีคาบวันเสาร์
if ($year_id > 0) {
    $st = $pdo->prepare(
        "SELECT ts.class_id, ts.period_no, ts.subject_name,
                COALESCE(r.room_name, hr.room_name) AS display_room,
                GROUP_CONCAT(t.first_name, ' ', t.last_name ORDER BY t.first_name SEPARATOR ', ') AS teachers
         FROM timetable_slots ts
         JOIN classes c ON c.id = ts.class_id
         LEFT JOIN rooms r  ON r.id = ts.room_id
         LEFT JOIN rooms hr ON hr.id = c.homeroom_room_id
         LEFT JOIN timetable_slot_teachers tst ON tst.slot_id = ts.id
         LEFT JOIN teachers t ON t.id = tst.teacher_id
         WHERE ts.academic_year_id = ? AND ts.term_no = ? AND ts.day_of_week = 6
         GROUP BY ts.class_id, ts.period_no"
    );
    $st->execute([$year_id, $term_no]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $satSlots[(int)$row['class_id']][(int)$row['period_no']] = $row;
        $satClassIds[(int)$row['class_id']] = true;
    }
}

/* ── Logo ────────────────────────────────────────────────────────── */
$logoPath = __DIR__ . '/report_logo.png';
$hasLogo  = file_exists($logoPath);
$logoUrl  = $hasLogo ? 'report_logo.png?v=' . filemtime($logoPath) : '';

/* ── Build pages list ────────────────────────────────────────────── */
// รวมเฉพาะ grade_label ที่มีห้องเรียนที่มีคาบวันเสาร์อย่างน้อย 1 คาบ
// แสดงทุกห้องของระดับชั้นนั้น (บางห้องอาจว่างเปล่า)
$pages = [];
foreach ($classesByGrade as $grade => $classes) {
    // ตรวจว่ามีห้องใดในระดับนี้มีคาบเสาร์บ้าง
    $hasAny = false;
    foreach ($classes as $cls) {
        if (isset($satClassIds[(int)$cls['id']])) { $hasAny = true; break; }
    }
    if (!$hasAny) continue;
    $pages[] = ['grade' => $grade, 'classes' => $classes];
}

$periodCount = count($periods);

/* ═══════════════════════════════════════════════════════════════════
   CSS
═══════════════════════════════════════════════════════════════════ */
$css = <<<'CSS'
@font-face { font-family:'Sarabun'; src:url('fonts/Sarabun-Regular.ttf') format('truetype'); font-weight:400 }
@font-face { font-family:'Sarabun'; src:url('fonts/Sarabun-Bold.ttf')    format('truetype'); font-weight:700 }

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }

body {
    font-family: 'Sarabun', 'Sarabun', system-ui, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

/* ── no-print toolbar ── */
.toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 10px 20px;
    background: #1e293b;
    position: sticky;
    top: 0;
    z-index: 100;
}
.toolbar .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: background .15s;
}
.btn-print  { background: #4f46e5; color:#fff; }
.btn-print:hover { background: #4338ca; }
.btn-back   { background: #475569; color:#fff; }
.btn-back:hover { background: #334155; }
.toolbar .label { color:#94a3b8; font-size:13px; }

/* ── pages wrapper ── */
.pages-wrapper { padding: 20px; }

/* ── one report page ── */
.report-page {
    width: 277mm;
    min-height: 190mm;
    margin: 0 auto 20px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    padding: 10mm 12mm 8mm;
}

/* ── page header ── */
.page-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}
.page-header .logo { height: 56px; width: auto; object-fit: contain; flex-shrink: 0; }
.page-header .header-text { flex: 1; text-align: center; }
.header-school { font-size: 18px; font-weight: 700; line-height: 1.2; }
.header-title  { font-size: 15px; font-weight: 600; margin-top: 2px; }
.header-grade  { font-size: 13px; color: #475569; margin-top: 2px; }

/* ── timetable ── */
.tt {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.tt th, .tt td {
    border: 1px solid #94a3b8;
    padding: 4px 3px;
    vertical-align: middle;
    text-align: center;
}
.tt thead th {
    background: #f1f5f9;
    font-weight: 700;
    font-size: 12px;
}
.tt thead th.class-head {
    width: 72px;
}
.tt tbody th.class-cell {
    background: #f8fafc;
    font-weight: 700;
    font-size: 12px;
    white-space: nowrap;
    text-align: center;
    width: 72px;
}
.tt td {
    min-height: 48px;
    height: 48px;
}
.period-no   { font-weight: 700; line-height: 1.1; font-size: 12px; }
.period-time { font-size: 10px; color: #64748b; line-height: 1.1; margin-top: 2px; }

/* cell content */
.cell-inner { display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 44px; gap: 2px; }
.subj { font-size: 11px; font-weight: 700; line-height: 1.2; }
.teacher { font-size: 10px; color: #475569; line-height: 1.2; }

/* footer */
.page-footer {
    display: flex;
    justify-content: space-between;
    margin-top: 6px;
    font-size: 11px;
    color: #64748b;
}

/* ════ PRINT ════ */
@media print {
    @page { size: A4 landscape; margin: 6mm; }
    html, body {
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .toolbar { display: none !important; }
    .pages-wrapper { padding: 0; }
    .report-page {
        width: 100%;
        margin: 0;
        padding: 5mm 6mm 4mm;
        border-radius: 0;
        box-shadow: none;
        page-break-after: always;
        break-after: page;
    }
    .report-page:last-child { page-break-after: auto; break-after: auto; }
    .tt th, .tt td { border-color: #000 !important; }
    .tt thead th { background: #e8e8e8 !important; }
    .tt tbody th.class-cell { background: #f0f0f0 !important; }
    .header-school { font-size: 16px !important; }
    .header-title  { font-size: 14px !important; }
}
CSS;

/* ═══════════════════════════════════════════════════════════════════
   Render
═══════════════════════════════════════════════════════════════════ */
ob_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ตารางเรียนเสริม วันเสาร์ – <?= h($school_name) ?></title>
<style><?= $css ?></style>
</head>
<body>

<!-- ── Toolbar (screen only) ── -->
<div class="toolbar noprint">
  <button class="btn btn-print" onclick="window.print()">🖨️ พิมพ์</button>
  <a class="btn btn-back" href="report.php?<?= h(http_build_query(array_filter([
      'year_id'     => $year_id,
      'term_no'     => $term_no,
      'school_name' => $school_name,
      'year_text'   => $year_text,
      'term_text'   => $term_text,
      'printed_at'  => $printed_at,
  ]))) ?>">← กลับ</a>
  <span class="label">ตารางเรียนเสริม วันเสาร์ – <?= h($year_text) ?> <?= h($term_text) ?></span>
</div>

<div class="pages-wrapper">
<?php if (empty($pages)): ?>
  <div style="text-align:center;padding:60px;color:#64748b;">
    <p style="font-size:20px;margin-bottom:8px;">ไม่พบตารางสอนวันเสาร์</p>
    <p>ยังไม่มีคาบเรียนที่จัดลงวันเสาร์ในปีการศึกษา/เทอมที่เลือก</p>
  </div>
<?php else: ?>

<?php foreach ($pages as $pgIdx => $pg):
    $grade   = $pg['grade'];
    $classes = $pg['classes'];
?>
  <div class="report-page">

    <!-- header -->
    <div class="page-header">
      <?php if ($hasLogo): ?>
        <img class="logo" src="<?= h($logoUrl) ?>" alt="logo">
      <?php endif; ?>
      <div class="header-text">
        <div class="header-school"><?= h($school_name) ?></div>
        <div class="header-title">ตารางเรียนเสริม วันเสาร์</div>
        <div class="header-grade">ชั้น <?= h(gradeFullName($grade)) ?></div>
      </div>
    </div>

    <!-- timetable -->
    <table class="tt">
      <thead>
        <tr>
          <th class="class-head">ห้อง</th>
          <?php foreach ($periods as $p): ?>
            <th>
              <div class="period-no">คาบที่ <?= (int)$p['period_no'] ?></div>
              <div class="period-time"><?= substr($p['start_time'],0,5) ?>–<?= substr($p['end_time'],0,5) ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classes as $cls): ?>
          <tr>
            <th class="class-cell"><?= h($cls['class_display_name'] ?: $cls['class_name']) ?></th>
            <?php foreach ($periods as $p):
              $pno  = (int)$p['period_no'];
              $slot = $satSlots[(int)$cls['id']][$pno] ?? null;
            ?>
              <td>
                <?php if ($slot): ?>
                  <div class="cell-inner">
                    <div class="subj"><?= h($slot['subject_name']) ?></div>
                    <?php if (!empty($slot['teachers'])): ?>
                      <div class="teacher"><?= h($slot['teachers']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- footer -->
    <div class="page-footer">
      <span><?= h($year_text) ?><?= $term_text ? ' · '.h($term_text) : '' ?></span>
      <span>พิมพ์ ณ วันที่ <?= h(thaidate_sat($printed_at)) ?></span>
    </div>

  </div><!-- .report-page -->
<?php endforeach; ?>

<?php endif; ?>
</div><!-- .pages-wrapper -->

<?php if ($export === 'print'): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>

</body>
</html>
<?php
echo ob_get_clean();
