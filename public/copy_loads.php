<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin(); requireAdmin();

$from_year=(int)($_POST['from_year_id']??0);
$from_term=(int)($_POST['from_term_no']??0);
$to_year  =(int)($_POST['to_year_id']??0);
$to_term  =(int)($_POST['to_term_no']??0);

try{
  if(!$from_year||!$from_term||!$to_year||!$to_term) throw new Exception('ข้อมูลไม่ครบ');

  $pdo->beginTransaction();

  // คัดลอก loads
  $pdo->prepare('
    INSERT INTO teaching_loads (academic_year_id, term_no, class_id, subject_id, periods_per_week, consecutive_slots, room_id)
    SELECT ?, ?, class_id, subject_id, periods_per_week, consecutive_slots, room_id
    FROM teaching_loads WHERE academic_year_id=? AND term_no=?')
    ->execute([$to_year,$to_term,$from_year,$from_term]);

  // map (class_id,subject_id,ppw,cons,room) -> id ใหม่
  $newRows=$pdo->prepare('SELECT id,class_id,subject_id,periods_per_week,consecutive_slots,COALESCE(room_id,0) r FROM teaching_loads WHERE academic_year_id=? AND term_no=?');
  $newRows->execute([$to_year,$to_term]); $NEW=$newRows->fetchAll();

  $oldRows=$pdo->prepare('SELECT id,class_id,subject_id,periods_per_week,consecutive_slots,COALESCE(room_id,0) r FROM teaching_loads WHERE academic_year_id=? AND term_no=?');
  $oldRows->execute([$from_year,$from_term]); $OLD=$oldRows->fetchAll();

  $map=[]; // old_id -> new_id
  foreach($OLD as $o){
    foreach($NEW as $n){
      if ($o['class_id']==$n['class_id'] && $o['subject_id']==$n['subject_id'] &&
          $o['periods_per_week']==$n['periods_per_week'] && $o['consecutive_slots']==$n['consecutive_slots'] &&
          $o['r']==$n['r']) { $map[(int)$o['id']] = (int)$n['id']; break; }
    }
  }

  // คัดลอกครูของโหลด
  $sel=$pdo->prepare('SELECT teacher_id, role FROM teaching_load_teachers WHERE load_id=?');
  $ins=$pdo->prepare('INSERT IGNORE INTO teaching_load_teachers(load_id,teacher_id,role) VALUES (?,?,?)');
  foreach($map as $old=>$new){
    $sel->execute([$old]);
    foreach($sel as $r) $ins->execute([$new,(int)$r['teacher_id'],$r['role']]);
  }

  $pdo->commit();
  flash_set('success','คัดลอกกำลังสอนสำเร็จ');
  redirect('loads.php?year_id='.$to_year.'&term_no='.$to_term);
}catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_set('error','คัดลอกไม่สำเร็จ: '.$e->getMessage());
  redirect('loads.php?year_id='.$to_year.'&term_no='.$to_term);
}
