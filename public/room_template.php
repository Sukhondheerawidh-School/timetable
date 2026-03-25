<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
requireLogin(); requireAdmin();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="room_template.csv"');
echo "\xEF\xBB\xBF"; // BOM

$out = fopen('php://output', 'w');
fputcsv($out, ['รหัสห้อง','ชื่อห้อง','อาคาร','ประเภท']);   // หัวตารางไทย
fputcsv($out, ['12101','ห้องคอมพิวเตอร์ 1','อาคาร 1','ห้องปฏิบัติการ']); // ตัวอย่าง
fclose($out); exit;
