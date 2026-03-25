<?php
ob_implicit_flush(true);
ob_end_flush();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // สำหรับ Nginx

require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/db.php';
requireLogin(); requireAdmin();

$year_id = (int)($_GET['year_id'] ?? 0);
$term_no = (int)($_GET['term_no'] ?? 1);

if (!$year_id || !in_array($term_no, [1, 2], true)) {
  echo "data: " . json_encode(['error' => 'Invalid parameters']) . "\n\n";
  exit;
}

function sendProgress($current, $total, $message = '') {
  $percent = $total > 0 ? round(($current / $total) * 100, 1) : 0;
  echo "data: " . json_encode([
    'current' => $current,
    'total' => $total,
    'percent' => $percent,
    'message' => $message
  ]) . "\n\n";
  if (ob_get_level() > 0) ob_flush();
  flush();
}

// โหลดข้อมูล
try {
  $loadsStmt = $pdo->prepare("
    SELECT tl.id, tl.periods_per_week
    FROM teaching_loads tl
    WHERE tl.academic_year_id=? AND tl.term_no=?
  ");
  $loadsStmt->execute([$year_id, $term_no]);
  $loads = $loadsStmt->fetchAll();
  
  $total = 0;
  foreach ($loads as $L) {
    $total += (int)$L['periods_per_week'];
  }

  sendProgress(0, $total, 'เริ่มจัดตาราง...');

  // จำลอง progress (ในความเป็นจริงต้องแก้ engine ให้ส่งสัญญาณกลับมา)
  for ($i = 0; $i <= $total; $i += max(1, (int)($total / 50))) {
    usleep(100000); // 0.1 วินาที
    sendProgress($i, $total, "กำลังจัดคาบที่ {$i}/{$total}...");
  }

  sendProgress($total, $total, 'เสร็จสิ้น');
  echo "data: " . json_encode(['done' => true]) . "\n\n";

} catch (Throwable $e) {
  echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
}