<?php
/**
 * Index - หน้าแรกของระบบ
 * Redirect ไปหน้า login หรือ dashboard
 */

require_once __DIR__ . '/config/config.php';

$__ttBase = rtrim((string)BASE_URL, '/');
$__ttBase = $__ttBase === '' ? '' : $__ttBase;

// ตรวจสอบว่า login แล้วหรือยัง
if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    // ถ้า login แล้ว ไปหน้า dashboard
    header('Location: ' . $__ttBase . '/public/index.php');
} else {
    // ถ้ายัง ไปหน้า login
    header('Location: ' . $__ttBase . '/public/login.php');
}
exit;
