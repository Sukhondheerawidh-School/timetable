<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

tt_teachers_init($pdo);

// ระดับชั้นที่สอน: auto (จากตารางสอนปีปัจจุบัน) + manual (ติ๊กเอง)
$activeYearId = tt_active_year_id($pdo);
$gradeMaps    = tt_teacher_grade_levels_maps($pdo, $activeYearId);
$gradeOrder   = tt_grade_levels_all($pdo);
$gradeRank    = array_flip($gradeOrder);

// ดึงครูทั้งหมด
$stmt = $pdo->query(
    'SELECT id, teacher_code, national_id, username, title, first_name, last_name, email, password_plain, subject_group
     FROM teachers
     ORDER BY teacher_code, first_name, last_name'
);
$teachers = $stmt->fetchAll();

// ส่ง CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="teachers_list_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// BOM สำหรับ Excel (รองรับภาษาไทย)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row (ตาม layout รายชื่อครู)
fputcsv($out, ['ลำดับ', 'รหัสบัตรประชาชน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'รหัสครูผู้สอน', 'Username', 'Password', 'Email', 'ชั้นที่สอน', 'กลุ่มสาระที่สอน']);

// Data rows
$i = 0;
foreach ($teachers as $t) {
    $i++;
    $tid     = (int)$t['id'];
    $autoG   = $gradeMaps['auto'][$tid]   ?? [];
    $manualG = $gradeMaps['manual'][$tid] ?? [];
    $allG    = array_values(array_unique(array_merge($autoG, $manualG)));
    usort($allG, fn($a, $b) => ($gradeRank[$a] ?? 999) <=> ($gradeRank[$b] ?? 999));

    fputcsv($out, [
        $i,
        $t['national_id'] ?? '',
        $t['title'],
        $t['first_name'],
        $t['last_name'],
        $t['teacher_code'],
        $t['username'] ?? '',
        $t['password_plain'] ?? '',
        $t['email'] ?? '',
        implode(', ', $allG),
        teacher_group_label(isset($t['subject_group']) ? (int)$t['subject_group'] : null),
    ]);
}

fclose($out);
exit;
