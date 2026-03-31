<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?? 'ระบบจัดตารางสอน'; ?></title>
  <link rel="icon" type="image/png" href="<?= url('assets/logo-web.png?v=' . time()); ?>">
  <link rel="shortcut icon" type="image/png" href="<?= url('assets/logo-web.png?v=' . time()); ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= url('assets/vendor/sweetalert2/sweetalert2.min.css?v=20260219'); ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <link rel="stylesheet" href="https://unpkg.com/sweetalert2@11/dist/sweetalert2.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Sarabun', sans-serif; }
    /* 🎨 Custom Color Palette */
    :root {
      --primary: #6366f1; /* Indigo */
      --primary-dark: #4f46e5;
      --secondary: #8b5cf6; /* Purple */
      --success: #10b981; /* Emerald */
      --danger: #ef4444; /* Red */
      --warning: #f59e0b; /* Amber */
    }
  </style>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#6366f1',
            'primary-dark': '#4f46e5',
          }
        }
      }
    }
  </script>
  <script src="<?= url('assets/vendor/sweetalert2/sweetalert2.all.min.js?v=20260219'); ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // Fallback CDN (บางเครือข่าย block jsdelivr/ไม่มีอินเทอร์เน็ต)
    if (!window.Swal) {
      var s = document.createElement('script');
      s.src = 'https://unpkg.com/sweetalert2@11/dist/sweetalert2.all.min.js';
      s.referrerPolicy = 'no-referrer';
      document.head.appendChild(s);
    }
  </script>
  <script src="<?= url('assets/js/tt_swal.js?v=20260219'); ?>"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100 min-h-screen">

