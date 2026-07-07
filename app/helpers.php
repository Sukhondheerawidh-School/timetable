<?php
function url($path = '') {
  $base = rtrim(BASE_URL, '/');
  $p = ltrim($path, '/');
  return $base . ($p ? '/' . $p : '');
}

function redirect($path = '') {
  header('Location: ' . url($path));
  exit;
}

// CSRF
function csrf_token() {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}
function verify_csrf($token) {
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
}

function flash_set($type, $msg) {
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get() {
  $f = $_SESSION['flash'] ?? null;
  unset($_SESSION['flash']);
  return $f;
}

/* =========================
   App Settings (key-value)
========================= */
function tt_app_settings_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
      skey VARCHAR(64) NOT NULL,
      svalue TEXT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (skey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore (old MySQL/permissions)
  }

  $done = true;
}

function tt_app_setting_get(PDO $pdo, string $key, ?string $default = null): ?string {
  tt_app_settings_init($pdo);
  try {
    $st = $pdo->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v === false) return $default;
    return $v === null ? null : (string)$v;
  } catch (Throwable $e) {
    return $default;
  }
}

function tt_app_setting_set(PDO $pdo, string $key, ?string $value): void {
  tt_app_settings_init($pdo);
  try {
    $st = $pdo->prepare('INSERT INTO app_settings(skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
    $st->execute([$key, $value]);
  } catch (Throwable $e) {
    // ignore
  }
}

/**
 * Global default year (configured by admin) with fallback to academic_years.is_active.
 */
function tt_active_year_id(PDO $pdo): int {
  $cfg = (int)(tt_app_setting_get($pdo, 'active_year_id', '0') ?? '0');
  if ($cfg > 0) {
    try {
      $chk = $pdo->prepare('SELECT 1 FROM academic_years WHERE id=? LIMIT 1');
      $chk->execute([$cfg]);
      if ($chk->fetchColumn()) return $cfg;
    } catch (Throwable $e) {
      // ignore
    }
  }

  try {
    $years = $pdo->query('SELECT id, is_active, year_label FROM academic_years ORDER BY year_label DESC')->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return 0;
  }
  foreach ($years as $y) {
    if (!empty($y['is_active'])) return (int)$y['id'];
  }
  return !empty($years) ? (int)$years[0]['id'] : 0;
}

/**
 * Default term for a year: if the year is the globally active year, use configured active_term_no (if valid),
 * otherwise fall back to month-based inference.
 */
function tt_default_term_no_for_year(PDO $pdo, int $yearId): int {
  if ($yearId <= 0) return 1;
  $activeYearId = tt_active_year_id($pdo);
  if ($activeYearId > 0 && $yearId === $activeYearId) {
    $cfg = (int)(tt_app_setting_get($pdo, 'active_term_no', '0') ?? '0');
    if ($cfg > 0) {
      return tt_validate_term_no($pdo, $yearId, $cfg);
    }
  }
  return tt_default_term_no($pdo, $yearId);
}

function tt_active_year_term(PDO $pdo): array {
  $yearId = tt_active_year_id($pdo);
  $termNo = $yearId > 0 ? tt_default_term_no_for_year($pdo, $yearId) : 1;
  return ['year_id' => $yearId, 'term_no' => $termNo];
}

function valid_username($u) {
  return (bool)preg_match('/^[a-z0-9._-]{3,32}$/i', $u);
}

function th_date($datetime) {
  if (!$datetime) return '';
  $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
  if ($ts <= 0) return '';
  $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $d = (int)date('j', $ts);
  $m = (int)date('n', $ts);
  $y = (int)date('Y', $ts) + 543; // แปลง ค.ศ. -> พ.ศ.
  return $d . ' ' . $months[$m] . ' ' . $y;
}

/**
 * ดึงรายการกลุ่มสาระการเรียนรู้จากฐานข้อมูล
 * @param bool $activeOnly ดึงเฉพาะกลุ่มสาระที่เปิดใช้งาน (default: true)
 * @return array [id => name]
 */
function teacher_group_options(bool $activeOnly = true): array {
  global $pdo;
  
  $sql = 'SELECT id, name FROM subject_groups';
  if ($activeOnly) {
    $sql .= ' WHERE is_active = 1';
  }
  $sql .= ' ORDER BY display_order ASC, name ASC';
  
  $stmt = $pdo->query($sql);
  $result = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $result[(int)$row['id']] = $row['name'];
  }
  
  return $result;
}

