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
- ชื่อภาษาอังกฤษ / first_name_en  (ไม่บังคับ)
- นามสกุลภาษาอังกฤษ / last_name_en  (ไม่บังคับ)
- อีเมล / email  (ไม่บังคับ)
- รหัสผ่าน / password  (ไม่บังคับ · ระบบจะเข้ารหัสให้อัตโนมัติ · เว้นว่างเมื่ออัปเดต = คงรหัสเดิม)
- กลุ่มสาระ / subject_group  (ใส่ 1-9 หรือใส่คำ เช่น "คณิตศาสตร์", "วิทยาศาสตร์และเทคโนโลยี", "อื่นๆ")

หมายเหตุ: ถ้ารหัสประจำตัวซ้ำกับที่มีอยู่ ระบบจะอัปเดตข้อมูลให้
*/
fputcsv($out, ['รหัสประจำตัว','คำนำหน้า','ชื่อ','นามสกุล','ชื่อภาษาอังกฤษ','นามสกุลภาษาอังกฤษ','อีเมล','รหัสผ่าน','กลุ่มสาระ']);

// ตัวอย่าง (ลบได้)
fputcsv($out, ['t00400','นาย','สุทา','โร','Sutha','Ro','sutha@example.com','changeme123','คณิตศาสตร์']);

fclose($out);
exit;
