<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity_log.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD']!=='POST' || !verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error','คำขอไม่ถูกต้อง'); redirect('activities.php');
}
$id = (int)($_POST['id'] ?? 0);
try{
  $oldStmt = $pdo->prepare('SELECT * FROM activity_groups WHERE id=?');
  $oldStmt->execute([$id]);
  $oldRow = $oldStmt->fetch();
  if ($oldRow) {
    $clsSel = $pdo->prepare('SELECT class_id FROM activity_classes WHERE activity_id=?');
    $clsSel->execute([$id]);
    $oldRow['class_ids'] = array_map('intval', array_column($clsSel->fetchAll(),'class_id'));

    $tchSel = $pdo->prepare('SELECT teacher_id FROM activity_teachers WHERE activity_id=?');
    $tchSel->execute([$id]);
    $oldRow['teacher_ids'] = array_map('intval', array_column($tchSel->fetchAll(),'teacher_id'));
  }

  $pdo->prepare('DELETE FROM activity_groups WHERE id=?')->execute([$id]);
  // activity_classes / activity_teachers จะถูกลบตาม (ON DELETE CASCADE)

  if ($oldRow) {
    logDelete('activity_groups', $id, $oldRow);
  }
  flash_set('success','ลบกิจกรรมสำเร็จ');
}catch(Throwable $e){
  flash_set('error','ลบไม่สำเร็จ: '.$e->getMessage());
}
redirect('activities.php');
