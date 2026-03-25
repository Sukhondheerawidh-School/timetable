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
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'timetable_app');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP ปกติว่าง

// ตั้งค่า session
ini_set('session.cookie_httponly', 1);
session_name('tt_sess');
session_start();

// timezone
date_default_timezone_set('Asia/Bangkok');

// error (ช่วง dev)
error_reporting(E_ALL);
ini_set('display_errors', 1);
