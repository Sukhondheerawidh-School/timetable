<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// เริ่ม session ถ้ายังไม่มี
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ตั้งค่า Session Timeout = 30 นาที
define('SESSION_TIMEOUT', 1800);

// ฟังก์ชันตรวจสอบ Session Timeout
function checkSessionTimeout() {
  if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed > SESSION_TIMEOUT) {
      // Session หมดอายุ
      $_SESSION = [];
      session_destroy();
      session_start();
      $_SESSION['timeout_message'] = 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
      header('Location: ' . url('login.php'));
      exit;
    }
  }
  // อัพเดทเวลาล่าสุด
  $_SESSION['LAST_ACTIVITY'] = time();
}

function login($username, $password) {
  global $pdo;
  $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
  $stmt->execute([$username]);
  $user = $stmt->fetch();
  if ($user && password_verify($password, $user['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['user'] = [
      'id'       => $user['id'],
      'username' => $user['username'],
      'role'     => $user['role'],
    ];
    $_SESSION['LAST_ACTIVITY'] = time(); // เพิ่มบรรทัดนี้
    return true;
  }
  return false;
}

function logout() {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

function isLoggedIn() { 
  checkSessionTimeout(); // เพิ่มบรรทัดนี้
  return !empty($_SESSION['user']); 
}

function currentUser() { return $_SESSION['user'] ?? null; }

function requireLogin() {
  if (!isLoggedIn()) {
    header('Location: ' . url('login.php'));
    exit;
  }
}

function requireAdmin() {
  requireLogin();
  $u = currentUser();
  if (!$u || $u['role'] !== 'admin') {
    http_response_code(403);
    die('403 Forbidden');
  }
}
