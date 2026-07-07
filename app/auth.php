<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/sso.php';

// เริ่ม session ถ้ายังไม่มี
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ตั้งค่า Session Timeout = 30 นาที
define('SESSION_TIMEOUT', 1800);

// Rate Limiting: สูงสุด 5 ครั้ง ใน 15 นาที → ล็อก 15 นาที
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 900); // 15 นาที

/**
 * ดึง Client IP (รองรับ proxy)
 */
function get_client_ip(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  // รับเฉพาะ IP ที่ valid, ไม่ใช้ X-Forwarded-For จาก untrusted proxy
  return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * ตรวจสอบว่า IP ถูกล็อกอยู่หรือไม่
 * คืนค่า: 0 = ปกติ, >0 = เหลือเวลาล็อก (วินาที)
 */
function login_rate_check(): int {
  global $pdo;
  $ip = get_client_ip();
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_rate_limit (
      ip VARCHAR(45) NOT NULL,
      attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
      locked_until INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at INT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ลบ record เก่า (หมดอายุแล้ว > 1 ชม.)
    $pdo->prepare("DELETE FROM login_rate_limit WHERE locked_until > 0 AND locked_until < ?")
        ->execute([time() - 3600]);

    $st = $pdo->prepare("SELECT attempts, locked_until FROM login_rate_limit WHERE ip = ? LIMIT 1");
    $st->execute([$ip]);
    $row = $st->fetch();

    if (!$row) return 0;

    if ($row['locked_until'] > time()) {
      return (int)($row['locked_until'] - time()); // วินาทีที่เหลือ
    }
    return 0;
  } catch (Throwable $e) {
    return 0; // ถ้า rate limit ล้มเหลว ให้ผ่านได้ (fail-open)
  }
}

/**
 * บันทึกความพยายาม login ที่ล้มเหลว
 */
function login_rate_fail(): void {
  global $pdo;
  $ip = get_client_ip();
  try {
    $now = time();
    $pdo->prepare(
      "INSERT INTO login_rate_limit (ip, attempts, locked_until, updated_at)
       VALUES (?, 1, 0, ?)
       ON DUPLICATE KEY UPDATE
         attempts = IF(locked_until > 0 AND locked_until < VALUES(updated_at), 1, attempts + 1),
         locked_until = IF(attempts + 1 >= ?, ?, IF(locked_until > 0 AND locked_until < VALUES(updated_at), 0, locked_until)),
         updated_at = VALUES(updated_at)"
    )->execute([$ip, $now, LOGIN_MAX_ATTEMPTS, $now + LOGIN_LOCKOUT_SECONDS]);
  } catch (Throwable $e) {
    // ignore
  }
}

/**
 * รีเซ็ต rate limit หลัง login สำเร็จ
 */
function login_rate_reset(): void {
  global $pdo;
  $ip = get_client_ip();
  try {
    $pdo->prepare("DELETE FROM login_rate_limit WHERE ip = ?")->execute([$ip]);
  } catch (Throwable $e) {
    // ignore
  }
}

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

  // ตรวจสอบ rate limit ก่อน
  $remaining = login_rate_check();
  if ($remaining > 0) {
    $mins = (int)ceil($remaining / 60);
    return ['locked' => true, 'wait' => $mins];
  }

  $retried = false;

  while (true) {
    try {
      $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
      $stmt->execute([$username]);
      $user = $stmt->fetch();
      if ($user && password_verify($password, $user['password_hash'])) {
        login_rate_reset(); // ล้าง rate limit
        session_regenerate_id(true);
        $_SESSION['user'] = [
          'id'       => $user['id'],
          'username' => $user['username'],
          'role'     => $user['role'],
        ];
        $_SESSION['LAST_ACTIVITY'] = time();
        unset($_SESSION['sso_skip']); // login มือ = เปิดทาง SSO รอบหน้าอีกครั้ง
        return true;
      }
      login_rate_fail(); // บันทึกว่า login ล้มเหลว
      return false;
    } catch (PDOException $e) {
      $mysqlCode = (int)($e->errorInfo[1] ?? 0);
      $msg = strtolower($e->getMessage());
      $isGoneAway = ($mysqlCode === 2006) || (strpos($msg, 'server has gone away') !== false);
      if (!$retried && $isGoneAway && function_exists('tt_db_reconnect') && tt_db_reconnect()) {
        $retried = true;
        continue;
      }
      return null; // DB error
    }
  }
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
  // ลอง auto-login จาก SchoolOS token ก่อน (ไม่มี/ไม่ผ่าน = เงียบ ไป flow เดิม)
  sso_attempt_login();
  if (!isLoggedIn()) {
    header('Location: ' . url('login.php'));
    exit;
  }
}

function isSuperuser(): bool {
  $u = currentUser();
  return $u !== null && ($u['role'] ?? '') === 'superuser';
}

function requireAdmin() {
  requireLogin();
  $u = currentUser();
  // superuser มีสิทธิ์ทุกอย่างที่ admin มี
  if (!$u || !in_array($u['role'] ?? '', ['admin', 'superuser'], true)) {
    http_response_code(403);
    die('403 Forbidden');
  }
}

function requireSuperuser() {
  requireLogin();
  if (!isSuperuser()) {
    http_response_code(403);
    die('403 Forbidden — เฉพาะ Superuser เท่านั้น');
  }
}

/**
 * ตรวจสอบว่า app_settings key นั้นถูกตั้งเป็น '1' หรือไม่
 */
function isSectionLocked(string $key): bool {
  global $pdo;
  if (!function_exists('tt_app_setting_get')) return false;
  try {
    return tt_app_setting_get($pdo, $key, '0') === '1';
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * ตรวจสอบว่าขณะนี้ระบบปิดการแก้ไข (global) อยู่หรือไม่
 */
function isEditingLocked(): bool {
  return isSectionLocked('editing_locked');
}

/**
 * ตรวจสอบว่าผู้ใช้สามารถแก้ไขข้อมูลใน section นั้นได้หรือไม่
 * superuser แก้ไขได้เสมอ
 * @param string $section 'timetable' | 'loads' | 'activities' | 'duty'
 */
function canEditSection(string $section): bool {
  if (isSuperuser()) return true;
  if (isSectionLocked('editing_locked')) return false; // global lock ยังทำงาน
  if (isSectionLocked('lock_' . $section)) return false;
  return true;
}

/**
 * ตรวจสอบว่าผู้ใช้ปัจจุบันสามารถแก้ไขข้อมูลได้หรือไม่ (legacy — ใช้ global lock)
 */
function canEdit(): bool {
  if (isSuperuser()) return true;
  return !isEditingLocked();
}
