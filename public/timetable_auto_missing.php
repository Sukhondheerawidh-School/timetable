<?php
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/helpers.php';
require_once __DIR__.'/../app/db.php';
requireLogin();
$isAdmin = (currentUser()['role'] ?? '') === 'admin';

$years = $pdo->query('SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC')->fetchAll();

// default year: active year if exists else first
$defaultYearId = 0;
foreach ($years as $y) {
  if (!empty($y['is_active'])) { $defaultYearId = (int)$y['id']; break; }
}
if (!$defaultYearId && !empty($years)) $defaultYearId = (int)$years[0]['id'];

$year_id = (int)($_GET['year_id'] ?? $defaultYearId);
$term_no = isset($_GET['term_no']) && $_GET['term_no'] !== ''
  ? (int)$_GET['term_no']
  : tt_default_term_no_for_year($pdo, $year_id);
$term_no = tt_validate_term_no($pdo, $year_id, $term_no);
$termOptions = tt_terms_list($pdo, $year_id);
$print = (int)($_GET['print'] ?? 0);

if (!$year_id) die('ไม่ระบุปีการศึกษา');

$sql = <<<SQL
WITH loads AS (
  SELECT
    tl.class_id,
    tl.subject_id,
    c.class_name,
    c.grade_label,
    s.subject_name AS raw_name,
    CASE WHEN IFNULL(s.subject_code,'')='' THEN s.subject_name
         ELSE CONCAT(s.subject_code,' - ',s.subject_name) END AS label,
    MAX(CAST(tl.periods_per_week AS SIGNED)) AS quota,
    GROUP_CONCAT(DISTINCT CONCAT(t.first_name,' ',t.last_name)
                 ORDER BY t.first_name, t.last_name SEPARATOR ', ') AS teacher_names
  FROM teaching_loads tl
  JOIN subjects s ON s.id = tl.subject_id
  JOIN classes  c ON c.id = tl.class_id
  JOIN teachers t ON t.id = tl.teacher_id
  WHERE tl.academic_year_id = :y AND tl.term_no = :t
  GROUP BY tl.class_id, tl.subject_id, c.class_name, c.grade_label, s.subject_name, label
),
used AS (
  SELECT
    ts.class_id,
    ts.subject_name,
    COUNT(DISTINCT ts.id) AS used_cnt
  FROM timetable_slots ts
  WHERE ts.academic_year_id = :y AND ts.term_no = :t
  GROUP BY ts.class_id, ts.subject_name
)
SELECT
  l.class_id,
  l.subject_id,
  l.class_name,
  l.grade_label,
  l.label,
  l.teacher_names,
  COALESCE(u1.used_cnt, 0) + COALESCE(u2.used_cnt, 0) AS used_cnt,
  l.quota,
  GREATEST(l.quota - COALESCE(u1.used_cnt, 0) - COALESCE(u2.used_cnt, 0), 0) AS remain
FROM loads l
LEFT JOIN used u1 ON u1.class_id = l.class_id AND u1.subject_name = l.label
LEFT JOIN used u2 ON u2.class_id = l.class_id AND u2.subject_name = l.raw_name AND l.label <> l.raw_name
WHERE GREATEST(l.quota - COALESCE(u1.used_cnt, 0) - COALESCE(u2.used_cnt, 0), 0) > 0
ORDER BY l.class_name, remain DESC, l.label
SQL;

