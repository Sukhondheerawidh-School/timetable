<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

// ดึงกลุ่มสาระทั้งหมด (รวม inactive) เพื่อ map label
$grpStmt = $pdo->query('SELECT id, name FROM subject_groups ORDER BY display_order, name');
$grpMap = [];
foreach ($grpStmt->fetchAll() as $r) {
    $grpMap[(int)$r['id']] = $r['name'];
}

// ดึงครูทั้งหมด
$stmt = $pdo->query(
    'SELECT teacher_code, title, first_name, last_name, subject_group
     FROM teachers
     ORDER BY teacher_code, first_name, last_name'
);
$teachers = $stmt->fetchAll();

// ส่ง CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="teachers_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// BOM สำหรับ Excel (รองรับภาษาไทย)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['รหัสประจำตัว', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'กลุ่มสาระ']);

// Data rows
foreach ($teachers as $t) {
    $grpName = isset($t['subject_group']) && $t['subject_group']
        ? ($grpMap[(int)$t['subject_group']] ?? '—')
        : '—';
    fputcsv($out, [
        $t['teacher_code'],
        $t['title'],
        $t['first_name'],
        $t['last_name'],
        $grpName,
    ]);
}

fclose($out);
exit;
