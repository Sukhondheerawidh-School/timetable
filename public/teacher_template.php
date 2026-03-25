<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
requireLogin();
requireAdmin();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="teacher_template.csv"');

// BOM เพื่อให้ Excel แสดงภาษาไทยถูกต้อง
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

/*
หัวตารางรองรับกับตัวนำเข้าที่แก้ไว้:
- รหัสประจำตัว / teacher_code
- คำนำหน้า / title
- ชื่อ / first_name
- นามสกุล / last_name
- กลุ่มสาระ / subject_group  (ใส่ 1-9 หรือใส่คำ เช่น "คณิตศาสตร์", "วิทยาศาสตร์และเทคโนโลยี", "อื่นๆ")
*/
fputcsv($out, ['รหัสประจำตัว','คำนำหน้า','ชื่อ','นามสกุล','กลุ่มสาระ']);

// ตัวอย่าง (ลบได้)
fputcsv($out, ['t00400','นาย','สุทา','โร','คณิตศาสตร์']);

fclose($out);
exit;