$st = $pdo->prepare($sql);
$st->execute(['y' => $year_id, 't' => $term_no]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$totalRemain = 0;
foreach ($rows as $r) $totalRemain += (int)$r['remain'];

$yearLabel = '';
foreach ($years as $y) {
  if ((int)$y['id'] === $year_id) { $yearLabel = (string)$y['year_label']; break; }
}

if ($print) {
  header('Content-Type: text/html; charset=utf-8');
  $now = date('Y-m-d H:i');
  ?>
  <!doctype html>
  <html lang="th">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>พิมพ์รายงานวิชาที่ยังลงไม่ได้</title>
    <style>
      body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color:#0f172a; }
      .wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
      h1 { margin: 0 0 6px 0; font-size: 20px; }
      .meta { font-size: 12px; color: #475569; margin-bottom: 12px; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th, td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
      th { background: #f1f5f9; text-align: left; }
      td.num, th.num { text-align: right; white-space: nowrap; }
      .badge { display:inline-block; padding:2px 6px; border:1px solid #fecaca; background:#fff1f2; color:#be123c; border-radius: 8px; font-weight: 700; }
      .note { margin-top: 10px; font-size: 11px; color:#475569; }
      @media print {
        @page { size: A4; margin: 10mm; }
        .no-print { display: none !important; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      }
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="no-print" style="display:flex; gap:8px; justify-content:flex-end; margin-bottom:10px;">
        <button onclick="window.print()" style="padding:8px 12px;border:1px solid #cbd5e1;background:#0f172a;color:white;border-radius:8px;cursor:pointer;">พิมพ์</button>
        <button onclick="window.close()" style="padding:8px 12px;border:1px solid #cbd5e1;background:white;border-radius:8px;cursor:pointer;">ปิดแท็บ</button>
      </div>

      <h1>รายงานวิชาที่ยังลงไม่ได้</h1>
      <div class="meta">
        ปีการศึกษา: <strong><?= htmlspecialchars($yearLabel ?: (string)$year_id) ?></strong>
        | เทอม: <strong><?= (int)$term_no ?></strong>
        | พิมพ์เมื่อ: <strong><?= htmlspecialchars($now) ?></strong>
        | รายการคงเหลือ: <strong><?= number_format(count($rows)) ?></strong>
        | คาบที่ยังขาดรวม: <strong><?= number_format($totalRemain) ?></strong>
      </div>

      <table>
        <thead>
          <tr>
            <th style="width:120px;">ห้อง</th>
            <th>วิชา</th>
            <th style="width:220px;">ครู</th>
            <th class="num" style="width:130px;">คาบที่ยังขาด</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="4" style="text-align:center; padding:18px; color:#047857;">✅ ไม่มีรายการคงเหลือ (ลงครบแล้ว)</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= htmlspecialchars($r['class_name']) ?></strong></td>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td><?= htmlspecialchars($r['teacher_names'] ?? '-') ?></td>
                <td class="num">ยังขาด <span class="badge"><?= (int)$r['remain'] ?></span> คาบ</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="note">หมายเหตุ: รายงานนี้แสดงเฉพาะรายการที่คงเหลือ (remain &gt; 0) สำหรับปี/เทอมที่เลือก</div>
    </div>
    <script>
      // พิมพ์อัตโนมัติเมื่อเปิดแท็บ
      window.addEventListener('load', () => setTimeout(() => window.print(), 250));
    </script>
  </body>
  </html>
  <?php
  exit;
}

include __DIR__.'/../partials/head.php';
include __DIR__.'/../partials/navbar.php';
?>

<div class="max-w-7xl mx-auto px-4 mt-8">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-xl font-semibold">📌 รายงานวิชาที่ยังลงไม่ได้</h1>
    <div class="flex gap-2">
      <?php if ($isAdmin): ?>
        <a class="px-3 py-2 rounded border hover:bg-slate-50" href="<?= url('timetable_auto_dashboard.php?year_id='.$year_id.'&term_no='.$term_no) ?>">กลับไปหน้าอัตโนมัติ</a>
      <?php endif; ?>
      <a class="px-3 py-2 rounded border hover:bg-slate-50" href="<?= url('timetable.php?view=class&year_id='.$year_id.'&term_no='.$term_no) ?>">ไปตารางสอน</a>
    </div>
  </div>

  <form method="get" class="bg-white rounded-2xl shadow p-4 mb-4 grid grid-cols-1 md:grid-cols-12 gap-3">
    <div class="md:col-span-5">
      <label class="block text-xs mb-1">ปีการศึกษา</label>
      <select name="year_id" class="w-full border rounded px-3 py-2">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y['id'] ?>" <?= $year_id===(int)$y['id']?'selected':''; ?>>
            <?= htmlspecialchars($y['year_label']) ?><?= $y['is_active']?' (ใช้งาน)':'' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-3">
      <label class="block text-xs mb-1">เทอม</label>
      <select name="term_no" class="w-full border rounded px-3 py-2">
        <?php foreach ($termOptions as $t): ?>
          <option value="<?= (int)$t['term_no']; ?>" <?= ((int)$t['term_no'] === (int)$term_no) ? 'selected' : ''; ?>><?= htmlspecialchars($t['term_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-4 flex items-end gap-2">
      <button class="px-4 py-2 rounded bg-slate-900 text-white hover:bg-slate-800" type="submit">ดูรายงาน</button>
      <a class="px-4 py-2 rounded border hover:bg-slate-50" href="<?= url('timetable_auto_missing.php?year_id='.$year_id.'&term_no='.$term_no) ?>">รีเฟรช</a>
    </div>
  </form>

  <div class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <div class="text-sm text-slate-600">รายการที่ยังขาด</div>
        <div class="text-2xl font-bold text-slate-900"><?= number_format(count($rows)) ?></div>
        <div class="text-xs text-slate-500">(ห้อง × วิชา)</div>
      </div>
      <div>
        <div class="text-sm text-slate-600">คาบที่ยังขาดรวม</div>
        <div class="text-2xl font-bold text-rose-600"><?= number_format($totalRemain) ?></div>
        <div class="text-xs text-slate-500">คาบ</div>
      </div>
      <?php if ($isAdmin): ?>
      <div>
        <div class="text-sm text-slate-600">พิมพ์</div>
        <div class="flex flex-wrap gap-2 items-center">
          <a target="_blank" rel="noopener" class="px-3 py-2 rounded bg-slate-900 text-white hover:bg-slate-800" href="<?= url('timetable_auto_missing.php?year_id='.$year_id.'&term_no='.$term_no.'&print=1') ?>">🖨️ พิมพ์รายการคงเหลือ</a>
          <div class="text-xs text-slate-500">เปิดแท็บใหม่เพื่อพิมพ์</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-4 py-3 font-medium border-b flex items-center justify-between gap-3">
      <span>รายการที่ยังลงไม่ครบ (remain &gt; 0)</span>
      <?php if ($isAdmin): ?>
        <a target="_blank" rel="noopener" class="px-3 py-2 rounded border hover:bg-slate-50" href="<?= url('timetable_auto_missing.php?year_id='.$year_id.'&term_no='.$term_no.'&print=1') ?>">🖨️ พิมพ์</a>
      <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-3 py-2 font-semibold">ห้อง</th>
            <th class="text-left px-3 py-2 font-semibold">วิชา</th>
            <th class="text-left px-3 py-2 font-semibold">ครู</th>
            <th class="text-center px-3 py-2 font-semibold">คาบที่ยังขาด</th>
            <th class="px-3 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="5" class="px-3 py-8 text-center text-emerald-700 bg-emerald-50">
                ✅ ไม่มีรายการคงเหลือ (ลงครบแล้ว)
              </td>
            </tr>
          <?php else: ?>
            <?php foreach($rows as $r): ?>
              <tr class="border-t hover:bg-slate-50">
                <td class="px-3 py-2 font-medium"><?= htmlspecialchars($r['class_name']) ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($r['label']) ?></td>
                <td class="px-3 py-2 text-slate-700"><?= htmlspecialchars($r['teacher_names'] ?? '-') ?></td>
                <td class="px-3 py-2 text-center">
                  ยังขาด <span class="inline-flex px-2 py-0.5 rounded-lg bg-rose-50 text-rose-700 border border-rose-200 font-semibold"><?= (int)$r['remain'] ?></span> คาบ
                </td>
                <td class="px-3 py-2 text-center">
                  <a class="text-indigo-600 hover:underline text-sm" href="<?= url('timetable.php?view=class&year_id='.$year_id.'&term_no='.$term_no.'&class_id='.(int)$r['class_id']) ?>">ไปจัดตาราง →</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
