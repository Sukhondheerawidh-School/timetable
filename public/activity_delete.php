<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD']!=='POST' || !verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error','คำขอไม่ถูกต้อง'); redirect('activities.php');
}
$id = (int)($_POST['id'] ?? 0);
try{
  $pdo->prepare('DELETE FROM activity_groups WHERE id=?')->execute([$id]);
  // activity_classes / activity_teachers จะถูกลบตาม (ON DELETE CASCADE)
  flash_set('success','ลบกิจกรรมสำเร็จ');
}catch(Throwable $e){
  flash_set('error','ลบไม่สำเร็จ: '.$e->getMessage());
}
redirect('activities.php');
