<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();
requireAdmin();

tt_teachers_init($pdo);

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
    // รหัสบัตรประชาชน
    'รหัสบัตรประชาชน'      => 'national_id',
    'เลขบัตรประชาชน'       => 'national_id',
    'เลขประจำตัวประชาชน'  => 'national_id',
    'บัตรประชาชน'         => 'national_id',
    'national_id'         => 'national_id',
    'nationalid'          => 'national_id',
    'id_card'             => 'national_id',
    'idcard'              => 'national_id',
    // ชื่อผู้ใช้
    'ชื่อผู้ใช้'    => 'username',
    'ยูสเซอร์เนม'   => 'username',
    'username'      => 'username',
    'user'          => 'username',
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
    // ชื่อจริงภาษาอังกฤษ
    'ชื่อภาษาอังกฤษ'      => 'first_name_en',
    'ชื่อจริงภาษาอังกฤษ'  => 'first_name_en',
    'ชื่อ(อังกฤษ)'        => 'first_name_en',
    'first_name_en'       => 'first_name_en',
    'firstname_en'        => 'first_name_en',
    'firstnameen'         => 'first_name_en',
    // นามสกุลภาษาอังกฤษ
    'นามสกุลภาษาอังกฤษ'   => 'last_name_en',
    'นามสกุล(อังกฤษ)'     => 'last_name_en',
    'last_name_en'        => 'last_name_en',
    'lastname_en'         => 'last_name_en',
    'lastnameen'          => 'last_name_en',
    // อีเมล
    'อีเมล'   => 'email',
    'อีเมล์'  => 'email',
    'email'   => 'email',
    'e-mail'  => 'email',
    // รหัสผ่าน
    'รหัสผ่าน'   => 'password',
    'password'   => 'password',
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

          $iCode    = array_search('teacher_code', $cols);
          $iNat     = array_search('national_id',  $cols);
          $iUser    = array_search('username',    $cols);
          $iFirst   = array_search('first_name',  $cols);
          $iLast    = array_search('last_name',   $cols);
          $iTitle   = array_search('title',       $cols);
          $iGroup   = array_search('subject_group',$cols);
          $iFirstEn = array_search('first_name_en',$cols);
          $iLastEn  = array_search('last_name_en', $cols);
          $iEmail   = array_search('email',       $cols);
          $iPass    = array_search('password',    $cols);

          if ($iCode === false) {
            $err = 'ไฟล์ต้องมีคอลัมน์ รหัสประจำตัว/teacher_code อย่างน้อย';
          } else {
            $skipped    = 0;   // รหัสใหม่แต่ไม่มีชื่อ/นามสกุล
            $skippedNew = 0;   // โหมดอัปเดตเท่านั้น: ข้ามรหัสที่ยังไม่มีในระบบ
            $updateOnly = !empty($_POST['update_only']);

            // ตรวจว่ารหัสนี้มีอยู่แล้วหรือยัง (เพื่อตัดสินใจ เพิ่ม/อัปเดต และบังคับชื่อเฉพาะตอนเพิ่มใหม่)
            $chk = $pdo->prepare('SELECT 1 FROM teachers WHERE teacher_code = ?');

            // ทุกคอลัมน์: ค่าว่างใน CSV = NULL → คงค่าเดิมไว้ (IF ... IS NULL) ไม่เขียนทับ
            $stmt = $pdo->prepare('
              INSERT INTO teachers(teacher_code,national_id,username,title,first_name,last_name,first_name_en,last_name_en,email,password_hash,password_plain,subject_group)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                national_id=IF(VALUES(national_id) IS NULL, national_id, VALUES(national_id)),
                username=IF(VALUES(username) IS NULL, username, VALUES(username)),
                title=IF(VALUES(title) = "", title, VALUES(title)),
                first_name=IF(VALUES(first_name) = "", first_name, VALUES(first_name)),
                last_name=IF(VALUES(last_name) = "", last_name, VALUES(last_name)),
                first_name_en=IF(VALUES(first_name_en) IS NULL, first_name_en, VALUES(first_name_en)),
                last_name_en=IF(VALUES(last_name_en) IS NULL, last_name_en, VALUES(last_name_en)),
                email=IF(VALUES(email) IS NULL, email, VALUES(email)),
                password_hash=IF(VALUES(password_hash) IS NULL, password_hash, VALUES(password_hash)),
                password_plain=IF(VALUES(password_plain) IS NULL, password_plain, VALUES(password_plain)),
                subject_group=IF(VALUES(subject_group) IS NULL, subject_group, VALUES(subject_group))
            ');

            while (($row = fgetcsv($fh)) !== false) {
              if (count($row) === 1 && trim($row[0]) === '') continue;

              $code  = trim($row[$iCode]  ?? '');
              if ($code === '') continue;

              $nat   = $iNat  !== false ? trim($row[$iNat] ?? '') : '';
              $username = $iUser !== false ? trim($row[$iUser] ?? '') : '';
              $first = $iFirst !== false ? trim($row[$iFirst] ?? '') : '';
              $last  = $iLast  !== false ? trim($row[$iLast]  ?? '') : '';
              $title = $iTitle !== false ? trim($row[$iTitle] ?? '') : '';
              $group = $iGroup !== false ? parse_subject_group($row[$iGroup] ?? '') : null;

              $firstEn = $iFirstEn !== false ? trim($row[$iFirstEn] ?? '') : '';
              $lastEn  = $iLastEn  !== false ? trim($row[$iLastEn]  ?? '') : '';
              $email   = $iEmail   !== false ? trim($row[$iEmail]   ?? '') : '';
              $pass    = $iPass    !== false ? (string)($row[$iPass] ?? '') : '';

              // มีรหัสนี้อยู่แล้วหรือยัง
              $chk->execute([$code]);
              $exists = (bool)$chk->fetchColumn();

              // โหมด "อัปเดตเท่านั้น": ข้ามรหัสที่ยังไม่มีในระบบ (ไม่เพิ่มครูใหม่)
              if (!$exists && $updateOnly) { $skippedNew++; continue; }

              // เพิ่มใหม่ต้องมีชื่อ+นามสกุล (กันสร้างครูที่ไม่มีชื่อ) · อัปเดตเว้นว่างได้
              if (!$exists && ($first === '' || $last === '')) { $skipped++; continue; }

              // คอลัมน์ NOT NULL (title/ชื่อ/นามสกุล) ใช้ค่าว่าง "" เป็นตัวบอกว่า "คงค่าเดิม"
              // ส่วนคอลัมน์ที่ยอม NULL ใช้ NULL เป็นตัวบอกว่า "คงค่าเดิม"
              $natVal      = $nat      !== '' ? $nat      : null;
              $usernameVal = $username !== '' ? $username : null;
              $firstEnVal = $firstEn !== '' ? $firstEn : null;
              $lastEnVal  = $lastEn  !== '' ? $lastEn  : null;
              $emailVal   = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
              $passHash   = $pass    !== '' ? password_hash($pass, PASSWORD_DEFAULT) : null;
              $passPlain  = $pass    !== '' ? $pass : null;

              $ok = $stmt->execute([$code,$natVal,$usernameVal,$title,$first,$last,$firstEnVal,$lastEnVal,$emailVal,$passHash,$passPlain,$group]);
              if ($ok) {
                if ($exists) $updated++; else $done++;
              }
            }
            fclose($fh);
            unlink($tmpUtf);

            logActivity('import', 'teachers', null, null, [
              'mode'          => $updateOnly ? 'update_only' : 'upsert',
              'created_count' => $done,
              'updated_count' => $updated,
              'skipped_count' => $skipped,
              'skipped_new_count' => $skippedNew,
            ]);
            if ($updateOnly) {
              $msg = "นำเข้า (อัปเดตเท่านั้น) เสร็จสิ้น: อัปเดต {$updated} รายการ";
              if ($skippedNew > 0) $msg .= ", ข้าม {$skippedNew} รายการ (ไม่พบรหัสในระบบ จึงไม่เพิ่มใหม่)";
            } else {
              $msg = "นำเข้าเสร็จสิ้น: เพิ่มใหม่ {$done} รายการ, อัปเดต {$updated} รายการ";
              if ($skipped > 0) $msg .= ", ข้าม {$skipped} รายการ (รหัสใหม่แต่ไม่มีชื่อ/นามสกุล)";
            }
            flash_set('success', $msg);
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
    <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start gap-2"><span class="shrink-0">❌</span><span><?= htmlspecialchars($err); ?></span></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow border border-slate-200 p-6 space-y-4">
    <form method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">ไฟล์ CSV</label>
        <input type="file" name="csv" accept=".csv" class="w-full border border-slate-200 rounded-xl px-3 py-2 bg-white focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 outline-none transition text-sm" required>
      </div>
      <label class="flex items-start gap-2.5 p-3 rounded-xl border border-amber-200 bg-amber-50 cursor-pointer">
        <input type="checkbox" name="update_only" value="1" <?= !empty($_POST['update_only']) ? 'checked' : ''; ?> class="mt-0.5 w-4 h-4 rounded border-slate-300 accent-amber-600">
        <span class="text-sm text-amber-800">
          <strong>นำเข้าเพื่ออัปเดตเท่านั้น (ไม่เพิ่มครูใหม่)</strong><br>
          <span class="text-xs text-amber-700">ถ้ารหัสประจำตัวในไฟล์ไม่มีอยู่ในระบบ จะข้ามแถวนั้น (ไม่สร้างครูใหม่) — กันข้อมูลที่ไม่ต้องการเข้ามาโดยไม่ตั้งใจ</span>
        </span>
      </label>
      <p class="text-xs text-slate-600">
        ต้องมีคอลัมน์: <code>รหัสประจำตัว/teacher_code</code> (อย่างน้อย)<br>
        คอลัมน์อื่น: <code>รหัสบัตรประชาชน/national_id</code>, <code>ชื่อผู้ใช้/username</code>, <code>คำนำหน้า/title</code>, <code>ชื่อ/first_name</code>, <code>นามสกุล/last_name</code>, <code>กลุ่มสาระ/subject_group</code> (1–9 หรือพิมพ์ชื่อกลุ่ม), <code>ชื่อภาษาอังกฤษ/first_name_en</code>, <code>นามสกุลภาษาอังกฤษ/last_name_en</code>, <code>อีเมล/email</code>, <code>รหัสผ่าน/password</code><br>
        💡 ถ้า <code>รหัสประจำตัว</code> ซ้ำกับที่มีอยู่ ระบบจะ<strong>อัปเดต</strong>ข้อมูลให้ — <strong>ช่องไหนเว้นว่างไว้จะไม่เขียนทับของเดิม</strong> (อัปเดตเฉพาะช่องที่กรอกมา)<br>
        ➕ การ<strong>เพิ่มครูใหม่</strong>ต้องมี ชื่อ + นามสกุล ด้วย (ถ้าเป็นรหัสใหม่แต่ไม่มีชื่อ ระบบจะข้ามแถวนั้น)<br>
        🔑 รหัสผ่านจะถูกเก็บแบบ hash (สำหรับ API) พร้อมสำเนาให้ผู้ดูแลดูย้อนหลังได้ในหน้ารายชื่อครู<br>
        <a class="underline text-blue-600" href="<?= url('teacher_template.php'); ?>">ดาวน์โหลดเทมเพลต CSV</a>
      </p>
      <div class="flex items-center gap-2">
        <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">อัปโหลดและนำเข้า</button>
        <a href="<?= url('teachers.php'); ?>" class="px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-xl transition">ยกเลิก</a>
      </div>
    </form>

    <div class="text-xs text-slate-500">
      ตัวอย่างข้อมูล (CSV):<br>
      รหัสประจำตัว,รหัสบัตรประชาชน,ชื่อผู้ใช้,คำนำหน้า,ชื่อ,นามสกุล,ชื่อภาษาอังกฤษ,นามสกุลภาษาอังกฤษ,อีเมล,รหัสผ่าน,กลุ่มสาระ<br>
      t00400,1212212112121,sutha,นาย,สุทา,โร,Sutha,Ro,sutha@example.com,changeme123,คณิตศาสตร์
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
