<?php
/**
 * สคริปต์แก้ไขข้อมูลกลุ่มสาระที่เป็น ??????? 
 * รันครั้งเดียวเพื่ออัปเดตข้อมูลเดิมให้ถูกต้อง
 */

require_once __DIR__ . '/../app/db.php';

// ข้อมูลกลุ่มสาระทั้ง 9 กลุ่มที่ถูกต้อง
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

echo "🔧 เริ่มแก้ไขข้อมูลกลุ่มสาระ...\n\n";

try {
    // ตั้งค่า charset ให้ถูกต้อง
    $pdo->exec("SET NAMES utf8mb4");
    
    $updated = 0;
    
    foreach ($subject_groups as $id => $name) {
        $stmt = $pdo->prepare('UPDATE subject_groups SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
        
        if ($stmt->rowCount() > 0) {
            $updated++;
            echo "✅ อัปเดต ID {$id}: {$name}\n";
        } else {
            echo "ℹ️  ID {$id} มีข้อมูลถูกต้องอยู่แล้ว\n";
        }
    }
    
    echo "\n✨ สำเร็จ! อัปเดต {$updated} รายการ\n";
    echo "\n📋 ตรวจสอบข้อมูลปัจจุบัน:\n";
    echo str_repeat('-', 80) . "\n";
    
    $stmt = $pdo->query('SELECT id, name, display_order, is_active FROM subject_groups ORDER BY display_order');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['is_active'] ? '🟢' : '🔴';
        echo "{$status} [{$row['id']}] {$row['name']} (ลำดับ: {$row['display_order']})\n";
    }
    
} catch (Exception $e) {
    echo "❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ เสร็จสิ้น! กรุณาเปิดหน้า subject_groups.php ในเบราว์เซอร์เพื่อตรวจสอบ\n";
