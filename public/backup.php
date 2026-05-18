<?php
// filepath: c:\xampp\htdocs\timetable\public\backup.php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../config/config.php';

requireAdmin(); // เฉพาะ Admin เท่านั้น

$flash = flash_get();

// ✅ จัดการ Backup - เก็บไฟล์ไว้ ไม่ลบ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    try {
      $backupDir = __DIR__ . '/../backups';
      if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
      }

      $filename = 'timetable_backup_' . date('Y-m-d_His') . '.sql';
      $filepath = $backupDir . '/' . $filename;

      // ✅ หา path ของ mysqldump ใน XAMPP
      $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
      
      if (!file_exists($mysqldumpPath)) {
        $mysqldumpPath = 'mysqldump';
      }

      // ✅ สร้างไฟล์ config ชั่วคราวเพื่อเก็บ password
      $configFile = $backupDir . '/.my.cnf';
      $configContent = "[client]\n";
      $configContent .= "user=" . DB_USER . "\n";
      $configContent .= "password=\"" . str_replace('"', '\\"', DB_PASS) . "\"\n";
      $configContent .= "host=" . DB_HOST . "\n";
      file_put_contents($configFile, $configContent);
      chmod($configFile, 0600);

      // ✅ รัน mysqldump
      $command = sprintf(
        '"%s" --defaults-extra-file="%s" --no-tablespaces %s > "%s" 2>&1',
        $mysqldumpPath,
        $configFile,
        escapeshellarg(DB_NAME),
        $filepath
      );

      exec($command, $output, $returnCode);

      if (file_exists($configFile)) {
        unlink($configFile);
      }

      if ($returnCode === 0 && file_exists($filepath) && filesize($filepath) > 0) {
        $sizeKB = number_format(filesize($filepath) / 1024, 2);
        flash_set('success', "✅ สำรองข้อมูลสำเร็จ! ไฟล์: {$filename} ({$sizeKB} KB)");
        redirect('backup.php');
      } else {
        $errorMsg = !empty($output) ? implode("\n", $output) : 'Unknown error';
        throw new Exception('ไม่สามารถสร้างไฟล์ backup ได้: ' . $errorMsg);
      }
    } catch (Throwable $e) {
      flash_set('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
      redirect('backup.php');
    }
  }
}

// ✅ จัดการ Restore จากไฟล์ที่มีอยู่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    $filename = basename($_POST['file'] ?? '');
    $filepath = __DIR__ . '/../backups/' . $filename;
    
    if (!file_exists($filepath) || pathinfo($filepath, PATHINFO_EXTENSION) !== 'sql') {
      flash_set('error', 'ไม่พบไฟล์ที่ต้องการ restore');
      redirect('backup.php');
    }
    
    try {
      // ✅ สร้าง backup ก่อน restore (เผื่อเกิดปัญหา)
      $autoBackupDir = __DIR__ . '/../backups';
      $autoBackupFile = 'auto_before_restore_' . date('Y-m-d_His') . '.sql';
      $autoBackupPath = $autoBackupDir . '/' . $autoBackupFile;
      
      $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
      if (!file_exists($mysqldumpPath)) {
        $mysqldumpPath = 'mysqldump';
      }
      
      $configFile = $autoBackupDir . '/.my.cnf';
      $configContent = "[client]\n";
      $configContent .= "user=" . DB_USER . "\n";
      $configContent .= "password=\"" . str_replace('"', '\\"', DB_PASS) . "\"\n";
      $configContent .= "host=" . DB_HOST . "\n";
      file_put_contents($configFile, $configContent);
      chmod($configFile, 0600);
      
      $command = sprintf(
        '"%s" --defaults-extra-file="%s" --no-tablespaces %s > "%s" 2>&1',
        $mysqldumpPath,
        $configFile,
        escapeshellarg(DB_NAME),
        $autoBackupPath
      );
      exec($command);
      
      // ✅ Restore จากไฟล์
      $mysqlPath = 'C:\\xampp\\mysql\\bin\\mysql.exe';
      if (!file_exists($mysqlPath)) {
        $mysqlPath = 'mysql';
      }
      
      $command = sprintf(
        '"%s" --defaults-extra-file="%s" %s < "%s" 2>&1',
        $mysqlPath,
        $configFile,
        escapeshellarg(DB_NAME),
        $filepath
      );
      
      exec($command, $output, $returnCode);
      
      if (file_exists($configFile)) {
        unlink($configFile);
      }
      
      if ($returnCode === 0) {
        flash_set('success', "✅ Restore สำเร็จ! (สร้าง backup อัตโนมัติ: {$autoBackupFile})");
        redirect('backup.php');
      } else {
        $errorMsg = !empty($output) ? implode("\n", $output) : 'Unknown error';
        throw new Exception('ไม่สามารถ restore ได้: ' . $errorMsg);
      }
    } catch (Throwable $e) {
      flash_set('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
      redirect('backup.php');
    }
  }
}

