<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error','คำขอไม่ถูกต้อง');
  redirect('teachers.php');
}

$id = (int)($_POST['id'] ?? 0);
try {
  $oldStmt = $pdo->prepare('SELECT * FROM teachers WHERE id = ?');
  $oldStmt->execute([$id]);
  $oldRow = $oldStmt->fetch();
  $oldBuildings = tt_teacher_buildings_get($pdo, $id);

  $stmt = $pdo->prepare('DELETE FROM teachers WHERE id = ?');
  $stmt->execute([$id]);

  if ($oldRow) {
    $oldRow['building_ids'] = $oldBuildings;
    logDelete('teachers', $id, $oldRow);
  }
  flash_set('success', 'ลบข้อมูลครูสำเร็จ');
} catch (Throwable $e) {
  flash_set('error', 'ลบไม่สำเร็จ: '.$e->getMessage());
}
$_from_q     = trim($_POST['from_q']     ?? '');
$_from_group = trim($_POST['from_group'] ?? '');
$_back_parts = [];
if ($_from_q !== '') $_back_parts['q'] = $_from_q;
if ($_from_group !== '') $_back_parts['group'] = (int)$_from_group;
$_back_qs = $_back_parts ? '?' . http_build_query($_back_parts) : '';
redirect('teachers.php'.$_back_qs);