/**
 * แปลง ID กลุ่มสาระเป็นชื่อกลุ่มสาระ
 * @param int|null $g ID ของกลุ่มสาระ
 * @return string ชื่อกลุ่มสาระ หรือ '—' ถ้าไม่พบ
 */
function teacher_group_label(?int $g): string {
  if (!$g) return '—';
  
  global $pdo;
  
  $stmt = $pdo->prepare('SELECT name FROM subject_groups WHERE id = ? LIMIT 1');
  $stmt->execute([$g]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  
  return $row ? $row['name'] : '—';
}

/* =========================
   Academic Terms (per year)
========================= */
function tt_terms_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  // Ensure table exists (fresh installs)
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS terms (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      term_no TINYINT UNSIGNED NOT NULL,
      term_name VARCHAR(190) NOT NULL DEFAULT '',
      start_month TINYINT UNSIGNED NULL,
      end_month TINYINT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_term (academic_year_id, term_no),
      CONSTRAINT fk_terms_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore (permissions/old MySQL)
  }

  // Best-effort schema evolution (ignore if already applied)
  $alters = [
    "ALTER TABLE terms ADD COLUMN term_name VARCHAR(190) NOT NULL DEFAULT ''",
    "ALTER TABLE terms ADD COLUMN start_month TINYINT UNSIGNED NULL",
    "ALTER TABLE terms ADD COLUMN end_month TINYINT UNSIGNED NULL",
    "ALTER TABLE terms ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE terms ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
  ];
  foreach ($alters as $sql) {
    try {
      $pdo->exec($sql);
    } catch (Throwable $e) {
      // ignore duplicate column / unsupported
    }
  }

  $done = true;
}

function tt_term_month_in_range(int $month, int $start, int $end): bool {
  if ($month < 1 || $month > 12) return false;
  if ($start < 1 || $start > 12 || $end < 1 || $end > 12) return false;
  if ($start <= $end) return $month >= $start && $month <= $end;
  // wrap-around range (e.g. 10-3)
  return ($month >= $start) || ($month <= $end);
}

/**
 * @return array<int, array{term_no:int, term_name:string, start_month:?int, end_month:?int}>
 */
