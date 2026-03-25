<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: text/html; charset=utf-8');
// ปิด buffer ที่อาจค้าง และให้ส่งผลลัพธ์ทันที
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(1);

// ===== รับพารามิเตอร์ =====
$year_id = (int)($_GET['year_id'] ?? 0);
$term_no = (int)($_GET['term_no'] ?? 1);

// ป้องกันกรณีไม่ได้ส่งปี/เทอม
if (!$year_id) {
    // เลือกปี active
    $y = $pdo->query("SELECT id FROM academic_years WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($y) $year_id = (int)$y['id'];
}
if (!$year_id) { echo "❌ ไม่พบปีการศึกษา<br>"; exit; }

// ===== helper =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function echo_status($html){
    echo $html;
    echo str_repeat(" ", 1024); // ช่วยให้ browser flush
    flush();
}
function getLevelTypeFromClass($class_name){
    // ประถม = ป, อนุบาล/ต้น = ต/อ/ป, มัธยม = ม
    // คุณปรับ logic ได้ตาม naming จริงของโรงเรียน
    $c = trim($class_name);
    if (mb_strpos($c, 'ม') === 0) return 'M';
    if (mb_strpos($c, 'ป') === 0 || mb_strpos($c, 'อ') === 0 || mb_strpos($c, 'ต') === 0) return 'P';
    return 'UNK';
}

// ===== โหลด period, room, class, homeroom =====
$periods = $pdo->query("SELECT period_no,start_time,end_time FROM period_slots ORDER BY period_no")->fetchAll(PDO::FETCH_ASSOC);
$periodNos = array_map(fn($p)=> (int)$p['period_no'], $periods);

$classes = $pdo->query("SELECT id,class_name,homeroom_room_id FROM classes")->fetchAll(PDO::FETCH_ASSOC);
$classMap = [];           // id => class_name
$classHomeRoom = [];      // id => homeroom_room_id
$classLevelType = [];     // id => 'P'|'M'|'UNK'
foreach($classes as $c){
    $classMap[(int)$c['id']] = $c['class_name'];
    $classHomeRoom[(int)$c['id']] = $c['homeroom_room_id'] ? (int)$c['homeroom_room_id'] : null;
    $classLevelType[(int)$c['id']] = getLevelTypeFromClass($c['class_name']);
}

// ห้องแล็บ (ถ้าต้องใช้ตรวจชนกัน)
$rooms = $pdo->query("SELECT id,room_name,room_type FROM rooms")->fetchAll(PDO::FETCH_ASSOC);
$roomType = []; // id => type
foreach($rooms as $r){ $roomType[(int)$r['id']] = $r['room_type']; }

// ===== โหลด teaching loads + ครูร่วม (lead/co) =====
/*
คาดโครงสร้าง:
- teaching_loads: id, academic_year_id, term_no, class_id, subject_name, periods_per_week, room_id (nullable), is_block_2 (เรียน 2 คาบติด)
- teaching_load_teachers: load_id, teacher_id
*/
$sqlLoads = "SELECT tl.id, tl.class_id, tl.subject_name, tl.periods_per_week, tl.room_id, tl.is_block_2
             FROM teaching_loads tl
             WHERE tl.academic_year_id=? AND tl.term_no=?";
$st = $pdo->prepare($sqlLoads);
$st->execute([$year_id,$term_no]);
$loads = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$loads) {
    echo_status("ไม่มีโหลดให้จัดสำหรับปี/เทอมนี้<br>");
    exit;
}

