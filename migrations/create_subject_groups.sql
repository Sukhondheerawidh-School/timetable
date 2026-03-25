-- สร้างตาราง subject_groups สำหรับจัดเก็บกลุ่มสาระการเรียนรู้
CREATE TABLE IF NOT EXISTS subject_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE COMMENT 'ชื่อกลุ่มสาระ',
  display_order INT NOT NULL DEFAULT 0 COMMENT 'ลำดับการแสดงผล',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=ใช้งาน, 0=ไม่ใช้งาน',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่มข้อมูลกลุ่มสาระเดิมที่มีอยู่แล้ว (ตาม helpers.php)
INSERT INTO subject_groups (id, name, display_order, is_active) VALUES
(1, 'กลุ่มสาระการเรียนรู้คณิตศาสตร์', 1, 1),
(2, 'กลุ่มสาระการเรียนรู้วิทยาศาสตร์และเทคโนโลยี', 2, 1),
(3, 'กลุ่มสาระการเรียนรู้ภาษาไทย', 3, 1),
(4, 'กลุ่มสาระการเรียนรู้ภาษาต่างประเทศ', 4, 1),
(5, 'กลุ่มสาระการเรียนรู้สังคมศึกษา ศาสนาและวัฒนธรรม', 5, 1),
(6, 'กลุ่มสาระการเรียนรู้สุขศึกษา พลศึกษา', 6, 1),
(7, 'กลุ่มสาระการเรียนรู้ศิลปศึกษา', 7, 1),
(8, 'กลุ่มสาระการเรียนรู้การงานอาชีพ', 8, 1),
(9, 'อื่นๆ', 9, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);
