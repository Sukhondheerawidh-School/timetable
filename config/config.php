<?php
// Base URL ของเว็บ (ซ่อน /public ด้วย .htaccess)
define('BASE_URL', '/timetable');

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
