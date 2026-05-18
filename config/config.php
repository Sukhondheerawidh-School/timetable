<?php
// Buffer output ตั้งแต่ต้น เพื่อป้องกัน PHP notice/warning รั่วออกมาก่อน DOCTYPE
ob_start();

// โหลดไฟล์ .env ถ้ามี (สำหรับ local development)
$__envFile = dirname(__DIR__) . '/.env';
if (is_file($__envFile)) {
    $__lines = file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($__lines as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#') continue;
        if (strpos($__line, '=') === false) continue;
        [$__key, $__val] = explode('=', $__line, 2);
        $__key = trim($__key);
        $__val = trim($__val);
        if ($__key !== '' && !getenv($__key)) {
            putenv("$__key=$__val");
        }
    }
    unset($__lines, $__line, $__key, $__val);
}
unset($__envFile);

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
$__ttDbDriver = (string)(getenv('TT_DB_DRIVER') ?: '');
$__ttDbHost = (string)(getenv('TT_DB_HOST') ?: '');
$__ttDbName = (string)(getenv('TT_DB_NAME') ?: '');
$__ttDbUser = (string)(getenv('TT_DB_USER') ?: '');
$__ttDbPass = (string)(getenv('TT_DB_PASS') ?: '');

define('DB_DRIVER', $__ttDbDriver);
define('DB_HOST', $__ttDbHost);
define('DB_NAME', $__ttDbName);
define('DB_USER', $__ttDbUser);
define('DB_PASS', $__ttDbPass);

// ตั้งค่า session - ข้ามหากถูก start ไปแล้ว (เช่น session.auto_start = 1 ใน php.ini)
if (session_status() === PHP_SESSION_NONE) {
  ini_set('session.cookie_httponly', 1);
  // เปิด secure cookie เฉพาะเมื่อเชื่อมต่อผ่าน HTTPS
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
  }
  session_name('tt_sess');
  session_start();
}

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
