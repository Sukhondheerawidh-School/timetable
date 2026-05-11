<?php
// Base URL ของเว็บ (ซ่อน /public ด้วย .htaccess)
// ตั้งค่าได้ 2 แบบ:
// 1) แนะนำ (prod): ตั้ง env var `TT_BASE_URL` เช่น "/" หรือ "/timetable"
// 2) ไม่ตั้ง: ระบบจะพยายามเดาจาก SCRIPT_NAME และตัดท้าย "/public" ออก
$__ttBaseUrl = (string)(getenv('TT_BASE_URL') ?: '');
$__ttBaseUrl = trim($__ttBaseUrl);
if ($__ttBaseUrl === '') {
	$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
	$dir = str_replace('\\', '/', dirname($scriptName));
	$dir = rtrim($dir, '/');
	if ($dir === '/' || $dir === '.') $dir = '';
	if (substr($dir, -7) === '/public') {
		$dir = substr($dir, 0, -7);
	}
	$__ttBaseUrl = $dir;
}
if ($__ttBaseUrl !== '' && $__ttBaseUrl[0] !== '/') {
	$__ttBaseUrl = '/' . $__ttBaseUrl;
}
$__ttBaseUrl = rtrim($__ttBaseUrl, '/');
define('BASE_URL', $__ttBaseUrl);

// DB
// แนะนำ: ตั้งค่าเป็น Environment Variables แทนการเขียน user/pass ลงไฟล์
// - TT_DB_HOST, TT_DB_NAME, TT_DB_USER, TT_DB_PASS
// (ใน XAMPP/Apache สามารถตั้งผ่าน httpd.conf / vhost ด้วย SetEnv)
$__ttDbDriver = (string)(getenv('TT_DB_DRIVER') ?: 'mysql');
$__ttDbHost = (string)(getenv('TT_DB_HOST') ?: '127.0.0.1');
$__ttDbName = (string)(getenv('TT_DB_NAME') ?: 'timetable_app');
$__ttDbUser = (string)(getenv('TT_DB_USER') ?: 'root');
$__ttDbPass = (string)(getenv('TT_DB_PASS') ?: ''); 

define('DB_DRIVER', $__ttDbDriver);
define('DB_HOST', $__ttDbHost);
define('DB_NAME', $__ttDbName);
define('DB_USER', $__ttDbUser);
define('DB_PASS', $__ttDbPass);

// ตั้งค่า session
ini_set('session.cookie_httponly', 1);
// เปิด secure cookie เฉพาะเมื่อเชื่อมต่อผ่าน HTTPS
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  ini_set('session.cookie_secure', 1);
}
session_name('tt_sess');
session_start();

// timezone
date_default_timezone_set('Asia/Bangkok');

// Security headers (ส่งก่อน output ใด ๆ)
if (!headers_sent()) {
  header('X-Frame-Options: SAMEORIGIN');
  header('X-Content-Type-Options: nosniff');
  header('X-XSS-Protection: 1; mode=block');
  header('Referrer-Policy: strict-origin-when-cross-origin');
}

// error: ซ่อนใน production, แสดงใน development
$_isDev = (strtolower((string)(getenv('APP_ENV') ?: 'production')) === 'development');
error_reporting($_isDev ? E_ALL : 0);
ini_set('display_errors', $_isDev ? '1' : '0');
unset($_isDev);
