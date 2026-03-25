<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
requireLogin(); requireAdmin();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="subject_template.csv"');
echo "\xEF\xBB\xBF"; // BOM for Excel

$out = fopen('php://output', 'w');
fputcsv($out, ['รหัสวิชา','ชื่อวิชา']);
fputcsv($out, ['ว12101','วิทยาศาสตร์ 1']);
fputcsv($out, ['ค12101','คณิตศาสตร์ 1']);
fclose($out); exit;
