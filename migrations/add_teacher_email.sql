-- Migration: เพิ่มคอลัมน์ email ในตาราง teachers
-- ใช้คู่กับ first_name_en/last_name_en/password_hash สำหรับสร้าง API เชื่อมต่อระบบอื่น
-- รัน 1 ครั้งเดียวบน server จริง (แอปมี tt_teachers_init() เพิ่มคอลัมน์ให้อัตโนมัติด้วย)

ALTER TABLE `teachers`
  ADD COLUMN `email` VARCHAR(190) NULL AFTER `last_name_en`;
