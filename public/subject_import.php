<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$err=''; $done=0; $updated=0;

function normalize_header_subject($h){
  $h = trim(mb_strtolower($h));
  $map = [
    'รหัสวิชา' => 'subject_code', 'code' => 'subject_code', 'subject_code' => 'subject_code',
    'ชื่อวิชา' => 'subject_name', 'name' => 'subject_name', 'subject_name' => 'subject_name',
  ];
  return $map[$h] ?? $h;
}

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  elseif (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) $err='อัปโหลดไฟล์ไม่สำเร็จ';
  else {
    $contents = file_get_contents($_FILES['csv']['tmp_name']);
    if ($contents===false) $err='อ่านไฟล์ไม่สำเร็จ';
    else {
      // ตรวจ encodings แบบปลอดภัย
      $supported = mb_list_encodings();
      $encodings = ['UTF-8','SJIS','ISO-8859-1'];
      if (in_array('TIS-620',$supported,true)) $encodings[]='TIS-620';
      if (in_array('WINDOWS-874',$supported,true)) $encodings[]='WINDOWS-874';
      if (in_array('CP874',$supported,true)) $encodings[]='CP874';
      $enc = mb_detect_encoding($contents,$encodings,true);
      if ($enc && strtoupper($enc)!=='UTF-8') $contents = iconv($enc,'UTF-8//IGNORE',$contents);
      // ตัด BOM
      if (strncmp($contents, "\xEF\xBB\xBF", 3)===0) $contents = substr($contents,3);

      $tmpUtf = tempnam(sys_get_temp_dir(),'csvutf');
      file_put_contents($tmpUtf,$contents);

      if (($fh=fopen($tmpUtf,'r'))!==false){
        $header = fgetcsv($fh);
        if (!$header) $err='ไม่พบหัวตารางในไฟล์';
        else {
          $header = array_map(fn($h)=>preg_replace('/^\xEF\xBB\xBF/','',(string)$h), $header);
          $cols = array_map('normalize_header_subject',$header);
          $iCode = array_search('subject_code',$cols);
          $iName = array_search('subject_name',$cols);

          if ($iCode===false || $iName===false){
            $err='ไฟล์ต้องมีอย่างน้อย: รหัสวิชา/subject_code, ชื่อวิชา/subject_name';
          } else {
            while(($row=fgetcsv($fh))!==false){
              if (count($row)===1 && trim($row[0])==='') continue;
              $code = trim($row[$iCode] ?? '');
              $name = trim($row[$iName] ?? '');
              if ($code==='' || $name==='') continue;

              $stmt = $pdo->prepare('INSERT INTO subjects(subject_code,subject_name)
                                     VALUES(?,?)
                                     ON DUPLICATE KEY UPDATE subject_name=VALUES(subject_name)');
              $ok = $stmt->execute([$code,$name]);
              if ($ok){
                if ($stmt->rowCount()===1) $done++; else $updated++;
              }
            }
            fclose($fh); unlink($tmpUtf);
            flash_set('success',"นำเข้าเสร็จสิ้น: เพิ่มใหม่ {$done} รายวิชา, อัปเดต {$updated} รายวิชา");
            redirect('subjects.php');
          }
        }
      } else $err='เปิดไฟล์ชั่วคราวไม่สำเร็จ';
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-2xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">นำเข้ารายวิชาจาก CSV</h1>

  <?php if ($err): ?><div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div><?php endif; ?>

  <div class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <form method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ไฟล์ CSV</label>
        <input type="file" name="csv" accept=".csv" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
      </div>
      <p class="text-xs text-slate-600">
        ต้องมีอย่างน้อย: <code>รหัสวิชา/subject_code</code>, <code>ชื่อวิชา/subject_name</code><br>
        <a class="underline text-blue-600" href="<?= url('subject_template.php'); ?>">ดาวน์โหลดเทมเพลต CSV</a>
      </p>
      <div class="flex items-center gap-2">
        <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">อัปโหลดและนำเข้า</button>
        <a href="<?= url('subjects.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
      </div>
    </form>

    <div class="text-xs text-slate-500">
      ตัวอย่าง (CSV):<br>
      รหัสวิชา,ชื่อวิชา<br>
      ว12101,วิทยาศาสตร์ 1<br>
      ค12101,คณิตศาสตร์ 1
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
