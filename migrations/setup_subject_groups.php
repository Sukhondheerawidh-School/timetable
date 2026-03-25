<?php
/**
 * Migration: สร้างตาราง subject_groups และเพิ่มข้อมูลกลุ่มสาระเริ่มต้น
 * 
 * วิธีใช้งาน:
 * 1. อัปโหลดไฟล์นี้ไปที่ server
 * 2. เปิด browser ไปที่: http://your-domain/timetable/migrations/setup_subject_groups.php
 * 3. หรือรันผ่าน terminal: php setup_subject_groups.php
 * 
 * หมายเหตุ: ไฟล์นี้จะตรวจสอบว่ามีตารางอยู่แล้วหรือไม่ ถ้ามีจะไม่สร้างซ้ำ
 */

// กำหนดค่า Timezone
date_default_timezone_set('Asia/Bangkok');

// โหลด config
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/db.php';

// ตั้งค่า charset ให้ถูกต้อง
$pdo->exec("SET NAMES utf8mb4");

echo "<!DOCTYPE html>
<html lang='th'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Setup Subject Groups Migration</title>
    <style>
        body { font-family: 'Sarabun', sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
        h1 { color: #4f46e5; }
        .success { background: #d1fae5; color: #065f46; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .error { background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .info { background: #dbeafe; color: #1e40af; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .step { background: #f3f4f6; padding: 10px 15px; border-left: 4px solid #6366f1; margin: 10px 0; }
        pre { background: #1f2937; color: #f3f4f6; padding: 15px; border-radius: 8px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #e5e7eb; padding: 10px; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; }
    </style>
</head>
<body>
    <h1>📚 Setup Subject Groups Migration</h1>
    <p>วันที่รัน: " . date('Y-m-d H:i:s') . "</p>
    <hr>
";

try {
    // ========================================
    // ตรวจสอบว่ามีตารางอยู่แล้วหรือไม่
    // ========================================
    echo "<div class='step'><strong>Step 1:</strong> ตรวจสอบตาราง subject_groups</div>";
    
    $tableExists = false;
    $stmt = $pdo->query("SHOW TABLES LIKE 'subject_groups'");
    if ($stmt->rowCount() > 0) {
        $tableExists = true;
        echo "<div class='info'>✓ ตาราง subject_groups มีอยู่แล้ว</div>";
    } else {
        echo "<div class='info'>○ ยังไม่มีตาราง subject_groups</div>";
    }

    // ========================================
    // สร้างตาราง (ถ้ายังไม่มี)
    // ========================================
    if (!$tableExists) {
        echo "<div class='step'><strong>Step 2:</strong> สร้างตาราง subject_groups</div>";
        
        $createTableSQL = "
        CREATE TABLE subject_groups (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL UNIQUE COMMENT 'ชื่อกลุ่มสาระ',
          display_order INT NOT NULL DEFAULT 0 COMMENT 'ลำดับการแสดงผล',
          is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=ใช้งาน, 0=ไม่ใช้งาน',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($createTableSQL);
        echo "<div class='success'>✓ สร้างตาราง subject_groups สำเร็จ</div>";
    } else {
        echo "<div class='step'><strong>Step 2:</strong> ข้ามการสร้างตาราง (มีอยู่แล้ว)</div>";
    }

    // ========================================
    // ตรวจสอบข้อมูลในตาราง
    // ========================================
    echo "<div class='step'><strong>Step 3:</strong> ตรวจสอบข้อมูลในตาราง</div>";
    
    $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM subject_groups");
    $currentCount = (int)$countStmt->fetch()['cnt'];
    
    echo "<div class='info'>พบข้อมูลในตาราง: {$currentCount} รายการ</div>";

    // ========================================
    // เพิ่มข้อมูลกลุ่มสาระเริ่มต้น
    // ========================================
    echo "<div class='step'><strong>Step 4:</strong> เพิ่ม/อัปเดตข้อมูลกลุ่มสาระ</div>";
    
    $subject_groups = [
        1 => 'กลุ่มสาระการเรียนรู้คณิตศาสตร์',
        2 => 'กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี',
        3 => 'กลุ่มสาระการเรียนรู้ภาษาไทย',
        4 => 'กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ',
        5 => 'กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนาและวัฒนธรรม',
        6 => 'กลุ่มสาระการเรียนรู้สุขศึกษา พลศึกษา',
        7 => 'กลุ่มสาระการเรียนรู้ศิลปศึกษา',
        8 => 'กลุ่มสาระการเรียนรู้การงานอาชีพ',
        9 => 'อื่นๆ',
    ];

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($subject_groups as $id => $name) {
        // ตรวจสอบว่ามี ID นี้อยู่แล้วหรือไม่
        $checkStmt = $pdo->prepare('SELECT id, name FROM subject_groups WHERE id = ?');
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // อัปเดตถ้าชื่อไม่ตรงกัน
            if ($existing['name'] !== $name) {
                $updateStmt = $pdo->prepare('UPDATE subject_groups SET name = ? WHERE id = ?');
                $updateStmt->execute([$name, $id]);
                echo "<div class='info'>↻ อัปเดต ID {$id}: {$name}</div>";
                $updated++;
            } else {
                echo "<div class='info'>○ ข้าม ID {$id}: {$name} (มีอยู่แล้ว)</div>";
                $skipped++;
            }
        } else {
            // ตรวจสอบว่ามีชื่อซ้ำหรือไม่
            $checkNameStmt = $pdo->prepare('SELECT id FROM subject_groups WHERE name = ?');
            $checkNameStmt->execute([$name]);
            $duplicateName = $checkNameStmt->fetch();
            
            if ($duplicateName) {
                // ถ้ามีชื่อซ้ำแล้ว ให้อัปเดต ID เป็น ID ที่ต้องการ
                $updateIdStmt = $pdo->prepare('UPDATE subject_groups SET id = ?, display_order = ? WHERE name = ?');
                $updateIdStmt->execute([$id, $id, $name]);
                echo "<div class='info'>↻ ย้าย ID {$duplicateName['id']} → {$id}: {$name}</div>";
                $updated++;
            } else {
                // เพิ่มใหม่
                $insertStmt = $pdo->prepare('
                    INSERT INTO subject_groups (id, name, display_order, is_active) 
                    VALUES (?, ?, ?, 1)
                ');
                $insertStmt->execute([$id, $name, $id]);
                echo "<div class='success'>+ เพิ่ม ID {$id}: {$name}</div>";
                $inserted++;
            }
        }
    }

    // ========================================
    // แสดงสรุปผลลัพธ์
    // ========================================
    echo "<div class='step'><strong>Step 5:</strong> สรุปผลการทำงาน</div>";
    echo "<div class='success'>
        <h3>✓ Migration สำเร็จ!</h3>
        <ul>
            <li>เพิ่มใหม่: {$inserted} รายการ</li>
            <li>อัปเดต: {$updated} รายการ</li>
            <li>ข้าม: {$skipped} รายการ</li>
            <li><strong>รวมทั้งหมด: " . ($inserted + $updated + $skipped) . " รายการ</strong></li>
        </ul>
    </div>";

    // ========================================
    // แสดงข้อมูลปัจจุบันในตาราง
    // ========================================
    echo "<div class='step'><strong>Step 6:</strong> ข้อมูลปัจจุบันในตาราง</div>";
    
    $allGroups = $pdo->query('SELECT * FROM subject_groups ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>
        <thead>
            <tr>
                <th>ID</th>
                <th>ชื่อกลุ่มสาระ</th>
                <th>ลำดับ</th>
                <th>สถานะ</th>
                <th>วันที่สร้าง</th>
            </tr>
        </thead>
        <tbody>";
    
    foreach ($allGroups as $group) {
        $status = $group['is_active'] ? '<span style="color: green;">✓ ใช้งาน</span>' : '<span style="color: gray;">✗ ปิดใช้งาน</span>';
        echo "<tr>
            <td>{$group['id']}</td>
            <td>{$group['name']}</td>
            <td>{$group['display_order']}</td>
            <td>{$status}</td>
            <td>{$group['created_at']}</td>
        </tr>";
    }
    
    echo "</tbody></table>";

    // ========================================
    // คำแนะนำถัดไป
    // ========================================
    echo "<div class='info'>
        <h3>📋 ขั้นตอนถัดไป:</h3>
        <ol>
            <li>ตรวจสอบข้อมูลในตารางด้านบน</li>
            <li>เข้าไปที่หน้า <a href='../public/subject_groups.php' style='color: #4f46e5;'>จัดการกลุ่มสาระ</a></li>
            <li>สามารถเพิ่ม แก้ไข หรือลบกลุ่มสาระได้ตามต้องการ</li>
            <li><strong>แนะนำ:</strong> ลบไฟล์นี้ออกหลังจาก migration เสร็จแล้ว เพื่อความปลอดภัย</li>
        </ol>
    </div>";

} catch (PDOException $e) {
    echo "<div class='error'>
        <h3>❌ เกิดข้อผิดพลาด!</h3>
        <p><strong>Error:</strong> {$e->getMessage()}</p>
        <pre>{$e->getTraceAsString()}</pre>
    </div>";
    
    echo "<div class='info'>
        <h3>💡 วิธีแก้ไข:</h3>
        <ul>
            <li>ตรวจสอบว่าไฟล์ <code>config/config.php</code> มีการตั้งค่าฐานข้อมูลถูกต้อง</li>
            <li>ตรวจสอบว่า MySQL/MariaDB ทำงานอยู่</li>
            <li>ตรวจสอบว่า user มีสิทธิ์ CREATE TABLE</li>
        </ul>
    </div>";
}

echo "
    <hr>
    <p style='text-align: center; color: #6b7280; font-size: 14px;'>
        Migration completed at " . date('Y-m-d H:i:s') . "
    </p>
</body>
</html>";