function tt_terms_list(PDO $pdo, int $yearId): array {
  tt_terms_init($pdo);
  if ($yearId <= 0) {
    return [
      ['term_no' => 1, 'term_name' => 'เทอม 1', 'start_month' => 4, 'end_month' => 9],
      ['term_no' => 2, 'term_name' => 'เทอม 2', 'start_month' => 10, 'end_month' => 3],
    ];
  }

  try {
    $stmt = $pdo->prepare('SELECT term_no, term_name, start_month, end_month FROM terms WHERE academic_year_id = ? ORDER BY term_no ASC');
    $stmt->execute([$yearId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // Old schema fallback (term_no only)
    $stmt = $pdo->prepare('SELECT term_no FROM terms WHERE academic_year_id = ? ORDER BY term_no ASC');
    $stmt->execute([$yearId]);
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $rows[] = ['term_no' => $r['term_no']];
    }
  }

  $out = [];
  foreach ($rows as $r) {
    $termNo = (int)($r['term_no'] ?? 0);
    if ($termNo <= 0) continue;

    $name = trim((string)($r['term_name'] ?? ''));
    $start = isset($r['start_month']) ? (int)$r['start_month'] : null;
    $end = isset($r['end_month']) ? (int)$r['end_month'] : null;

    // Backward compatible defaults for the classic Thai 2-term model
    if (($start === 0 || $end === 0 || $start === null || $end === null) && ($termNo === 1 || $termNo === 2)) {
      $start = ($termNo === 1) ? 4 : 10;
      $end = ($termNo === 1) ? 9 : 3;
    }
    if ($name === '') $name = 'เทอม ' . $termNo;

    $out[] = [
      'term_no' => $termNo,
      'term_name' => $name,
      'start_month' => ($start !== null && $start >= 1 && $start <= 12) ? $start : null,
      'end_month' => ($end !== null && $end >= 1 && $end <= 12) ? $end : null,
    ];
  }

  if (!$out) {
    // If no rows exist, keep legacy expectations (2 terms)
    return [
      ['term_no' => 1, 'term_name' => 'เทอม 1', 'start_month' => 4, 'end_month' => 9],
      ['term_no' => 2, 'term_name' => 'เทอม 2', 'start_month' => 10, 'end_month' => 3],
    ];
  }

  return $out;
}

function tt_terms_map(PDO $pdo, int $yearId): array {
  $map = [];
  foreach (tt_terms_list($pdo, $yearId) as $t) {
    $map[(int)$t['term_no']] = $t;
  }
  return $map;
}

function tt_term_label_from_no(PDO $pdo, int $yearId, int $termNo): string {
  $m = tt_terms_map($pdo, $yearId);
  if (isset($m[$termNo])) return (string)$m[$termNo]['term_name'];
  return 'เทอม ' . $termNo;
}

function tt_default_term_no(PDO $pdo, int $yearId, ?int $month = null): int {
  $month = $month ?? (int)date('n');
  $terms = tt_terms_list($pdo, $yearId);

  // Prefer configured term ranges
  foreach ($terms as $t) {
    $start = $t['start_month'];
    $end = $t['end_month'];
    if ($start !== null && $end !== null && tt_term_month_in_range($month, (int)$start, (int)$end)) {
      return (int)$t['term_no'];
    }
  }

  // Legacy fallback
  return ($month >= 4 && $month <= 9) ? 1 : 2;
}

function tt_validate_term_no(PDO $pdo, int $yearId, int $termNo): int {
  $map = tt_terms_map($pdo, $yearId);
  if (isset($map[$termNo])) return $termNo;
  $first = array_key_first($map);
  return $first ? (int)$first : 1;
}

/* =========================
   Buildings (อาคาร)
========================= */
/**
 * Best-effort schema evolution สำหรับตาราง teachers
 * เพิ่มคอลัมน์ ชื่อ/นามสกุลภาษาอังกฤษ และ password_hash (สำหรับ API ในอนาคต)
 */
function tt_teachers_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  $alters = [
    "ALTER TABLE teachers ADD COLUMN national_id VARCHAR(20) NULL AFTER teacher_code",
    "ALTER TABLE teachers ADD COLUMN username VARCHAR(100) NULL AFTER teacher_code",
    "ALTER TABLE teachers ADD COLUMN first_name_en VARCHAR(100) NULL AFTER last_name",
    "ALTER TABLE teachers ADD COLUMN last_name_en VARCHAR(100) NULL AFTER first_name_en",
    "ALTER TABLE teachers ADD COLUMN email VARCHAR(190) NULL AFTER last_name_en",
    "ALTER TABLE teachers ADD COLUMN password_hash VARCHAR(255) NULL AFTER email",
    "ALTER TABLE teachers ADD COLUMN password_plain VARCHAR(255) NULL AFTER password_hash",
  ];
  foreach ($alters as $sql) {
    try {
      $pdo->exec($sql);
    } catch (Throwable $e) {
      // ignore duplicate column / unsupported
    }
  }

  $done = true;
}

