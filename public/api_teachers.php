<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$group = isset($_GET['group']) && $_GET['group'] !== '' ? (int)$_GET['group'] : null;

$sql = 'SELECT id, first_name, last_name FROM teachers';
$params = [];
if ($group !== null) {
  $sql .= ' WHERE subject_group = :grp';
  $params['grp'] = $group;
}
$sql .= ' ORDER BY first_name, last_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);