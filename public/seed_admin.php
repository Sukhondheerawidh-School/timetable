<?php
// ป้องกันการเรียกจาก Web – ไฟล์นี้ใช้ได้เฉพาะ CLI เท่านั้น
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  die('403 Forbidden — ไฟล์นี้รันได้เฉพาะทาง CLI เท่านั้น กรุณาใช้คำสั่ง: php public/seed_admin.php');
}

require_once __DIR__ . '/../app/db.php';

$name = 'Administrator';
$username = 'admin';
$plain = '12345678';
$hash = password_hash($plain, PASSWORD_BCRYPT);

try {
  $stmt = $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)');
  $stmt->execute([$name, $username, $hash, 'admin']);
  echo "Seed admin สำเร็จ!<br>Username: {$username}<br>Password: {$plain}<br><br>ควรลบไฟล์นี้ทันที (public/seed_admin.php)";
} catch (Throwable $e) {
  echo "เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage());
}