// ต่อครูร่วม
$stT = $pdo->prepare("SELECT tlt.load_id, tlt.teacher_id, t.first_name
                      FROM teaching_load_teachers tlt
                      JOIN teachers t ON t.id=tlt.teacher_id
                      WHERE tlt.load_id IN (" . implode(',', array_map('intval', array_column($loads,'id'))) . ")");
$stT->execute();
$rowsT = $stT->fetchAll(PDO::FETCH_ASSOC);
$loadTeachers = [];  // load_id => [teacher_ids]
foreach($rowsT as $r){
    $loadTeachers[(int)$r['load_id']][] = (int)$r['teacher_id'];
}

// fallback: ถ้าไม่มีในตารางคู่ครู และ teaching_loads มี teacher_id (ในบางระบบ)
try{
    $fallback = $pdo->query("SHOW COLUMNS FROM teaching_loads LIKE 'teacher_id'")->fetch(PDO::FETCH_ASSOC);
    if ($fallback) {
        $stF = $pdo->prepare("SELECT id, teacher_id FROM teaching_loads WHERE academic_year_id=? AND term_no=? AND teacher_id IS NOT NULL");
        $stF->execute([$year_id,$term_no]);
        while($r=$stF->fetch(PDO::FETCH_ASSOC)){
            $tid = (int)$r['teacher_id'];
            if ($tid) $loadTeachers[(int)$r['id']][] = $tid;
        }
    }
}catch(Exception $e){ /* ignore */ }

// ===== นับคาบที่ลงแล้วสำหรับแต่ละ load (ประมาณจาก class+subject+teacher) =====
// หมายเหตุ: ถ้า slot มี column load_id อยู่ให้ใช้จะดีที่สุด
$usedCountByLoad = [];
foreach($loads as $ld){
    $ldid = (int)$ld['id'];
    $class_id = (int)$ld['class_id'];
    $subj = $ld['subject_name'];

    $teacher_ids = $loadTeachers[$ldid] ?? [];
    if (!$teacher_ids) {
        $usedCountByLoad[$ldid] = 0; // ยังไม่รู้ครู → ถือว่ายังไม่ลง
        continue;
    }

    // นับ slot ที่ class+subject ตรง และมีครูคนใดคนหนึ่งในชุดนี้
    $inT = implode(',', array_fill(0, count($teacher_ids), '?'));
    $params = array_merge([$year_id, $term_no, $class_id, $subj], $teacher_ids);
    $sqlUsed = "SELECT COUNT(DISTINCT ts.id) AS cnt
                FROM timetable_slots ts
                JOIN timetable_slot_teachers tst ON tst.slot_id = ts.id
                WHERE ts.academic_year_id=? AND ts.term_no=?
                  AND ts.class_id=? AND ts.subject_name=?
                  AND tst.teacher_id IN ($inT)";
    $stU = $pdo->prepare($sqlUsed);
    $stU->execute($params);
    $usedCountByLoad[$ldid] = (int)($stU->fetchColumn() ?: 0);
}

// ===== โหลดข้อมูลตารางปัจจุบันมาสร้างตัวนับ (รายวัน) =====
$clsDayCnt  = []; // [class_id][day] => int
$tchDayCnt  = []; // [teacher_id][day] => int
$roomDayCnt = []; // [room_id][day] => int

$st = $pdo->prepare("
  SELECT ts.class_id, ts.room_id, tst.teacher_id, ts.day_of_week
  FROM timetable_slots ts
  LEFT JOIN timetable_slot_teachers tst ON tst.slot_id = ts.id
  WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week BETWEEN 1 AND 5
");
$st->execute([$year_id,$term_no]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $d = (int)$r['day_of_week'];
    if ($r['class_id'])  $clsDayCnt[(int)$r['class_id']][$d] = ($clsDayCnt[(int)$r['class_id']][$d] ?? 0) + 1;
    if ($r['room_id'])   $roomDayCnt[(int)$r['room_id']][$d] = ($roomDayCnt[(int)$r['room_id']][$d] ?? 0) + 1;
    if ($r['teacher_id'])$tchDayCnt[(int)$r['teacher_id']][$d] = ($tchDayCnt[(int)$r['teacher_id']][$d] ?? 0) + 1;
}

// ===== สุ่มลำดับโหลดก่อนเริ่ม =====
shuffle($loads);

// ===== ฟังก์ชันคะแนนสมดุลวัน =====
function pickBestDay(array $candidateDays, int $class_id, array $teacher_ids, ?int $room_id,
                     array $clsDayCnt, array $tchDayCnt, array $roomDayCnt): int {
    $W_CLASS  = 1.0;
    $W_TCH    = 1.2;
    $W_ROOM   = 0.6;
    $W_NOISE  = 0.05;

    $bestDay = -1;
    $bestScore = PHP_INT_MAX;

    foreach ($candidateDays as $d) {
        $clsLoad = $clsDayCnt[$class_id][$d] ?? 0;

        $t_sum = 0; $t_n = 0;
        foreach ($teacher_ids as $tid) {
            $t_sum += ($tchDayCnt[$tid][$d] ?? 0);
            $t_n++;
        }
        $t_avg = $t_n ? ($t_sum / $t_n) : 0;

        $rLoad = ($room_id ? ($roomDayCnt[$room_id][$d] ?? 0) : 0);

        $score = $W_CLASS*$clsLoad + $W_TCH*$t_avg + $W_ROOM*$rLoad + mt_rand()/mt_getrandmax()*$W_NOISE;

        if ($score < $bestScore) {
            $bestScore = $score;
            $bestDay = $d;
        }
    }

    return $bestDay;
}

// ===== Utilities ตรวจ constraint =====
function teacherHasTooLongStreak(PDO $pdo, int $year_id, int $term_no, int $teacher_id, int $day, int $newPeriod, int $maxStreak=4){
    // หา periods ที่ครูมีสอนอยู่ในวันนั้น
    $st = $pdo->prepare("SELECT ts.period_no
                         FROM timetable_slots ts
                         JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
                         WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week=? AND tst.teacher_id=?");
    $st->execute([$year_id,$term_no,$day,$teacher_id]);
    $ps = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC),'period_no'));
    $ps[] = $newPeriod;
    sort($ps);
    // เช็ค streak ติดกัน
    $streak = 1; $maxst=1;
    for($i=1;$i<count($ps);$i++){
        if ($ps[$i] === $ps[$i-1]+1) { $streak++; $maxst = max($maxst,$streak); }
        else $streak=1;
    }
    return $maxst > $maxStreak;
}
function teacherNeedsLunch(PDO $pdo, int $year_id, int $term_no, int $teacher_id, int $day, int $newPeriod){
    // ครูต้องมีว่าง >=1 ในช่วง 4,5,6
    $target = [4,5,6];
    $st = $pdo->prepare("SELECT ts.period_no
                         FROM timetable_slots ts
                         JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
                         WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week=? AND tst.teacher_id=? AND ts.period_no IN (4,5,6)");
    $st->execute([$year_id,$term_no,$day,$teacher_id]);
    $busy = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC),'period_no'));
    $busy[] = $newPeriod;
    $busy = array_unique($busy);
    // ถ้าชุด busy ครอบคลุมทั้ง 4,5,6 แปลว่าไม่มีพัก
    foreach($target as $p){
        if (!in_array($p, $busy, true)) return false; // ยังเหลือว่างอย่างน้อยหนึ่ง
    }
    return true; // ไม่มีช่วงว่าง
}
function sameSubjectTeacherOnceADay(PDO $pdo, int $year_id, int $term_no, int $class_id, string $subject_name, array $teacher_ids, int $day){
    // NOTE: ชื่อฟังก์ชันเดิมคงไว้เพื่อไม่กระทบจุดเรียกใช้
    // เงื่อนไขจริง: วิชาเดิมของห้องเดียวกันห้ามลงซ้ำใน "วันเดียวกัน" (ไม่ขึ้นกับครู)
    $st = $pdo->prepare("SELECT COUNT(*) FROM timetable_slots
                         WHERE academic_year_id=? AND term_no=? AND class_id=? AND subject_name=? AND day_of_week=?");
    $st->execute([$year_id,$term_no,$class_id,$subject_name,$day]);
    return ((int)$st->fetchColumn()) > 0;
}
function crossBuildingAdjacentBlocked(PDO $pdo, int $year_id, int $term_no, array $teacher_ids, int $class_id, int $day, int $period, array $classLevelType){
    // ถ้า period-1 หรือ period+1 ของครูคนใดเป็นคนละตึก (P vs M) → block
    if (!$teacher_ids) return false;
    $curType = $classLevelType[$class_id] ?? 'UNK';
    $checkPs = [];
    if ($period > 1)  $checkPs[] = $period-1;
    if ($period < 12) $checkPs[] = $period+1; // กำหนด max 12 พอ
    if (!$checkPs) return false;

    $inT = implode(',', array_fill(0,count($teacher_ids),'?'));
    $inP = implode(',', $checkPs);
    $params = array_merge([$year_id,$term_no,$day], $teacher_ids);
    $sql = "SELECT ts.period_no, ts.class_id
            FROM timetable_slots ts
            JOIN timetable_slot_teachers tst ON tst.slot_id=ts.id
            WHERE ts.academic_year_id=? AND ts.term_no=? AND ts.day_of_week=? 
              AND ts.period_no IN ($inP)
              AND tst.teacher_id IN ($inT)";
    $st = $pdo->prepare($sql); $st->execute($params);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
        $othClass = (int)$r['class_id'];
        $othType = $classLevelType[$othClass] ?? 'UNK';
        if ($othType !== 'UNK' && $curType !== 'UNK' && $othType !== $curType) {
            return true; // คนละตึก ชิดกัน
        }
    }
    return false;
}
function labRoomConflict(PDO $pdo, int $year_id, int $term_no, ?int $room_id, int $day, int $period){
    if (!$room_id) return false;
    $st = $pdo->prepare("SELECT COUNT(*) FROM timetable_slots
                         WHERE academic_year_id=? AND term_no=? AND day_of_week=? AND period_no=? AND room_id=?");
    $st->execute([$year_id,$term_no,$day,$period,$room_id]);
    return ((int)$st->fetchColumn()) > 0;
}
function slotOccupied(PDO $pdo, int $year_id, int $term_no, int $class_id, int $day, int $period){
    $st = $pdo->prepare("SELECT COUNT(*) FROM timetable_slots
                         WHERE academic_year_id=? AND term_no=? AND class_id=? AND day_of_week=? AND period_no=?");
    $st->execute([$year_id,$term_no,$class_id,$day,$period]);
    return ((int)$st->fetchColumn()) > 0;
}

// ===== เริ่มทำงาน =====
$startTime = microtime(true);
$totalLoads = count($loads);
$doneLoads = 0;
$placedCount = 0;   // คาบที่วางได้
$skippedCount = 0;  // คาบที่ข้ามเพราะหาไม่ได้
$errors = [];

echo_status("<div style='font:14px/1.4 sans-serif'>");
echo_status("<div><b>เริ่มจัดตารางอัตโนมัติ</b> ปีการศึกษา ".h($year_id)." เทอม ".h($term_no)."</div>");
echo_status("<div>พบโหลดทั้งหมด ".h($totalLoads)." รายการ</div><hr>");

foreach ($loads as $ld) {
    $ldid = (int)$ld['id'];
    $class_id = (int)$ld['class_id'];
    $class_name = $classMap[$class_id] ?? ('#'.$class_id);
    $teacher_ids = array_values(array_unique($loadTeachers[$ldid] ?? []));
    $subject_name = $ld['subject_name'];
    $need = max(0, (int)$ld['periods_per_week'] - (int)($usedCountByLoad[$ldid] ?? 0));
    $isBlock2 = !empty($ld['is_block_2']);
    $prefRoomId = $ld['room_id'] ? (int)$ld['room_id'] : null;

    $doneLoads++;

    // สถานะโหลดนี้
    $who = $teacher_ids ? ("ครู ".h(implode(', ', array_map(function($tid) use($pdo){
                    static $cache=[];
                    if(!isset($cache[$tid])){
                        $r=$pdo->prepare("SELECT first_name FROM teachers WHERE id=?");
                        $r->execute([$tid]);
                        $cache[$tid] = $r->fetchColumn() ?: ('#'.$tid);
                    }
                    return $cache[$tid];
                }, $teacher_ids)))) : "<i>ยังไม่กำหนดครู</i>";

    echo_status("<div>[$doneLoads/$totalLoads] กำลังลง: ห้อง ".h($class_name)." / วิชา ".h($subject_name)." / $who — ต้องลงอีก ".h($need)." คาบ</div>");

    if (!$teacher_ids) {
        $errors[] = "โหลด $ldid ยังไม่กำหนดครู";
        echo_status("<div style='color:#b91c1c'>ข้าม: ไม่พบครูในโหลดนี้</div>");
        continue;
    }
    if ($need <= 0) {
        echo_status("<div style='color:#16a34a'>ครบแล้ว: ไม่ต้องลงเพิ่ม</div>");
        continue;
    }

    // ลงทีละคาบ
    for ($i=0;$i<$need;$i++) {
        // 1) หา candidate days จาก constraint ระดับ "วัน"
        $dayPool = [1,2,3,4,5];
        shuffle($dayPool); // randomize

        $candidates = [];
        foreach ($dayPool as $d) {
            // ห้ามลงซ้ำวิชา-ครู เดิมในวันเดียวกัน (ห้องเดียวกัน)
            if (sameSubjectTeacherOnceADay($pdo, $year_id, $term_no, $class_id, $subject_name, $teacher_ids, $d)) {
                continue;
            }
            $candidates[] = $d;
        }

        if (!$candidates) {
            $skippedCount++;
            echo_status("<div style='color:#b45309'>ข้าม 1 คาบ: ไม่มีวันว่างตามเงื่อนไขรายวัน</div>");
            continue;
        }

        // 2) เลือกวันด้วยคะแนนสมดุล
        $bestDay = pickBestDay($candidates, $class_id, $teacher_ids, $prefRoomId, $clsDayCnt, $tchDayCnt, $roomDayCnt);

        // 3) หา period ในวันนั้น
        $pools = $periodNos;
        shuffle($pools);

        $placed = false;

        // ถ้าต้องลง 2 คาบติด (block) ให้หา period ที่ p และ p+1 ว่างพร้อมกัน
        if ($isBlock2) {
            // ลองทีละ period (p, p+1)
            foreach ($pools as $p) {
                $p2 = $p+1;
                if (!in_array($p2, $periodNos,true)) continue;

                // ห้องนี้ช่วง p,p+1 ต้องว่าง
                if (slotOccupied($pdo,$year_id,$term_no,$class_id,$bestDay,$p))   continue;
                if (slotOccupied($pdo,$year_id,$term_no,$class_id,$bestDay,$p2))  continue;

                // ครูต้องไม่ติด 4+ คาบ
                $violate = false;
                foreach ($teacher_ids as $tid) {
                    if (teacherHasTooLongStreak($pdo,$year_id,$term_no,$tid,$bestDay,$p,4) ||
                        teacherHasTooLongStreak($pdo,$year_id,$term_no,$tid,$bestDay,$p2,4)) { $violate=true; break; }
                    if (teacherNeedsLunch($pdo,$year_id,$term_no,$tid,$bestDay,$p) &&
                        teacherNeedsLunch($pdo,$year_id,$term_no,$tid,$bestDay,$p2)) { $violate=true; break; }
                }
                if ($violate) continue;

                // cross-building adjacency
                if (crossBuildingAdjacentBlocked($pdo,$year_id,$term_no,$teacher_ids,$class_id,$bestDay,$p,$classLevelType) ||
                    crossBuildingAdjacentBlocked($pdo,$year_id,$term_no,$teacher_ids,$class_id,$bestDay,$p2,$classLevelType)) {
                    continue;
                }

                // lab room conflict
                if (labRoomConflict($pdo,$year_id,$term_no,$prefRoomId,$bestDay,$p) ||
                    labRoomConflict($pdo,$year_id,$term_no,$prefRoomId,$bestDay,$p2)) {
                    continue;
                }

                // ผ่าน → insert 2 แถว
                try {
                    $pdo->beginTransaction();
                    // กำหนดห้อง: ถ้ามี room_id ในโหลด ใช้อันนั้น, ถ้าไม่มีปล่อย NULL (ไปโชว์ homeroom ตอนแสดงผล)
                    $ins = $pdo->prepare("INSERT INTO timetable_slots
                        (academic_year_id, term_no, class_id, subject_name, day_of_week, period_no, room_id)
                        VALUES (?,?,?,?,?,?,?)");
                    $ins->execute([$year_id,$term_no,$class_id,$subject_name,$bestDay,$p,$prefRoomId]);
                    $slot1 = (int)$pdo->lastInsertId();

                    $ins->execute([$year_id,$term_no,$class_id,$subject_name,$bestDay,$p2,$prefRoomId]);
                    $slot2 = (int)$pdo->lastInsertId();

                    $insT = $pdo->prepare("INSERT INTO timetable_slot_teachers (slot_id, teacher_id) VALUES (?,?)");
                    foreach ([$slot1,$slot2] as $sid) {
                        foreach ($teacher_ids as $tid) { $insT->execute([$sid,$tid]); }
                    }
                    $pdo->commit();

                    // update counters
                    $clsDayCnt[$class_id][$bestDay] = ($clsDayCnt[$class_id][$bestDay] ?? 0) + 2;
                    foreach ($teacher_ids as $tid) {
                        $tchDayCnt[$tid][$bestDay] = ($tchDayCnt[$tid][$bestDay] ?? 0) + 2;
                    }
                    if ($prefRoomId) {
                        $roomDayCnt[$prefRoomId][$bestDay] = ($roomDayCnt[$prefRoomId][$bestDay] ?? 0) + 2;
                    }

                    $placedCount += 2;
                    echo_status("<div style='color:#166534'>✓ วาง 2 คาบติด: วัน $bestDay คาบ $p–$p2</div>");
                    $placed = true;
                    break;
                } catch(PDOException $e){
                    $pdo->rollBack();
                    // ทับ uniq_cell หรือข้อจำกัดอื่น → ลองคู่อื่น
                    continue;
                }
            }
        } // end block2

        if (!$placed) {
            // วาง 1 คาบปกติ
            foreach ($pools as $p) {
                // ห้องนี้ช่วง p ต้องว่าง
                if (slotOccupied($pdo,$year_id,$term_no,$class_id,$bestDay,$p))   continue;

                // ครู: ไม่ติด 4+, มีพัก 4–6
                $violate = false;
                foreach ($teacher_ids as $tid) {
                    if (teacherHasTooLongStreak($pdo,$year_id,$term_no,$tid,$bestDay,$p,4)) { $violate=true; break; }
                    if (teacherNeedsLunch($pdo,$year_id,$term_no,$tid,$bestDay,$p)) { $violate=true; break; }
                }
                if ($violate) continue;

                // cross-building adjacency
                if (crossBuildingAdjacentBlocked($pdo,$year_id,$term_no,$teacher_ids,$class_id,$bestDay,$p,$classLevelType)) {
                    continue;
                }

                // lab room conflict
                if (labRoomConflict($pdo,$year_id,$term_no,$prefRoomId,$bestDay,$p)) {
                    continue;
                }

                // ผ่าน → insert
                try {
                    $ins = $pdo->prepare("INSERT INTO timetable_slots
                        (academic_year_id, term_no, class_id, subject_name, day_of_week, period_no, room_id)
                        VALUES (?,?,?,?,?,?,?)");
                    $ins->execute([$year_id,$term_no,$class_id,$subject_name,$bestDay,$p,$prefRoomId]);
                    $slot_id = (int)$pdo->lastInsertId();

                    $insT = $pdo->prepare("INSERT INTO timetable_slot_teachers (slot_id, teacher_id) VALUES (?,?)");
                    foreach ($teacher_ids as $tid) { $insT->execute([$slot_id,$tid]); }

                    // update counters
                    $clsDayCnt[$class_id][$bestDay] = ($clsDayCnt[$class_id][$bestDay] ?? 0) + 1;
                    foreach ($teacher_ids as $tid) {
                        $tchDayCnt[$tid][$bestDay] = ($tchDayCnt[$tid][$bestDay] ?? 0) + 1;
                    }
                    if ($prefRoomId) {
                        $roomDayCnt[$prefRoomId][$bestDay] = ($roomDayCnt[$prefRoomId][$bestDay] ?? 0) + 1;
                    }

                    $placedCount++;
                    echo_status("<div style='color:#166534'>✓ วาง 1 คาบ: วัน $bestDay คาบ $p</div>");
                    $placed = true;
                    break;
                } catch(PDOException $e){
                    // ทับ uniq_cell หรือข้อจำกัดอื่น → ลองคาบถัดไป
                    continue;
                }
            }
        }

        if (!$placed) {
            $skippedCount++;
            echo_status("<div style='color:#b45309'>ข้าม 1 คาบ: วัน/คาบที่เหมาะสมไม่พอ</div>");
        }
    } // end for need
    echo_status("<div style='color:#334155'>สรุปโหลดนี้: ลงแล้วรวม ".h(($usedCountByLoad[$ldid]??0) + $placedCount)." คาบ</div><hr>");
} // end foreach loads

$sec = round(microtime(true) - $startTime, 2);
echo_status("<div style='margin-top:10px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px'>
  <div><b>เสร็จสิ้น</b></div>
  <div>วางคาบใหม่ได้: <b>".h($placedCount)."</b> คาบ</div>
  <div>ข้ามไป: <b>".h($skippedCount)."</b> คาบ</div>
  <div>ใช้เวลา: <b>".h($sec)."</b> วินาที</div>
</div>");

// ปุ่มกลับ/รีเฟรชตาราง
$backUrl = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'timetable_auto_dashboard.php?year_id='.$year_id.'&term_no='.$term_no;
echo_status("<div style='margin-top:10px'>
  <a href='".h($backUrl)."' style='display:inline-block;background:#2563eb;color:#fff;padding:8px 12px;border-radius:10px;text-decoration:none'>กลับแดชบอร์ด</a>
  <a href='timetable.php?year_id=".h($year_id)."&term_no=".h($term_no)."' style='display:inline-block;background:#16a34a;color:#fff;padding:8px 12px;border-radius:10px;text-decoration:none;margin-left:6px'>ไปหน้าตาราง</a>
</div>");

?>

<!-- Progress Bar -->
<div id="progressContainer" class="hidden bg-white rounded-2xl shadow p-4 mb-4">
  <div class="mb-2 flex justify-between items-center">
    <span id="progressText" class="text-sm font-medium">กำลังจัดตาราง...</span>
    <span id="progressPercent" class="text-sm text-slate-500">0%</span>
  </div>
  <div class="w-full bg-slate-200 rounded-full h-2.5">
    <div id="progressBar" class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
  </div>
</div>

  <!-- ฟอร์มจัดตารางอัตโนมัติ -->
  <div class="bg-white rounded-2xl shadow p-4">
    <!-- ...existing code... -->
  </div>

<script>
// ...existing code...

function showProgress() {
  document.getElementById('progressContainer').classList.remove('hidden');
  document.getElementById('progressBar').style.width = '0%';
  document.getElementById('progressPercent').textContent = '0%';
  document.getElementById('progressText').textContent = 'กำลังเริ่มต้น...';
}

function updateProgress(data) {
  if (data.percent !== undefined) {
    document.getElementById('progressBar').style.width = data.percent + '%';
    document.getElementById('progressPercent').textContent = data.percent + '%';
  }
  if (data.message) {
    document.getElementById('progressText').textContent = data.message;
  }
}

function hideProgress() {
  setTimeout(() => {
    document.getElementById('progressContainer').classList.add('hidden');
  }, 2000);
}

async function runAuto() {
    const ok = await ttConfirm({
        title: 'จัดตารางอัตโนมัติ',
        text: 'คาบเดิม (source=auto) จะถูกลบ ยืนยัน?',
        confirmButtonText: 'เริ่มจัด',
        cancelButtonText: 'ยกเลิก'
    });
    if (!ok) return;
  const y = document.getElementById('year_id').value;
  const t = document.getElementById('term_no').value;
  
  showProgress();
  setLoading(true);
  
  // เปิด EventSource สำหรับ progress
  const progressUrl = `timetable_auto_progress.php?year_id=${y}&term_no=${t}`;
  const eventSource = new EventSource(progressUrl);
  
  eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    if (data.error) {
            ttAlert({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(data.error) });
      eventSource.close();
      setLoading(false);
      hideProgress();
      return;
    }
    updateProgress(data);
    if (data.done) {
      eventSource.close();
    }
  };
  
  eventSource.onerror = function() {
    eventSource.close();
  };
  
  try {
    const res = await fetch(`timetable_auto_run.php?action=run`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ year_id: y, term_no: t })
    });
    const data = await res.json();
    
    eventSource.close();
    hideProgress();
    
        if (data.error) {
            await ttAlert({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(data.error) });
        } else if (data.ok) {
            const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            let html = `<div style="text-align:left">จัดสำเร็จ <b>${escapeHtml(data.placed)}</b> คาบ จาก <b>${escapeHtml(data.attempt)}</b> รายการ</div>`;
            if (data.fails && data.fails.length > 0) {
                const lines = data.fails.slice(0, 10).map(escapeHtml).join('\n');
                html += `<div style="margin-top:10px;text-align:left"><b>จัดไม่ได้:</b><pre style="white-space:pre-wrap;margin-top:6px">${lines}${data.fails.length > 10 ? `\n... และอีก ${escapeHtml(data.fails.length - 10)} รายการ` : ''}</pre></div>`;
            }
            await ttAlert({ icon: 'success', title: 'เสร็จสิ้น', html });
            location.reload();
        }
  } catch (e) {
    eventSource.close();
    hideProgress();
        await ttAlert({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e.message || e) });
  } finally {
    setLoading(false);
  }
}

// ...existing code...
</script>
