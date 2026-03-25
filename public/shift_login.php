<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

function shift_check_session_timeout(): void {
  if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed = time() - (int)$_SESSION['LAST_ACTIVITY'];
    if (defined('SESSION_TIMEOUT') && $elapsed > SESSION_TIMEOUT) {
      $_SESSION = [];
      session_destroy();
      session_start();
      $_SESSION['timeout_message'] = 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
      header('Location: ' . url('shift_login'));
      exit;
    }
  }
  $_SESSION['LAST_ACTIVITY'] = time();
}

function shift_is_logged_in(): bool {
  shift_check_session_timeout();
  return !empty($_SESSION['user']);
}

if (shift_is_logged_in()) {
  redirect('shift_view');
}

$err = '';
$timeout_msg = '';
if (isset($_SESSION['timeout_message'])) {
  $timeout_msg = (string)$_SESSION['timeout_message'];
  unset($_SESSION['timeout_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่';
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
      $err = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif (login($username, $password)) {
      redirect('shift_view');
    } else {
      $err = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>เข้าสู่ระบบ - ดูเวรวันหยุด</title>
  <link rel="icon" type="image/x-icon" href="/timetable/favicon.ico?v=<?= time(); ?>">
  <link rel="shortcut icon" type="image/x-icon" href="/timetable/favicon.ico?v=<?= time(); ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Sarabun', sans-serif; }
    .card { backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.96); }
  </style>
</head>
<body class="bg-gradient-to-br from-indigo-600 via-violet-600 to-slate-900">
  <div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-white mb-2">🗓️ ดูเวรวันหยุด</h1>
        <p class="text-indigo-100">เข้าสู่ระบบเพื่อดูรายการเวร</p>
      </div>

      <div class="card rounded-2xl shadow-2xl p-8">
        <h2 class="text-xl font-semibold text-slate-900 text-center mb-6">🔐 เข้าสู่ระบบ</h2>

        <?php if ($timeout_msg): ?>
          <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 text-sm">
            <?= htmlspecialchars($timeout_msg); ?>
          </div>
        <?php endif; ?>

        <?php if ($err): ?>
          <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <?= htmlspecialchars($err); ?>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-5">
          <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-2">ชื่อผู้ใช้</label>
            <input type="text" name="username" class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-slate-900 focus:border-transparent transition-all" placeholder="กรอกชื่อผู้ใช้" required autofocus>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700 mb-2">รหัสผ่าน</label>
            <input type="password" name="password" class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-slate-900 focus:border-transparent transition-all" placeholder="กรอกรหัสผ่าน" required>
          </div>

          <button type="submit" class="w-full py-3.5 px-4 bg-slate-900 text-white font-medium rounded-xl hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-900 transition-all">
            🚀 เข้าสู่ระบบ
          </button>
        </form>

        <div class="mt-6 text-center text-xs text-slate-500">
          👤 ใช้บัญชีผู้ใช้เดียวกับระบบหลัก
        </div>
      </div>

      <div class="mt-8 text-center">
        <p class="text-white text-sm opacity-90">© <?= date('Y'); ?> โรงเรียนสุคนธีรวิทย์ - All rights reserved</p>
      </div>
    </div>
  </div>
</body>
</html>
