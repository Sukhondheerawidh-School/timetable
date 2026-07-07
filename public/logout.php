<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

$viaSSO = !empty($_SESSION['sso_login']);

logout();

if ($viaSSO) {
  // เข้ามาแบบ SSO: ลบ token ของ portal ด้วย ไม่งั้นจะถูก auto-login กลับทันที
  // (logout จากแอปนี้ = ออกจาก SchoolOS ทั้งระบบ)
  setcookie('schoolos_token', '', time() - 3600, '/');
  header('Location: /login');
  exit;
}

// login แบบ local ปกติ: กันไม่ให้ token ของ portal (ถ้ามีค้าง) ดึงกลับเข้าเอง
session_start();
$_SESSION['sso_skip'] = 1;
redirect('login.php');