// ✅ จัดการ Upload ไฟล์ใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
      flash_set('error', 'กรุณาเลือกไฟล์ .sql');
      redirect('backup.php');
    }
    
    $uploadedFile = $_FILES['backup_file'];
    $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    
    if ($ext !== 'sql') {
      flash_set('error', 'รองรับเฉพาะไฟล์ .sql เท่านั้น');
      redirect('backup.php');
    }
    
    try {
      $backupDir = __DIR__ . '/../backups';
      if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
      }
      
      $filename = 'uploaded_' . date('Y-m-d_His') . '.sql';
      $filepath = $backupDir . '/' . $filename;
      
      if (move_uploaded_file($uploadedFile['tmp_name'], $filepath)) {
        $sizeKB = number_format(filesize($filepath) / 1024, 2);
        flash_set('success', "✅ Upload สำเร็จ! ไฟล์: {$filename} ({$sizeKB} KB)");
      } else {
        throw new Exception('ไม่สามารถบันทึกไฟล์ได้');
      }
    } catch (Throwable $e) {
      flash_set('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
    redirect('backup.php');
  }
}

// ✅ จัดการ Download ไฟล์ที่มีอยู่
if (isset($_GET['download'])) {
  $filename = basename($_GET['download']);
  $filepath = __DIR__ . '/../backups/' . $filename;
  
  if (file_exists($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === 'sql') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($filepath);
    exit;
  } else {
    flash_set('error', 'ไม่พบไฟล์ที่ต้องการดาวน์โหลด');
    redirect('backup.php');
  }
}

// ✅ จัดการลบไฟล์
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    flash_set('error', 'CSRF token ไม่ถูกต้อง');
  } else {
    $filename = basename($_POST['file'] ?? '');
    $filepath = __DIR__ . '/../backups/' . $filename;
    
    if (file_exists($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === 'sql') {
      if (unlink($filepath)) {
        flash_set('success', 'ลบไฟล์สำเร็จ');
      } else {
        flash_set('error', 'ไม่สามารถลบไฟล์ได้');
      }
    } else {
      flash_set('error', 'ไม่พบไฟล์ที่ต้องการลบ');
    }
  }
  redirect('backup.php');
}

