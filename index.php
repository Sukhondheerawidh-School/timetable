<?php
/**
 * Index - หน้าแรกของระบบ
 * Redirect ไปหน้า login หรือ dashboard
 */

require_once __DIR__ . '/config/config.php';

// ตรวจสอบว่า login แล้วหรือยัง
if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    // ถ้า login แล้ว ไปหน้า dashboard
    header('Location: /timetable/public/index.php');
} else {
    // ถ้ายัง ไปหน้า login
    header('Location: /timetable/public/login.php');
}
exit;
