<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error','คำขอไม่ถูกต้อง'); redirect('classes.php');
}

$id = (int)($_POST['id'] ?? 0);
try {
  $stmt = $pdo->prepare('DELETE FROM classes WHERE id = ?');
  $stmt->execute([$id]);
  // class_teachers จะโดนลบตามด้วย (ON DELETE CASCADE)
  flash_set('success','ลบชั้นเรียนสำเร็จ');
} catch (Throwable $e) {
  flash_set('error','ลบไม่สำเร็จ: '.$e->getMessage());
}
redirect('classes.php');