// ✅ แสดงไฟล์ backup ที่มีอยู่
$backupDir = __DIR__ . '/../backups';
$backups = [];
if (is_dir($backupDir)) {
  $files = glob($backupDir . '/*.sql');
  foreach ($files as $file) {
    $backups[] = [
      'name' => basename($file),
      'size' => filesize($file),
      'date' => date('Y-m-d H:i:s', filemtime($file)),
      'path' => $file
    ];
  }
  usort($backups, fn($a, $b) => $b['date'] <=> $a['date']);
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-4xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-4">
    <h1 class="text-xl font-semibold">💾 สำรองข้อมูล</h1>
    <div class="flex gap-2">
      <!-- ✅ ปุ่ม Upload -->
      <button type="button" onclick="document.getElementById('uploadModal').classList.remove('hidden')" 
              class="px-4 py-2 rounded-xl border border-slate-300 hover:bg-slate-50">
        📤 Upload ไฟล์
      </button>
      <!-- ปุ่ม Backup -->
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="action" value="backup">
        <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700">
          ➕ สร้าง Backup ใหม่
        </button>
      </form>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded <?= $flash['type']==='success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?> text-sm">
      <?= htmlspecialchars($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <!-- คำแนะนำ -->
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
    <div class="flex items-start gap-3">
      <span class="text-2xl">⚠️</span>
      <div class="text-sm text-amber-800">
        <p class="font-semibold mb-1">คำแนะนำ:</p>
        <ul class="list-disc list-inside space-y-1">
          <li><strong>Backup:</strong> กดปุ่ม "➕ สร้าง Backup ใหม่" เพื่อสำรองข้อมูล</li>
          <li><strong>Upload:</strong> กดปุ่ม "📤 Upload ไฟล์" เพื่ออัปโหลดไฟล์ .sql จากเครื่องของคุณ</li>
          <li><strong>Restore:</strong> กดปุ่ม "🔄 Restore" เพื่อกู้คืนข้อมูลจากไฟล์ backup</li>
          <li><strong>⚠️ ระวัง:</strong> การ Restore จะแทนที่ข้อมูลทั้งหมดในฐานข้อมูล (ระบบจะสร้าง backup อัตโนมัติก่อน restore)</li>
          <li>เก็บไฟล์ backup ไว้ในที่ปลอดภัย (นอกเครื่อง server)</li>
        </ul>
      </div>
    </div>
  </div>

  <!-- ประวัติ Backup -->
  <?php if (!empty($backups)): ?>
  <div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b bg-slate-50 flex items-center justify-between">
      <h2 class="font-semibold">📂 ไฟล์ Backup ที่มีอยู่ (<?= count($backups); ?> ไฟล์)</h2>
      <div class="text-sm text-slate-500">
        รวมขนาด: <?= number_format(array_sum(array_column($backups, 'size')) / 1024, 2); ?> KB
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left px-4 py-3 font-semibold">ชื่อไฟล์</th>
            <th class="text-left px-4 py-3 font-semibold">วันที่สร้าง</th>
            <th class="text-right px-4 py-3 font-semibold">ขนาด</th>
            <th class="text-right px-4 py-3 font-semibold">การทำงาน</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($backups as $backup): ?>
            <tr class="border-t hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <span class="text-lg">📄</span>
                  <code class="text-xs"><?= htmlspecialchars($backup['name']); ?></code>
                </div>
              </td>
              <td class="px-4 py-3 text-slate-600">
                <?= htmlspecialchars($backup['date']); ?>
              </td>
              <td class="px-4 py-3 text-right font-mono text-slate-600">
                <?= number_format($backup['size'] / 1024, 2); ?> KB
              </td>
              <td class="px-4 py-3 text-right">
                <!-- ✅ ปุ่ม Restore -->
                <form method="post" class="inline mr-2" onsubmit="return ttConfirmSubmit(this,{title:'Restore ฐานข้อมูล', text:'⚠️ คุณแน่ใจหรือไม่?\n\nการ Restore จะแทนที่ข้อมูลทั้งหมดในฐานข้อมูล!\n\n(ระบบจะสร้าง backup อัตโนมัติก่อน restore)', confirmButtonText:'Restore', cancelButtonText:'ยกเลิก'});">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="file" value="<?= htmlspecialchars($backup['name']); ?>">
                  <button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-xs">
                    <span>🔄</span>
                    <span>Restore</span>
                  </button>
                </form>
                
                <!-- ปุ่ม Download -->
                <a href="?download=<?= urlencode($backup['name']); ?>" 
                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 text-xs mr-2">
                  <span>⬇️</span>
                  <span>ดาวน์โหลด</span>
                </a>
                
                <!-- ปุ่มลบ -->
                <form method="post" class="inline" onsubmit="return ttConfirmSubmit(this,{text:'⚠️ ต้องการลบไฟล์นี้?', confirmButtonText:'ลบ'});">
                  <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="file" value="<?= htmlspecialchars($backup['name']); ?>">
                  <button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-rose-600 text-rose-600 hover:bg-rose-50 text-xs">
                    <span>🗑️</span>
                    <span>ลบ</span>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
    <div class="bg-white rounded-xl shadow p-12 text-center text-slate-500">
      <div class="text-6xl mb-4">📭</div>
      <div class="text-lg font-medium mb-2">ยังไม่มีไฟล์ backup</div>
      <div class="text-sm">กดปุ่ม "➕ สร้าง Backup ใหม่" หรือ "📤 Upload ไฟล์" เพื่อเริ่มต้น</div>
    </div>
  <?php endif; ?>
</div>

<!-- ✅ Modal Upload -->
<div id="uploadModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-md w-full mx-4">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold">📤 Upload ไฟล์ Backup</h2>
      <button type="button" onclick="document.getElementById('uploadModal').classList.add('hidden')" 
              class="text-slate-400 hover:text-slate-600 text-2xl leading-none">
        ×
      </button>
    </div>
    
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="action" value="upload">
      
      <div class="mb-4">
        <label class="block text-sm mb-2">เลือกไฟล์ .sql</label>
        <input type="file" name="backup_file" accept=".sql" required
               class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm">
        <p class="text-xs text-slate-500 mt-1">รองรับเฉพาะไฟล์ .sql เท่านั้น</p>
      </div>
      
      <div class="flex gap-2">
        <button type="submit" class="flex-1 px-4 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700">
          Upload
        </button>
        <button type="button" onclick="document.getElementById('uploadModal').classList.add('hidden')" 
                class="flex-1 px-4 py-2 rounded-xl border hover:bg-slate-50">
          ยกเลิก
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>