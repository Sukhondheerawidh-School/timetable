<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

$err = '';
$done = 0;
$updated = 0;

/** map ชื่อหัวตาราง -> internal field */
function normalize_header($h) {
  $h = trim(mb_strtolower($h));
  $map = [
    // รหัส
    'รหัสประจำตัว' => 'teacher_code',
    'รหัส'         => 'teacher_code',
    'code'         => 'teacher_code',
    'teacher_code' => 'teacher_code',
    // คำนำหน้า
    'คำนำหน้า'     => 'title',
    'คำนำหน้า.'    => 'title',
    'title'         => 'title',
    // ชื่อ
    'ชื่อ'          => 'first_name',
    'ชื่อจริง'      => 'first_name',
    'first_name'    => 'first_name',
    // นามสกุล
    'นามสกุล'      => 'last_name',
    'lastname'      => 'last_name',
    'last_name'     => 'last_name',
    // กลุ่มสาระ
    'กลุ่มสาระ'        => 'subject_group',
    'หมวด'            => 'subject_group',
    'group'           => 'subject_group',
    'subject_group'   => 'subject_group',
  ];
  return $map[$h] ?? $h;
}

/** แปลงค่ากลุ่มสาระ (ข้อความ/ตัวเลข) -> หมายเลข 1..9 หรือ null */
function parse_subject_group($val): ?int {
  $v = trim(mb_strtolower((string)$val));
  if ($v === '') return null;
  if (ctype_digit($v)) {
    $n = (int)$v;
    return ($n >= 1 && $n <= 9) ? $n : 9;
  }
  if (str_contains($v,'คณิต')) return 1;
  if (str_contains($v,'วิทย') || str_contains($v,'เทคโน')) return 2;
  if (str_contains($v,'ไทย')) return 3;
  if (str_contains($v,'ต่างประเทศ') || str_contains($v,'อังกฤษ')) return 4;
  if (str_contains($v,'สังคม') || str_contains($v,'ศาสนา') || str_contains($v,'วัฒนธรรม')) return 5;
  if (str_contains($v,'สุข') || str_contains($v,'พละ') || str_contains($v,'พลศึกษา')) return 6;
  if (str_contains($v,'ศิลป')) return 7;
  if (str_contains($v,'การงาน') || str_contains($v,'อาชีพ')) return 8;
  return 9; // อื่นๆ
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $err = 'CSRF ไม่ถูกต้อง';
  } elseif (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    $err = 'อัปโหลดไฟล์ไม่สำเร็จ';
  } else {
    $tmp = $_FILES['csv']['tmp_name'];
    $contents = file_get_contents($tmp);

    if ($contents === false) {
      $err = 'อ่านไฟล์ไม่สำเร็จ';
    } else {
      // ตรวจ encoding แบบปลอดภัย
      $supported = mb_list_encodings();
      $encodings = ['UTF-8','SJIS','ISO-8859-1'];
      if (in_array('TIS-620',$supported,true))     $encodings[]='TIS-620';
      if (in_array('WINDOWS-874',$supported,true)) $encodings[]='WINDOWS-874';
      if (in_array('CP874',$supported,true))       $encodings[]='CP874';
      $enc = mb_detect_encoding($contents, $encodings, true);
      if ($enc && strtoupper($enc) !== 'UTF-8') {
        $contents = iconv($enc, 'UTF-8//IGNORE', $contents);
      }
      // ตัด BOM ต้นไฟล์ (Excel CSV UTF-8)
      if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
        $contents = substr($contents, 3);
      }

      // เขียนไฟล์ UTF-8 ชั่วคราว
      $tmpUtf = tempnam(sys_get_temp_dir(), 'csvutf');
      file_put_contents($tmpUtf, $contents);

      if (($fh = fopen($tmpUtf, 'r')) !== false) {
        $header = fgetcsv($fh);
        if (!$header) {
          $err = 'ไม่พบหัวตารางในไฟล์';
        } else {
          // ตัด BOM ที่หัวคอลัมน์แรก (กันกรณีเหลือ)
          $header = array_map(fn($h)=>preg_replace('/^\xEF\xBB\xBF/','',(string)$h), $header);
          $cols = array_map('normalize_header', $header);

          $iCode  = array_search('teacher_code', $cols);
          $iFirst = array_search('first_name',  $cols);
          $iLast  = array_search('last_name',   $cols);
          $iTitle = array_search('title',       $cols);
          $iGroup = array_search('subject_group',$cols);

          if ($iCode === false || $iFirst === false || $iLast === false) {
            $err = 'ไฟล์ต้องมีอย่างน้อย: รหัสประจำตัว/teacher_code, ชื่อ/first_name, นามสกุล/last_name';
          } else {
            while (($row = fgetcsv($fh)) !== false) {
              if (count($row) === 1 && trim($row[0]) === '') continue;

              $code  = trim($row[$iCode]  ?? '');
              $first = trim($row[$iFirst] ?? '');
              $last  = trim($row[$iLast]  ?? '');
              $title = $iTitle !== false ? trim($row[$iTitle] ?? '') : '';
              $group = $iGroup !== false ? parse_subject_group($row[$iGroup] ?? '') : null;

              if ($code === '' || $first === '' || $last === '') continue;

              $stmt = $pdo->prepare('
                INSERT INTO teachers(teacher_code,title,first_name,last_name,subject_group)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  title=VALUES(title),
                  first_name=VALUES(first_name),
                  last_name=VALUES(last_name),
                  subject_group=VALUES(subject_group)
              ');
              $ok = $stmt->execute([$code,$title,$first,$last,$group]);
              if ($ok) {
                if ($stmt->rowCount() === 1) $done++; else $updated++;
              }
            }
            fclose($fh);
            unlink($tmpUtf);

            logActivity('import', 'teachers', null, null, [
              'created_count' => $done,
              'updated_count' => $updated,
            ]);
            flash_set('success', "นำเข้าเสร็จสิ้น: เพิ่มใหม่ {$done} รายการ, อัปเดต {$updated} รายการ");
            redirect('teachers.php');
          }
        }
      } else {
        $err = 'เปิดไฟล์ชั่วคราวไม่สำเร็จ';
      }
    }
  }
}
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-2xl mx-auto px-4">
  <h1 class="text-xl font-semibold mt-8 mb-4">นำเข้าครูจาก CSV</h1>

  <?php if ($err): ?>
    <div class="mb-4 p-3 rounded bg-rose-50 text-rose-700 text-sm"><?= htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow p-6 space-y-4">
    <form method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <div>
        <label class="block text-sm mb-1">ไฟล์ CSV</label>
        <input type="file" name="csv" accept=".csv" class="w-full border rounded-lg px-3 py-2" required>
      </div>
      <p class="text-xs text-slate-600">
        ต้องมีอย่างน้อย: <code>รหัสประจำตัว/teacher_code</code>, <code>ชื่อ/first_name</code>, <code>นามสกุล/last_name</code><br>
        คอลัมน์เสริม: <code>คำนำหน้า/title</code>, <code>กลุ่มสาระ/subject_group</code> (1–9 หรือพิมพ์ชื่อกลุ่ม)<br>
        <a class="underline text-blue-600" href="<?= url('teacher_template.php'); ?>">ดาวน์โหลดเทมเพลต CSV</a>
      </p>
      <div class="flex items-center gap-2">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white hover:opacity-90">อัปโหลดและนำเข้า</button>
        <a href="<?= url('teachers.php'); ?>" class="px-4 py-2 rounded-xl border">ยกเลิก</a>
      </div>
    </form>

    <div class="text-xs text-slate-500">
      ตัวอย่างข้อมูล (CSV):<br>
      รหัสประจำตัว,คำนำหน้า,ชื่อ,นามสกุล,กลุ่มสาระ<br>
      t00400,นาย,สุทา,โร,คณิตศาสตร์
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
