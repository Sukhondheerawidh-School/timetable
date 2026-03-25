<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

if (isLoggedIn()) redirect('index.php');

$err = '';
$timeout_msg = '';

// แสดงข้อความ timeout
if (isset($_SESSION['timeout_message'])) {
  $timeout_msg = $_SESSION['timeout_message'];
  unset($_SESSION['timeout_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่';
  } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
      $err = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif (login($username, $password)) {
      redirect('index.php');
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
  <title>เข้าสู่ระบบ - ระบบจัดตารางสอน</title>
  <link rel="icon" type="image/x-icon" href="/timetable/favicon.ico?v=<?= time(); ?>">
  <link rel="shortcut icon" type="image/x-icon" href="/timetable/favicon.ico?v=<?= time(); ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Sarabun', sans-serif;
    }
    .gradient-bg {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .login-card {
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, 0.95);
    }
    .logo-container {
      background: white;
      padding: 1rem;
      border-radius: 1.5rem;
      box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    }
  </style>
</head>
<body class="gradient-bg">
  <div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
      <!-- Header -->
      <div class="text-center mb-8">
        <div class="inline-block logo-container mb-4">
          <img src="<?= url('assets/logo-login.png'); ?>" alt="Logo โรงเรียนสุคนธีรวิทย์" class="h-24 w-auto object-contain">
        </div>
        <h1 class="text-3xl font-bold text-white mb-2">ระบบจัดตารางสอน</h1>
        <p class="text-indigo-100 text-lg">โรงเรียนสุคนธีรวิทย์</p>
      </div>

      <!-- Login Card -->
      <div class="login-card rounded-2xl shadow-2xl p-8">
        <h2 class="text-2xl font-semibold text-gray-800 text-center mb-6">เข้าสู่ระบบ</h2>
        
        <?php if ($timeout_msg): ?>
          <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 text-sm flex items-start gap-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span><?= htmlspecialchars($timeout_msg); ?></span>
          </div>
        <?php endif; ?>

        <?php if ($err): ?>
          <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <span><?= htmlspecialchars($err); ?></span>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-5">
          <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อผู้ใช้</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
              <input type="text" name="username" 
                     class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all" 
                     placeholder="กรอกชื่อผู้ใช้" 
                     required 
                     autofocus>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">รหัสผ่าน</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
              </div>
              <input type="password" name="password" 
                     class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all" 
                     placeholder="กรอกรหัสผ่าน" 
                     required>
            </div>
          </div>

          <button type="submit" 
                  class="w-full py-3.5 px-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-medium rounded-xl hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transform transition-all hover:scale-[1.02] active:scale-[0.98] shadow-lg">
            เข้าสู่ระบบ
          </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-500">
          <p>ระบบจัดการตารางสอนอัตโนมัติ</p>
          <p class="mt-1">สำหรับผู้ดูแลระบบและครูผู้สอน</p>
        </div>
      </div>

      <!-- Footer -->
      <div class="mt-8 text-center">
        <p class="text-white text-sm opacity-90">
          © <?= date('Y'); ?> โรงเรียนสุคนธีรวิทย์ - All rights reserved
        </p>
      </div>
    </div>
  </div>
</body>
</html>
