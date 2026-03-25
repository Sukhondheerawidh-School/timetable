<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('loads.php');
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error', 'CSRF token ไม่ถูกต้อง');
  redirect('loads.php');
}

$id = (int)($_POST['id'] ?? 0);
// ✅ รับค่าฟิลเตอร์ทั้งหมด
$year_id = (int)($_POST['year_id'] ?? 0);
$term_no = (int)($_POST['term_no'] ?? 1);
$group = isset($_POST['group']) && $_POST['group'] !== '' ? (int)$_POST['group'] : '';
$teacher_id = isset($_POST['teacher_id']) && $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : '';
$page = isset($_POST['page']) && $_POST['page'] !== '' ? (int)$_POST['page'] : 1;

if (!$id) {
  flash_set('error', 'ไม่พบ ID ที่ต้องการลบ');
  redirect('loads.php');
}

try {
  // ✅ ดึงข้อมูลก่อนลบเพื่อบันทึก log
  $stmt = $pdo->prepare("
    SELECT tl.*, 
           t.first_name, t.last_name,
           s.subject_code, s.subject_name,
           c.class_name,
           r.room_code, r.room_name,
           ay.year_label
    FROM teaching_loads tl
    JOIN teachers t ON t.id = tl.teacher_id
    JOIN subjects s ON s.id = tl.subject_id
    JOIN classes c ON c.id = tl.class_id
    LEFT JOIN rooms r ON r.id = tl.room_id
    LEFT JOIN academic_years ay ON ay.id = tl.academic_year_id
    WHERE tl.id = ?
  ");
  $stmt->execute([$id]);
  $load = $stmt->fetch();
  
  if (!$load) {
    flash_set('error', 'ไม่พบรายการที่ต้องการลบ');
    redirect('loads.php');
  }
  
  // ลบข้อมูล
  $deleteStmt = $pdo->prepare('DELETE FROM teaching_loads WHERE id = ?');
  $deleteStmt->execute([$id]);
  
  if ($deleteStmt->rowCount() > 0) {
    // ✅ บันทึก log
    $roomInfo = '';
    if ($load['room_code'] && $load['room_name']) {
      $roomInfo = $load['room_code'] . ' - ' . $load['room_name'];
    } elseif ($load['room_name']) {
      $roomInfo = $load['room_name'];
    }
    
    logDelete('teaching_loads', $id, [
      'year_label' => $load['year_label'],
      'term_no' => $load['term_no'],
      'teacher_name' => $load['first_name'] . ' ' . $load['last_name'],
      'subject' => ($load['subject_code'] ? $load['subject_code'] . ' - ' : '') . $load['subject_name'],
      'class_name' => $load['class_name'],
      'room_name' => $roomInfo ?: '-',
      'periods_per_week' => $load['periods_per_week'],
      'consecutive_slots' => $load['consecutive_slots']
    ]);
    
    flash_set('success', 'ลบรายการสำเร็จ');
  } else {
    flash_set('error', 'ไม่พบรายการที่ต้องการลบ');
  }
} catch (Throwable $e) {
  flash_set('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
  error_log("Load delete error: " . $e->getMessage());
}

// ✅ สร้าง query string พร้อมฟิลเตอร์และหน้าเดิม
$queryParams = [];
if ($year_id) $queryParams['year_id'] = $year_id;
if ($term_no) $queryParams['term_no'] = $term_no;
if ($group !== '') $queryParams['group'] = $group;
if ($teacher_id !== '') $queryParams['teacher_id'] = $teacher_id;
if ($page > 1) $queryParams['page'] = $page;

$queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
redirect('loads.php' . $queryString);