function tt_buildings_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_buildings (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      building_name VARCHAR(190) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_buildings (
      teacher_id INT UNSIGNED NOT NULL,
      building_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (teacher_id, building_id),
      KEY idx_building (building_id),
      CONSTRAINT fk_tb_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
      CONSTRAINT fk_tb_building FOREIGN KEY (building_id) REFERENCES duty_buildings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  $done = true;
}

/**
 * @return array<int, array{id:int, building_name:string, is_active:int, sort_order:int}>
 */
function tt_buildings_list(PDO $pdo, bool $activeOnly = true): array {
  tt_buildings_init($pdo);
  try {
    $sql = 'SELECT id, building_name, is_active, sort_order FROM duty_buildings';
    if ($activeOnly) $sql .= ' WHERE is_active=1';
    $sql .= ' ORDER BY sort_order ASC, building_name ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return [];
  }
}

function tt_building_label(PDO $pdo, ?int $buildingId): string {
  if (!$buildingId) return '—';
  tt_buildings_init($pdo);
  try {
    $st = $pdo->prepare('SELECT building_name FROM duty_buildings WHERE id=? LIMIT 1');
    $st->execute([(int)$buildingId]);
    $name = $st->fetchColumn();
    return $name ? (string)$name : '—';
  } catch (Throwable $e) {
    return '—';
  }
}

/**
 * @return int[] building ids
 */
function tt_teacher_buildings_get(PDO $pdo, int $teacherId): array {
  tt_buildings_init($pdo);
  if ($teacherId <= 0) return [];
  try {
    $st = $pdo->prepare('SELECT building_id FROM teacher_buildings WHERE teacher_id=? ORDER BY building_id');
    $st->execute([$teacherId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * @param int[] $buildingIds
 */
function tt_teacher_buildings_set(PDO $pdo, int $teacherId, array $buildingIds): void {
  tt_buildings_init($pdo);
  if ($teacherId <= 0) return;
  $clean = [];
  foreach ($buildingIds as $bid) {
    $bid = (int)$bid;
    if ($bid <= 0) continue;
    $clean[$bid] = true;
  }
  $cleanIds = array_slice(array_keys($clean), 0, 2); // allow up to 2

  try {
    $pdo->prepare('DELETE FROM teacher_buildings WHERE teacher_id=?')->execute([$teacherId]);
    if (!$cleanIds) return;
    $ins = $pdo->prepare('INSERT INTO teacher_buildings(teacher_id, building_id) VALUES (?,?)');
    foreach ($cleanIds as $bid) {
      $ins->execute([$teacherId, (int)$bid]);
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/* =========================
   Teacher grade levels (ระดับชั้นที่สอน)
   - "auto": ดึงจากตารางสอน (teaching_loads) ของปีการศึกษานั้น ๆ → ติ๊กถูกและล็อกไว้ (เอาออกไม่ได้)
   - "manual": ผู้ดูแลติ๊กเอง → เก็บในตาราง teacher_grade_levels
========================= */
function tt_teacher_grade_levels_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_grade_levels (
      teacher_id INT UNSIGNED NOT NULL,
      grade_label VARCHAR(50) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (teacher_id, grade_label),
      CONSTRAINT fk_tgl_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }
  $done = true;
}

/**
 * รายชื่อระดับชั้นทั้งหมด (distinct grade_label จากตารางชั้นเรียน) เรียงแบบเดียวกับหน้าชั้นเรียน
 * @return string[]
 */
function tt_grade_levels_all(PDO $pdo): array {
  try {
    $sql = "SELECT DISTINCT grade_label FROM classes
            WHERE grade_label IS NOT NULL AND grade_label <> ''
            ORDER BY FIELD(LEFT(grade_label,1),'ต','อ','ป','ม'), grade_label ASC";
    return array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * ระดับชั้นที่ครูสอนจริงตามตารางสอนในปีการศึกษาที่กำหนด (ติ๊กถูก + ล็อก)
 * @return string[]
 */
function tt_teacher_grade_levels_auto(PDO $pdo, int $teacherId, int $yearId): array {
  if ($teacherId <= 0 || $yearId <= 0) return [];
  try {
    $st = $pdo->prepare("SELECT DISTINCT c.grade_label
      FROM teaching_loads tl
      JOIN classes c ON c.id = tl.class_id
      WHERE tl.teacher_id = ? AND tl.academic_year_id = ?
        AND c.grade_label IS NOT NULL AND c.grade_label <> ''");
    $st->execute([$teacherId, $yearId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * ระดับชั้นที่ผู้ดูแลติ๊กเอง (เก็บในตาราง)
 * @return string[]
 */
function tt_teacher_grade_levels_manual(PDO $pdo, int $teacherId): array {
  tt_teacher_grade_levels_init($pdo);
  if ($teacherId <= 0) return [];
  try {
    $st = $pdo->prepare('SELECT grade_label FROM teacher_grade_levels WHERE teacher_id=?');
    $st->execute([$teacherId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * บันทึกระดับชั้นที่ติ๊กเอง (แทนที่ของเดิมทั้งหมด) — ส่วนที่ดึงจากตารางสอนไม่ต้องเก็บ เพราะคำนวณสด
 * @param string[] $grades
 */
function tt_teacher_grade_levels_set(PDO $pdo, int $teacherId, array $grades): void {
  tt_teacher_grade_levels_init($pdo);
  if ($teacherId <= 0) return;
  $clean = [];
  foreach ($grades as $g) {
    $g = trim((string)$g);
    if ($g === '') continue;
    $clean[$g] = true;
  }
  try {
    $pdo->prepare('DELETE FROM teacher_grade_levels WHERE teacher_id=?')->execute([$teacherId]);
    if (!$clean) return;
    $ins = $pdo->prepare('INSERT INTO teacher_grade_levels(teacher_id, grade_label) VALUES (?,?)');
    foreach (array_keys($clean) as $g) {
      $ins->execute([$teacherId, $g]);
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/**
 * map teacher_id → ระดับชั้น (สำหรับแสดงในตารางรายชื่อ โดยไม่ยิงทีละแถว)
 * @return array{auto: array<int,string[]>, manual: array<int,string[]>}
 */
function tt_teacher_grade_levels_maps(PDO $pdo, int $yearId): array {
  tt_teacher_grade_levels_init($pdo);
  $auto = [];
  $manual = [];
  try {
    if ($yearId > 0) {
      $st = $pdo->prepare("SELECT DISTINCT tl.teacher_id, c.grade_label
        FROM teaching_loads tl
        JOIN classes c ON c.id = tl.class_id
        WHERE tl.academic_year_id = ?
          AND c.grade_label IS NOT NULL AND c.grade_label <> ''");
      $st->execute([$yearId]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $auto[(int)$r['teacher_id']][] = (string)$r['grade_label'];
      }
    }
  } catch (Throwable $e) {
    // ignore
  }
  try {
    foreach ($pdo->query('SELECT teacher_id, grade_label FROM teacher_grade_levels')->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $manual[(int)$r['teacher_id']][] = (string)$r['grade_label'];
    }
  } catch (Throwable $e) {
    // ignore
  }
  return ['auto' => $auto, 'manual' => $manual];
}

/* =========================
   Duty Roster (จัดเวร)
========================= */
function tt_dow_label(int $n): string {
  static $m = [1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'];
  return $m[(int)$n] ?? (string)$n;
}

function tt_duty_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  // Buildings support used by duty posts/assign
  tt_buildings_init($pdo);

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_time_slots (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      term_no TINYINT UNSIGNED NOT NULL,
      slot_key VARCHAR(20) NOT NULL,
      slot_label VARCHAR(120) NOT NULL DEFAULT '',
      period_no TINYINT UNSIGNED NULL,
      start_time TIME NOT NULL,
      end_time TIME NOT NULL,
      is_duty_enabled TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_term_slot (academic_year_id, term_no, slot_key),
      KEY idx_year_term (academic_year_id, term_no),
      CONSTRAINT fk_duty_slot_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_posts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      term_no TINYINT UNSIGNED NOT NULL,
      post_name VARCHAR(150) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_term_name (academic_year_id, term_no, post_name),
      KEY idx_year_term (academic_year_id, term_no),
      CONSTRAINT fk_duty_post_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_shifts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      term_no TINYINT UNSIGNED NOT NULL,
      day_of_week TINYINT UNSIGNED NOT NULL,
      duty_time_slot_id INT UNSIGNED NOT NULL,
      duty_post_id INT UNSIGNED NOT NULL,
      required_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_shift (academic_year_id, term_no, day_of_week, duty_time_slot_id, duty_post_id),
      KEY idx_year_term (academic_year_id, term_no),
      KEY idx_slot (duty_time_slot_id),
      KEY idx_post (duty_post_id),
      CONSTRAINT fk_duty_shift_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_shift_slot FOREIGN KEY (duty_time_slot_id) REFERENCES duty_time_slots(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_shift_post FOREIGN KEY (duty_post_id) REFERENCES duty_posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_assignments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      duty_shift_id INT UNSIGNED NOT NULL,
      teacher_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_shift_teacher (duty_shift_id, teacher_id),
      KEY idx_teacher (teacher_id),
      CONSTRAINT fk_duty_as_shift FOREIGN KEY (duty_shift_id) REFERENCES duty_shifts(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_as_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  // =========================
  // Master Data (shared across terms)
  // =========================
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_master_time_slots (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      slot_key VARCHAR(20) NOT NULL,
      slot_label VARCHAR(120) NOT NULL DEFAULT '',
      period_no TINYINT UNSIGNED NULL,
      start_time TIME NOT NULL,
      end_time TIME NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_slot_key (slot_key),
      KEY idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_master_posts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_name VARCHAR(150) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_post_name (post_name),
      KEY idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  // Best-effort schema evolution for duty_master_posts -> link to building
  $altersMasterPosts = [
    'ALTER TABLE duty_master_posts ADD COLUMN building_id INT UNSIGNED NULL',
    'ALTER TABLE duty_master_posts ADD KEY idx_building (building_id)',
    'ALTER TABLE duty_master_posts ADD CONSTRAINT fk_dmp_building FOREIGN KEY (building_id) REFERENCES duty_buildings(id) ON DELETE SET NULL',
  ];
  foreach ($altersMasterPosts as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ }
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_master_shifts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      day_of_week TINYINT UNSIGNED NOT NULL,
      duty_time_slot_id INT UNSIGNED NOT NULL,
      duty_post_id INT UNSIGNED NOT NULL,
      required_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_master_shift (day_of_week, duty_time_slot_id, duty_post_id),
      KEY idx_day_slot (day_of_week, duty_time_slot_id),
      KEY idx_post (duty_post_id),
      CONSTRAINT fk_duty_mshift_slot FOREIGN KEY (duty_time_slot_id) REFERENCES duty_master_time_slots(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_mshift_post FOREIGN KEY (duty_post_id) REFERENCES duty_master_posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_term_assignments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      term_no TINYINT UNSIGNED NOT NULL,
      duty_master_shift_id INT UNSIGNED NOT NULL,
      teacher_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_term_shift_teacher (academic_year_id, term_no, duty_master_shift_id, teacher_id),
      KEY idx_year_term (academic_year_id, term_no),
      KEY idx_teacher (teacher_id),
      CONSTRAINT fk_duty_tas_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_tas_shift FOREIGN KEY (duty_master_shift_id) REFERENCES duty_master_shifts(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_tas_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  // Teachers excluded from duty roster per term
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duty_term_exclusions (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      term_no TINYINT UNSIGNED NOT NULL,
      teacher_id INT UNSIGNED NOT NULL,
      reason VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_term_teacher (academic_year_id, term_no, teacher_id),
      KEY idx_year_term (academic_year_id, term_no),
      KEY idx_teacher (teacher_id),
      CONSTRAINT fk_duty_tex_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
      CONSTRAINT fk_duty_tex_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  $done = true;
}

/**
 * Sync master duty time slots from period_slots (shared across all terms).
 * Preserves is_active for existing slots.
 */
function tt_duty_master_sync_from_periods(PDO $pdo): void {
  tt_duty_init($pdo);
  $periods = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll(PDO::FETCH_ASSOC);
  if (!$periods) return;

  $ins = $pdo->prepare(
    'INSERT INTO duty_master_time_slots(slot_key, slot_label, period_no, start_time, end_time, is_active, sort_order)\n'
    .'VALUES (?,?,?,?,?,?,?)\n'
    .'ON DUPLICATE KEY UPDATE\n'
    .'  slot_label=VALUES(slot_label),\n'
    .'  period_no=VALUES(period_no),\n'
    .'  start_time=VALUES(start_time),\n'
    .'  end_time=VALUES(end_time),\n'
    .'  sort_order=VALUES(sort_order)'
  );

  foreach ($periods as $p) {
    $pno = (int)$p['period_no'];
    if ($pno <= 0 || $pno > 99) continue;
    $slotKey = 'P' . $pno;
    $label = 'คาบ ' . $pno;
    $sort = $pno * 10;
    try {
      $ins->execute([$slotKey, $label, $pno, $p['start_time'], $p['end_time'], 1, $sort]);
    } catch (Throwable $e) {
      // ignore
    }
  }
}

/**
 * Sync duty_time_slots from period_slots for a given year/term.
 * Preserves is_duty_enabled if a slot already exists.
 */
function tt_duty_sync_from_periods(PDO $pdo, int $yearId, int $termNo): void {
  tt_duty_init($pdo);
  if ($yearId <= 0 || $termNo <= 0) return;

  $periods = $pdo->query('SELECT period_no, start_time, end_time FROM period_slots ORDER BY period_no')->fetchAll(PDO::FETCH_ASSOC);
  if (!$periods) return;

  $ins = $pdo->prepare(
    'INSERT INTO duty_time_slots(academic_year_id, term_no, slot_key, slot_label, period_no, start_time, end_time, is_duty_enabled, sort_order)\n'
    .'VALUES (?,?,?,?,?,?,?,?,?)\n'
    .'ON DUPLICATE KEY UPDATE\n'
    .'  slot_label=VALUES(slot_label),\n'
    .'  period_no=VALUES(period_no),\n'
    .'  start_time=VALUES(start_time),\n'
    .'  end_time=VALUES(end_time),\n'
    .'  sort_order=VALUES(sort_order)'
  );

  foreach ($periods as $p) {
    $pno = (int)$p['period_no'];
    if ($pno <= 0 || $pno > 99) continue;
    $slotKey = 'P' . $pno;
    $label = 'คาบ ' . $pno;
    $sort = $pno * 10;
    try {
      $ins->execute([$yearId, $termNo, $slotKey, $label, $pno, $p['start_time'], $p['end_time'], 1, $sort]);
    } catch (Throwable $e) {
      // ignore
    }
  }
}

/* =========================
   Holiday Duty (เวรวันหยุด)
   - กำหนดวันที่เวรระดับปีการศึกษา
   - กำหนดจุดเวร + จำนวนคน
   - ทีมเวร + สมาชิกทีม
   - ลงเวรรายวัน (ลงทั้งทีม/ลงรายคน)
   - แทนเวร (บันทึกว่าใครแทนใคร)
========================= */
function tt_holiday_duty_init(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_duty_dates (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      duty_date DATE NOT NULL,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_date (academic_year_id, duty_date),
      KEY idx_year_date (academic_year_id, duty_date),
      CONSTRAINT fk_hdd_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_duty_posts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      post_name VARCHAR(150) NOT NULL,
      required_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_post (academic_year_id, post_name),
      KEY idx_year_active (academic_year_id, is_active, sort_order),
      CONSTRAINT fk_hdp_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_duty_teams (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      academic_year_id INT UNSIGNED NOT NULL,
      team_name VARCHAR(80) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_team (academic_year_id, team_name),
      KEY idx_year_active (academic_year_id, is_active, sort_order),
      CONSTRAINT fk_hdt_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_duty_team_members (
      team_id INT UNSIGNED NOT NULL,
      teacher_id INT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (team_id, teacher_id),
      KEY idx_teacher (teacher_id),
      CONSTRAINT fk_hdtm_team FOREIGN KEY (team_id) REFERENCES holiday_duty_teams(id) ON DELETE CASCADE,
      CONSTRAINT fk_hdtm_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_duty_assignments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      holiday_duty_date_id INT UNSIGNED NOT NULL,
      holiday_duty_post_id INT UNSIGNED NOT NULL,
      teacher_id INT UNSIGNED NOT NULL,
      team_id INT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_date_post_teacher (holiday_duty_date_id, holiday_duty_post_id, teacher_id),
      KEY idx_date_post (holiday_duty_date_id, holiday_duty_post_id),
      KEY idx_teacher (teacher_id),
      KEY idx_team (team_id),
      CONSTRAINT fk_hda_date FOREIGN KEY (holiday_duty_date_id) REFERENCES holiday_duty_dates(id) ON DELETE CASCADE,
      CONSTRAINT fk_hda_post FOREIGN KEY (holiday_duty_post_id) REFERENCES holiday_duty_posts(id) ON DELETE CASCADE,
      CONSTRAINT fk_hda_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
      CONSTRAINT fk_hda_team FOREIGN KEY (team_id) REFERENCES holiday_duty_teams(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS holiday_duty_substitutions (
      assignment_id INT UNSIGNED NOT NULL,
      from_teacher_id INT UNSIGNED NOT NULL,
      to_teacher_id INT UNSIGNED NOT NULL,
      reason VARCHAR(255) NULL,
      created_by_user_id INT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (assignment_id),
      KEY idx_from (from_teacher_id),
      KEY idx_to (to_teacher_id),
      CONSTRAINT fk_hds_as FOREIGN KEY (assignment_id) REFERENCES holiday_duty_assignments(id) ON DELETE CASCADE,
      CONSTRAINT fk_hds_from FOREIGN KEY (from_teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
      CONSTRAINT fk_hds_to FOREIGN KEY (to_teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e) {
    // ignore
  }

  $done = true;
}