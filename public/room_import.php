<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$err=''; $done=0; $updated=0;

function normalize_header_room($h){
  $h = trim(mb_strtolower($h));
  $map = [
    'รหัสห้อง'   => 'room_code', 'code' => 'room_code', 'room_code' => 'room_code',
    'ชื่อห้อง'   => 'room_name', 'name' => 'room_name', 'room_name' => 'room_name',
    'อาคาร'      => 'building',  'building' => 'building',
    'ประเภท'     => 'room_type', 'type' => 'room_type', 'room_type' => 'room_type'
  ];
  return $map[$h] ?? $h;
}
function normalize_room_type($v){
  $v = trim(mb_strtolower($v));
  if ($v === 'lab' || $v === 'ห้องปฏิบัติการ' || $v === 'ปฏิบัติการ') return 'lab';
  return 'classroom';
}

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!verify_csrf($_POST['csrf'] ?? '')) $err='CSRF ไม่ถูกต้อง';
  elseif (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) $err='อัปโหลดไฟล์ไม่สำเร็จ';
  else{
    $contents = file_get_contents($_FILES['csv']['tmp_name']);
    if ($contents===false) $err='อ่านไฟล์ไม่สำเร็จ';
    else{
      // ปลอดภัยกับ mb_detect_encoding
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

      if(($fh=fopen($tmpUtf,'r'))!==false){
        $header = fgetcsv($fh);
        if(!$header) $err='ไม่พบหัวตารางในไฟล์';
        else{
          // ลอก BOM ในหัวคอลัมน์แรก
          $header = array_map(function($h){ return preg_replace('/^\xEF\xBB\xBF/','',(string)$h); }, $header);
          $cols = array_map('normalize_header_room',$header);
          $iCode = array_search('room_code',$cols);
          $iName = array_search('room_name',$cols);
          $iBld  = array_search('building',$cols);
          $iType = array_search('room_type',$cols);

          if ($iCode===false || $iName===false){
            $err = 'ไฟล์ต้องมีอย่างน้อย: รหัสห้อง/room_code และ ชื่อห้อง/room_name';
          } else {
            while(($row=fgetcsv($fh))!==false){
              if (count($row)===1 && trim($row[0])==='') continue;
              $code = trim($row[$iCode] ?? '');
              $name = trim($row[$iName] ?? '');
              $bld  = $iBld!==false  ? trim($row[$iBld] ?? '')  : '';
              $type = $iType!==false ? normalize_room_type($row[$iType] ?? '') : 'classroom';
              if ($code==='' || $name==='') continue;

              $stmt = $pdo->prepare('INSERT INTO rooms(room_code,room_name,building,room_type)
                                     VALUES (?,?,?,?)
                                     ON DUPLICATE KEY UPDATE room_name=VALUES(room_name), building=VALUES(building), room_type=VALUES(room_type)');
              $ok = $stmt->execute([$code,$name,$bld,$type]);
              if ($ok){
                if ($stmt->rowCount()===1) $done++; else $updated++;
              }
            }
            fclose($fh); unlink($tmpUtf);
            flash_set('success',"นำเข้าเสร็จสิ้น: เพิ่มใหม่ {$done} ห้อง, อัปเดต {$updated} ห้อง");
            redirect('rooms.php');
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
  <h1 class="text-xl font-semibold mt-8 mb-4">นำเข้าห้องจาก CSV</h1>

  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div><?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-6 space-y-4">
    <form method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <div>
        <label class="block text-sm mb-1">ไฟล์ CSV</label>
        <input type="file" name="csv" accept=".csv" class="w-full border rounded-lg px-3 py-2" required>
      </div>
      <p class="text-xs text-slate-600">
        ต้องมีอย่างน้อย: <code>รหัสห้อง/room_code</code>, <code>ชื่อห้อง/room_name</code><br>
        คอลัมน์เสริม: <code>อาคาร/building</code>, <code>ประเภท/room_type</code> (ห้องเรียน / ห้องปฏิบัติการ)<br>
        <a class="underline text-blue-600" href="<?= url('room_template.php'); ?>">ดาวน์โหลดเทมเพลต CSV</a>
      </p>
      <div class="flex items-center gap-2">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">อัปโหลดและนำเข้า</button>
        <a href="<?= url('rooms.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
      </div>
    </form>

    <div class="text-xs text-slate-500">
      ตัวอย่าง (CSV):<br>
      รหัสห้อง,ชื่อห้อง,อาคาร,ประเภท<br>
      12101,ห้องคอมพิวเตอร์ 1,อาคาร 1,ห้องปฏิบัติการ
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
