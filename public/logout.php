<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

// ถือว่าอยู่ในบริบท SchoolOS เมื่อ session มาจาก SSO หรือมี portal token ติดมากับเบราว์เซอร์
// (ครอบคลุมเคส login มือใน Slot ไว้ก่อน แล้วเข้าใช้งานผ่าน gateway ทีหลัง)
$viaSchoolOS = !empty($_SESSION['sso_login']) || !empty($_COOKIE['schoolos_token']);

logout();

if ($viaSchoolOS) {
  // ลบ token ของ portal ด้วย (logout = ออกจาก SchoolOS ทั้งระบบ)
  // ไม่งั้น token ที่ยังดีอยู่จะ auto-login กลับเข้ามาทันที
  setcookie('schoolos_token', '', time() - 3600, '/');
  header('Location: /login');
  exit;
}

// ใช้งานแบบ standalone (เข้าตรง ไม่มี token): ไปหน้า login ของ Slot ตามเดิม
session_start();
$_SESSION['sso_skip'] = 1;
redirect('login.php');
