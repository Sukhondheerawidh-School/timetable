<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error', 'คำขอไม่ถูกต้อง');
  redirect('users.php');
}

$id = (int)($_POST['id'] ?? 0);

// กันลบตัวเอง
if ($id === (int)currentUser()['id']) {
  flash_set('error', 'ไม่สามารถลบบัญชีของตัวเองได้');
  redirect('users.php');
}

try {
  $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
  $stmt->execute([$id]);
  flash_set('success', 'ลบผู้ใช้สำเร็จ');
} catch (Throwable $e) {
  flash_set('error', 'ลบไม่สำเร็จ: '.$e->getMessage());
}

redirect('users.php');
